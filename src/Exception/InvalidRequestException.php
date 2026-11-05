<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A request the client can tell is wrong without sending it: one which cannot be
 * encoded - a payload which is not JSON, in any request - or a publication
 * without a channel or data. It is never sent.
 *
 * Only rules which hold for every Centrifugo are checked; everything else
 * Centrifugo checks itself, and reports as a BadRequestException.
 */
final class InvalidRequestException extends \InvalidArgumentException implements CentException {}
