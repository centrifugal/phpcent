<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Support;

use Centrifugal\Phpcent\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A fake Centrifugo behind a PSR-18 client: $reply answers each request - with
 * a ResponseInterface, a result object for {"result": ...}, or a Throwable to
 * throw - and every request is recorded.
 */
final class FakeCentrifugo implements ClientInterface
{
    /** @var list<array{method: string, path: string, headers: array<string, string>, body: string, json: mixed}> */
    public array $requests = [];

    /** @var \Closure(string, mixed, RequestInterface): mixed */
    private \Closure $reply;

    /** @param ?callable(string, mixed, RequestInterface): mixed $reply */
    public function __construct(?callable $reply = null)
    {
        $this->reply = $reply !== null ? $reply(...) : static fn(): mixed => new \stdClass();
    }

    /** @param array<string, mixed> $options */
    public static function client(self $fake, array $options = []): Client
    {
        return new Client(...$options + ['apiEndpoint' => 'http://centrifugo.test/api', 'apiKey' => 'key', 'httpClient' => $fake, 'httpFactory' => new Psr17Factory()]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = substr($path, (int) strrpos($path, '/') + 1);
        $body = (string) $request->getBody();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        $json = json_decode($body, false);
        $this->requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body, 'json' => $json];
        $out = ($this->reply)($method, $json, $request);
        if ($out instanceof \Throwable) {
            throw $out;
        }
        if ($out instanceof ResponseInterface) {
            return $out;
        }

        return self::json(200, ['result' => $out]);
    }

    public static function json(int $status, mixed $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array{method: string, path: string, headers: array<string, string>, body: string, json: mixed} */
    public function last(): array
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('no request');
        }

        return $last;
    }
}
