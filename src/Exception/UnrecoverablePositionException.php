<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 112: the stream position asked for is not in the history any more.
 */
final class UnrecoverablePositionException extends ApiException
{
    public const int CODE = 112;
}
