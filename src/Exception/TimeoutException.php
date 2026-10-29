<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * The request timed out: its timeout, or requestTimeout. A request which timed
 * out may still have been carried out.
 */
final class TimeoutException extends \RuntimeException implements CentException
{
    use IdempotencyKey;
}
