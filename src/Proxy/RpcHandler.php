<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Proxy;

/**
 * The RPC proxy: the function answers a client's RPC call. ProxyError fails the
 * call; Disconnect closes the connection.
 *
 * @extends ProxyHandler<RPCRequest, RPCResult>
 */
final class RpcHandler extends ProxyHandler
{
    protected function name(): string
    {
        return 'rpc';
    }

    protected function requestClass(): string
    {
        return RPCRequest::class;
    }

    protected function resultClass(): string
    {
        return RPCResult::class;
    }

    protected function answers(): int
    {
        return self::ERROR_DISCONNECT;
    }
}
