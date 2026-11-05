<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The publish proxy: the function decides whether a client may publish into a
 * channel, and may change the publication. ProxyError rejects it; Disconnect
 * closes the connection.
 *
 * @extends ProxyHandler<PublishRequest, PublishResult>
 */
final class PublishHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'publish';
    }

    protected function requestClass(): string
    {
        return PublishRequest::class;
    }

    protected function resultClass(): string
    {
        return PublishResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
