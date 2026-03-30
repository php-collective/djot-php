<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Raw inline content (pass-through to specific format)
 */
class RawInline extends InlineNode
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

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getType(): string
    {
        return 'raw_inline';
    }
}
