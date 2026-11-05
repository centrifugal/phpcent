<?php

declare(strict_types=1);

// The backend Centrifugo calls in the integration tests, run by php -S on the
// port testdata/centrifugo.json points its proxies at. Each request it gets is
// appended to PHPCENT_PROXY_LOG, one JSON line, for the test to read.

use Centrifugal\Phpcent\Endpoints\Jwks;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Proxy\ConnectHandler;
use Centrifugal\Phpcent\Proxy\ConnectRequest;
use Centrifugal\Phpcent\Proxy\ConnectResult;
use Centrifugal\Phpcent\Proxy\Disconnect;
use Centrifugal\Phpcent\Proxy\RefreshHandler;
use Centrifugal\Phpcent\Proxy\RefreshRequest;
use Centrifugal\Phpcent\Proxy\RefreshResult;
use Centrifugal\Phpcent\Token\Key;

require __DIR__ . '/../../vendor/autoload.php';

$log = static function (string $kind, mixed $req): void {
    file_put_contents((string) getenv('PHPCENT_PROXY_LOG'), json_encode(['kind' => $kind, 'req' => $req]) . "\n", \FILE_APPEND | \LOCK_EX);
};
$onError = static function (HttpRequest $r, \Throwable $e) use ($log): void {
    $log('error', $e->getMessage());
};
$user = (string) getenv('PHPCENT_PROXY_USER');

$handler = match (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), \PHP_URL_PATH)) {
    '/connect' => new ConnectHandler(static function (ConnectRequest $req, HttpRequest $http) use ($log, $user): ConnectResult {
        $log('connect', $req);
        if (!($req->data instanceof \stdClass && ($req->data->allow ?? false) === true)) {
            throw new Disconnect(4501, 'unauthorized');
        }

        return new ConnectResult(
            user: $user,
            expireAt: time() + 2,
            info: ['name' => 'Alice'],
            data: ['welcome' => true],
            channels: [(string) getenv('PHPCENT_PROXY_CHANNEL')],
        );
    }, $onError),
    '/refresh' => new RefreshHandler(static function (RefreshRequest $req) use ($log): RefreshResult {
        $log('refresh', $req);

        return new RefreshResult(expired: true);
    }, $onError),
    // The JWKS of Centrifugo PRO's http_api.jwks.endpoint. The same key on
    // every run, from a fixed seed: Centrifugo caches keys by ID, so a new key
    // under a known ID would be refused.
    '/api_jwks' => new Jwks(Key::ed25519(str_repeat("\1", 32))->withKeyId('gocent-api-test')),
    default => null,
};
if ($handler === null) {
    http_response_code(404);

    return;
}
$handler->serve();
