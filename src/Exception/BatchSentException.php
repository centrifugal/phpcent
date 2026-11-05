<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A batch sent again: a batch is sent once.
 */
final class BatchSentException extends \LogicException implements CentException {}
