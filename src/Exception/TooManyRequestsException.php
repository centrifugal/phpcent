<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 111: a rate limit was reached, temporary.
 */
final class TooManyRequestsException extends ApiException
{
    public const int CODE = 111;
}
