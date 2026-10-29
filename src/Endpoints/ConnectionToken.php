<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;

/**
 * The endpoint a client's getToken callback calls: $issue returns the
 * connection token of the request's user - from Token::connection, say - or
 * throws Unauthorized. It takes GET and POST, the body ignored.
 *
 *     $endpoint = new ConnectionToken(function (HttpRequest $http) use ($key): string {
 *         $user = sessionUser($http->header('cookie')) ?? throw new Unauthorized();
 *         return Token::connection($key, new ConnectionClaims(
 *             user: $user,
 *             expiresAt: new DateTimeImmutable('+15 minutes'),
 *         ));
 *     });
 */
final class ConnectionToken extends Endpoint
{
    /** @var \Closure(HttpRequest): string */
    private readonly \Closure $issue;

    /**
     * @param callable(HttpRequest): string $issue
     * @param ?callable(HttpRequest, \Throwable): void $onError
     */
    public function __construct(callable $issue, ?callable $onError = null)
    {
        parent::__construct($onError, self::MAX_BODY);
        $this->issue = $issue(...);
    }

    protected function begin(HttpRequest $req, string $body): HttpResponse|array
    {
        return \in_array($req->method, ['GET', 'POST'], true) ? [] : HttpResponse::allow('GET, POST');
    }

    protected function call(HttpRequest $req, array $args): mixed
    {
        return ($this->issue)($req);
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
