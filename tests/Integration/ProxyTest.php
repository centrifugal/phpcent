<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Integration;

use Centrifugal\Phpcent\PresenceRequest;
use Centrifugal\Phpcent\PublishRequest;
use Centrifugal\Phpcent\Tests\Support\TestServer;

/**
 * The proxy handlers, called by a real Centrifugo: testdata/centrifugo.json
 * points its connect and refresh proxies at port 3100 of the host, which it
 * reaches as host.docker.internal, or PHPCENT_TEST_PROXY_PORT.
 */
final class ProxyTest extends IntegrationTestCase
{
    /**
     * The requests the backend got so far, waiting for at least $n of $kind.
     *
     * @return list<array{kind: string, req: mixed}>
     */
    private static function waitFor(string $log, string $kind, int $n): array
    {
        $deadline = microtime(true) + 30;
        while (true) {
            $lines = array_map(static fn(string $l): mixed => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($log))));
            /** @var list<array{kind: string, req: mixed}> $entries */
            $entries = array_values($lines);
            if (\count(array_filter($entries, static fn(array $e): bool => $e['kind'] === $kind)) >= $n) {
                return $entries;
            }
            if (microtime(true) > $deadline) {
                self::fail("no {$kind} request after 30s: " . json_encode($entries));
            }
            usleep(50_000);
        }
    }

    public function testConnectAndRefresh(): void
    {
        $c = $this->client();
        $user = $this->unique('proxy-');
        $ch = "{$user}-news";
        $log = tempnam(sys_get_temp_dir(), 'phpcent-proxy-');
        \assert(\is_string($log));
        $server = new TestServer(__DIR__ . '/proxy_router.php', '0.0.0.0', (int) (getenv('PHPCENT_TEST_PROXY_PORT') ?: 3100), [
            'PHPCENT_PROXY_LOG' => $log,
            'PHPCENT_PROXY_USER' => $user,
            'PHPCENT_PROXY_CHANNEL' => $ch,
        ]);
        try {
            // Refused: the connection is closed with the disconnect's code.
            $refused = $this->stream(['data' => ['allow' => false]]);
            $msg = $refused->next();
            $req = self::waitFor($log, 'connect', 1)[0]['req'];
            \assert(\is_array($req));
            self::assertSame(['uni_http_stream', 'json', 'json'], [$req['transport'], $req['protocol'], $req['encoding']]);
            self::assertNotSame('', $req['client']);
            self::assertIsArray($msg);
            self::assertIsArray($msg['disconnect'] ?? null);
            self::assertSame(4501, $msg['disconnect']['code']);

            // Accepted: the result's data and server-side channel reach the
            // client, and so does a publication into that channel.
            $s = $this->stream(['data' => ['allow' => true]]);
            $reply = $s->next();
            self::assertIsArray($reply);
            self::assertIsArray($reply['connect'] ?? null);
            self::assertSame(['welcome' => true], $reply['connect']['data']);
            self::assertIsArray($reply['connect']['subs']);
            self::assertArrayHasKey($ch, $reply['connect']['subs']);
            $c->publish(new PublishRequest(channel: $ch, data: ['text' => 'hello']));
            self::assertStringContainsString('hello', json_encode($s->next()) ?: '');
            $pres = $c->presence(new PresenceRequest(channel: $ch))->presence;
            self::assertSame([[$user, '{"name":"Alice"}']], array_values(array_map(static fn($i) => [$i->user, json_encode($i->connInfo)], $pres)));

            // It expires: Centrifugo asks the refresh proxy, which ends it.
            $entries = self::waitFor($log, 'refresh', 1);
            $ref = array_values(array_filter($entries, static fn(array $e): bool => $e['kind'] === 'refresh'))[0]['req'];
            \assert(\is_array($ref));
            self::assertSame($user, $ref['user']);
            self::assertSame($reply['connect']['client'], $ref['client']);
            while ($s->next() !== null) {
            }
            self::assertSame([], array_values(array_filter($entries, static fn(array $e): bool => $e['kind'] === 'error')));
        } finally {
            $server->stop();
            @unlink($log);
        }
    }
}
