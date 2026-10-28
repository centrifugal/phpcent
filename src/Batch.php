<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\BatchFailedCommandsException;
use Centrifugal\Phpcent\Exception\BatchNotSentException;
use Centrifugal\Phpcent\Exception\BatchSentException;
use Centrifugal\Phpcent\Exception\CentException;
use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Internal\Reply;
use Centrifugal\Phpcent\Internal\Validate;

/**
 * Commands sent to Centrifugo in one request. Create one with
 * Client::newBatch, add requests - each add returns a Pending - then send:
 *
 *     $batch = $client->newBatch();
 *     $pub = $batch->add(new PublishRequest(channel: 'news', data: ['text' => 'hi']));
 *     $stats = $batch->add(new PresenceStatsRequest(channel: 'news'));
 *     $batch->send();               // throws unless every command succeeded
 *     $pub->result()->offset;
 *
 * A batch is sent once.
 */
final class Batch implements \Countable
{
    /** @var list<string> */
    private array $commands = [];

    /** @var list<string> */
    private array $methods = [];

    /** @var list<?InvalidRequestException> */
    private array $invalid = [];

    /** @var list<\Closure(\stdClass): mixed> */
    private array $decoders = [];

    /** @var list<\stdClass> */
    private array $replies = [];

    /** @var array<int, mixed> each command's result, or its error */
    private array $outcomes = [];

    private ?\Throwable $error = null;

    private bool $sent = false;

    /** @internal */
    public function __construct(
        private readonly Client $client,
        private readonly bool $parallel,
    ) {}

    /**
     * Adds a command; its Pending holds what the client's method for the
     * request would return - for a broadcast, every channel's outcome, and
     * BroadcastFailedChannelsException when any channel failed.
     *
     * @template T
     *
     * @param Request<T> $request
     *
     * @return Pending<T>
     */
    public function add(Request $request): Pending
    {
        if ($this->sent) {
            throw new \LogicException('phpcent: command added to a batch that was already sent');
        }
        $i = \count($this->commands);
        $method = $request::apiMethod();
        $invalid = null;
        $text = '';
        try {
            $text = '{"' . $method . '":' . Validate::encode($request) . '}';
        } catch (InvalidRequestException $e) {
            $invalid = $e;
        }
        if ($request instanceof BroadcastRequest) {
            $channels = array_values($request->channels);
            $key = $request->idempotencyKey;
            $decode = static fn(\stdClass $r): BroadcastResult => BroadcastResult::fromWire(
                $r->broadcast ?? new \stdClass(),
                "replies[{$i}].broadcast",
                $channels,
                $key,
            )->check();
        } else {
            $decode = static fn(\stdClass $r): mixed => $request::decodeResult($r->{$method} ?? new \stdClass(), "replies[{$i}].{$method}");
        }
        $this->commands[] = $text;
        $this->methods[] = $method;
        $this->invalid[] = $invalid;
        $this->decoders[] = $decode;

        /** @var Pending<T> */
        return new Pending(fn(): mixed => $this->outcome($i));
    }

    public function count(): int
    {
        return \count($this->commands);
    }

    /**
     * Sends the batch and waits for Centrifugo's reply to every command.
     *
     * It returns when every command succeeded. BatchFailedCommandsException
     * means one or more commands failed, up to all: every command got its own
     * reply, each Pending holds its outcome. Any other exception means the
     * batch failed as a whole: no command got a reply, and every Pending throws
     * that exception. A command the client can tell is invalid fails the whole
     * batch before anything is sent. An empty batch sends nothing.
     *
     * @param ?float $timeout seconds bounding the call: requestTimeout when null
     *
     * @throws CentException
     */
    public function send(?float $timeout = null): BatchResult
    {
        if ($this->sent) {
            throw new BatchSentException('batch already sent');
        }
        $this->sent = true;
        try {
            if ($this->commands === []) {
                $this->client->checkOpen();

                return new BatchResult(true);
            }
            foreach ($this->invalid as $i => $err) {
                if ($err !== null) {
                    throw new InvalidRequestException("batch command #{$i}: {$err->getMessage()}", 0, $err->getPrevious());
                }
            }
            $body = '{"commands":[' . implode(',', $this->commands) . ']' . ($this->parallel ? ',"parallel":true' : '') . '}';
            $this->replies = $this->client->sendBatch($body, \count($this->commands), $timeout);
        } catch (\Throwable $e) {
            $this->error = $e;

            throw $e;
        }
        $failed = [];
        foreach ($this->methods as $i => $method) {
            $this->resolve($i);
            if ($this->outcomes[$i] instanceof \Throwable) {
                $failed[] = new FailedCommand($i, $method, $this->outcomes[$i]);
            }
        }
        $res = new BatchResult(true, $failed);
        if ($failed !== []) {
            throw new BatchFailedCommandsException($failed, \count($this->commands), $res);
        }

        return $res;
    }

    private function outcome(int $i): mixed
    {
        if (!$this->sent) {
            throw new BatchNotSentException('batch not sent yet');
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        $this->resolve($i);
        $out = $this->outcomes[$i];
        if ($out instanceof \Throwable) {
            throw $out;
        }

        return $out;
    }

    /**
     * Decodes command i's outcome once, so that send's
     * BatchFailedCommandsException holds the very exception its Pending throws.
     */
    private function resolve(int $i): void
    {
        if (\array_key_exists($i, $this->outcomes)) {
            return;
        }
        $reply = $this->replies[$i];
        $err = Reply::commandError($reply, $i, $this->methods[$i]);
        if ($err !== null) {
            $this->outcomes[$i] = $err;

            return;
        }
        try {
            $this->outcomes[$i] = ($this->decoders[$i])($reply);
        } catch (CentException $e) {
            $this->outcomes[$i] = $e;
        }
    }
}
