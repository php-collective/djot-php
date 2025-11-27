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

/**
 * Renders AST to HTML
 */
class HtmlRenderer
{
    protected bool $softBreakAsNewline = false;

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

    public function __construct(protected bool $xhtml = false)
    {
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
        // Reset footnote reference counts for each render
        $this->footnoteRefCounts = [];

        return $this->renderChildren($document);
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
            $node instanceof ThematicBreak => $this->renderThematicBreak(),
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

        return '<p' . $attrs . '>' . $this->renderChildren($node) . "</p>\n";
    }

    protected function renderHeading(Heading $node): string
    {
        $level = $node->getLevel();
        $attrs = $this->renderAttributes($node);

        return '<h' . $level . $attrs . '>' . $this->renderChildren($node) . '</h' . $level . ">\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage();
        $attrs = $this->renderAttributes($node);

        $code = $this->escape($node->getContent());

        if ($language !== null) {
            return '<pre' . $attrs . '><code class="language-' . $this->escape($language) . '">' . $code . "</code></pre>\n";
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
            $start = $node->getStart();
            if ($start !== 1) {
                $attrs .= ' start="' . $start . '"';
            }

            return '<ol' . $attrs . ">\n" . $html . "</ol>\n";
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
            // For tight lists, strip paragraph wrapper
            $content = preg_replace('/^<p>(.+)<\/p>\n?$/s', '$1', $content) ?? $content;
        } else {
            // For loose lists, check if content is just one paragraph followed by lists
            // In djot, this case should also strip the leading <p> wrapper
            // Pattern: single paragraph followed by list(s)
            $content = preg_replace('/^<p>(.+?)<\/p>\n(<[uo]l>)/s', "$1\n$2", $content) ?? $content;
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

    protected function renderThematicBreak(): string
    {
        return $this->xhtml ? "<hr />\n" : "<hr>\n";
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

        // Render caption if present
        $caption = $node->getCaption();
        if ($caption !== null) {
            $html .= '<caption>' . $this->escape($caption) . "</caption>\n";
        }

        $children = $node->getChildren();
        $inHead = false;
        $inBody = false;

        foreach ($children as $row) {
            if ($row instanceof TableRow) {
                if ($row->isHeader() && !$inHead) {
                    $html .= "<thead>\n";
                    $inHead = true;
                } elseif (!$row->isHeader() && $inHead) {
                    $html .= "</thead>\n";
                    $inHead = false;
                }

                if (!$row->isHeader() && !$inBody) {
                    $html .= "<tbody>\n";
                    $inBody = true;
                }

                $html .= $this->renderTableRow($row);
            }
        }

        if ($inHead) {
            $html .= "</thead>\n";
        }
        if ($inBody) {
            $html .= "</tbody>\n";
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

        $html = '<a';
        // Only output href if not empty (unresolved references have no href)
        if ($href !== '') {
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
        $src = $this->escape($node->getSource());
        $title = $node->getTitle();

        $html = '<img alt="' . $alt . '" src="' . $src . '"';
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

        // Sort attributes: id first, then class, then others alphabetically
        uksort($attrs, function (string $a, string $b): int {
            if ($a === 'id') {
                return -1;
            }
            if ($b === 'id') {
                return 1;
            }
            if ($a === 'class') {
                return -1;
            }
            if ($b === 'class') {
                return 1;
            }

            return strcmp($a, $b);
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

        // Convert non-breaking space to entity for consistency with official djot
        return str_replace("\u{00A0}", '&nbsp;', $escaped);
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

        // Convert non-breaking space to entity for consistency with official djot
        return str_replace("\u{00A0}", '&nbsp;', $escaped);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() === 'html') {
            return $node->getContent() . "\n";
        }

        return '';
    }

    protected function renderRawInline(RawInline $node): string
    {
        // Only output if format is HTML
        if ($node->getFormat() === 'html') {
            return $node->getContent();
        }

        return '';
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
        $label = $this->escape($node->getLabel());
        $attrs = $this->renderAttributes($node);

        $content = trim($this->renderChildren($node));

        // Strip wrapping <p>...</p> to avoid nested paragraphs
        if (preg_match('/^<p>(.+)<\/p>$/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Build backref links for all references to this footnote
        $refCount = $this->footnoteRefCounts[$node->getLabel()] ?? 1;
        $backrefs = '';
        if ($refCount === 1) {
            $backrefs = '<a href="#fnref-' . $label . '-1">↩</a>';
        } else {
            $links = [];
            for ($i = 1; $i <= $refCount; $i++) {
                $links[] = '<a href="#fnref-' . $label . '-' . $i . '">↩<sup>' . $i . '</sup></a>';
            }
            $backrefs = implode(' ', $links);
        }

        return '<div' . $attrs . ' class="footnote" id="fn-' . $label . '">' . "\n"
            . '<p><sup>' . $label . '</sup> ' . $content . ' ' . $backrefs . '</p>' . "\n"
            . "</div>\n";
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $label = $node->getLabel();
        $escapedLabel = $this->escape($label);

        // Track reference count and generate unique ID
        if (!isset($this->footnoteRefCounts[$label])) {
            $this->footnoteRefCounts[$label] = 0;
        }
        $this->footnoteRefCounts[$label]++;
        $refNum = $this->footnoteRefCounts[$label];

        return '<sup id="fnref-' . $escapedLabel . '-' . $refNum . '"><a href="#fn-' . $escapedLabel . '">' . $escapedLabel . '</a></sup>';
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->escape($node->getContent());

        if ($node->isDisplay()) {
            return '<span class="math display">\\[' . $content . "\\]</span>\n";
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
