<?php

declare(strict_types=1);

/*
 * Writes API.md, the API reference, from phpcent's docblocks:
 *
 *     php gen/api_docs.php            # write
 *     php gen/api_docs.php --check    # fail when API.md is stale
 *
 * Everything public but what is marked @internal: classes, their constructors,
 * the promoted properties documented by the constructor's @param, and public
 * methods. The generated request and result types are folded away.
 */

namespace Centrifugal\Phpcent\Gen;

require __DIR__ . '/../vendor/autoload.php';

const ROOT = __DIR__ . '/..';

/** @return list<class-string> every class under dir, from its file name */
function classes(string $dir, string $namespace): array
{
    $out = [];
    foreach (glob("{$dir}/*.php") ?: [] as $file) {
        $class = $namespace . '\\' . basename($file, '.php');
        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            $out[] = $class;
        }
    }
    sort($out);

    return $out;
}

/** The text of a docblock: tags dropped, 4-space indented blocks as PHP code. */
function text(string|false $doc): string
{
    if ($doc === false) {
        return '';
    }
    $lines = [];
    foreach (explode("\n", $doc) as $l) {
        $l = preg_replace('#^\s*/\*\*\s?|\s*\*/\s*$|^\s*\*\s?#', '', $l) ?? '';
        $lines[] = rtrim($l);
    }
    $out = [];
    $code = [];
    $flush = static function () use (&$out, &$code): void {
        if ($code !== []) {
            while ($code !== [] && end($code) === '') {
                array_pop($code);
            }
            $out[] = '';
            $out[] = '```php';
            foreach ($code as $c) {
                $out[] = $c === '' ? '' : substr($c, 4);
            }
            $out[] = '```';
            $out[] = '';
            $code = [];
        }
    };
    $inTags = false;
    foreach ($lines as $l) {
        if (str_starts_with($l, '@')) {
            $inTags = true;
        }
        if ($inTags) {
            continue;
        }
        if (str_starts_with($l, '    ') || ($code !== [] && $l === '')) {
            $code[] = $l;
            continue;
        }
        $flush();
        $out[] = $l;
    }
    $flush();

    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)) ?? '');
}

function internal(string|false $doc): bool
{
    return $doc !== false && preg_match('#^\s*(/\*\*|\*)\s*@internal\b#m', $doc) === 1;
}

/**
 * The @param tags of a docblock: name => [type, description].
 *
 * @return array<string, array{string, string}>
 */
function params(string|false $doc): array
{
    if ($doc === false) {
        return [];
    }
    $body = preg_replace('#^\s*/\*\*|\*/\s*$#', '', $doc) ?? '';
    $body = preg_replace('#^\s*\*\s?#m', '', $body) ?? '';
    $out = [];
    // A tag runs until the next one, its continuation lines indented.
    foreach (preg_split('/\n(?=@)/', $body) ?: [] as $tag) {
        if (preg_match('/^@param\s+(.+?)\s+\$(\w+)\b\s*(.*)$/s', trim($tag), $m) === 1) {
            $out[$m[2]] = [$m[1], trim(preg_replace('/\s+/', ' ', $m[3]) ?? '')];
        }
    }

    return $out;
}

function type(?\ReflectionType $t, string $self = ''): string
{
    if ($t === null) {
        return '';
    }
    $s = (string) $t;
    // PHP 8.5 prints self as the class's name, earlier versions as self.
    if ($self !== '') {
        $s = preg_replace('/\bself\b/', $self, $s) ?? $s;
    }

    return preg_replace('/Centrifugal\\\\Phpcent\\\\(\w+\\\\)*/', '', $s) ?? $s;
}

function signature(\ReflectionMethod $m, string $name): string
{
    $params = [];
    foreach ($m->getParameters() as $p) {
        $s = type($p->getType());
        $s = ($s !== '' ? "{$s} " : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $const = $p->isDefaultValueConstant() ? (string) $p->getDefaultValueConstantName() : '';
            // An internal constant is shown as its value.
            $default = $const !== '' && !str_contains($const, 'Internal') ? $const : var_export($p->getDefaultValue(), true);
            $default = preg_replace(['/^.*\\\\/', '/^array \(\s*\)$/', '/^NULL$/'], ['', '[]', 'null'], $default) ?? $default;
            $s .= " = {$default}";
        }
        $params[] = $s;
    }
    $ret = $m->hasReturnType() ? ': ' . type($m->getReturnType(), $m->getDeclaringClass()->getShortName()) : '';
    $prefix = $m->isStatic() ? 'static ' : '';
    $one = "{$prefix}{$name}(" . implode(', ', $params) . "){$ret}";
    if (\strlen($one) <= 100) {
        return $one;
    }

    return "{$prefix}{$name}(\n    " . implode(",\n    ", $params) . ",\n){$ret}";
}

function renderClass(string $class, string $level = '###'): string
{
    $r = new \ReflectionClass($class);
    $kind = $r->isInterface() ? 'interface ' : ($r->isEnum() ? 'enum ' : '');
    $parts = ["{$level} {$kind}`{$r->getShortName()}`"];
    $ctor = $r->getConstructor();
    if (!$r->isInterface() && !$r->isEnum() && $ctor !== null && $ctor->isPublic() && !internal($ctor->getDocComment())) {
        $parts[] = "```php\n" . signature($ctor, "new {$r->getShortName()}") . "\n```";
    }
    if (($t = text($r->getDocComment())) !== '') {
        $parts[] = $t;
    }
    if ($r->isEnum()) {
        $parts[] = implode("\n", array_map(static fn(\UnitEnum $c): string => "- `{$r->getShortName()}::{$c->name}`", $class::cases()));
    }
    if ($ctor !== null && $ctor->isPublic() && !internal($ctor->getDocComment())) {
        $rows = [];
        foreach (params($ctor->getDocComment()) as $name => [$t, $desc]) {
            $rows[] = "- `\${$name}`" . ($t !== '' ? " `{$t}`" : '') . ($desc !== '' ? " - {$desc}" : '');
        }
        if ($rows !== []) {
            $parts[] = implode("\n", $rows);
        }
    }
    $methods = [];
    foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
        $declaring = $m->getDeclaringClass()->getName();
        if ($m->isConstructor() || internal($m->getDocComment()) || !str_starts_with($declaring, 'Centrifugal\\Phpcent')) {
            continue;
        }
        if ($r->isEnum() && \in_array($m->getName(), ['cases', 'from', 'tryFrom'], true)) {
            continue;
        }
        if ($declaring !== $class) {
            // Inherited: documented with the class declaring it.
            continue;
        }
        $doc = $m->getDocComment();
        $sig = signature($m, $m->getName());
        // A generic return type - Pending<PublishResult> - says more than the native one.
        if ($doc !== false && preg_match('/@return\s+(\w+<[^>\s]+>)/', $doc, $ret) === 1) {
            $sig = preg_replace('/\): \w+$/', "): {$ret[1]}", $sig) ?? $sig;
        }
        $rows = [];
        foreach (params($doc) as $name => [$t, $desc]) {
            if ($desc !== '') {
                $rows[] = "- `\${$name}` - {$desc}";
            }
        }
        $methods[$m->getName()] = '#' . $level . ' `' . $sig . "`\n\n" . text($doc) . ($rows !== [] ? "\n\n" . implode("\n", $rows) : '');
    }
    ksort($methods);

    return implode("\n\n", [...$parts, ...array_map('rtrim', $methods)]);
}

function section(string $title, string $intro, array $classes): string
{
    $items = array_map(static fn(string $c): string => renderClass($c), $classes);

    return implode("\n\n", array_filter(["## {$title}", $intro, ...$items]));
}

function generated(string $title, array $classes): string
{
    $body = implode("\n\n", array_map(static fn(string $c): string => renderClass($c, '####'), $classes));
    $n = \count($classes);

    return "## {$title}\n\n<details>\n<summary>{$n} types</summary>\n\n{$body}\n\n</details>";
}

function isGenerated(string $class): bool
{
    $file = (new \ReflectionClass($class))->getFileName();

    return $file !== false && str_contains($file, '/generated/');
}

function documented(string $class): bool
{
    $r = new \ReflectionClass($class);

    return !internal($r->getDocComment()) && !isGenerated($class);
}

function generate(): string
{
    $root = classes(ROOT . '/src', 'Centrifugal\\Phpcent');
    $order = ['Client', 'Batch', 'Pending', 'BatchResult', 'FailedCommand', 'BroadcastResult', 'ChannelResult', 'RawJson', 'Payload', 'Message', 'Retry', 'BroadcastAttempt', 'RequestInfo', 'Command', 'SharedPoll'];
    $main = array_values(array_filter($root, documented(...)));
    usort($main, static function (string $a, string $b) use ($order): int {
        $ia = array_search(substr($a, (int) strrpos($a, '\\') + 1), $order, true);
        $ib = array_search(substr($b, (int) strrpos($b, '\\') + 1), $order, true);

        return [$ia === false ? 99 : $ia, $a] <=> [$ib === false ? 99 : $ib, $b];
    });
    $errors = array_values(array_filter(classes(ROOT . '/src/Exception', 'Centrifugal\\Phpcent\\Exception'), documented(...)));
    $tree = [];
    foreach ($errors as $e) {
        $depth = 0;
        for ($p = (new \ReflectionClass($e))->getParentClass(); $p !== false && str_starts_with($p->getName(), 'Centrifugal'); $p = $p->getParentClass()) {
            $depth++;
        }
        $parent = (new \ReflectionClass($e))->getParentClass();
        $tree[] = str_repeat('  ', $depth) . '- `' . (new \ReflectionClass($e))->getShortName() . '`' . ($depth === 0 && $parent !== false ? " (a `\\{$parent->getName()}`)" : '');
    }

    $out = [
        '# phpcent API reference',
        '<!-- Generated by gen/api_docs.php from the docblocks: `composer docs`. Do not edit. -->',
        'The public API of phpcent, from its docblocks. The [README](README.md) shows how to use it; the request and result types come from Centrifugo\'s protos, with their documentation.',
        section('Centrifugal\\Phpcent', 'The client, batches, results and helpers.', $main),
        section('Centrifugal\\Phpcent\\Exception', "Every exception implements `CentException`.\n\n" . implode("\n", $tree), $errors),
        section('Centrifugal\\Phpcent\\Token', '', array_values(array_filter(classes(ROOT . '/src/Token', 'Centrifugal\\Phpcent\\Token'), documented(...)))),
        section('Centrifugal\\Phpcent\\Http', '', array_values(array_filter(classes(ROOT . '/src/Http', 'Centrifugal\\Phpcent\\Http'), documented(...)))),
        section('Centrifugal\\Phpcent\\Endpoints', '', array_values(array_filter(classes(ROOT . '/src/Endpoints', 'Centrifugal\\Phpcent\\Endpoints'), documented(...)))),
        section('Centrifugal\\Phpcent\\Proxy', '', array_values(array_filter(classes(ROOT . '/src/Proxy', 'Centrifugal\\Phpcent\\Proxy'), documented(...)))),
        generated('Centrifugal\\Phpcent: request and result types', array_values(array_filter(classes(ROOT . '/generated', 'Centrifugal\\Phpcent'), static fn(string $c): bool => !internal((new \ReflectionClass($c))->getDocComment())))),
        generated('Centrifugal\\Phpcent\\Proxy: request and result types', classes(ROOT . '/generated/Proxy', 'Centrifugal\\Phpcent\\Proxy')),
    ];
    $text = implode("\n\n", $out);

    return rtrim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) . "\n";
}

$path = ROOT . '/API.md';
$text = generate();
if (\in_array('--check', $argv, true)) {
    if (!is_file($path) || file_get_contents($path) !== $text) {
        fwrite(STDERR, "API.md is stale: run `composer docs`\n");
        exit(1);
    }
    exit(0);
}
file_put_contents($path, $text);
