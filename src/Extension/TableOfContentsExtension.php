<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Renderer\HtmlRenderer;
use Djot\Util\StringUtil;

/**
 * Table of Contents generator
 *
 * Extracts headings from a document and generates a structured table of contents.
 * Can render as HTML or provide raw data for custom rendering.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $tocExtension = new TableOfContentsExtension();
 * $converter->addExtension($tocExtension);
 *
 * $html = $converter->convert($djot);
 *
 * // Get TOC as nested HTML list
 * $tocHtml = $tocExtension->getTocHtml();
 *
 * // Or get raw TOC data for custom rendering
 * $tocData = $tocExtension->getToc();
 * // Returns: [['level' => 1, 'text' => 'Intro', 'id' => 'Intro'], ...]
 * ```
 *
 * Configuration:
 * ```php
 * $tocExtension = new TableOfContentsExtension(
 *     minLevel: 2, // Start from h2
 *     maxLevel: 3, // Up to h3
 *     listType: 'ol', // Use ordered list
 *     position: 'top', // Auto-insert at 'top', 'bottom', or null for manual
 *     separator: "<hr>\n", // Optional separator between TOC and content
 *     collapsible: true, // Wrap in a <details>/<summary> disclosure
 *     summary: 'Contents', // Disclosure label (default 'Table of Contents')
 *     open: false, // Start expanded when true (default collapsed)
 * );
 * ```
 */
class TableOfContentsExtension implements ResettableExtensionInterface
{
    /**
     * Collected TOC entries from last parse
     *
     * @var list<array{level: int, text: string, id: string}>
     */
    protected array $toc = [];

    /**
     * @param int $minLevel Minimum heading level to include (1-6)
     * @param int $maxLevel Maximum heading level to include (1-6)
     * @param string $listType HTML list type: 'ul' or 'ol'
     * @param string $cssClass CSS class for the TOC container
     * @param string|null $position Auto-insert position: 'top', 'bottom', or null for manual placement
     * @param string $separator HTML separator between TOC and content (when position is set)
     * @param bool $collapsible Wrap the TOC in a `<details>`/`<summary>` disclosure so it can be
     *   collapsed. Off by default; when off the output is the unchanged `<nav class="toc">`.
     * @param string $summary Summary label for the disclosure (only used when $collapsible is true).
     * @param bool $open Render the disclosure expanded by default (only used when $collapsible is true).
     */
    public function __construct(
        protected int $minLevel = 1,
        protected int $maxLevel = 6,
        protected string $listType = 'ul',
        protected string $cssClass = 'toc',
        protected ?string $position = null,
        protected string $separator = '',
        protected bool $collapsible = false,
        protected string $summary = 'Table of Contents',
        protected bool $open = false,
    ) {
    }

    public function register(DjotConverter $converter): void
    {
        // Only works with HTML output - requires heading ID tracking
        if (!$converter->getRenderer() instanceof HtmlRenderer) {
            return;
        }

        $tracker = $converter->getHeadingIdTracker();

        // Hook into heading rendering to collect TOC entries
        $converter->on('render.heading', function ($event) use ($tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            $level = $node->getLevel();

            if ($level < $this->minLevel || $level > $this->maxLevel) {
                return;
            }

            $text = $tracker->getPlainText($node);
            $id = $tracker->getIdForHeading($node);

            $this->toc[] = [
                'level' => $level,
                'text' => $text,
                'id' => $id,
            ];
        });

        // Auto-insert TOC if position is specified
        if ($this->position !== null) {
            $converter->addOutputTransformer(function (string $html): string {
                $tocHtml = $this->getTocHtml();
                if ($tocHtml === '') {
                    return $html;
                }

                return match ($this->position) {
                    'top' => $tocHtml . $this->separator . $html,
                    'bottom' => $html . $this->separator . $tocHtml,
                    default => $html,
                };
            });
        }
    }

    /**
     * Get the table of contents as structured data
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function getToc(): array
    {
        return $this->toc;
    }

    /**
     * Get the table of contents as HTML
     *
     * @return string HTML list of TOC entries
     */
    public function getTocHtml(): string
    {
        if ($this->toc === []) {
            return '';
        }

        return $this->renderTocHtml($this->toc);
    }

    /**
     * Check if the document has any headings for TOC
     */
    public function hasToc(): bool
    {
        return $this->toc !== [];
    }

    /**
     * Clear the collected TOC (useful when reusing the extension)
     */
    public function clear(): void
    {
        $this->toc = [];
    }

    /**
     * Render TOC as nested HTML list
     *
     * @param list<array{level: int, text: string, id: string}> $headings
     */
    protected function renderTocHtml(array $headings): string
    {
        if ($headings === []) {
            return '';
        }

        if (!$this->collapsible) {
            return '<nav class="' . StringUtil::escapeHtml($this->cssClass) . '">' . "\n"
                . $this->renderTocList($headings)
                . '</nav>' . "\n";
        }

        // Collapsible: the heading list sits directly inside a <details>
        // disclosure so it can be toggled, closed by default unless $open.
        $open = $this->open ? ' open' : '';
        $html = '<details class="' . StringUtil::escapeHtml($this->cssClass) . '"' . $open . '>' . "\n";
        $html .= '<summary>' . StringUtil::escapeHtml($this->summary) . '</summary>' . "\n";
        $html .= $this->renderTocList($headings);
        $html .= '</details>' . "\n";

        return $html;
    }

    /**
     * Render the nested list structure
     *
     * @param list<array{level: int, text: string, id: string}> $headings
     */
    protected function renderTocList(array $headings): string
    {
        if ($headings === []) {
            return '';
        }

        $html = '<' . $this->listType . '>' . "\n";
        $levelStack = [$headings[0]['level']];
        $hasOpenItem = false;

        foreach ($headings as $heading) {
            $level = $heading['level'];

            if ($hasOpenItem) {
                $depth = count($levelStack);
                $currentLevel = $levelStack[$depth - 1];

                if ($level > $currentLevel) {
                    $html .= "\n<" . $this->listType . '>' . "\n";
                    $levelStack[] = $level;
                } else {
                    while ($depth > 1 && $level <= $levelStack[$depth - 2]) {
                        $html .= '</li>' . "\n";
                        $html .= '</' . $this->listType . '>' . "\n";
                        array_pop($levelStack);
                        $depth--;
                    }

                    $html .= '</li>' . "\n";
                }
            }

            $html .= '<li>';
            $html .= '<a href="#' . StringUtil::escapeHtml($heading['id']) . '">';
            $html .= StringUtil::escapeHtml($heading['text']);
            $html .= '</a>';
            $hasOpenItem = true;
        }

        $html .= '</li>' . "\n";

        $depth = count($levelStack);
        while ($depth > 1) {
            $html .= '</' . $this->listType . '>' . "\n";
            $html .= '</li>' . "\n";
            array_pop($levelStack);
            $depth--;
        }

        $html .= '</' . $this->listType . '>' . "\n";

        return $html;
    }
}
