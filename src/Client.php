<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\CentException;
use Centrifugal\Phpcent\Exception\ClosedException;
use Centrifugal\Phpcent\Exception\InvalidConfigException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Exception\TimeoutException;
use Centrifugal\Phpcent\Internal\BroadcastRequest;
use Centrifugal\Phpcent\Internal\CurlTransport;
use Centrifugal\Phpcent\Internal\Headers;
use Centrifugal\Phpcent\Internal\Limits;
use Centrifugal\Phpcent\Internal\Methods;
use Centrifugal\Phpcent\Internal\Psr18Transport;
use Centrifugal\Phpcent\Internal\Reply;
use Centrifugal\Phpcent\Internal\Request;
use Centrifugal\Phpcent\Internal\Transport;
use Centrifugal\Phpcent\Internal\Validate;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Calls Centrifugo's HTTP server API. Create it once and reuse it:
 *
 *     $client = new Client('http://localhost:8000/api', apiKey: getenv('CENTRIFUGO_API_KEY'));
 *     $client->publish(channel: 'news', data: ['text' => 'hello']);
 *
 * There is a method per API method, taking the fields of its request as named
 * arguments - pass them by name: their order follows api.proto and is not part
 * of the API. Each returns its result, throwing unless Centrifugo did
 * everything asked, and takes $timeout, in seconds; without one,
 * requestTimeout bounds the call.
 */
final class Client
{
    use ClientMethods;

    public const string USER_AGENT = 'phpcent/7';

    private readonly string $endpoint;

    /** @var ?\Closure(): string */
    private readonly ?\Closure $endpointFunc;

    /** @var ?\Closure(): string */
    private readonly ?\Closure $tokenFunc;

    /** @var ?\Closure(RequestInfo): void */
    private readonly ?\Closure $onRequest;

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly Transport $transport;

    private readonly float $requestTimeout;

    private bool $closed = false;

    /**
     * @param ?string $apiEndpoint the base URL of the API,
     *     "http://localhost:8000/api". Each method is sent to it plus its name,
     *     "/publish".
     * @param string $apiKey sent as X-API-Key. Leave it and bearerTokenFunc
     *     unset when requests are authenticated another way - mutual TLS, a
     *     proxy - or not at all.
     * @param ?callable(): string $apiEndpointFunc instead of apiEndpoint, returns
     *     it for each request, for addresses discovered rather than fixed
     * @param ?callable(): string $bearerTokenFunc returns the token sent as
     *     "Authorization: Bearer" with each request, for Centrifugo PRO's JWKS
     *     authentication of the API - an ApiTokens's token method, say. Not
     *     together with apiKey.
     * @param ?ClientInterface $httpClient a PSR-18 client sending the requests,
     *     instead of curl: Guzzle, Symfony HttpClient, a fake in tests. It is
     *     never changed: credentials and headers go on each request. Its own
     *     timeouts and redirect policy apply - PSR-18 has no way to give a
     *     request a deadline.
     * @param (RequestFactoryInterface&StreamFactoryInterface)|null $httpFactory
     *     the PSR-17 factories for httpClient's requests; not needed when
     *     httpClient implements them itself, as Symfony's Psr18Client does
     * @param array<int, mixed> $curlOptions extra curl options for the default
     *     transport: CURLOPT_CAINFO, CURLOPT_SSLCERT, CURLOPT_IPRESOLVE and the
     *     like. Not the options phpcent sets itself.
     * @param float $requestTimeout seconds bounding a call without a timeout of
     *     its own, 10 by default. curl bounds the whole request by it -
     *     connecting, sending, reading the reply.
     * @param array<string, string> $headers extra headers for every request, for
     *     a proxy in front of Centrifugo. Not X-API-Key, Authorization or
     *     Content-Type. A User-Agent replaces phpcent's.
     * @param ?callable(RequestInfo): void $onRequest called after every HTTP
     *     request with a RequestInfo, for metrics; the call waits for it
     *
     * @throws InvalidConfigException naming the option and the fix
     */
    public function __construct(
        ?string $apiEndpoint = null,
        string $apiKey = '',
        ?callable $apiEndpointFunc = null,
        ?callable $bearerTokenFunc = null,
        ?ClientInterface $httpClient = null,
        (RequestFactoryInterface&StreamFactoryInterface)|null $httpFactory = null,
        array $curlOptions = [],
        float $requestTimeout = Limits::REQUEST_TIMEOUT,
        array $headers = [],
        ?callable $onRequest = null,
    ) {
        if ($apiEndpoint === null && $apiEndpointFunc === null) {
            throw new InvalidConfigException('apiEndpoint is required, for example http://localhost:8000/api');
        }
        if ($apiEndpoint !== null && $apiEndpointFunc !== null) {
            throw new InvalidConfigException('set apiEndpoint or apiEndpointFunc, not both');
        }
        $this->endpoint = $apiEndpoint !== null ? self::checkEndpoint($apiEndpoint, 'apiEndpoint') : '';
        $this->endpointFunc = $apiEndpointFunc !== null ? $apiEndpointFunc(...) : null;

        if ($apiKey !== '' && $bearerTokenFunc !== null) {
            throw new InvalidConfigException('set apiKey or bearerTokenFunc, not both: a request with a bearer token is judged by the token alone');
        }
        if (!Headers::validValue($apiKey)) {
            throw new InvalidConfigException('apiKey must be printable ASCII: no line breaks, control or non-ASCII characters');
        }
        $this->tokenFunc = $bearerTokenFunc !== null ? $bearerTokenFunc(...) : null;

        $h = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $lower = strtolower($name);
            if ($lower === 'x-api-key') {
                throw new InvalidConfigException('headers may not set X-API-Key; use apiKey');
            }
            if ($lower === 'authorization') {
                throw new InvalidConfigException('headers may not set Authorization; use bearerTokenFunc');
            }
            if ($lower === 'content-type') {
                throw new InvalidConfigException('headers may not set Content-Type');
            }
            if (!Headers::validName($name)) {
                throw new InvalidConfigException("headers: \"{$name}\" is not a valid HTTP header name");
            }
            if (!\is_string($value) || !Headers::validValue($value)) {
                throw new InvalidConfigException("headers: {$name} must be a string of printable ASCII: no line breaks, control or non-ASCII characters");
            }
            if ($lower === 'host' && $value === '') {
                throw new InvalidConfigException('headers: Host must not be empty');
            }
            $h[$name] = $value;
        }
        $h['Content-Type'] = 'application/json';
        if (!\in_array('user-agent', array_map(strtolower(...), array_keys($h)), true)) {
            $h['User-Agent'] = self::USER_AGENT;
        }
        if ($apiKey !== '') {
            $h['X-API-Key'] = $apiKey;
        }
        $this->headers = $h;

        if (!is_finite($requestTimeout) || $requestTimeout <= 0) {
            throw new InvalidConfigException('requestTimeout must be a positive number of seconds');
        }
        $this->requestTimeout = $requestTimeout;
        $this->onRequest = $onRequest !== null ? $onRequest(...) : null;

        if ($httpClient !== null) {
            if ($curlOptions !== []) {
                throw new InvalidConfigException('curlOptions are for the default transport: configure httpClient instead');
            }
            $factory = $httpFactory ?? $httpClient;
            if (!$factory instanceof RequestFactoryInterface || !$factory instanceof StreamFactoryInterface) {
                throw new InvalidConfigException(
                    'httpClient needs PSR-17 factories for its requests: pass httpFactory - new GuzzleHttp\Psr7\HttpFactory() '
                    . 'or new Nyholm\Psr7\Factory\Psr17Factory(), say',
                );
            }
            $this->transport = new Psr18Transport($httpClient, $factory, $factory);
        } else {
            if ($httpFactory !== null) {
                throw new InvalidConfigException('httpFactory is for httpClient: set both, or neither');
            }
            if (!CurlTransport::available()) {
                throw new InvalidConfigException('the default transport needs ext-curl: enable it, or pass a PSR-18 httpClient');
            }
            $this->transport = new CurlTransport($curlOptions);
        }
    }

    // Broadcast: the public methods are generated, with the fields of
    // BroadcastRequest as their arguments.

    private function sendBroadcast(BroadcastRequest $request, ?float $timeout): BroadcastResult
    {
        $channels = array_values($request->channels);
        $result = $this->result($request, $timeout);

        return BroadcastResult::fromWire($result, 'broadcast result', $channels, $request->idempotencyKey)->check();
    }

    /** @param ?callable(BroadcastAttempt): ?float $onAttemptError */
    private function retryBroadcast(
        BroadcastRequest $request,
        int $maxAttempts,
        ?float $attemptTimeout,
        ?callable $onAttemptError,
        ?float $timeout,
    ): BroadcastResult {
        if ($maxAttempts < 1) {
            throw new InvalidRequestException('invalid broadcast request: maxAttempts must be 1 or more');
        }
        if ($attemptTimeout !== null && (!is_finite($attemptTimeout) || $attemptTimeout <= 0)) {
            throw new InvalidRequestException('invalid broadcast request: attemptTimeout must be positive');
        }
        Validate::encode($request);
        // Without a timeout the call has no bound of its own: each attempt has
        // attemptTimeout, or requestTimeout as a call alone would, so that an
        // attempt which hangs leaves time to retry.
        $deadline = $timeout !== null ? $this->deadline($timeout) : \INF;
        $perAttempt = $attemptTimeout ?? $this->requestTimeout;

        $channels = array_values($request->channels);
        $key = $request->idempotencyKey !== '' ? $request->idempotencyKey : 'phpcent-' . self::randomKey();
        /** @var list<ChannelResult> $final */
        $final = array_map(static fn(string $ch): ChannelResult => new ChannelResult($ch), $channels);
        // Positions in channels of what the next attempt sends: positions, not
        // names, so that a channel listed twice keeps both entries.
        $pending = array_keys($channels);
        $replied = false;
        $attempts = 0;
        $last = null;
        $backoff = Retry::exponentialBackoff(Limits::BACKOFF_BASE, Limits::BACKOFF_LIMIT);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $left = $deadline - self::now();
            if ($left <= 0) {
                $last ??= new TimeoutException('broadcast: timed out');
                break;
            }
            $attemptReq = clone $request;
            $attemptReq->channels = array_map(static fn(int $p): string => $channels[$p], $pending);
            $attemptReq->idempotencyKey = $key;
            try {
                $res = $this->sendBroadcast($attemptReq, min($left, $perAttempt));
                $err = null;
            } catch (InvalidRequestException $e) {
                throw $e;
            } catch (BroadcastFailedChannelsException $e) {
                [$res, $err] = [$e->result, $e];
            } catch (\Throwable $e) {
                if (!$e instanceof CentException) {
                    throw $e;
                }
                [$res, $err] = [BroadcastResult::failedAsWhole($attemptReq->channels, $e, 1, $key), $e];
            }
            $attempts++;
            foreach ($pending as $i => $pos) {
                $final[$pos] = $res->channels[$i]->withAttempts($final[$pos]->attempts + 1);
            }
            if ($err === null || $err instanceof BroadcastFailedChannelsException) {
                $replied = true;
            }
            $last = $err;
            // An ended deadline leaves no time for another attempt.
            if ($err === null || !Retry::retryable($err) || $attempt === $maxAttempts || self::now() >= $deadline) {
                break;
            }
            $pending = array_values(array_filter($pending, static fn(int $pos): bool => Retry::retryable($final[$pos]->error)));
            $wait = null;
            if ($onAttemptError !== null) {
                $info = new BroadcastAttempt($attempt, $err, array_map(static fn(int $p): ChannelResult => $final[$p], $pending), new BroadcastResult(array_values($final), $key, $attempts, $replied), $key);

                $wait = $onAttemptError($info);
            }
            $delay = $wait ?? $backoff($attempt);
            $sleep = max(0.0, min($delay, $deadline - self::now()));
            if ($sleep > 0) {
                usleep((int) ($sleep * 1e6));
            }
            $this->checkOpen();
        }
        $out = self::outcome(new BroadcastResult(array_values($final), $key, $attempts, $replied), $replied, $last);
        if ($out instanceof \Throwable) {
            throw $out;
        }

        return $out;
    }

    // Batches.

    /**
     * An empty Batch sent by this client. Without $parallel the commands run
     * one by one, in the order added - but Centrifugo PRO sends a sequential
     * batch's publications to its broker together: each channel's take effect
     * in order, publications into different channels may not, unless the
     * namespace sets publication_grouping_disabled. With $parallel, Centrifugo
     * runs them concurrently, with no order.
     */
    public function newBatch(bool $parallel = false): Batch
    {
        return new Batch($this, $parallel);
    }

    // Shutdown.

    /**
     * Stops the client: calls made from now on throw ClosedException, and its
     * connections are closed. A broadcast being retried stops at its next
     * attempt.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->transport->close();
    }

    // Sending.

    /**
     * @template T
     *
     * @param Request<T> $request
     *
     * @return T
     */
    private function invoke(Request $request, ?float $timeout): mixed
    {
        $method = $request::apiMethod();

        return $request::decodeResult($this->result($request, $timeout), "{$method} result");
    }

    /** @param Request<mixed> $request */
    private function result(Request $request, ?float $timeout): \stdClass
    {
        $method = $request::apiMethod();
        $deadline = $this->deadline($timeout);
        /** @var array{\stdClass, ?ApiException} $out */
        $out = $this->call(
            $method,
            static fn(): string => Validate::encode($request),
            1,
            $deadline,
            static function (string $raw) use ($method): array {
                $out = Reply::result($raw, $method);

                return [$out, $out[1] === null ? 0 : 1];
            },
        );
        [$result, $err] = $out;
        if ($err !== null) {
            throw $err;
        }

        return $result;
    }

    /**
     * @internal
     *
     * @return list<\stdClass>
     */
    public function sendBatch(string $body, int $n, ?float $timeout): array
    {
        /** @var list<\stdClass> */
        return $this->call(
            'batch',
            static fn(): string => $body,
            $n,
            $this->deadline($timeout),
            static function (string $raw) use ($n): array {
                $replies = Reply::batch($raw, $n);

                return [$replies, \count(array_filter($replies, static fn(\stdClass $r): bool => isset($r->error)))];
            },
        );
    }

    /** @internal */
    public function checkOpen(): void
    {
        if ($this->closed) {
            throw new ClosedException('client closed');
        }
    }

    /**
     * @param \Closure(): string $makeBody
     * @param \Closure(string): array{mixed, int} $parse
     */
    private function call(string $method, \Closure $makeBody, int $commands, float $deadline, \Closure $parse): mixed
    {
        $this->checkOpen();
        $start = self::now();
        $status = 0;
        try {
            [$out, $failed] = $parse($this->roundtrip($method, $makeBody, $deadline, $status));
        } catch (\Throwable $e) {
            $this->report($method, $commands, 0, $start, $status, $e);

            throw $e;
        }
        $this->report($method, $commands, $failed, $start, $status, null);

        return $out;
    }

    /** @param \Closure(): string $makeBody */
    private function roundtrip(string $method, \Closure $makeBody, float $deadline, int &$status): string
    {
        $base = $this->endpoint;
        if ($this->endpointFunc !== null) {
            $base = self::checkEndpoint(($this->endpointFunc)(), 'apiEndpointFunc');
        }
        $headers = $this->headers;
        if ($this->tokenFunc !== null) {
            $token = ($this->tokenFunc)();
            if ($token === '' || !Headers::validValue($token)) {
                throw new InvalidConfigException('bearerTokenFunc returned an empty token, or one which is not printable ASCII');
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = $makeBody();
        $left = $deadline - self::now();
        if ($left <= 0) {
            throw new TimeoutException("{$method}: timed out");
        }
        $resp = $this->transport->post("{$base}/{$method}", $headers, $body, $left, $method);
        $status = $resp->status;
        if ($resp->status !== 200) {
            throw Reply::httpError($method, $resp);
        }

        return $resp->body;
    }

    private function report(string $method, int $commands, int $failed, float $start, int $status, ?\Throwable $error): void
    {
        if ($this->onRequest !== null) {
            ($this->onRequest)(new RequestInfo($method, $commands, $failed, self::now() - $start, $status, $error));
        }
    }

    private function deadline(?float $timeout): float
    {
        if ($timeout === null) {
            $timeout = $this->requestTimeout;
        } elseif (!is_finite($timeout) || $timeout < 0) {
            throw new InvalidRequestException('timeout must be a non-negative number of seconds');
        }

        return self::now() + $timeout;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    private static function randomKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * The final result of broadcastWithRetry, or the exception to throw:
     * BroadcastFailedChannelsException when some attempt got a reply, the last
     * attempt's exception otherwise.
     */
    private static function outcome(BroadcastResult $res, bool $replied, ?\Throwable $last): BroadcastResult|\Throwable
    {
        if (!$replied) {
            return $last ?? new \LogicException('phpcent: broadcastWithRetry: no reply and no error');
        }
        try {
            return $res->check();
        } catch (BroadcastFailedChannelsException $e) {
            return $e;
        }
    }

    /** The endpoint without a trailing slash, or InvalidConfigException naming what is wrong. */
    private static function checkEndpoint(string $addr, string $option): string
    {
        $u = parse_url($addr);
        if ($u === false) {
            throw new InvalidConfigException("{$option}: \"{$addr}\" is not a valid URL");
        }
        $scheme = strtolower($u['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidConfigException("{$option}: \"{$addr}\" must be an absolute http:// or https:// URL");
        }
        if (($u['host'] ?? '') === '') {
            throw new InvalidConfigException("{$option}: \"{$addr}\" has no host");
        }
        if (isset($u['query']) || isset($u['fragment']) || isset($u['user']) || isset($u['pass'])) {
            throw new InvalidConfigException("{$option}: \"{$addr}\" must not have a query, fragment or credentials; use apiKey");
        }
        $path = rtrim($u['path'] ?? '', '/');
        $last = substr($path, (int) strrpos($path, '/') + 1);
        if (isset(Methods::PRO[$last])) {
            throw new InvalidConfigException("{$option}: \"{$addr}\" ends with the method \"{$last}\"; it is the API base URL, such as http://localhost:8000/api");
        }
        $host = str_contains($u['host'], ':') && !str_starts_with($u['host'], '[') ? "[{$u['host']}]" : $u['host'];

        return $scheme . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '') . $path;
    }
}
