<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Batch;
use Centrifugal\Phpcent\BroadcastAttempt;
use Centrifugal\Phpcent\Client;
use Centrifugal\Phpcent\Command;
use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\BadRequestException;
use Centrifugal\Phpcent\Exception\BatchFailedCommandsException;
use Centrifugal\Phpcent\Exception\BatchNotSentException;
use Centrifugal\Phpcent\Exception\BatchSentException;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\ClosedException;
use Centrifugal\Phpcent\Exception\DecodeException;
use Centrifugal\Phpcent\Exception\HttpException;
use Centrifugal\Phpcent\Exception\InternalException;
use Centrifugal\Phpcent\Exception\InvalidConfigException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\NotFoundException;
use Centrifugal\Phpcent\Exception\TimeoutException;
use Centrifugal\Phpcent\Exception\TooManyRequestsException;
use Centrifugal\Phpcent\Exception\UnauthorizedException;
use Centrifugal\Phpcent\Exception\UnknownChannelException;
use Centrifugal\Phpcent\Internal\PresenceStatsRequest;
use Centrifugal\Phpcent\Internal\Request;
use Centrifugal\Phpcent\RequestInfo;
use Centrifugal\Phpcent\Retry;
use Centrifugal\Phpcent\Tests\Support\FakeCentrifugo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    // Configuration.

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badConfigs(): iterable
    {
        yield 'no endpoint' => [[], 'apiEndpoint is required'];
        yield 'both endpoints' => [['apiEndpoint' => 'http://a/api', 'apiEndpointFunc' => static fn(): string => 'x'], 'not both'];
        yield 'relative' => [['apiEndpoint' => 'localhost:8000/api'], 'absolute'];
        yield 'ftp' => [['apiEndpoint' => 'ftp://a/api'], 'absolute'];
        yield 'no host' => [['apiEndpoint' => 'http:///api'], 'http:///api'];
        yield 'query' => [['apiEndpoint' => 'http://a/api?x=1'], 'query'];
        yield 'fragment' => [['apiEndpoint' => 'http://a/api#x'], 'fragment'];
        yield 'credentials' => [['apiEndpoint' => 'http://u:p@a/api'], 'credentials'];
        yield 'method' => [['apiEndpoint' => 'http://a/api/publish'], 'ends with the method "publish"'];
        yield 'key and token' => [['apiEndpoint' => 'http://a/api', 'apiKey' => 'k', 'bearerTokenFunc' => static fn(): string => 't'], 'not both'];
        yield 'key with newline' => [['apiEndpoint' => 'http://a/api', 'apiKey' => "k\n"], 'printable ASCII'];
        yield 'X-API-Key header' => [['apiEndpoint' => 'http://a/api', 'headers' => ['x-api-key' => 'k']], 'apiKey'];
        yield 'Authorization header' => [['apiEndpoint' => 'http://a/api', 'headers' => ['Authorization' => 'x']], 'bearerTokenFunc'];
        yield 'Content-Type header' => [['apiEndpoint' => 'http://a/api', 'headers' => ['Content-Type' => 'x']], 'Content-Type'];
        yield 'bad header name' => [['apiEndpoint' => 'http://a/api', 'headers' => ['a b' => 'x']], 'valid HTTP header name'];
        yield 'bad header value' => [['apiEndpoint' => 'http://a/api', 'headers' => ['X-A' => "a\r\nb"]], 'printable ASCII'];
        yield 'empty host' => [['apiEndpoint' => 'http://a/api', 'headers' => ['Host' => '']], 'Host'];
        yield 'zero timeout' => [['apiEndpoint' => 'http://a/api', 'requestTimeout' => 0.0], 'requestTimeout'];
        yield 'NaN timeout' => [['apiEndpoint' => 'http://a/api', 'requestTimeout' => \NAN], 'requestTimeout'];
        yield 'owned curl option' => [['apiEndpoint' => 'http://a/api', 'curlOptions' => [\CURLOPT_URL => 'x']], 'CURLOPT_URL'];
        yield 'factory without client' => [['apiEndpoint' => 'http://a/api', 'httpFactory' => new Psr17Factory()], 'httpFactory'];
        yield 'client without factory' => [['apiEndpoint' => 'http://a/api', 'httpClient' => new FakeCentrifugo()], 'PSR-17'];
        yield 'curl options with client' => [['apiEndpoint' => 'http://a/api', 'httpClient' => new FakeCentrifugo(), 'httpFactory' => new Psr17Factory(), 'curlOptions' => [\CURLOPT_VERBOSE => true]], 'curlOptions'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badConfigs')]
    public function testConfigRefused(array $options, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);
        new Client(...$options);
    }

    public function testEndpointNormalized(): void
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake, ['apiEndpoint' => 'HTTP://Host:8000/prefix/api/'])->info();
        self::assertSame('/prefix/api/info', $fake->last()['path']);
    }

    public function testNoCredentialsIsValid(): void
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake, ['apiKey' => ''])->info();
        self::assertArrayNotHasKey('x-api-key', $fake->last()['headers']);
        self::assertArrayNotHasKey('authorization', $fake->last()['headers']);
    }

    public function testHeaders(): void
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake, ['headers' => ['X-Tenant' => 'a'], 'apiKey' => 'secret'])->info();
        $h = $fake->last()['headers'];
        self::assertSame('application/json', $h['content-type']);
        self::assertSame('phpcent/7', $h['user-agent']);
        self::assertSame('secret', $h['x-api-key']);
        self::assertSame('a', $h['x-tenant']);

        FakeCentrifugo::client($fake, ['headers' => ['User-Agent' => 'mine/1']])->info();
        self::assertSame('mine/1', $fake->last()['headers']['user-agent']);
    }

    public function testBearerTokenPerRequest(): void
    {
        $fake = new FakeCentrifugo();
        $n = 0;
        $c = FakeCentrifugo::client($fake, ['apiKey' => '', 'bearerTokenFunc' => static function () use (&$n): string {
            return 't' . ++$n;
        }]);
        $c->info();
        $c->info();
        self::assertSame('Bearer t2', $fake->last()['headers']['authorization']);

        $bad = FakeCentrifugo::client($fake, ['apiKey' => '', 'bearerTokenFunc' => static fn(): string => '']);
        $sent = \count($fake->requests);
        try {
            $bad->info();
            self::fail('an empty token was sent');
        } catch (InvalidConfigException $e) {
            self::assertStringContainsString('bearerTokenFunc', $e->getMessage());
        }
        self::assertCount($sent, $fake->requests);
    }

    public function testEndpointFuncPerRequest(): void
    {
        $fake = new FakeCentrifugo();
        $addr = 'http://a/one/api';
        $c = FakeCentrifugo::client($fake, ['apiEndpoint' => null, 'apiEndpointFunc' => static function () use (&$addr): string {
            return $addr;
        }]);
        $c->info();
        self::assertSame('/one/api/info', $fake->last()['path']);
        $addr = 'http://a/api/info';
        $this->expectException(InvalidConfigException::class);
        $c->info();
    }

    // Every method.

    /** @return iterable<string, array{string, class-string<Request<mixed>>, array<string, mixed>}> */
    public static function methods(): iterable
    {
        $trait = new \ReflectionClass(\Centrifugal\Phpcent\ClientMethods::class);
        foreach ($trait->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $name = $m->getName();
            if ($name === 'broadcast' || $name === 'broadcastWithRetry') {
                continue; // a result per channel: tested on their own
            }
            /** @var class-string<Request<mixed>> $class */
            $class = 'Centrifugal\\Phpcent\\Internal\\' . ucfirst($name) . 'Request';
            $args = $name === 'publish' ? ['channel' => 'ch', 'data' => 1] : self::requiredArgs($m);
            yield $name => [$name, $class, $args];
        }
    }

    /**
     * Zero values of a method's required arguments.
     *
     * @return array<string, mixed>
     */
    private static function requiredArgs(\ReflectionMethod $m): array
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional()) {
                continue;
            }
            $type = $p->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            $args[$p->getName()] = match ($type->getName()) {
                'string' => '',
                'int' => 0,
                'bool' => false,
                'array' => [],
                default => new ($type->getName())(),
            };
        }

        return $args;
    }

    public function testRequiredFieldsAreRequiredArguments(): void
    {
        $c = FakeCentrifugo::client(new FakeCentrifugo());
        try {
            // @phpstan-ignore arguments.count
            $c->history();
            self::fail('history without a channel');
        } catch (\ArgumentCountError $e) {
            self::assertStringContainsString('Too few arguments to function Centrifugal\\Phpcent\\Client::history()', $e->getMessage());
        }
        // "Required unless ..." stays optional: publish takes data or b64data.
        $publish = new \ReflectionMethod(Client::class, 'publish');
        self::assertSame(['channel'], array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            array_values(array_filter($publish->getParameters(), static fn(\ReflectionParameter $p): bool => !$p->isOptional())),
        ));
    }

    /**
     * @param class-string<Request<mixed>> $class
     * @param array<string, mixed> $args
     */
    #[DataProvider('methods')]
    public function testEveryMethodReachesItsEndpoint(string $name, string $class, array $args): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        $result = $c->{$name}(...$args);
        self::assertSame('/api/' . $class::apiMethod(), $fake->last()['path']);
        self::assertSame((new $class(...$args))->toJson(), $fake->last()['body']);
        self::assertSame($class::decodeResult(new \stdClass(), 'x')::class, $result::class);
    }

    /**
     * @param class-string<Request<mixed>> $class
     * @param array<string, mixed> $args
     */
    #[DataProvider('methods')]
    public function testEveryMethodInABatch(string $name, string $class, array $args): void
    {
        $fake = new FakeCentrifugo(static fn(string $method, mixed $json): Response => FakeCentrifugo::json(200, ['replies' => [[$class::apiMethod() => new \stdClass()]]]));
        $b = FakeCentrifugo::client($fake)->newBatch();
        $p = $b->{$name}(...$args);
        $b->send();
        self::assertSame('/api/batch', $fake->last()['path']);
        self::assertSame('{"commands":[{"' . $class::apiMethod() . '":' . (new $class(...$args))->toJson() . '}]}', $fake->last()['body']);
        self::assertSame($class::decodeResult(new \stdClass(), 'x')::class, $p->result()::class);
    }

    /**
     * @param class-string<Request<mixed>> $class
     * @param array<string, mixed> $args
     */
    #[DataProvider('methods')]
    public function testEveryMethodAsACommand(string $name, string $class, array $args): void
    {
        $cmd = Command::{$name}(...$args);
        self::assertSame($class::apiMethod(), $cmd->method);
        self::assertSame((new $class(...$args))->toJson(), $cmd->payload);
    }

    public function testClientBatchAndCommandTakeTheSameArguments(): void
    {
        $params = static fn(\ReflectionMethod $m): array => array_map(
            static fn(\ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''),
            $m->getParameters(),
        );
        $client = new \ReflectionClass(Client::class);
        $batch = new \ReflectionClass(Batch::class);
        $command = new \ReflectionClass(Command::class);
        $n = 0;
        foreach ((new \ReflectionClass(\Centrifugal\Phpcent\ClientMethods::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getName() === 'broadcastWithRetry') {
                continue;
            }
            $fields = $params($m);
            self::assertSame('?float $timeout = NULL', array_pop($fields), $m->getName());
            self::assertSame($fields, $params($batch->getMethod($m->getName())), $m->getName());
            self::assertSame($fields, $params($command->getMethod($m->getName())), $m->getName());
            self::assertTrue($client->getMethod($m->getName())->isPublic());
            $n++;
        }
        self::assertGreaterThan(30, $n);
        // broadcastWithRetry takes broadcast's fields, then its own.
        $retry = $params($client->getMethod('broadcastWithRetry'));
        $broadcast = $params($client->getMethod('broadcast'));
        array_pop($broadcast);
        self::assertSame($broadcast, \array_slice($retry, 0, \count($broadcast)));
    }

    public function testRequestNamesItsMethod(): void
    {
        self::assertSame('presence_stats', PresenceStatsRequest::apiMethod());
        self::assertSame('presence_stats', PresenceStatsRequest::API_METHOD);
    }

    public function testRequestWithoutFieldsIsOptional(): void
    {
        $fake = new FakeCentrifugo();
        FakeCentrifugo::client($fake)->info();
        self::assertSame('{}', $fake->last()['body']);
        FakeCentrifugo::client($fake)->info();
        self::assertSame('{}', $fake->last()['body']);
    }

    // Errors.

    /** @return iterable<string, array{int, class-string<ApiException>}> */
    public static function codes(): iterable
    {
        yield 'internal' => [100, InternalException::class];
        yield 'unknown channel' => [102, UnknownChannelException::class];
        yield 'not found' => [104, NotFoundException::class];
        yield 'bad request' => [107, BadRequestException::class];
        yield 'too many requests' => [111, TooManyRequestsException::class];
        yield 'unknown code' => [9999, ApiException::class];
    }

    /** @param class-string<ApiException> $class */
    #[DataProvider('codes')]
    public function testApiErrorByCode(int $code, string $class): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, ['error' => ['code' => $code, 'message' => 'm', 'temporary' => true]]));
        try {
            FakeCentrifugo::client($fake)->info();
            self::fail('no error');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($code, $e->getCode());
            self::assertSame('m', $e->serverMessage);
            self::assertTrue($e->temporary);
            self::assertSame("m (code {$code})", $e->getMessage());
        }
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>}> */
    public static function transportMode(): iterable
    {
        foreach ([400, 404, 409, 416, 429, 500] as $status) {
            yield "status {$status}" => [$status, '{"code":102,"message":"unknown channel"}', UnknownChannelException::class];
        }
        yield 'gateway 429' => [429, '{"code":429}', HttpException::class];
        yield 'gateway 500 code' => [500, '{"code":500,"message":"x"}', HttpException::class];
        yield 'status not mapped' => [502, '{"code":102}', HttpException::class];
        yield 'not json' => [400, 'bad', HttpException::class];
    }

    /** @param class-string<\Throwable> $class */
    #[DataProvider('transportMode')]
    public function testTransportErrorMode(int $status, string $body, string $class): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json($status, $body));
        $this->expectException($class);
        FakeCentrifugo::client($fake)->info();
    }

    public function testHttpErrors(): void
    {
        $status = 401;
        $fake = new FakeCentrifugo(static function () use (&$status): Response {
            return new Response($status, ['X-Request-Id' => 'r1', 'Location' => 'https://x'], 'nope');
        });
        $c = FakeCentrifugo::client($fake);
        try {
            $c->info();
            self::fail('no error');
        } catch (UnauthorizedException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('nope', $e->body);
            self::assertSame('r1', $e->headers['x-request-id']);
            self::assertStringContainsString('check the credentials', $e->getMessage());
            self::assertFalse(Retry::retryable($e));
        }
        $status = 404;
        try {
            $c->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertNotInstanceOf(UnauthorizedException::class, $e);
            self::assertStringContainsString('is apiEndpoint the API base URL', $e->getMessage());
        }
        try {
            $c->connections();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertStringContainsString('connections is a Centrifugo PRO method', $e->getMessage());
            self::assertNotInstanceOf(NotFoundException::class, $e);
        }
        $status = 302;
        try {
            $c->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertStringContainsString('a redirect, which phpcent does not follow', $e->getMessage());
        }
        $status = 503;
        try {
            $c->info();
            self::fail('no error');
        } catch (HttpException $e) {
            self::assertTrue(Retry::retryable($e));
        }
    }

    public function testProMethodInBatchHint(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, ['replies' => [['error' => ['code' => 104, 'message' => 'not found']], ['error' => ['code' => 104, 'message' => 'not found']]]]));
        $b = FakeCentrifugo::client($fake)->newBatch();
        $conns = $b->connections();
        $hist = $b->history(channel: 'x');
        try {
            $b->send();
        } catch (BatchFailedCommandsException) {
        }
        try {
            $conns->result();
            self::fail('no error');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('connections is a Centrifugo PRO method: is the server Centrifugo OSS?', $e->getMessage());
        }
        try {
            $hist->result();
            self::fail('no error');
        } catch (NotFoundException $e) {
            self::assertStringNotContainsString('PRO', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function notReplies(): iterable
    {
        yield 'html' => ['<html>hi</html>'];
        yield 'array' => ['[1]'];
        yield 'result not object' => ['{"result":1}'];
        yield 'field of another type' => ['{"result":{"nodes":"x"}}'];
        yield 'error not object' => ['{"error":1}'];
    }

    #[DataProvider('notReplies')]
    public function testDecodeError(string $body): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, $body));
        $this->expectException(DecodeException::class);
        FakeCentrifugo::client($fake)->info();
    }

    public function testPsr18TimeoutsAndCertificateErrors(): void
    {
        $error = static fn(string $message): \Closure => static fn(): \Throwable => new class ($message) extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('POST', 'http://x');
            }
        };
        // Guzzle's and Symfony's wording of their own timeouts.
        foreach (['cURL error 28: Operation timed out after 500 milliseconds', 'Idle timeout reached for "http://x".'] as $message) {
            try {
                FakeCentrifugo::client(new FakeCentrifugo($error($message)))->info();
                self::fail('no error');
            } catch (TimeoutException $e) {
                self::assertTrue(Retry::retryable($e));
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        try {
            FakeCentrifugo::client(new FakeCentrifugo($error('cURL error 60: SSL certificate problem: unable to get local issuer certificate')))->info();
            self::fail('no error');
        } catch (NetworkException $e) {
            self::assertTrue($e->certificate);
            self::assertFalse(Retry::retryable($e));
        }
    }

    public function testNetworkError(): void
    {
        $fake = new FakeCentrifugo(static fn(): \Throwable => new class ('refused') extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return (new Psr17Factory())->createRequest('POST', 'http://x');
            }
        });
        try {
            FakeCentrifugo::client($fake)->info();
            self::fail('no error');
        } catch (NetworkException $e) {
            self::assertTrue(Retry::retryable($e));
            self::assertInstanceOf(NetworkExceptionInterface::class, $e->getPrevious());
        }
    }

    public function testTimeoutBeforeSending(): void
    {
        $fake = new FakeCentrifugo();
        try {
            FakeCentrifugo::client($fake)->info(timeout: 1e-9);
            self::fail('no error');
        } catch (TimeoutException $e) {
            self::assertTrue(Retry::retryable($e));
        }
        self::assertSame([], $fake->requests);
        // 0 does not mean "no timeout", as in curl: it is refused, as a negative one is.
        foreach ([0.0, -1.0] as $timeout) {
            try {
                FakeCentrifugo::client($fake)->info(timeout: $timeout);
                self::fail("timeout {$timeout} taken");
            } catch (InvalidRequestException $e) {
                self::assertStringContainsString('positive number of seconds', $e->getMessage());
            }
        }
    }

    public function testInvalidRequestsAreNotSent(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        foreach ([
            ['publish', ['channel' => '', 'data' => 1], 'invalid publish request: channel is required'],
            ['publish', ['channel' => 'a'], 'invalid publish request: data or b64data is required'],
            ['broadcast', ['channels' => [], 'data' => 1], 'invalid broadcast request: channels is required'],
            ['broadcast', ['channels' => ['a']], 'data or b64data is required'],
            ['broadcast', ['channels' => ['a', ''], 'data' => 1], 'channels[1] is empty'],
            ['publish', ['channel' => 'a', 'data' => \INF], 'invalid publish request: data cannot be encoded'],
            ['publish', ['channel' => "\xff", 'data' => 1], 'channel is not valid UTF-8'],
        ] as [$method, $args, $message]) {
            try {
                $c->{$method}(...$args);
                self::fail("sent: {$message}");
            } catch (InvalidRequestException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertFalse(Retry::retryable($e));
            }
        }
        self::assertSame([], $fake->requests);
        // Only those rules: a publication with b64data alone is Centrifugo's to judge.
        $c->publish(channel: 'a', b64data: 'AA==');
        self::assertCount(1, $fake->requests);
    }

    // Broadcast.

    /** @param list<mixed> $responses */
    private static function broadcastReply(array $responses): FakeCentrifugo
    {
        return new FakeCentrifugo(static fn(): array => ['responses' => $responses]);
    }

    public function testBroadcastSucceeds(): void
    {
        $fake = self::broadcastReply([['result' => ['offset' => 1]], ['result' => ['offset' => 2]]]);
        $res = FakeCentrifugo::client($fake)->broadcast(channels: ['a', 'b'], data: 1, idempotencyKey: 'k');
        self::assertTrue($res->replied);
        self::assertSame([], $res->failed);
        self::assertSame(['a', 'b'], array_map(static fn($c) => $c->channel, $res->channels));
        self::assertSame(2, $res->channels[1]->result?->offset);
        self::assertSame('k', $res->idempotencyKey);
        self::assertSame(1, $res->attempts);
    }

    public function testBroadcastPartialFailure(): void
    {
        $fake = self::broadcastReply([['result' => ['offset' => 1]], ['error' => ['code' => 102, 'message' => 'unknown channel']], ['error' => ['code' => 100, 'message' => 'internal']]]);
        try {
            FakeCentrifugo::client($fake)->broadcast(channels: ['a', 'b', 'c'], data: 1);
            self::fail('no error');
        } catch (BroadcastFailedChannelsException $e) {
            self::assertNotInstanceOf(ApiException::class, $e);
            self::assertTrue($e->result->replied);
            self::assertSame(['b', 'c'], array_map(static fn($c) => $c->channel, $e->result->failed));
            self::assertSame(['b', 'c'], array_map(static fn($c) => $c->channel, $e->failed));
            self::assertSame(3, $e->total);
            self::assertInstanceOf(UnknownChannelException::class, $e->failed[0]->error);
            self::assertSame(['c'], $e->retryableChannels());
            self::assertTrue(Retry::retryable($e));
            self::assertSame('broadcast: 2 of 3 channels failed, 1 got the publication: b: unknown channel (code 102); c: internal (code 100) (retry only retryableChannels(), with the same idempotencyKey)', $e->getMessage());
        }
    }

    public function testBroadcastIntoOneUnknownChannelIsNotUnknownChannel(): void
    {
        $fake = self::broadcastReply([['error' => ['code' => 102, 'message' => 'unknown channel']]]);
        try {
            FakeCentrifugo::client($fake)->broadcast(channels: ['a'], data: 1);
            self::fail('no error');
        } catch (BroadcastFailedChannelsException $e) {
            self::assertSame('broadcast: 1 of 1 channels failed: a: unknown channel (code 102)', $e->getMessage());
            self::assertFalse(Retry::retryable($e));
        }
    }

    public function testBroadcastWrongReplyCount(): void
    {
        $fake = self::broadcastReply([['result' => ['offset' => 1]]]);
        $this->expectException(DecodeException::class);
        FakeCentrifugo::client($fake)->broadcast(channels: ['a', 'b'], data: 1);
    }

    public function testBroadcastWithRetry(): void
    {
        $calls = 0;
        $fake = new FakeCentrifugo(static function (string $method, mixed $json) use (&$calls): array {
            $calls++;
            \assert($json instanceof \stdClass && \is_array($json->channels));

            return ['responses' => array_map(static fn(string $ch): array => match ($ch) {
                'a' => ['result' => ['offset' => 1]],
                'b' => ['error' => ['code' => 102, 'message' => 'unknown channel']],
                default => $calls === 1 ? ['error' => ['code' => 111, 'message' => 'limit', 'temporary' => true]] : ['result' => ['offset' => 5]],
            }, $json->channels)];
        });
        $attempts = [];
        try {
            FakeCentrifugo::client($fake)->broadcastWithRetry(
                channels: ['a', 'b', 'c'],
                data: 1,
                onAttemptError: static function (BroadcastAttempt $a) use (&$attempts): float {
                    $attempts[] = $a;

                    return 0.0;
                },
            );
            self::fail('no error');
        } catch (BroadcastFailedChannelsException $e) {
            // b failed for good; c succeeded on the retry, which only sent c.
            self::assertSame(['b'], array_map(static fn($c) => $c->channel, $e->failed));
            self::assertSame(5, $e->result->channels[2]->result?->offset);
            self::assertSame([1, 1, 2], array_map(static fn($c) => $c->attempts, $e->result->channels));
            self::assertSame(2, $e->result->attempts);
            self::assertStringStartsWith('phpcent-', $e->result->idempotencyKey);
        }
        self::assertCount(1, $attempts);
        self::assertSame(['c'], array_map(static fn($c) => $c->channel, $attempts[0]->retry));
        $keys = array_map(static fn(array $r): mixed => $r['json']->idempotency_key ?? null, $fake->requests);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame(['c'], $fake->requests[1]['json']->channels);
    }

    public function testBroadcastWithRetryStops(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(503, 'down'));
        $c = FakeCentrifugo::client($fake);
        // The callback stops the retries by throwing.
        try {
            $c->broadcastWithRetry(channels: ['a'], data: 1, maxAttempts: 5, onAttemptError: static fn(): never => throw new \DomainException('stop'));
            self::fail('no error');
        } catch (\DomainException $e) {
            self::assertSame('stop', $e->getMessage());
        }
        self::assertCount(1, $fake->requests);
        // A failure no retry fixes stops it at once.
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(401, ''));
        try {
            FakeCentrifugo::client($fake)->broadcastWithRetry(channels: ['a'], data: 1, maxAttempts: 5);
            self::fail('no error');
        } catch (UnauthorizedException) {
        }
        self::assertCount(1, $fake->requests);
        // maxAttempts is a bound.
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(503, ''));
        try {
            FakeCentrifugo::client($fake)->broadcastWithRetry(channels: ['a'], data: 1, maxAttempts: 3, onAttemptError: static fn(): float => 0.0);
            self::fail('no error');
        } catch (HttpException $e) {
            // The key the attempts carried, for a retry which does not publish twice.
            $sent = array_unique(array_map(static fn(array $r): string => json_decode($r['body'])->idempotency_key, $fake->requests));
            self::assertSame([$e->idempotencyKey], array_values($sent));
        }
        self::assertCount(3, $fake->requests);
    }

    public function testBroadcastWithRetryOptions(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        foreach ([['maxAttempts' => 0], ['maxAttempts' => -1], ['attemptTimeout' => -1.0], ['attemptTimeout' => 0.0]] as $opts) {
            try {
                $c->broadcastWithRetry(...['channels' => ['a'], 'data' => 1, ...$opts]);
                self::fail('accepted ' . json_encode($opts));
            } catch (InvalidRequestException) {
            }
        }
        self::assertSame([], $fake->requests);
    }

    public function testBroadcastWithRetryTimeoutBoundsWaits(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(503, ''));
        $start = microtime(true);
        try {
            FakeCentrifugo::client($fake)->broadcastWithRetry(channels: ['a'], data: 1, maxAttempts: 10, onAttemptError: static fn(): float => 60.0, timeout: 0.3);
            self::fail('no error');
        } catch (HttpException|TimeoutException) {
        }
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    // Batches.

    public function testBatch(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, ['replies' => [
            ['publish' => ['offset' => 3]],
            ['error' => ['code' => 102, 'message' => 'unknown channel']],
            ['broadcast' => ['responses' => [['result' => ['offset' => 1]], ['error' => ['code' => 102, 'message' => 'u']]]]],
        ]]));
        $b = FakeCentrifugo::client($fake)->newBatch(parallel: true);
        $pub = $b->publish(channel: 'a', data: 1);
        $hist = $b->history(channel: 'x');
        $bc = $b->broadcast(channels: ['a', 'b'], data: 1);
        self::assertCount(3, $b);
        try {
            $pub->result();
            self::fail('read before sending');
        } catch (BatchNotSentException) {
        }
        try {
            $b->send();
            self::fail('no error');
        } catch (BatchFailedCommandsException $e) {
            self::assertTrue($e->result->replied);
            self::assertSame([1, 2], array_map(static fn($f) => $f->index, $e->failed));
            self::assertSame(['history', 'broadcast'], array_map(static fn($f) => $f->method, $e->failed));
            self::assertStringStartsWith('batch: 2 of 3 commands failed, 1 succeeded: #1 history: unknown channel (code 102); #2 broadcast: broadcast: 1 of 2', $e->getMessage());
            // The very exception the pending throws.
            try {
                $bc->result();
                self::fail('no error');
            } catch (BroadcastFailedChannelsException $be) {
                self::assertSame($e->failed[1]->error, $be);
            }
        }
        self::assertStringEndsWith('],"parallel":true}', $fake->last()['body']);
        self::assertSame(3, $pub->result()->offset);
        $this->expectException(UnknownChannelException::class);
        $hist->result();
    }

    public function testBatchSentOnce(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, ['replies' => [['info' => new \stdClass()]]]));
        $b = FakeCentrifugo::client($fake)->newBatch();
        $b->info();
        $b->send();
        try {
            $b->send();
            self::fail('sent twice');
        } catch (BatchSentException) {
        }
        $this->expectException(\LogicException::class);
        $b->info();
    }

    public function testBatchFailingAsAWhole(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(500, 'down'));
        $b = FakeCentrifugo::client($fake)->newBatch();
        $p = $b->info();
        try {
            $b->send();
            self::fail('no error');
        } catch (HttpException $e) {
            try {
                $p->result();
                self::fail('no error');
            } catch (HttpException $pe) {
                self::assertSame($e, $pe);
            }
        }
    }

    public function testBatchWrongReplyCount(): void
    {
        $fake = new FakeCentrifugo(static fn(): Response => FakeCentrifugo::json(200, ['replies' => []]));
        $b = FakeCentrifugo::client($fake)->newBatch();
        $b->info();
        $this->expectException(DecodeException::class);
        $b->send();
    }

    public function testInvalidCommandFailsTheBatchBeforeSending(): void
    {
        $fake = new FakeCentrifugo();
        $b = FakeCentrifugo::client($fake)->newBatch();
        $b->info();
        $b->publish(channel: 'a');
        try {
            $b->send();
            self::fail('no error');
        } catch (InvalidRequestException $e) {
            self::assertStringStartsWith('batch command #1: invalid publish request', $e->getMessage());
        }
        self::assertSame([], $fake->requests);
    }

    public function testEmptyBatch(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        self::assertTrue($c->newBatch()->send()->replied);
        self::assertSame([], $fake->requests);
        $c->close();
        $this->expectException(ClosedException::class);
        $c->newBatch()->send();
    }

    // The rest.

    public function testClose(): void
    {
        $fake = new FakeCentrifugo();
        $c = FakeCentrifugo::client($fake);
        $c->close();
        try {
            $c->info();
            self::fail('no error');
        } catch (ClosedException $e) {
            self::assertFalse(Retry::retryable($e));
        }
        self::assertSame([], $fake->requests);
    }

    public function testOnRequest(): void
    {
        /** @var list<RequestInfo> $infos */
        $infos = [];
        $reply = FakeCentrifugo::json(200, ['result' => new \stdClass()]);
        $fake = new FakeCentrifugo(static function () use (&$reply): Response {
            return $reply;
        });
        $c = FakeCentrifugo::client($fake, ['onRequest' => static function (RequestInfo $i) use (&$infos): void {
            $infos[] = $i;
        }]);
        $c->info();
        $reply = FakeCentrifugo::json(200, ['replies' => [['error' => ['code' => 102]], ['info' => new \stdClass()]]]);
        $b = $c->newBatch();
        $b->info();
        $b->info();
        try {
            $b->send();
        } catch (BatchFailedCommandsException) {
        }
        $reply = FakeCentrifugo::json(400, '{"code":102,"message":"unknown channel"}');
        try {
            $c->info();
        } catch (UnknownChannelException) {
        }
        try {
            $c->publish(channel: 'a', data: \NAN);
        } catch (InvalidRequestException) {
        }
        self::assertSame(['info', 'batch', 'info', 'publish'], array_map(static fn($i) => $i->method, $infos));
        self::assertSame([1, 2, 1, 1], array_map(static fn($i) => $i->commands, $infos));
        self::assertSame([0, 1, 0, 0], array_map(static fn($i) => $i->failed, $infos));
        self::assertSame([200, 200, 400, 0], array_map(static fn($i) => $i->statusCode, $infos));
        self::assertNull($infos[1]->error);
        self::assertInstanceOf(UnknownChannelException::class, $infos[2]->error);
        self::assertInstanceOf(InvalidRequestException::class, $infos[3]->error);
        self::assertGreaterThanOrEqual(0.0, $infos[0]->duration);
    }

    public function testRetryable(): void
    {
        self::assertFalse(Retry::retryable(null));
        self::assertTrue(Retry::retryable(ApiException::fromCode(100)));
        self::assertTrue(Retry::retryable(ApiException::fromCode(108, '', true)));
        self::assertFalse(Retry::retryable(ApiException::fromCode(102)));
        self::assertTrue(Retry::retryable(new HttpException(429)));
        self::assertTrue(Retry::retryable(new HttpException(500)));
        self::assertFalse(Retry::retryable(new HttpException(404)));
        self::assertFalse(Retry::retryable(new NetworkException('cert', true)));
        self::assertFalse(Retry::retryable(new DecodeException('x')));
        self::assertFalse(Retry::retryable(new \RuntimeException('x')));
    }

    public function testExponentialBackoff(): void
    {
        $d = Retry::exponentialBackoff(0.1, 1.0);
        foreach ([[1, 0.1], [2, 0.2], [3, 0.4], [4, 0.8], [5, 1.0], [100, 1.0], [0, 0.1], [-5, 0.1], [\PHP_INT_MAX, 1.0]] as [$attempt, $max]) {
            for ($i = 0; $i < 20; $i++) {
                $v = $d($attempt);
                self::assertGreaterThanOrEqual($max / 2 - 1e-9, $v, "attempt {$attempt}");
                self::assertLessThanOrEqual($max + 1e-9, $v, "attempt {$attempt}");
            }
        }
        self::assertSame(0.0, Retry::exponentialBackoff(-1.0, -5.0)(3));
        // A zero base with a huge attempt: no loop of that many steps.
        $start = microtime(true);
        self::assertSame(0.0, Retry::exponentialBackoff(0.0, 1.0)(\PHP_INT_MAX));
        self::assertLessThan(0.1, microtime(true) - $start);
        self::assertLessThanOrEqual(2.0, Retry::exponentialBackoff(2.0, 1.0)(3));
    }

    public function testCommand(): void
    {
        $cmd = Command::publish(channel: 'news', data: ['text' => 'hi']);
        self::assertSame('publish', $cmd->method);
        self::assertSame('{"channel":"news","data":{"text":"hi"}}', $cmd->payload);
        self::assertSame('{"method":"publish","payload":{"channel":"news","data":{"text":"hi"}}}', $cmd->toJson());
        $this->expectException(InvalidRequestException::class);
        Command::publish(channel: 'news');
    }
}
