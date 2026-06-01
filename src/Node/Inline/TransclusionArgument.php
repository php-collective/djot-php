<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Transclusion argument
 */
class TransclusionArgument extends InlineNode
{
    public function __construct(
        protected ?string $key = null,
        protected int $index = 0,
    ) {
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function isNamed(): bool
    {
        return $this->key !== null;
    }

    public function getType(): string
    {
        return 'transclusion_argument';
    }
}
