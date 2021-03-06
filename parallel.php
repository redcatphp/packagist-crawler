<?php
namespace Spindle\HttpClient;

use hirak\PackagistCrawler\ExpiredFileManager;

set_time_limit(0);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/config.php')) {
    $config = require __DIR__ . '/config.php';
} else {
    $config = require __DIR__ . '/config.default.php';
}

if (file_exists($config->lockfile)) {
    throw new \RuntimeException("$config->lockfile exists");
}


touch($config->lockfile);
register_shutdown_function(function() use($config) {
    unlink($config->lockfile);
});

$globals = new \stdClass;
$globals->q = new \SplQueue;
$globals->expiredManager = new ExpiredFileManager($config->expiredDb, $config->expireMinutes);
for ($i=0; $i<$config->maxConnections; ++$i) {
    $req = new Request;
    $req->setOption('encoding', 'gzip');
    $req->setOption('userAgent', 'https://github.com/hirak/packagist-crawler');
    $globals->q->enqueue($req);
}

$globals->mh = new Multi;
clearExpiredFiles($globals->expiredManager);

do {
    $globals->retry = false;
    $providers = downloadProviders($config, $globals);
    downloadPackages($config, $globals, $providers);
    $globals->retry = checkFiles($config);
    generateHtml($config);
} while ($globals->retry);

flushFiles($config);
exit;

/**
 * packages.json & provider-xxx$xxx.json downloader
 */
function downloadProviders($config, $globals)
{
    $cachedir = $config->cachedir;

    $packagesCache = $cachedir . 'packages.json';

    $req = new Request($config->packagistUrl . '/packages.json');
    $req->setOption('encoding', 'gzip');

    $res = $req->send();

    if (200 === $res->getStatusCode()) {
        $packages = json_decode($res->getBody());
        foreach (explode(' ', 'notify notify-batch search') as $k) {
            if (0 === strpos($packages->$k, '/')) {
                $packages->$k = 'https://packagist.org' . $packages->$k;
            }
        }
        file_put_contents($packagesCache . '.new', json_encode($packages));
    } else {
        //no changes';
        copy($packagesCache, $packagesCache . '.new');
        $packages = json_decode(file_get_contents($packagesCache));
    }

    if (empty($packages->{'provider-includes'})) {
        throw new \RuntimeException('packages.json schema changed?');
    }

    $providers = [];

    foreach ($packages->{'provider-includes'} as $tpl => $version) {
        $fileurl = str_replace('%hash%', $version->sha256, $tpl);
        $cachename = $cachedir . $fileurl;
        $providers[] = $cachename;

        if (file_exists($cachename)) continue;

        $req->setOption('url', $config->packagistUrl . '/' . $fileurl);
        $res = $req->send();

        error_log($res->getStatusCode(). "\t". $res->getUrl());
        if (200 !== $res->getStatusCode()) {
            $globals->retry = true;
            continue;
        }

        $oldcache = $cachedir . str_replace('%hash%', '*', $tpl);
        if ($glob = glob($oldcache)) {
            foreach ($glob as $old) {
                $globals->expiredManager->add($old, time());
            }
        }
        if (!file_exists(dirname($cachename))) {
            mkdir(dirname($cachename), 0777, true);
        }
        file_put_contents($cachename, $res->getBody());
        if ($config->generateGz) {
            file_put_contents($cachename . '.gz', gzencode($res->getBody()));
        }
    }

    return $providers;
}

/**
 * composer.json downloader
 *
 */
function downloadPackages($config, $globals, $providers)
{
    $cachedir = $config->cachedir;
    $i = 0;
    $urls = [];

    foreach ($providers as $providerjson) {
        $list = json_decode(file_get_contents($providerjson));
        if (!$list || empty($list->providers)) continue;

        $list = $list->providers;
        $all = count((array)$list);

        $sum = 0;
        foreach ($list as $packageName => $provider) {
            ++$sum;
            $url = "$config->packagistUrl/p/$packageName\$$provider->sha256.json";
            $cachefile = $cachedir . str_replace("$config->packagistUrl/", '', $url);
            if (file_exists($cachefile)) continue;

            $req = $globals->q->dequeue();
            $req->packageName = $packageName;
            $req->setOption('url', $url);
            $globals->mh->attach($req);
            $globals->mh->start(); //non block

            if (count($globals->q)) continue;

            /** @type Request[] $requests */
            do {
                $requests = $globals->mh->getFinishedResponses(); //block
            } while (0 === count($requests));
            //error_log("downloaded: $sum / $all");

            foreach ($requests as $req) {
                $res = $req->getResponse();
                $globals->q->enqueue($req);

                if (200 !== $res->getStatusCode()) {
                    error_log($res->getStatusCode(). "\t". $res->getUrl());
                    $globals->retry = true;
                    continue;
                }

                $cachefile = $cachedir
                    . str_replace("$config->packagistUrl/", '', $res->getUrl());

                if ($glob = glob("{$cachedir}p/$req->packageName\$*")) {
                    foreach ($glob as $old) {
                        $globals->expiredManager->add($old, time());
                    }
                }
                if (!file_exists(dirname($cachefile))) {
                    mkdir(dirname($cachefile), 0777, true);
                }
                file_put_contents($cachefile, $res->getBody());
                if ($config->generateGz) {
                    file_put_contents($cachefile . '.gz', gzencode($res->getBody()));
                }
            }
        }
    }


    if (0 === count($globals->mh)) return;
    //残りの端数をダウンロード
    $globals->mh->waitResponse();
    foreach ($globals->mh as $req) {
        $res = $req->getResponse();

        if (200 !== $res->getStatusCode()) {
            error_log($res->getStatusCode(). "\t". $res->getUrl());
            $globals->retry = true;
            continue;
        }

        $cachefile = $cachedir
            . str_replace("$config->packagistUrl/", '', $res->getUrl());
        if ($glob = glob("{$cachedir}p/$req->packageName\$*")) {
            foreach ($glob as $old) {
                $globals->expiredManager->add($old, time());
            }
        }
        if (!file_exists(dirname($cachefile))) {
            mkdir(dirname($cachefile), 0777, true);
        }
        file_put_contents($cachefile, $res->getBody());
        if ($config->generateGz) {
            file_put_contents($cachefile . '.gz', gzencode($res->getBody()));
        }
    }

}

function flushFiles($config)
{
    rename(
        $config->cachedir . 'packages.json.new',
        $config->cachedir . 'packages.json'
    );
    file_put_contents(
        $config->cachedir . 'packages.json.gz',
        gzencode(file_get_contents($config->cachedir . 'packages.json'))
    );

    error_log('finished! flushing...');
}

/**
 * check sha256
 */
function checkFiles($config)
{
    $cachedir = $config->cachedir;

    $packagejson = json_decode(file_get_contents($cachedir.'packages.json.new'));

    $i = $j = 0;
    foreach ($packagejson->{'provider-includes'} as $tpl => $provider) {
        $providerjson = str_replace('%hash%', $provider->sha256, $tpl);
        $packages = json_decode(file_get_contents($cachedir.$providerjson));

        foreach ($packages->providers as $tpl2 => $sha) {
            if (!file_exists($file = $cachedir . "p/$tpl2\$$sha->sha256.json")) {
                ++$i;
            } elseif ($sha->sha256 !== hash_file('sha256', $file)) {
                ++$i;
                unlink($file);
            } else {
                ++$j;
            }
        }
    }

    error_log($i . ' / ' . ($i + $j));
    return $i;
}

function clearExpiredFiles(ExpiredFileManager $expiredManager)
{
    $expiredFiles = $expiredManager->getExpiredFileList();

    foreach ($expiredFiles as $file) {
        if (file_exists($file)) {
            unlink($file) and $expiredManager->delete($file);
        } else {
            $expiredManager->delete($file);
        }
    }
}

function generateHtml($_config)
{
    $url = $_config->url;
    ob_start();
    include __DIR__ . '/index.html.php';
    file_put_contents($_config->cachedir . '/index.html', ob_get_clean());
}
