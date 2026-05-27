<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    protected ?string $source = null;

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): void
    {
        $this->source = $source;
    }

    public function getType(): string
    {
        return 'div';
    }
}
