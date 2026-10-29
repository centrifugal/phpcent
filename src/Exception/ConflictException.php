<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 113: the request conflicts with the current state - a map publish whose
 * version check failed, say.
 */
final class ConflictException extends ApiException
{
    public const int CODE = 113;
}
