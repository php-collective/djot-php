<?php

declare(strict_types=1);

namespace Djot\Watch;

class FileWatcher
{
    /**
     * Fingerprint per tracked path: (mtime, size, hash).
     *
     * PHP's filemtime() is second-resolution, so a same-second edit that
     * preserves file length (e.g. a typo fix replacing 3 chars with 3 chars)
     * would look identical via (mtime, size) alone. The content hash catches
     * those cases. xxhash32 if available (fast, ~3 GB/s), md5 fallback —
     * cryptographic strength is irrelevant; we only need collision avoidance
     * across the file's lifetime in this process.
     *
     * @var array<string, array{mtime: int, size: int, hash: string}>
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
     * @return array{mtime: int, size: int, hash: string}
     */
    private function fingerprint(string $path): array
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return ['mtime' => 0, 'size' => 0, 'hash' => ''];
        }
        $algo = in_array('xxh32', hash_algos(), true) ? 'xxh32' : 'md5';
        $hash = @hash_file($algo, $path);

        return [
            'mtime' => (int)filemtime($path),
            'size' => (int)filesize($path),
            'hash' => is_string($hash) ? $hash : '',
        ];
    }
}
