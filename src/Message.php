<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * A message of Centrifugo's protocols - a request or result of the server API
 * or of the proxy - generated from its proto.
 */
interface Message
{
    /**
     * The message as Centrifugo reads it: JSON, fields at their zero value left
     * out. $path prefixes the field names in error messages.
     *
     * @throws Exception\InvalidRequestException when it cannot be encoded
     */
    public function toJson(string $path = ''): string;

    /**
     * The message from what json_decode gives for it.
     *
     * @internal
     *
     * @throws Exception\DecodeException
     */
    public static function fromWire(mixed $v, string $path): static;
}
