<?php

declare(strict_types=1);

namespace Djot\Node\Block;

use Djot\Node\ContentNodeInterface;

/**
 * Comment block - stripped from output
 */
class Comment extends BlockNode implements ContentNodeInterface
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
