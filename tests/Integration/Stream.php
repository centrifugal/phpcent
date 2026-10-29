<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Integration;

/**
 * A client connection over Centrifugo's unidirectional HTTP stream: one JSON
 * message per line.
 */
final class Stream
{
    /** @var resource */
    private $conn;

    /** @param array<string, mixed> $body */
    public function __construct(string $url, array $body)
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body, \JSON_THROW_ON_ERROR),
            // HTTP/1.0: the reply streams until the connection closes, unchunked.
            'protocol_version' => 1.0,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);
        $conn = fopen($url, 'r', false, $ctx);
        if ($conn === false) {
            throw new \RuntimeException("cannot connect to {$url}");
        }
        stream_set_timeout($conn, 30);
        $this->conn = $conn;
    }

    /**
     * The next message, null once the connection is closed; pings skipped.
     *
     * @return ?array<string, mixed>
     */
    public function next(): ?array
    {
        while (($line = fgets($this->conn)) !== false) {
            $line = trim($line);
            if ($line === '' || $line === '{}') {
                continue;
            }
            $msg = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($msg));

            /** @var array<string, mixed> $msg */
            return $msg;
        }

        return null;
    }

    public function close(): void
    {
        fclose($this->conn);
    }
}
