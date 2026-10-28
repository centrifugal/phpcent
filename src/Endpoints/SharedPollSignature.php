<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;

/**
 * The endpoint a shared poll subscription's getSignature callback calls: a POST
 * of {"channel": ..., "keys": [...]}, answered {"signature": ..., "keys":
 * [...]}.
 *
 * $sign decides which of the keys the user may track in the channel, signs
 * them - with SharedPoll::sign, say - and returns them, in the order signed,
 * with the signature: [$allowed, $signature]. The keys it leaves out are
 * revoked: the client stops tracking them. To end the subscription instead,
 * it throws Unauthorized. It may return only keys it was asked for: a
 * signature covering another would be refused, and such a key fails the
 * request with 500.
 */
final class SharedPollSignature extends Endpoint
{
    /** @var \Closure(HttpRequest, string, list<string>): array{list<string>, string} */
    private readonly \Closure $sign;

    /**
     * @param callable(HttpRequest, string, list<string>): array{list<string>, string} $sign
     * @param ?callable(HttpRequest, \Throwable): void $onError
     */
    public function __construct(callable $sign, ?callable $onError = null)
    {
        parent::__construct($onError, self::MAX_BODY);
        $this->sign = $sign(...);
    }

    protected function begin(HttpRequest $req, string $body): HttpResponse|array
    {
        if ($req->method !== 'POST') {
            return HttpResponse::allow('POST');
        }
        $d = $this->decode($req, $body);
        if ($d instanceof HttpResponse) {
            return $d;
        }
        $channel = $d->channel ?? '';
        $keys = $d->keys ?? [];
        if (!\is_string($channel) || !\is_array($keys) || !array_is_list($keys) || array_filter($keys, static fn(mixed $k): bool => !\is_string($k)) !== []) {
            return $this->fail($req, 400, new \UnexpectedValueException('malformed request: channel and keys must be strings'));
        }
        if ($channel === '') {
            return $this->fail($req, 400, new \UnexpectedValueException('signature request without a channel'));
        }

        return [$channel, $keys];
    }

    protected function call(HttpRequest $req, array $args): mixed
    {
        [$channel, $keys] = $args;
        \assert(\is_string($channel) && \is_array($keys));

        /** @var list<string> $keys */
        return ($this->sign)($req, $channel, $keys);
    }

    protected function end(HttpRequest $req, array $args, mixed $out, ?\Throwable $error): HttpResponse
    {
        if ($error === null) {
            try {
                if (!\is_array($out) || \count($out) !== 2 || !\is_array($out[0] ?? null) || !\is_string($out[1] ?? null)) {
                    throw new \UnexpectedValueException('the function must return [$keys, $signature]');
                }
                $asked = [];
                foreach (\is_array($args[1]) ? $args[1] : [] as $k) {
                    $asked[\is_string($k) ? $k : ''] = true;
                }
                $allowed = [];
                foreach ($out[0] as $k) {
                    if (!\is_string($k)) {
                        throw new \UnexpectedValueException('the function must return [$keys, $signature]: keys are strings');
                    }
                    if (!isset($asked[$k])) {
                        throw new \UnexpectedValueException("signed key \"{$k}\" the client did not ask for");
                    }
                    $allowed[] = $k;
                }

                // The SDK reads the keys as an array, empty included.
                return $this->answer($req, ['signature' => $out[1], 'keys' => $allowed], null);
            } catch (\UnexpectedValueException $e) {
                $error = $e;
            }
        }

        return $this->answer($req, [], $error);
    }
}
