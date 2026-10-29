<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Code 104: what the request refers to was not found.
 *
 * A method only Centrifugo PRO has, sent in a batch to Centrifugo OSS, fails
 * with it too, instead of the 404 it gets when sent alone.
 */
final class NotFoundException extends ApiException
{
    public const int CODE = 104;
}
