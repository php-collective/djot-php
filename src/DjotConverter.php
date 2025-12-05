<?php

declare(strict_types=1);

namespace Djot;

use Closure;
use Djot\Filter\ProfileFilter;
use Djot\Node\Document;
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\SoftBreakMode;
use LengthException;
use RuntimeException;

/**
 * Main Djot to HTML converter
 */
class DjotConverter
{
    protected BlockParser $parser;

    protected HtmlRenderer $renderer;

    protected bool $collectWarnings;

    protected bool $strictMode;

    protected ?Profile $profile = null;

    protected ?ProfileFilter $profileFilter = null;

    /**
     * @param bool $xhtml Whether to use XHTML-compatible output
     * @param bool $warnings Whether to collect warnings during parsing
     * @param bool $strict Whether to throw exceptions on parse errors
     * @param \Djot\SafeMode|bool|null $safeMode Enable safe mode (true for defaults, SafeMode instance for custom config)
     * @param \Djot\Profile|null $profile Profile for feature restriction (null = all features allowed)
     * @param bool $significantNewlines Enable significant newlines mode (markdown-like paragraph interruption)
     */
    public function __construct(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
        bool $significantNewlines = false,
    ) {
        $this->collectWarnings = $warnings;
        $this->strictMode = $strict;
        $this->parser = new BlockParser($warnings, $strict, $significantNewlines);
        $this->renderer = new HtmlRenderer($xhtml);

        // Configure safe mode
        if ($safeMode === true) {
            $this->renderer->setSafeMode(SafeMode::defaults());
        } elseif ($safeMode instanceof SafeMode) {
            $this->renderer->setSafeMode($safeMode);
        }

        // In significant newlines mode, soft breaks become visible <br>
        if ($significantNewlines) {
            $this->renderer->setSoftBreakMode(SoftBreakMode::Break);
        }

        // Configure profile
        $this->profile = $profile;
        if ($profile !== null) {
            $this->profileFilter = new ProfileFilter();
        }
    }

    /**
     * Create a converter with significant newlines mode enabled
     *
     * In this mode:
     * - Block elements (lists, blockquotes, code) can interrupt paragraphs
     * - Soft breaks render as visible <br> tags
     * - Nested blocks in lists don't need blank lines
     *
     * Ideal for chat messages, comments, and quick notes.
     */
    public static function withSignificantNewlines(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
    ): self {
        return new self($xhtml, $warnings, $strict, $safeMode, $profile, true);
    }

    /**
     * Enable or disable safe mode
     *
     * @param \Djot\SafeMode|bool|null $safeMode True for defaults, SafeMode for custom, null/false to disable
     */
    public function setSafeMode(bool|SafeMode|null $safeMode): self
    {
        if ($safeMode === true) {
            $this->renderer->setSafeMode(SafeMode::defaults());
        } elseif ($safeMode instanceof SafeMode) {
            $this->renderer->setSafeMode($safeMode);
        } else {
            $this->renderer->setSafeMode(null);
        }

        return $this;
    }

    /**
     * Set the profile for feature restriction
     *
     * @param \Djot\Profile|null $profile Null to disable profile filtering
     */
    public function setProfile(?Profile $profile): self
    {
        $this->profile = $profile;
        if ($profile !== null && $this->profileFilter === null) {
            $this->profileFilter = new ProfileFilter();
        }

        return $this;
    }

    /**
     * Get the current profile
     */
    public function getProfile(): ?Profile
    {
        return $this->profile;
    }

    /**
     * Convert Djot markup to HTML
     *
     * @throws \LengthException If input exceeds profile's max length
     */
    public function convert(string $djot): string
    {
        // Check max length before parsing
        if ($this->profile !== null && $this->profile->getMaxLength() > 0) {
            if (strlen($djot) > $this->profile->getMaxLength()) {
                throw new LengthException(
                    sprintf(
                        'Input length (%d bytes) exceeds maximum allowed (%d bytes)',
                        strlen($djot),
                        $this->profile->getMaxLength(),
                    ),
                );
            }
        }

        $document = $this->parse($djot);

        // Apply profile filter after parsing
        if ($this->profile !== null && $this->profileFilter !== null) {
            $document = $this->profileFilter->filter($document, $this->profile);
        }

        return $this->render($document);
    }

    /**
     * Convert a Djot file to HTML
     */
    public function convertFile(string $path): string
    {
        $document = $this->parseFile($path);

        return $this->render($document);
    }

    /**
     * Parse Djot markup into an AST
     */
    public function parse(string $djot): Document
    {
        return $this->parser->parse($djot);
    }

    /**
     * Parse a Djot file into an AST
     *
     * @throws \RuntimeException If the file cannot be read
     */
    public function parseFile(string $path): Document
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $this->parse($content);
    }

    /**
     * Render an AST document to HTML
     */
    public function render(Document $document): string
    {
        return $this->renderer->render($document);
    }

    /**
     * Register a listener for a render event
     *
     * Event names correspond to node types:
     * - render.link, render.image, render.paragraph, render.heading, etc.
     * - render.* for all nodes
     *
     * Example:
     * ```php
     * $converter->on('render.link', function(RenderEvent $event): void {
     *     $link = $event->getNode();
     *     $link->setAttribute('target', '_blank');
     * });
     * ```
     *
     * @param string $event
     * @param \Closure(\Djot\Event\RenderEvent): void $listener
     */
    public function on(string $event, Closure $listener): self
    {
        $this->renderer->on($event, $listener);

        return $this;
    }

    /**
     * Remove all listeners for an event (or all events if no event specified)
     */
    public function off(?string $event = null): self
    {
        $this->renderer->off($event);

        return $this;
    }

    /**
     * Get the HTML renderer for direct configuration
     */
    public function getRenderer(): HtmlRenderer
    {
        return $this->renderer;
    }

    /**
     * Get the block parser for direct access
     */
    public function getParser(): BlockParser
    {
        return $this->parser;
    }

    /**
     * Get warnings collected during the last parse operation
     *
     * Only populated when warnings collection is enabled.
     *
     * @return array<\Djot\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->parser->getWarnings();
    }

    /**
     * Check if there were any warnings during the last parse operation
     */
    public function hasWarnings(): bool
    {
        return count($this->parser->getWarnings()) > 0;
    }

    /**
     * Clear any collected warnings
     */
    public function clearWarnings(): self
    {
        $this->parser->clearWarnings();

        return $this;
    }

    /**
     * Get profile violations from the last convert operation
     *
     * @return array<\Djot\ProfileViolation>
     */
    public function getProfileViolations(): array
    {
        return $this->profileFilter?->getViolations() ?? [];
    }

    /**
     * Check if there were any profile violations during the last convert
     */
    public function hasProfileViolations(): bool
    {
        return count($this->getProfileViolations()) > 0;
    }
}
