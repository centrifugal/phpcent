<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * The outcome of a sent batch as a whole. Each command's own outcome is in the
 * Pending Batch::add returned.
 */
final class BatchResult
{
    /**
     * @param bool $replied true when Centrifugo replied to every command, the
     *     failed ones included; false when the batch failed as a whole. True
     *     for an empty batch.
     * @param list<FailedCommand> $failed
     */
    public function __construct(
        public readonly bool $replied,
        private readonly array $failed = [],
    ) {}

    /**
     * The commands which failed, with their errors, in the order they were
     * added.
     *
     * @return list<FailedCommand>
     */
    public function failed(): array
    {
        return $this->failed;
    }
}
