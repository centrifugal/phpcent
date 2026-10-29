<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Integration;

use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\BatchFailedCommandsException;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\HttpException;
use Centrifugal\Phpcent\Exception\NotFoundException;
use Centrifugal\Phpcent\Exception\UnknownChannelException;
use Centrifugal\Phpcent\RawJson;
use Centrifugal\Phpcent\Retry;
use Centrifugal\Phpcent\Token\ConnectionClaims;
use Centrifugal\Phpcent\Token\Key;
use Centrifugal\Phpcent\Token\Token;

/** The API client against a real Centrifugo. */
final class ApiTest extends IntegrationTestCase
{
    public function testPublishAndHistory(): void
    {
        $c = $this->client();
        $ch = $this->unique();
        $raw = '{"n":1.0,"big":9007199254740993,"empty":{},"keys":{"0":"a"}}';
        $res = $c->publish(channel: $ch, data: new RawJson($raw), tags: ['k' => 'v']);
        self::assertSame(1, $res->offset);
        self::assertNotSame('', $res->epoch);
        $pubs = $c->history(channel: $ch, limit: 10)->publications;
        self::assertCount(1, $pubs);
        self::assertSame(['k' => 'v'], $pubs[0]->tags);
        self::assertSame($raw, json_encode($pubs[0]->data, \JSON_PRESERVE_ZERO_FRACTION));
        // Sent back unchanged, the payload is the same JSON value.
        $c->publish(channel: $ch, data: $pubs[0]->data);
        $again = $c->history(channel: $ch, limit: 10, reverse: true)->publications[0];
        self::assertSame($raw, json_encode($again->data, \JSON_PRESERVE_ZERO_FRACTION));
        $c->historyRemove(channel: $ch);
        self::assertSame([], $c->history(channel: $ch, limit: 10)->publications);
    }

    public function testIdempotencyKey(): void
    {
        $c = $this->client();
        $ch = $this->unique();
        $first = $c->publish(channel: $ch, data: 1, idempotencyKey: 'k1');
        $second = $c->publish(channel: $ch, data: 2, idempotencyKey: 'k1');
        self::assertSame($first->offset, $second->offset);
        self::assertCount(1, $c->history(channel: $ch, limit: 10)->publications);
    }

    public function testBroadcast(): void
    {
        $c = $this->client();
        [$a, $b] = [$this->unique('a-'), $this->unique('b-')];
        $res = $c->broadcast(channels: [$a, $b], data: ['x' => 1]);
        self::assertSame([1, 1], array_map(static fn($ch) => $ch->result?->offset, $res->channels));
        try {
            $c->broadcast(channels: [$a, 'unknown:x'], data: ['x' => 2]);
            self::fail('no error');
        } catch (BroadcastFailedChannelsException $e) {
            self::assertSame(['unknown:x'], array_map(static fn($f) => $f->channel, $e->failed));
            self::assertInstanceOf(UnknownChannelException::class, $e->failed[0]->error);
            self::assertNotNull($e->result->channels[0]->result);
            self::assertFalse(Retry::retryable($e));
        }
    }

    public function testBroadcastWithRetry(): void
    {
        $c = $this->client();
        $a = $this->unique();
        $res = $c->broadcastWithRetry(channels: [$a], data: 1);
        self::assertStringStartsWith('phpcent-', $res->idempotencyKey);
        // Retried later with the key, nothing is published again.
        $c->broadcast(channels: [$a], data: 1, idempotencyKey: $res->idempotencyKey);
        self::assertCount(1, $c->history(channel: $a, limit: 10)->publications);
    }

    public function testBatch(): void
    {
        $c = $this->client();
        $ch = $this->unique();
        $b = $c->newBatch();
        $pub = $b->publish(channel: $ch, data: 1);
        $hist = $b->history(channel: $ch, limit: 1);
        $bad = $b->publish(channel: 'unknown:x', data: 1);
        try {
            $b->send();
            self::fail('no error');
        } catch (BatchFailedCommandsException $e) {
            self::assertSame([2], array_map(static fn($f) => $f->index, $e->failed));
        }
        self::assertSame(1, $pub->result()->offset);
        self::assertCount(1, $hist->result()->publications);
        $this->expectException(UnknownChannelException::class);
        $bad->result();
    }

    public function testRefusedRequestIsNotRetryable(): void
    {
        try {
            $this->client()->history(channel: 'unknown:x');
            self::fail('no error');
        } catch (ApiException $e) {
            self::assertFalse(Retry::retryable($e));
        }
    }

    public function testProMethod(): void
    {
        // A PRO method on Centrifugo OSS: a 404 which says so, and in a batch a
        // 104 which says so too. Against Centrifugo PRO it succeeds.
        $c = $this->client();
        try {
            $c->connections(user: 'nobody');
            self::assertTrue(true);

            return; // Centrifugo PRO
        } catch (HttpException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertStringContainsString('Centrifugo PRO method', $e->getMessage());
        }
        $b = $c->newBatch();
        $conns = $b->connections(user: 'nobody');
        try {
            $b->send();
        } catch (BatchFailedCommandsException) {
        }
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Centrifugo PRO method');
        $conns->result();
    }

    public function testInfo(): void
    {
        $info = $this->client()->info();
        self::assertNotEmpty($info->nodes);
        self::assertNotSame('', $info->nodes[0]->version);
    }

    public function testMap(): void
    {
        $c = $this->client();
        $ch = 'map:' . $this->unique();
        $c->mapPublish(channel: $ch, key: 'k1', data: ['v' => 1]);
        $c->mapPublish(channel: $ch, key: 'k2', data: ['v' => 2]);
        $keys = array_map(static fn($e) => $e->key, $c->mapReadState(channel: $ch, limit: 10)->entries);
        sort($keys);
        self::assertSame(['k1', 'k2'], $keys);
        $c->mapRemove(channel: $ch, key: 'k1');
        self::assertSame(1, $c->mapStats(channel: $ch)->numKeys);
        $c->mapClear(channel: $ch);
    }

    public function testSharedPollPublish(): void
    {
        $this->client()->sharedPollPublish(channel: 'poll:' . $this->unique(), key: 'k', version: 1, data: ['v' => 1]);
        self::assertTrue(true);
    }

    public function testConnectionWithTokens(): void
    {
        // Tokens connect a real client - HMAC, and Ed25519 verified by
        // client.token.ed25519_public_key (the public key of the seed below,
        // set in testdata/centrifugo.json) - and the server-side methods act on it.
        $c = $this->client();
        $exp = new \DateTimeImmutable('+1 hour');
        foreach (['hmac' => Key::hmac('client_secret'), 'ed25519' => Key::ed25519(str_repeat("\2", 32))] as $name => $key) {
            $user = $this->unique("{$name}-");
            $ch = "{$user}-news";
            $tok = Token::connection($key, new ConnectionClaims(expiresAt: $exp, user: $user, info: ['name' => 'Alice'], channels: [$ch]));
            $s = $this->stream(['token' => $tok]);
            $msg = $s->next();
            self::assertIsArray($msg);
            self::assertArrayHasKey('connect', $msg, json_encode($msg) ?: '');
            self::assertStringContainsString($ch, json_encode($msg) ?: '');
            $pres = $c->presence(channel: $ch)->presence;
            self::assertSame([[$user, '{"name":"Alice"}']], array_values(array_map(static fn($i) => [$i->user, json_encode($i->connInfo)], $pres)));
            self::assertSame(1, $c->presenceStats(channel: $ch)->numUsers);
            $c->publish(channel: $ch, data: ['text' => 'hello']);
            self::assertStringContainsString('hello', json_encode($s->next()) ?: '');
            $other = "{$ch}-2";
            $c->subscribe(channel: $other, user: $user);
            self::assertStringContainsString($other, json_encode($s->next()) ?: '');
            $c->unsubscribe(channel: $other, user: $user);
            $c->refresh(user: $user, expireAt: $exp->getTimestamp());
            $c->disconnect(user: $user);
            while ($s->next() !== null) {
            }
            $s->close();
        }
        // A token signed with another key is refused.
        $bad = Token::connection(Key::ed25519(random_bytes(32)), new ConnectionClaims(expiresAt: $exp, user: 'u'));
        $msg = $this->stream(['token' => $bad])->next();
        self::assertTrue($msg === null || isset($msg['disconnect']));
    }
}
