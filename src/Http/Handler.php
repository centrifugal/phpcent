<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Http;

use Centrifugal\Phpcent\Exception\InvalidConfigException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The base of every handler - a token or signature endpoint, the JWKS
 * endpoint, a proxy handler. Its framework-free core, handle, turns a request
 * into a response; handlePsr and psr15 serve it in PSR-7 and PSR-15 frameworks
 * (Slim, Mezzio, and Symfony or Laravel through their PSR-7 bridges), and
 * serve answers the request of a plain PHP script.
 */
abstract class Handler
{
    /** @var ?\Closure(HttpRequest, \Throwable): void */
    private readonly ?\Closure $onError;

    /** @param ?callable(HttpRequest, \Throwable): void $onError */
    public function __construct(?callable $onError, protected readonly int $maxBody)
    {
        if ($maxBody <= 0) {
            throw new InvalidConfigException('maxBody must be a positive number of bytes');
        }
        $this->onError = $onError !== null ? $onError(...) : null;
    }

    /**
     * The response to the request, or the function's arguments after the
     * request when it is called.
     *
     * @return HttpResponse|list<mixed>
     */
    abstract protected function begin(HttpRequest $req, string $body): HttpResponse|array;

    /**
     * The response to what the function returned or threw.
     *
     * @param list<mixed> $args
     */
    abstract protected function end(HttpRequest $req, array $args, mixed $out, ?\Throwable $error): HttpResponse;

    /**
     * Calls the function with the arguments begin made.
     *
     * @param list<mixed> $args
     */
    abstract protected function call(HttpRequest $req, array $args): mixed;

    /** $status, after telling onError of $error. */
    protected function fail(HttpRequest $req, int $status, \Throwable $error): HttpResponse
    {
        if ($this->onError !== null) {
            ($this->onError)($req, $error);
        }

        return HttpResponse::status($status);
    }

    /**
     * Answers a request: the core every adapter calls. $raw is passed on to the
     * function as HttpRequest::$raw: the server's own request object, or what
     * your framework knows of the request, such as its authenticated user.
     *
     *     // Laravel
     *     Route::post('/centrifugo/connect', function (Request $request) use ($handler) {
     *         $res = $handler->handle($request->method(), $request->headers->all(), $request->getContent(), raw: $request->user());
     *         return response($res->body, $res->status, $res->headers);
     *     });
     *
     * @param array<array-key, string|null|array<string|null>> $headers as
     *     frameworks give them - Laravel's and Symfony's $request->headers->all()
     *     included
     * @param array<string, mixed> $query
     */
    public function handle(string $method, array $headers = [], string $body = '', array $query = [], string $path = '', mixed $raw = null): HttpResponse
    {
        $req = new HttpRequest(strtoupper($method), self::headers($headers), self::query($query), $path, $raw);
        if (\strlen($body) > $this->maxBody) {
            return $this->fail($req, 400, new \LengthException("request body over {$this->maxBody} bytes"));
        }
        $begun = $this->begin($req, $body);
        if ($begun instanceof HttpResponse) {
            return $begun;
        }
        try {
            $out = $this->call($req, $begun);
        } catch (\Throwable $e) {
            return $this->end($req, $begun, null, $e);
        }

        return $this->end($req, $begun, $out, null);
    }

    /** Answers a PSR-7 request, with responses from $factory. */
    public function handlePsr(ServerRequestInterface $request, ResponseFactoryInterface&StreamFactoryInterface $factory): ResponseInterface
    {
        $stream = $request->getBody();
        $body = '';
        while (!$stream->eof() && \strlen($body) <= $this->maxBody) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $res = $this->handle($request->getMethod(), $request->getHeaders(), $body, $query, $request->getUri()->getPath(), $request);
        $out = $factory->createResponse($res->status)->withBody($factory->createStream($res->body));
        foreach ($res->headers as $name => $value) {
            $out = $out->withHeader($name, $value);
        }

        return $out;
    }

    /**
     * The handler as a PSR-15 request handler, with responses from $factory:
     * new Nyholm\Psr7\Factory\Psr17Factory(), say.
     */
    public function psr15(ResponseFactoryInterface&StreamFactoryInterface $factory): RequestHandlerInterface
    {
        return new Psr15Handler($this, $factory);
    }

    /**
     * Answers the request of a plain PHP script - php-fpm, the built-in server -
     * from $_SERVER and php://input, sending the response with header() and
     * echo:
     *
     *     // centrifugo_connect.php
     *     require 'vendor/autoload.php';
     *     $handler->serve();
     */
    public function serve(): void
    {
        /** @var array<string, mixed> $server */
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $k => $v) {
            if (\is_string($v) && str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $k => $name) {
            if (isset($server[$k]) && \is_string($server[$k]) && $server[$k] !== '') {
                $headers[$name] = $server[$k];
            }
        }
        $body = (string) file_get_contents('php://input', false, null, 0, max(0, $this->maxBody + 1));
        /** @var array<string, mixed> $query */
        $query = $_GET;
        $method = \is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET';
        $uri = \is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';
        $res = $this->handle($method, $headers, $body, $query, (string) parse_url($uri, \PHP_URL_PATH), $server);
        http_response_code($res->status);
        foreach ($res->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $res->body;
    }

    /**
     * @param array<array-key, string|null|array<string|null>> $headers
     *
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            $value = \is_array($value) ? implode(', ', array_filter($value, \is_string(...))) : (string) $value;
            $out[$name] = isset($out[$name]) ? "{$out[$name]}, {$value}" : $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, string>
     */
    private static function query(array $query): array
    {
        $out = [];
        foreach ($query as $k => $v) {
            if (\is_string($v)) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }
}
