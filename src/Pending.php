<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\BatchNotSentException;
use Centrifugal\Phpcent\Exception\CentException;

/**
 * The outcome of one command of a batch, available once the batch is sent.
 *
 * @template T
 */
final class Pending
{
    /**
     * @param \Closure(): T $get
     *
     * @internal
     */
    public function __construct(private readonly \Closure $get) {}

    /**
     * A Pending of no batch, whose result() returns $result or throws $error:
     * for test doubles of code which reads a batch's outcomes.
     *
     * @template R
     *
     * @param R $result
     *
     * @return self<R>
     */
    public static function resolved(mixed $result = null, ?\Throwable $error = null): self
    {
        return new self(static function () use ($result, $error): mixed {
            if ($error !== null) {
                throw $error;
            }

            return $result;
        });
    }

    /**
     * The command's result, or its error thrown: an ApiException for this
     * command, the error which failed the whole batch, or
     * BatchNotSentException. A broadcast throws
     * BroadcastFailedChannelsException, carrying every channel's outcome, when
     * any channel failed.
     *
     * @return T
     *
     * @throws CentException
     * @throws BatchNotSentException
     */
    public function result(): mixed
    {
        return ($this->get)();
    }
}
