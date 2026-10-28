<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The subscribe proxy: the function decides whether a client may subscribe to a
 * channel. ProxyError rejects the subscription; Disconnect closes the
 * connection.
 *
 * @extends ProxyHandler<SubscribeRequest, SubscribeResult>
 */
final class SubscribeHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'subscribe';
    }

    protected function requestClass(): string
    {
        return SubscribeRequest::class;
    }

    protected function resultClass(): string
    {
        return SubscribeResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
