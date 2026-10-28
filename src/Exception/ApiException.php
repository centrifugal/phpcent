<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * An error of Centrifugo itself: it understood the request and refused it, or
 * failed to carry it out.
 *
 * Centrifugo identifies an error by its code - getCode() - while its message
 * is for people and may change. Errors with a known code are thrown as the
 * subclass for it - catch UnknownChannelException - and any other code as
 * ApiException itself: Centrifugo adds codes from time to time, so match the
 * ones you handle and treat the rest by their class.
 */
class ApiException extends \RuntimeException implements CentException
{
    /** The code subclasses stand for. */
    public const int CODE = 0;

    private const array BY_CODE = [
        100 => InternalException::class,
        102 => UnknownChannelException::class,
        104 => NotFoundException::class,
        107 => BadRequestException::class,
        108 => NotAvailableException::class,
        111 => TooManyRequestsException::class,
        112 => UnrecoverablePositionException::class,
        113 => ConflictException::class,
    ];

    /**
     * @param int $code Centrifugo's error code
     * @param string $serverMessage Centrifugo's message, for people
     * @param bool $temporary set by Centrifugo when the same request may succeed
     *     if retried later - a broker being unavailable, a rate limit - not
     *     because of the request. It does not mean nothing was done: a
     *     publication may have happened, so retry it with the same
     *     idempotencyKey. Centrifugo versions before the field existed never
     *     set it; Retry::retryable takes that into account.
     * @param string $proMethod a Centrifugo PRO method which failed with code
     *     104 in a batch, for the hint in the message
     */
    final public function __construct(
        int $code,
        public readonly string $serverMessage = '',
        public readonly bool $temporary = false,
        string $proMethod = '',
    ) {
        $message = ($serverMessage !== '' ? $serverMessage : 'server error') . " (code {$code})";
        if ($proMethod !== '') {
            $message .= " ({$proMethod} is a Centrifugo PRO method: is the server Centrifugo OSS?)";
        }
        parent::__construct($message, $code);
    }

    /** The exception for code: the subclass of a known code, ApiException otherwise. */
    public static function fromCode(int $code, string $serverMessage = '', bool $temporary = false, string $proMethod = ''): self
    {
        $class = self::BY_CODE[$code] ?? self::class;

        return new $class($code, $serverMessage, $temporary, $proMethod);
    }
}
