<?php

declare(strict_types=1);

namespace Djot\Converter;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;

/**
 * Converts HTML to Djot markup
 *
 * Useful for importing HTML content from CMS systems, WYSIWYG editors,
 * or web scraping into Djot format.
 *
 * Key Djot requirements handled:
 * - Blank lines required around block elements (headings, code blocks, lists)
 * - Nested lists require blank line before the nested portion
 */
class HtmlToDjot
{
    protected int $listDepth = 0;

    protected bool $inPre = false;

    /**
     * Convert HTML to Djot markup
     */
    public function convert(string $html): string
    {
        // Reset state
        $this->listDepth = 0;
        $this->inPre = false;

        // Wrap in root element if needed
        if (!preg_match('/<(html|body|div)[^>]*>/i', $html)) {
            $html = '<div>' . $html . '</div>';
        }

        // Load HTML
        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $djot = $this->processNode($doc->documentElement ?? $doc);

        // Clean up
        $djot = $this->cleanup($djot);

        return $djot;
    }

    /**
     * Convert an HTML file to Djot
     *
     * @throws \RuntimeException If file cannot be read
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

        return $this->convert($content);
    }

    protected function processNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            $text = $node->textContent;
            if (!$this->inPre) {
                // Normalize whitespace outside pre blocks
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            }

            return $text;
        }

        if (!($node instanceof DOMElement)) {
            // Process children for other node types
            return $this->processChildren($node);
        }

        $tagName = strtolower($node->tagName);

        return match ($tagName) {
            'html', 'body', 'div', 'article', 'section', 'main', 'header', 'footer', 'nav', 'aside' => $this->processBlock($node),
            'p' => $this->processParagraph($node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->processHeading($node),
            'strong', 'b' => $this->processInlineFormatting($node, '*', '*'),
            'em', 'i' => $this->processInlineFormatting($node, '_', '_'),
            'u', 'ins' => $this->processInlineFormatting($node, '{+', '+}'),
            's', 'strike', 'del' => $this->processInlineFormatting($node, '{-', '-}'),
            'mark' => $this->processInlineFormatting($node, '{=', '=}'),
            'sup' => $this->processInlineFormatting($node, '^', '^'),
            'sub' => $this->processInlineFormatting($node, '~', '~'),
            'code' => $this->processCode($node),
            'pre' => $this->processPreBlock($node),
            'a' => $this->processLink($node),
            'img' => $this->processImage($node),
            'br' => $this->inPre ? "\n" : "\\\n",
            'hr' => "\n\n---\n\n",
            'blockquote' => $this->processBlockquote($node),
            'ul', 'ol' => $this->processList($node),
            'li' => $this->processListItem($node),
            'table' => $this->processTable($node),
            'dl' => $this->processDefinitionList($node),
            'span' => $this->processSpan($node),
            'figure' => $this->processFigure($node),
            'figcaption' => '', // Handled by figure
            'thead', 'tbody', 'tfoot', 'tr', 'th', 'td' => $this->processChildren($node), // Handled by table
            'script', 'style', 'noscript' => '', // Skip these
            default => $this->processChildren($node),
        };
    }

    protected function processChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            $output .= $this->processNode($child);
        }

        return $output;
    }

    protected function processBlock(DOMNode $node): string
    {
        $content = '';
        foreach ($node->childNodes as $child) {
            $result = $this->processNode($child);
            $content .= $result;
        }

        return trim($content);
    }

    protected function processParagraph(DOMElement $node): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        return $content . "\n\n";
    }

    protected function processHeading(DOMElement $node): string
    {
        $level = (int)substr($node->tagName, 1);
        $content = trim($this->processChildren($node));
        $prefix = str_repeat('#', $level) . ' ';

        return $prefix . $content . "\n\n";
    }

    protected function processInlineFormatting(DOMElement $node, string $open, string $close): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        return $open . $content . $close;
    }

    protected function processCode(DOMElement $node): string
    {
        // Check if inside a pre block (handled by processPreBlock)
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'pre') {
            return $node->textContent;
        }

        $content = $node->textContent;

        // Use enough backticks to avoid conflicts
        $backticks = '`';
        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        return $backticks . $content . $backticks;
    }

    protected function processPreBlock(DOMElement $node): string
    {
        $this->inPre = true;

        // Get content (may be wrapped in code tag)
        $code = $node->getElementsByTagName('code')->item(0);
        $content = $code ? $code->textContent : $node->textContent;

        // Detect language from class
        $language = '';
        if ($code instanceof DOMElement) {
            $class = $code->getAttribute('class');
            if (preg_match('/language-(\w+)/', $class, $m)) {
                $language = $m[1];
            } elseif (preg_match('/(\w+)/', $class, $m)) {
                $language = $m[1];
            }
        }

        // Use enough backticks
        $backticks = '```';
        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        $this->inPre = false;

        return "\n" . $backticks . $language . "\n" . rtrim($content) . "\n" . $backticks . "\n\n";
    }

    protected function processLink(DOMElement $node): string
    {
        $href = $node->getAttribute('href');
        $text = trim($this->processChildren($node));
        $title = $node->getAttribute('title');

        if ($text === '') {
            $text = $href;
        }

        if ($title !== '') {
            return '[' . $text . '](' . $href . ' "' . $title . '")';
        }

        return '[' . $text . '](' . $href . ')';
    }

    protected function processImage(DOMElement $node): string
    {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        $title = $node->getAttribute('title');

        if ($title !== '') {
            return '![' . $alt . '](' . $src . ' "' . $title . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    protected function processBlockquote(DOMElement $node): string
    {
        $content = trim($this->processChildren($node));
        $lines = explode("\n", $content);

        $quoted = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $quoted[] = '> ' . $line;
            }
        }

        return "\n" . implode("\n", $quoted) . "\n\n";
    }

    protected function processList(DOMElement $node): string
    {
        $this->listDepth++;
        $isOrdered = strtolower($node->tagName) === 'ol';
        $output = '';
        $counter = 1;

        // Get start attribute for ordered lists
        if ($isOrdered && $node->hasAttribute('start')) {
            $counter = (int)$node->getAttribute('start');
        }

        // Add leading newline for top-level lists to ensure blank line before
        if ($this->listDepth === 1) {
            $output .= "\n";
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $indent = str_repeat('  ', $this->listDepth - 1);
                $prefix = $isOrdered ? $counter . '. ' : '- ';

                // Process list item content, separating text from nested lists
                $textContent = '';
                $nestedContent = '';

                foreach ($child->childNodes as $liChild) {
                    if ($liChild instanceof DOMElement) {
                        $childTag = strtolower($liChild->tagName);
                        if ($childTag === 'ul' || $childTag === 'ol') {
                            // Process nested list separately
                            $nestedContent .= $this->processNode($liChild);
                        } else {
                            $textContent .= $this->processNode($liChild);
                        }
                    } else {
                        $textContent .= $this->processNode($liChild);
                    }
                }

                $textContent = trim($textContent);

                // Handle multi-line text content
                $lines = explode("\n", $textContent);
                $firstLine = array_shift($lines);
                $output .= $indent . $prefix . $firstLine . "\n";

                if ($lines) {
                    $continuation = str_repeat(' ', strlen($prefix));
                    foreach ($lines as $line) {
                        if (trim($line) !== '') {
                            $output .= $indent . $continuation . $line . "\n";
                        }
                    }
                }

                // Add nested list content with blank line before it (required by Djot)
                if ($nestedContent !== '') {
                    $output .= "\n" . $nestedContent;
                }

                $counter++;
            }
        }

        $this->listDepth--;

        // Add trailing newline for top-level lists
        return $output . ($this->listDepth === 0 ? "\n" : '');
    }

    protected function processListItem(DOMElement $node): string
    {
        return $this->processChildren($node);
    }

    protected function processTable(DOMElement $node): string
    {
        $rows = [];
        $headerRow = null;
        $columnCount = 0;

        // Find all rows
        $trElements = $node->getElementsByTagName('tr');

        foreach ($trElements as $tr) {
            $cells = [];
            $isHeader = false;

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement) {
                    $tag = strtolower($cell->tagName);
                    if ($tag === 'th' || $tag === 'td') {
                        $cells[] = trim($this->processChildren($cell));
                        if ($tag === 'th') {
                            $isHeader = true;
                        }
                    }
                }
            }

            if ($cells) {
                $columnCount = max($columnCount, count($cells));
                $row = '| ' . implode(' | ', $cells) . ' |';

                if ($isHeader && $headerRow === null) {
                    $headerRow = $row;
                } else {
                    $rows[] = $row;
                }
            }
        }

        $output = "\n";

        if ($headerRow !== null) {
            $output .= $headerRow . "\n";
            $separator = array_fill(0, $columnCount, '---');
            $output .= '| ' . implode(' | ', $separator) . ' |' . "\n";
        }

        $output .= implode("\n", $rows) . "\n\n";

        return $output;
    }

    protected function processDefinitionList(DOMElement $node): string
    {
        $output = "\n";

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'dt') {
                    $output .= trim($this->processChildren($child)) . "\n";
                } elseif ($tag === 'dd') {
                    $output .= ': ' . trim($this->processChildren($child)) . "\n";
                }
            }
        }

        return $output . "\n";
    }

    protected function processSpan(DOMElement $node): string
    {
        $content = $this->processChildren($node);
        $class = $node->getAttribute('class');
        $id = $node->getAttribute('id');

        // If span has class or id, convert to Djot span syntax
        if ($class !== '' || $id !== '') {
            $attrs = '';
            if ($class !== '') {
                $classes = explode(' ', $class);
                foreach ($classes as $c) {
                    $attrs .= '.' . $c . ' ';
                }
            }
            if ($id !== '') {
                $attrs .= '#' . $id;
            }

            return '[' . $content . ']{' . trim($attrs) . '}';
        }

        return $content;
    }

    protected function processFigure(DOMElement $node): string
    {
        $output = "\n";

        // Find img and figcaption
        $img = $node->getElementsByTagName('img')->item(0);
        $caption = $node->getElementsByTagName('figcaption')->item(0);

        if ($img instanceof DOMElement) {
            $output .= $this->processImage($img);
        }

        if ($caption instanceof DOMElement) {
            $output .= "\n^ " . trim($this->processChildren($caption));
        }

        return $output . "\n\n";
    }

    protected function cleanup(string $djot): string
    {
        // Remove leading whitespace from lines (except in code blocks)
        $lines = explode("\n", $djot);
        $inCodeBlock = false;
        $result = [];

        foreach ($lines as $line) {
            // Track code blocks
            if (str_starts_with(trim($line), '```')) {
                $inCodeBlock = !$inCodeBlock;
                $result[] = $line;

                continue;
            }

            if ($inCodeBlock) {
                $result[] = $line;
            } else {
                // Preserve indentation for list items, trim other leading whitespace
                if (preg_match('/^(\s*)([-*+]|\d+\.)\s/', $line, $m)) {
                    // It's a list item - preserve indentation
                    $result[] = $line;
                } else {
                    // Regular line - trim leading whitespace
                    $result[] = ltrim($line);
                }
            }
        }

        $djot = implode("\n", $result);

        // Normalize multiple blank lines to max 2 (must run after line processing)
        $djot = preg_replace("/\n{3,}/", "\n\n", $djot) ?? $djot;

        // Remove leading/trailing whitespace
        $djot = trim($djot);

        return $djot . "\n";
    }
}
