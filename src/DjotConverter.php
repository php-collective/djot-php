<?php

declare(strict_types=1);

namespace Djot;

use Closure;
use Djot\Node\Document;
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;
use RuntimeException;

/**
 * Main Djot to HTML converter
 */
class DjotConverter
{
    protected BlockParser $parser;

    protected HtmlRenderer $renderer;

    public function __construct(bool $xhtml = false)
    {
        $this->parser = new BlockParser();
        $this->renderer = new HtmlRenderer($xhtml);
    }

    /**
     * Convert Djot markup to HTML
     */
    public function convert(string $djot): string
    {
        $document = $this->parse($djot);

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
}
