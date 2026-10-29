<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * A 401: Centrifugo did not accept the credentials - the API key or the bearer
 * token.
 */
final class UnauthorizedException extends HttpException {}
