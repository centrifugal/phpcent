<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Exception\DecodeException;
use Centrifugal\Phpcent\Exception\HttpException;
use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\TimeoutException;
use Centrifugal\Phpcent\InfoResult;
use Centrifugal\Phpcent\Retry;
use Centrifugal\Phpcent\Tests\Support\TestServer;
use PHPUnit\Framework\TestCase;

/**
 * The default curl transport against a real HTTP server: one deadline per
 * request, headers included; redirects not followed; error bodies bounded.
 * And the handlers' serve(), from $_SERVER and php://input.
 */
final class CurlTest extends TestCase
{
    private static TestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private static function client(string $behaviour, float $timeout = 10.0): Client
    {
        return new Client(self::$server->base . "/{$behaviour}/api", apiKey: 'secret', requestTimeout: $timeout);
    }

    public function testRequest(): void
    {
        $res = self::client('ok')->publish(channel: 'c', data: ['a' => 1]);
        self::assertSame(1, $res->offset);
        self::assertSame('e', $res->epoch);
    }

    public function testSlowHeadersTimeOut(): void
    {
        $start = microtime(true);
        try {
            self::client('slowheaders', 0.5)->info();
            self::fail('no timeout');
        } catch (TimeoutException $e) {
            self::assertTrue(Retry::retryable($e));
        }
        self::assertLessThan(1.5, microtime(true) - $start);
    }

    public function testTricklingBodyTimesOut(): void
    {
        // The body arrives a byte every 100 ms for 3 seconds: the deadline
        // bounds the whole request, not each read.
        $start = microtime(true);
        try {
            self::client('trickle')->info(timeout: 0.8);
            self::fail('no timeout');
        } catch (TimeoutException) {
        }
        self::assertLessThan(1.8, microtime(true) - $start);
    }

    /**
     * requestTimeout bounds every call; a call's own timeout replaces it,
     * longer or shorter (spec 0.3, 3.2). The server answers after 0.6 s - or
     * later, when it is still busy with the requests of tests before which
     * timed out: the longer timeouts leave room for that.
     */
    public function testTimeoutReplacesRequestTimeout(): void
    {
        $timesOut = static function (callable $call): bool {
            try {
                $call();

                return false;
            } catch (TimeoutException) {
                return true;
            }
        };
        $short = self::client('sleep', 0.2);
        self::assertTrue($timesOut(static fn(): mixed => $short->publish(channel: 'c', data: 1)), 'requestTimeout');
        self::assertFalse($timesOut(static fn(): mixed => $short->publish(channel: 'c', data: 1, timeout: 15.0)), 'a longer timeout');
        $long = self::client('sleep', 10.0);
        $start = microtime(true);
        self::assertTrue($timesOut(static fn(): mixed => $long->publish(channel: 'c', data: 1, timeout: 0.2)), 'a shorter timeout');
        self::assertLessThan(0.55, microtime(true) - $start);
    }

    /**
     * attemptTimeout is each attempt's own timeout, replacing requestTimeout;
     * the call's timeout still ends an attempt sooner (spec 0.3, 7.3).
     */
    public function testAttemptTimeout(): void
    {
        $short = self::client('sleep', 0.2);
        $res = $short->broadcastWithRetry(channels: ['c'], data: 1, maxAttempts: 1, attemptTimeout: 15.0);
        self::assertSame(1, $res->channels[0]->result?->offset);
        $start = microtime(true);
        try {
            self::client('sleep', 10.0)->broadcastWithRetry(channels: ['c'], data: 1, maxAttempts: 1, attemptTimeout: 3.0, timeout: 0.2);
            self::fail('no timeout');
        } catch (TimeoutException) {
        }
        self::assertLessThan(0.55, microtime(true) - $start);
    }

    public function testRedirectNotFollowed(): void
    {
        try {
            self::client('redirect')->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertSame(302, $e->statusCode);
            self::assertSame('https://elsewhere.test/api/publish', $e->headers['location']);
        }
    }

    public function testErrorBodyBounded(): void
    {
        try {
            self::client('bigerror')->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertSame(502, $e->statusCode);
            self::assertSame(512, \strlen($e->body));
        }
    }

    public function testNotCentrifugo(): void
    {
        $this->expectException(DecodeException::class);
        self::client('html')->info();
    }

    public function testConnectionRefused(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        \assert($sock !== false);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        try {
            (new Client("http://{$name}/api"))->info();
            self::fail('no error');
        } catch (NetworkException $e) {
            self::assertFalse($e->certificate);
            self::assertTrue(Retry::retryable($e));
        }
    }

    public function testCurlOptions(): void
    {
        // An option of the application's own, as phpcent 6's forceIpResolveV4 set.
        $c = new Client(self::$server->base . '/ok/api', curlOptions: [\CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4]);
        self::assertInstanceOf(InfoResult::class, $c->info());
    }

    public function testServe(): void
    {
        $ch = curl_init(self::$server->base . '/serve-connect');
        \assert($ch !== false);
        curl_setopt_array($ch, [\CURLOPT_POST => true, \CURLOPT_POSTFIELDS => '{"data":{"x":1}}', \CURLOPT_HTTPHEADER => ['X-User: 42'], \CURLOPT_RETURNTRANSFER => true]);
        self::assertSame('{"result":{"user":"42","data":{"x":1}}}', curl_exec($ch));
        self::assertSame(200, curl_getinfo($ch, \CURLINFO_RESPONSE_CODE));

        $ch = curl_init(self::$server->base . '/serve-token?who=alice');
        \assert($ch !== false);
        curl_setopt_array($ch, [\CURLOPT_RETURNTRANSFER => true, \CURLOPT_HEADER => true]);
        $out = (string) curl_exec($ch);
        self::assertStringContainsString('Cache-Control: no-store', $out);
        self::assertStringEndsWith('{"token":"token-for-alice"}', $out);
    }
}
