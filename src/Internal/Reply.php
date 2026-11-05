<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\CentException;
use Centrifugal\Phpcent\Exception\DecodeException;
use Centrifugal\Phpcent\Exception\HttpException;

/**
 * Reading Centrifugo's replies: results, errors, batches.
 *
 * @internal
 */
final class Reply
{
    /** Statuses Centrifugo's transport error mode maps errors to. */
    private const array TRANSPORT_MODE_STATUSES = [400, 404, 409, 416, 429, 500];

    /**
     * The result of a method's reply, or its ApiException.
     *
     * @return array{\stdClass, ?ApiException}
     */
    public static function result(string $body, string $method): array
    {
        $d = Decode::json($body, $method);
        if (!$d instanceof \stdClass) {
            throw new DecodeException("{$method}: the reply is not a JSON object");
        }
        if (isset($d->error)) {
            return [new \stdClass(), self::apiError($d->error, "{$method}: error", '')];
        }
        $result = $d->result ?? null;
        if ($result === null) {
            return [new \stdClass(), null];
        }
        if (!$result instanceof \stdClass) {
            throw new DecodeException("{$method}: result: expected an object");
        }

        return [$result, null];
    }

    /**
     * The replies of a batch of n commands.
     *
     * @return list<\stdClass>
     */
    public static function batch(string $body, int $n): array
    {
        $d = Decode::json($body, 'batch');
        if (!$d instanceof \stdClass) {
            throw new DecodeException('batch: the reply is not a JSON object');
        }
        if (isset($d->error)) {
            throw self::apiError($d->error, 'batch: error', '');
        }
        $replies = $d->replies ?? [];
        if (!\is_array($replies) || !array_is_list($replies)) {
            throw new DecodeException('batch: replies: expected an array');
        }
        foreach ($replies as $i => $r) {
            if (!$r instanceof \stdClass) {
                throw new DecodeException("batch: replies[{$i}]: expected an object");
            }
        }
        if (\count($replies) !== $n) {
            throw new DecodeException('batch: got ' . \count($replies) . " replies for {$n} commands");
        }

        /** @var list<\stdClass> $replies */
        return $replies;
    }

    /**
     * The error of a batch command's reply, if it has one. A PRO method's code
     * 104 hints that the server may be Centrifugo OSS.
     */
    public static function commandError(\stdClass $reply, int $i, string $method): ?ApiException
    {
        if (!isset($reply->error)) {
            return null;
        }
        $pro = (Methods::PRO[$method] ?? false) ? $method : '';

        return self::apiError($reply->error, "replies[{$i}].error", $pro, onlyNotFound: true);
    }

    public static function apiError(mixed $v, string $path, string $proMethod, bool $onlyNotFound = false): ApiException
    {
        $d = Decode::object($v, $path);
        $code = Decode::int($d, 'code', $path);
        if ($onlyNotFound && $code !== 104) {
            $proMethod = '';
        }

        return ApiException::fromCode(
            $code,
            Decode::string($d, 'message', $path),
            Decode::bool($d, 'temporary', $path),
            $proMethod,
        );
    }

    /**
     * The error of a reply with another status than 200: a Centrifugo error in
     * the transport error mode, an HttpException otherwise.
     */
    public static function httpError(string $method, TransportResponse $resp): CentException
    {
        if (\in_array($resp->status, self::TRANSPORT_MODE_STATUSES, true)) {
            try {
                $d = json_decode($resp->body, false, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $d = null;
            }
            // A body whose code is an HTTP error status - {"code": 429} from a
            // rate limiting gateway - is a gateway's, not Centrifugo's.
            if ($d instanceof \stdClass && \is_int($d->code ?? null)) {
                $code = $d->code;
                if ($code >= 100 && ($code < 400 || $code >= 600)) {
                    try {
                        return self::apiError($d, 'error', '');
                    } catch (DecodeException) {
                        // Not Centrifugo's after all.
                    }
                }
            }
        }
        $pro = (Methods::PRO[$method] ?? false) ? $method : '';

        return HttpException::fromStatus($resp->status, trim(substr($resp->body, 0, Limits::ERROR_KEPT)), $resp->headers, $pro);
    }
}
