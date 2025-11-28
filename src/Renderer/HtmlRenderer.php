<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Closure;
use Djot\Event\RenderEvent;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Footnote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\RawBlock;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\Document;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\SafeMode;

/**
 * Renders AST to HTML
 */
class HtmlRenderer
{
    protected bool $softBreakAsNewline = true;

    /**
     * Safe mode configuration (null = disabled)
     */
    protected ?SafeMode $safeMode = null;

    /**
     * @var array<string, array<\Closure(\Djot\Event\RenderEvent): void>>
     */
    protected array $listeners = [];

    /**
     * Tracks footnote reference counts for generating unique IDs
     *
     * @var array<string, int>
     */
    protected array $footnoteRefCounts = [];

    /**
     * Tracks used IDs for deduplication
     *
     * @var array<string, int>
     */
    protected array $usedIds = [];

    /**
     * Counter for auto-generated section IDs (when heading has no text)
     */
    protected int $sectionCounter = 0;

    /**
     * Maps footnote labels to their assigned numbers (order of first reference)
     *
     * @var array<string, int>
     */
    protected array $footnoteNumbers = [];

    /**
     * Counter for footnote numbering
     */
    protected int $footnoteCounter = 0;

    /**
     * Collected footnote nodes for rendering at end
     *
     * @var array<string, \Djot\Node\Block\Footnote>
     */
    protected array $collectedFootnotes = [];

    public function __construct(protected bool $xhtml = false)
    {
    }

    /**
     * Enable safe mode with the given configuration
     */
    public function setSafeMode(?SafeMode $safeMode): self
    {
        $this->safeMode = $safeMode;

        return $this;
    }

    /**
     * Get the current safe mode configuration
     */
    public function getSafeMode(): ?SafeMode
    {
        return $this->safeMode;
    }

    /**
     * Check if safe mode is enabled
     */
    public function isSafeModeEnabled(): bool
    {
        return $this->safeMode !== null;
    }

    public function setSoftBreakAsNewline(bool $value): void
    {
        $this->softBreakAsNewline = $value;
    }

    /**
     * Register a listener for a render event
     *
     * Event names correspond to node types:
     * - render.link, render.image, render.paragraph, etc.
     * - render.* for all nodes
     *
     * @param string $event
     * @param \Closure(\Djot\Event\RenderEvent): void $listener
     */
    public function on(string $event, Closure $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Remove all listeners for an event (or all events if no event specified)
     */
    public function off(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }

    public function render(Document $document): string
    {
        // Reset state for each render
        $this->footnoteRefCounts = [];
        $this->usedIds = [];
        $this->sectionCounter = 0;
        $this->footnoteNumbers = [];
        $this->footnoteCounter = 0;
        $this->collectedFootnotes = [];

        $html = $this->renderDocumentWithSections($document);

        // Render collected footnotes at end (populated by renderFootnote/renderFootnoteRef during rendering)
        // @phpstan-ignore-next-line
        if ($this->collectedFootnotes || $this->footnoteNumbers) {
            $html .= $this->renderFootnotesSection();
        }

        return $html;
    }

    /**
     * Render document with section wrapping around headings
     */
    protected function renderDocumentWithSections(Document $document): string
    {
        $children = $document->getChildren();
        $html = '';
        /** @var array<int, int> $openSections Level => count of open sections at that level */
        $openSections = [];

        $childCount = count($children);
        for ($i = 0; $i < $childCount; $i++) {
            $child = $children[$i];

            if ($child instanceof Heading) {
                $level = $child->getLevel();

                // Dispatch render event for heading - allows custom rendering
                $eventName = 'render.' . $child->getType();
                $event = new RenderEvent($child);
                $this->dispatchEvent($eventName, $event);
                $this->dispatchEvent('render.*', $event);

                // Close any sections at same or deeper level
                for ($l = 6; $l >= $level; $l--) {
                    while (!empty($openSections[$l]) && $openSections[$l] > 0) {
                        $html .= "</section>\n";
                        $openSections[$l]--;
                    }
                }

                // If event provided custom HTML, use it (without section wrapper)
                if ($event->isDefaultPrevented()) {
                    $html .= $event->getHtml() ?? '';

                    continue;
                }

                // Get the section ID
                $sectionId = $this->getSectionId($child);

                // Open new section
                $html .= '<section id="' . $this->escapeAttribute($sectionId) . '">' . "\n";
                if (!isset($openSections[$level])) {
                    $openSections[$level] = 0;
                }
                $openSections[$level]++;

                // Render heading without section wrapper
                $html .= $this->renderHeadingContent($child);
            } else {
                // Track IDs from non-heading elements for deduplication
                $this->trackIdFromNode($child);
                $html .= $this->renderNode($child);
            }
        }

        // Close all open sections (deepest first)
        for ($l = 6; $l >= 1; $l--) {
            while (!empty($openSections[$l]) && $openSections[$l] > 0) {
                $html .= "</section>\n";
                $openSections[$l]--;
            }
        }

        return $html;
    }

    /**
     * Generate section ID from heading
     */
    protected function getSectionId(Heading $node): string
    {
        // If heading has explicit id attribute, use it
        if ($node->hasAttribute('id')) {
            $id = (string)$node->getAttribute('id');
            // Track explicit IDs so auto-generated IDs don't conflict
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 0;
            }

            return $id;
        }

        // Generate from heading text
        $headingText = $this->getPlainText($node);

        if ($headingText === '') {
            // Generate fallback ID
            $this->sectionCounter++;

            return 's-' . $this->sectionCounter;
        }

        // Convert to valid ID:
        // 1. Strip # characters entirely
        // 2. Trim whitespace
        // 3. Replace whitespace sequences with single dashes
        $baseId = str_replace('#', '', $headingText);
        $baseId = trim($baseId);
        $baseId = preg_replace('/[\s]+/', '-', $baseId) ?? $baseId;

        // Track and deduplicate
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 0;

            return $baseId;
        }

        // Already used, add suffix (first conflict is -1, second is -2, etc.)
        $this->usedIds[$baseId]++;

        return $baseId . '-' . $this->usedIds[$baseId];
    }

    /**
     * Track ID usage from non-heading elements (like paragraphs with explicit IDs)
     */
    protected function trackIdFromNode(Node $node): void
    {
        if ($node->hasAttribute('id')) {
            $id = (string)$node->getAttribute('id');
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 0;
            }
        }
    }

    /**
     * Render just the heading tag content (without section wrapper)
     */
    protected function renderHeadingContent(Heading $node): string
    {
        $level = $node->getLevel();

        // Don't render id on heading since it's on section
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Render attributes excluding specified ones
     *
     * @param \Djot\Node\Node $node
     * @param array<string> $exclude
     */
    protected function renderAttributesExcluding(Node $node, array $exclude): string
    {
        $attrs = $node->getAttributes();
        if (!$attrs) {
            return '';
        }

        // Filter out excluded attributes
        $attrs = array_diff_key($attrs, array_flip($exclude));
        if (!$attrs) {
            return '';
        }

        // Filter dangerous attributes in safe mode
        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
        }

        // Sort attributes: id first, then others in source order
        uksort($attrs, function (string $a, string $b): int {
            if ($a === 'id') {
                return -1;
            }
            if ($b === 'id') {
                return 1;
            }

            return 0;
        });

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape($key) . '="' . $this->escapeAttribute((string)$value) . '"';
        }

        return $html;
    }

    protected function renderNode(Node $node): string
    {
        // Dispatch render event
        $eventName = 'render.' . $node->getType();
        $event = new RenderEvent($node);

        // Call specific listeners
        $this->dispatchEvent($eventName, $event);

        // Call wildcard listeners
        $this->dispatchEvent('render.*', $event);

        // If listener provided custom HTML, use it
        if ($event->isDefaultPrevented()) {
            return $event->getHtml() ?? '';
        }

        return match (true) {
            $node instanceof Document => $this->renderChildren($node),
            $node instanceof Paragraph => $this->renderParagraph($node),
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof CodeBlock => $this->renderCodeBlock($node),
            $node instanceof Comment => '', // Comments are stripped from output
            $node instanceof RawBlock => $this->renderRawBlock($node),
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof DefinitionList => $this->renderDefinitionList($node),
            $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
            $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof ThematicBreak => $this->renderThematicBreak($node),
            $node instanceof Div => $this->renderDiv($node),
            $node instanceof Table => $this->renderTable($node),
            $node instanceof TableRow => $this->renderTableRow($node),
            $node instanceof TableCell => $this->renderTableCell($node),
            $node instanceof LineBlock => $this->renderLineBlock($node),
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Text => $this->renderText($node),
            $node instanceof Emphasis => $this->renderEmphasis($node),
            $node instanceof Strong => $this->renderStrong($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof Code => $this->renderCode($node),
            $node instanceof RawInline => $this->renderRawInline($node),
            $node instanceof Math => $this->renderMath($node),
            $node instanceof Symbol => $this->renderSymbol($node),
            $node instanceof FootnoteRef => $this->renderFootnoteRef($node),
            $node instanceof SoftBreak => $this->renderSoftBreak(),
            $node instanceof HardBreak => $this->renderHardBreak(),
            $node instanceof Span => $this->renderSpan($node),
            $node instanceof Highlight => $this->renderHighlight($node),
            $node instanceof Superscript => $this->renderSuperscript($node),
            $node instanceof Subscript => $this->renderSubscript($node),
            $node instanceof Insert => $this->renderInsert($node),
            $node instanceof Delete => $this->renderDelete($node),
            default => $this->renderChildren($node),
        };
    }

    protected function renderChildren(Node $node): string
    {
        $html = '';
        foreach ($node->getChildren() as $child) {
            $html .= $this->renderNode($child);
        }

        return $html;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = rtrim($this->renderChildren($node), " \t");

        return '<p' . $attrs . '>' . $content . "</p>\n";
    }

    protected function renderHeading(Heading $node): string
    {
        // This is called when a heading is rendered inside other blocks (blockquote, div, etc.)
        // Section wrapping is ONLY applied at document level by renderDocumentWithSections
        // Inside nested blocks, headings just get id attribute directly
        $level = $node->getLevel();
        $sectionId = $this->getSectionId($node);
        $attrs = $this->renderAttributesExcluding($node, ['id']);

        return '<h' . $level . ' id="' . $this->escapeAttribute($sectionId) . '"' . $attrs . '>'
            . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    /**
     * Get plain text content of a node (for generating heading IDs)
     */
    protected function getPlainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Node) {
                $text .= $this->getPlainText($child);
            }
        }

        return $text;
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage();
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($node->getContent());
        // Add trailing newline inside code block (official djot behavior)
        if ($code !== '' && !str_ends_with($code, "\n")) {
            $code .= "\n";
        }

        if ($language !== null) {
            $langClass = 'class="language-' . $this->escape($language) . '"';

            return '<pre' . $attrs . '><code ' . $langClass . '>' . $code . "</code></pre>\n";
        }

        return '<pre' . $attrs . '><code>' . $code . "</code></pre>\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<blockquote' . $attrs . ">\n" . $this->renderChildren($node) . "</blockquote>\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $attrs = $this->renderAttributes($node);
        $tight = $node->isTight();

        // Render children with tight parameter
        $html = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $html .= $this->renderListItem($child, $tight);
            } else {
                $html .= $this->renderNode($child);
            }
        }

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $olAttrs = '';
            $start = $node->getStart();
            $style = $node->getStyle();

            // Order: start, then type, then other attrs
            if ($start !== 1) {
                $olAttrs .= ' start="' . $start . '"';
            }
            if ($style !== null) {
                $olAttrs .= ' type="' . $style . '"';
            }

            return '<ol' . $olAttrs . $attrs . ">\n" . $html . "</ol>\n";
        }

        if ($node->getListType() === ListBlock::TYPE_TASK) {
            $attrs .= ' class="task-list"';
        }

        return '<ul' . $attrs . ">\n" . $html . "</ul>\n";
    }

    protected function renderListItem(ListItem $node, bool $tight = true): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        if ($tight) {
            // For tight lists, strip paragraph wrapper from content
            // This handles both:
            // 1. Single paragraph: <p>text</p> -> text
            // 2. Paragraph followed by nested list: <p>text</p>\n<ul>... -> text\n<ul>...
            $content = preg_replace('/^<p>(.+?)<\/p>(\n)?/s', '$1$2', $content) ?? $content;
        }

        // Handle task list items
        if ($node->isTask()) {
            $checked = $node->getChecked() ? ' checked=""' : '';
            // Always use xhtml-style format for task list checkboxes
            $checkbox = '<input disabled="" type="checkbox"' . $checked . "/>\n";
            $content = $checkbox . rtrim($content);
        } else {
            $content = rtrim($content);
        }

        // In djot, list item content is always on its own line
        return '<li' . $attrs . ">\n" . $content . "\n</li>\n";
    }

    protected function renderThematicBreak(ThematicBreak $node): string
    {
        $attrs = $this->renderAttributes($node);

        return $this->xhtml ? '<hr' . $attrs . " />\n" : '<hr' . $attrs . ">\n";
    }

    protected function renderDiv(Div $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<div' . $attrs . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<div class="line-block"' . $attrs . ">\n" . $this->renderChildren($node) . "</div>\n";
    }

    protected function renderTable(Table $node): string
    {
        $attrs = $this->renderAttributes($node);
        $html = '<table' . $attrs . ">\n";

        // Render caption if present (with parsed inline content)
        if ($node->hasCaptionChildren()) {
            $captionHtml = '';
            foreach ($node->getCaptionChildren() as $child) {
                $captionHtml .= $this->renderNode($child);
            }
            // Remove trailing newline from last text node
            $captionHtml = rtrim($captionHtml);
            $html .= '<caption>' . $captionHtml . "</caption>\n";
        }

        // djot tables don't use thead/tbody - just rows with th or td cells
        foreach ($node->getChildren() as $row) {
            if ($row instanceof TableRow) {
                $html .= $this->renderTableRow($row);
            }
        }

        return $html . "</table>\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<tr' . $attrs . ">\n" . $this->renderChildren($node) . "</tr>\n";
    }

    protected function renderTableCell(TableCell $node): string
    {
        $tag = $node->isHeader() ? 'th' : 'td';
        $attrs = $this->renderAttributes($node);

        $alignment = $node->getAlignment();
        if ($alignment !== TableCell::ALIGN_DEFAULT) {
            $attrs .= ' style="text-align: ' . $alignment . ';"';
        }

        return '<' . $tag . $attrs . '>' . $this->renderChildren($node) . '</' . $tag . ">\n";
    }

    protected function renderText(Text $node): string
    {
        return $this->escape($node->getContent());
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<em' . $attrs . '>' . $this->renderChildren($node) . '</em>';
    }

    protected function renderStrong(Strong $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<strong' . $attrs . '>' . $this->renderChildren($node) . '</strong>';
    }

    protected function renderLink(Link $node): string
    {
        $attrs = $this->renderAttributes($node);
        $href = $node->getDestination();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null && $href !== null) {
            $href = $this->safeMode->sanitizeUrl($href);
        }

        $html = '<a';
        // Only output href if destination is set (even if empty)
        if ($href !== null) {
            $html .= ' href="' . $this->escape($href) . '"';
        }
        if ($title !== null) {
            $html .= ' title="' . $this->escape($title) . '"';
        }
        $html .= $attrs . '>' . $this->renderChildren($node) . '</a>';

        return $html;
    }

    protected function renderImage(Image $node): string
    {
        $attrs = $this->renderAttributes($node);
        $alt = $this->escape($node->getAlt());
        $src = $node->getSource();
        $title = $node->getTitle();

        // Sanitize URL in safe mode
        if ($this->safeMode !== null) {
            $src = $this->safeMode->sanitizeUrl($src);
        }

        $html = '<img alt="' . $alt . '" src="' . $this->escape($src) . '"';
        if ($title !== null) {
            $html .= ' title="' . $this->escape($title) . '"';
        }
        $html .= $attrs;

        return $this->xhtml ? $html . ' />' : $html . '>';
    }

    protected function renderCode(Code $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<code' . $attrs . '>' . $this->escape($node->getContent()) . '</code>';
    }

    protected function renderSoftBreak(): string
    {
        return $this->softBreakAsNewline ? "\n" : ' ';
    }

    protected function renderHardBreak(): string
    {
        return $this->xhtml ? "<br />\n" : "<br>\n";
    }

    protected function renderSpan(Span $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<span' . $attrs . '>' . $this->renderChildren($node) . '</span>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<mark' . $attrs . '>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sup' . $attrs . '>' . $this->renderChildren($node) . '</sup>';
    }

    protected function renderSubscript(Subscript $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<sub' . $attrs . '>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderInsert(Insert $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<ins' . $attrs . '>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderDelete(Delete $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<del' . $attrs . '>' . $this->renderChildren($node) . '</del>';
    }

    protected function renderAttributes(Node $node): string
    {
        $attrs = $node->getAttributes();
        if (!$attrs) {
            return '';
        }

        // Filter dangerous attributes in safe mode
        if ($this->safeMode !== null) {
            $attrs = $this->safeMode->filterAttributes($attrs);
        }

        // Sort attributes: id first, then others in source order
        uksort($attrs, function (string $a, string $b): int {
            if ($a === 'id') {
                return -1;
            }
            if ($b === 'id') {
                return 1;
            }

            return 0; // preserve order for other attributes
        });

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape($key) . '="' . $this->escapeAttribute((string)$value) . '"';
        }

        return $html;
    }

    protected function escape(string $text): string
    {
        // ENT_NOQUOTES: Don't convert quotes - official djot keeps them literal
        // Only escape <, >, and & for HTML safety
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        // Literal NBSP characters in source are preserved as-is
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    /**
     * Escape text for use in HTML attribute values
     *
     * Unlike escape(), this DOES escape quotes since they're in attribute context
     */
    protected function escapeAttribute(string $text): string
    {
        // ENT_QUOTES: Escape both single and double quotes for attribute values
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Convert escaped space placeholder (U+E000) to &nbsp; entity
        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content) . "\n";
            }
        }

        return $content . "\n";
    }

    protected function renderRawInline(RawInline $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() !== 'html') {
            return '';
        }

        $content = $node->getContent();

        // Handle raw HTML according to safe mode
        if ($this->safeMode !== null) {
            $mode = $this->safeMode->getRawHtmlMode();
            if ($mode === SafeMode::RAW_HTML_STRIP) {
                return '';
            }
            if ($mode === SafeMode::RAW_HTML_ESCAPE) {
                return $this->escape($content);
            }
        }

        return $content;
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dl' . $attrs . ">\n" . $this->renderChildren($node) . "</dl>\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        $attrs = $this->renderAttributes($node);

        return '<dt' . $attrs . '>' . $this->renderChildren($node) . "</dt>\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        // Content goes on separate lines
        $content = rtrim($content);
        if ($content === '') {
            return '<dd' . $attrs . ">\n</dd>\n";
        }

        return '<dd' . $attrs . ">\n" . $content . "\n</dd>\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // Collect footnote for rendering at document end, don't output here
        $label = $node->getLabel();
        $this->collectedFootnotes[$label] = $node;

        return '';
    }

    /**
     * Render all collected footnotes as end section
     */
    protected function renderFootnotesSection(): string
    {
        // Pre-render all footnote contents to discover any nested footnote references
        // Keep iterating until no new footnotes are discovered
        $renderedContents = [];
        $processedNumbers = [];

        do {
            $newFootnotes = false;
            foreach ($this->footnoteNumbers as $label => $number) {
                if (isset($processedNumbers[$number])) {
                    continue;
                }
                $processedNumbers[$number] = true;

                if (isset($this->collectedFootnotes[$label])) {
                    // Rendering may discover new footnote references
                    $renderedContents[$number] = trim($this->renderChildren($this->collectedFootnotes[$label]));
                } else {
                    $renderedContents[$number] = '';
                }

                // Check if new footnotes were discovered during rendering
                if (count($this->footnoteNumbers) > count($processedNumbers)) {
                    $newFootnotes = true;
                }
            }
        } while ($newFootnotes);

        // Sort footnotes by their reference number order
        ksort($renderedContents);

        $html = '<section role="doc-endnotes">' . "\n";
        $html .= '<hr>' . "\n";
        $html .= '<ol>' . "\n";

        foreach ($renderedContents as $number => $content) {
            $html .= '<li id="fn' . $number . '">' . "\n";

            // Add backlink - if content ends with </p>, insert before it
            // Otherwise add as separate paragraph
            if ($content !== '' && preg_match('/^(.*)(<\/p>\n?)$/s', $content, $matches)) {
                $content = $matches[1] . '<a href="#fnref' . $number . '" role="doc-backlink">↩︎</a></p>';
                $html .= $content . "\n";
            } else {
                // Content doesn't end with paragraph (e.g., code block or empty)
                if ($content !== '') {
                    $html .= $content . "\n";
                }
                $html .= '<p><a href="#fnref' . $number . '" role="doc-backlink">↩︎</a></p>' . "\n";
            }

            $html .= '</li>' . "\n";
        }

        $html .= '</ol>' . "\n";
        $html .= '</section>' . "\n";

        return $html;
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $label = $node->getLabel();

        // Assign number to footnote on first reference
        if (!isset($this->footnoteNumbers[$label])) {
            $this->footnoteCounter++;
            $this->footnoteNumbers[$label] = $this->footnoteCounter;
        }
        $number = $this->footnoteNumbers[$label];

        // Format: <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>
        return '<a id="fnref' . $number . '" href="#fn' . $number . '" role="doc-noteref"><sup>' . $number . '</sup></a>';
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->escape($node->getContent());

        if ($node->isDisplay()) {
            return '<span class="math display">\\[' . $content . '\\]</span>';
        }

        return '<span class="math inline">\\(' . $content . '\\)</span>';
    }

    protected function renderSymbol(Symbol $node): string
    {
        // By default, symbols are rendered as their name
        // Could be extended to support emoji mappings
        return ':' . $this->escape($node->getName()) . ':';
    }

    /**
     * Dispatch an event to all registered listeners
     */
    protected function dispatchEvent(string $event, RenderEvent $renderEvent): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener($renderEvent);

            // Stop propagation if default was prevented
            if ($renderEvent->isDefaultPrevented()) {
                break;
            }
        }
    }
}
