<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Internal\Encode;

/**
 * Tokens: connection and subscription tokens for client SDKs, the JSON Web Key
 * Set Centrifugo verifies them with, and Centrifugo PRO's server API tokens.
 *
 *     $key = Key::hmac($secret);   // client.token.hmac_secret_key
 *     $token = Token::connection($key, new ConnectionClaims(
 *         user: '42',
 *         expiresAt: new DateTimeImmutable('+15 minutes'),
 *     ));
 *
 * See https://centrifugal.dev/docs/server/authentication.
 */
final class Token
{
    /**
     * A connection token with $claims, signed with $key.
     *
     * @throws InvalidRequestException when a payload claim is not valid JSON
     */
    public static function connection(Key $key, ConnectionClaims $claims): string
    {
        $c = $claims;
        if ($c->channels !== [] && $c->subs !== []) {
            throw new InvalidRequestException('token: set channels or subs, not both: Centrifugo ignores channels when subs is set');
        }
        $p = self::registered($c->user, $c->expiresAt, $c->issuedAt, $c->id, $c->audience, $c->issuer);
        if (($at = self::sessionExpiry($c->connectionExpiresAt)) !== null) {
            $p[] = '"expire_at":' . $at;
        }
        if ($c->info !== null) {
            $p[] = '"info":' . Encode::payload($c->info, 'info');
        }
        if ($c->b64info !== '') {
            $p[] = '"b64info":' . Encode::string($c->b64info, 'b64info');
        }
        if ($c->channels !== []) {
            $p[] = '"channels":' . Encode::stringList($c->channels, 'channels');
        }
        if ($c->subs !== []) {
            $subs = [];
            foreach ($c->subs as $ch => $opts) {
                if (!$opts instanceof SubscribeOptions) {
                    throw new InvalidRequestException("token: subs[{$ch}] must be a " . SubscribeOptions::class . ', not ' . get_debug_type($opts));
                }
                $subs[] = Encode::string((string) $ch, 'subs') . ':' . $opts->toJson("subs[{$ch}].");
            }
            $p[] = '"subs":{' . implode(',', $subs) . '}';
        }
        if ($c->meta !== null) {
            $p[] = '"meta":' . Encode::payload($c->meta, 'meta');
        }
        if ($c->caps !== []) {
            $p[] = '"caps":' . Encode::messageList($c->caps, 'caps');
        }
        if ($c->labels !== []) {
            $p[] = '"labels":' . Encode::stringMap($c->labels, 'labels');
        }

        return self::sign($key, $p);
    }

    /**
     * A subscription token with $claims, signed with $key.
     *
     * @throws InvalidRequestException when a payload claim is not valid JSON
     */
    public static function subscription(Key $key, SubscriptionClaims $claims): string
    {
        $c = $claims;
        if ($c->channel === '') {
            throw new InvalidRequestException('token: a subscription token needs a channel');
        }
        $p = self::registered($c->user, $c->expiresAt, $c->issuedAt, $c->id, $c->audience, $c->issuer);
        $p[] = '"channel":' . Encode::string($c->channel, 'channel');
        if (($at = self::sessionExpiry($c->subscriptionExpiresAt)) !== null) {
            $p[] = '"expire_at":' . $at;
        }
        if ($c->info !== null) {
            $p[] = '"info":' . Encode::payload($c->info, 'info');
        }
        if ($c->b64info !== '') {
            $p[] = '"b64info":' . Encode::string($c->b64info, 'b64info');
        }
        if ($c->data !== null) {
            $p[] = '"data":' . Encode::payload($c->data, 'data');
        }
        if ($c->b64data !== '') {
            $p[] = '"b64data":' . Encode::string($c->b64data, 'b64data');
        }
        if ($c->override !== null && ($o = $c->override->toJson('override.')) !== '{}') {
            $p[] = '"override":' . $o;
        }
        if ($c->allow !== []) {
            $p[] = '"allow":' . Encode::stringList($c->allow, 'allow');
        }
        if ($c->serverTagsFilter !== null && ($f = $c->serverTagsFilter->toJson('server_tags_filter.')) !== '{}') {
            $p[] = '"server_tags_filter":' . $f;
        }

        return self::sign($key, $p);
    }

    /**
     * A token for Centrifugo PRO's server API with $claims, signed with $key -
     * an asymmetric key with an ID, published by jwks: Centrifugo PRO finds it
     * by the "kid". See ApiClaims for keeping it apart from client tokens, and
     * ApiTokens for one to send with every call.
     */
    public static function api(Key $key, ApiClaims $claims): string
    {
        $c = $claims;
        if ($key->jwk() === null) {
            throw new InvalidRequestException(
                'token: an API token needs an RSA, ECDSA or Ed25519 key: Centrifugo PRO verifies it with the public keys of its JWKS endpoint',
            );
        }
        if ($key->kid === '') {
            throw new InvalidRequestException('token: an API token needs a key ID, by which Centrifugo PRO finds the key: use withKeyId');
        }
        if ($c->audience === '' && $c->scope === []) {
            throw new InvalidRequestException(
                'token: set audience or scope, required by http_api.jwks, so that client tokens signed with the same keys can\'t call the server API',
            );
        }
        if ($c->expiresAt === null) {
            throw new InvalidRequestException('token: set expiresAt: Centrifugo PRO accepts an API token without one while its key is published');
        }
        foreach ($c->scope as $s) {
            if (!\is_string($s) || $s === '' || preg_match('/\s/', $s) === 1) {
                throw new InvalidRequestException('token: a scope is empty or has a space');
            }
        }
        $p = self::registered($c->subject, $c->expiresAt, $c->issuedAt, $c->id, $c->audience, $c->issuer);
        if ($c->scope !== []) {
            $p[] = '"scope":' . Encode::string(implode(' ', $c->scope), 'scope');
        }

        return self::sign($key, $p);
    }

    /**
     * The public keys of $keys as a JSON Web Key Set, for Centrifugo's
     * client.token.jwks_public_endpoint, which verifies tokens by their "kid".
     * Each key needs an ID - see Key::withKeyId - unique in the set. HMAC keys
     * have no public key to publish, and are refused, as is a set without
     * keys.
     *
     * A set is how keys rotate without restarting Centrifugo: publish the new
     * key next to the old one; once every instance of the application
     * publishes it, sign with it; drop the old key once the tokens it signed
     * have expired. Never give a key ID to another key: Centrifugo caches keys
     * by ID.
     *
     * Publish the keys of client tokens and of Centrifugo PRO's server API
     * tokens at separate endpoints: a token signed with any key of a set passes
     * Centrifugo's signature check.
     */
    public static function jwks(Key ...$keys): string
    {
        if ($keys === []) {
            throw new InvalidRequestException('token: a JWKS without keys');
        }
        $out = [];
        $seen = [];
        foreach (array_values($keys) as $i => $k) {
            $jwk = $k->jwk();
            if ($jwk === null) {
                throw new InvalidRequestException("token: JWKS key #{$i} has no public key to publish: an HMAC key");
            }
            if ($k->kid === '') {
                throw new InvalidRequestException("token: JWKS key #{$i} has no key ID: set one with withKeyId");
            }
            if (isset($seen[$k->kid])) {
                throw new InvalidRequestException("token: JWKS key ID \"{$k->kid}\" used twice");
            }
            $seen[$k->kid] = true;
            $kty = $jwk['kty'];
            unset($jwk['kty']);
            $out[] = ['kty' => $kty, 'kid' => $k->kid, 'use' => 'sig', 'alg' => $k->alg] + $jwk;
        }

        return json_encode(['keys' => $out], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The RFC 7519 claims Centrifugo reads, in the order the spec's vectors use:
     * sub, exp, iat, jti, aud, iss.
     *
     * @return list<string>
     */
    private static function registered(string $user, \DateTimeInterface|Expiry $expiresAt, ?\DateTimeInterface $issuedAt, string $id, string $audience, string $issuer): array
    {
        $p = ['"sub":' . Encode::string($user, 'sub')];
        if ($expiresAt instanceof \DateTimeInterface) {
            $p[] = '"exp":' . $expiresAt->getTimestamp();
        }
        $p[] = '"iat":' . ($issuedAt?->getTimestamp() ?? time());
        if ($id !== '') {
            $p[] = '"jti":' . Encode::string($id, 'jti');
        }
        if ($audience !== '') {
            $p[] = '"aud":' . Encode::string($audience, 'aud');
        }
        if ($issuer !== '') {
            $p[] = '"iss":' . Encode::string($issuer, 'iss');
        }

        return $p;
    }

    /** The "expire_at" claim: left out (null), 0 for never, or a time. */
    private static function sessionExpiry(\DateTimeInterface|Expiry|null $at): ?int
    {
        return match (true) {
            $at === null => null,
            $at instanceof Expiry => 0,
            default => $at->getTimestamp(),
        };
    }

    /** @param list<string> $claims */
    private static function sign(Key $key, array $claims): string
    {
        $header = '{"alg":"' . $key->alg . '","typ":"JWT"' . ($key->kid !== '' ? ',"kid":' . Encode::string($key->kid, 'kid') : '') . '}';
        $signed = Key::b64($header) . '.' . Key::b64('{' . implode(',', $claims) . '}');

        return $signed . '.' . Key::b64($key->sign($signed));
    }
}
