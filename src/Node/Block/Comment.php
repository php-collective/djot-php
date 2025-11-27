<?php

declare(strict_types=1);

namespace Djot\Node\Block;

/**
 * Comment block - stripped from output
 */
class Comment extends BlockNode
{
    public function __construct(protected string $content = '')
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getType(): string
    {
        return 'comment';
    }
}
