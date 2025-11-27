<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Math inline or display
 */
class Math extends InlineNode
{
    public function __construct(
        protected string $content = '',
        protected bool $display = false,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Display math ($$) vs inline math ($)
     */
    public function isDisplay(): bool
    {
        return $this->display;
    }

    public function getType(): string
    {
        return 'math';
    }
}
