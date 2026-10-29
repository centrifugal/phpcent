<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\RawJson;

/**
 * Encoding requests: each field as JSON text, which the generated toJson
 * methods join. A payload is encoded on its own and spliced in as it is, so
 * that RawJson keeps its text and nothing else changes it.
 *
 * @internal
 */
final class Encode
{
    public const int FLAGS = \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    /**
     * RawJson values met inside payloads being encoded: RawJson::jsonSerialize
     * writes a placeholder for each, replaced by its text afterwards.
     *
     * @var list<array{string, list<string>}>
     */
    private static array $raw = [];

    public static function string(string $s, string $path): string
    {
        try {
            return json_encode($s, self::FLAGS);
        } catch (\JsonException $e) {
            throw new InvalidRequestException("{$path} is not valid UTF-8: send binary base64-encoded in a b64 field", 0, $e);
        }
    }

    public static function float(float $f, string $path): string
    {
        if (!is_finite($f)) {
            throw new InvalidRequestException("{$path} is {$f}, which is not a JSON number");
        }

        return json_encode($f, self::FLAGS);
    }

    /**
     * A payload as JSON text: RawJson as it is; anything else as json_encode
     * writes it, with NaN, infinities and strings which are not UTF-8 - binary
     * data - refused.
     */
    public static function payload(mixed $v, string $path): string
    {
        if ($v instanceof RawJson) {
            return $v->json;
        }
        $nonce = bin2hex(random_bytes(8));
        self::$raw[] = [$nonce, []];
        try {
            $text = self::encodeValue($v);
        } catch (\JsonException $e) {
            $why = match ($e->getCode()) {
                \JSON_ERROR_UTF8 => 'a string is not valid UTF-8: send binary base64-encoded in a b64 field, such as b64data',
                \JSON_ERROR_INF_OR_NAN => 'NaN and infinities are not JSON numbers',
                default => $e->getMessage(),
            };
            throw new InvalidRequestException("{$path} cannot be encoded: {$why}", 0, $e);
        } catch (InvalidRequestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A JsonSerializable which threw.
            throw new InvalidRequestException("{$path} cannot be encoded: {$e->getMessage()}", 0, $e);
        } finally {
            [, $texts] = array_pop(self::$raw);
        }
        foreach ($texts as $i => $raw) {
            $text = str_replace('"' . self::placeholder($nonce, $i) . '"', $raw, $text);
        }

        return $text;
    }

    /**
     * json_encode, which also throws what a JsonSerializable throws.
     *
     * @throws \JsonException
     * @throws \Throwable
     */
    private static function encodeValue(mixed $v): string
    {
        return json_encode($v, self::FLAGS);
    }

    /**
     * How an error in a result renders as JSON: {code, message} for an
     * ApiException, {message} for any other.
     *
     * @return array{code?: int, message: string}
     */
    public static function error(\Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return ['code' => $e->getCode(), 'message' => $e->serverMessage];
        }

        return ['message' => $e->getMessage()];
    }

    /**
     * The placeholder of a RawJson inside a payload being encoded, or null when
     * no payload is: RawJson::jsonSerialize's way to keep its text.
     *
     * @internal
     */
    public static function nestedRaw(RawJson $raw): ?string
    {
        $n = \count(self::$raw);
        if ($n === 0) {
            return null;
        }
        [$nonce, $texts] = self::$raw[$n - 1];
        $texts[] = $raw->json;
        self::$raw[$n - 1] = [$nonce, $texts];

        return self::placeholder($nonce, \count($texts) - 1);
    }

    private static function placeholder(string $nonce, int $i): string
    {
        // Letters and digits only, so that json_encode writes it unchanged; the
        // random nonce keeps any string of the payload from passing for one.
        return "centrifugo_raw_json_{$nonce}_{$i}";
    }

    /** @param array<mixed> $l */
    public static function stringList(array $l, string $path): string
    {
        self::list($l, $path);
        $out = [];
        foreach ($l as $i => $s) {
            if (!\is_string($s)) {
                throw new InvalidRequestException("{$path}[{$i}] must be a string, not " . get_debug_type($s));
            }
            $out[] = self::string($s, "{$path}[{$i}]");
        }

        return '[' . implode(',', $out) . ']';
    }

    /** @param array<mixed> $l */
    public static function intList(array $l, string $path): string
    {
        self::list($l, $path);
        foreach ($l as $i => $v) {
            if (!\is_int($v)) {
                throw new InvalidRequestException("{$path}[{$i}] must be an int, not " . get_debug_type($v));
            }
        }

        return '[' . implode(',', $l) . ']';
    }

    /** @param array<mixed> $l */
    public static function floatList(array $l, string $path): string
    {
        self::list($l, $path);
        $out = [];
        foreach ($l as $i => $v) {
            if (!\is_float($v) && !\is_int($v)) {
                throw new InvalidRequestException("{$path}[{$i}] must be a float, not " . get_debug_type($v));
            }
            $out[] = self::float((float) $v, "{$path}[{$i}]");
        }

        return '[' . implode(',', $out) . ']';
    }

    /** @param array<mixed> $l */
    public static function payloadList(array $l, string $path): string
    {
        self::list($l, $path);
        $out = [];
        foreach ($l as $i => $v) {
            $out[] = self::payload($v, "{$path}[{$i}]");
        }

        return '[' . implode(',', $out) . ']';
    }

    /** @param array<mixed> $l */
    public static function messageList(array $l, string $path): string
    {
        self::list($l, $path);
        $out = [];
        foreach ($l as $i => $m) {
            $out[] = self::message($m, "{$path}[{$i}]");
        }

        return '[' . implode(',', $out) . ']';
    }

    /** @param array<mixed> $m */
    public static function stringMap(array $m, string $path): string
    {
        $out = [];
        foreach ($m as $k => $v) {
            if (!\is_string($v)) {
                throw new InvalidRequestException("{$path}[{$k}] must be a string, not " . get_debug_type($v));
            }
            $out[] = self::key($k, $path) . ':' . self::string($v, "{$path}[{$k}]");
        }

        return '{' . implode(',', $out) . '}';
    }

    /** @param array<mixed> $m */
    public static function intMap(array $m, string $path): string
    {
        $out = [];
        foreach ($m as $k => $v) {
            if (!\is_int($v)) {
                throw new InvalidRequestException("{$path}[{$k}] must be an int, not " . get_debug_type($v));
            }
            $out[] = self::key($k, $path) . ':' . $v;
        }

        return '{' . implode(',', $out) . '}';
    }

    /** @param array<mixed> $m */
    public static function floatMap(array $m, string $path): string
    {
        $out = [];
        foreach ($m as $k => $v) {
            if (!\is_float($v) && !\is_int($v)) {
                throw new InvalidRequestException("{$path}[{$k}] must be a float, not " . get_debug_type($v));
            }
            $out[] = self::key($k, $path) . ':' . self::float((float) $v, "{$path}[{$k}]");
        }

        return '{' . implode(',', $out) . '}';
    }

    /** @param array<mixed> $m */
    public static function payloadMap(array $m, string $path): string
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[] = self::key($k, $path) . ':' . self::payload($v, "{$path}[{$k}]");
        }

        return '{' . implode(',', $out) . '}';
    }

    /** @param array<mixed> $m */
    public static function messageMap(array $m, string $path): string
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[] = self::key($k, $path) . ':' . self::message($v, "{$path}[{$k}]");
        }

        return '{' . implode(',', $out) . '}';
    }

    private static function message(mixed $m, string $path): string
    {
        if (!\is_object($m) || !method_exists($m, 'toJson')) {
            throw new InvalidRequestException("{$path} must be a message object, not " . get_debug_type($m));
        }
        $json = $m->toJson("{$path}.");
        \assert(\is_string($json));

        return $json;
    }

    /** @param array<mixed> $l */
    private static function list(array $l, string $path): void
    {
        if (!array_is_list($l)) {
            throw new InvalidRequestException("{$path} must be a list, not an array with keys");
        }
    }

    private static function key(int|string $k, string $path): string
    {
        // PHP turns numeric string keys into ints: a map key is a string again.
        return self::string((string) $k, "{$path} key");
    }
}
