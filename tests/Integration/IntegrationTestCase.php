<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Integration;

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Exception\CentException;
use PHPUnit\Framework\TestCase;

/**
 * The integration tests run against a real Centrifugo, set up as
 * testdata/centrifugo.json says - history and presence for channels without a
 * namespace, a "map" and a "poll" namespace, HMAC and Ed25519 client tokens,
 * the unidirectional HTTP stream, the connect and refresh proxies:
 *
 *     docker compose -f testdata/centrifugo-pro.compose.yml up -d --wait   # Centrifugo PRO
 *     composer test-integration
 *
 * PHPCENT_TEST_ADDR and PHPCENT_TEST_API_KEY default to
 * http://localhost:8100/api and "api_key". The PRO tests skip against
 * Centrifugo OSS, unless PHPCENT_TEST_PRO is set: then a server which is not
 * PRO fails them.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static function addr(): string
    {
        return getenv('PHPCENT_TEST_ADDR') ?: 'http://localhost:8100/api';
    }

    protected static function apiKey(): string
    {
        return getenv('PHPCENT_TEST_API_KEY') ?: 'api_key';
    }

    protected function client(): Client
    {
        $c = new Client(self::addr(), apiKey: self::apiKey());
        try {
            $c->info();
        } catch (CentException $e) {
            self::fail('Centrifugo not reachable at ' . self::addr() . ": {$e->getMessage()}");
        }

        return $c;
    }

    protected function pro(): Client
    {
        $c = $this->client();
        try {
            $c->connections(user: 'nobody');
        } catch (CentException $e) {
            if (getenv('PHPCENT_TEST_PRO')) {
                self::fail("PHPCENT_TEST_PRO is set, but the server is not Centrifugo PRO: {$e->getMessage()}");
            }
            self::markTestSkipped("not Centrifugo PRO: {$e->getMessage()}");
        }

        return $c;
    }

    protected function unique(string $prefix = ''): string
    {
        return $prefix . 'phpcent-' . $this->name() . '-' . bin2hex(random_bytes(4));
    }

    /**
     * A client connection over Centrifugo's unidirectional HTTP stream, with
     * the given connect request.
     *
     * @param array<string, mixed> $body
     */
    protected function stream(array $body): Stream
    {
        return new Stream(substr(self::addr(), 0, -\strlen('/api')) . '/connection/uni_http_stream', $body);
    }
}
