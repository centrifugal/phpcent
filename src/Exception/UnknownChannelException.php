<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 102: the channel is not in a namespace Centrifugo is configured with.
 */
final class UnknownChannelException extends ApiException
{
    public const int CODE = 102;
}
