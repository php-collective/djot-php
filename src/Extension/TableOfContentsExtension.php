<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

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
 * );
 * ```
 */
class TableOfContentsExtension implements ExtensionInterface
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
     */
    public function __construct(
        protected int $minLevel = 1,
        protected int $maxLevel = 6,
        protected string $listType = 'ul',
        protected string $cssClass = 'toc',
    ) {
    }

    /**
     * @return void
     */
    public function register(DjotConverter $converter): void
    {
        // Hook into heading rendering to collect TOC entries
        $converter->on('render.heading', function ($event): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            $level = $node->getLevel();

            if ($level < $this->minLevel || $level > $this->maxLevel) {
                return;
            }

            $text = $this->getPlainText($node);
            $id = $this->generateId($node, $text);

            $this->toc[] = [
                'level' => $level,
                'text' => $text,
                'id' => $id,
            ];
        });
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
     *
     * @return void
     */
    public function clear(): void
    {
        $this->toc = [];
    }

    /**
     * Extract plain text from a node
     */
    protected function getPlainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif (method_exists($child, 'getChildren')) {
                $text .= $this->getPlainText($child);
            }
        }

        return $text;
    }

    /**
     * Generate ID for a heading (matches HtmlRenderer::getSectionId() behavior)
     */
    protected function generateId(Heading $heading, string $text): string
    {
        // Check for explicit ID attribute
        $id = $heading->getAttribute('id');
        if (is_string($id) && $id !== '') {
            return $id;
        }

        // Generate from text - match HtmlRenderer::getSectionId() behavior:
        // 1. Strip # characters entirely
        // 2. Trim whitespace
        // 3. Replace whitespace sequences with single dashes
        if ($text === '') {
            return '';
        }

        $id = str_replace('#', '', $text);
        $id = trim($id);
        $id = preg_replace('/[\s]+/', '-', $id) ?? $id;

        return $id;
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

        $html = '<nav class="' . htmlspecialchars($this->cssClass, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        $html .= $this->renderTocList($headings);
        $html .= '</nav>' . "\n";

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

        // Find the minimum level in the actual headings
        $minActualLevel = min(array_column($headings, 'level'));

        $html = '<' . $this->listType . '>' . "\n";
        $currentLevel = $minActualLevel;
        $openLists = 1;

        foreach ($headings as $i => $heading) {
            $level = $heading['level'];

            // Open new lists for deeper levels
            while ($currentLevel < $level) {
                $html .= '<' . $this->listType . '>' . "\n";
                $openLists++;
                $currentLevel++;
            }

            // Close lists for shallower levels
            while ($currentLevel > $level) {
                $html .= '</li>' . "\n";
                $html .= '</' . $this->listType . '>' . "\n";
                $openLists--;
                $currentLevel--;
            }

            // Close previous item at same level (except first)
            if ($i > 0 && $headings[$i - 1]['level'] >= $level) {
                $html .= '</li>' . "\n";
            }

            // Render the item
            $html .= '<li>';
            $html .= '<a href="#' . htmlspecialchars($heading['id'], ENT_QUOTES, 'UTF-8') . '">';
            $html .= htmlspecialchars($heading['text'], ENT_QUOTES, 'UTF-8');
            $html .= '</a>';
        }

        // Close all remaining open elements
        while ($openLists > 0) {
            $html .= '</li>' . "\n";
            $html .= '</' . $this->listType . '>' . "\n";
            $openLists--;
        }

        return $html;
    }
}
