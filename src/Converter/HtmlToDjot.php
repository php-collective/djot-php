<?php

declare(strict_types=1);

namespace Djot\Converter;

use Djot\Node\Block\TableCell;
use Djot\Util\StringUtil;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
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

    protected bool $preserveTextWhitespace = false;

    /**
     * Collected reference definitions for round-trip support
     * Maps reference label => url
     *
     * @var array<string, string>
     */
    protected array $referenceDefinitions = [];

    /**
     * Collected footnote definitions for round-trip support
     * Maps footnote label => content
     *
     * @var array<string, string>
     */
    protected array $footnoteDefinitions = [];

    /**
     * Collected abbreviation definitions for round-trip support
     *
     * Stores complete definition lines in Djot format: "*[ABBR]: Definition"
     *
     * @var array<string>
     */
    protected array $abbreviationDefinitions = [];

    /**
     * Abbreviation definition lookup for round-trip preservation.
     *
     * @var array<string, string>
     */
    protected array $abbreviationMap = [];

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
        $this->preserveTextWhitespace = false;
        $this->referenceDefinitions = [];
        $this->footnoteDefinitions = [];
        $this->abbreviationDefinitions = [];
        $this->abbreviationMap = [];

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

        // Extract abbreviation definitions from template element (round-trip support)
        $this->extractAbbreviationDefinitions($doc);

        $djot = $this->processNode($doc->documentElement ?? $doc);

        // Prepend abbreviation definitions at the start
        if ($this->abbreviationDefinitions !== []) {
            $abbrevs = implode("\n", $this->abbreviationDefinitions) . "\n\n";
            $djot = $abbrevs . $djot;
        }

        // Append reference definitions collected during conversion
        if ($this->referenceDefinitions !== []) {
            // Ensure blank line before reference definitions
            $refs = "\n\n";
            foreach ($this->referenceDefinitions as $label => $url) {
                $refs .= '[' . $label . ']: ' . $url . "\n";
            }
            $djot .= $refs;
        }

        // Append footnote definitions collected during conversion
        if ($this->footnoteDefinitions !== []) {
            // Ensure blank line before footnote definitions
            $notes = "\n\n";
            foreach ($this->footnoteDefinitions as $label => $content) {
                $notes .= $this->formatFootnoteDefinition($label, $content) . "\n";
            }
            $djot .= $notes;
        }

        // Clean up
        $djot = $this->cleanup($djot);

        return $djot;
    }

    /**
     * Extract abbreviation definitions from template element
     */
    protected function extractAbbreviationDefinitions(DOMDocument $doc): void
    {
        $templates = $doc->getElementsByTagName('template');
        foreach ($templates as $template) {
            if ($template->hasAttribute('data-djot-abbreviations')) {
                $content = $template->textContent;
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $this->abbreviationDefinitions[] = $line;
                        if (preg_match('/^\*\[([^\]]+)\]:\s*(.*)$/', $line, $matches) === 1) {
                            $this->abbreviationMap[$matches[1]] = $matches[2];
                        }
                    }
                }
                // Remove the template element so it's not processed
                $template->parentNode?->removeChild($template);

                break;
            }
        }
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

    /**
     * @phpstan-impure
     */
    protected function processNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            $text = $node->textContent;
            if (!$this->inPre && !$this->preserveTextWhitespace) {
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

        $djotSrc = $this->extractRoundTripSource($node, $tagName);
        if ($djotSrc !== null) {
            return $djotSrc;
        }

        if ($tagName === 'section' && $this->isInlineOnlyEndnotesSection($node)) {
            return '';
        }

        return match ($tagName) {
            'section' => $this->processSection($node),
            'html', 'body', 'article', 'main', 'header', 'footer', 'nav', 'aside',
            'address', 'dialog', 'fieldset', 'form', 'hgroup', 'menu', 'search' => $this->processBlock($node),
            'details' => $this->processDetails($node),
            'div' => $this->processDiv($node),
            'p' => $this->processParagraph($node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->processHeading($node),
            'strong', 'b' => $this->processInlineFormatting($node, '*', '*'),
            'em', 'i' => $this->processInlineFormatting($node, '_', '_'),
            'u', 'ins' => $this->processInlineFormatting($node, '{+', '+}'),
            's', 'strike', 'del' => $this->processInlineFormatting($node, '{-', '-}'),
            'mark' => $this->processInlineFormatting($node, '{=', '=}'),
            'sup' => $this->processInlineFormatting($node, '^', '^'),
            'sub' => $this->processInlineFormatting($node, '~', '~'),
            'kbd' => $this->processSemanticSpan($node, 'kbd'),
            'dfn' => $this->processSemanticSpan($node, 'dfn'),
            'abbr' => $this->processSemanticSpan($node, 'abbr'),
            'samp' => $this->processSemanticSpan($node, 'samp'),
            'var' => $this->processSemanticSpan($node, 'var'),
            'q' => $this->processInlineQuote($node),
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
            'math' => $this->processMath($node),
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

    /**
     * Process section elements, handling explicit IDs for round-trip support
     */
    protected function processSection(DOMElement $node): string
    {
        // Check if this is a footnotes section (doc-endnotes)
        if ($node->getAttribute('role') === 'doc-endnotes') {
            return $this->processEndnotesSection($node);
        }

        // Check if section has an explicit ID from round-trip mode
        $hasExplicitId = $node->hasAttribute('data-djot-explicit-id');
        $sectionId = $node->getAttribute('id');

        // Find the first heading inside this section
        $firstHeading = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                    $firstHeading = $child;

                    break;
                }
            }
        }

        // If we have an explicit ID and a heading, combine ID with heading's attributes
        $prefix = '';
        if ($hasExplicitId && $sectionId !== '' && $firstHeading !== null) {
            // Get heading's attributes (class, etc.) excluding id
            $headingAttrs = $this->getElementAttributes($firstHeading, ['id', 'data-djot-explicit-id', 'data-djot-source-level']);

            // Build combined attribute block with ID first
            $attrParts = ['#' . $sectionId];
            if ($headingAttrs !== '') {
                // Add other attributes (already formatted with . prefix for classes)
                $attrParts[] = $headingAttrs;
            }
            $prefix = '{' . implode(' ', $attrParts) . "}\n";

            // Mark that we've handled the heading's attributes
            $firstHeading->setAttribute('data-djot-attrs-handled', '1');
        }

        // Process section content as a normal block
        $content = $this->processBlock($node);

        return $prefix . $content;
    }

    protected function processDiv(DOMElement $node): string
    {
        // Check for admonition div (round-trip support)
        if ($node->hasAttribute('data-djot-admonition-type')) {
            return $this->processAdmonition($node);
        }

        // Check for line block (round-trip support)
        if ($this->hasClass($node, 'line-block')) {
            return $this->processLineBlock($node);
        }

        $classes = $this->getElementClassList($node);
        $fenceClass = array_shift($classes);

        // Check for wrapper div unwrapping: if div has NO class but has attrs
        // and single block child, apply attrs to the child instead of fenced div
        if ($fenceClass === null || $fenceClass === '') {
            $singleChild = $this->getSingleBlockChild($node);
            if ($singleChild !== null) {
                $attrs = $this->formatBlockAttributes($node);
                if ($attrs !== '') {
                    $content = trim($this->processNode($singleChild));

                    return $attrs . $content . "\n";
                }
            }
        }

        if ($fenceClass === null || $fenceClass === '') {
            $attrs = $this->formatBlockAttributes($node);
            if ($attrs === '') {
                return $this->processBlock($node);
            }

            $content = trim($this->processChildren($node));
            $output = $attrs . ":::\n";
            if ($content !== '') {
                $output .= $content . "\n";
            }

            return $output . ":::\n\n";
        }
        if ($fenceClass === 'djot-content' && $classes === [] && $node->getAttribute('id') === '') {
            $hasExtraAttrs = false;
            /** @var \DOMAttr $attr */
            foreach ($node->attributes as $attr) {
                if ($attr->name !== 'class' && !in_array($attr->name, $this->skipAttributes, true) && !str_starts_with($attr->name, 'data-djot-')) {
                    $hasExtraAttrs = true;

                    break;
                }
            }
            if (!$hasExtraAttrs) {
                return $this->processBlock($node);
            }
        }

        $content = trim($this->processChildren($node));
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }
        foreach ($classes as $class) {
            $parts[] = '.' . $class;
        }
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if ($name === 'id' || $name === 'class' || in_array($name, $this->skipAttributes, true) || str_starts_with($name, 'data-djot-')) {
                continue;
            }
            $value = $attr->value;
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }
        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $output = $attrs . '::: ' . $fenceClass . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . ":::\n\n";
    }

    /**
     * Process admonition div (with data-djot-admonition-type) for round-trip
     */
    protected function processAdmonition(DOMElement $node): string
    {
        $type = $node->getAttribute('data-djot-admonition-type');
        $customTitle = $node->getAttribute('data-djot-admonition-title');

        // Build attributes (excluding admonition-specific classes and data attributes)
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }

        // Add custom title if provided
        if ($customTitle !== '') {
            $parts[] = 'title=' . $this->quoteAttributeValue($customTitle);
        }

        // Get remaining classes (exclude 'admonition' and the type)
        $classes = $this->getElementClassList($node);
        foreach ($classes as $class) {
            if ($class !== 'admonition' && $class !== $type) {
                $parts[] = '.' . $class;
            }
        }

        // Add other attributes (excluding special ones)
        $skipAttrs = ['id', 'class', 'data-djot-admonition-type', 'data-djot-admonition-title', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
                continue;
            }
            $value = $attr->value;
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Process content, excluding the title element
        $content = $this->processAdmonitionContent($node);

        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $output = $attrs . '::: ' . $type . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . ":::\n\n";
    }

    /**
     * Process admonition content, excluding the title element
     */
    protected function processAdmonitionContent(DOMElement $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                // Skip the title element (p.admonition-title or summary)
                if ($tag === 'p' && $this->hasClass($child, 'admonition-title')) {
                    continue;
                }
                if ($tag === 'summary') {
                    continue;
                }
            }
            $output .= $this->processNode($child);
        }

        return trim($output);
    }

    /**
     * Process details element (potentially collapsible admonition)
     */
    protected function processDetails(DOMElement $node): string
    {
        // Check for collapsible admonition (round-trip support)
        if ($node->hasAttribute('data-djot-admonition-type')) {
            return $this->processCollapsibleAdmonition($node);
        }

        // Otherwise treat as regular block
        return $this->processBlock($node);
    }

    /**
     * Process collapsible admonition (details element with data-djot-admonition-type)
     */
    protected function processCollapsibleAdmonition(DOMElement $node): string
    {
        $type = $node->getAttribute('data-djot-admonition-type');
        $customTitle = $node->getAttribute('data-djot-admonition-title');
        $isOpen = $node->hasAttribute('open');

        // Build attributes
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }

        // Add collapsible attribute
        $parts[] = $isOpen ? 'collapsible=open' : 'collapsible';

        // Add custom title if provided
        if ($customTitle !== '') {
            $parts[] = 'title=' . $this->quoteAttributeValue($customTitle);
        }

        // Get remaining classes (exclude 'admonition' and the type)
        $classes = $this->getElementClassList($node);
        foreach ($classes as $class) {
            if ($class !== 'admonition' && $class !== $type) {
                $parts[] = '.' . $class;
            }
        }

        // Add other attributes (excluding special ones)
        $skipAttrs = ['id', 'class', 'open', 'data-djot-admonition-type', 'data-djot-admonition-title', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
                continue;
            }
            $value = $attr->value;
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Process content, excluding the summary (title) element
        $content = $this->processAdmonitionContent($node);

        // Collapsible admonitions always have at least the 'collapsible' attribute
        $attrs = '{' . implode(' ', $parts) . "}\n";
        $output = $attrs . '::: ' . $type . "\n";
        if ($content !== '') {
            $output .= $content . "\n";
        }

        return $output . ":::\n\n";
    }

    /**
     * Process line block div (with class "line-block") for round-trip
     */
    protected function processLineBlock(DOMElement $node): string
    {
        // Build attributes (excluding 'line-block' class)
        $parts = [];
        $id = $node->getAttribute('id');
        if ($id !== '') {
            $parts[] = '#' . $id;
        }

        // Get remaining classes (exclude 'line-block')
        $classes = $this->getElementClassList($node);
        foreach ($classes as $class) {
            if ($class !== 'line-block') {
                $parts[] = '.' . $class;
            }
        }

        // Add other attributes (excluding special ones)
        $skipAttrs = ['id', 'class', ...$this->skipAttributes];
        /** @var \DOMAttr $attr */
        foreach ($node->attributes as $attr) {
            $name = $attr->name;
            if (in_array($name, $skipAttrs, true) || str_starts_with($name, 'data-djot-')) {
                continue;
            }
            $value = $attr->value;
            $parts[] = $value === '' ? $name : $name . '=' . $this->quoteAttributeValue($value);
        }

        // Extract lines from the content - handle <br> as line separators
        $lines = $this->extractLineBlockLines($node);

        $attrs = $parts === [] ? '' : '{' . implode(' ', $parts) . "}\n";
        $output = $attrs;

        foreach ($lines as $line) {
            $output .= '| ' . $line . "\n";
        }

        return $output . "\n";
    }

    /**
     * Extract lines from a line block, handling <br> elements as separators
     *
     * @return array<string>
     */
    protected function extractLineBlockLines(DOMElement $node): array
    {
        $lines = [];
        $currentLine = '';

        $processNode = function (DOMNode $child) use (&$lines, &$currentLine): void {
            if ($child instanceof DOMText) {
                $text = $child->textContent;
                // Normalize whitespace but preserve content
                $text = preg_replace('/\s+/', ' ', $text) ?? $text;
                $currentLine .= $text;
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    // <br> marks end of current line
                    $lines[] = trim($currentLine);
                    $currentLine = '';
                } else {
                    // Process other elements inline (strong, em, etc.)
                    $currentLine .= $this->processNode($child);
                }
            }
        };

        // Find inner content (may be wrapped in <p> or direct children)
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'p') {
                // Process paragraph's children
                foreach ($child->childNodes as $pChild) {
                    $processNode($pChild);
                }

                if (trim($currentLine) !== '') {
                    $lines[] = trim($currentLine);
                    $currentLine = '';
                }
            } else {
                $processNode($child);
            }
        }

        // Don't forget the last line if any content remains
        if (trim($currentLine) !== '') {
            $lines[] = trim($currentLine);
        }

        return $lines;
    }

    /**
     * Check if an element has a specific class
     */
    protected function hasClass(DOMElement $node, string $className): bool
    {
        $classes = $this->getElementClassList($node);

        return in_array($className, $classes, true);
    }

    /**
     * @return list<string>
     */
    protected function getElementClassList(DOMElement $node): array
    {
        $classes = trim($node->getAttribute('class'));
        if ($classes === '') {
            return [];
        }

        $classList = preg_split('/\s+/', $classes) ?: [];

        return array_values(array_filter($classList, static fn (string $class): bool => $class !== ''));
    }

    protected function quoteAttributeValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function quoteLinkTitle(string $title): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
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
        if ($node->hasAttribute('data-djot-source-level')) {
            $level = max(1, min(6, (int)$node->getAttribute('data-djot-source-level')));
        }
        $content = trim($this->processChildren($node));
        $prefix = str_repeat('#', $level) . ' ';

        // Check if attributes were already handled by processSection
        if ($node->hasAttribute('data-djot-attrs-handled')) {
            return $prefix . $content . "\n\n";
        }

        // Skip ID attribute unless it was explicitly set (marked by data-djot-explicit-id)
        // Auto-generated IDs should not be preserved in round-trip
        $skipAttrs = ['data-djot-source-level', 'data-djot-explicit-id', 'data-djot-attrs-handled'];
        if (!$node->hasAttribute('data-djot-explicit-id')) {
            $skipAttrs[] = 'id';
        }
        $attrs = $this->formatBlockAttributes($node, $skipAttrs);

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

        // Add spaces around content if needed to distinguish from fence
        // Only add space if content starts/ends with backtick but NOT already a space
        // (If there's already a space, it was preserved from the original)
        if (strlen($backticks) > 1) {
            $needsStartSpace = str_starts_with($content, '`') && !str_starts_with($content, ' ');
            $needsEndSpace = str_ends_with($content, '`') && !str_ends_with($content, ' ');

            if ($needsStartSpace || $needsEndSpace) {
                $start = $needsStartSpace ? ' ' : '';
                $end = $needsEndSpace ? ' ' : '';

                return $backticks . $start . $content . $end . $backticks . $attrs;
            }
        }

        return $backticks . $content . $backticks . $attrs;
    }

    protected function processPreBlock(DOMElement $node): string
    {
        $this->inPre = true;

        // Get content (may be wrapped in code tag)
        $code = $this->findFirstDirectChildByTagName($node, 'code');
        $content = $code ? $code->textContent : $node->textContent;

        // Detect language from class
        $language = '';
        if ($code instanceof DOMElement) {
            $classList = $this->getElementClassList($code);
            foreach ($classList as $class) {
                if (str_starts_with($class, 'language-') && $class !== 'language-') {
                    $language = substr($class, 9);

                    break;
                }
            }

            if ($language === '' && $classList !== []) {
                $language = $classList[0];
            }
        }

        $backticks = StringUtil::findSafeCodeFence($content, 3);
        $this->inPre = false;

        // Get attributes from pre element (skip class on code since used for language)
        $attrs = $this->formatBlockAttributes($node);

        return $attrs . "\n" . $backticks . $language . "\n" . rtrim($content) . "\n" . $backticks . "\n\n";
    }

    protected function extractRoundTripSource(DOMElement $node, string $tagName): ?string
    {
        if (!$node->hasAttribute('data-djot-src')) {
            return null;
        }

        if ($tagName !== 'pre' && !in_array($tagName, $this->blockElements, true)) {
            return null;
        }

        $source = html_entity_decode($node->getAttribute('data-djot-src'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return rtrim($source, "\n") . "\n\n";
    }

    protected function processLink(DOMElement $node): string
    {
        if ($node->hasAttribute('data-djot-heading-ref')) {
            $target = $node->getAttribute('data-djot-heading-ref');
            $displayText = $node->getAttribute('data-djot-heading-ref-display');
            if ($displayText === '') {
                $displayText = trim($this->processChildren($node));
            }

            return $displayText === $target
                ? '[[' . $target . ']]'
                : '[[' . $target . '|' . $displayText . ']]';
        }

        // Check for regular footnote reference (round-trip support)
        if ($node->hasAttribute('data-djot-footnote-label')) {
            $label = $node->getAttribute('data-djot-footnote-label');

            return '[^' . $label . ']';
        }

        if ($node->hasAttribute('data-djot-inline-footnote-html')) {
            $html = $node->getAttribute('data-djot-inline-footnote-html');
            preg_match('/^\s+/u', $html, $leadingWhitespaceMatch);
            preg_match('/\s+$/u', $html, $trailingWhitespaceMatch);

            $leadingWhitespace = $leadingWhitespaceMatch[0] ?? '';
            $trailingWhitespace = $trailingWhitespaceMatch[0] ?? '';
            $trimmedHtml = preg_replace('/^\s+|\s+$/u', '', $html) ?? $html;
            $content = $this->convertInlineFragmentToDjot($trimmedHtml);
            $content = $leadingWhitespace . $content . $trailingWhitespace;
            $cssClass = $node->getAttribute('data-djot-inline-footnote-class');
            if ($cssClass === '') {
                $cssClass = 'fn';
            }

            return '[' . $content . ']{.' . $cssClass . '}';
        }

        $href = $node->getAttribute('href');
        $text = trim($this->processChildren($node));
        $title = $node->getAttribute('title');

        if ($text === '') {
            $text = $href;
        }

        // Check for @mention (round-trip support for MentionsExtension)
        if ($node->hasAttribute('data-username')) {
            $username = $node->getAttribute('data-username');
            // Verify the link text matches @username pattern
            if ($text === '@' . $username) {
                return '@' . $username;
            }
        }

        // Check for autolink (round-trip support)
        if ($node->hasAttribute('data-djot-autolink')) {
            // Skip href and data-djot-autolink since they're in the autolink syntax
            $attrs = $this->formatInlineAttributes($node, ['href', 'data-djot-autolink']);

            // Email autolinks have mailto: prefix - strip it for output
            if (str_starts_with($href, 'mailto:')) {
                $email = substr($href, 7);

                return '<' . $email . '>' . $attrs;
            }

            return '<' . $href . '>' . $attrs;
        }

        // Check for reference link (round-trip support)
        if ($node->hasAttribute('data-djot-ref')) {
            $refLabel = $node->getAttribute('data-djot-ref');
            // Skip href, title, and data-djot-ref since they're in the reference syntax
            $attrs = $this->formatInlineAttributes($node, ['href', 'title', 'data-djot-ref']);

            // Collect reference definition
            // For collapsed reference (empty label), use the link text as label
            $defLabel = $refLabel === '' ? $text : $refLabel;
            if (!isset($this->referenceDefinitions[$defLabel])) {
                $this->referenceDefinitions[$defLabel] = $href;
            }

            // Output reference link syntax
            if ($refLabel === '') {
                // Collapsed reference [text][]
                return '[' . $text . '][]' . $attrs;
            }

            return '[' . $text . '][' . $refLabel . ']' . $attrs;
        }

        // Skip href and title since they're in the link syntax
        $attrs = $this->formatInlineAttributes($node, ['href', 'title']);

        if ($title !== '') {
            return '[' . $text . '](' . $href . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
        }

        return '[' . $text . '](' . $href . ')' . $attrs;
    }

    protected function processImage(DOMElement $node): string
    {
        $src = $node->getAttribute('src');
        $alt = $node->getAttribute('alt');
        $title = $node->getAttribute('title');

        // Check for reference image (round-trip support)
        if ($node->hasAttribute('data-djot-ref')) {
            $refLabel = $node->getAttribute('data-djot-ref');
            // Skip src, alt, title, and data-djot-ref since they're in the reference syntax
            $attrs = $this->formatInlineAttributes($node, ['src', 'alt', 'title', 'data-djot-ref']);

            // Collect reference definition
            // For collapsed reference (empty label), use the alt text as label
            $defLabel = $refLabel === '' ? $alt : $refLabel;
            if (!isset($this->referenceDefinitions[$defLabel])) {
                $this->referenceDefinitions[$defLabel] = $src;
            }

            // Output reference image syntax
            if ($refLabel === '') {
                // Collapsed reference ![alt][]
                return '![' . $alt . '][]' . $attrs;
            }

            return '![' . $alt . '][' . $refLabel . ']' . $attrs;
        }

        // Skip src, alt, title since they're in the image syntax
        $attrs = $this->formatInlineAttributes($node, ['src', 'alt', 'title']);

        if ($title !== '') {
            return '![' . $alt . '](' . $src . ' ' . $this->quoteLinkTitle($title) . ')' . $attrs;
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
        // Check for attribution (footer or cite element)
        $attribution = $this->extractBlockquoteAttribution($node);

        // Process content excluding attribution elements, preserving paragraph breaks
        $parts = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }

            // Skip footer and cite elements (handled as attribution)
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if ($tag === 'footer' || $tag === 'cite') {
                    continue;
                }
            }

            $part = rtrim($this->processNode($child), "\n");
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        $content = implode("\n\n", $parts);
        $lines = explode("\n", $content);

        $quoted = [];
        foreach ($lines as $line) {
            $quoted[] = $line === '' ? '>' : '> ' . rtrim($line);
        }

        // Add attribution as final quoted line with blank line before
        if ($attribution !== null) {
            $quoted[] = '>';
            foreach (explode("\n", $attribution) as $line) {
                $quoted[] = $line === '' ? '>' : '> ' . rtrim($line);
            }
        }

        $attrs = $this->formatBlockAttributes($node);

        return $attrs . "\n" . implode("\n", $quoted) . "\n\n";
    }

    /**
     * Extract attribution from blockquote (footer or cite element)
     */
    protected function extractBlockquoteAttribution(DOMElement $node): ?string
    {
        // Look for footer or cite as direct child
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'footer' || $tag === 'cite') {
                $text = trim($this->processChildren($child));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Process MathML element to Djot math syntax
     *
     * Attempts to extract LaTeX from:
     * 1. alttext attribute
     * 2. annotation element with encoding="application/x-tex" or "LaTeX"
     * 3. Falls back to text content
     */
    protected function processMath(DOMElement $node): string
    {
        $isDisplay = $node->getAttribute('display') === 'block';

        // Try alttext attribute first (common in MathJax output)
        $latex = $node->getAttribute('alttext');
        if ($latex !== '') {
            return $this->renderMath($latex, $isDisplay);
        }

        // Look for annotation element with LaTeX encoding
        $annotations = $node->getElementsByTagName('annotation');
        foreach ($annotations as $annotation) {
            $encoding = $annotation->getAttribute('encoding');
            if (stripos($encoding, 'tex') !== false || stripos($encoding, 'latex') !== false) {
                $latex = trim($annotation->textContent);
                if ($latex !== '') {
                    return $this->renderMath($latex, $isDisplay);
                }
            }
        }

        // Fall back to rendered MathML text, excluding annotation payloads.
        $text = trim($this->extractMathText($node));
        if ($text !== '') {
            return $this->renderMath($text, $isDisplay);
        }

        return '';
    }

    protected function renderMath(string $content, bool $isDisplay): string
    {
        $delimiter = $isDisplay ? '$$' : '$';
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $delimiter . $backticks . $content . $backticks . $delimiter;
    }

    protected function extractMathText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if ($tag === 'annotation' || $tag === 'annotation-xml') {
                return '';
            }
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->extractMathText($child);
        }

        return $text;
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
                if ($child->hasAttribute('data-djot-inline-footnote')) {
                    continue;
                }

                $indent = str_repeat('  ', $this->listDepth - 1);

                // Check for task list item (has checkbox input)
                $checkbox = '';
                $checkboxInput = $this->getDirectCheckboxInput($child);
                if ($isTaskList || $checkboxInput !== null) {
                    $isChecked = $checkboxInput?->hasAttribute('checked') ?? false;
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
    protected function getDirectCheckboxInput(DOMElement $li): ?DOMElement
    {
        foreach ($li->childNodes as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'input'
                && $child->getAttribute('type') === 'checkbox'
            ) {
                return $child;
            }
        }

        return null;
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
        $alignments = [];

        // Find caption element if present
        $captionElement = $this->findFirstDirectChildByTagName($node, 'caption');
        if ($captionElement instanceof DOMElement) {
            $captionText = trim($this->processChildren($captionElement));
        }

        // Find all rows
        $trElements = $this->getDirectTableRows($node);

        foreach ($trElements as $tr) {
            $cells = [];
            $isHeader = false;

            $columnIndex = 0;
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
                        if (!isset($alignments[$columnIndex])) {
                            $alignments[$columnIndex] = $this->extractTableCellAlignment($cell);
                        }
                        $columnIndex++;
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

        // Table-level attributes (excluding data-djot-col-widths which is for round-trip)
        $tableAttrs = $this->formatBlockAttributes($node, ['data-djot-col-widths']);
        $output = $tableAttrs . "\n";

        if ($headerRow !== null) {
            $output .= $headerRow . "\n";

            // Use original separator widths if available for round-trip
            $separator = [];
            $colWidthsAttr = $node->getAttribute('data-djot-col-widths');
            if ($colWidthsAttr !== '') {
                $colWidths = array_map('intval', explode(',', $colWidthsAttr));
                foreach ($colWidths as $width) {
                    // Use exact width from original for round-trip fidelity
                    $separator[] = $this->buildTableSeparator($width, $alignments[count($separator)] ?? TableCell::ALIGN_DEFAULT);
                }
                // Fill remaining columns with default width
                $separatorCount = count($separator);
                while ($separatorCount < $columnCount) {
                    $separator[] = $this->buildTableSeparator(3, $alignments[$separatorCount] ?? TableCell::ALIGN_DEFAULT);
                    $separatorCount++;
                }
            } else {
                for ($i = 0; $i < $columnCount; $i++) {
                    $separator[] = $this->buildTableSeparator(3, $alignments[$i] ?? TableCell::ALIGN_DEFAULT);
                }
            }

            $output .= '|' . implode('|', $separator) . '|' . "\n";
        }

        $output .= implode("\n", $rows) . "\n";

        // Add caption after table
        if ($captionText !== '') {
            $output .= $this->formatCaptionText($captionText);
        }

        return $output . "\n";
    }

    /**
     * @return list<\DOMElement>
     */
    protected function getDirectTableRows(DOMElement $table): array
    {
        $rows = [];

        foreach ($table->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'tr') {
                $rows[] = $child;

                continue;
            }

            if (!in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                continue;
            }

            foreach ($child->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    protected function findFirstDirectChildByTagName(DOMElement $node, string $tagName): ?DOMElement
    {
        $tagName = strtolower($tagName);

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tagName) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Get single block child element if div is a wrapper
     *
     * Returns the child element if:
     * - There is exactly one element child (ignoring whitespace text nodes)
     * - The child is a block-level element
     */
    protected function getSingleBlockChild(DOMElement $node): ?DOMElement
    {
        $elementChild = null;

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                // Allow whitespace-only text nodes
                if (trim($child->textContent) !== '') {
                    return null; // Has significant text content, not a wrapper
                }

                continue;
            }

            if ($child instanceof DOMElement) {
                if ($elementChild !== null) {
                    return null; // More than one element child
                }
                $elementChild = $child;
            }
        }

        // Check if the child is a block element
        if ($elementChild !== null) {
            $tag = strtolower($elementChild->tagName);
            if (in_array($tag, $this->blockElements, true)) {
                return $elementChild;
            }
        }

        return null;
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

    protected function extractTableCellAlignment(DOMElement $cell): string
    {
        $style = $cell->getAttribute('style');
        if ($style !== '' && preg_match('/text-align\s*:\s*(left|right|center)\s*;?/i', $style, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return TableCell::ALIGN_DEFAULT;
    }

    protected function buildTableSeparator(int $width, string $alignment): string
    {
        // Width represents the number of dashes in the separator
        // For alignment markers, we add colons around the dashes
        // Minimum is 1 dash for center, 2 for others (Djot allows 2-dash separators)
        return match ($alignment) {
            TableCell::ALIGN_LEFT => ':' . str_repeat('-', max(2, $width)),
            TableCell::ALIGN_RIGHT => str_repeat('-', max(2, $width)) . ':',
            TableCell::ALIGN_CENTER => ':' . str_repeat('-', max(1, $width)) . ':',
            default => str_repeat('-', max(2, $width)),
        };
    }

    protected function processSpan(DOMElement $node): string
    {
        // Check for escaped text (round-trip support)
        if ($node->hasAttribute('data-djot-escaped')) {
            return '\\' . $node->textContent;
        }

        // Check for raw inline content (round-trip support)
        if ($node->hasAttribute('data-djot-raw')) {
            return $this->processRawInline($node);
        }

        $content = $this->processChildren($node);

        // Use getElementAttributes to get all attributes including data-*
        $attrs = $this->getElementAttributes($node);

        // If span has any attributes, convert to Djot span syntax
        if ($attrs !== '') {
            return '[' . $content . ']{' . $attrs . '}';
        }

        return $content;
    }

    /**
     * Process raw inline span (with data-djot-raw) for round-trip
     */
    protected function processRawInline(DOMElement $node): string
    {
        $format = $node->getAttribute('data-djot-raw');

        // For HTML format, get the innerHTML (raw HTML content)
        // For other formats, get the text content (was HTML-escaped)
        if ($format === 'html') {
            $content = $this->getInnerHtml($node);
        } else {
            $content = $node->textContent;
        }

        // Find the appropriate backtick fence
        $backticks = StringUtil::findSafeCodeFence($content, 1);

        return $backticks . $content . $backticks . '{=' . $format . '}';
    }

    /**
     * Process semantic HTML elements to Djot span syntax
     *
     * Converts semantic HTML inline elements to Djot span syntax
     * for round-trip support with SemanticSpanExtension.
     *
     * @param \DOMElement $node The semantic element
     * @param string $type The element type (kbd, dfn, abbr, samp, var)
     */
    protected function processSemanticSpan(DOMElement $node, string $type): string
    {
        $content = $this->processChildren($node);

        // Preserve definition-based abbreviations when the round-trip template
        // already restored the matching abbreviation definition.
        if ($type === 'abbr') {
            $title = $node->getAttribute('title');
            if (($this->abbreviationMap[$content] ?? null) === $title) {
                return $content;
            }
        }

        // Build attribute parts
        $attrParts = [];

        // For abbr and dfn, the title attribute becomes the attribute value
        $title = $node->getAttribute('title');
        if ($title !== '' && ($type === 'abbr' || $type === 'dfn')) {
            // Escape quotes and backslashes in title
            $escapedTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
            $attrParts[] = $type . '="' . $escapedTitle . '"';
        } else {
            // For kbd or title-less dfn/abbr, use the attribute name as a flag.
            $attrParts[] = $type;
        }

        // Add any other attributes (id, class, data-*)
        $otherAttrs = $this->getElementAttributes($node, ['title']);
        if ($otherAttrs !== '') {
            $attrParts[] = $otherAttrs;
        }

        return '[' . $content . ']{' . implode(' ', $attrParts) . '}';
    }

    /**
     * Process inline quote element to Djot
     *
     * Converts <q> to quoted text. If the q element has a cite attribute,
     * it's preserved as an attribute on a span.
     */
    protected function processInlineQuote(DOMElement $node): string
    {
        $content = $this->processChildren($node);
        $escapedContent = str_replace(['\\', '"'], ['\\\\', '\\"'], $content);

        // Wrap in quotes
        $quoted = '"' . $escapedContent . '"';

        // If there's a cite attribute, wrap in span with the attribute
        $cite = $node->getAttribute('cite');
        if ($cite !== '') {
            $escapedCite = str_replace(['\\', '"'], ['\\\\', '\\"'], $cite);

            return '[' . $quoted . ']{cite="' . $escapedCite . '"}';
        }

        return $quoted;
    }

    /**
     * Get the innerHTML of an element
     */
    protected function getInnerHtml(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    protected function processFigure(DOMElement $node): string
    {
        if ($this->shouldFallbackFigureToRawHtml($node)) {
            return $this->processRawHtmlBlock($node);
        }

        $output = "\n";

        // Find img, blockquote, and figcaption
        $img = $this->findFirstDirectChildByTagName($node, 'img');
        $blockquote = $this->findFirstDirectChildByTagName($node, 'blockquote');
        $caption = $this->findFirstDirectChildByTagName($node, 'figcaption');

        if ($img instanceof DOMElement) {
            $output .= $this->processImage($img) . "\n";
        } elseif ($blockquote instanceof DOMElement) {
            $output .= $this->processBlockquote($blockquote);
            // Remove the trailing blank line since caption follows immediately
            $output = rtrim($output) . "\n";
        }

        if ($caption instanceof DOMElement) {
            $output .= $this->formatCaptionText(trim($this->processChildren($caption)));
        }

        return $output . "\n\n";
    }

    protected function shouldFallbackFigureToRawHtml(DOMElement $node): bool
    {
        if ($this->getElementAttributes($node) !== '') {
            return true;
        }

        $contentChildren = [];
        foreach ($node->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                if (trim($child->textContent) !== '') {
                    return true;
                }

                continue;
            }

            if (strtolower($child->tagName) === 'figcaption') {
                continue;
            }

            $contentChildren[] = strtolower($child->tagName);
        }

        if (count($contentChildren) !== 1) {
            return true;
        }

        return !in_array($contentChildren[0], ['img', 'blockquote'], true);
    }

    protected function processRawHtmlBlock(DOMElement $node): string
    {
        $html = $node->ownerDocument?->saveHTML($node);
        if (!is_string($html)) {
            $html = '';
        }

        $fence = StringUtil::findSafeCodeFence($html, 3);

        return "\n" . $fence . " =html\n" . $html . "\n" . $fence . "\n\n";
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
            if (str_starts_with($name, 'data-djot-')) {
                continue;
            }

            $value = $attr->value;
            if ($value === '') {
                // Boolean attribute
                $parts[] = $name;
            } else {
                $parts[] = $name . '=' . $this->quoteAttributeValue($value);
            }
        }

        return implode(' ', $parts);
    }

    protected function formatCaptionText(string $captionText): string
    {
        $lines = preg_split('/\R/', $captionText) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
        if ($lines === []) {
            return '';
        }

        $firstLine = array_shift($lines);
        $output = '^ ' . $firstLine . "\n";

        foreach ($lines as $line) {
            $output .= $line . "\n";
        }

        return $output;
    }

    protected function convertInlineFragmentToDjot(string $html): string
    {
        $converter = new self();
        $converter->preserveTextWhitespace = true;

        $doc = new DOMDocument();
        $doc->encoding = 'UTF-8';

        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><span>' . $html . '</span>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $doc->documentElement;
        if (!$root instanceof DOMElement) {
            return '';
        }

        return $converter->processChildren($root);
    }

    protected function isInlineOnlyEndnotesSection(DOMElement $node): bool
    {
        if ($node->getAttribute('role') !== 'doc-endnotes') {
            return false;
        }

        $ol = $this->findFirstDirectChildByTagName($node, 'ol');
        if (!$ol instanceof DOMElement) {
            return false;
        }

        $listItems = $this->getDirectChildElementsByTagName($ol, 'li');
        if ($listItems === []) {
            return false;
        }

        foreach ($listItems as $listItem) {
            if (!$listItem->hasAttribute('data-djot-inline-footnote')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process the footnotes endnotes section and extract definitions
     */
    protected function processEndnotesSection(DOMElement $node): string
    {
        // Find the <ol> containing footnote definitions
        $ol = $this->findFirstDirectChildByTagName($node, 'ol');
        if (!$ol instanceof DOMElement) {
            return '';
        }

        // Process each <li> footnote definition
        $listItems = $this->getDirectChildElementsByTagName($ol, 'li');
        foreach ($listItems as $li) {
            // Skip inline footnotes (handled separately)
            if ($li->hasAttribute('data-djot-inline-footnote')) {
                continue;
            }

            // Get footnote label from data attribute
            $label = $li->getAttribute('data-djot-footnote-label');
            if ($label === '') {
                // Fallback: extract from id attribute (fn1 -> 1)
                $id = $li->getAttribute('id');
                if (str_starts_with($id, 'fn')) {
                    $label = substr($id, 2);
                } else {
                    continue;
                }
            }

            // Extract content, removing the backlink
            $content = $this->processFootnoteContent($li);
            if ($content !== '') {
                $this->footnoteDefinitions[$label] = $content;
            }
        }

        // Return empty - footnote definitions are appended at the end
        return '';
    }

    /**
     * @return list<\DOMElement>
     */
    protected function getDirectChildElementsByTagName(DOMElement $node, string $tagName): array
    {
        $matches = [];
        $tagName = strtolower($tagName);

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tagName) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    /**
     * Process footnote content, removing backlinks
     */
    protected function processFootnoteContent(DOMElement $li): string
    {
        // Clone the node so we can remove backlinks without affecting the original
        $clone = $li->cloneNode(true);

        // Remove all backlink elements
        $ownerDocument = $clone->ownerDocument;
        if ($ownerDocument !== null) {
            $xpath = new DOMXPath($ownerDocument);
            /** @var \DOMNodeList<\DOMElement> $backlinks */
            $backlinks = $xpath->query('.//a[@role="doc-backlink"]', $clone);
            foreach ($backlinks as $backlink) {
                $backlink->parentNode?->removeChild($backlink);
            }
        }

        // Process the remaining content
        $content = trim($this->processChildren($clone));

        return $content;
    }

    protected function formatFootnoteDefinition(string|int $label, string $content): string
    {
        $lines = explode("\n", $content);
        $firstLine = $lines[0];
        $lines = array_slice($lines, 1);
        $formatted = '[^' . $label . ']: ' . $firstLine;

        foreach ($lines as $line) {
            $formatted .= "\n";
            if ($line !== '') {
                $formatted .= '  ' . $line;
            }
        }

        return $formatted;
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
            if (str_starts_with($line, ': ')) {
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
