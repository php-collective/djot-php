<?php

declare(strict_types=1);

namespace Djot\Watch;

class SseChannel
{
    public function __construct(private readonly string $statePath)
    {
        if (!file_exists($this->statePath)) {
            file_put_contents($this->statePath, '0');
        }
    }

    public function current(): int
    {
        clearstatcache(true, $this->statePath);
        $raw = @file_get_contents($this->statePath);
        if ($raw === false) {
            return 0;
        }

        return (int)trim($raw);
    }

    public function bump(): void
    {
        $next = $this->current() + 1;
        file_put_contents($this->statePath, (string)$next, LOCK_EX);
    }
}
