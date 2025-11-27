<?php

declare(strict_types=1);

namespace Djot\Renderer;

use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
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
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Renders AST to plain text
 *
 * Useful for:
 * - Search indexing
 * - SEO meta descriptions
 * - Plain text email fallbacks
 * - Word count / reading time estimation
 */
class PlainTextRenderer
{
    protected string $listItemPrefix = '- ';

    protected string $orderedListItemPrefix = '. ';

    protected string $tableCellSeparator = "\t";

    public function render(Document $document): string
    {
        $text = $this->renderChildren($document);

        // Normalize multiple blank lines to single
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text) . "\n";
    }

    protected function renderNode(Node $node): string
    {
        return match (true) {
            $node instanceof Document => $this->renderChildren($node),
            $node instanceof Paragraph => $this->renderParagraph($node),
            $node instanceof Heading => $this->renderHeading($node),
            $node instanceof CodeBlock => $this->renderCodeBlock($node),
            $node instanceof Comment => '', // Skip comments
            $node instanceof RawBlock => '', // Skip raw blocks (format-specific)
            $node instanceof ListBlock => $this->renderList($node),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof DefinitionList => $this->renderDefinitionList($node),
            $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
            $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
            $node instanceof ThematicBreak => $this->renderThematicBreak(),
            $node instanceof Table => $this->renderTable($node),
            $node instanceof TableRow => $this->renderTableRow($node),
            $node instanceof TableCell => $this->renderTableCell($node),
            $node instanceof LineBlock => $this->renderLineBlock($node),
            $node instanceof Footnote => $this->renderFootnote($node),
            $node instanceof Text => $node->getContent(),
            $node instanceof Code => $node->getContent(),
            $node instanceof Math => $node->getContent(),
            $node instanceof Image => $node->getAlt(),
            $node instanceof Symbol => ':' . $node->getName() . ':',
            $node instanceof FootnoteRef => '[' . $node->getLabel() . ']',
            $node instanceof SoftBreak => ' ',
            $node instanceof HardBreak => "\n",
            $node instanceof RawInline => '', // Skip raw inlines (format-specific)
            default => $this->renderChildren($node),
        };
    }

    protected function renderChildren(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= $this->renderNode($child);
        }

        return $text;
    }

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderHeading(Heading $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        return $node->getContent() . "\n\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $text = '';
        $counter = $node->getStart();

        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    $text .= $counter . $this->orderedListItemPrefix;
                    $counter++;
                } else {
                    $text .= $this->listItemPrefix;
                }
                $text .= trim($this->renderChildren($child)) . "\n";
            }
        }

        return $text . "\n";
    }

    protected function renderListItem(ListItem $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        return '  ' . trim($this->renderChildren($node)) . "\n";
    }

    protected function renderThematicBreak(): string
    {
        return "---\n\n";
    }

    protected function renderTable(Table $node): string
    {
        $text = '';

        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $text .= $this->renderTableRow($child);
            }
        }

        if ($node->hasCaptionChildren()) {
            foreach ($node->getCaptionChildren() as $child) {
                $text .= $this->renderNode($child);
            }
            $text = rtrim($text) . "\n";
        }

        return $text . "\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $cells = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableCell) {
                $cells[] = trim($this->renderChildren($child));
            }
        }

        return implode($this->tableCellSeparator, $cells) . "\n";
    }

    protected function renderTableCell(TableCell $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= trim($this->renderNode($child)) . "\n";
        }

        return $text . "\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        return '[' . $node->getLabel() . ']: ' . trim($this->renderChildren($node)) . "\n";
    }

    /**
     * Set the prefix for unordered list items
     */
    public function setListItemPrefix(string $prefix): void
    {
        $this->listItemPrefix = $prefix;
    }

    /**
     * Set the suffix for ordered list item numbers
     */
    public function setOrderedListItemPrefix(string $prefix): void
    {
        $this->orderedListItemPrefix = $prefix;
    }

    /**
     * Set the separator between table cells
     */
    public function setTableCellSeparator(string $separator): void
    {
        $this->tableCellSeparator = $separator;
    }
}
