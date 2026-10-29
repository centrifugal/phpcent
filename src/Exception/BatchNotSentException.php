<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A pending command's result read before its batch was sent.
 */
final class BatchNotSentException extends \LogicException implements CentException {}
