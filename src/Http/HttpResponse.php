<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Http;

/** A handler's answer. */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * @internal
     *
     * @param array<string, string> $extra
     */
    public static function text(int $status, string $message, array $extra = []): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'] + $extra, $message);
    }

    /** @internal */
    public static function status(int $status): self
    {
        return self::text($status, match ($status) {
            400 => 'Bad Request',
            403 => 'Forbidden',
            405 => 'Method Not Allowed',
            default => 'Internal Server Error',
        });
    }

    /** @internal */
    public static function allow(string $methods): self
    {
        return self::text(405, "only {$methods} allowed", ['Allow' => $methods]);
    }

    /** @internal */
    public static function json(string $body, string $cache = 'no-store'): self
    {
        return new self(200, ['Content-Type' => 'application/json', 'Cache-Control' => $cache], $body);
    }
}
