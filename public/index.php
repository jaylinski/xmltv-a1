<?php

ini_set('max_execution_time', 900); // 900sec = 15min
ini_set('memory_limit', '384M'); // 512M (container limit) minus opcache memory
ini_set('opcache.memory_consumption', '128');

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use XmlTv\XmlTv;
use XmlTvA1\Magenta;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Vienna');

$epg = __DIR__ . '/epg.xml.gz';
$generating = __DIR__ . '/epg-is-being-generated';
$anHourAgo = strtotime('-1 hour');
$yesterday = strtotime('-12 hours');

if (!file_exists($generating) && (!file_exists($epg) || filemtime($epg) < $yesterday)) {
    file_put_contents($generating, '1');
    $cacheDirectory = __DIR__ . '/../storage/cache';
    $cacheLifetime = 172800; // 48 hours
    if (!file_exists($cacheDirectory)) {
        mkdir($cacheDirectory, 0755, true);
    }

    try {
        $cache = new FilesystemAdapter('Magenta', $cacheLifetime, $cacheDirectory);
        $psr16Cache = new Psr16Cache($cache);

        $source = new Magenta($psr16Cache, $debug = false, true);
        $xml = XmlTv::generate($source->get(), false);
        file_put_contents(__DIR__ . '/epg.xml', $xml);

        $data = gzcompress($xml, 9, ZLIB_ENCODING_GZIP);
        file_put_contents($epg, $data);
    } catch (Exception $exception) {
        echo 'Exception: ' . $exception->getMessage();
    } finally {
        unlink($generating);
    }
}

// Failsafe. If `unlink` above fails because of a fatal error, the EGP would not be generated anymore.
if (file_exists($generating) && filemtime($generating) < $anHourAgo) {
    unlink($generating);
}

if (file_exists($epg)) {
    header('Content-Encoding: gzip');
    header('Content-Type: application/xml');
    header('Content-Length: ' . filesize($epg));

    echo file_get_contents($epg);
} else {
    echo 'No EPG generated yet.';
}
