<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\InvalidConfigException;
use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\TimeoutException;

/**
 * The default transport: ext-curl, one handle per client, so that its
 * connections are kept alive between calls. With PHP 8.5's persistent share
 * handles, connections are shared between the requests a PHP-FPM worker
 * serves too.
 *
 * curl bounds the whole request - connecting, sending, the reply's headers and
 * body - by CURLOPT_TIMEOUT_MS: the call's one deadline.
 *
 * @internal
 */
final class CurlTransport implements Transport
{
    /** Options the transport sets itself, which curlOptions may not. */
    private const array OWNED = [
        'CURLOPT_URL', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_CUSTOMREQUEST',
        'CURLOPT_RETURNTRANSFER', 'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION', 'CURLOPT_HEADER',
        'CURLOPT_TIMEOUT', 'CURLOPT_TIMEOUT_MS', 'CURLOPT_NOSIGNAL', 'CURLOPT_FOLLOWLOCATION',
        'CURLOPT_PROTOCOLS', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_SHARE', 'CURLOPT_USERAGENT', 'CURLOPT_NOBODY',
        'CURLOPT_HTTPGET', 'CURLOPT_UPLOAD', 'CURLOPT_FILE', 'CURLOPT_WRITEHEADER',
    ];

    /** curl's errors for a TLS certificate refused, which no retry fixes. */
    private const array CERTIFICATE_ERRORS = [51, 58, 60, 77, 83, 90, 91];

    private const int CURLE_OPERATION_TIMEDOUT = 28;

    private const int CURLE_WRITE_ERROR = 23;

    private ?\CurlHandle $handle = null;

    private static mixed $share = null;

    /** @param array<int, mixed> $options */
    public function __construct(private readonly array $options)
    {
        $owned = [];
        foreach (self::OWNED as $name) {
            if (\defined($name)) {
                $owned[\constant($name)] = $name;
            }
        }
        foreach (array_keys($options) as $opt) {
            if (isset($owned[$opt])) {
                throw new InvalidConfigException("curlOptions: {$owned[$opt]} is set by phpcent itself; use the client's options instead");
            }
        }
    }

    public static function available(): bool
    {
        return \extension_loaded('curl');
    }

    public function post(string $url, array $headers, string $body, float $timeout, string $method): TransportResponse
    {
        $h = $this->handle ??= curl_init();
        curl_reset($h);

        $lines = ['Expect:']; // no "100 Continue" round trip before a large body
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $status = 0;
        $respHeaders = [];
        $out = '';
        $read = 0;
        $opts = $this->options;
        if (\defined('CURLOPT_MAXAGE_CONN') && !isset($opts[\CURLOPT_MAXAGE_CONN])) {
            $opts[\CURLOPT_MAXAGE_CONN] = Limits::IDLE_CONNECTION_SECONDS;
        }
        $opts[\CURLOPT_URL] = $url;
        $opts[\CURLOPT_POST] = true;
        $opts[\CURLOPT_POSTFIELDS] = $body;
        $opts[\CURLOPT_HTTPHEADER] = $lines;
        $opts[\CURLOPT_FOLLOWLOCATION] = false;
        $opts[\CURLOPT_NOSIGNAL] = true;
        $opts[\CURLOPT_TIMEOUT_MS] = max(1, (int) ceil($timeout * 1000));
        // CURLOPT_PROTOCOLS_STR needs curl 7.85; CURLOPT_PROTOCOLS is deprecated
        // from it on.
        if (\defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[\constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
        } else {
            $opts[\CURLOPT_PROTOCOLS] = \CURLPROTO_HTTP | \CURLPROTO_HTTPS;
        }
        $opts[\CURLOPT_HEADERFUNCTION] = static function (\CurlHandle $ch, string $line) use (&$status, &$respHeaders): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A new status line - after a 1xx, say - starts the headers over.
                $status = (int) $m[1];
                $respHeaders = [];
            } elseif (($pos = strpos($line, ':')) !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                $respHeaders[$name] = isset($respHeaders[$name]) ? "{$respHeaders[$name]}, {$value}" : $value;
            }

            return \strlen($line);
        };
        $opts[\CURLOPT_WRITEFUNCTION] = static function (\CurlHandle $ch, string $chunk) use (&$status, &$out, &$read): int {
            $read += \strlen($chunk);
            if ($status === 200) {
                $out .= $chunk;

                return \strlen($chunk);
            }
            if (\strlen($out) < Limits::ERROR_BODY) {
                $out .= substr($chunk, 0, Limits::ERROR_BODY - \strlen($out));
            }

            // Past the drain bound the connection is not worth keeping: stop.
            return $read > Limits::DRAIN ? 0 : \strlen($chunk);
        };
        if (\function_exists('curl_share_init_persistent')) {
            self::$share ??= curl_share_init_persistent([\CURL_LOCK_DATA_CONNECT, \CURL_LOCK_DATA_DNS, \CURL_LOCK_DATA_SSL_SESSION]);
            $opts[\CURLOPT_SHARE] = self::$share;
        }
        if (!curl_setopt_array($h, $opts)) {
            throw new NetworkException("{$method}: curl refused an option: " . curl_error($h));
        }

        curl_exec($h);
        $errno = curl_errno($h);
        if ($errno === self::CURLE_WRITE_ERROR && $status !== 200 && $status !== 0) {
            $errno = 0; // stopped draining an error body: the reply is read
        }
        if ($errno === self::CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutException("{$method}: timed out: " . curl_error($h));
        }
        if ($errno !== 0) {
            throw new NetworkException("{$method}: " . curl_error($h) . " (curl error {$errno})", \in_array($errno, self::CERTIFICATE_ERRORS, true));
        }

        return new TransportResponse($status, $respHeaders, $out);
    }

    public function close(): void
    {
        // The handle's connections close with it; a persistent share keeps
        // its own for the next request of the worker.
        $this->handle = null;
    }
}
