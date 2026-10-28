<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\ApiException;
use Centrifugal\Phpcent\Exception\BatchFailedCommandsException;
use Centrifugal\Phpcent\Exception\BroadcastFailedChannelsException;
use Centrifugal\Phpcent\Exception\HttpException;
use Centrifugal\Phpcent\Exception\NetworkException;
use Centrifugal\Phpcent\Exception\TimeoutException;

/** Retry helpers: whether an error is worth a retry, and how long to wait. */
final class Retry
{
    /**
     * Whether a new attempt, with time left, may succeed after $error: the
     * error came from a temporary condition, not from the request.
     *
     * Retryable are an ApiException Centrifugo marked temporary, and code 100
     * from Centrifugo versions which did not mark errors yet; a network
     * failure; a timeout; an HttpException 429 or 5xx; a broadcast or batch
     * partial error with at least one retryable part.
     *
     * Not retryable are the request's own errors - an unknown channel, a bad
     * request, InvalidRequestException - and InvalidConfigException,
     * ClosedException, a DecodeException, rejected credentials and a refused
     * TLS certificate.
     *
     * A retryable error does not mean nothing was done: retry a publication
     * only with the same idempotencyKey. Retry a broadcast with
     * Client::broadcastWithRetry, or only its retryableChannels(): repeating
     * the call resends to the channels which already got it.
     */
    public static function retryable(?\Throwable $error): bool
    {
        return match (true) {
            $error === null => false,
            $error instanceof BroadcastFailedChannelsException => $error->retryableChannels() !== [],
            $error instanceof BatchFailedCommandsException => self::anyRetryable($error->failed),
            $error instanceof ApiException => $error->temporary || $error->getCode() === 100,
            $error instanceof HttpException => $error->statusCode === 429 || $error->statusCode >= 500,
            $error instanceof TimeoutException => true,
            $error instanceof NetworkException => !$error->certificate,
            default => false,
        };
    }

    /** @param list<FailedCommand> $failed */
    private static function anyRetryable(array $failed): bool
    {
        foreach ($failed as $f) {
            if (self::retryable($f->error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The wait, in seconds, before retrying after a failed attempt, attempt 1
     * being the first failure: $base after it, twice as long after each next
     * one, up to $limit. Each wait is a random time between half of it and all
     * of it, so that clients which failed together do not all retry at the
     * same moment. A negative base counts as zero, a limit below base as base,
     * and an attempt below 1 as 1.
     *
     * In a retry loop of your own - a publication retried with the same
     * idempotencyKey:
     *
     *     $backoff = Retry::exponentialBackoff(0.1, 2.0);
     *     for ($attempt = 1; ; $attempt++) {
     *         try {
     *             return $client->publish($request);
     *         } catch (CentException $e) {
     *             if (!Retry::retryable($e) || $attempt === 3) {
     *                 throw $e;
     *             }
     *         }
     *         usleep((int) ($backoff($attempt) * 1e6));
     *     }
     *
     * Retry a broadcast with Client::broadcastWithRetry instead: repeating it
     * resends to the channels which already got it.
     *
     * @return \Closure(int): float
     */
    public static function exponentialBackoff(float $base, float $limit): \Closure
    {
        $base = max($base, 0.0);
        $limit = max($limit, $base);

        return static function (int $attempt) use ($base, $limit): float {
            $d = $base;
            // A zero base never grows: nothing to double.
            for ($i = 1; $i < max($attempt, 1) && $d < $limit && $d > 0; $i++) {
                $d *= 2;
            }
            $d = min($d, $limit);

            // Spreads retries out; nothing depends on it being unpredictable.
            return $d / 2 + (mt_rand() / mt_getrandmax()) * ($d / 2);
        };
    }
}
