<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\TimeoutException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client the application passed: its redirect policy and connection
 * handling apply. PSR-18 has no way to give a request a deadline, so Guzzle
 * and Symfony's Psr18Client get the call's through their own API - Guzzle's
 * `timeout` request option, Symfony's `max_duration` - and any other client
 * has only its own timeouts. The client is never changed: credentials and
 * headers go on each request.
 *
 * PSR-18 has no timeout or certificate exceptions either: the client's
 * exception is a TimeoutException when the call's time ran out or its
 * message says it timed out - curl's error 28, Guzzle's and Symfony's
 * wording - and a NetworkException with $certificate set when it says a TLS
 * certificate was refused.
 *
 * @internal
 */
final class Psr18Transport implements Transport
{
    /** A timeout, as curl - and so Guzzle - and Symfony's client word it. */
    private const string TIMEOUT = '/cURL error 28\b|timed out|timeout reached/i';

    /** A TLS certificate refused: curl's errors for it, or the word. */
    private const string CERTIFICATE = '/cURL error (?:51|58|60|77|83|90|91)\b|certificate/i';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function post(string $url, array $headers, string $body, float $timeout, string $method): TransportResponse
    {
        $req = $this->requests->createRequest('POST', $url)->withBody($this->streams->createStream($body));
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        $start = hrtime(true);
        try {
            $resp = $this->send($req, $timeout);
        } catch (ClientExceptionInterface $e) {
            self::timedOut($e, $method, $start, $timeout);

            throw new NetworkException("{$method}: " . $e::class . ': ' . $e->getMessage(), preg_match(self::CERTIFICATE, self::messages($e)) === 1, $e);
        }
        $status = $resp->getStatusCode();
        $respHeaders = [];
        foreach ($resp->getHeaders() as $name => $values) {
            $respHeaders[strtolower((string) $name)] = implode(', ', $values);
        }
        $stream = $resp->getBody();
        try {
            if ($status === 200) {
                $out = (string) $stream;
            } else {
                $out = '';
                $read = 0;
                while (!$stream->eof() && $read < Limits::DRAIN) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        break;
                    }
                    $read += \strlen($chunk);
                    if (\strlen($out) < Limits::ERROR_BODY) {
                        $out .= substr($chunk, 0, Limits::ERROR_BODY - \strlen($out));
                    }
                }
            }
        } catch (\RuntimeException $e) {
            // Symfony's client reads the body as it is asked for: its
            // max_duration ends here.
            self::timedOut($e, $method, $start, $timeout);

            throw new NetworkException("{$method}: reading the reply: {$e->getMessage()}", false, $e);
        } finally {
            $stream->close();
        }
        // A body which ended once the call's time ran out may have been cut
        // short there: some clients end it at their deadline without an
        // error - Symfony's 7.3.0 does at max_duration - and the reply
        // would read as one which is not JSON. It is a timeout.
        if ((hrtime(true) - $start) / 1e9 >= $timeout) {
            throw new TimeoutException("{$method}: timed out reading the reply");
        }

        return new TransportResponse($status, $respHeaders, $out);
    }

    public function close(): void {}

    /**
     * Sends $req within $timeout seconds, where the client lets a request have
     * a deadline: Guzzle's send() with the options its sendRequest() sets
     * itself, Symfony's client with max_duration. A class not installed makes
     * instanceof false: neither library is a dependency.
     */
    private function send(RequestInterface $req, float $timeout): ResponseInterface
    {
        // Both read 0 as no limit.
        $timeout = max($timeout, 0.001);
        if ($this->client instanceof \GuzzleHttp\ClientInterface) {
            return $this->client->send($req, [
                'timeout' => $timeout,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        }
        if ($this->client instanceof \Symfony\Component\HttpClient\Psr18Client) {
            return $this->client->withOptions(['max_duration' => $timeout])->sendRequest($req);
        }

        return $this->client->sendRequest($req);
    }

    /** Throws a TimeoutException when $e is one: the call's time ran out, or its message says it timed out. */
    private static function timedOut(\Throwable $e, string $method, int|float $start, float $timeout): void
    {
        if ((hrtime(true) - $start) / 1e9 >= $timeout || preg_match(self::TIMEOUT, self::messages($e)) === 1) {
            throw new TimeoutException("{$method}: timed out: " . $e::class . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /** The messages of $e and the exceptions it wraps. */
    private static function messages(\Throwable $e): string
    {
        $out = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $out[] = $x->getMessage();
        }

        return implode("\n", $out);
    }
}
