<?php

declare(strict_types=1);

namespace Djot\Converter;

use Djot\Util\StringUtil;
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
     * Attributes to skip when converting (these don't translate well to Djot)
     *
     * @var array<string>
     */
    protected array $skipAttributes = [
        'style', // CSS doesn't map to Djot
        'onclick', 'onload', 'onmouseover', 'onmouseout', 'onsubmit', // JS events
        'xmlns', // XML namespace
        'role', // ARIA (could be kept, but often noise)
    ];

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
            'html', 'body', 'div', 'article', 'section', 'main', 'header', 'footer', 'nav', 'aside',
            'address', 'details', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search' => $this->processBlock($node),
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
            'hr' => $this->processHr($node),
            'blockquote' => $this->processBlockquote($node),
            'ul', 'ol' => $this->processList($node),
            'li' => $this->processListItem($node),
            'table' => $this->processTable($node),
            'dl' => $this->processDefinitionList($node),
            'span' => $this->processSpan($node),
            'figure' => $this->processFigure($node),
            'figcaption' => '', // Handled by figure
            'caption' => '', // Handled by table
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

    /**
     * Block-level elements that should break implicit paragraphs
     *
     * @var array<string>
     */
    protected array $blockElements = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote',
        'ul', 'ol', 'li', 'table', 'dl', 'hr', 'div', 'section',
        'article', 'header', 'footer', 'nav', 'aside', 'figure', 'main',
        'address', 'details', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search',
    ];

    protected function processBlock(DOMNode $node): string
    {
        $content = '';
        $inlineBuffer = '';

        foreach ($node->childNodes as $child) {
            $isBlock = false;

            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);
                $isBlock = in_array($tagName, $this->blockElements, true);
            }

            if ($isBlock) {
                // Flush any accumulated inline content as an implicit paragraph
                $inlineText = trim($inlineBuffer);
                if ($inlineText !== '') {
                    $content .= $inlineText . "\n\n";
                }
                $inlineBuffer = '';

                // Process the block element
                $content .= $this->processNode($child);
            } else {
                // Accumulate inline content
                $inlineBuffer .= $this->processNode($child);
            }
        }

        // Flush any remaining inline content
        $inlineText = trim($inlineBuffer);
        if ($inlineText !== '') {
            $content .= $inlineText . "\n\n";
        }

        return trim($content);
    }

    protected function processParagraph(DOMElement $node): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        $attrs = $this->formatBlockAttributes($node);

        return $attrs . $content . "\n\n";
    }

    protected function processHeading(DOMElement $node): string
    {
        $level = (int)substr($node->tagName, 1);
        $content = trim($this->processChildren($node));
        $prefix = str_repeat('#', $level) . ' ';
        $attrs = $this->formatBlockAttributes($node);

        return $attrs . $prefix . $content . "\n\n";
    }

    protected function processInlineFormatting(DOMElement $node, string $open, string $close): string
    {
        $content = trim($this->processChildren($node));
        if ($content === '') {
            return '';
        }

        $attrs = $this->formatInlineAttributes($node);

        return $open . $content . $close . $attrs;
    }

    protected function processCode(DOMElement $node): string
    {
        // Check if inside a pre block (handled by processPreBlock)
        $parent = $node->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'pre') {
            return $node->textContent;
        }

        $content = $node->textContent;

        $backticks = StringUtil::findSafeCodeFence($content, 1);
        $attrs = $this->formatInlineAttributes($node);

        return $backticks . $content . $backticks . $attrs;
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

        $backticks = StringUtil::findSafeCodeFence($content, 3);
        $this->inPre = false;

        // Get attributes from pre element (skip class on code since used for language)
        $attrs = $this->formatBlockAttributes($node);

        return $attrs . "\n" . $backticks . $language . "\n" . rtrim($content) . "\n" . $backticks . "\n\n";
    }

    protected function processLink(DOMElement $node): string
    {
        $href = $node->getAttribute('href');
        $text = trim($this->processChildren($node));
        $title = $node->getAttribute('title');

        if ($text === '') {
            $text = $href;
        }

        // Skip href and title since they're in the link syntax
        $attrs = $this->formatInlineAttributes($node, ['href', 'title']);

        if ($title !== '') {
            return '[' . $text . '](' . $href . ' "' . $title . '")' . $attrs;
        }

        return '[' . $text . '](' . $href . ')' . $attrs;
    }

    protected function processImage(DOMElement $node): string
    {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        $title = $node->getAttribute('title');

        // Skip src, alt, title since they're in the image syntax
        $attrs = $this->formatInlineAttributes($node, ['src', 'alt', 'title']);

        if ($title !== '') {
            return '![' . $alt . '](' . $src . ' "' . $title . '")' . $attrs;
        }

        return '![' . $alt . '](' . $src . ')' . $attrs;
    }

    protected function processHr(DOMNode $node): string
    {
        $char = '-';
        if ($node instanceof DOMElement && $node->hasAttribute('data-char')) {
            $char = $node->getAttribute('data-char');
        }

        return "\n\n" . str_repeat($char, 3) . "\n\n";
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

        $attrs = $this->formatBlockAttributes($node);

        return $attrs . "\n" . implode("\n", $quoted) . "\n\n";
    }

    protected function processList(DOMElement $node): string
    {
        $this->listDepth++;
        $isOrdered = strtolower($node->tagName) === 'ol';
        $isTaskList = $node->getAttribute('class') === 'task-list';
        $output = '';
        $counter = 1;

        // Get start attribute for ordered lists
        if ($isOrdered && $node->hasAttribute('start')) {
            $counter = (int)$node->getAttribute('start');
        }

        // Get marker from data attribute (for round-trip fidelity)
        $marker = $node->getAttribute('data-marker');
        if ($isOrdered) {
            $marker = $marker ?: '.';
        } else {
            $marker = $marker ?: '-';
        }

        // Add leading newline for top-level lists to ensure blank line before
        if ($this->listDepth === 1) {
            // Add list-level attributes (skip 'start', 'data-marker', 'class' for task-list)
            $skipAttrs = $isOrdered ? ['start', 'data-marker'] : ['data-marker'];
            if ($isTaskList) {
                $skipAttrs[] = 'class';
            }
            $listAttrs = $this->formatBlockAttributes($node, $skipAttrs);
            $output .= $listAttrs . "\n";
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $indent = str_repeat('  ', $this->listDepth - 1);

                // Check for task list item (has checkbox input)
                $checkbox = '';
                if ($isTaskList || $this->hasCheckbox($child)) {
                    $isChecked = $this->isCheckboxChecked($child);
                    $checkbox = $isChecked ? '[x] ' : '[ ] ';
                }

                $prefix = $isOrdered ? $counter . $marker . ' ' : $marker . ' ' . $checkbox;

                // Process list item content, separating text from nested lists
                $textContent = '';
                $nestedContent = '';

                foreach ($child->childNodes as $liChild) {
                    if ($liChild instanceof DOMElement) {
                        $childTag = strtolower($liChild->tagName);
                        if ($childTag === 'ul' || $childTag === 'ol') {
                            // Process nested list separately
                            $nestedContent .= $this->processNode($liChild);
                        } elseif ($childTag === 'input' && $liChild->getAttribute('type') === 'checkbox') {
                            // Skip checkbox inputs (handled via $checkbox prefix)
                            continue;
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

                // Add list item attributes on next line (indented)
                $liAttrs = $this->getElementAttributes($child);
                if ($liAttrs !== '') {
                    $output .= $indent . str_repeat(' ', strlen($prefix)) . '{' . $liAttrs . "}\n";
                }

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

    /**
     * Check if a list item contains a checkbox input
     */
    protected function hasCheckbox(DOMElement $li): bool
    {
        $inputs = $li->getElementsByTagName('input');
        foreach ($inputs as $input) {
            if ($input->getAttribute('type') === 'checkbox') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a list item's checkbox is checked
     */
    protected function isCheckboxChecked(DOMElement $li): bool
    {
        $inputs = $li->getElementsByTagName('input');
        foreach ($inputs as $input) {
            if ($input->getAttribute('type') === 'checkbox') {
                return $input->hasAttribute('checked');
            }
        }

        return false;
    }

    /**
     * Remove checkbox input from content when processing task list items
     */
    protected function processListItemContent(DOMElement $li, bool $isTaskList): string
    {
        $content = '';
        foreach ($li->childNodes as $child) {
            // Skip checkbox inputs in task lists
            if ($isTaskList && $child instanceof DOMElement) {
                if ($child->tagName === 'input' && $child->getAttribute('type') === 'checkbox') {
                    continue;
                }
            }
            $content .= $this->processNode($child);
        }

        return $content;
    }

    protected function processTable(DOMElement $node): string
    {
        $rows = [];
        $headerRow = null;
        $headerRowAttrs = '';
        $columnCount = 0;
        $captionText = '';

        // Find caption element if present
        $captionElement = $node->getElementsByTagName('caption')->item(0);
        if ($captionElement instanceof DOMElement) {
            $captionText = trim($this->processChildren($captionElement));
        }

        // Find all rows
        $trElements = $node->getElementsByTagName('tr');

        foreach ($trElements as $tr) {
            $cells = [];
            $isHeader = false;

            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement) {
                    $tag = strtolower($cell->tagName);
                    if ($tag === 'th' || $tag === 'td') {
                        // Get cell content with cell attributes
                        $cellContent = trim($this->processChildren($cell));
                        $cellAttrs = $this->getElementAttributes($cell);
                        if ($cellAttrs !== '') {
                            // Cell attributes go after opening pipe: |{.class} content |
                            $cells[] = '{' . $cellAttrs . '} ' . $cellContent;
                        } else {
                            $cells[] = $cellContent;
                        }
                        if ($tag === 'th') {
                            $isHeader = true;
                        }
                    }
                }
            }

            if ($cells) {
                $columnCount = max($columnCount, count($cells));

                // Get row attributes
                $rowAttrs = $this->getElementAttributes($tr);
                $rowAttrSuffix = $rowAttrs !== '' ? '{' . $rowAttrs . '}' : '';

                $row = '| ' . implode(' | ', $cells) . ' |' . $rowAttrSuffix;

                if ($isHeader && $headerRow === null) {
                    $headerRow = $row;
                    $headerRowAttrs = $rowAttrSuffix;
                } else {
                    $rows[] = $row;
                }
            }
        }

        // Table-level attributes
        $tableAttrs = $this->formatBlockAttributes($node);
        $output = $tableAttrs . "\n";

        if ($headerRow !== null) {
            $output .= $headerRow . "\n";
            $separator = array_fill(0, $columnCount, '---');
            $output .= '| ' . implode(' | ', $separator) . ' |' . "\n";
        }

        $output .= implode("\n", $rows) . "\n";

        // Add caption after table
        if ($captionText !== '') {
            $output .= '^ ' . $captionText . "\n";
        }

        return $output . "\n";
    }

    protected function processDefinitionList(DOMElement $node): string
    {
        // Definition list level attributes
        $dlAttrs = $this->formatBlockAttributes($node);
        $output = $dlAttrs . "\n";
        $lastWasTerm = false;
        $ddCount = 0;

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'dt') {
                    // Term: `: term` format
                    $output .= ': ' . trim($this->processChildren($child)) . "\n";
                    // dt attributes on next line
                    $dtAttrs = $this->getElementAttributes($child);
                    if ($dtAttrs !== '') {
                        $output .= '{' . $dtAttrs . "}\n";
                    }
                    $lastWasTerm = true;
                    $ddCount = 0;
                } elseif ($tag === 'dd') {
                    // Definition: indented content after blank line
                    if ($lastWasTerm) {
                        $output .= "\n";
                    } elseif ($ddCount > 0) {
                        // Multiple dd elements for same term - use continuation marker
                        $output .= ": +\n\n";
                    }
                    $content = trim($this->processChildren($child));
                    // Indent definition content
                    $lines = explode("\n", $content);
                    foreach ($lines as $line) {
                        $output .= '  ' . $line . "\n";
                    }
                    // Output dd attributes as last indented line (after content)
                    $ddAttrs = $this->getElementAttributes($child);
                    if ($ddAttrs !== '') {
                        $output .= '  {' . $ddAttrs . "}\n";
                    }
                    // Add blank line after dd for visual separation
                    $output .= "\n";
                    $lastWasTerm = false;
                    $ddCount++;
                }
            }
        }

        return $output . "\n";
    }

    protected function processSpan(DOMElement $node): string
    {
        $content = $this->processChildren($node);

        // Use getElementAttributes to get all attributes including data-*
        $attrs = $this->getElementAttributes($node);

        // If span has any attributes, convert to Djot span syntax
        if ($attrs !== '') {
            return '[' . $content . ']{' . $attrs . '}';
        }

        return $content;
    }

    protected function processFigure(DOMElement $node): string
    {
        $output = "\n";

        // Find img, blockquote, and figcaption
        $img = $node->getElementsByTagName('img')->item(0);
        $blockquote = $node->getElementsByTagName('blockquote')->item(0);
        $caption = $node->getElementsByTagName('figcaption')->item(0);

        if ($img instanceof DOMElement) {
            $output .= $this->processImage($img) . "\n";
        } elseif ($blockquote instanceof DOMElement) {
            $output .= $this->processBlockquote($blockquote);
            // Remove the trailing blank line since caption follows immediately
            $output = rtrim($output) . "\n";
        }

        if ($caption instanceof DOMElement) {
            $output .= '^ ' . trim($this->processChildren($caption));
        }

        return $output . "\n\n";
    }

    /**
     * Format element attributes as Djot block attribute syntax.
     * Returns empty string if no relevant attributes.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip for this element
     *
     * @return string Djot attribute block like "{#id .class key=value}\n" or ""
     */
    protected function formatBlockAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        $attrs = $this->getElementAttributes($node, $skipAttrs);
        if (!$attrs) {
            return '';
        }

        return '{' . $attrs . "}\n";
    }

    /**
     * Format element attributes as Djot inline attribute syntax.
     * Returns empty string if no relevant attributes.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip for this element
     *
     * @return string Djot inline attributes like "{#id .class}" or ""
     */
    protected function formatInlineAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        $attrs = $this->getElementAttributes($node, $skipAttrs);
        if (!$attrs) {
            return '';
        }

        return '{' . $attrs . '}';
    }

    /**
     * Extract and format attributes from a DOM element.
     *
     * @param \DOMElement $node The element to extract attributes from
     * @param array<string> $skipAttrs Additional attributes to skip
     *
     * @return string Formatted attributes (without braces) or empty string
     */
    protected function getElementAttributes(DOMElement $node, array $skipAttrs = []): string
    {
        $parts = [];
        $allSkip = array_merge($this->skipAttributes, $skipAttrs);

        // Process id first
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }

        // Process class (if not skipped)
        if (!in_array('class', $allSkip, true)) {
            $class = $node->getAttribute('class');
            if ($class !== '') {
                $classes = preg_split('/\s+/', trim($class));
                if ($classes) {
                    foreach ($classes as $c) {
                        if ($c !== '') {
                            $parts[] = '.' . $c;
                        }
                    }
                }
            }
        }

        // Process other attributes
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;

            // Skip already processed and skip-list attributes
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            if (in_array($name, $allSkip, true)) {
                continue;
            }

            $value = $attr->value;
            if ($value === '') {
                // Boolean attribute
                $parts[] = $name;
            } else {
                // Quote value if it contains spaces or special chars
                if (preg_match('/[\s"\'{}]/', $value)) {
                    $parts[] = $name . '="' . str_replace('"', '\\"', $value) . '"';
                } else {
                    $parts[] = $name . '=' . $value;
                }
            }
        }

        return implode(' ', $parts);
    }

    protected function cleanup(string $djot): string
    {
        // Remove leading whitespace from lines (except in code blocks and indented content)
        $lines = explode("\n", $djot);
        $inCodeBlock = false;
        $inDefinitionList = false;
        $inList = false;
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

                continue;
            }

            // Track definition lists (`: term` starts one)
            if (preg_match('/^: /', $line)) {
                $inDefinitionList = true;
                $inList = false;
                $result[] = $line;

                continue;
            }

            // Preserve indentation for list items and track list context
            if (preg_match('/^(\s*)([-*+]|\d+\.)\s/', $line, $m)) {
                $result[] = $line;
                $inDefinitionList = false;
                $inList = true;

                continue;
            }

            // Preserve indented attribute blocks after list items (li attributes)
            if ($inList && preg_match('/^\s+\{[^{}]+\}\s*$/', $line)) {
                $result[] = $line;

                continue;
            }

            // Preserve indentation for definition content (indented lines after `: term`)
            if ($inDefinitionList && preg_match('/^  /', $line)) {
                $result[] = $line;

                continue;
            }

            // Preserve standalone attribute blocks in definition lists (dt/dd attributes)
            if ($inDefinitionList && preg_match('/^\{[^{}]+\}\s*$/', $line)) {
                $result[] = $line;

                continue;
            }

            // Blank line (or whitespace-only line) ends definition list context but not list context
            if (trim($line) === '') {
                $result[] = ''; // Normalize to empty string

                continue;
            }

            // Regular line - trim leading whitespace and reset contexts
            $result[] = ltrim($line);
            $inDefinitionList = false;
            $inList = false;
        }

        $djot = implode("\n", $result);

        // Normalize multiple blank lines to max 2 (must run after line processing)
        $djot = preg_replace("/\n{3,}/", "\n\n", $djot) ?? $djot;

        // Remove leading/trailing whitespace
        $djot = trim($djot);

        return $djot . "\n";
    }
}
