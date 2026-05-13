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
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    // Detect client disconnect so we can break out of the long-poll loop
    // without blocking php -S (or downstream proc_close) for the full window.
    ignore_user_abort(false);

    $channel = new SseChannel($statePath);
    $last = $channel->current();
    echo ": connected\n\n";
    @ob_flush();
    @flush();
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
            @ob_flush();
            @flush();
            $last = $now;
            // Send a comment after the reload so write-detection of aborts
            // fires on the next iteration if the client has disconnected.
            echo ": tick\n\n";
            @ob_flush();
            @flush();
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
