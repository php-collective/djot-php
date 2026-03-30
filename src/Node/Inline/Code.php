<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Inline code (backtick delimited)
 */
class Code extends InlineNode
{
    public function __construct(protected string $content = '')
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getType(): string
    {
        return 'code';
    }
}
