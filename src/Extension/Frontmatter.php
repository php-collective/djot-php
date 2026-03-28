<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\Node\Block\BlockNode;

/**
 * Frontmatter block node
 *
 * Represents document metadata in various formats (YAML, TOML, JSON, etc.)
 * at the start of a document.
 *
 * Syntax:
 * ```
 * ---yaml
 * title: My Document
 * author: John Doe
 * ---
 * ```
 *
 * The format identifier (yaml, toml, json, etc.) is required to distinguish
 * from thematic breaks (---).
 */
class Frontmatter extends BlockNode
{
    public function __construct(
        protected string $content = '',
        protected string $format = 'yaml',
    ) {
    }

    /**
     * Get the raw frontmatter content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Set the raw frontmatter content
     */
    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * Get the format identifier (yaml, toml, json, etc.)
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Set the format identifier
     */
    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getType(): string
    {
        return 'frontmatter';
    }
}
