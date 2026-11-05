<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

/**
 * An expiry chosen to be never - always explicitly: for expiresAt, a token
 * accepted forever, and forever once leaked; for connectionExpiresAt and
 * subscriptionExpiresAt, a connection or subscription which never expires.
 */
enum Expiry
{
    case Never;
}
