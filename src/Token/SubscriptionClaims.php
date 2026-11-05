<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\FilterNode;
use Centrifugal\Phpcent\SubscribeOptionOverride;

/**
 * The claims of a subscription token. See
 * https://centrifugal.dev/docs/server/channel_token_auth.
 */
final class SubscriptionClaims
{
    /**
     * @param string $channel the channel the token allows to subscribe to. Required.
     * @param \DateTimeInterface|Expiry $expiresAt until when the token is
     *     accepted: the "exp" claim, or Expiry::Never. Required. The
     *     subscription expires then too, unless subscriptionExpiresAt says
     *     otherwise.
     * @param string $user the ID of the connection's user: the "sub" claim
     * @param \DateTimeInterface|Expiry|null $subscriptionExpiresAt when set, when
     *     the subscription expires instead of at expiresAt: the "expire_at"
     *     claim. Expiry::Never makes it never expire, while expiresAt still
     *     bounds when the token is accepted: with a short expiresAt, a token
     *     which subscribes once.
     * @param ?\DateTimeInterface $issuedAt the "iat" claim; null for now
     * @param string $id the "jti" claim, for Centrifugo PRO's token revocation
     * @param string $audience the "aud" claim
     * @param string $issuer the "iss" claim
     * @param mixed $info channel-specific information about the connection
     * @param string $b64info binary info in base64, instead of info
     * @param mixed $data sent to the client in the subscribe reply
     * @param string $b64data binary data in base64, instead of data
     * @param ?SubscribeOptionOverride $override channel options of the namespace
     *     overridden for this subscription
     * @param list<string> $allow capabilities in the channel besides
     *     subscribing: "pub", "hst", "prs". Centrifugo PRO only.
     * @param ?FilterNode $serverTagsFilter only publications whose tags match it
     *     are delivered. Centrifugo PRO only.
     */
    public function __construct(
        public string $channel,
        public \DateTimeInterface|Expiry $expiresAt,
        public string $user = '',
        public \DateTimeInterface|Expiry|null $subscriptionExpiresAt = null,
        public ?\DateTimeInterface $issuedAt = null,
        public string $id = '',
        public string $audience = '',
        public string $issuer = '',
        public mixed $info = null,
        public string $b64info = '',
        public mixed $data = null,
        public string $b64data = '',
        public ?SubscribeOptionOverride $override = null,
        public array $allow = [],
        public ?FilterNode $serverTagsFilter = null,
    ) {}
}
