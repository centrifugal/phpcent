<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

use Centrifugal\Phpcent\Http\Handler;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;

/**
 * An endpoint client SDKs call. It answers a JSON object, never cached;
 * Unauthorized - also as a previous exception of another - with 403, on which
 * client SDKs stop asking, and disconnect or unsubscribe; any other exception
 * with 500, which they retry. A malformed request gets 400, a wrong method 405.
 * onError, when set, is told of every 500 and 400, with the exception.
 *
 * @internal
 */
abstract class Endpoint extends Handler
{
    /** Bodies are read up to 1 MiB. */
    public const int MAX_BODY = 1 << 20;

    /** @param array<string, mixed> $body */
    protected function answer(HttpRequest $req, array $body, ?\Throwable $error): HttpResponse
    {
        if ($error !== null) {
            for ($e = $error; $e !== null; $e = $e->getPrevious()) {
                if ($e instanceof Unauthorized) {
                    return HttpResponse::status(403);
                }
            }

            return $this->fail($req, 500, $error);
        }

        return HttpResponse::json(json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /** The request's JSON object, or the 400 answering it. */
    protected function decode(HttpRequest $req, string $body): \stdClass|HttpResponse
    {
        try {
            $d = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->fail($req, 400, new \UnexpectedValueException("malformed request: {$e->getMessage()}", 0, $e));
        }
        if (!$d instanceof \stdClass) {
            return $this->fail($req, 400, new \UnexpectedValueException('malformed request: not a JSON object'));
        }

        return $d;
    }

    protected static function token(mixed $out): string
    {
        if (!\is_string($out) || $out === '') {
            throw new \UnexpectedValueException('the function returned ' . get_debug_type($out) . ', not a token');
        }

        return $out;
    }
}
