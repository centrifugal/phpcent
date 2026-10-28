<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The channel state proxy of Centrifugo PRO, in preview: the function receives
 * channels becoming occupied or vacated. ProxyError, or any other exception,
 * fails the request.
 *
 * @extends ProxyHandler<NotifyChannelStateRequest, NotifyChannelStateResult>
 */
final class NotifyChannelStateHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'channel state';
    }

    protected function requestClass(): string
    {
        return NotifyChannelStateRequest::class;
    }

    protected function resultClass(): string
    {
        return NotifyChannelStateResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR;
    }
}
