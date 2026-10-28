<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

/**
 * The claims of a connection token. See
 * https://centrifugal.dev/docs/server/authentication.
 */
final class ConnectionClaims
{
    /**
     * @param \DateTimeInterface|Expiry $expiresAt until when the token is
     *     accepted: the "exp" claim, or Expiry::Never for a token accepted
     *     forever - and forever once leaked, for a trusted service's own
     *     connection, say. Required. The connection expires then too, unless
     *     connectionExpiresAt says otherwise: Centrifugo asks the client for a
     *     new token, and closes the connection without one.
     * @param string $user the ID of the connection's user: the "sub" claim.
     *     Empty for an anonymous connection.
     * @param \DateTimeInterface|Expiry|null $connectionExpiresAt when set, when
     *     the connection expires instead of at expiresAt: the "expire_at"
     *     claim. Expiry::Never makes the connection never expire - Centrifugo
     *     never asks for a new token - while expiresAt still bounds when the
     *     token is accepted, on reconnects too. Most applications leave it
     *     unset.
     * @param ?\DateTimeInterface $issuedAt the "iat" claim; null for now
     * @param string $id the "jti" claim, for Centrifugo PRO's token revocation
     * @param string $audience the "aud" claim: client.token.audience
     * @param string $issuer the "iss" claim: client.token.issuer
     * @param mixed $info information about the connection: in presence, join and
     *     leave events, and the client's publications
     * @param string $b64info binary info in base64, instead of info
     * @param list<string> $channels server-side channels to subscribe the
     *     connection to
     * @param array<string, SubscribeOptions> $subs server-side channels to
     *     subscribe the connection to, each with its options. Set channels or
     *     subs, not both: Centrifugo ignores channels when subs is set.
     * @param mixed $meta custom data attached to the connection. Not visible to
     *     other clients - Centrifugo never sends it - but the token carries it,
     *     visible to the user owning the token: put nothing in it that user
     *     must not see.
     * @param list<ChannelsCapability> $caps capabilities granting the
     *     connection operations in channels. Centrifugo PRO only.
     * @param array<string, string> $labels the connection's client labels: for
     *     metrics, targeted server API calls and CEL expressions. Centrifugo
     *     PRO only.
     */
    public function __construct(
        public \DateTimeInterface|Expiry $expiresAt,
        public string $user = '',
        public \DateTimeInterface|Expiry|null $connectionExpiresAt = null,
        public ?\DateTimeInterface $issuedAt = null,
        public string $id = '',
        public string $audience = '',
        public string $issuer = '',
        public mixed $info = null,
        public string $b64info = '',
        public array $channels = [],
        public array $subs = [],
        public mixed $meta = null,
        public array $caps = [],
        public array $labels = [],
    ) {}
}
