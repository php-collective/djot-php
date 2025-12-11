<?php

declare(strict_types=1);

namespace Djot\Node\Inline;

/**
 * Abbreviation element
 *
 * Renders as <abbr title="definition">text</abbr>
 *
 * This is an extension feature inspired by PHP Markdown Extra.
 * Abbreviations are defined like:
 *
 *   *[HTML]: Hyper Text Markup Language
 *
 * And all occurrences of "HTML" in the document are wrapped in <abbr> tags.
 */
class Abbreviation extends InlineNode
{
    public function __construct(protected string $title)
    {
    }

    public function getType(): string
    {
        return 'abbreviation';
    }

    /**
     * Get the abbreviation definition (title)
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Set the abbreviation definition (title)
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }
}
