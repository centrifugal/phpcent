<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

use Centrifugal\Phpcent\Exception\CentException;
use Centrifugal\Phpcent\Http\Handler;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;
use Centrifugal\Phpcent\Internal\Decode;
use Centrifugal\Phpcent\Internal\Encode;
use Centrifugal\Phpcent\Message;

/**
 * The application side of a Centrifugo HTTP proxy: it decodes the request
 * Centrifugo sends, calls the function, and encodes its answer.
 *
 * Answering: the function returns a result to accept the request, or throws:
 *
 * - ProxyError rejects the request with that error, which the client
 *   receives: use codes in [400, 1999]. The connect, subscribe, publish, RPC,
 *   map publish and map remove proxies pass it to the client; the shared poll
 *   refresh and channel state proxies take it as a failure.
 * - Disconnect closes the client's connection with that code: use codes in
 *   [4000, 4999] - [4500, 4999] to tell the client not to reconnect. Only the
 *   proxies which pass a ProxyError to the client honour it.
 * - Any other exception, or one a proxy cannot carry, fails the request with
 *   HTTP status 500, and is passed to onError for logging. Centrifugo then
 *   answers the client with its temporary internal error; for refresh and sub
 *   refresh it keeps the connection or subscription for another minute and
 *   asks again.
 *
 * A ProxyError or Disconnect as the previous exception of another counts too.
 * The refresh and sub refresh proxies end a connection or subscription through
 * their result's expired, not an exception.
 *
 * Payloads - data, info, meta - arrive as json_decode gives them, objects as
 * stdClass, and a result takes them back as they are, or as RawJson. A proxy
 * with binary_encoding enabled sends them base64-encoded in the b64 fields,
 * with encoding "binary"; a result may answer with either field.
 *
 * Security: Centrifugo trusts what a proxy endpoint answers, and the endpoint
 * trusts what Centrifugo sends - a SubscribeRequest's user is the user
 * Centrifugo authenticated. Anyone else able to reach the endpoint could claim
 * any user. Keep proxy endpoints reachable by Centrifugo only: on an internal
 * network, or behind a check of a secret Centrifugo sends as a static header.
 *
 * @template TRequest of Message
 * @template TResult of Message
 */
abstract class ProxyHandler extends Handler
{
    /**
     * The default bound of a request's body, 16 MiB: far above what
     * Centrifugo's own limits on client messages let through, it only stops a
     * request which is not Centrifugo's from taking the memory it likes.
     */
    public const int DEFAULT_MAX_BODY = 16 << 20;

    protected const int RESULT_ONLY = 0;

    protected const int ERROR = 1;

    protected const int ERROR_DISCONNECT = 2;

    /** @var \Closure(TRequest, HttpRequest): TResult */
    private readonly \Closure $func;

    /**
     * @param callable(TRequest, HttpRequest): TResult $func answers the proxy
     *     request: it takes the decoded request and the HTTP request - with the
     *     headers Centrifugo passed on
     * @param ?callable(HttpRequest, \Throwable): void $onError told of every 500
     *     and 400, with the exception
     */
    public function __construct(callable $func, ?callable $onError = null, int $maxBody = self::DEFAULT_MAX_BODY)
    {
        parent::__construct($onError, $maxBody);
        $this->func = $func(...);
    }

    /** The proxy's name, for messages: "connect". */
    abstract protected function name(): string;

    /** @return class-string<TRequest> */
    abstract protected function requestClass(): string;

    /** @return class-string<TResult> */
    abstract protected function resultClass(): string;

    /** What the proxy's response can carry besides a result: RESULT_ONLY, ERROR or ERROR_DISCONNECT. */
    abstract protected function answers(): int;

    protected function begin(HttpRequest $req, string $body): HttpResponse|array
    {
        if ($req->method !== 'POST') {
            return HttpResponse::allow('POST');
        }
        try {
            $class = $this->requestClass();
            $decoded = $class::fromWire(Decode::json($body, $this->name()), $this->name());
        } catch (CentException $e) {
            return $this->fail($req, 400, new \UnexpectedValueException("proxy: malformed {$this->name()} request: {$e->getMessage()}", 0, $e));
        }

        return [$decoded];
    }

    protected function call(HttpRequest $req, array $args): mixed
    {
        /** @var TRequest $request */
        $request = $args[0];

        return ($this->func)($request, $req);
    }

    protected function end(HttpRequest $req, array $args, mixed $out, ?\Throwable $error): HttpResponse
    {
        if ($error === null) {
            $class = $this->resultClass();
            if (!$out instanceof $class) {
                return $this->fail($req, 500, new \UnexpectedValueException("proxy: the {$this->name()} function returned " . get_debug_type($out) . ", not {$class}"));
            }
            try {
                $body = '{"result":' . $out->toJson() . '}';
            } catch (CentException $e) {
                // A payload which is not JSON, say: Centrifugo could not use the response.
                return $this->fail($req, 500, $e);
            }

            return new HttpResponse(200, ['Content-Type' => 'application/json'], $body);
        }
        $disconnect = self::find($error, Disconnect::class);
        $proxyError = self::find($error, ProxyError::class);
        if ($disconnect !== null && $this->answers() === self::ERROR_DISCONNECT) {
            $body = '{"disconnect":{"code":' . $disconnect->getCode() . ',"reason":' . Encode::string($disconnect->reason, 'reason') . '}}';
        } elseif ($proxyError !== null && $this->answers() !== self::RESULT_ONLY) {
            $body = '{"error":{"code":' . $proxyError->getCode() . ',"message":' . Encode::string($proxyError->getMessage(), 'message')
                . ($proxyError->temporary ? ',"temporary":true' : '') . '}}';
        } elseif ($disconnect !== null || $proxyError !== null) {
            // Centrifugo would ignore it in this proxy's response.
            return $this->fail($req, 500, new \UnexpectedValueException("proxy: the {$this->name()} proxy cannot answer with " . $error::class . ': failing the request', 0, $error));
        } else {
            return $this->fail($req, 500, $error);
        }

        return new HttpResponse(200, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * $error or a previous exception of it, when it is a $class.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return ?T
     */
    private static function find(\Throwable $error, string $class): ?\Throwable
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return $e;
            }
        }

        return null;
    }
}
