<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * Thrown by a proxy function to close the client's connection: a code in
 * [4000, 4999] - [4000, 4499] tells the client to reconnect, [4500, 4999] not
 * to. $reason is a short description, at most 32 bytes.
 */
final class Disconnect extends \RuntimeException
{
    public function __construct(int $code, public readonly string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct("disconnect: {$reason} (code {$code})", $code, $previous);
    }
}
