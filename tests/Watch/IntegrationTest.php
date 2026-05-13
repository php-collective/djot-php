<?php

declare(strict_types=1);

namespace Djot\Test\Watch;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for `bin/djot-watch`. Spawns the binary, hits the HTTP
 * server, modifies the watched file, and asserts an SSE reload event arrives.
 *
 * Skipped when pcntl is missing (the watcher needs SIGINT handling). The
 * test allocates a free port itself to avoid colliding with the default 8765.
 */
class IntegrationTest extends TestCase
{
    public function testWatcherServesAndReloadsOnFileChange(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $port = $this->findFreePort();
        $target = tempnam(sys_get_temp_dir(), 'djot_integ_') . '.djot';
        file_put_contents($target, "# Initial\n");

        $bin = realpath(__DIR__ . '/../../bin/djot-watch');
        self::assertNotFalse($bin);
        $cmd = sprintf(
            '%s %s --port %d --no-open %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bin),
            $port,
            escapeshellarg($target),
        );

        // Redirect child stdio to /dev/null so its periodic stdout writes
        // (e.g. "reload (N)") don't block on a full pipe buffer.
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);

        try {
            $url = "http://127.0.0.1:{$port}/";
            self::assertTrue($this->waitForServer($url), "Server did not respond at {$url}");

            // Trigger a change: write different-length content and advance mtime.
            touch($target, time() + 2);
            file_put_contents($target, "# Updated content here\n");
            clearstatcache(true, $target);

            self::assertTrue(
                $this->waitForReload("http://127.0.0.1:{$port}/__sse", 5),
                'No SSE reload event received within 5 seconds',
            );
        } finally {
            proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
            // Give the watcher a moment to flush its finally block before reaping.
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }
            proc_close($proc);
            @unlink($target);
        }
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock);
        $name = stream_socket_get_name($sock, false);
        self::assertNotFalse($name);
        fclose($sock);
        $portStr = substr($name, (int)strrpos($name, ':') + 1);

        return (int)$portStr;
    }

    private function waitForServer(string $url): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000);
            $body = @file_get_contents($url, false, $ctx);
            if (is_string($body) && str_contains($body, '<h1')) {
                return true;
            }
        }

        return false;
    }

    private function waitForReload(string $sseUrl, int $timeoutSeconds): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeoutSeconds]]);
        $stream = @fopen($sseUrl, 'r', false, $ctx);
        if (!is_resource($stream)) {
            return false;
        }
        stream_set_timeout($stream, $timeoutSeconds);

        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        try {
            while (microtime(true) < $deadline) {
                $chunk = fread($stream, 256);
                if ($chunk === false || $chunk === '') {
                    $info = stream_get_meta_data($stream);
                    if ($info['timed_out']) {
                        return false;
                    }
                    usleep(50_000);

                    continue;
                }
                $buffer .= $chunk;
                if (str_contains($buffer, 'event: reload')) {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($stream);
        }
    }
}
