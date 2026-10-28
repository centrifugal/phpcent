<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Exception;

/**
 * The reply is not a Centrifugo API reply. The usual cause is an apiEndpoint
 * which does not point at Centrifugo's API, so that something else - a proxy,
 * a web page - answers instead.
 */
final class DecodeException extends \RuntimeException implements CentException {}
