<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Support;

/**
 * A value for every field of a generated message, nested ones included, read
 * from the types its constructor's docblock gives: so that a test sending a
 * request filled this way passes every required-field check.
 */
final class Fill
{
    /**
     * Every argument of a generated message's constructor.
     *
     * @param class-string $class
     *
     * @return array<string, mixed>
     */
    public static function args(string $class, int $depth = 0): array
    {
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null) {
            return [];
        }
        preg_match_all('/@param (\S+(?:, \S+)?) \$(\w+)/', (string) $ctor->getDocComment(), $m, \PREG_SET_ORDER);
        $types = [];
        foreach ($m as [, $type, $name]) {
            $types[$name] = $type;
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $args[$p->getName()] = self::value($types[$p->getName()] ?? throw new \LogicException("{$class}: no type for \${$p->getName()}"), $class, $depth);
        }

        return $args;
    }

    private static function value(string $type, string $in, int $depth): mixed
    {
        $type = preg_replace('/\|null$/', '', ltrim($type, '?')) ?? $type;
        if (preg_match('/^list<(.+)>$/', $type, $m) === 1) {
            // A message holding itself, as FilterNode, stops somewhere.
            return $depth > 2 ? [] : [self::value($m[1], $in, $depth + 1)];
        }
        if (preg_match('/^array<string, (.+)>$/', $type, $m) === 1) {
            return ['k' => self::value($m[1], $in, $depth + 1)];
        }

        return match ($type) {
            'string' => 'x',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'mixed' => ['k' => 'v'],
            default => self::message($type, $in, $depth),
        };
    }

    private static function message(string $short, string $in, int $depth): object
    {
        $ns = substr($in, 0, (int) strrpos($in, '\\'));
        foreach ([$ns, 'Centrifugal\\Phpcent'] as $base) {
            $class = "{$base}\\{$short}";
            if (class_exists($class)) {
                return new $class(...self::args($class, $depth + 1));
            }
        }

        throw new \LogicException("no class {$short} for {$in}");
    }
}
