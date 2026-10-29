<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The cache empty proxy of Centrifugo PRO: the function learns that a client
 * subscribed to a channel in cache recovery mode with nothing to recover, and
 * may publish the current state - then it returns a result with populated set,
 * for Centrifugo to recover once more. Any exception fails the request, and the
 * client which subscribed is disconnected to reconnect.
 *
 * @extends ProxyHandler<NotifyCacheEmptyRequest, NotifyCacheEmptyResult>
 */
final class NotifyCacheEmptyHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'cache empty';
    }

    protected function requestClass(): string
    {
        return NotifyCacheEmptyRequest::class;
    }

    protected function resultClass(): string
    {
        return NotifyCacheEmptyResult::class;
    }

    protected function answers(): int
    {
        return self::RESULT_ONLY;
    }
}
