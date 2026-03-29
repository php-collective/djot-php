<?php

declare(strict_types=1);

namespace Djot;

use Closure;
use Djot\Extension\ExtensionInterface;
use Djot\Extension\HeadingReferenceExtension;
use Djot\Extension\WikilinksExtension;
use Djot\Filter\ProfileFilter;
use Djot\Node\Document;
use Djot\Parser\BlockParser;
use Djot\Renderer\HeadingIdTracker;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\SoftBreakMode;
use LengthException;
use LogicException;
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
     * Registered extensions
     *
     * @var array<\Djot\Extension\ExtensionInterface>
     */
    protected array $extensions = [];

    /**
     * Output transformers (called after rendering)
     *
     * @var array<\Closure(string): string>
     */
    protected array $outputTransformers = [];

    /**
     * @param bool $xhtml Whether to use XHTML-compatible output
     * @param bool $warnings Whether to collect warnings during parsing
     * @param bool $strict Whether to throw exceptions on parse errors
     * @param \Djot\SafeMode|bool|null $safeMode Enable safe mode (true for defaults, SafeMode instance for custom config)
     * @param \Djot\Profile|null $profile Profile for feature restriction (null = all features allowed)
     * @param bool $significantNewlines Enable significant newlines mode (markdown-like paragraph interruption)
     * @param \Djot\Renderer\SoftBreakMode|null $softBreakMode How to render soft breaks (null = renderer default)
     */
    public function __construct(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
        bool $significantNewlines = false,
        ?SoftBreakMode $softBreakMode = null,
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

        // Configure soft break mode if explicitly provided
        if ($softBreakMode !== null) {
            $this->renderer->setSoftBreakMode($softBreakMode);
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
     * - Block elements (lists, blockquotes, code) can interrupt paragraphs without blank lines
     * - Nested blocks in lists don't need blank lines
     *
     * This provides markdown-like behavior for block interruption.
     *
     * Note: This does NOT change how soft breaks are rendered. Use setSoftBreakMode()
     * or the softBreakMode parameter if you also want visible line breaks.
     *
     * @param bool $xhtml Whether to use XHTML-compatible output
     * @param bool $warnings Whether to collect warnings during parsing
     * @param bool $strict Whether to throw exceptions on parse errors
     * @param \Djot\SafeMode|bool|null $safeMode Enable safe mode
     * @param \Djot\Profile|null $profile Profile for feature restriction
     * @param \Djot\Renderer\SoftBreakMode|null $softBreakMode How to render soft breaks
     */
    public static function withSignificantNewlines(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
        ?SoftBreakMode $softBreakMode = null,
    ): self {
        return new self($xhtml, $warnings, $strict, $safeMode, $profile, true, $softBreakMode);
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
     */
    public function convert(string $djot): string
    {
        // Check max length before parsing
        $this->enforceProfileMaxLength($djot);

        $document = $this->parse($djot);
        $document = $this->applyProfile($document);

        $html = $this->render($document);

        // Apply output transformers
        foreach ($this->outputTransformers as $transformer) {
            $html = $transformer($html);
        }

        return $html;
    }

    /**
     * Convert a Djot file to HTML
     *
     * @throws \RuntimeException
     */
    public function convertFile(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        $this->enforceProfileMaxLength($content);

        $document = $this->parse($content);
        $document = $this->applyProfile($document);

        $html = $this->render($document);

        // Apply output transformers
        foreach ($this->outputTransformers as $transformer) {
            $html = $transformer($html);
        }

        return $html;
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
        foreach ($this->extensions as $extension) {
            if (method_exists($extension, 'clear')) {
                $extension->clear();
            }
        }

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
     * Get the heading ID tracker
     */
    public function getHeadingIdTracker(): HeadingIdTracker
    {
        return $this->renderer->getHeadingIdTracker();
    }

    /**
     * Get the block parser for direct access
     */
    public function getParser(): BlockParser
    {
        return $this->parser;
    }

    /**
     * Register an extension
     *
     * Extensions can add custom inline/block patterns and render event listeners.
     *
     * Example:
     * ```php
     * $converter->addExtension(new ExternalLinksExtension());
     * $converter->addExtension(new MentionsExtension(
     *     userUrlTemplate: 'https://github.com/{username}',
     * ));
     * ```
     */
    public function addExtension(ExtensionInterface $extension): self
    {
        $this->assertCompatibleExtension($extension);
        $this->extensions[] = $extension;
        $extension->register($this);

        return $this;
    }

    /**
     * @throws \LogicException When the extension conflicts with an already registered extension
     */
    protected function assertCompatibleExtension(ExtensionInterface $extension): void
    {
        foreach ($this->extensions as $registered) {
            $hasHeadingReferences = $extension instanceof HeadingReferenceExtension
                || $registered instanceof HeadingReferenceExtension;
            $hasWikilinks = $extension instanceof WikilinksExtension
                || $registered instanceof WikilinksExtension;

            if ($hasHeadingReferences && $hasWikilinks) {
                throw new LogicException(
                    'HeadingReferenceExtension cannot be used together with WikilinksExtension because both parse [[...]] syntax.',
                );
            }
        }
    }

    /**
     * Get all registered extensions
     *
     * @return array<\Djot\Extension\ExtensionInterface>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * Add an output transformer
     *
     * Output transformers are called after rendering, allowing extensions
     * to modify the final HTML output (e.g., prepend/append content).
     *
     * @param \Closure(string): string $transformer
     */
    public function addOutputTransformer(Closure $transformer): self
    {
        $this->outputTransformers[] = $transformer;

        return $this;
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

    /**
     * @throws \LengthException If input exceeds profile's max length
     */
    protected function enforceProfileMaxLength(string $input): void
    {
        if ($this->profile !== null && $this->profile->getMaxLength() > 0) {
            if (strlen($input) > $this->profile->getMaxLength()) {
                throw new LengthException(
                    sprintf(
                        'Input length (%d bytes) exceeds maximum allowed (%d bytes)',
                        strlen($input),
                        $this->profile->getMaxLength(),
                    ),
                );
            }
        }
    }

    protected function applyProfile(Document $document): Document
    {
        if ($this->profileFilter !== null) {
            $this->profileFilter->clearViolations();
        }

        if ($this->profile !== null && $this->profileFilter !== null) {
            return $this->profileFilter->filter($document, $this->profile);
        }

        return $document;
    }
}
