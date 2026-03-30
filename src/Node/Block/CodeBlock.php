<?php

declare(strict_types=1);

namespace Djot\Node\Block;

use Djot\Node\ContentNodeInterface;

/**
 * Fenced code block
 */
class CodeBlock extends BlockNode implements ContentNodeInterface
{
    public function __construct(
        protected string $content = '',
        protected ?string $language = null,
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

    public function appendContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }

    public function getType(): string
    {
        return 'code_block';
    }
}
