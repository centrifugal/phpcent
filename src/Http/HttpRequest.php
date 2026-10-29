<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Http;

/** A request to a handler, as the application's function receives it. */
final class HttpRequest
{
    /**
     * @param string $method the HTTP method, upper case
     * @param array<string, string> $headers the headers, names in lower case,
     *     values of a repeated header joined with ", ": those Centrifugo passes
     *     on from the client's connection to a proxy, the client SDK's to an
     *     endpoint
     * @param array<string, string> $query the query parameters
     * @param string $path the path, as the server saw it
     * @param mixed $raw the server's own form of the request - a PSR-7
     *     ServerRequestInterface, $_SERVER, or what was given to handle - for
     *     what the core does not carry, such as the client's address or a user
     *     your framework authenticated
     */
    public function __construct(
        public readonly string $method,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly string $path = '',
        public readonly mixed $raw = null,
    ) {}

    /** A header's value, looked up case-insensitively; null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
