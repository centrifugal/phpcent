<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\NetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client the application passed: its own timeouts, redirect policy and
 * connection handling apply - PSR-18 has no way to give a request a deadline.
 * The client is never changed: credentials and headers go on each request.
 *
 * @internal
 */
final class Psr18Transport implements Transport
{
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
        try {
            $resp = $this->client->sendRequest($req);
        } catch (ClientExceptionInterface $e) {
            throw new NetworkException("{$method}: " . $e::class . ': ' . $e->getMessage(), false, $e);
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
            throw new NetworkException("{$method}: reading the reply: {$e->getMessage()}", false, $e);
        } finally {
            $stream->close();
        }

        return new TransportResponse($status, $respHeaders, $out);
    }

    public function close(): void {}
}
