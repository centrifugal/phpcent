<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Endpoints;

/**
 * Thrown by the function of an endpoint when the user may not have a token or
 * signature: answered with 403, on which client SDKs stop asking. It is the
 * endpoints' own, named after client SDKs' UnauthorizedError - not the API
 * client's UnauthorizedException, which means Centrifugo refused the SDK's
 * credentials.
 */
final class Unauthorized extends \RuntimeException {}
