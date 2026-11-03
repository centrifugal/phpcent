<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A configuration with a mistake: the message names the option and the fix.
 * Thrown creating a client, and on a call whose apiEndpointFunc returns an
 * endpoint failing apiEndpoint's rules, or whose bearerTokenFunc returns an
 * empty token or one which is not printable ASCII - before anything is sent.
 * Not retryable.
 */
final class InvalidConfigException extends \InvalidArgumentException implements CentException
{
    use IdempotencyKey;
}
