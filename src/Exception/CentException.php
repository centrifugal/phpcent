<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * Implemented by every exception phpcent throws: catch CentException for all of
 * them.
 */
interface CentException extends \Throwable {}
