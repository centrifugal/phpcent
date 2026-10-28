<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Gen;

/**
 * A parser for the part of proto3 Centrifugo's api.proto and proxy.proto use.
 *
 * Top-level messages with scalar, message, repeated and map fields, and one
 * service whose methods may stream. Anything else - a oneof, an enum, a nested
 * message - fails the generation, rather than being dropped from the SDK
 * without a word.
 */
final class Proto
{
    public const array SCALARS = ['string', 'bool', 'int32', 'int64', 'uint32', 'uint64', 'double', 'float', 'bytes'];

    /** @var list<ProtoMessage> */
    public array $messages = [];

    /** @var array<string, ProtoMessage> */
    public array $byName = [];

    /** @var list<ProtoMethod> */
    public array $methods = [];

    public static function parse(string $text, string $name): self
    {
        $s = new self();
        $comment = [];
        $current = null;
        $inService = false;
        $lineNo = 0;
        $fail = static function (string $msg) use (&$lineNo, $name): \RuntimeException {
            return new \RuntimeException("{$name}:{$lineNo}: {$msg}");
        };

        foreach (explode("\n", $text) as $i => $raw) {
            $lineNo = $i + 1;
            $line = trim($raw);
            if ($line === '') {
                // A blank line ends a comment block: only a comment directly
                // above a declaration documents it.
                $comment = [];
                continue;
            }
            if (str_starts_with($line, '//')) {
                $comment[] = trim(substr($line, 2));
                continue;
            }
            $pos = strpos($line, '//');
            if ($pos !== false) {
                // A trailing comment documents its line when nothing above does.
                if ($comment === []) {
                    $comment = [trim(substr($line, $pos + 2))];
                }
                $line = trim(substr($line, 0, $pos));
            }

            if ($current === null && !$inService) {
                if (preg_match('/^(syntax|package|option)\b.*;$/', $line)) {
                    // Nothing to generate.
                } elseif (preg_match('/^message\s+(\w+)\s*\{\s*(\})?$/', $line, $m)) {
                    if (isset($s->byName[$m[1]])) {
                        throw $fail("message {$m[1]} declared twice");
                    }
                    $msg = new ProtoMessage($m[1], $comment);
                    $s->messages[] = $msg;
                    $s->byName[$msg->name] = $msg;
                    if (($m[2] ?? '') === '') {
                        $current = $msg;
                    }
                } elseif (preg_match('/^service\s+\w+\s*\{$/', $line)) {
                    $inService = true;
                } else {
                    throw $fail("unsupported top-level declaration: {$line}");
                }
            } elseif ($inService) {
                if ($line === '}') {
                    $inService = false;
                } elseif (preg_match('/^rpc\s+(\w+)\s*\(\s*(stream\s+)?(\w+)\s*\)\s*returns\s*\(\s*(stream\s+)?(\w+)\s*\)\s*(?:\{\s*\}|;)$/', $line, $m)) {
                    $s->methods[] = new ProtoMethod($m[1], $m[3], $m[5], $m[2] !== '' || $m[4] !== '');
                } else {
                    throw $fail("unsupported service declaration: {$line}");
                }
            } else {
                if ($line === '}') {
                    $current = null;
                } elseif (preg_match('/^reserved\s+[\d\s,to]+;$/', $line)) {
                    // Nothing to generate.
                } elseif (preg_match('/^map\s*<\s*(\w+)\s*,\s*(\w+)\s*>\s+(\w+)\s*=\s*\d+\s*;$/', $line, $m)) {
                    $current->fields[] = new ProtoField($m[3], $m[2], false, $m[1], $comment);
                } elseif (preg_match('/^(repeated\s+)?(\w+)\s+(\w+)\s*=\s*\d+\s*;$/', $line, $m)) {
                    $current->fields[] = new ProtoField($m[3], $m[2], $m[1] !== '', '', $comment);
                } else {
                    throw $fail("unsupported declaration in message {$current->name}: {$line}");
                }
            }
            $comment = [];
        }
        if ($current !== null || $inService) {
            throw new \RuntimeException("{$name}: unexpected end of file");
        }
        $s->check($name);

        return $s;
    }

    /** Every type a field or method refers to is declared. */
    private function check(string $name): void
    {
        foreach ($this->messages as $m) {
            foreach ($m->fields as $f) {
                if (!\in_array($f->type, self::SCALARS, true) && !isset($this->byName[$f->type])) {
                    throw new \RuntimeException("{$name}: {$m->name}.{$f->name} has unknown type {$f->type}");
                }
                if ($f->mapKey !== '' && $f->mapKey !== 'string') {
                    throw new \RuntimeException("{$name}: {$m->name}.{$f->name}: only string map keys are supported");
                }
            }
        }
        foreach ($this->methods as $rpc) {
            if (!isset($this->byName[$rpc->request], $this->byName[$rpc->response])) {
                throw new \RuntimeException("{$name}: rpc {$rpc->name} refers to an undeclared message");
            }
        }
    }
}

final class ProtoField
{
    /** @param list<string> $doc */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $repeated,
        public readonly string $mapKey,
        public readonly array $doc,
    ) {}
}

final class ProtoMessage
{
    /** @var list<ProtoField> */
    public array $fields = [];

    /** @param list<string> $doc */
    public function __construct(
        public readonly string $name,
        public readonly array $doc,
    ) {}
}

final class ProtoMethod
{
    public function __construct(
        public readonly string $name,
        public readonly string $request,
        public readonly string $response,
        public readonly bool $streaming,
    ) {}
}
