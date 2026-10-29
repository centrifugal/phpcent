<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 107: Centrifugo refused the request as invalid.
 */
final class BadRequestException extends ApiException
{
    public const int CODE = 107;
}
