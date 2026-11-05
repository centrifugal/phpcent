<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The connect proxy: the function authenticates a connecting client and returns
 * its user and initial state. ProxyError rejects the connection; Disconnect
 * closes it with that code.
 *
 *     $handler = new ConnectHandler(function (ConnectRequest $req, HttpRequest $http): ConnectResult {
 *         $user = sessionUser($http->header('cookie'))   // passed on with http_headers
 *             ?? throw new Disconnect(4501, 'unauthorized');
 *         return new ConnectResult(user: $user);
 *     });
 *
 * @extends ProxyHandler<ConnectRequest, ConnectResult>
 */
final class ConnectHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'connect';
    }

    protected function requestClass(): string
    {
        return ConnectRequest::class;
    }

    protected function resultClass(): string
    {
        return ConnectResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
