<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/** A failed attempt of Client::broadcastWithRetry, given to its onAttemptError. */
final class BroadcastAttempt
{
    /**
     * @param int $attempt the attempt which failed, from 1
     * @param \Throwable $error its error: BroadcastFailedChannelsException when
     *     Centrifugo replied and some channels failed, otherwise the error of the
     *     request as a whole
     * @param list<ChannelResult> $retry the channels the next attempt sends,
     *     with the errors this attempt failed them with. A broadcast to many
     *     channels which all failed lists every one: log a count or a few.
     * @param BroadcastResult $result every channel's outcome so far, as the call
     *     would return it if it stopped now
     * @param string $idempotencyKey the key every attempt is sent with
     */
    public function __construct(
        public readonly int $attempt,
        public readonly \Throwable $error,
        public readonly array $retry,
        public readonly BroadcastResult $result,
        public readonly string $idempotencyKey,
    ) {}
}
