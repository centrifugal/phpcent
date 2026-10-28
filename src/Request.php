<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

/**
 * A request of the Centrifugo server API, such as PublishRequest: what the
 * Client's methods, Batch::add and Command::from take. Each is generated from
 * Centrifugo's api.proto.
 *
 * @template-covariant TResult the result of its method
 */
interface Request extends Message
{
    /** The server API method the request is sent to, such as "publish". */
    public static function apiMethod(): string;

    /**
     * The result of the request's method, from its reply.
     *
     * @internal
     *
     * @return TResult
     */
    public static function decodeResult(mixed $v, string $path): mixed;
}
