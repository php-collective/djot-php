<?php

declare(strict_types=1);

namespace Djot\Watch;

class FileWatcher
{
    /**
     * Fingerprint per tracked path. PHP's filemtime() is only second-resolution,
     * so two saves within the same second look identical via mtime alone.
     * Combining mtime with filesize catches the common case where the buffer's
     * byte count differs after a quick re-save.
     *
     * @var array<string, array{mtime: int, size: int}>
     */
    private array $fingerprints = [];

    /**
     * @param list<string> $paths
     */
    public function __construct(private readonly array $paths)
    {
        foreach ($this->paths as $path) {
            $this->fingerprints[$path] = $this->fingerprint($path);
        }
    }

    public function poll(): bool
    {
        $changed = false;
        foreach ($this->paths as $path) {
            $current = $this->fingerprint($path);
            if ($current !== $this->fingerprints[$path]) {
                $this->fingerprints[$path] = $current;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @return array{mtime: int, size: int}
     */
    private function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return ['mtime' => 0, 'size' => 0];
        }

        return [
            'mtime' => (int)filemtime($path),
            'size' => (int)filesize($path),
        ];
    }
}
