<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Event\RenderEvent;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\Caption;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Figure;
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
use Djot\Node\Inline\Abbreviation;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\EscapedText;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\Mention;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Renderer\Utility\AbbreviationBudgetTrait;
use Djot\Renderer\Utility\EventDispatcherTrait;
use Djot\Util\StringUtil;
use Djot\Util\UrlSafety;

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
    use AbbreviationBudgetTrait;
    use EventDispatcherTrait;

    protected int $listDepth = 0;

    protected bool $inBlockQuote = false;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

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

    public function render(Document $document): string
    {
        $this->resetAbbreviationBudget($document->getSourceLength());

        $markdown = $this->renderChildren($document);

        // Normalize multiple blank lines
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        $markdown = trim($markdown) . "\n";

        // The internal non-breaking-space placeholder (U+E000) becomes a literal
        // non-breaking space (U+00A0). Markdown is a re-parseable round-trip
        // format, so unlike the display renderers it keeps the real nbsp: that
        // survives a re-render as `&nbsp;` and is never mistaken for a Markdown
        // indented-code-block prefix the way ordinary leading spaces would be.
        // Done as the final step, after trimming, so placeholder-derived leading
        // indentation (e.g. in a line block) survives.
        return str_replace("\u{E000}", "\u{00A0}", $markdown);
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
            // Keep the backslash so the literal stays literal when re-parsed as
            // Markdown: a bare `.` from `\.` would turn `1\. x` back into an
            // ordered list. EscapedText only ever holds escaped ASCII
            // punctuation, all of which CommonMark allows a `\` before.
            $node instanceof EscapedText => '\\' . $node->getContent(),
            $node instanceof Figure => $this->renderFigure($node),
            $node instanceof Caption => $this->renderCaption($node),
            $node instanceof Abbreviation => $this->renderAbbreviation($node),
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
        $prefix = str_repeat('#', $node->getLevel()) . ' ';

        return $prefix . $this->renderChildren($node) . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $language = $node->getLanguage() ?? '';
        $content = $node->getContent();

        $backticks = StringUtil::findSafeCodeFence($content, 3);

        // The payload carries its own terminator, so only supply one when it does
        // not - the same tolerance the HTML renderer already has. Appending
        // unconditionally doubled the last newline and grew a blank line before
        // the closing fence on every round trip.
        $terminator = ($content === '' || str_ends_with($content, "\n")) ? '' : "\n";

        return $backticks . $language . "\n" . $content . $terminator . $backticks . "\n\n";
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
        // Divs/admonitions don't exist in Markdown; render the content. A Div's
        // quoted title (e.g. an admonition title carried as the `title`
        // attribute) would otherwise be lost - preserve it as a leading bold
        // line.
        $body = $this->renderChildren($node);
        $title = $node->getAttribute('title');
        if (is_string($title) && $title !== '') {
            return '**' . $this->escapeText($title) . "**\n\n" . $body;
        }

        return $body;
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
        return $this->renderDelimited($node, '*', 'em');
    }

    protected function renderStrong(Strong $node): string
    {
        return $this->renderDelimited($node, '**', 'strong');
    }

    /**
     * Render a CommonMark delimiter run without changing its inline meaning.
     *
     * Padding belongs outside the run because a delimiter beside whitespace
     * cannot open or close. Ambiguous seams use inline HTML, which is verbose
     * but preserves the tree accepted by every CommonMark reader.
     */
    protected function renderDelimited(Node $node, string $delimiter, string $tag): string
    {
        $inner = $this->renderChildren($node);
        preg_match('/^\s*/u', $inner, $leadingMatch);
        preg_match('/\s*$/u', $inner, $trailingMatch);
        $leading = $leadingMatch[0] ?? '';
        $trailing = $trailingMatch[0] ?? '';
        $coreLength = strlen($inner) - strlen($leading) - strlen($trailing);
        $core = $coreLength > 0 ? substr($inner, strlen($leading), $coreLength) : '';

        if ($core === '') {
            return $inner === '' ? '' : '<' . $tag . '>' . $inner . '</' . $tag . '>';
        }

        $previous = $this->adjacentSiblingCharacter($node, false);
        $next = $this->adjacentSiblingCharacter($node, true);
        $first = mb_substr($core, 0, 1);
        $last = mb_substr($core, -1);
        $parent = $node->getParent();
        $ambiguousSeam = ($previous !== null
                && !$this->isWhitespaceOrPunctuation($previous)
                && $this->isPunctuation($first))
            || ($next !== null
                && !$this->isWhitespaceOrPunctuation($next)
                && $this->isPunctuation($last))
            || (($node instanceof Emphasis || $node instanceof Strong)
                && ($parent instanceof Emphasis || $parent instanceof Strong));

        if ($ambiguousSeam) {
            return $leading . '<' . $tag . '>' . $core . '</' . $tag . '>' . $trailing;
        }

        return $leading . $delimiter . $core . $delimiter . $trailing;
    }

    protected function adjacentSiblingCharacter(Node $node, bool $after): ?string
    {
        $parent = $node->getParent();
        if ($parent === null) {
            return null;
        }
        $children = $parent->getChildren();
        $index = array_search($node, $children, true);
        if (!is_int($index)) {
            return null;
        }
        for ($at = $index + ($after ? 1 : -1); isset($children[$at]); $at += $after ? 1 : -1) {
            $text = $this->nodeBoundaryText($children[$at], $after);
            if ($text !== '') {
                return $after ? mb_substr($text, 0, 1) : mb_substr($text, -1);
            }
        }
        if ($parent instanceof Span) {
            return $this->adjacentSiblingCharacter($parent, $after);
        }

        return null;
    }

    protected function nodeBoundaryText(Node $node, bool $after): string
    {
        if ($node instanceof Text) {
            return $node->getContent();
        }
        if ($node instanceof EscapedText) {
            return '\\' . $node->getContent();
        }
        if ($node instanceof Code) {
            return '`';
        }
        if ($node instanceof Math) {
            return '$';
        }
        if ($node instanceof RawInline) {
            return $node->getFormat() === 'html' ? ($after ? '<' : '>') : '';
        }
        if ($node instanceof Link || $node instanceof Image) {
            return $after ? '[' : ')';
        }
        if ($node instanceof Emphasis) {
            return '*';
        }
        if ($node instanceof Strong || $node instanceof Delete) {
            return '**';
        }
        $children = $node->getChildren();
        if ($children === []) {
            return $node instanceof SoftBreak || $node instanceof HardBreak ? "\n" : '';
        }
        $ordered = $after ? $children : array_reverse($children);
        foreach ($ordered as $child) {
            $text = $this->nodeBoundaryText($child, $after);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    protected function isWhitespaceOrPunctuation(string $character): bool
    {
        return preg_match('/[\s\p{P}\p{S}]/u', $character) === 1;
    }

    protected function isPunctuation(string $character): bool
    {
        return preg_match('/[\p{P}\p{S}]/u', $character) === 1;
    }

    protected function renderCode(Code $node): string
    {
        $content = $node->getContent();

        $backticks = StringUtil::findSafeCodeFence($content, 1);

        // Add spaces if content starts/ends with backtick
        if (str_starts_with($content, '`') || str_ends_with($content, '`')) {
            return $backticks . ' ' . $content . ' ' . $backticks;
        }

        return $backticks . $content . $backticks;
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderChildren($node);
        $url = UrlSafety::sanitize($node->getDestination() ?? '');
        $title = $node->getTitle();

        if ($url === '' && $node instanceof Mention) {
            return $text;
        }

        if ($title !== null) {
            return '[' . $text . '](' . $url . ' "' . $title . '")';
        }

        return '[' . $text . '](' . $url . ')';
    }

    protected function renderImage(Image $node): string
    {
        $alt = $node->getAlt();
        $src = UrlSafety::sanitize($node->getSource());
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
        return $this->renderDelimited($node, '~~', 'del');
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

    /**
     * A figure renders its target then its caption as a separate block
     * (Markdown has no figure element). A BLANK line before the caption is
     * required, not just a newline: against a block-quote target a single
     * newline would make the caption a lazy continuation of the quote and
     * swallow it.
     */
    protected function renderFigure(Figure $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $output = rtrim($output) . "\n\n" . $this->renderCaption($child);
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    protected function renderCaption(Caption $node): string
    {
        return trim($this->renderChildren($node)) . "\n\n";
    }

    /**
     * Markdown has no abbreviation syntax; emit inline abbr HTML so the title
     * is preserved (mirrors how subscript/superscript fall back to inline HTML).
     */
    protected function renderAbbreviation(Abbreviation $node): string
    {
        // DoS guard: once the cumulative expansion bytes would exceed the
        // budget, degrade to plain key text (no <abbr> wrapper, no title).
        // Return the children as ordinary Markdown text - NOT the HTML-escaped
        // form below, which is only correct inside the raw <abbr> element (a
        // bare `&`/`<` in the key must stay literal in Markdown output).
        if (!$this->chargeAbbreviationExpansion($node->getTitle())) {
            return $this->renderChildren($node);
        }

        // The whole element is raw inline HTML, so both the title (attribute)
        // and the text (element content) need HTML escaping, NOT Markdown text
        // escaping: a `"` in the title or a `<` in the text would otherwise
        // break the tag / be misparsed as markup downstream.
        $text = htmlspecialchars($this->renderChildren($node), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($node->getTitle(), ENT_QUOTES, 'UTF-8');

        return '<abbr title="' . $title . '">' . $text . '</abbr>';
    }

    protected function escapeText(string $text): string
    {
        // Escape special Markdown characters in text
        // But be careful not to over-escape
        $escaped = preg_replace('/([\\\\`*_\[\]#~])/', '\\\\$1', $text) ?? $text;

        return preg_replace('/<(?=[A-Za-z\/!?])/', '\\\\<', $escaped) ?? $escaped;
    }
}
