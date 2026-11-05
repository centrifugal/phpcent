<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

use Centrifugal\Phpcent\BroadcastResult;
use Centrifugal\Phpcent\ChannelResult;
use Centrifugal\Phpcent\Retry;

/**
 * A broadcast Centrifugo carried out which failed in one or more of its
 * channels, up to all of them. $result holds every channel's outcome.
 *
 * It is not an ApiException of its parts: catch (UnknownChannelException) does
 * not catch a broadcast in which one channel of many was unknown, nor one into
 * a single channel - the broadcast as a whole is not unknown. Look at each
 * channel's error in $failed.
 */
final class BroadcastFailedChannelsException extends \RuntimeException implements CentException
{
    /**
     * @param list<ChannelResult> $failed the channels which failed, with their
     *     errors, in the order of the request
     * @param int $total how many channels the broadcast had
     * @param BroadcastResult $result every channel's outcome
     */
    public function __construct(
        public readonly array $failed,
        public readonly int $total,
        public readonly BroadcastResult $result,
    ) {
        $msg = Failures::message(
            'broadcast',
            'channels',
            'got the publication',
            $total,
            array_map(static fn(ChannelResult $c): array => [$c->channel, $c->error], $failed),
        );
        if ($this->retryableChannels() !== []) {
            $msg .= ' (retry only retryableChannels(), with the same idempotencyKey)';
        }
        parent::__construct($msg);
    }

    /**
     * The channels whose failure is retryable, in the order of the request: the
     * channels a retry should go to, with the same idempotencyKey. Channels
     * which failed for good, such as an unknown channel, are left out.
     *
     * @return list<string>
     */
    public function retryableChannels(): array
    {
        $out = [];
        foreach ($this->failed as $f) {
            if ($f->error !== null && Retry::retryable($f->error)) {
                $out[] = $f->channel;
            }
        }

        return $out;
    }
}
