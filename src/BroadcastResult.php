<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\DecodeException;
use Centrifugal\Phpcent\Internal\Decode;
use Centrifugal\Phpcent\Internal\Reply;

/**
 * The outcome of a broadcast: one entry per channel, in the order the request
 * listed them.
 */
final class BroadcastResult
{
    /**
     * @param list<ChannelResult> $channels every channel's outcome
     * @param string $idempotencyKey the key the broadcast was sent with: the
     *     request's, or the one broadcastWithRetry made when the request had
     *     none. A later retry with it does not publish again into the channels
     *     which got the publication.
     * @param int $attempts how many times the broadcast was tried: 1 from
     *     broadcast, up to maxAttempts from broadcastWithRetry, 0 when it was
     *     refused before sending
     * @param bool $replied true when Centrifugo carried the broadcast out and
     *     replied for every channel, the failed ones included; false when it
     *     failed as a whole
     */
    public function __construct(
        public readonly array $channels = [],
        public readonly string $idempotencyKey = '',
        public readonly int $attempts = 0,
        public readonly bool $replied = false,
    ) {}

    /**
     * The channels which did not get the publication, with their errors, in
     * the order of the request.
     *
     * @return list<ChannelResult>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->channels, static fn(ChannelResult $c): bool => $c->error !== null));
    }

    /**
     * The result of a broadcast which failed as a whole: every channel failed
     * with $error.
     *
     * @internal
     *
     * @param list<string> $channels
     */
    public static function failedAsWhole(array $channels, \Throwable $error, int $attempts = 0, string $key = ''): self
    {
        return new self(
            array_map(static fn(string $ch): ChannelResult => new ChannelResult($ch, null, $error, $attempts), $channels),
            $key,
            $attempts,
        );
    }

    /**
     * The result of a broadcast Centrifugo replied to: one response per channel,
     * in the request's order.
     *
     * @internal
     *
     * @param list<string> $channels
     *
     * @throws DecodeException
     */
    public static function fromWire(mixed $v, string $path, array $channels, string $key): self
    {
        $d = Decode::object($v, $path);
        $responses = $d->responses ?? [];
        if (!\is_array($responses)) {
            throw new DecodeException("{$path}.responses: expected an array");
        }
        if (\count($responses) !== \count($channels)) {
            throw new DecodeException('got ' . \count($responses) . ' replies for ' . \count($channels) . ' channels');
        }
        $out = [];
        foreach (array_values($responses) as $i => $item) {
            $where = "{$path}.responses[{$i}]";
            $r = Decode::object($item, $where);
            if (isset($r->error)) {
                $out[] = new ChannelResult($channels[$i], null, Reply::apiError($r->error, "{$where}.error", ''), 1);
            } else {
                $out[] = new ChannelResult($channels[$i], PublishResult::fromWire($r->result ?? new \stdClass(), "{$where}.result"), null, 1);
            }
        }

        return new self($out, $key, 1, true);
    }

    /**
     * $this, or BroadcastFailedChannelsException carrying it when any channel
     * failed.
     *
     * @internal
     *
     * @throws BroadcastFailedChannelsException
     */
    public function check(): self
    {
        $failed = $this->failed();
        if ($failed !== []) {
            throw new BroadcastFailedChannelsException($failed, \count($this->channels), $this);
        }

        return $this;
    }
}
