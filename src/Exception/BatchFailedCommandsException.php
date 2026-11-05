<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

use Centrifugal\Phpcent\BatchResult;
use Centrifugal\Phpcent\FailedCommand;

/**
 * A batch in which one or more commands failed, up to all of them. Every
 * command got its own reply: each Pending holds its outcome. $result is the
 * batch's.
 *
 * It is not an ApiException of its parts: look at each command's error in
 * $failed, or at its Pending.
 */
final class BatchFailedCommandsException extends \RuntimeException implements CentException
{
    /**
     * @param list<FailedCommand> $failed
     */
    public function __construct(
        public readonly array $failed,
        public readonly int $total,
        public readonly BatchResult $result,
    ) {
        parent::__construct(Failures::message(
            'batch',
            'commands',
            'succeeded',
            $total,
            array_map(static fn(FailedCommand $f): array => ["#{$f->index} {$f->method}", $f->error], $failed),
        ));
    }
}
