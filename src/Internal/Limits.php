<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

/**
 * The defaults and bounds of the spec's section 17.
 *
 * @internal
 */
final class Limits
{
    public const float REQUEST_TIMEOUT = 10.0;

    /**
     * The body of an unexpected status is read up to ERROR_BODY bytes for a
     * Centrifugo error in it, ERROR_KEPT of it kept for the HttpException, and
     * the rest drained up to DRAIN, so that the connection is reused.
     */
    public const int ERROR_BODY = 4 << 10;

    public const int ERROR_KEPT = 512;

    public const int DRAIN = 64 << 10;

    /** Idle connections are closed after it, before load balancers commonly close theirs. */
    public const int IDLE_CONNECTION_SECONDS = 50;

    public const int MAX_ATTEMPTS = 2;

    public const float BACKOFF_BASE = 0.1;

    public const float BACKOFF_LIMIT = 5.0;
}
