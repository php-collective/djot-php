<?php

declare(strict_types=1);

namespace Djot\Extension;

use Closure;
use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Document;

/**
 * Parses frontmatter blocks at the start of documents
 *
 * Supports YAML, NEON, TOML, JSON, or any other format. The extension parses
 * the frontmatter syntax but does not interpret the content - applications
 * should use their preferred library (symfony/yaml, etc.) to parse the
 * raw content.
 *
 * Syntax:
 * ```
 * ---yaml
 * title: My Document
 * author: John Doe
 * ---
 *
 * # Document content starts here
 * ```
 *
 * Block attributes are placed above (standard djot style):
 * ```
 * {.meta #frontmatter}
 * ---yaml
 * title: My Document
 * ---
 * ```
 *
 * The format identifier (yaml, toml, json) is required to distinguish
 * from thematic breaks (---).
 *
 * Example usage:
 * ```php
 * $ext = new FrontmatterExtension();
 * $converter = new DjotConverter();
 * $converter->addExtension($ext);
 *
 * $html = $converter->convert($djot);
 *
 * // Get the raw frontmatter
 * $frontmatter = $ext->getFrontmatter();
 * if ($frontmatter) {
 *     echo $frontmatter->getFormat(); // 'yaml'
 *     echo $frontmatter->getContent(); // 'title: My Document...'
 * }
 *
 * // Or use the convenience method with a parser
 * $metadata = $ext->getParsedContent(function($content, $format) {
 *     if ($format === 'yaml') {
 *         return \Symfony\Component\Yaml\Yaml::parse($content);
 *     }
 *     return null;
 * });
 * ```
 *
 * Configuration:
 * ```php
 * // Bare --- opening (no format specified) falls back to 'yaml' by default
 * $ext = new FrontmatterExtension();
 *
 * // Configure a different default format (e.g. for TOML-first projects)
 * $ext = new FrontmatterExtension(defaultFormat: 'toml');
 *
 * // Output frontmatter as HTML comment
 * $ext = new FrontmatterExtension(renderAsComment: true);
 *
 * // Custom render callback
 * $ext = new FrontmatterExtension(
 *     renderCallback: fn(Frontmatter $fm) => '<script type="application/json">' .
 *         htmlspecialchars($fm->getContent()) . '</script>'
 * );
 * ```
 */
class FrontmatterExtension implements ExtensionInterface
{
    protected ?Frontmatter $frontmatter = null;

    /**
     * @var \Closure|null
     * @phpstan-var (\Closure(\Djot\Extension\Frontmatter): string)|null
     */
    protected ?Closure $renderCallback = null;

    /**
     * @param string $defaultFormat Format to use when the opening delimiter has no format identifier (e.g. bare ---)
     * @param bool $renderAsComment If true, render frontmatter as HTML comment
     * @param (\Closure(\Djot\Extension\Frontmatter): string)|null $renderCallback Custom render callback
     */
    public function __construct(
        protected string $defaultFormat = 'yaml',
        protected bool $renderAsComment = false,
        ?Closure $renderCallback = null,
    ) {
        $this->renderCallback = $renderCallback;
    }

    public function register(DjotConverter $converter): void
    {
        $parser = $converter->getParser();

        // Register block pattern for frontmatter
        // Matches --- optionally followed by a format identifier (e.g. ---yaml, ---toml)
        // When no identifier is present, $defaultFormat is used as the fallback
        $parser->addBlockPattern(
            '/^---(\w*)\s*$/',
            function (array $lines, int $start, $parent, $blockParser) {
                // Only match at document start (first block of Document)
                if (!($parent instanceof Document) || $parent->hasChildren()) {
                    return null;
                }

                if (!preg_match('/^---(\w*)\s*$/', $lines[$start], $matches)) {
                    return null; // @codeCoverageIgnore - pattern already matched
                }
                $format = $matches[1] !== '' ? $matches[1] : $this->defaultFormat;

                // Find closing ---
                $i = $start + 1;
                $count = count($lines);
                $contentLines = [];
                $closed = false;

                while ($i < $count) {
                    $line = $lines[$i];
                    // Closing delimiter is just ---
                    if (preg_match('/^---\s*$/', $line)) {
                        $i++;
                        $closed = true;

                        break;
                    }
                    $contentLines[] = $line;
                    $i++;
                }

                // If no closing delimiter found, don't treat as frontmatter
                if (!$closed) {
                    return null;
                }

                $content = implode("\n", $contentLines);

                $frontmatter = new Frontmatter($content, $format);

                // Apply block attributes from preceding line (standard djot style)
                $attrs = $blockParser->consumePendingAttributes();
                if ($attrs !== []) {
                    $frontmatter->setAttributes($attrs);
                }

                $this->frontmatter = $frontmatter;
                $parent->appendChild($frontmatter);

                return $i - $start;
            },
        );

        // Track which document we've processed to clear stale state
        $processedDoc = null;

        // Clear state when rendering a new document (using wildcard to catch first child)
        $converter->on('render.*', function (RenderEvent $event) use (&$processedDoc): void {
            $node = $event->getNode();
            $parent = $node->getParent();

            // Only process when we see a direct child of Document
            if (!($parent instanceof Document)) {
                return;
            }

            // If this is a new document (different from last processed), check for frontmatter
            if ($processedDoc !== $parent) {
                $processedDoc = $parent;

                // Check if this document has a Frontmatter node
                $hasFrontmatter = false;
                foreach ($parent->getChildren() as $child) {
                    if ($child instanceof Frontmatter) {
                        $hasFrontmatter = true;

                        break;
                    }
                }

                // Clear stale state if no frontmatter in this document
                if (!$hasFrontmatter) {
                    $this->frontmatter = null;
                }
            }
        });

        // Register render event to control output
        $converter->on('render.frontmatter', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!($node instanceof Frontmatter)) {
                return;
            }

            $event->preventDefault();

            if ($this->renderCallback !== null) {
                $event->setHtml(($this->renderCallback)($node));

                return;
            }

            if ($this->renderAsComment) {
                $content = $node->getContent();
                // Escape -- in content to prevent breaking HTML comments
                $escaped = str_replace('--', '&#45;&#45;', $content);
                $event->setHtml("<!-- frontmatter ({$node->getFormat()})\n{$escaped}\n-->\n");

                return;
            }

            // Default: no output
            $event->setHtml('');
        });
    }

    /**
     * Get the parsed frontmatter node
     *
     * Returns null if no frontmatter was found or parsing hasn't occurred yet.
     */
    public function getFrontmatter(): ?Frontmatter
    {
        return $this->frontmatter;
    }

    /**
     * Check if frontmatter was found
     */
    public function hasFrontmatter(): bool
    {
        return $this->frontmatter !== null;
    }

    /**
     * Get the raw frontmatter content
     */
    public function getContent(): ?string
    {
        return $this->frontmatter?->getContent();
    }

    /**
     * Get the frontmatter format (yaml, toml, json, etc.)
     */
    public function getFormat(): ?string
    {
        return $this->frontmatter?->getFormat();
    }

    /**
     * Parse the frontmatter content using a custom parser
     *
     * Example with Symfony YAML:
     * ```php
     * $data = $ext->getParsedContent(function($content, $format) {
     *     return match($format) {
     *         'yaml' => \Symfony\Component\Yaml\Yaml::parse($content),
     *         'json' => json_decode($content, true),
     *         'toml' => \Yosymfony\Toml\Toml::parse($content),
     *         default => null,
     *     };
     * });
     * ```
     *
     * @param callable $parser Callback that receives (string $content, string $format) and returns parsed data
     *
     * @return mixed The parsed content, or null if no frontmatter
     */
    public function getParsedContent(callable $parser): mixed
    {
        if ($this->frontmatter === null) {
            return null;
        }

        return $parser(
            $this->frontmatter->getContent(),
            $this->frontmatter->getFormat(),
        );
    }

    /**
     * Reset the extension state (for reuse with multiple documents)
     */
    public function reset(): void
    {
        $this->frontmatter = null;
    }
}
