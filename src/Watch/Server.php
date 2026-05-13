<?php

declare(strict_types=1);

namespace Djot\Watch;

use RuntimeException;

class Server
{
    /**
     * @var resource|null
     */
    private $process;

    /**
     * Start `php -S` with the given router. Picks the next free port if
     * `$port` is taken, scanning up to 10 above.
     *
     * @param string $host
     * @param int $port
     * @param string $routerPath
     * @param array<string, string> $env
     *
     * @throws \RuntimeException
     *
     * @return int the port actually used
     */
    public function start(string $host, int $port, string $routerPath, array $env): int
    {
        $actualPort = $this->pickPort($host, $port);
        $docroot = dirname($routerPath);
        $cmd = sprintf(
            'exec %s -S %s:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            $actualPort,
            escapeshellarg($docroot),
            escapeshellarg($routerPath),
        );

        // Discard php -S stdio. The dev server's request log otherwise fills
        // the pipe buffer (a few thousand requests) and the worker blocks on
        // write, making the server appear hung. We don't surface those logs
        // anywhere; if they're ever wanted, swap '/dev/null' for a log file.
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $envForProc = array_merge($_ENV, $env);
        $proc = proc_open($cmd, $descriptors, $pipes, null, $envForProc);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to start php -S');
        }
        $this->process = $proc;

        return $actualPort;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, defined('SIGTERM') ? SIGTERM : 15);
        }
        proc_close($this->process);
        $this->process = null;
    }

    private function pickPort(string $host, int $port): int
    {
        for ($p = $port; $p < $port + 10; $p++) {
            $sock = @stream_socket_server("tcp://{$host}:{$p}", $errno, $errstr);
            if ($sock !== false) {
                fclose($sock);

                return $p;
            }
        }

        throw new RuntimeException(sprintf('No free port found in range %d-%d', $port, $port + 9));
    }
}
