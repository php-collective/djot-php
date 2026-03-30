<?php

declare(strict_types=1);

namespace Djot;

use Closure;
use Djot\Extension\BeforeRenderExtensionInterface;
use Djot\Extension\ExtensionInterface;
use Djot\Extension\HeadingReferenceExtension;
use Djot\Extension\ParsedDocumentExtensionInterface;
use Djot\Extension\ResettableExtensionInterface;
use Djot\Extension\WikilinksExtension;
use Djot\Filter\ProfileFilter;
use Djot\Node\Document;
use Djot\Parser\BlockParser;
use Djot\Renderer\AnsiRenderer;
use Djot\Renderer\HeadingIdTracker;
use Djot\Renderer\HtmlRenderer;
use Djot\Renderer\MarkdownRenderer;
use Djot\Renderer\PlainTextRenderer;
use Djot\Renderer\RendererInterface;
use Djot\Renderer\SoftBreakMode;
use Djot\Transform\RenderAwareTransformerInterface;
use Djot\Transform\TransformerInterface;
use LengthException;
use LogicException;
use RuntimeException;

/**
 * Main Djot to HTML converter
 */
class DjotConverter
{
    protected BlockParser $parser;

    protected RendererInterface $renderer;

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
     * Create a converter with custom parser and/or renderer
     *
     * ```php
     * // Different renderer
     * $converter = DjotConverter::create(renderer: new MarkdownRenderer());
     *
     * // Custom parser
     * $converter = DjotConverter::create(
     *     parser: new BlockParser(significantNewlines: true),
     *     renderer: new HtmlRenderer(xhtml: true),
     * );
     * ```
     *
     * @param \Djot\Parser\BlockParser|null $parser Custom parser (null = default)
     * @param \Djot\Renderer\RendererInterface|null $renderer Custom renderer (null = HtmlRenderer)
     * @param \Djot\Profile|null $profile Profile for feature restriction
     */
    public static function create(
        ?BlockParser $parser = null,
        ?RendererInterface $renderer = null,
        ?Profile $profile = null,
    ): self {
        return new self(
            parser: $parser ?? new BlockParser(),
            renderer: $renderer ?? new HtmlRenderer(),
            profile: $profile,
        );
    }

    /**
     * Create a converter that outputs Markdown
     */
    public static function markdown(?BlockParser $parser = null): self
    {
        return self::create($parser, new MarkdownRenderer());
    }

    /**
     * Create a converter that outputs plain text
     */
    public static function plainText(?BlockParser $parser = null): self
    {
        return self::create($parser, new PlainTextRenderer());
    }

    /**
     * Create a converter that outputs ANSI (terminal)
     */
    public static function ansi(?BlockParser $parser = null): self
    {
        return self::create($parser, new AnsiRenderer());
    }

    /**
     * Convenience constructor with inline configuration
     *
     * For simple usage, pass configuration directly. For advanced usage with
     * full control over parser/renderer, use DjotConverter::create() instead.
     *
     * @param bool $xhtml Whether to use XHTML-compatible output
     * @param bool $warnings Whether to collect warnings during parsing
     * @param bool $strict Whether to throw exceptions on parse errors
     * @param \Djot\SafeMode|bool|null $safeMode Enable safe mode (true for defaults, SafeMode instance for custom config)
     * @param \Djot\Profile|null $profile Profile for feature restriction (null = all features allowed)
     * @param bool $significantNewlines Enable significant newlines mode (markdown-like paragraph interruption)
     * @param \Djot\Renderer\SoftBreakMode|null $softBreakMode How to render soft breaks (HTML renderer only)
     * @param bool $roundTripMode Add data attributes for Djot→HTML→Djot round-trips (HTML renderer only)
     * @param \Djot\Parser\BlockParser|null $parser Pre-configured parser (ignores warnings/strict/significantNewlines if set)
     * @param \Djot\Renderer\RendererInterface|null $renderer Pre-configured renderer (ignores xhtml/safeMode/softBreakMode/roundTripMode if set)
     */
    public function __construct(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
        bool $significantNewlines = false,
        ?SoftBreakMode $softBreakMode = null,
        bool $roundTripMode = false,
        ?BlockParser $parser = null,
        ?RendererInterface $renderer = null,
    ) {
        $this->collectWarnings = $warnings;
        $this->strictMode = $strict;

        // Use provided parser or create one from parameters
        if ($parser !== null) {
            $this->parser = $parser;
        } else {
            $this->parser = new BlockParser($warnings, $strict, $significantNewlines);
        }

        // Use provided renderer or create one from parameters
        if ($renderer !== null) {
            $this->renderer = $renderer;
        } else {
            $this->renderer = new HtmlRenderer($xhtml);

            // Configure safe mode
            $this->setSafeMode($safeMode);

            // Configure soft break mode if explicitly provided
            if ($softBreakMode !== null) {
                $this->renderer->setSoftBreakMode($softBreakMode);
            }

            // Configure round-trip mode
            if ($roundTripMode) {
                $this->renderer->setRoundTripMode(true);
            }
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
     * @param \Djot\Renderer\SoftBreakMode|null $softBreakMode How to render soft breaks (HTML renderer only)
     * @param bool $roundTripMode Add data attributes for round-trips (HTML renderer only)
     */
    public static function withSignificantNewlines(
        bool $xhtml = false,
        bool $warnings = false,
        bool $strict = false,
        bool|SafeMode|null $safeMode = null,
        ?Profile $profile = null,
        ?SoftBreakMode $softBreakMode = null,
        bool $roundTripMode = false,
    ): self {
        return new self($xhtml, $warnings, $strict, $safeMode, $profile, true, $softBreakMode, $roundTripMode);
    }

    /**
     * Enable or disable safe mode (HtmlRenderer only)
     *
     * @param \Djot\SafeMode|bool|null $safeMode True for defaults, SafeMode for custom, null/false to disable
     */
    public function setSafeMode(bool|SafeMode|null $safeMode): self
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            return $this;
        }

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

        return $this->render($this->parse($djot));
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

        return $this->render($this->parse($content));
    }

    /**
     * Parse Djot markup into an AST
     */
    public function parse(string $djot): Document
    {
        $this->enforceProfileMaxLength($djot);

        $document = $this->parser->parse($djot);

        foreach ($this->extensions as $extension) {
            if ($extension instanceof ParsedDocumentExtensionInterface) {
                $extension->afterParse($document);
            }
        }

        return $document;
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
     * Apply one or more AST transforms and return the transformed document.
     */
    public function transform(Document $document, TransformerInterface ...$transformers): Document
    {
        foreach ($transformers as $transformer) {
            $document = $transformer instanceof RenderAwareTransformerInterface
                ? $transformer->transformForRenderer($document, $this->renderer)
                : $transformer->transform($document);
        }

        return $document;
    }

    /**
     * Render an AST document to HTML
     */
    public function render(Document $document): string
    {
        $document = $this->prepareDocumentForRender($document);

        foreach ($this->extensions as $extension) {
            if ($extension instanceof ResettableExtensionInterface) {
                $extension->clear();
            }
        }

        foreach ($this->extensions as $extension) {
            if ($extension instanceof BeforeRenderExtensionInterface) {
                $document = $extension->beforeRender($document);
            }
        }

        $html = $this->renderer->render($document);

        foreach ($this->outputTransformers as $transformer) {
            $html = $transformer($html);
        }

        return $html;
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
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->on($event, $listener);
        }

        return $this;
    }

    /**
     * Remove all listeners for an event (or all events if no event specified)
     */
    public function off(?string $event = null): self
    {
        if ($this->renderer instanceof HtmlRenderer) {
            $this->renderer->off($event);
        }

        return $this;
    }

    /**
     * Get the renderer
     */
    public function getRenderer(): RendererInterface
    {
        return $this->renderer;
    }

    /**
     * Get the HTML renderer for direct configuration
     *
     * @throws \LogicException If renderer is not HtmlRenderer
     */
    public function getHtmlRenderer(): HtmlRenderer
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            throw new LogicException('getHtmlRenderer() is only available when using HtmlRenderer');
        }

        return $this->renderer;
    }

    /**
     * Get the heading ID tracker (HtmlRenderer only)
     *
     * @throws \LogicException If renderer is not HtmlRenderer
     */
    public function getHeadingIdTracker(): HeadingIdTracker
    {
        if (!$this->renderer instanceof HtmlRenderer) {
            throw new LogicException('getHeadingIdTracker() is only supported with HtmlRenderer');
        }

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
        $registeredExtension = $extension instanceof BeforeRenderExtensionInterface ? clone $extension : $extension;
        $this->extensions[] = $registeredExtension;
        $registeredExtension->register($this);

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
        return $this->parser->getWarnings() !== [];
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
        return $this->getProfileViolations() !== [];
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

    protected function prepareDocumentForRender(Document $document): Document
    {
        if ($this->profileFilter !== null && $this->profile === null) {
            $this->profileFilter->clearViolations();
        }

        if ($this->profile === null || $this->profileFilter === null) {
            return $document;
        }

        return $this->applyProfile(clone $document);
    }
}
