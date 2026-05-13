<?php

declare(strict_types=1);

/**
 * Router for the dev preview server. Runs inside `php -S`, so this script
 * receives one request per HTTP hit and returns true if it handled the URL.
 */

$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require $autoloadPath;

        break;
    }
}

use Djot\Watch\Renderer;
use Djot\Watch\SseChannel;

$target = (string)getenv('DJOT_WATCH_TARGET');
$statePath = (string)getenv('DJOT_WATCH_STATE');
$cssOverride = (string)getenv('DJOT_WATCH_CSS');
$assetsDir = __DIR__ . '/assets';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : null;
if (!is_string($path)) {
    $path = '/';
}

if ($path === '/__assets/livereload.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile($assetsDir . '/livereload.js');

    return true;
}

if ($path === '/__assets/style.css') {
    header('Content-Type: text/css; charset=utf-8');
    if ($cssOverride !== '' && is_file($cssOverride)) {
        readfile($cssOverride);
    } else {
        readfile($assetsDir . '/style.css');
    }

    return true;
}

if ($path === '/__sse') {
    // Tear down any output buffering and disable PHP's auto-buffers so
    // each echo flushes to the wire immediately. Without this, php -S
    // tends to hold bytes until the script ends and the browser sees the
    // SSE stream as a single delayed blob rather than a live event feed.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    ob_implicit_flush(true);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    // Detect client disconnect so we can break out of the long-poll loop
    // without blocking php -S (or downstream proc_close) for the full window.
    ignore_user_abort(false);

    $channel = new SseChannel($statePath);
    $last = $channel->current();

    // 2 KiB of comment padding upfront. Some browsers (notably Chrome and
    // its forks) defer EventSource parsing until they have ~1 KiB+ of
    // body bytes; without this the first real event takes that long to
    // surface, and a short-lived `event: reload` can be missed entirely.
    echo ': ' . str_repeat('x', 2048) . "\n\n";
    echo ": connected\n\n";
    flush();
    if (connection_aborted()) {
        return true;
    }

    // Hold the connection for ~25s, then the browser reconnects automatically.
    $deadline = time() + 25;
    while (time() < $deadline) {
        if (connection_aborted()) {
            return true;
        }
        $now = $channel->current();
        if ($now !== $last) {
            echo "event: reload\ndata: {$now}\n\n";
            flush();
            $last = $now;
        } else {
            // Periodic comment keeps the connection warm AND gives PHP a
            // chance to notice client disconnects (connection_aborted() is
            // only updated on write attempts).
            echo ": ping\n\n";
            flush();
        }
        usleep(100_000);
    }

    return true;
}

if ($path === '/' || $path === '/index.html') {
    if (!is_file($target)) {
        http_response_code(404);
        echo "djot-watch: target file '{$target}' not found.";

        return true;
    }
    $djot = (string)file_get_contents($target);
    $renderer = new Renderer();
    echo $renderer->renderDocument($djot, cssPath: $cssOverride !== '' ? $cssOverride : null);

    return true;
}

http_response_code(404);
echo 'Not found';

return true;
