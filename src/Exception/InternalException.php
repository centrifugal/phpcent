<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 100: an internal error of Centrifugo, temporary.
 */
final class InternalException extends ApiException
{
    public const int CODE = 100;
}
