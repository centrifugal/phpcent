<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Gen;

/**
 * What the generator reads in the protos' comments: the phrases of spec
 * section 2 and 3.3, and the constants' pinned names. Kept apart from generate.php for GeneratorTest.
 */
final class Rules
{
    /**
     * Whether the proto marks the field plainly "Required." - the word and
     * its period. "Required unless ..." and "Required when ..." are
     * conditional, and stay optional.
     */
    public static function required(ProtoField $f): bool
    {
        return preg_match('/\bRequired\./', implode(' ', $f->doc)) === 1;
    }

    /**
     * Whether a required field's comment gives its empty value a meaning
     * after "Required." - `"" clears it`, `An empty map clears it.` Such a
     * field is required in the signature but not checked before sending,
     * since empty is one of its values.
     */
    public static function emptyHasMeaning(ProtoField $f): bool
    {
        $doc = implode(' ', $f->doc);
        $at = strpos($doc, 'Required.');
        if ($at === false) {
            return false;
        }
        $after = substr($doc, $at + \strlen('Required.'));

        return str_contains($after, '""') || str_contains(strtolower($after), 'empty');
    }

    /**
     * Whether a message is a presence wrapper, which Centrifugo reads by
     * whether it is there: a message named *Value whose one field is value,
     * as BoolValue, or one whose only field's empty value has a meaning, as
     * device_update's DeviceUserUpdate. A field holding one is optional and
     * sent whenever set, even empty.
     */
    public static function isWrapper(?ProtoMessage $m): bool
    {
        if ($m === null || \count($m->fields) !== 1) {
            return false;
        }
        $f = $m->fields[0];
        if (str_ends_with($m->name, 'Value') && $f->name === 'value') {
            return true;
        }

        return self::required($f) && self::emptyHasMeaning($f);
    }

    /**
     * The values a field's comment lists in the sentence "One of: add,
     * remove, set.", or null without one: after "One of:", up to the period,
     * separated by commas, each lowercase letters, digits and underscores. A
     * malformed sentence fails the generation rather than being skipped.
     *
     * @param list<string> $doc
     *
     * @return ?list<string>
     */
    public static function listedValues(array $doc): ?array
    {
        $text = implode(' ', $doc);
        $at = strpos($text, 'One of:');
        if ($at === false) {
            return null;
        }
        $after = substr($text, $at + \strlen('One of:'));
        $end = strpos($after, '.');
        if ($end === false) {
            throw new \RuntimeException('the values after "One of:" must end with a period');
        }
        $values = [];
        foreach (explode(',', substr($after, 0, $end)) as $v) {
            $v = trim($v);
            if (preg_match('/^[a-z][a-z0-9_]*$/', $v) !== 1) {
                throw new \RuntimeException("\"{$v}\" is not a value: lowercase letters, digits and underscores");
            }
            if (\in_array($v, $values, true)) {
                throw new \RuntimeException("value \"{$v}\" listed twice");
            }
            $values[] = $v;
        }

        return $values;
    }

    /**
     * The constant classes of a proto, in its order: a class per name the
     * pins give the string fields with listed values - Provider, Op, KeyMode -
     * holding a constant per value: Provider::FCM, KeyMode::IF_NEW. The names
     * are the spec's table (section 3.3), held in gen/constants_*.txt as
     * "Message.field Name" or "*.field Name" lines: never derived, so that a
     * name never changes because an unrelated message appeared. The
     * generation fails on a field with listed values no pin names, a pin no
     * field uses, one name over different values, and a name PHP reserves.
     * Only the messages $include accepts count: those the namespace has
     * classes for.
     *
     * @param callable(string): bool $include
     *
     * @return list<ConstGroup>
     */
    public static function constGroups(Proto $s, callable $include, string $pins, string $name): array
    {
        $pinned = [];
        foreach (explode("\n", $pins) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^((?:\*|[A-Z]\w*)\.[a-z]\w*)\s+([A-Z]\w*)$/', $line, $m) !== 1) {
                throw new \RuntimeException("{$name}:" . ($i + 1) . ": not a pin, \"Message.field Name\" or \"*.field Name\": {$line}");
            }
            if (isset($pinned[$m[1]])) {
                throw new \RuntimeException("{$name}: {$m[1]} pinned twice");
            }
            if (self::reserved($m[2])) {
                throw new \RuntimeException("{$name}: {$m[2]} cannot name a PHP class");
            }
            $pinned[$m[1]] = $m[2];
        }
        $used = [];
        /** @var array<string, ConstGroup> $groups */
        $groups = [];
        foreach ($s->messages as $m) {
            if (!$include($m->name)) {
                continue;
            }
            foreach ($m->fields as $f) {
                try {
                    $values = self::listedValues($f->doc);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException("{$m->name}.{$f->name}: {$e->getMessage()}", 0, $e);
                }
                if ($values === null) {
                    continue;
                }
                $selector = isset($pinned["{$m->name}.{$f->name}"]) ? "{$m->name}.{$f->name}" : "*.{$f->name}";
                $class = $pinned[$selector] ?? throw new \RuntimeException("{$m->name}.{$f->name} lists its values, but {$name} gives it no name: name it there and in the spec's table of section 3.3");
                $used[$selector] = true;
                $g = $groups[$class] ??= new ConstGroup($class, $f->name, $values);
                if ($g->values !== $values) {
                    throw new \RuntimeException("{$name}: {$class} names " . implode(', ', $g->values) . " and, in {$m->name}.{$f->name}, " . implode(', ', $values));
                }
                $g->messages[] = $m->name;
            }
        }
        $unused = array_diff_key($pinned, $used);
        if ($unused !== []) {
            throw new \RuntimeException("{$name}: no field with listed values for " . implode(', ', array_keys($unused)));
        }

        return array_values($groups);
    }

    /** Whether PHP refuses the name for a class. */
    public static function reserved(string $class): bool
    {
        return \in_array(strtolower($class), [
            'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
            'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
            'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final', 'finally',
            'float', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof',
            'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'never', 'new',
            'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'resource',
            'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void',
            'while', 'xor', 'yield',
        ], true);
    }
}

/** The constants of a name the pins give, and the messages whose fields list them. */
final class ConstGroup
{
    /** @var list<string> */
    public array $messages = [];

    /** @param list<string> $values */
    public function __construct(
        public readonly string $name,
        public readonly string $field,
        public readonly array $values,
    ) {}

    /** The class holding the constants: Op, KeyMode, FilterNodeOp. */
    public function className(): string
    {
        return $this->name;
    }

    /** The constant of a value: IF_NEW for "if_new". */
    public static function constName(string $value): string
    {
        return strtoupper($value);
    }
}
