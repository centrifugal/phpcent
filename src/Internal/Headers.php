<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

/** @internal */
final class Headers
{
    public static function validName(string $name): bool
    {
        return preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) === 1;
    }

    /** Printable ASCII and tab: what HTTP header values carry without an encoding. */
    public static function validValue(string $value): bool
    {
        return preg_match('/\A[\x20-\x7E\t]*\z/', $value) === 1;
    }
}
