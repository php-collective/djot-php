<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

use Djot\Node\ContentNodeInterface;

/**
 * Math inline or display
 */
class Math extends InlineNode implements ContentNodeInterface
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

    public function setContent(string $content): void
    {
        $this->content = $content;
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
