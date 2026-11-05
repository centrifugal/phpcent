<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * An entry read by Client::mapReadStateAll, with the position of the state it
 * was read at: following the state with mapReadStream from $state - as its
 * sinceOffset and sinceEpoch - gets every change made since.
 */
final class MapStateItem
{
    /**
     * @param MapEntry $entry the entry
     * @param StreamPosition $state the position of the map's state the entry
     *     was read at: the first page's, every later page being read at it -
     *     or the revision the call asked for
     */
    public function __construct(
        public readonly MapEntry $entry,
        public readonly StreamPosition $state,
    ) {}
}
