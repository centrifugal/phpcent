<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * A publication read by Client::historyAll, with the epoch of the stream it
 * was read from: its position() is where to resume reading, or a client's
 * recoverSince, with no fields copied by hand.
 *
 *     foreach ($client->historyAll(channel: 'news', limit: 100) as $item) {
 *         handle($item->publication->data);
 *         $last = $item->position();   // to resume from later
 *     }
 */
final class HistoryItem
{
    /**
     * @param Publication $publication the publication
     * @param string $epoch the epoch of the channel's stream it is in
     */
    public function __construct(
        public readonly Publication $publication,
        public readonly string $epoch,
    ) {}

    /** Where the publication is in the channel's stream. */
    public function position(): StreamPosition
    {
        return new StreamPosition(offset: $this->publication->offset, epoch: $this->epoch);
    }
}
