<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Internal\Encode;

/** The outcome of a broadcast in one channel. */
final class ChannelResult implements \JsonSerializable
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

    /** @return array{channel: string, result?: PublishResult, error?: array{code?: int, message: string}, attempts: int} */
    public function jsonSerialize(): array
    {
        $out = ['channel' => $this->channel];
        if ($this->error !== null) {
            $out['error'] = Encode::error($this->error);
        } else {
            $out['result'] = $this->result ?? new PublishResult();
        }
        $out['attempts'] = $this->attempts;

        return $out;
    }

    /** @internal */
    public function withAttempts(int $attempts): self
    {
        return new self($this->channel, $this->result, $this->error, $attempts);
    }
}
