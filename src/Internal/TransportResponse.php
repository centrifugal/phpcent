<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

/** @internal */
final class TransportResponse
{
    /**
     * @param array<string, string> $headers names in lower case, values of a
     *     repeated header joined with ", "
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}
}
