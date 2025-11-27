<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Hyperlink
 */
class Link extends InlineNode
{
    public function __construct(
        protected ?string $destination = null,
        protected ?string $title = null,
    ) {
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return 'link';
    }
}
