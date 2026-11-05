<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * Thrown by a proxy function to reject the client's request with this error:
 * a code in [400, 1999], which Centrifugo leaves to applications. $temporary
 * tells the client that retrying may succeed.
 */
final class ProxyError extends \RuntimeException
{
    public function __construct(int $code, string $message = '', public readonly bool $temporary = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
