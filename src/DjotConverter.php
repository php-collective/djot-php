<?php

declare(strict_types=1);

namespace Djot;

use Djot\Node\Document;
use Djot\Parser\BlockParser;
use Djot\Renderer\HtmlRenderer;

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
     * Parse Djot markup into an AST
     */
    public function parse(string $djot): Document
    {
        return $this->parser->parse($djot);
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
     * @param callable(\Djot\Event\RenderEvent) $listener
     */
    public function on(string $event, callable $listener): self
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
