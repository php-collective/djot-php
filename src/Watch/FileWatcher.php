<?php

declare(strict_types=1);

namespace Djot\Watch;

class FileWatcher
{
    /** @var array<string, int> */
    private array $mtimes = [];

    /** @param list<string> $paths */
    public function __construct(private readonly array $paths)
    {
        foreach ($this->paths as $path) {
            clearstatcache(true, $path);
            $this->mtimes[$path] = file_exists($path) ? (int)filemtime($path) : 0;
        }
    }

    public function poll(): bool
    {
        $changed = false;
        foreach ($this->paths as $path) {
            clearstatcache(true, $path);
            $current = file_exists($path) ? (int)filemtime($path) : 0;
            if ($current !== $this->mtimes[$path]) {
                $this->mtimes[$path] = $current;
                $changed = true;
            }
        }

        return $changed;
    }
}
