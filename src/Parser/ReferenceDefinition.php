<?php

declare(strict_types=1);

namespace Djot\Parser;

/**
 * Holds a reference definition's URL and attributes
 */
class ReferenceDefinition
{
    /**
     * @param string $url
     * @param array<string, string> $attributes
     * @param int $line Line number where reference was defined (0-indexed)
     */
    public function __construct(
        public readonly string $url,
        public readonly array $attributes = [],
        public readonly int $line = 0,
    ) {
    }
}
