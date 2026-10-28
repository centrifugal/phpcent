<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

/**
 * The claims of a token authenticating calls to Centrifugo PRO's server API,
 * verified with the keys of http_api.jwks.endpoint. See
 * https://centrifugal.dev/docs/pro/server_api_enhancements.
 *
 * A token passing Centrifugo PRO's checks can call every API method, and it
 * checks only what it is configured to: the signature by any key of the JWKS
 * endpoint, and audience, issuer and scope only when http_api.jwks sets them.
 * So the token must carry a claim no other token carries - above all the
 * connection and subscription tokens users hold:
 *
 * - sign API tokens with keys of their own, published at their own JWKS
 *   endpoint - never the one of client.token.jwks_public_endpoint;
 * - set audience, scope or both, to values only API tokens carry, and
 *   configure http_api.jwks.audience and scope to require them. Token::api
 *   refuses claims with neither.
 */
final class ApiClaims
{
    /**
     * @param string $audience the "aud" claim: http_api.jwks.audience
     * @param list<string> $scope the "scope" claim, its scopes joined by spaces:
     *     http_api.jwks.scope is one of them
     * @param string $issuer the "iss" claim: http_api.jwks.issuer
     * @param string $subject the "sub" claim: who calls the API, for the
     *     application's own logs
     * @param ?\DateTimeInterface $expiresAt when the token expires: required by
     *     Token::api, best minutes ahead. Centrifugo PRO would accept a token
     *     without "exp" for as long as its key is published, so Token::api never
     *     signs one. ApiTokens sets it itself.
     * @param ?\DateTimeInterface $issuedAt the "iat" claim; null for now
     * @param string $id the "jti" claim
     */
    public function __construct(
        public string $audience = '',
        public array $scope = [],
        public string $issuer = '',
        public string $subject = '',
        public ?\DateTimeInterface $expiresAt = null,
        public ?\DateTimeInterface $issuedAt = null,
        public string $id = '',
    ) {}
}
