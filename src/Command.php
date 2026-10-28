<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent;

use Centrifugal\Phpcent\Exception\InvalidRequestException;
use Centrifugal\Phpcent\Internal\Validate;

/**
 * An API call left for Centrifugo's async consumers to make: written to a
 * PostgreSQL outbox table, a Kafka topic, a Redis stream. See
 * https://centrifugal.dev/docs/server/consumers.
 *
 * Consumers take it as its method and payload apart - the outbox table's
 * method and payload columns, a message attribute - or as the JSON message
 * {"method": ..., "payload": ...} of toJson (Kafka, NATS JetStream).
 *
 * Within a partition a consumer keeps commands in order: a channel's commands
 * keep their order only when every writer derives their partition from the
 * channel the same way - the channel as a Kafka message key, say.
 */
final class Command
{
    /**
     * @param string $method the API method, such as "publish"
     * @param string $payload the request, as JSON text
     */
    private function __construct(
        public readonly string $method,
        public readonly string $payload,
    ) {}

    /**
     * The command making the API call $request. The request is checked as a
     * Client checks it before sending - an invalid one throws
     * InvalidRequestException here, rather than in Centrifugo, whose consumers
     * drop a command they cannot run - and encoded.
     *
     *     $cmd = Command::from(new PublishRequest(channel: 'news', data: ['text' => 'hi']));
     *     $pdo->prepare('INSERT INTO centrifugo_outbox (method, payload, partition) VALUES (?, ?, 0)')
     *         ->execute([$cmd->method, $cmd->payload]);
     *
     * @param Request<mixed> $request
     *
     * @throws InvalidRequestException
     */
    public static function from(Request $request): self
    {
        return new self($request::apiMethod(), Validate::encode($request));
    }

    /** The command as one JSON message: {"method": ..., "payload": ...}. */
    public function toJson(): string
    {
        return '{"method":' . json_encode($this->method, \JSON_THROW_ON_ERROR) . ',"payload":' . $this->payload . '}';
    }
}
