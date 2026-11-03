<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\DeviceListResult;
use Centrifugal\Phpcent\DeviceTopicListResult;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\HistoryItem;
use Centrifugal\Phpcent\HistoryResult;
use Centrifugal\Phpcent\MapEntry;
use Centrifugal\Phpcent\MapReadStateResult;
use Centrifugal\Phpcent\MapStateItem;
use Centrifugal\Phpcent\Publication;
use Centrifugal\Phpcent\StreamPosition;
use Centrifugal\Phpcent\UserTopicListResult;

/**
 * The paged methods' iterators, which Client's historyAll and the other
 * <method>All methods return: every item of every page, a page read when the
 * iteration reaches it. Each takes the first request and a function making
 * one page's call. The request is checked when the method is called, before
 * anything is sent; the caller's request is never changed.
 *
 * @internal
 */
final class Pages
{
    /** Refuses a pageTimeout which is not a positive number of seconds, at the call. */
    public static function checkPageTimeout(?float $pageTimeout): void
    {
        if ($pageTimeout !== null && (!is_finite($pageTimeout) || $pageTimeout <= 0)) {
            throw new InvalidRequestException('pageTimeout must be a positive number of seconds: null for requestTimeout');
        }
    }

    /**
     * History by since: each page after the first from the last publication's
     * offset and the result's epoch, until a page shorter than limit.
     *
     * @param \Closure(HistoryRequest): HistoryResult $call
     *
     * @return \Generator<int, HistoryItem>
     */
    public static function history(HistoryRequest $first, \Closure $call): \Generator
    {
        if ($first->limit < 1) {
            // 0 asks for the position alone, -1 for everything in one call.
            throw new InvalidRequestException('invalid history request: historyAll needs a limit of 1 or more: the size of a page');
        }
        if ($first->reverse) {
            throw new InvalidRequestException('invalid history request: historyAll cannot read in reverse: since has no counterpart running back');
        }
        Validate::encode($first);

        return self::historyPages($first, $call);
    }

    /**
     * @param \Closure(HistoryRequest): HistoryResult $call
     *
     * @return \Generator<int, HistoryItem>
     */
    private static function historyPages(HistoryRequest $req, \Closure $call): \Generator
    {
        while (true) {
            $page = $call($req);
            foreach ($page->publications as $pub) {
                yield new HistoryItem($pub, $page->epoch);
            }
            $last = end($page->publications);
            if ($last === false || \count($page->publications) < $req->limit) {
                return;
            }
            $req = clone $req;
            $req->since = new StreamPosition(offset: $last->offset, epoch: $page->epoch);
        }
    }

    /**
     * @param \Closure(DeviceListRequest): DeviceListResult $call
     *
     * @return \Generator<int, \Centrifugal\Phpcent\Device>
     */
    public static function deviceList(DeviceListRequest $first, \Closure $call): \Generator
    {
        Validate::encode($first);

        return self::cursor(
            $first,
            $call,
            static fn(DeviceListResult $r): array => [$r->items, $r->nextCursor],
            static function (DeviceListRequest $req, string $cursor): DeviceListRequest {
                $req = clone $req;
                $req->cursor = $cursor;

                return $req;
            },
        );
    }

    /**
     * @param \Closure(DeviceTopicListRequest): DeviceTopicListResult $call
     *
     * @return \Generator<int, \Centrifugal\Phpcent\DeviceTopic>
     */
    public static function deviceTopicList(DeviceTopicListRequest $first, \Closure $call): \Generator
    {
        Validate::encode($first);

        return self::cursor(
            $first,
            $call,
            static fn(DeviceTopicListResult $r): array => [$r->items, $r->nextCursor],
            static function (DeviceTopicListRequest $req, string $cursor): DeviceTopicListRequest {
                $req = clone $req;
                $req->cursor = $cursor;

                return $req;
            },
        );
    }

    /**
     * @param \Closure(UserTopicListRequest): UserTopicListResult $call
     *
     * @return \Generator<int, \Centrifugal\Phpcent\UserTopic>
     */
    public static function userTopicList(UserTopicListRequest $first, \Closure $call): \Generator
    {
        Validate::encode($first);

        return self::cursor(
            $first,
            $call,
            static fn(UserTopicListResult $r): array => [$r->items, $r->nextCursor],
            static function (UserTopicListRequest $req, string $cursor): UserTopicListRequest {
                $req = clone $req;
                $req->cursor = $cursor;

                return $req;
            },
        );
    }

    /**
     * A map's state by cursor, every page after the first at the first one's
     * revision - its offset and epoch as revisionOffset and revisionEpoch,
     * unless the request sets them - so that the map's stream reset between
     * pages fails with UnrecoverablePositionException instead of mixing two
     * states.
     *
     * @param \Closure(MapReadStateRequest): MapReadStateResult $call
     *
     * Each entry comes with the state's position: the revision every page is
     * read at.
     *
     * @return \Generator<int, MapStateItem>
     */
    public static function mapReadState(MapReadStateRequest $first, \Closure $call): \Generator
    {
        Validate::encode($first);
        $asked = $first->revisionOffset !== 0 || $first->revisionEpoch !== ''
            ? new StreamPosition(offset: $first->revisionOffset, epoch: $first->revisionEpoch)
            : null;

        return self::cursor(
            $first,
            $call,
            static function (MapReadStateResult $r, MapReadStateResult $firstPage) use ($asked): array {
                $state = $asked ?? $firstPage->position();

                return [array_map(static fn(MapEntry $e): MapStateItem => new MapStateItem($e, $state), $r->entries), $r->cursor];
            },
            static function (MapReadStateRequest $req, string $cursor, MapReadStateResult $firstPage): MapReadStateRequest {
                $req = clone $req;
                $req->cursor = $cursor;
                if ($req->revisionOffset === 0 && $req->revisionEpoch === '') {
                    $req->revisionOffset = $firstPage->offset;
                    $req->revisionEpoch = $firstPage->epoch;
                }

                return $req;
            },
        );
    }

    /**
     * Pages by cursor: each page after the first from the cursor of the one
     * before, until an empty one.
     *
     * @template TRequest
     * @template TResult
     * @template TItem
     *
     * @param TRequest $req
     * @param \Closure(TRequest): TResult $call
     * @param \Closure(TResult, TResult): array{list<TItem>, string} $items the
     *     page's items and next cursor, given the page and the first one
     * @param \Closure(TRequest, string, TResult): TRequest $next the request for
     *     the page at a cursor, given the first page
     *
     * @return \Generator<int, TItem>
     */
    private static function cursor(mixed $req, \Closure $call, \Closure $items, \Closure $next): \Generator
    {
        $first = null;
        while (true) {
            $page = $call($req);
            $first ??= $page;
            [$list, $cursor] = $items($page, $first);
            foreach ($list as $item) {
                yield $item;
            }
            if ($cursor === '') {
                return;
            }
            $req = $next($req, $cursor, $first);
        }
    }
}
