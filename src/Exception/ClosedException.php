<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A call made after the client was closed.
 */
final class ClosedException extends \RuntimeException implements CentException {}
