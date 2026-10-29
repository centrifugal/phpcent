<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Endpoints\ConnectionToken;
use Centrifugal\Phpcent\Endpoints\Jwks;
use Centrifugal\Phpcent\Endpoints\SharedPollSignature;
use Centrifugal\Phpcent\Endpoints\SubscriptionToken;
use Centrifugal\Phpcent\Endpoints\Unauthorized;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;
use Centrifugal\Phpcent\Proxy\ConnectHandler;
use Centrifugal\Phpcent\Proxy\ConnectRequest;
use Centrifugal\Phpcent\Proxy\ConnectResult;
use Centrifugal\Phpcent\Proxy\Disconnect;
use Centrifugal\Phpcent\Proxy\NotifyChannelStateHandler;
use Centrifugal\Phpcent\Proxy\NotifyChannelStateRequest;
use Centrifugal\Phpcent\Proxy\NotifyChannelStateResult;
use Centrifugal\Phpcent\Proxy\ProxyError;
use Centrifugal\Phpcent\Proxy\PublishHandler;
use Centrifugal\Phpcent\Proxy\PublishRequest;
use Centrifugal\Phpcent\Proxy\PublishResult;
use Centrifugal\Phpcent\Proxy\RefreshHandler;
use Centrifugal\Phpcent\Proxy\RefreshRequest;
use Centrifugal\Phpcent\Proxy\RefreshResult;
use Centrifugal\Phpcent\RawJson;
use Centrifugal\Phpcent\Token\Key;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/** The endpoints for client SDKs and the proxy handlers: spec sections 11.3, 12 and 14. */
final class HandlersTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $errors = [];

    private function onError(): \Closure
    {
        return function (HttpRequest $r, \Throwable $e): void {
            $this->errors[] = $e;
        };
    }

    // Endpoints.

    public function testConnectionToken(): void
    {
        $h = new ConnectionToken(static fn(HttpRequest $r): string => 'tok-' . $r->header('X-User'), $this->onError());
        foreach (['GET', 'post'] as $method) {
            $res = $h->handle($method, ['x-user' => ['42']], 'ignored');
            self::assertSame(200, $res->status);
            self::assertSame('{"token":"tok-42"}', $res->body);
            self::assertSame('application/json', $res->headers['Content-Type']);
            self::assertSame('no-store', $res->headers['Cache-Control']);
        }
        $res = $h->handle('PUT');
        self::assertSame(405, $res->status);
        self::assertSame('GET, POST', $res->headers['Allow']);
        self::assertSame([], $this->errors);
    }

    public function testUnauthorizedIs403(): void
    {
        foreach ([new Unauthorized(), new \RuntimeException('wrapped', 0, new Unauthorized())] as $e) {
            $h = new ConnectionToken(static fn(): never => throw $e, $this->onError());
            self::assertSame(403, $h->handle('GET')->status);
        }
        self::assertSame([], $this->errors);
    }

    public function testOtherErrorsAre500(): void
    {
        $h = new ConnectionToken(static fn(): never => throw new \LogicException('db down'), $this->onError());
        self::assertSame(500, $h->handle('GET')->status);
        $h = new ConnectionToken(static fn(): string => '', $this->onError());
        self::assertSame(500, $h->handle('GET')->status);
        self::assertCount(2, $this->errors);
        self::assertSame('db down', $this->errors[0]->getMessage());
    }

    public function testSubscriptionToken(): void
    {
        $h = new SubscriptionToken(static fn(HttpRequest $r, string $ch): string => "tok-{$ch}", $this->onError());
        self::assertSame('{"token":"tok-news"}', $h->handle('POST', [], '{"channel":"news"}')->body);
        self::assertSame('{"token":"tok-news"}', $h->handle('GET', query: ['channel' => 'news'])->body);
        foreach (['', '{', '[]', '{"channel":1}', '{"channel":""}'] as $body) {
            self::assertSame(400, $h->handle('POST', [], $body)->status, $body);
        }
        self::assertSame(400, $h->handle('GET')->status);
        self::assertCount(6, $this->errors);
        self::assertSame(400, $h->handle('POST', [], str_repeat(' ', (1 << 20) + 1))->status);
    }

    public function testSharedPollSignature(): void
    {
        $h = new SharedPollSignature(static fn(HttpRequest $r, string $ch, array $keys): array => [array_values(array_filter($keys, static fn(string $k): bool => $k !== 'secret')), "sig-{$ch}"], $this->onError());
        self::assertSame('{"signature":"sig-c","keys":["a"]}', $h->handle('POST', [], '{"channel":"c","keys":["a","secret"]}')->body);
        // Keys always an array, empty included.
        self::assertSame('{"signature":"sig-c","keys":[]}', $h->handle('POST', [], '{"channel":"c"}')->body);
        self::assertSame(405, $h->handle('GET')->status);
        self::assertSame(400, $h->handle('POST', [], '{"channel":"c","keys":[1]}')->status);

        $extra = new SharedPollSignature(static fn(): array => [['other'], 'sig'], $this->onError());
        self::assertSame(500, $extra->handle('POST', [], '{"channel":"c","keys":["a"]}')->status);
        self::assertStringContainsString('did not ask for', end($this->errors)->getMessage());
    }

    public function testJwks(): void
    {
        $h = new Jwks(Key::ed25519(str_repeat("\1", 32))->withKeyId('k'));
        $res = $h->handle('GET');
        self::assertSame(200, $res->status);
        self::assertSame('public, max-age=300', $res->headers['Cache-Control']);
        self::assertStringStartsWith('{"keys":[{"kty":"OKP","kid":"k"', $res->body);
        self::assertSame('', $h->handle('HEAD')->body);
        self::assertSame(405, $h->handle('POST')->status);
        // A set Token::jwks refuses fails when the endpoint is built.
        $this->expectException(\InvalidArgumentException::class);
        new Jwks(Key::hmac('s')->withKeyId('h'));
    }

    // Proxy handlers.

    public function testConnectProxy(): void
    {
        $seen = null;
        $h = new ConnectHandler(function (ConnectRequest $req, HttpRequest $http) use (&$seen): ConnectResult {
            $seen = $req;
            \assert($req->data instanceof \stdClass);

            return match ($req->data->answer) {
                'ok' => new ConnectResult(user: (string) $http->header('x-user'), data: $req->data, info: new RawJson('{"n":1.50}')),
                'error' => throw new ProxyError(1000, 'nope', temporary: true),
                'disconnect' => throw new Disconnect(4501, 'unauthorized'),
                'wrapped' => throw new \RuntimeException('x', 0, new Disconnect(4500, 'bye')),
                default => throw new \LogicException('bug'),
            };
        }, $this->onError());
        $body = '{"client":"c1","transport":"websocket","data":{"answer":"ok","empty":{}}}';
        $res = $h->handle('POST', ['X-User' => '42'], $body);
        self::assertSame(200, $res->status);
        self::assertSame('{"result":{"user":"42","info":{"n":1.50},"data":{"answer":"ok","empty":{}}}}', $res->body);
        self::assertInstanceOf(ConnectRequest::class, $seen);
        self::assertSame('c1', $seen->client);

        $answer = static fn(string $a): HttpResponse => $h->handle('POST', [], '{"data":{"answer":"' . $a . '"}}');
        self::assertSame('{"error":{"code":1000,"message":"nope","temporary":true}}', $answer('error')->body);
        self::assertSame('{"disconnect":{"code":4501,"reason":"unauthorized"}}', $answer('disconnect')->body);
        self::assertSame('{"disconnect":{"code":4500,"reason":"bye"}}', $answer('wrapped')->body);
        self::assertSame(500, $answer('bug')->status);
        self::assertSame(['bug'], array_map(static fn(\Throwable $e): string => $e->getMessage(), $this->errors));

        self::assertSame(405, $h->handle('GET')->status);
        self::assertSame(400, $h->handle('POST', [], '{"client":1}')->status);
        self::assertSame(400, $h->handle('POST', [], 'not json')->status);
    }

    public function testAnswersAProxyCannotCarry(): void
    {
        $refresh = new RefreshHandler(static fn(): never => throw new ProxyError(1000, 'x'), $this->onError());
        self::assertSame(500, $refresh->handle('POST', [], '{}')->status);
        $state = new NotifyChannelStateHandler(static fn(): never => throw new Disconnect(4000, 'x'), $this->onError());
        self::assertSame(500, $state->handle('POST', [], '{}')->status);
        $state = new NotifyChannelStateHandler(static fn(): never => throw new ProxyError(1000, 'x'), $this->onError());
        self::assertSame('{"error":{"code":1000,"message":"x"}}', $state->handle('POST', [], '{}')->body);
        // A result of another type.
        $wrong = new RefreshHandler(static fn(RefreshRequest $r): NotifyChannelStateResult => new NotifyChannelStateResult(), $this->onError());
        self::assertSame(500, $wrong->handle('POST', [], '{}')->status);
        // A result which cannot be encoded.
        $nan = new PublishHandler(static fn(PublishRequest $r): PublishResult => new PublishResult(data: \NAN), $this->onError());
        self::assertSame(500, $nan->handle('POST', [], '{}')->status);
        self::assertCount(4, $this->errors);
        // The refresh proxy ends a connection through its result.
        $expire = new RefreshHandler(static fn(RefreshRequest $r): RefreshResult => new RefreshResult(expired: true));
        self::assertSame('{"result":{"expired":true}}', $expire->handle('POST', [], '{}')->body);
        $empty = new NotifyChannelStateHandler(static fn(NotifyChannelStateRequest $r): NotifyChannelStateResult => new NotifyChannelStateResult());
        self::assertSame('{"result":{}}', $empty->handle('POST', [], '{}')->body);
    }

    public function testBinaryMode(): void
    {
        $h = new PublishHandler(static fn(PublishRequest $r): PublishResult => new PublishResult(b64data: $r->b64data));
        self::assertSame('{"result":{"b64data":"AAE="}}', $h->handle('POST', [], '{"encoding":"binary","b64data":"AAE="}')->body);
    }

    public function testBodyLimit(): void
    {
        $h = new RefreshHandler(static fn(RefreshRequest $r): RefreshResult => new RefreshResult(), $this->onError(), maxBody: 10);
        self::assertSame(400, $h->handle('POST', [], '{"user":"0123456789"}')->status);
        self::assertSame(200, $h->handle('POST', [], '{}')->status);
        $this->expectException(\InvalidArgumentException::class);
        new RefreshHandler(static fn(RefreshRequest $r): RefreshResult => new RefreshResult(), maxBody: 0);
    }

    public function testPsr15(): void
    {
        $f = new Psr17Factory();
        $h = (new ConnectHandler(static fn(ConnectRequest $r, HttpRequest $http): ConnectResult => new ConnectResult(
            user: (string) $http->header('x-user'),
            data: $http->raw instanceof ServerRequest ? 'psr' : 'other',
        )))->psr15($f);
        $req = (new ServerRequest('POST', 'http://app/centrifugo/connect'))->withHeader('X-User', '7')->withBody($f->createStream('{}'));
        $res = $h->handle($req);
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('application/json', $res->getHeaderLine('Content-Type'));
        self::assertSame('{"result":{"user":"7","data":"psr"}}', (string) $res->getBody());
    }
}
