<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\DeviceFilter;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Exception\UnrecoverablePositionException;
use Centrifugal\Phpcent\HistoryItem;
use Centrifugal\Phpcent\HistoryResult;
use Centrifugal\Phpcent\MapPublishResult;
use Centrifugal\Phpcent\MapReadStateResult;
use Centrifugal\Phpcent\MapReadStreamResult;
use Centrifugal\Phpcent\MapRemoveResult;
use Centrifugal\Phpcent\MapStateItem;
use Centrifugal\Phpcent\PublishResult;
use Centrifugal\Phpcent\StreamPosition;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The <method>All iterators (spec 3.8) against a fake holding $n items, in
 * pages of the request's limit.
 */
final class PagesTest extends TestCase
{
    private const int PAGE = 3;

    /**
     * A fake answering a cursor method from $n items: the cursor is the
     * index of the page's first item. $failAt makes that page's call fail.
     */
    private static function cursorFake(string $method, string $itemsField, string $nextField, int $n, int $failAt = -1): FakeCentrifugo
    {
        $pages = 0;

        return new FakeCentrifugo(static function (string $m, mixed $json) use ($method, $itemsField, $nextField, $n, $failAt, &$pages): mixed {
            self::assertSame($method, $m);
            self::assertInstanceOf(\stdClass::class, $json);
            if ($pages++ === $failAt) {
                return FakeCentrifugo::json(200, ['error' => ['code' => 100, 'message' => 'internal server error']]);
            }
            $from = (int) ($json->cursor ?? 0);
            $limit = (int) ($json->limit ?? self::PAGE);
            $items = [];
            for ($i = $from; $i < min($n, $from + $limit); $i++) {
                $items[] = ['id' => "i{$i}", 'key' => "i{$i}"];
            }
            $next = $from + $limit < $n ? (string) ($from + $limit) : '';

            return [$itemsField => $items, $nextField => $next, 'offset' => 7, 'epoch' => 'e'];
        });
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function cursorMethods(): iterable
    {
        // name, API method, items, next cursor, the item's field
        yield 'deviceListAll' => ['deviceListAll', 'device_list', 'items', 'next_cursor', 'id'];
        yield 'deviceTopicListAll' => ['deviceTopicListAll', 'device_topic_list', 'items', 'next_cursor', 'id'];
        yield 'userTopicListAll' => ['userTopicListAll', 'user_topic_list', 'items', 'next_cursor', 'id'];
        yield 'mapReadStateAll' => ['mapReadStateAll', 'map_read_state', 'entries', 'cursor', 'key'];
    }

    private static function id(object $item, string $field): mixed
    {
        return $item instanceof MapStateItem ? $item->entry->{$field} : $item->{$field};
    }

    /** @return array<string, mixed> */
    private static function args(string $name): array
    {
        return $name === 'mapReadStateAll' ? ['channel' => 'm', 'limit' => self::PAGE] : ['limit' => self::PAGE];
    }

    #[DataProvider('cursorMethods')]
    public function testCursorPages(string $name, string $method, string $itemsField, string $nextField, string $idField): void
    {
        // No items, one, exactly a page, several pages and a part.
        foreach ([0 => 1, 1 => 1, self::PAGE => 1, 2 * self::PAGE + 1 => 3] as $n => $requests) {
            $fake = self::cursorFake($method, $itemsField, $nextField, $n);
            $ids = [];
            foreach (FakeCentrifugo::client($fake)->{$name}(...self::args($name)) as $item) {
                $ids[] = self::id($item, $idField);
            }
            self::assertSame(array_map(static fn(int $i): string => "i{$i}", $n === 0 ? [] : range(0, $n - 1)), $ids, "{$name}: {$n} items");
            self::assertCount($requests, $fake->requests, "{$name}: {$n} items");
            // Each request after the first carries the cursor of the reply before.
            foreach ($fake->requests as $i => $r) {
                self::assertInstanceOf(\stdClass::class, $r['json']);
                self::assertSame($i === 0 ? null : (string) ($i * self::PAGE), $r['json']->cursor ?? null);
            }
        }
    }

    #[DataProvider('cursorMethods')]
    public function testLeavingEarlyReadsNoFurtherPage(string $name, string $method, string $itemsField, string $nextField, string $idField): void
    {
        $fake = self::cursorFake($method, $itemsField, $nextField, 10);
        $it = FakeCentrifugo::client($fake)->{$name}(...self::args($name));
        // Nothing is read before the iteration starts.
        self::assertSame([], $fake->requests);
        foreach ($it as $i => $item) {
            self::assertSame("i{$i}", self::id($item, $idField));
            if ($i === self::PAGE) {
                break; // the first item of the second page
            }
        }
        self::assertCount(2, $fake->requests);
    }

    #[DataProvider('cursorMethods')]
    public function testErrorEndsTheIteration(string $name, string $method, string $itemsField, string $nextField, string $idField): void
    {
        $fake = self::cursorFake($method, $itemsField, $nextField, 10, failAt: 1);
        $seen = 0;
        try {
            foreach (FakeCentrifugo::client($fake)->{$name}(...self::args($name)) as $item) {
                self::assertSame("i{$seen}", self::id($item, $idField));
                $seen++;
            }
            self::fail('no exception');
        } catch (\Centrifugal\Phpcent\Exception\InternalException) {
            self::assertSame(self::PAGE, $seen);
        }
    }

    public function testKeysRunOnAcrossPages(): void
    {
        $fake = self::cursorFake('device_list', 'items', 'next_cursor', 7);
        $all = iterator_to_array(FakeCentrifugo::client($fake)->deviceListAll(limit: self::PAGE));
        self::assertSame(range(0, 6), array_keys($all));
    }

    public function testCallersRequestIsSentAsGiven(): void
    {
        $fake = self::cursorFake('device_list', 'items', 'next_cursor', 4);
        iterator_to_array(FakeCentrifugo::client($fake)->deviceListAll(filter: new DeviceFilter(users: ['u']), includeMeta: true, limit: self::PAGE));
        self::assertSame('{"filter":{"users":["u"]},"include_meta":true,"limit":3}', $fake->requests[0]['body']);
        self::assertSame('{"filter":{"users":["u"]},"include_meta":true,"cursor":"3","limit":3}', $fake->requests[1]['body']);
    }

    public function testMapReadStateAllPinsTheFirstPagesRevision(): void
    {
        $epochs = ['e1', 'e1', 'e2'];
        $fake = new FakeCentrifugo(static function (string $m, mixed $json) use (&$epochs): mixed {
            \assert($json instanceof \stdClass);
            $from = (int) ($json->cursor ?? 0);

            return ['entries' => [['key' => "k{$from}"]], 'cursor' => $from < 2 ? (string) ($from + 1) : '', 'offset' => 10 + $from, 'epoch' => array_shift($epochs)];
        });
        $keys = [];
        foreach (FakeCentrifugo::client($fake)->mapReadStateAll(channel: 'm', limit: 1) as $e) {
            $keys[] = $e->entry->key;
        }
        self::assertSame(['k0', 'k1', 'k2'], $keys);
        self::assertSame('{"channel":"m","limit":1}', $fake->requests[0]['body']);
        // Every later page at the first one's offset and epoch.
        self::assertSame('{"channel":"m","cursor":"1","limit":1,"revision_offset":10,"revision_epoch":"e1"}', $fake->requests[1]['body']);
        self::assertSame('{"channel":"m","cursor":"2","limit":1,"revision_offset":10,"revision_epoch":"e1"}', $fake->requests[2]['body']);

        // Or the caller's.
        $epochs = ['e1', 'e1', 'e2'];
        $fake2 = new FakeCentrifugo(static fn(string $m, mixed $json): array => ['entries' => [], 'cursor' => $json instanceof \stdClass && isset($json->cursor) ? '' : '1', 'offset' => 10, 'epoch' => 'e1']);
        iterator_to_array(FakeCentrifugo::client($fake2)->mapReadStateAll(channel: 'm', revisionOffset: 5, revisionEpoch: 'mine'));
        self::assertSame('{"channel":"m","cursor":"1","revision_offset":5,"revision_epoch":"mine"}', $fake2->requests[1]['body']);
    }

    public function testMapReadStateAllStaleRevision(): void
    {
        $fake = new FakeCentrifugo(static fn(string $m, mixed $json): mixed => $json instanceof \stdClass && isset($json->revision_epoch)
            ? FakeCentrifugo::json(200, ['error' => ['code' => 112, 'message' => 'unrecoverable position']])
            : ['entries' => [['key' => 'a']], 'cursor' => '1', 'offset' => 1, 'epoch' => 'e']);
        $keys = [];
        $this->expectException(UnrecoverablePositionException::class);
        try {
            foreach (FakeCentrifugo::client($fake)->mapReadStateAll(channel: 'm') as $e) {
                $keys[] = $e->entry->key;
            }
        } finally {
            self::assertSame(['a'], $keys);
        }
    }

    // History.

    /** A fake holding publications with offsets 1 to $n, in epoch "e". */
    private static function historyFake(int $n, int $failAt = -1): FakeCentrifugo
    {
        $pages = 0;

        return new FakeCentrifugo(static function (string $m, mixed $json) use ($n, $failAt, &$pages): mixed {
            self::assertSame('history', $m);
            \assert($json instanceof \stdClass);
            if ($pages++ === $failAt) {
                return FakeCentrifugo::json(200, ['error' => ['code' => 100, 'message' => 'internal server error']]);
            }
            $after = (int) ($json->since->offset ?? 0);
            $pubs = [];
            for ($o = $after + 1; $o <= min($n, $after + (int) $json->limit); $o++) {
                $pubs[] = ['data' => $o, 'offset' => $o];
            }

            return ['publications' => $pubs, 'offset' => $n, 'epoch' => 'e'];
        });
    }

    public function testHistoryPages(): void
    {
        // No publications, one, exactly a page (and the empty page after it),
        // several pages and a part.
        foreach ([0 => 1, 1 => 1, self::PAGE => 2, 2 * self::PAGE + 1 => 3] as $n => $requests) {
            $fake = self::historyFake($n);
            $offsets = [];
            foreach (FakeCentrifugo::client($fake)->historyAll(channel: 'ch', limit: self::PAGE) as $pub) {
                $offsets[] = $pub->publication->offset;
            }
            self::assertSame($n === 0 ? [] : range(1, $n), $offsets, "{$n} publications");
            self::assertCount($requests, $fake->requests, "{$n} publications");
            self::assertSame('{"channel":"ch","limit":3}', $fake->requests[0]['body']);
            foreach (\array_slice($fake->requests, 1) as $i => $r) {
                // since: the last publication of the page before, in its epoch.
                self::assertSame('{"channel":"ch","limit":3,"since":{"offset":' . (($i + 1) * self::PAGE) . ',"epoch":"e"}}', $r['body']);
            }
        }
    }

    public function testHistoryFromAPosition(): void
    {
        $fake = self::historyFake(5);
        $it = FakeCentrifugo::client($fake)->historyAll(channel: 'ch', limit: 10, since: new StreamPosition(offset: 3, epoch: 'e'));
        self::assertSame([4, 5], array_map(static fn(HistoryItem $p): int => $p->publication->offset, iterator_to_array($it)));
        self::assertSame('{"channel":"ch","limit":10,"since":{"offset":3,"epoch":"e"}}', $fake->last()['body']);
    }

    public function testHistoryLeavingEarlyAndErrors(): void
    {
        $fake = self::historyFake(10);
        foreach (FakeCentrifugo::client($fake)->historyAll(channel: 'ch', limit: self::PAGE) as $pub) {
            if ($pub->publication->offset === 2) {
                break;
            }
        }
        self::assertCount(1, $fake->requests);

        $fake = self::historyFake(10, failAt: 1);
        $seen = 0;
        try {
            foreach (FakeCentrifugo::client($fake)->historyAll(channel: 'ch', limit: self::PAGE) as $pub) {
                $seen++;
            }
            self::fail('no exception');
        } catch (\Centrifugal\Phpcent\Exception\InternalException) {
            self::assertSame(self::PAGE, $seen);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function historyRefusals(): iterable
    {
        yield 'no limit' => [['channel' => 'ch'], 'limit of 1 or more'];
        yield 'limit -1' => [['channel' => 'ch', 'limit' => -1], 'limit of 1 or more'];
        yield 'reverse' => [['channel' => 'ch', 'limit' => 10, 'reverse' => true], 'reverse'];
        yield 'no channel' => [['channel' => '', 'limit' => 10], 'invalid history request: channel is required'];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('historyRefusals')]
    public function testHistoryRefusalsAtTheCall(array $args, string $message): void
    {
        $fake = self::historyFake(3);
        $c = FakeCentrifugo::client($fake);
        try {
            // Refused at the call, not on the first iteration.
            $c->historyAll(...$args);
            self::fail('not refused');
        } catch (InvalidRequestException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $fake->requests);
    }

    public function testCursorRequestCheckedAtTheCall(): void
    {
        $fake = new FakeCentrifugo();
        $this->expectExceptionMessage('invalid map_read_state request: channel is required');
        try {
            FakeCentrifugo::client($fake)->mapReadStateAll(channel: '');
        } finally {
            self::assertSame([], $fake->requests);
        }
    }

    // Positions.

    public function testPositions(): void
    {
        $want = new StreamPosition(offset: 42, epoch: 'ep');
        self::assertEquals($want, (new PublishResult(offset: 42, epoch: 'ep'))->position());
        self::assertEquals($want, (new HistoryResult(offset: 42, epoch: 'ep'))->position());
        self::assertEquals($want, (new MapReadStateResult(offset: 42, epoch: 'ep'))->position());
        self::assertEquals($want, (new MapReadStreamResult(offset: 42, epoch: 'ep'))->position());
        self::assertEquals($want, (new MapPublishResult(offset: 42, epoch: 'ep'))->position());
        self::assertEquals($want, (new MapRemoveResult(offset: 42, epoch: 'ep'))->position());
        // Not part of a result's JSON.
        self::assertSame('{"offset":42,"epoch":"ep"}', json_encode(new PublishResult(offset: 42, epoch: 'ep')));
    }

    public function testItemsCarryTheirPositions(): void
    {
        // History: each publication's offset, in its page's epoch.
        $fake = self::historyFake(4);
        $positions = array_map(static fn(HistoryItem $i): StreamPosition => $i->position(), iterator_to_array(FakeCentrifugo::client($fake)->historyAll(channel: 'ch', limit: self::PAGE)));
        self::assertEquals(array_map(static fn(int $o): StreamPosition => new StreamPosition(offset: $o, epoch: 'e'), range(1, 4)), $positions);

        // A map's state: the first page's position on every entry, later
        // pages read at it, even when a later page reports another.
        $fake = new FakeCentrifugo(static function (string $m, mixed $json): array {
            \assert($json instanceof \stdClass);
            $from = (int) ($json->cursor ?? 0);

            return ['entries' => [['key' => "k{$from}"]], 'cursor' => $from < 1 ? '1' : '', 'offset' => 10 + $from, 'epoch' => 'e'];
        });
        $states = array_map(static fn(MapStateItem $i): StreamPosition => $i->state, iterator_to_array(FakeCentrifugo::client($fake)->mapReadStateAll(channel: 'm')));
        self::assertEquals([new StreamPosition(offset: 10, epoch: 'e'), new StreamPosition(offset: 10, epoch: 'e')], $states);
        // Or the revision the call asked for.
        $states = array_map(static fn(MapStateItem $i): StreamPosition => $i->state, iterator_to_array(FakeCentrifugo::client($fake)->mapReadStateAll(channel: 'm', revisionOffset: 5, revisionEpoch: 'mine')));
        self::assertEquals([new StreamPosition(offset: 5, epoch: 'mine'), new StreamPosition(offset: 5, epoch: 'mine')], $states);
    }

    public function testWhatCameAfterAPublication(): void
    {
        $fake = new FakeCentrifugo(static fn(string $m, mixed $json): mixed => $m === 'publish'
            ? ['offset' => 7, 'epoch' => 'e']
            : ['publications' => [], 'offset' => 7, 'epoch' => 'e']);
        $c = FakeCentrifugo::client($fake);
        $pub = $c->publish(channel: 'ch', data: 1);
        iterator_to_array($c->historyAll(channel: 'ch', limit: 100, since: $pub->position()));
        self::assertSame('{"channel":"ch","limit":100,"since":{"offset":7,"epoch":"e"}}', $fake->last()['body']);
    }
}
