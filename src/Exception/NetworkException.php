<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * The request failed on its way: a refused or reset connection, a failed TLS
 * handshake. The cause, when there is one, is the previous exception.
 */
final class NetworkException extends \RuntimeException implements CentException
{
    use IdempotencyKey;

    /**
     * @param bool $certificate the server's TLS certificate was refused, which
     *     no retry fixes
     */
    public function __construct(
        string $message,
        public readonly bool $certificate = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
