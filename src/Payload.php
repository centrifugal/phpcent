<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * Payloads as arrays. Replies give payloads - data, info, meta - as json_decode
 * gives them, objects as stdClass, so that one sent back unchanged is the same
 * JSON value. For code which reads them as arrays:
 *
 *     $data = Payload::toArray($pub->data);
 *     echo $data['text'];
 *
 * An array is for reading: sent back, an empty object or one with keys "0",
 * "1"... would become a JSON array. Send the payload itself back instead.
 */
final class Payload
{
    /**
     * $value with every object in it as an associative array, as
     * json_decode($json, true) would have given it. Numbers, strings, booleans
     * and null stay as they are.
     *
     * @return ($value is \stdClass|array<mixed> ? array<mixed> : mixed)
     */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if ($v instanceof \stdClass || \is_array($v)) {
                $value[$k] = self::toArray($v);
            }
        }

        return $value;
    }
}
