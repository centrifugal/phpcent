<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\SharedPoll;
use Centrifugal\Phpcent\SubscribeOptionOverride;
use Centrifugal\Phpcent\Token\ApiClaims;
use Centrifugal\Phpcent\Token\ApiTokens;
use Centrifugal\Phpcent\Token\ChannelsCapability;
use Centrifugal\Phpcent\Token\ConnectionClaims;
use Centrifugal\Phpcent\Token\Expiry;
use Centrifugal\Phpcent\Token\Key;
use Centrifugal\Phpcent\Token\SubscribeOptions;
use Centrifugal\Phpcent\Token\SubscriptionClaims;
use Centrifugal\Phpcent\Token\Token;
use PHPUnit\Framework\TestCase;

/** Tokens and shared poll signatures: spec sections 11 and 13, and the vectors of 15.1. */
final class TokenTest extends TestCase
{
    private static function at(int $unix): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($unix);
    }

    /** @return array<string, mixed> */
    private static function claims(string $token): array
    {
        $parts = explode('.', $token);
        self::assertCount(3, $parts);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
        self::assertIsArray($claims);

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /** @return array<string, mixed> */
    private static function header(string $token): array
    {
        $h = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true) ?: '', true);
        self::assertIsArray($h);

        /** @var array<string, mixed> $h */
        return $h;
    }

    public function testVectors(): void
    {
        $key = Key::hmac('client_secret');
        self::assertSame(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI0MiIsImV4cCI6MTcwMDAwMzYwMCwiaWF0IjoxNzAwMDAwMDAwfQ.HKZwmcYmHVbRESuYd4swVgAH7gg41giR57F_K5FEmEY',
            Token::connection($key, new ConnectionClaims(user: '42', expiresAt: self::at(1700003600), issuedAt: self::at(1700000000))),
        );
        self::assertSame(
            'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI0MiIsImV4cCI6MTcwMDAwMzYwMCwiaWF0IjoxNzAwMDAwMDAwLCJjaGFubmVsIjoibmV3cyJ9.v_Quw-azNV1qFjm9ZQnkp6QBtK6LmtFxY74YcUHlJcA',
            Token::subscription($key, new SubscriptionClaims(channel: 'news', user: '42', expiresAt: self::at(1700003600), issuedAt: self::at(1700000000))),
        );
        self::assertSame(
            '1700000000:1700003600:c61dbf52085a6973537e71644fa30a3bbfc29a52d7ce9f1b7618531596ce587d',
            SharedPoll::signAt('shared_poll_secret', '42', 'poll:prices', ['AAPL', 'GOOG'], self::at(1700003600), 1700000000),
        );
        self::assertSame(
            '1700000000:0:92dd64af5612afde06cad03bb63435d951e037cb55a087af05c2a1399abade0e',
            SharedPoll::signAt('shared_poll_secret', '', 'poll:prices', [], Expiry::Never, 1700000000),
        );
    }

    public function testExpiry(): void
    {
        $key = Key::hmac('s');
        $never = self::claims(Token::connection($key, new ConnectionClaims(expiresAt: Expiry::Never)));
        self::assertArrayNotHasKey('exp', $never);
        self::assertArrayNotHasKey('expire_at', $never);
        self::assertSame('', $never['sub']);
        $c = self::claims(Token::connection($key, new ConnectionClaims(expiresAt: self::at(2000000000), connectionExpiresAt: Expiry::Never)));
        self::assertSame(2000000000, $c['exp']);
        self::assertSame(0, $c['expire_at']);
        $s = self::claims(Token::subscription($key, new SubscriptionClaims(channel: 'c', expiresAt: self::at(2000000000), subscriptionExpiresAt: self::at(2000000100))));
        self::assertSame(2000000100, $s['expire_at']);
        self::assertEqualsWithDelta(time(), $s['iat'], 5);
    }

    public function testAllClaims(): void
    {
        $key = Key::hmac('s');
        $c = self::claims(Token::connection($key, new ConnectionClaims(
            expiresAt: self::at(2000000000),
            user: 'u',
            id: 'j',
            audience: 'a',
            issuer: 'i',
            info: ['name' => 'Alice'],
            subs: ['ch' => new SubscribeOptions(data: new \stdClass(), override: new SubscribeOptionOverride(presence: true))],
            meta: ['plan' => 'pro'],
            caps: [new ChannelsCapability(channels: ['news'], allow: ['sub'])],
            labels: ['region' => 'eu'],
        )));
        self::assertSame(['sub', 'exp', 'iat', 'jti', 'aud', 'iss', 'info', 'subs', 'meta', 'caps', 'labels'], array_keys($c));
        self::assertSame(['ch' => ['data' => [], 'override' => ['presence' => ['value' => true]]]], $c['subs']);
        $s = self::claims(Token::subscription($key, new SubscriptionClaims(channel: 'c', expiresAt: self::at(2000000000), data: [1], allow: ['pub'], b64info: 'AA==')));
        self::assertSame(['sub', 'exp', 'iat', 'channel', 'b64info', 'data', 'allow'], array_keys($s));
    }

    public function testRefused(): void
    {
        $key = Key::hmac('s');
        foreach ([
            static fn(): string => Token::connection($key, new ConnectionClaims(expiresAt: Expiry::Never, channels: ['a'], subs: ['b' => new SubscribeOptions()])),
            static fn(): string => Token::subscription($key, new SubscriptionClaims(channel: '', expiresAt: Expiry::Never)),
            static fn(): Key => Key::hmac(''),
            static fn(): Key => Key::ed25519('short'),
            static fn(): Key => $key->withKeyId(''),
        ] as $i => $f) {
            try {
                $f();
                self::fail("case {$i} accepted");
            } catch (\InvalidArgumentException) {
            }
        }
        // A payload which is not JSON fails at signing.
        $this->expectException(InvalidRequestException::class);
        Token::connection($key, new ConnectionClaims(expiresAt: Expiry::Never, info: \NAN));
    }

    public function testAsymmetricKeys(): void
    {
        $keys = [
            'RS256' => ['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            'ES256' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            'ES384' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'],
            'ES512' => ['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp521r1'],
        ];
        foreach ($keys as $alg => $opts) {
            $pk = openssl_pkey_new($opts);
            self::assertNotFalse($pk);
            self::assertTrue(openssl_pkey_export($pk, $pem));
            \assert(\is_string($pem));
            $key = Key::fromPem($pem)->withKeyId("k-{$alg}");
            self::assertSame($alg, $key->alg);
            $tok = Token::connection($key, new ConnectionClaims(user: '1', expiresAt: Expiry::Never));
            self::assertSame(['alg' => $alg, 'typ' => 'JWT', 'kid' => "k-{$alg}"], self::header($tok));
            [$h, $p, $s] = explode('.', $tok);
            $sig = base64_decode(strtr($s, '-_', '+/'), true);
            \assert(\is_string($sig));
            $details = openssl_pkey_get_details($pk);
            \assert(\is_array($details) && \is_string($details['key']));
            if ($alg !== 'RS256') {
                $sig = self::rawToDer($sig);
            }
            $digest = ['RS256' => \OPENSSL_ALGO_SHA256, 'ES256' => \OPENSSL_ALGO_SHA256, 'ES384' => \OPENSSL_ALGO_SHA384, 'ES512' => \OPENSSL_ALGO_SHA512][$alg];
            self::assertSame(1, openssl_verify("{$h}.{$p}", $sig, $details['key'], $digest), $alg);
        }
    }

    /** r || s, back to DER, for openssl_verify. */
    private static function rawToDer(string $raw): string
    {
        $int = static function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '' || (\ord($b[0]) & 0x80) !== 0) {
                $b = "\0" . $b;
            }

            return "\x02" . \chr(\strlen($b)) . $b;
        };
        $half = intdiv(\strlen($raw), 2);
        $body = $int(substr($raw, 0, $half)) . $int(substr($raw, $half));
        $len = \strlen($body) > 127 ? "\x81" . \chr(\strlen($body)) : \chr(\strlen($body));

        return "\x30" . $len . $body;
    }

    public function testOtherCurvesRefused(): void
    {
        $pk = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        self::assertNotFalse($pk);
        $this->expectExceptionMessage('want P-256, P-384 or P-521');
        Key::ecdsa($pk);
    }

    public function testEd25519(): void
    {
        $seed = str_repeat("\1", 32);
        $key = Key::ed25519($seed)->withKeyId('e');
        $tok = Token::connection($key, new ConnectionClaims(expiresAt: Expiry::Never));
        [$h, $p, $s] = explode('.', $tok);
        $pub = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
        self::assertTrue(sodium_crypto_sign_verify_detached((string) base64_decode(strtr($s, '-_', '+/') . '==', false), "{$h}.{$p}", $pub));
        // The same key from its PKCS #8 PEM and from a 64-byte secret key.
        $pem = "-----BEGIN PRIVATE KEY-----\n" . base64_encode("\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20" . $seed) . "\n-----END PRIVATE KEY-----\n";
        $jwks = Token::jwks($key);
        self::assertSame($jwks, Token::jwks(Key::fromPem($pem)->withKeyId('e')));
        self::assertSame($jwks, Token::jwks(Key::ed25519(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)))->withKeyId('e')));
        self::assertSame('{"keys":[{"kty":"OKP","kid":"e","use":"sig","alg":"EdDSA","crv":"Ed25519","x":"' . rtrim(strtr(base64_encode($pub), '+/', '-_'), '=') . '"}]}', $jwks);
    }

    public function testJwks(): void
    {
        $pk = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($pk);
        $ec = Key::ecdsa($pk)->withKeyId('ec');
        $set = json_decode(Token::jwks($ec, Key::ed25519(str_repeat("\2", 32))->withKeyId('ed')), true);
        self::assertIsArray($set);
        self::assertIsArray($set['keys']);
        self::assertSame(['kty', 'kid', 'use', 'alg', 'crv', 'x', 'y'], array_keys($set['keys'][0]));
        self::assertSame('sig', $set['keys'][0]['use']);
        self::assertSame(43, \strlen($set['keys'][0]['x'])); // 32 bytes, unpadded base64url
        foreach ([
            [],
            [Key::hmac('s')->withKeyId('h')],
            [Key::ed25519(str_repeat("\2", 32))],
            [$ec, Key::ed25519(str_repeat("\2", 32))->withKeyId('ec')],
        ] as $i => $keys) {
            try {
                Token::jwks(...$keys);
                self::fail("set {$i} accepted");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testApiTokens(): void
    {
        $key = Key::ed25519(str_repeat("\3", 32))->withKeyId('api');
        $claims = new ApiClaims(audience: 'centrifugo-api', scope: ['centrifugo:api', 'b']);
        $tokens = new ApiTokens(claims: $claims, key: $key);
        $t = $tokens->token();
        self::assertSame($t, $tokens->token());
        $c = self::claims($t);
        self::assertSame('centrifugo:api b', $c['scope']);
        self::assertSame('centrifugo-api', $c['aud']);
        self::assertIsInt($c['exp']);
        self::assertIsInt($c['iat']);
        self::assertSame(300, $c['exp'] - $c['iat']);

        // A short ttl: a new token once half of it passed.
        $short = new ApiTokens(claims: $claims, currentKey: static fn(): Key => $key, ttl: 2.0);
        $first = $short->token();
        usleep(1_100_000);
        self::assertNotSame($first, $short->token());

        $exp = self::at(time() + 60);
        foreach ([
            static fn(): string => Token::api(Key::hmac('s')->withKeyId('h'), new ApiClaims(audience: 'a', expiresAt: $exp)),
            static fn(): string => Token::api(Key::ed25519(str_repeat("\3", 32)), new ApiClaims(audience: 'a', expiresAt: $exp)),
            static fn(): string => Token::api($key, new ApiClaims(expiresAt: $exp)),
            static fn(): string => Token::api($key, new ApiClaims(audience: 'a')),
            static fn(): string => Token::api($key, new ApiClaims(scope: [''], expiresAt: $exp)),
            static fn(): string => Token::api($key, new ApiClaims(scope: ['a b'], expiresAt: $exp)),
            static fn(): ApiTokens => new ApiTokens(claims: new ApiClaims(audience: 'a', expiresAt: $exp), key: $key),
            static fn(): ApiTokens => new ApiTokens(claims: $claims),
            static fn(): ApiTokens => new ApiTokens(claims: $claims, key: $key, currentKey: static fn(): Key => $key),
            static fn(): ApiTokens => new ApiTokens(claims: $claims, key: $key, ttl: -1.0),
        ] as $i => $f) {
            try {
                $f();
                self::fail("case {$i} accepted");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testSharedPollSign(): void
    {
        $sig = SharedPoll::sign('s', user: 'u', channel: 'c', keys: ['a'], expiresAt: Expiry::Never);
        self::assertMatchesRegularExpression('/^\d+:0:[0-9a-f]{64}$/', $sig);
        self::assertNotSame(
            SharedPoll::signAt('s', 'alice', 'news:tech', [], Expiry::Never, 1),
            SharedPoll::signAt('s', 'alice:news', 'tech', [], Expiry::Never, 1),
        );
        // The expiry is chosen explicitly: never is spelled, a number refused.
        try {
            // @phpstan-ignore arguments.count
            SharedPoll::sign('s', user: 'u', channel: 'c', keys: []);
            self::fail('signed without an expiry');
        } catch (\ArgumentCountError) {
        }
        try {
            // @phpstan-ignore argument.type
            SharedPoll::sign('s', user: 'u', channel: 'c', keys: [], expiresAt: 3600);
            self::fail('signed with a number');
        } catch (\TypeError) {
        }
        $this->expectException(InvalidRequestException::class);
        SharedPoll::sign('', user: 'u', channel: 'c', keys: [], expiresAt: Expiry::Never);
    }
}
