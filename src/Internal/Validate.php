<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Internal;

use Centrifugal\Phpcent\Exception\InvalidRequestException;

/**
 * The rules the client checks before sending: those which hold for every
 * Centrifugo. Everything else Centrifugo checks itself.
 *
 * @internal
 */
final class Validate
{
    /** null is "no data", as everywhere in phpcent: how to publish JSON null. */
    private const string NULL_HINT = " (to publish JSON null, pass data: new RawJson('null'))";

    /**
     * The request's JSON, or InvalidRequestException for a request the client
     * can tell is wrong.
     *
     * @param Request<mixed> $request
     */
    public static function encode(Request $request): string
    {
        $method = $request::apiMethod();
        if ($request instanceof PublishRequest) {
            if ($request->channel === '') {
                throw new InvalidRequestException('invalid publish request: channel is required');
            }
            if ($request->data === null && $request->b64data === '') {
                throw new InvalidRequestException('invalid publish request: data or b64data is required' . self::NULL_HINT);
            }
        } elseif ($request instanceof BroadcastRequest) {
            if ($request->channels === []) {
                throw new InvalidRequestException('invalid broadcast request: channels is required');
            }
            if ($request->data === null && $request->b64data === '') {
                throw new InvalidRequestException('invalid broadcast request: data or b64data is required' . self::NULL_HINT);
            }
            foreach ($request->channels as $i => $ch) {
                if ($ch === '') {
                    throw new InvalidRequestException("invalid broadcast request: channels[{$i}] is empty");
                }
            }
        }
        try {
            return $request->toJson();
        } catch (InvalidRequestException $e) {
            throw new InvalidRequestException("invalid {$method} request: {$e->getMessage()}", 0, $e->getPrevious());
        }
    }
}
