<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\Internal\Encode;

/**
 * Operations granted in channels: a connection may do an operation in a channel
 * when any of its capabilities allows the operation and matches the channel.
 * Centrifugo PRO only.
 */
final class ChannelsCapability
{
    /**
     * @param list<string> $channels the channels, or channel patterns, it applies to
     * @param list<string> $allow the operations: "sub" (subscribe), "pub"
     *     (publish), "hst" (history), "prs" (presence)
     * @param string $match how channels are matched: empty for exact names,
     *     "wildcard" or "regex"
     */
    public function __construct(
        public array $channels = [],
        public array $allow = [],
        public string $match = '',
    ) {}

    /** @internal */
    public function toJson(string $path = ''): string
    {
        $p = [];
        if ($this->channels !== []) {
            $p[] = '"channels":' . Encode::stringList($this->channels, $path . 'channels');
        }
        if ($this->allow !== []) {
            $p[] = '"allow":' . Encode::stringList($this->allow, $path . 'allow');
        }
        if ($this->match !== '') {
            $p[] = '"match":' . Encode::string($this->match, $path . 'match');
        }

        return '{' . implode(',', $p) . '}';
    }
}
