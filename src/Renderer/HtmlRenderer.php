<?php

declare(strict_types=1);

namespace Djot\Renderer;

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
     * @var array<string, array<callable(\Djot\Event\RenderEvent): void>>
     */
    protected array $listeners = [];

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
     * @param callable(\Djot\Event\RenderEvent): void $listener
     */
    public function on(string $event, callable $listener): void
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

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $start = $node->getStart();
            if ($start !== 1) {
                $attrs .= ' start="' . $start . '"';
            }

            return '<ol' . $attrs . ">\n" . $this->renderChildren($node) . "</ol>\n";
        }

        if ($node->getListType() === ListBlock::TYPE_TASK) {
            $attrs .= ' class="task-list"';
        }

        return '<ul' . $attrs . ">\n" . $this->renderChildren($node) . "</ul>\n";
    }

    protected function renderListItem(ListItem $node): string
    {
        $attrs = $this->renderAttributes($node);
        $content = $this->renderChildren($node);

        // Handle task list items
        if ($node->isTask()) {
            $checked = $node->getChecked() ? ' checked=""' : '';
            $disabled = $this->xhtml ? ' disabled="disabled"' : ' disabled';
            $checkbox = '<input type="checkbox"' . $checked . $disabled . ($this->xhtml ? ' />' : '>') . ' ';
            $content = $checkbox . ltrim($content);
        }

        // For tight lists, don't wrap single paragraphs
        $content = preg_replace('/^<p>(.+)<\/p>\n?$/s', '$1', $content) ?? $content;

        return '<li' . $attrs . '>' . $content . "</li>\n";
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
        $href = $this->escape($node->getDestination());
        $title = $node->getTitle();

        $html = '<a href="' . $href . '"';
        if ($title !== null) {
            $html .= ' title="' . $this->escape($title) . '"';
        }
        $html .= $attrs . '>' . $this->renderChildren($node) . '</a>';

        return $html;
    }

    protected function renderImage(Image $node): string
    {
        $attrs = $this->renderAttributes($node);
        $src = $this->escape($node->getSource());
        $alt = $this->escape($node->getAlt());
        $title = $node->getTitle();

        $html = '<img src="' . $src . '" alt="' . $alt . '"';
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

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escape($key) . '="' . $this->escape((string)$value) . '"';
        }

        return $html;
    }

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

        // For simple descriptions, don't wrap in paragraph
        $content = preg_replace('/^<p>(.+)<\/p>\n?$/s', '$1', $content) ?? $content;

        return '<dd' . $attrs . '>' . $content . "</dd>\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $label = $this->escape($node->getLabel());
        $attrs = $this->renderAttributes($node);

        return '<div' . $attrs . ' class="footnote" id="fn-' . $label . '">' . "\n"
            . '<p><sup>' . $label . '</sup> ' . trim($this->renderChildren($node))
            . ' <a href="#fnref-' . $label . '">↩</a></p>' . "\n"
            . "</div>\n";
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $label = $this->escape($node->getLabel());

        return '<sup id="fnref-' . $label . '"><a href="#fn-' . $label . '">' . $label . '</a></sup>';
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
