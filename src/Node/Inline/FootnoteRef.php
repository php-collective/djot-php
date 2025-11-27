<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Footnote reference [^label]
 */
class FootnoteRef extends InlineNode
{
    public function __construct(protected string $label = '')
    {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return 'footnote_ref';
    }
}
