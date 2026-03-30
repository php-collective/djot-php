<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

use Djot\Node\ContentNodeInterface;

/**
 * Plain text node
 */
class Text extends InlineNode implements ContentNodeInterface
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

    public function appendContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getType(): string
    {
        return 'text';
    }
}
