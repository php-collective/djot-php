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
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $url,
        public readonly array $attributes = [],
    ) {
    }
}
