<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\TimeoutException;

/**
 * Sends a request to Centrifugo: curl by default, or a PSR-18 client.
 *
 * @internal
 */
interface Transport
{
    /**
     * POSTs body to url. A reply with another status than 200 is read up to
     * Limits::ERROR_BODY bytes, and the rest drained up to Limits::DRAIN.
     *
     * @param array<string, string> $headers
     *
     * @throws NetworkException
     * @throws TimeoutException
     */
    public function post(string $url, array $headers, string $body, float $timeout, string $method): TransportResponse;

    public function close(): void;
}
