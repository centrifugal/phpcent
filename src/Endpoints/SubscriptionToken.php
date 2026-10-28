<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;

/**
 * The endpoint a subscription's getToken callback calls: $issue returns the
 * token of the request's user for the channel - from Token::subscription,
 * say - or throws Unauthorized when the user may not subscribe. The channel
 * comes from a POST's JSON body, {"channel": ...}, or from a GET's channel
 * query parameter; it is required.
 */
final class SubscriptionToken extends Endpoint
{
    /** @var \Closure(HttpRequest, string): string */
    private readonly \Closure $issue;

    /**
     * @param callable(HttpRequest, string): string $issue
     * @param ?callable(HttpRequest, \Throwable): void $onError
     */
    public function __construct(callable $issue, ?callable $onError = null)
    {
        parent::__construct($onError, self::MAX_BODY);
        $this->issue = $issue(...);
    }

    protected function begin(HttpRequest $req, string $body): HttpResponse|array
    {
        if ($req->method === 'GET') {
            $channel = $req->query['channel'] ?? '';
        } elseif ($req->method === 'POST') {
            $d = $this->decode($req, $body);
            if ($d instanceof HttpResponse) {
                return $d;
            }
            $channel = $d->channel ?? '';
            if (!\is_string($channel)) {
                return $this->fail($req, 400, new \UnexpectedValueException('malformed request: channel is not a string'));
            }
        } else {
            return HttpResponse::allow('GET, POST');
        }
        if ($channel === '') {
            return $this->fail($req, 400, new \UnexpectedValueException('subscription token request without a channel'));
        }

        return [$channel];
    }

    protected function call(HttpRequest $req, array $args): mixed
    {
        \assert(\is_string($args[0]));

        return ($this->issue)($req, $args[0]);
    }

    protected function end(HttpRequest $req, array $args, mixed $out, ?\Throwable $error): HttpResponse
    {
        if ($error === null) {
            try {
                return $this->answer($req, ['token' => self::token($out)], null);
            } catch (\UnexpectedValueException $e) {
                $error = $e;
            }
        }

        return $this->answer($req, [], $error);
    }
}
