<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The map publish proxy: the function decides whether a client may publish a
 * key into a map channel, and may change the publication.
 *
 * @extends ProxyHandler<MapPublishRequest, MapPublishResult>
 */
final class MapPublishHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'map publish';
    }

    protected function requestClass(): string
    {
        return MapPublishRequest::class;
    }

    protected function resultClass(): string
    {
        return MapPublishResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
