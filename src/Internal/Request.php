<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Message;

/**
 * A request of the Centrifugo server API, such as PublishRequest, which the
 * methods of Client, Batch and Command build from their named arguments. Each
 * is generated from Centrifugo's api.proto.
 *
 * @template-covariant TResult the result of its method
 *
 * @internal
 */
interface Request extends Message
{
    /** The server API method the request is sent to, such as "publish". */
    public static function apiMethod(): string;

    /**
     * The result of the request's method, from its reply.
     *
     * @return TResult
     */
    public static function decodeResult(mixed $v, string $path): mixed;
}
