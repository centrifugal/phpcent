<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\DecodeException;

/**
 * Decoding replies, from the objects json_decode gives: each helper takes the
 * decoded JSON and a path naming it, for the DecodeException of a reply not of
 * the expected shape. Fields left out of a reply take their zero value; fields
 * the client does not know are ignored.
 *
 * @internal
 */
final class Decode
{
    /**
     * JSON text as PHP values, objects as stdClass: a payload in it goes back
     * out as the same JSON value - {} stays {}, 1.0 stays 1.0.
     */
    public static function json(string $text, string $what): mixed
    {
        try {
            return json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DecodeException("{$what}: the reply is not JSON: {$e->getMessage()}", 0, $e);
        }
    }

    public static function object(mixed $v, string $path): \stdClass
    {
        if (!$v instanceof \stdClass) {
            throw new DecodeException("{$path}: expected an object, got " . self::kind($v));
        }

        return $v;
    }

    public static function string(\stdClass $d, string $key, string $path): string
    {
        $v = $d->{$key} ?? null;

        return $v === null ? '' : self::asString($v, "{$path}.{$key}");
    }

    public static function bool(\stdClass $d, string $key, string $path): bool
    {
        $v = $d->{$key} ?? null;

        return $v === null ? false : self::asBool($v, "{$path}.{$key}");
    }

    public static function int(\stdClass $d, string $key, string $path): int
    {
        $v = $d->{$key} ?? null;

        return $v === null ? 0 : self::asInt($v, "{$path}.{$key}");
    }

    public static function float(\stdClass $d, string $key, string $path): float
    {
        $v = $d->{$key} ?? null;

        return $v === null ? 0.0 : self::asFloat($v, "{$path}.{$key}");
    }

    /** A payload: whatever JSON value it is, as json_decode gives it. */
    public static function payload(\stdClass $d, string $key): mixed
    {
        return $d->{$key} ?? null;
    }

    /** A BoolValue: null when absent, its value otherwise. */
    public static function boolValue(\stdClass $d, string $key, string $path): ?bool
    {
        $v = $d->{$key} ?? null;
        if ($v === null) {
            return null;
        }

        // The value's zero, which a protobuf encoder leaves out, is false.
        return self::bool(self::object($v, "{$path}.{$key}"), 'value', "{$path}.{$key}");
    }

    /** An Int32Value: null when absent, its value otherwise. */
    public static function int32Value(\stdClass $d, string $key, string $path): ?int
    {
        $v = $d->{$key} ?? null;
        if ($v === null) {
            return null;
        }

        return self::int(self::object($v, "{$path}.{$key}"), 'value', "{$path}.{$key}");
    }

    /**
     * @template T
     *
     * @param callable(mixed, string): T $conv
     *
     * @return T|null
     */
    public static function message(\stdClass $d, string $key, string $path, callable $conv): mixed
    {
        $v = $d->{$key} ?? null;

        return $v === null ? null : $conv($v, "{$path}.{$key}");
    }

    /**
     * @template T
     *
     * @param callable(mixed, string): T $conv
     *
     * @return list<T>
     */
    public static function listOf(\stdClass $d, string $key, string $path, callable $conv): array
    {
        $v = $d->{$key} ?? null;
        if ($v === null) {
            return [];
        }
        if (!\is_array($v)) {
            throw new DecodeException("{$path}.{$key}: expected an array, got " . self::kind($v));
        }
        $out = [];
        foreach ($v as $i => $item) {
            $out[] = $conv($item, "{$path}.{$key}[{$i}]");
        }

        return $out;
    }

    /**
     * @template T
     *
     * @param callable(mixed, string): T $conv
     *
     * @return array<string, T>
     */
    public static function mapOf(\stdClass $d, string $key, string $path, callable $conv): array
    {
        $v = $d->{$key} ?? null;
        if ($v === null) {
            return [];
        }
        $out = [];
        foreach ((array) self::object($v, "{$path}.{$key}") as $k => $item) {
            $out[(string) $k] = $conv($item, "{$path}.{$key}[{$k}]");
        }

        return $out;
    }

    public static function asString(mixed $v, string $path): string
    {
        if (!\is_string($v)) {
            throw new DecodeException("{$path}: expected a string, got " . self::kind($v));
        }

        return $v;
    }

    public static function asBool(mixed $v, string $path): bool
    {
        if (!\is_bool($v)) {
            throw new DecodeException("{$path}: expected a boolean, got " . self::kind($v));
        }

        return $v;
    }

    public static function asInt(mixed $v, string $path): int
    {
        if (!\is_int($v)) {
            throw new DecodeException("{$path}: expected an integer, got " . self::kind($v));
        }

        return $v;
    }

    public static function asFloat(mixed $v, string $path): float
    {
        if (!\is_int($v) && !\is_float($v)) {
            throw new DecodeException("{$path}: expected a number, got " . self::kind($v));
        }

        return (float) $v;
    }

    public static function asPayload(mixed $v, string $path): mixed
    {
        return $v;
    }

    public static function kind(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            \is_bool($v) => 'a boolean',
            \is_int($v), \is_float($v) => 'a number',
            \is_string($v) => 'a string',
            \is_array($v) => 'an array',
            $v instanceof \stdClass => 'an object',
            default => get_debug_type($v),
        };
    }
}
