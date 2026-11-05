<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The shared poll refresh proxy: the function returns the current data of the
 * items tracked in a shared poll channel. ProxyError, or any other exception,
 * fails the refresh: Centrifugo tries again at the next interval.
 *
 * @extends ProxyHandler<SharedPollRefreshRequest, SharedPollRefreshResult>
 */
final class SharedPollRefreshHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'shared poll refresh';
    }

    protected function requestClass(): string
    {
        return SharedPollRefreshRequest::class;
    }

    protected function resultClass(): string
    {
        return SharedPollRefreshResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR;
    }
}
