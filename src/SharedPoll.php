<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Token\Expiry;

/**
 * Signatures for shared poll subscriptions.
 *
 * See https://centrifugal.dev/docs/server/shared_poll. Shared poll
 * subscriptions are experimental in Centrifugo, and this class follows their
 * changes.
 */
final class SharedPoll
{
    /**
     * The signature authorizing $user to track $keys in $channel until
     * $expiresAt, as Centrifugo verifies it: "iat:exp:hmac", iat now and exp
     * $expiresAt in Unix seconds. $expiresAt is required, as a token's expiry
     * is: a time, or Expiry::Never for a signature which never expires.
     * $secret is Centrifugo's
     * shared_poll.hmac_secret_key; $user is the ID of the connection's user,
     * empty for an anonymous one.
     *
     * The signature covers exactly $keys, in their order: a client tracking
     * other keys, or the same in another order, is refused. Name the
     * arguments, so that a user and a channel cannot be swapped:
     *
     *     SharedPoll::sign($secret, user: '42', channel: 'poll:prices', keys: ['AAPL'], expiresAt: $exp);
     *
     * @param list<string> $keys
     *
     * @throws InvalidRequestException for an empty secret
     */
    public static function sign(#[\SensitiveParameter] string $secret, string $user, string $channel, array $keys, \DateTimeInterface|Expiry $expiresAt): string
    {
        return self::signAt($secret, $user, $channel, $keys, $expiresAt, time());
    }

    /**
     * sign, at the Unix time $now: for the spec's test vectors.
     *
     * @internal
     *
     * @param list<string> $keys
     */
    public static function signAt(#[\SensitiveParameter] string $secret, string $user, string $channel, array $keys, \DateTimeInterface|Expiry $expiresAt, int $now): string
    {
        if ($secret === '') {
            throw new InvalidRequestException('sharedpoll: empty secret');
        }
        $exp = $expiresAt instanceof \DateTimeInterface ? (string) $expiresAt->getTimestamp() : '0';
        $iat = (string) $now;
        $keysHash = hash('sha256', implode("\0", $keys));
        // NUL-separated, so that a colon in a user or channel cannot move
        // between fields: ("alice", "news:tech") and ("alice:news", "tech")
        // sign differently.
        $mac = hash_hmac('sha256', implode("\0", [$iat, $exp, $user, $channel, $keysHash]), $secret);

        return "{$iat}:{$exp}:{$mac}";
    }
}
