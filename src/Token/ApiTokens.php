<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\Exception\InvalidRequestException;

/**
 * Issues the tokens a Client sends to Centrifugo PRO: its token method is the
 * client's bearerTokenFunc. It signs a token with Token::api and reuses it for
 * half its lifetime, so a token sent has at least half of it left.
 *
 *     $tokens = new ApiTokens(
 *         claims: new ApiClaims(audience: 'centrifugo-api', scope: ['centrifugo:api']),
 *         key: $apiKey,   // its own key, not the client tokens'
 *     );
 *     $client = new Client('https://centrifugo.example.com/api', bearerTokenFunc: $tokens->token(...));
 *
 * $currentKey, when set, returns the key to sign each new token with instead of
 * $key - the key active now, when keys rotate. A key must be published in the
 * JWKS before it signs: Centrifugo PRO refuses a token whose key it can't
 * find. $claims must leave expiresAt and issuedAt unset: the tokens are issued
 * when needed and expire $ttl seconds later (300 by default).
 */
final class ApiTokens
{
    /** @var ?\Closure(): Key */
    private readonly ?\Closure $currentKey;

    private string $token = '';

    private float $refreshAt = 0.0;

    /** @param ?callable(): Key $currentKey */
    public function __construct(
        private readonly ApiClaims $claims,
        private readonly ?Key $key = null,
        ?callable $currentKey = null,
        private readonly float $ttl = 300.0,
    ) {
        if (($key === null) === ($currentKey === null)) {
            throw new InvalidRequestException('token: ApiTokens takes key or currentKey, one of them');
        }
        if ($claims->expiresAt !== null || $claims->issuedAt !== null) {
            throw new InvalidRequestException('token: ApiTokens sets expiresAt and issuedAt itself: leave them unset, and set ttl');
        }
        if (!is_finite($ttl) || $ttl <= 0) {
            throw new InvalidRequestException('token: ApiTokens ttl must be a positive number of seconds');
        }
        $this->currentKey = $currentKey !== null ? $currentKey(...) : null;
    }

    /** A token valid for at least half of ttl, signing a new one when the previous one has less left. */
    public function token(): string
    {
        $now = microtime(true);
        if ($this->token !== '' && $now < $this->refreshAt) {
            return $this->token;
        }
        $key = $this->currentKey !== null ? ($this->currentKey)() : $this->key;
        \assert($key !== null);
        $c = clone $this->claims;
        $c->issuedAt = (new \DateTimeImmutable())->setTimestamp((int) $now);
        $c->expiresAt = (new \DateTimeImmutable())->setTimestamp((int) ($now + $this->ttl));
        $this->token = Token::api($key, $c);
        $this->refreshAt = $now + $this->ttl / 2;

        return $this->token;
    }
}
