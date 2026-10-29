<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Internal\Encode;

/**
 * The outcome of a sent batch as a whole. Each command's own outcome is in the
 * Pending its method returned.
 *
 * json_encode renders it as {"replied", "failed"}, each failed command as
 * {"index", "method", "error"} and an error as {"code", "message"}.
 */
final class BatchResult implements \JsonSerializable
{
    /**
     * @param bool $replied true when Centrifugo replied to every command, the
     *     failed ones included; false when the batch failed as a whole. True
     *     for an empty batch.
     * @param list<FailedCommand> $failed the commands which failed, with their
     *     errors, in the order they were added - as the exception's $failed
     *     lists them
     */
    public function __construct(
        public readonly bool $replied,
        public readonly array $failed = [],
    ) {}

    /** @return array{replied: bool, failed: list<array{index: int, method: string, error: array{code?: int, message: string}}>} */
    public function jsonSerialize(): array
    {
        return [
            'replied' => $this->replied,
            'failed' => array_map(static fn(FailedCommand $f): array => [
                'index' => $f->index,
                'method' => $f->method,
                'error' => Encode::error($f->error),
            ], $this->failed),
        ];
    }
}
