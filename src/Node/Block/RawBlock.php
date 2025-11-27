<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Raw block (pass-through to specific format)
 */
class RawBlock extends BlockNode
{
    public function __construct(
        protected string $content = '',
        protected string $format = '',
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getType(): string
    {
        return 'raw_block';
    }
}
