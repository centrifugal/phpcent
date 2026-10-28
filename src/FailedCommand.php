<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/** The failure of one command of a batch. */
final class FailedCommand
{
    /**
     * @param int $index the command's position in the batch, from zero
     * @param string $method its API method, such as "publish"
     * @param \Throwable $error the error its Pending throws: an ApiException, or
     *     a BroadcastFailedChannelsException for a broadcast which failed in one
     *     or more channels
     */
    public function __construct(
        public readonly int $index,
        public readonly string $method,
        public readonly \Throwable $error,
    ) {}
}
