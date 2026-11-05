<?php

declare(strict_types=1);

/*
 * Copies api.proto and proxy.proto from a released Centrifugo tag, then
 * regenerates the code and API.md:
 *
 *     CENTRIFUGO_REF=v6.10.2 composer sync-proto
 *
 * There is no default ref, so that nothing is synced from an unreleased branch
 * by accident.
 */

$ref = (string) getenv('CENTRIFUGO_REF');
if ($ref === '') {
    fwrite(STDERR, "set CENTRIFUGO_REF to a Centrifugo tag, e.g. CENTRIFUGO_REF=v6.10.2 composer sync-proto\n");
    exit(1);
}
$root = dirname(__DIR__);
$base = "https://raw.githubusercontent.com/centrifugal/centrifugo/{$ref}/internal";
$files = [
    'api.proto' => ["{$base}/apiproto/api.proto", 'service CentrifugoApi'],
    'proxy.proto' => ["{$base}/proxyproto/proxy.proto", 'service CentrifugoProxy'],
];
$got = [];
foreach ($files as $name => [$url, $marker]) {
    $text = @file_get_contents($url);
    if ($text === false) {
        fwrite(STDERR, "no {$name} at Centrifugo {$ref}\n");
        exit(1);
    }
    if (preg_match('/^' . preg_quote($marker, '/') . '\b/m', $text) !== 1) {
        fwrite(STDERR, "not Centrifugo's {$name}\n");
        exit(1);
    }
    $got[$name] = $text;
}
foreach ($got as $name => $text) {
    file_put_contents("{$root}/proto/{$name}", $text);
}
foreach (['generate.php', 'api_docs.php'] as $script) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/{$script}"), $code);
    if ($code !== 0) {
        exit($code);
    }
}
