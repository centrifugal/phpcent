<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The map remove proxy: the function decides whether a client may remove a key
 * from a map channel.
 *
 * @extends ProxyHandler<MapRemoveRequest, MapRemoveResult>
 */
final class MapRemoveHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'map remove';
    }

    protected function requestClass(): string
    {
        return MapRemoveRequest::class;
    }

    protected function resultClass(): string
    {
        return MapRemoveResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
