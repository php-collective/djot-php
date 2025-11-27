<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Image
 */
class Image extends InlineNode
{
    public function __construct(
        protected string $source = '',
        protected string $alt = '',
        protected ?string $title = null,
    ) {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return 'image';
    }
}
