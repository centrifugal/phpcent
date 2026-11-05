<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * What apiEndpointFunc or bearerTokenFunc threw, failing the call before
 * anything was sent: the function's own exception is the previous one, and the
 * message names the function - "bearerTokenFunc: RuntimeException: ...".
 *
 * Retry::retryable judges it by the function's exception: retryable when that
 * is a phpcent exception which is - a TimeoutException, say - and not
 * otherwise.
 */
final class FunctionException extends \RuntimeException implements CentException
{
    use IdempotencyKey;

    /**
     * @param string $function the option holding the function:
     *     "apiEndpointFunc" or "bearerTokenFunc"
     */
    public function __construct(
        public readonly string $function,
        \Throwable $previous,
    ) {
        parent::__construct("{$function}: " . $previous::class . ': ' . $previous->getMessage(), 0, $previous);
    }
}
