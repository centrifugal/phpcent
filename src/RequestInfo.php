<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/** One HTTP request to Centrifugo's API, for onRequest. */
final class RequestInfo
{
    /**
     * @param string $method the API method: "publish", say, or "batch" for a batch
     * @param int $commands how many commands the request carried: 1, or a batch's size
     * @param int $failed how many of them Centrifugo answered with an error. A
     *     broadcast which failed in some channels is not counted: its command
     *     was answered.
     * @param float $duration seconds from before getting the endpoint and
     *     credentials to the reply read
     * @param int $statusCode the HTTP status of the reply, 0 without one
     * @param ?\Throwable $error the request's own error: the network, a timeout,
     *     an HTTP status, a reply which is not Centrifugo's, a request refused
     *     before sending - or, in the transport error mode, the error of its one
     *     command. Null when Centrifugo's reply was read.
     */
    public function __construct(
        public readonly string $method,
        public readonly int $commands,
        public readonly int $failed,
        public readonly float $duration,
        public readonly int $statusCode,
        public readonly ?\Throwable $error,
    ) {}
}
