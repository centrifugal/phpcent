<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 108: the feature is not available - history or presence off in the
 * channel's namespace, say.
 */
final class NotAvailableException extends ApiException
{
    public const int CODE = 108;
}
