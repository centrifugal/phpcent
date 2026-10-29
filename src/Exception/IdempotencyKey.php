<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * The idempotency key of the exception broadcastWithRetry throws when no
 * attempt got a reply - a timeout, say.
 */
trait IdempotencyKey
{
    /**
     * The key broadcastWithRetry's attempts carried - made for the call when
     * the request had none - set on the exception it throws when no attempt
     * got a reply: Centrifugo may have published, and a retry with this key
     * does not publish twice. Null on other exceptions.
     */
    public ?string $idempotencyKey = null;
}
