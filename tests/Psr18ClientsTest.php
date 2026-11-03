<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Exception\HttpException;
use Centrifugal\Phpcent\Exception\TimeoutException;
use Centrifugal\Phpcent\Tests\Support\TestServer;
use GuzzleHttp\Client as Guzzle;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Guzzle and Symfony's client, as PSR-18 clients, against a real HTTP server:
 * the call's deadline bounds the request, whatever timeouts the client has of
 * its own, and they behave as sendRequest() does otherwise: an error status is
 * a reply, and redirects follow the client's own policy.
 */
final class Psr18ClientsTest extends TestCase
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

    /** @return iterable<string, array{\Closure(): ClientInterface}> */
    public static function clients(): iterable
    {
        // Their own timeouts far longer than the calls'.
        yield 'guzzle' => [static fn(): ClientInterface => new Guzzle(['timeout' => 30])];
        yield 'symfony' => [static fn(): ClientInterface => new Psr18Client()];
    }

    /** @param \Closure(): ClientInterface $http */
    private static function client(\Closure $http, string $behaviour): Client
    {
        return new Client(self::$server->base . "/{$behaviour}/api", apiKey: 'secret', httpClient: $http(), httpFactory: new Psr17Factory());
    }

    /** @param \Closure(): ClientInterface $http */
    #[DataProvider('clients')]
    public function testRequest(\Closure $http): void
    {
        self::assertSame(1, self::client($http, 'ok')->publish(channel: 'c', data: ['a' => 1])->offset);
    }

    /** @param \Closure(): ClientInterface $http */
    #[DataProvider('clients')]
    public function testSilentServerTimesOut(\Closure $http): void
    {
        $start = microtime(true);
        try {
            self::client($http, 'slowheaders')->info(timeout: 0.5);
            self::fail('no timeout');
        } catch (TimeoutException) {
        }
        self::assertLessThan(1.2, microtime(true) - $start);
    }

    /** @param \Closure(): ClientInterface $http */
    #[DataProvider('clients')]
    public function testTricklingBodyTimesOut(\Closure $http): void
    {
        $start = microtime(true);
        try {
            self::client($http, 'trickle')->info(timeout: 0.8);
            self::fail('no timeout');
        } catch (TimeoutException) {
        }
        self::assertLessThan(1.8, microtime(true) - $start);
    }

    /** @param \Closure(): ClientInterface $http */
    #[DataProvider('clients')]
    public function testErrorStatusIsAnHttpException(\Closure $http): void
    {
        try {
            self::client($http, 'bigerror')->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertSame(502, $e->statusCode);
        }
    }
}
