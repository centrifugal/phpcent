<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A Handler as a PSR-15 request handler: see Handler::psr15.
 *
 * @internal
 */
final class Psr15Handler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Handler $handler,
        private readonly ResponseFactoryInterface&StreamFactoryInterface $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->handlePsr($request, $this->factory);
    }
}
