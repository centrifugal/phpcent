<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/** The outcome of a broadcast in one channel. */
final class ChannelResult
{
    /**
     * @param string $channel the channel, as the request named it
     * @param ?PublishResult $result the publication's position in the channel's
     *     history, when it succeeded
     * @param ?\Throwable $error null when the publication succeeded; the
     *     channel's ApiException, or the error of the whole broadcast when it
     *     failed as a whole
     * @param int $attempts how many times the channel was sent: more than 1 when
     *     broadcastWithRetry retried it
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?PublishResult $result = null,
        public readonly ?\Throwable $error = null,
        public readonly int $attempts = 0,
    ) {}

    /** @internal */
    public function withAttempts(int $attempts): self
    {
        return new self($this->channel, $this->result, $this->error, $attempts);
    }
}
