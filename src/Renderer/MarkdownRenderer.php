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
use Djot\Renderer\Utility\EventDispatcherTrait;

/**
 * Renders AST to Markdown (CommonMark compatible where possible)
 *
 * This renderer converts Djot AST to Markdown syntax. The output is designed
 * for consumption by Markdown parsers, not Djot parsers. For round-trip
 * stability, the Markdown output should be re-parsed by a Markdown parser.
 *
 * Syntax mapping (Djot → Markdown):
 * - Emphasis: `_text_` → `*text*`
 * - Strong: `*text*` → `**text**`
 *
 * Note: Some Djot features don't have direct Markdown equivalents
 * and will be rendered as HTML or approximated.
 */
class MarkdownRenderer implements RendererInterface
{
    use EventDispatcherTrait;

    protected int $listDepth = 0;

    protected bool $inBlockQuote = false;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

    protected int $headingLevelShift = 0;

    /**
     * Set how soft breaks are rendered
     *
     * @param \Djot\Renderer\SoftBreakMode $mode Newline (default) or Space
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    /**
     * Shift rendered heading levels without mutating the AST.
     */
    public function setHeadingLevelShift(int $shift): self
    {
        $this->headingLevelShift = max(0, min($shift, 5));

        return $this;
    }

    public function render(Document $document): string
    {
        $markdown = $this->renderChildren($document);

        // Normalize multiple blank lines
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown) . "\n";
    }

    protected function renderNode(Node $node): string
    {
        // Dispatch render events
        $eventName = 'render.' . $node->getType();
        $event = new RenderEvent($node);
        $this->dispatchEvent($eventName, $event);
        $this->dispatchEvent('render.*', $event);

        if ($event->isDefaultPrevented()) {
            return $event->getHtml() ?? '';
        }

        return match (true) {
            $node instanceof Document => $this->renderChildren($node),
            $node instanceof Paragraph => $this->renderParagraph($node),
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof CodeBlock => $this->renderCodeBlock($node),
            $node instanceof Comment => '', // Skip comments
            $node instanceof RawBlock => $this->renderRawBlock($node),
            $node instanceof BlockQuote => $this->renderBlockQuote($node),
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof DefinitionList => $this->renderDefinitionList($node),
            $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
            $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
            $node instanceof ThematicBreak => str_repeat($node->char, 3) . "\n\n",
            $node instanceof Div => $this->renderDiv($node),
            $node instanceof Table => $this->renderTable($node),
            $node instanceof LineBlock => $this->renderLineBlock($node),
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Text => $this->escapeText($node->getContent()),
            $node instanceof Emphasis => $this->renderEmphasis($node),
            $node instanceof Strong => $this->renderStrong($node),
            $node instanceof Code => $this->renderCode($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof HardBreak => "  \n",
            $node instanceof SoftBreak => match ($this->softBreakMode) {
                SoftBreakMode::Newline => "\n",
                SoftBreakMode::Space => ' ',
                SoftBreakMode::Break => "  \n", // Markdown hard break
            },
            $node instanceof Superscript => $this->renderSuperscript($node),
            $node instanceof Subscript => $this->renderSubscript($node),
            $node instanceof Highlight => $this->renderHighlight($node),
            $node instanceof Insert => $this->renderInsert($node),
            $node instanceof Delete => $this->renderDelete($node),
            $node instanceof Span => $this->renderSpan($node),
            $node instanceof Math => $this->renderMath($node),
            $node instanceof Symbol => ':' . $node->getName() . ':',
            $node instanceof FootnoteRef => '[^' . $node->getLabel() . ']',
            $node instanceof RawInline => $this->renderRawInline($node),
            default => $this->renderChildren($node),
        };
    }

    protected function renderChildren(Node $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderHeading(Heading $node): string
    {
        $prefix = str_repeat('#', min($node->getLevel() + $this->headingLevelShift, 6)) . ' ';

        return $prefix . $this->renderChildren($node) . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage() ?? '';
        $content = $node->getContent();

        // Use enough backticks to avoid conflicts
        $backticks = '```';
        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        return $backticks . $language . "\n" . $content . "\n" . $backticks . "\n\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $this->inBlockQuote = true;
        $content = $this->renderChildren($node);
        $this->inBlockQuote = false;

        // Prefix each line with >
        $lines = explode("\n", trim($content));
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);

        return implode("\n", $quoted) . "\n\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;
        $output = '';
        $counter = $node->getStart();

        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                $indent = str_repeat('  ', $this->listDepth - 1);

                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    // Normalize to standard Markdown: numeric with . or )
                    // Roman/alpha styles and (n) format are Djot-specific
                    $marker = $node->getMarker();
                    if ($marker === '()' || $marker === null) {
                        $marker = '.';
                    }
                    $prefix = $counter . $marker . ' ';
                    $counter++;
                } elseif ($node->getListType() === ListBlock::TYPE_TASK) {
                    $marker = $node->getMarker() ?? '-';
                    $checkbox = $child->getChecked() ? '[x] ' : '[ ] ';
                    $prefix = $marker . ' ' . $checkbox;
                } else {
                    $marker = $node->getMarker() ?? '-';
                    $prefix = $marker . ' ';
                }

                $content = trim($this->renderChildren($child));
                // Handle multi-line list items
                $lines = explode("\n", $content);
                $firstLine = array_shift($lines);
                $output .= $indent . $prefix . $firstLine . "\n";

                if ($lines) {
                    $continuation = str_repeat(' ', strlen($prefix));
                    foreach ($lines as $line) {
                        $output .= $indent . $continuation . $line . "\n";
                    }
                }
            }
        }

        $this->listDepth--;

        return $output . ($this->listDepth === 0 ? "\n" : '');
    }

    protected function renderListItem(ListItem $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        // Markdown doesn't have native definition lists
        // Use HTML or approximate with bold term
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        return '**' . $this->renderChildren($node) . "**\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        return ': ' . trim($this->renderChildren($node)) . "\n";
    }

    protected function renderDiv(Div $node): string
    {
        // Divs don't exist in Markdown, just render content
        return $this->renderChildren($node);
    }

    protected function renderTable(Table $node): string
    {
        $rows = [];
        $headerRow = null;
        $alignments = [];

        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $cells = [];
                $cellIndex = 0;

                foreach ($child->getChildren() as $cell) {
                    if ($cell instanceof TableCell) {
                        $cells[] = trim($this->renderChildren($cell));
                        // Get alignment from first body row (where it's stored)
                        if (!$child->isHeader() && !isset($alignments[$cellIndex])) {
                            $alignments[$cellIndex] = $cell->getAlignment();
                        }
                        $cellIndex++;
                    }
                }

                if ($child->isHeader()) {
                    $headerRow = '| ' . implode(' | ', $cells) . ' |';
                } else {
                    $rows[] = '| ' . implode(' | ', $cells) . ' |';
                }
            }
        }

        $output = '';
        if ($headerRow !== null) {
            $output .= $headerRow . "\n";

            // Generate separator row with alignments
            $separators = [];
            foreach ($alignments as $align) {
                $separators[] = match ($align) {
                    TableCell::ALIGN_LEFT => ':---',
                    TableCell::ALIGN_CENTER => ':---:',
                    TableCell::ALIGN_RIGHT => '---:',
                    default => '---',
                };
            }
            $output .= '| ' . implode(' | ', $separators) . ' |' . "\n";
        }

        $output .= implode("\n", $rows) . "\n\n";

        return $output;
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Line blocks don't exist in Markdown, use hard breaks
        $content = $this->renderChildren($node);

        // Replace soft breaks with hard breaks
        return str_replace("\n", "  \n", trim($content)) . "\n\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $content = trim($this->renderChildren($node));

        return '[^' . $node->getLabel() . ']: ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        return '*' . $this->renderChildren($node) . '*';
    }

    protected function renderStrong(Strong $node): string
    {
        return '**' . $this->renderChildren($node) . '**';
    }

    protected function renderCode(Code $node): string
    {
        $content = $node->getContent();

        // Use enough backticks to avoid conflicts
        $backticks = '`';
        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        // Add spaces if content starts/ends with backtick
        if (str_starts_with($content, '`') || str_ends_with($content, '`')) {
            return $backticks . ' ' . $content . ' ' . $backticks;
        }

        return $backticks . $content . $backticks;
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderChildren($node);
        $url = $node->getDestination();
        $title = $node->getTitle();

        if ($title !== null) {
            return '[' . $text . '](' . $url . ' "' . $title . '")';
        }

        return '[' . $text . '](' . $url . ')';
    }

    protected function renderImage(Image $node): string
    {
        $alt = $node->getAlt();
        $src = $node->getSource();
        $title = $node->getTitle();

        if ($title !== null) {
            return '![' . $alt . '](' . $src . ' "' . $title . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        // Markdown doesn't have native superscript, use HTML
        return '<sup>' . $this->renderChildren($node) . '</sup>';
    }

    protected function renderSubscript(Subscript $node): string
    {
        // Markdown doesn't have native subscript, use HTML
        return '<sub>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        // Markdown doesn't have native highlight, use HTML
        return '<mark>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderInsert(Insert $node): string
    {
        // Use HTML ins tag
        return '<ins>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderDelete(Delete $node): string
    {
        // Some Markdown flavors support ~~strikethrough~~
        return '~~' . $this->renderChildren($node) . '~~';
    }

    protected function renderSpan(Span $node): string
    {
        // Spans with attributes don't exist in Markdown
        // Just render the content
        return $this->renderChildren($node);
    }

    protected function renderMath(Math $node): string
    {
        $content = $node->getContent();

        if ($node->isDisplay()) {
            return '$$' . $content . '$$';
        }

        return '$' . $content . '$';
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        if ($node->getFormat() === 'html') {
            return $node->getContent() . "\n\n";
        }

        return '';
    }

    protected function renderRawInline(RawInline $node): string
    {
        if ($node->getFormat() === 'html') {
            return $node->getContent();
        }

        return '';
    }

    protected function escapeText(string $text): string
    {
        // Escape special Markdown characters in text
        // But be careful not to over-escape
        return preg_replace('/([\\\\`*_\[\]#])/', '\\\\$1', $text) ?? $text;
    }
}
