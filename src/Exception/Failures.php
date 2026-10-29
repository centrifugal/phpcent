<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/** @internal */
final class Failures
{
    /** How many failures a partial error names in its message; all stay in the exception. */
    private const int MAX_NAMED = 3;

    /**
     * "broadcast: 2 of 4 channels failed, 2 got the publication: a: ...; b: ..."
     *
     * @param list<array{string, ?\Throwable}> $items
     */
    public static function message(string $op, string $parts, string $rest, int $total, array $items): string
    {
        $failed = \count($items);
        $s = "{$op}: {$failed} of {$total} {$parts} failed";
        if ($failed < $total) {
            $s .= ', ' . ($total - $failed) . " {$rest}";
        }
        $named = [];
        foreach ($items as $i => [$label, $err]) {
            if ($i === self::MAX_NAMED) {
                $named[] = 'and ' . ($failed - $i) . ' more';
                break;
            }
            $named[] = "{$label}: " . ($err?->getMessage() ?? '');
        }

        return $named !== [] ? $s . ': ' . implode('; ', $named) : $s;
    }
}
