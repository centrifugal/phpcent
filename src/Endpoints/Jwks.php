<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

use Centrifugal\Phpcent\Http\Handler;
use Centrifugal\Phpcent\Http\HttpRequest;
use Centrifugal\Phpcent\Http\HttpResponse;
use Centrifugal\Phpcent\Token\Key;
use Centrifugal\Phpcent\Token\Token;

/**
 * Serves the JSON Web Key Set of keys - see Token::jwks - at the URL configured
 * as Centrifugo's client.token.jwks_public_endpoint, or Centrifugo PRO's
 * http_api.jwks.endpoint for API keys. It answers GET and HEAD, cacheable for
 * five minutes. A set Token::jwks refuses fails here, when the endpoint is
 * built.
 */
final class Jwks extends Handler
{
    private readonly string $body;

    public function __construct(Key ...$keys)
    {
        parent::__construct(null, 1 << 20);
        $this->body = Token::jwks(...$keys);
    }

    protected function begin(HttpRequest $req, string $body): HttpResponse
    {
        if ($req->method !== 'GET' && $req->method !== 'HEAD') {
            return HttpResponse::allow('GET, HEAD');
        }

        return HttpResponse::json($req->method === 'GET' ? $this->body : '', 'public, max-age=300');
    }

    protected function call(HttpRequest $req, array $args): mixed
    {
        throw new \LogicException('begin always answers');
    }

    protected function end(HttpRequest $req, array $args, mixed $out, ?\Throwable $error): HttpResponse
    {
        throw new \LogicException('begin always answers');
    }
}
