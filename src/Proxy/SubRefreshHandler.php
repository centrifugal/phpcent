<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The sub refresh proxy: the function decides whether a subscription about to
 * expire lives on, and until when. To end it, return a result with expired
 * set. Any exception fails the request, and Centrifugo keeps the subscription
 * for another minute before asking again.
 *
 * @extends ProxyHandler<SubRefreshRequest, SubRefreshResult>
 */
final class SubRefreshHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'sub refresh';
    }

    protected function requestClass(): string
    {
        return SubRefreshRequest::class;
    }

    protected function resultClass(): string
    {
        return SubRefreshResult::class;
    }

    protected function answers(): int
    {
        return self::RESULT_ONLY;
    }
}
