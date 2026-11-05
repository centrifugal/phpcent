<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The refresh proxy: the function decides whether a connection about to expire
 * lives on, and until when. To end it, return a result with expired set. Any
 * exception fails the request, and Centrifugo keeps the connection for another
 * minute before asking again.
 *
 * @extends ProxyHandler<RefreshRequest, RefreshResult>
 */
final class RefreshHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'refresh';
    }

    protected function requestClass(): string
    {
        return RefreshRequest::class;
    }

    protected function resultClass(): string
    {
        return RefreshResult::class;
    }

    protected function answers(): int
    {
        return self::RESULT_ONLY;
    }
}
