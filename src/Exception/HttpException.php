<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A reply with an HTTP status other than 200: the request did not reach the API
 * method. A 401 is an UnauthorizedException.
 */
class HttpException extends \RuntimeException implements CentException
{
    use IdempotencyKey;

    /**
     * @param int $statusCode the reply's HTTP status
     * @param string $body the start of the reply's body, for diagnostics
     * @param array<string, string> $headers the reply's headers, names in lower
     *     case: a request ID a proxy set, a Retry-After
     * @param string $proMethod a Centrifugo PRO method which got a 404, for the
     *     hint in the message
     */
    final public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly array $headers = [],
        string $proMethod = '',
    ) {
        $message = "unexpected HTTP status {$statusCode}";
        if ($statusCode >= 300 && $statusCode < 400) {
            // Centrifugo's API never redirects: something in front of it does,
            // which phpcent does not follow.
            $message .= ' (a redirect, which phpcent does not follow: check apiEndpoint, http or https say)';
        } elseif ($statusCode === 404 && $proMethod !== '') {
            $message .= " ({$proMethod} is a Centrifugo PRO method: if other methods work, the server is Centrifugo OSS)";
        } elseif ($statusCode === 404) {
            // The usual cause: apiEndpoint lacks the API prefix.
            $message .= ' (is apiEndpoint the API base URL, such as http://localhost:8000/api?)';
        } elseif ($statusCode === 401) {
            $message .= ' (check the credentials: apiKey or bearerTokenFunc)';
        }
        if ($body !== '') {
            $message .= ': ' . (preg_match('//u', $body) === 1 ? $body : '(not UTF-8)');
        }
        parent::__construct($message, $statusCode);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function fromStatus(int $statusCode, string $body = '', array $headers = [], string $proMethod = ''): self
    {
        return $statusCode === 401
            ? new UnauthorizedException($statusCode, $body, $headers, $proMethod)
            : new self($statusCode, $body, $headers, $proMethod);
    }
}
