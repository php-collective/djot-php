<?php

declare(strict_types=1);

namespace Djot\Watch;

use RuntimeException;

/**
 * Long-running CLI controller for the djot-watch live-preview server.
 *
 * Parses argv, spawns the HTTP server via `php -S`, polls the target file for
 * mtime/size changes, and bumps a shared SSE channel each time so connected
 * browser clients reload.
 */
class Watcher
{
    /**
     * @var string
     */
    public const DEFAULT_HOST = '127.0.0.1';

    /**
     * @var int
     */
    public const DEFAULT_PORT = 8765;

    /**
     * @var int
     */
    private const POLL_INTERVAL_US = 250_000;

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        try {
            $opts = $this->parseArgs($argv);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 64;
        }

        if ($opts['help']) {
            $this->printHelp();

            return 0;
        }
        if ($opts['version']) {
            echo "djot-watch 0.1.0\n";

            return 0;
        }
        if ($opts['target'] === null) {
            fwrite(STDERR, "Error: no target file given. Try `djot-watch --help`.\n");

            return 64;
        }
        if (!is_file($opts['target'])) {
            fwrite(STDERR, "Error: target file does not exist: {$opts['target']}\n");

            return 66;
        }

        $statePath = (string)tempnam(sys_get_temp_dir(), 'djot_watch_');
        if ($statePath === '') {
            fwrite(STDERR, "Error: could not create state file\n");

            return 70;
        }

        $channel = new SseChannel($statePath);
        $fileWatcher = new FileWatcher([$opts['target']]);
        $server = new Server();

        $port = $server->start(
            $opts['host'],
            $opts['port'],
            __DIR__ . '/router.php',
            [
                'DJOT_WATCH_TARGET' => $opts['target'],
                'DJOT_WATCH_STATE' => $statePath,
                'DJOT_WATCH_CSS' => $opts['css'] ?? '',
            ],
        );

        $url = "http://{$opts['host']}:{$port}/";
        fwrite(STDOUT, "djot-watch serving {$opts['target']} at {$url}\n");
        fwrite(STDOUT, "Press Ctrl+C to stop.\n");

        if (!$opts['no_open']) {
            $this->openBrowser($url);
        }

        $stopping = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stopping): void {
                $stopping = true;
            };
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
        }

        try {
            while (!$stopping) {
                if ($fileWatcher->poll()) {
                    $channel->bump();
                    fwrite(STDOUT, 'reload (' . $channel->current() . ")\n");
                }
                usleep(self::POLL_INTERVAL_US);
            }
        } finally {
            $server->stop();
            @unlink($statePath);
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     *
     * @throws \RuntimeException
     *
     * @return array{
     *     target: string|null,
     *     host: string,
     *     port: int,
     *     css: string|null,
     *     no_open: bool,
     *     help: bool,
     *     version: bool,
     * }
     */
    private function parseArgs(array $argv): array
    {
        $opts = [
            'target' => null,
            'host' => self::DEFAULT_HOST,
            'port' => self::DEFAULT_PORT,
            'css' => null,
            'no_open' => false,
            'help' => false,
            'version' => false,
        ];

        $n = count($argv);
        for ($i = 1; $i < $n; $i++) {
            $arg = $argv[$i];
            switch ($arg) {
                case '-h':
                case '--help':
                    $opts['help'] = true;

                    break;
                case '-v':
                case '--version':
                    $opts['version'] = true;

                    break;
                case '--no-open':
                    $opts['no_open'] = true;

                    break;
                case '-p':
                case '--port':
                    if (!isset($argv[$i + 1])) {
                        throw new RuntimeException($arg . ' requires a value');
                    }
                    $opts['port'] = (int)$argv[++$i];

                    break;
                case '--host':
                    if (!isset($argv[$i + 1])) {
                        throw new RuntimeException($arg . ' requires a value');
                    }
                    $opts['host'] = $argv[++$i];

                    break;
                case '--css':
                    if (!isset($argv[$i + 1])) {
                        throw new RuntimeException($arg . ' requires a value');
                    }
                    $opts['css'] = $argv[++$i];

                    break;
                default:
                    if (str_starts_with($arg, '-')) {
                        throw new RuntimeException("Unknown option: {$arg}");
                    }
                    if ($opts['target'] !== null) {
                        throw new RuntimeException("Only one target file supported (got '{$arg}' after '{$opts['target']}')");
                    }
                    $opts['target'] = $arg;
            }
        }

        return $opts;
    }

    private function openBrowser(string $url): void
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start ""',
            default => 'xdg-open',
        };
        @exec(sprintf('%s %s >/dev/null 2>&1 &', $cmd, escapeshellarg($url)));
    }

    private function printHelp(): void
    {
        echo <<<HELP
djot-watch — live preview server for djot files

Usage:
  djot-watch [options] <file.djot>

Options:
  -p, --port PORT       HTTP port (default 8765; auto-bumps up to +10)
      --host HOST       Bind host (default 127.0.0.1)
      --no-open         Do not open browser on startup
      --css FILE        Custom CSS file served at /__assets/style.css
  -v, --version         Print version
  -h, --help            Print this help

HELP;
    }
}
