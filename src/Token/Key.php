<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\Exception\InvalidConfigException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;

/**
 * A key signing tokens, matching the one Centrifugo verifies with. Make one
 * with Key::hmac, Key::rsa, Key::ecdsa, Key::ed25519 or Key::fromPem.
 *
 * HMAC keys need nothing but PHP; RSA and ECDSA keys need ext-openssl,
 * Ed25519 keys ext-sodium.
 */
final class Key
{
    private const array EC_CURVES = [
        'prime256v1' => ['ES256', 'P-256', 32, \OPENSSL_ALGO_SHA256],
        'secp384r1' => ['ES384', 'P-384', 48, \OPENSSL_ALGO_SHA384],
        'secp521r1' => ['ES512', 'P-521', 66, \OPENSSL_ALGO_SHA512],
    ];

    // The DER prefix of an Ed25519 private key in PKCS #8, before its 32-byte seed.
    private const string ED25519_PKCS8_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    /**
     * @param string $alg the JWS algorithm: HS256, RS256, ES256, ES384, ES512 or EdDSA
     * @param \Closure(string): string $sign
     * @param ?array<string, string> $jwk the public key as a JSON Web Key, without
     *     kid, use and alg; null for HMAC
     * @param string $kid the key ID written as the tokens' "kid", if set
     */
    private function __construct(
        public readonly string $alg,
        private readonly \Closure $sign,
        private readonly ?array $jwk,
        public readonly string $kid = '',
    ) {}

    /**
     * The key, with $kid as the "kid" of the tokens it signs: the ID of its
     * public key in the JWKS Centrifugo verifies with. Never give the ID of a
     * key to another: Centrifugo caches keys by ID, and would refuse the other
     * key's tokens until its cache expires.
     */
    public function withKeyId(string $kid): self
    {
        if ($kid === '') {
            throw new InvalidRequestException('token: a key ID must not be empty');
        }

        return new self($this->alg, $this->sign, $this->jwk, $kid);
    }

    /** A key signing with HS256 and $secret: Centrifugo's client.token.hmac_secret_key. */
    public static function hmac(#[\SensitiveParameter] string $secret): self
    {
        if ($secret === '') {
            throw new InvalidRequestException('token: empty HMAC secret');
        }

        return new self('HS256', static fn(string $data): string => hash_hmac('sha256', $data, $secret, true), null);
    }

    /**
     * A key signing with RS256, whose public key is Centrifugo's
     * client.token.rsa_public_key: an OpenSSL key, or its PEM.
     */
    public static function rsa(#[\SensitiveParameter] \OpenSSLAsymmetricKey|string $privateKey, ?string $passphrase = null): self
    {
        $key = self::openssl($privateKey, $passphrase);
        $d = self::details($key);
        if (($d['type'] ?? null) !== \OPENSSL_KEYTYPE_RSA) {
            throw new InvalidRequestException('token: Key::rsa takes an RSA private key');
        }
        $jwk = ['kty' => 'RSA', 'n' => self::b64(ltrim(self::part($d, 'rsa', 'n'), "\0")), 'e' => self::b64(ltrim(self::part($d, 'rsa', 'e'), "\0"))];

        return new self('RS256', static function (string $data) use ($key): string {
            if (!openssl_sign($data, $sig, $key, \OPENSSL_ALGO_SHA256) || !\is_string($sig)) {
                throw new \RuntimeException('token: RSA signing failed: ' . openssl_error_string());
            }

            return $sig;
        }, $jwk);
    }

    /**
     * A key signing with ES256, ES384 or ES512 - for a P-256, P-384 or P-521
     * key - whose public key is Centrifugo's client.token.ecdsa_public_key: an
     * OpenSSL key, or its PEM.
     */
    public static function ecdsa(#[\SensitiveParameter] \OpenSSLAsymmetricKey|string $privateKey, ?string $passphrase = null): self
    {
        $key = self::openssl($privateKey, $passphrase);
        $d = self::details($key);
        if (($d['type'] ?? null) !== \OPENSSL_KEYTYPE_EC) {
            throw new InvalidRequestException('token: Key::ecdsa takes an EC private key');
        }
        $curve = self::part($d, 'ec', 'curve_name');
        if (!isset(self::EC_CURVES[$curve])) {
            throw new InvalidRequestException("token: ECDSA curve {$curve}, want P-256, P-384 or P-521");
        }
        [$alg, $crv, $size, $digest] = self::EC_CURVES[$curve];
        $jwk = [
            'kty' => 'EC',
            'crv' => $crv,
            'x' => self::b64(str_pad(self::part($d, 'ec', 'x'), $size, "\0", \STR_PAD_LEFT)),
            'y' => self::b64(str_pad(self::part($d, 'ec', 'y'), $size, "\0", \STR_PAD_LEFT)),
        ];

        return new self($alg, static function (string $data) use ($key, $size, $digest): string {
            if (!openssl_sign($data, $der, $key, $digest) || !\is_string($der)) {
                throw new \RuntimeException('token: ECDSA signing failed: ' . openssl_error_string());
            }

            // JWS wants r and s as fixed-size big-endian halves, not DER.
            return self::derToRaw($der, $size);
        }, $jwk);
    }

    /**
     * A key signing with EdDSA, whose public key is Centrifugo's
     * client.token.ed25519_public_key (Centrifugo v6.10.2+), or is published in
     * its JWKS: a 32-byte seed, or a 64-byte secret key of ext-sodium.
     */
    public static function ed25519(#[\SensitiveParameter] string $privateKey): self
    {
        if (!\function_exists('sodium_crypto_sign_detached')) {
            throw new InvalidConfigException('token: Ed25519 keys need ext-sodium');
        }
        $secret = match (\strlen($privateKey)) {
            SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($privateKey)),
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => $privateKey,
            default => throw new InvalidRequestException('token: an Ed25519 key is a 32-byte seed or a 64-byte secret key'),
        };
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);

        return new self(
            'EdDSA',
            static fn(string $data): string => sodium_crypto_sign_detached($data, $secret),
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => self::b64($public)],
        );
    }

    /**
     * The key of a PEM-encoded private key - RSA, ECDSA or Ed25519 - as
     * Key::rsa, Key::ecdsa or Key::ed25519 make it.
     */
    public static function fromPem(#[\SensitiveParameter] string $pem, ?string $passphrase = null): self
    {
        if ($passphrase === null && preg_match('/-----BEGIN PRIVATE KEY-----(.+?)-----END PRIVATE KEY-----/s', $pem, $m) === 1) {
            $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
            if ($der !== false && \strlen($der) === 48 && str_starts_with($der, self::ED25519_PKCS8_PREFIX)) {
                return self::ed25519(substr($der, 16));
            }
        }
        $key = self::openssl($pem, $passphrase);

        return match (self::details($key)['type'] ?? null) {
            \OPENSSL_KEYTYPE_RSA => self::rsa($key),
            \OPENSSL_KEYTYPE_EC => self::ecdsa($key),
            default => throw new InvalidRequestException('token: unsupported private key type: want RSA, ECDSA or Ed25519'),
        };
    }

    /** @internal */
    public function sign(string $data): string
    {
        return ($this->sign)($data);
    }

    /**
     * @internal
     *
     * @return ?array<string, string>
     */
    public function jwk(): ?array
    {
        return $this->jwk;
    }

    /** @internal */
    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function openssl(\OpenSSLAsymmetricKey|string $key, ?string $passphrase): \OpenSSLAsymmetricKey
    {
        if ($key instanceof \OpenSSLAsymmetricKey) {
            return $key;
        }
        if (!\function_exists('openssl_pkey_get_private')) {
            throw new InvalidConfigException('token: RSA and ECDSA keys need ext-openssl');
        }
        $k = openssl_pkey_get_private($key, $passphrase);
        if ($k === false) {
            throw new InvalidRequestException('token: not a PEM private key: ' . openssl_error_string());
        }

        return $k;
    }

    /** @return array<mixed> */
    private static function details(\OpenSSLAsymmetricKey $key): array
    {
        $d = openssl_pkey_get_details($key);
        if ($d === false) {
            throw new InvalidRequestException('token: cannot read the key: ' . openssl_error_string());
        }

        return $d;
    }

    /**
     * A part of a key's details: details['rsa']['n'], say.
     *
     * @param array<mixed> $details
     */
    private static function part(array $details, string $kind, string $name): string
    {
        $v = \is_array($details[$kind] ?? null) ? ($details[$kind][$name] ?? null) : null;
        if (!\is_string($v)) {
            throw new InvalidRequestException("token: the key has no {$kind} {$name}");
        }

        return $v;
    }

    /** An ECDSA signature in DER - SEQUENCE { INTEGER r, INTEGER s } - as r || s. */
    private static function derToRaw(string $der, int $size): string
    {
        $pos = 0;
        $seq = self::derValue($der, $pos);
        $pos = 0;
        $r = ltrim(self::derValue($seq, $pos), "\0");
        $s = ltrim(self::derValue($seq, $pos), "\0");

        return str_pad($r, $size, "\0", \STR_PAD_LEFT) . str_pad($s, $size, "\0", \STR_PAD_LEFT);
    }

    /** The value of the DER element at $pos: a tag, a length - short or long form - and the value. */
    private static function derValue(string $der, int &$pos): string
    {
        $pos++;
        $len = \ord($der[$pos++]);
        if (($len & 0x80) !== 0) {
            $n = $len & 0x7F;
            $len = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = ($len << 8) | \ord($der[$pos++]);
            }
        }
        $out = substr($der, $pos, $len);
        $pos += $len;

        return $out;
    }
}
