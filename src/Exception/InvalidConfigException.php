<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A configuration with a mistake: the message names the option and the fix.
 */
final class InvalidConfigException extends \InvalidArgumentException implements CentException
{
    use IdempotencyKey;
}
