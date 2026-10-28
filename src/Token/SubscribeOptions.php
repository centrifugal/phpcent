<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Token;

use Centrifugal\Phpcent\FilterNode;
use Centrifugal\Phpcent\Internal\Encode;
use Centrifugal\Phpcent\SubscribeOptionOverride;

/** The options of a server-side subscription in ConnectionClaims::$subs. */
final class SubscribeOptions
{
    /**
     * @param mixed $info channel-specific information about the connection
     * @param string $b64info binary info in base64, instead of info
     * @param mixed $data sent to the client with the subscription
     * @param string $b64data binary data in base64, instead of data
     * @param ?SubscribeOptionOverride $override channel options of the
     *     namespace overridden for this subscription
     * @param ?FilterNode $serverTagsFilter only publications whose tags match it
     *     are delivered. Centrifugo PRO only, which refuses a malformed filter:
     *     the subscription fails.
     */
    public function __construct(
        public mixed $info = null,
        public string $b64info = '',
        public mixed $data = null,
        public string $b64data = '',
        public ?SubscribeOptionOverride $override = null,
        public ?FilterNode $serverTagsFilter = null,
    ) {}

    /** @internal */
    public function toJson(string $path = ''): string
    {
        $p = [];
        if ($this->info !== null) {
            $p[] = '"info":' . Encode::payload($this->info, $path . 'info');
        }
        if ($this->b64info !== '') {
            $p[] = '"b64info":' . Encode::string($this->b64info, $path . 'b64info');
        }
        if ($this->data !== null) {
            $p[] = '"data":' . Encode::payload($this->data, $path . 'data');
        }
        if ($this->b64data !== '') {
            $p[] = '"b64data":' . Encode::string($this->b64data, $path . 'b64data');
        }
        if ($this->override !== null && ($o = $this->override->toJson($path . 'override.')) !== '{}') {
            $p[] = '"override":' . $o;
        }
        if ($this->serverTagsFilter !== null && ($f = $this->serverTagsFilter->toJson($path . 'server_tags_filter.')) !== '{}') {
            $p[] = '"server_tags_filter":' . $f;
        }

        return '{' . implode(',', $p) . '}';
    }
}
