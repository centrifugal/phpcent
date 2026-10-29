<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Internal\Encode;

/**
 * JSON text, sent as it is: a payload already encoded - from a database, a
 * queue, a cache - or encoded with rules of your own. It is checked to be
 * valid JSON when created, and sent unchanged, number text kept.
 *
 *     $client->publish(channel: 'news', data: new RawJson('{"text":"hi"}'));
 *
 * It may also stand for a part of a payload - ['user' => 42, 'doc' => $raw] -
 * which is then sent as its text too. A string, by contrast, is always a JSON
 * string: 'hi' publishes "hi".
 */
final class RawJson implements \JsonSerializable, \Stringable
{
    /** The JSON text. */
    public readonly string $json;

    /** @throws InvalidRequestException when $json is not valid JSON */
    public function __construct(string $json)
    {
        if (!json_validate($json, 512)) {
            throw new InvalidRequestException('raw JSON is not valid JSON: ' . json_last_error_msg());
        }
        $this->json = $json;
    }

    /**
     * The JSON value as PHP values: objects as stdClass, or as associative
     * arrays with $associative.
     */
    public function decode(bool $associative = false): mixed
    {
        return json_decode($this->json, $associative, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Its text when inside a payload cent encodes; its value, decoded, for a
     * json_encode of your own.
     */
    public function jsonSerialize(): mixed
    {
        return Encode::nestedRaw($this) ?? $this->decode();
    }

    public function __toString(): string
    {
        return $this->json;
    }
}
