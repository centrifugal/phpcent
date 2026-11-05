<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Tests\Support;

/**
 * PHP's built-in server running tests/Support/router.php, for what only a real
 * HTTP server shows: curl's timeouts, redirects, error bodies, and handlers
 * served from $_SERVER.
 */
final class TestServer
{
    /** @var resource */
    private $proc;

    public readonly string $base;

    /**
     * @param int $port 0 for a free one
     * @param array<string, string> $env for the router
     */
    public function __construct(string $router = __DIR__ . '/router.php', string $host = '127.0.0.1', int $port = 0, array $env = [])
    {
        if ($port === 0) {
            $sock = stream_socket_server('tcp://127.0.0.1:0');
            if ($sock === false) {
                throw new \RuntimeException('no free port');
            }
            $name = (string) stream_socket_get_name($sock, false);
            fclose($sock);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        }
        $this->base = "http://127.0.0.1:{$port}";
        // One process, no PHP_CLI_SERVER_WORKERS: stop() ends it, where workers
        // would outlive it and keep proc_close waiting.
        $proc = proc_open([\PHP_BINARY, '-S', "{$host}:{$port}", $router], [1 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pipes, null, $env === [] ? null : getenv() + $env);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('cannot start php -S');
        }
        $this->proc = $proc;
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException('php -S did not start');
    }

    public function stop(): void
    {
        proc_terminate($this->proc);
        proc_close($this->proc);
    }
}
