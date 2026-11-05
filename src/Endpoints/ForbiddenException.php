<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

/**
 * Thrown by the function of an endpoint when the user may not have a token or
 * signature: answered with 403, on which client SDKs stop asking, and
 * disconnect or unsubscribe. Client SDKs report it as their
 * UnauthorizedError.
 *
 * It is named apart from the API client's UnauthorizedException, which means
 * Centrifugo refused phpcent's own credentials, so that catching one where
 * the other is meant cannot happen silently.
 */
final class ForbiddenException extends \RuntimeException {}
