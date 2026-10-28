<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Integration;

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Command;
use Centrifugal\Phpcent\DeviceFilter;
use Centrifugal\Phpcent\DeviceMetaUpdate;
use Centrifugal\Phpcent\DeviceTopicFilter;
use Centrifugal\Phpcent\Exception\NotAvailableException;
use Centrifugal\Phpcent\Exception\UnauthorizedException;
use Centrifugal\Phpcent\PushNotification;
use Centrifugal\Phpcent\PushRecipient;
use Centrifugal\Phpcent\Tests\Support\TestServer;
use Centrifugal\Phpcent\Token\ApiClaims;
use Centrifugal\Phpcent\Token\ApiTokens;
use Centrifugal\Phpcent\Token\ConnectionClaims;
use Centrifugal\Phpcent\Token\Key;
use Centrifugal\Phpcent\Token\Token;
use Centrifugal\Phpcent\UserTopicFilter;
use Centrifugal\Phpcent\WebPushPushNotification;

/**
 * Centrifugo PRO methods, API tokens and async consumers, against the
 * Centrifugo PRO of testdata/centrifugo-pro.compose.yml: user status, user
 * blocking and token revocation in Redis, devices in PostgreSQL, push
 * notifications through webpush in dry run.
 *
 * The tests use Centrifugo PRO in sandbox mode, without a license key.
 * Centrifugo PRO is commercial software, under the Centrifugal Labs License
 * Agreement (https://centrifugal.dev/license), not under phpcent's MIT
 * license.
 */
final class ProTest extends IntegrationTestCase
{
    /** A browser PushSubscription, as webpush devices are registered with. */
    private static function webpushToken(): string
    {
        $k = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($k !== false);
        $d = openssl_pkey_get_details($k);
        \assert(\is_array($d) && \is_array($d['ec']) && \is_string($d['ec']['x']) && \is_string($d['ec']['y']));
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", \STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", \STR_PAD_LEFT);
        $e = static fn(string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        return json_encode([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $e(substr($pub, 0, 8)),
            'keys' => ['p256dh' => $e($pub), 'auth' => $e(str_repeat("\0", 16))],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    public function testUserStatus(): void
    {
        $pro = $this->pro();
        $user = $this->unique();
        $pro->updateUserStatus(users: [$user], state: 'away');
        $st = $pro->getUserStatus(users: [$user])->statuses[0];
        self::assertSame([$user, 'away'], [$st->user, $st->state]);
        self::assertGreaterThan(0, $st->active);
        $pro->deleteUserStatus(users: [$user]);
        self::assertSame(0, $pro->getUserStatus(users: [$user])->statuses[0]->active);
    }

    public function testUserAndTokenControl(): void
    {
        $pro = $this->pro();
        $user = $this->unique();
        $expire = time() + 60;
        $pro->blockUser(expireAt: $expire, user: $user);
        $pro->unblockUser(user: $user);
        $pro->revokeToken(expireAt: $expire, uid: "{$user}-jti");
        $pro->invalidateUserTokens(expireAt: $expire, user: $user, issuedBefore: time());
        self::assertTrue(true);
    }

    public function testDevicesAndPush(): void
    {
        $pro = $this->pro();
        $user = $this->unique();
        $reg = $pro->deviceRegister(provider: 'webpush', token: self::webpushToken(), platform: 'web', user: $user, meta: ['app' => 'phpcent'], topics: ['news']);
        self::assertNotSame('', $reg->id);
        $byUser = new DeviceFilter(users: [$user]);
        $list = $pro->deviceList(filter: $byUser, includeTotalCount: true, includeMeta: true, includeTopics: true);
        self::assertSame(1, $list->totalCount);
        self::assertSame($reg->id, $list->items[0]->id);
        self::assertSame(['app' => 'phpcent'], $list->items[0]->meta);
        self::assertContains('news', $list->items[0]->topics);
        $pro->deviceTopicUpdate(deviceId: $reg->id, op: 'add', topics: ['sport']);
        $topics = $pro->deviceTopicList(filter: new DeviceTopicFilter(deviceIds: [$reg->id]), includeTotalCount: true);
        self::assertSame(2, $topics->totalCount);
        $pro->deviceUpdate(ids: [$reg->id], metaUpdate: new DeviceMetaUpdate(meta: ['app' => 'updated']));
        self::assertSame(['app' => 'updated'], $pro->deviceList(filter: $byUser, includeMeta: true)->items[0]->meta);
        $pro->userTopicUpdate(user: $user, op: 'add', topics: ['alerts']);
        $ut = $pro->userTopicList(filter: new UserTopicFilter(users: [$user]));
        self::assertSame(['alerts'], array_map(static fn($i) => $i->topic, $ut->items));
        $push = $pro->sendPushNotification(
            recipient: new PushRecipient(filter: $byUser),
            notification: new PushNotification(webpush: new WebPushPushNotification(payload: ['title' => 'hi'])),
            sendAt: time() + 3600,
        );
        self::assertNotSame('', $push->uid);
        $pro->cancelPush(uid: $push->uid);
        // Push status needs push analytics in ClickHouse; without it, the
        // method is not available.
        try {
            $pro->updatePushStatus(analyticsUid: $push->uid, status: 'delivered', deviceId: $reg->id);
        } catch (NotAvailableException) {
        }
        $pro->deviceRemove(ids: [$reg->id]);
        self::assertSame([], $pro->deviceList(filter: $byUser)->items);
    }

    public function testProBatch(): void
    {
        $pro = $this->pro();
        $user = $this->unique();
        $b = $pro->newBatch();
        $b->updateUserStatus(users: [$user], state: 'busy');
        $status = $b->getUserStatus(users: [$user]);
        $reg = $b->deviceRegister(provider: 'webpush', token: self::webpushToken(), platform: 'web', user: $user);
        $conns = $b->connections(user: $user);
        $b->send();
        self::assertSame('busy', $status->result()->statuses[0]->state);
        self::assertNotSame('', $reg->result()->id);
        self::assertSame([], $conns->result()->connections);
        $pro->deviceRemove(ids: [$reg->result()->id]);
    }

    public function testApiTokens(): void
    {
        // Tokens from ApiTokens authenticate API calls to Centrifugo PRO, which
        // verifies them with the keys served at the endpoint of
        // testdata/centrifugo-pro.compose.yml: port 3101 of the host. Tokens
        // without the audience and scope it requires are refused, as are
        // tokens signed with a key not published.
        $this->pro();
        $server = new TestServer(__DIR__ . '/proxy_router.php', '0.0.0.0', (int) (getenv('PHPCENT_TEST_API_JWKS_PORT') ?: 3101), ['PHPCENT_PROXY_LOG' => '/dev/null']);
        try {
            $key = Key::ed25519(str_repeat("\1", 32))->withKeyId('gocent-api-test');
            $publish = function (callable $token): void {
                (new Client(self::addr(), bearerTokenFunc: $token))->publish(channel: $this->unique(), data: new \stdClass());
            };
            $tokens = new ApiTokens(claims: new ApiClaims(audience: 'centrifugo-api', scope: ['centrifugo:api']), key: $key);
            $publish($tokens->token(...));
            $this->addToAssertionCount(1);

            $exp = new \DateTimeImmutable('+1 minute');
            $refused = [
                Token::api($key, new ApiClaims(audience: 'centrifugo-api', expiresAt: $exp)),
                Token::api($key, new ApiClaims(scope: ['centrifugo:api'], expiresAt: $exp)),
                // A connection token signed with a published key: no audience or scope.
                Token::connection($key, new ConnectionClaims(expiresAt: $exp, user: '42')),
                (new ApiTokens(claims: new ApiClaims(audience: 'centrifugo-api'), key: Key::ed25519(str_repeat("\2", 32))->withKeyId('gocent-unpublished')))->token(),
            ];
            foreach ($refused as $i => $tok) {
                try {
                    $publish(static fn(): string => $tok);
                    self::fail("token {$i} accepted");
                } catch (UnauthorizedException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $server->stop();
        }
    }

    public function testCommandConsumed(): void
    {
        // Commands from Command::from, written to a Redis stream, are run by
        // Centrifugo's async consumer: apart, as method and payload, and whole.
        $pro = $this->pro();
        $ch = $this->unique();
        $apart = Command::publish(channel: $ch, data: ['n' => 1]);
        self::redisXadd('phpcent_commands', 'method', $apart->method, 'payload', $apart->payload);
        $whole = Command::publish(channel: $ch, data: ['n' => 2]);
        self::redisXadd('phpcent_commands', 'payload', $whole->toJson());
        $deadline = microtime(true) + 15;
        while (true) {
            $pubs = $pro->history(channel: $ch, limit: 10)->publications;
            if (\count($pubs) === 2) {
                self::assertSame(['{"n":1}', '{"n":2}'], array_map(static fn($p) => json_encode($p->data), $pubs));

                return;
            }
            self::assertLessThan($deadline, microtime(true), \count($pubs) . ' of 2 commands consumed after 15s');
            usleep(100_000);
        }
    }

    private static function redisXadd(string $stream, string ...$fields): void
    {
        $addr = getenv('PHPCENT_TEST_REDIS') ?: 'localhost:46379';
        $args = ['XADD', $stream, '*', ...$fields];
        $cmd = '*' . \count($args) . "\r\n";
        foreach ($args as $a) {
            $cmd .= '$' . \strlen($a) . "\r\n{$a}\r\n";
        }
        $s = stream_socket_client("tcp://{$addr}", $errno, $errstr, 5);
        if ($s === false) {
            self::fail("redis at {$addr}: {$errstr}");
        }
        fwrite($s, $cmd);
        $reply = (string) fgets($s);
        fclose($s);
        self::assertStringStartsWith('$', $reply);
    }
}
