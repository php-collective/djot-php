<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Renderer\HtmlRenderer;

/**
 * Renders `::: list-table` blocks as real HTML `<table>` markup, with the
 * table authored as a nested list so that cells can hold full block content
 * (paragraphs, lists, code, …) that the native pipe-table syntax cannot.
 *
 * A `list-table` div is authored as an outer list where each outer item is a
 * row and each inner item is a cell:
 *
 * ```
 * {caption="Quarterly results" header-rows=1}
 * ::: list-table
 * - - Region
 *   - Notes
 * - - EMEA
 *   - Strong quarter.
 *
 *     Drivers:
 *
 *     - new logos
 *     - renewals
 * :::
 * ```
 *
 * The caption, `header-rows` and `header-cols` are read from the div's
 * attributes, which sit on the PRECEDING attribute line (djot has no
 * `::: type "title"` parse - a quoted title would land in the class name).
 *
 * `caption="..."` emits a `<caption>`; `header-rows=N` promotes the first N
 * rows to `<thead>`/`<th>`, and `header-cols=N` promotes the first N cells of
 * every row to row-header `<th>`. Single-paragraph cells collapse to inline
 * content (`<td>text</td>`); multi-block cells keep their block wrappers.
 *
 * Only `::: list-table` divs whose sole block child is the table list are
 * claimed; every other div defers to the core renderer. When this extension is
 * not registered the block degrades to the default `<div class="list-table">`
 * holding the literal nested list.
 *
 * Only applies to HTML output.
 *
 * Example:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new ListTableExtension());
 * ```
 */
class ListTableExtension implements ExtensionInterface
{
    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'list-table';

    public function register(DjotConverter $converter): void
    {
        // Only applies to HTML output - other renderers render the div normally.
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            // Only claim `::: list-table` blocks; everything else defers to the
            // core div renderer (and any other extension that wants it).
            if (!$node->hasClass(self::KIND)) {
                return;
            }

            $html = $this->renderListTable($node, $renderer);
            if ($html === null) {
                // No usable outer list found; defer to the default div renderer
                // so content is never silently dropped.
                return;
            }

            $event->setHtml($html);
        });
    }

    /**
     * Render the `<table>` for a `list-table` div, or null to defer.
     */
    protected function renderListTable(Div $node, HtmlRenderer $renderer): ?string
    {
        // Claim the div only when its sole block child is the table list. If it
        // holds extra siblings (a stray paragraph before/after the list, etc.)
        // defer to the default div renderer so that content is never silently
        // dropped - the block then degrades to the literal nested-list div.
        $children = $node->getChildren();
        if (count($children) !== 1 || !$children[0] instanceof ListBlock) {
            return null;
        }
        $outerList = $children[0];

        // Each outer list item is a row; its cells are the items of the inner
        // ListBlock children, in document order. djot-php yields exactly one
        // inner list per row, but the flatten-all-inner-lists path stays for
        // robustness.
        $rows = [];
        foreach ($outerList->getChildren() as $rowItem) {
            if (!$rowItem instanceof ListItem) {
                continue;
            }

            $cells = $this->extractCells($rowItem);

            // A row without an inner cell list (e.g. `- Row label` with direct
            // content rather than nested `- - cell` items) yields no cells. Such
            // a structure is not a clean table; defer to the default div so the
            // row's content is never silently dropped into an empty `<tr>`.
            if ($cells === []) {
                return null;
            }

            $rows[] = $cells;
        }

        if ($rows === []) {
            return null;
        }

        $headerRows = max(0, (int)($node->getAttribute('header-rows') ?? '0'));
        $headerCols = max(0, (int)($node->getAttribute('header-cols') ?? '0'));

        // Ragged rows: pad short rows with empty cells to the widest row so no
        // content is dropped and the grid stays rectangular.
        $columnCount = 0;
        foreach ($rows as $cells) {
            $columnCount = max($columnCount, count($cells));
        }

        $lines = [];

        $caption = $node->getAttribute('caption');
        if ($caption !== null && trim($caption) !== '') {
            $lines[] = '  <caption>' . $this->escapeHtml($caption) . '</caption>';
        }

        $renderRow = function (array $cells, bool $isHeaderRow) use ($renderer, $headerCols, $columnCount): string {
            $html = '';
            for ($i = 0; $i < $columnCount; $i++) {
                $isHeaderCell = $isHeaderRow || $i < $headerCols;
                $tag = $isHeaderCell ? 'th' : 'td';
                $cell = $cells[$i] ?? null;
                $content = $cell !== null ? $this->renderCell($cell, $renderer) : '';
                $html .= '<' . $tag . '>' . $content . '</' . $tag . '>';
            }

            return '<tr>' . $html . '</tr>';
        };

        $headRows = array_slice($rows, 0, $headerRows);
        $bodyRows = array_slice($rows, $headerRows);

        if ($headRows !== []) {
            $thead = '';
            foreach ($headRows as $cells) {
                $thead .= $renderRow($cells, true);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        if ($bodyRows !== []) {
            $tbody = '';
            foreach ($bodyRows as $cells) {
                $tbody .= '    ' . $renderRow($cells, false) . "\n";
            }
            $lines[] = "  <tbody>\n" . rtrim($tbody, "\n") . "\n  </tbody>";
        }

        $attrs = $this->renderTableAttributes($node, $renderer);

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    /**
     * Extract the cells of a row.
     *
     * A row like `- - A` / ` - B` parses to the outer item holding ONE inner
     * ListBlock whose items are the cells. The flatten-all-inner-lists loop
     * keeps multiple inner lists working too. Any non-list block sibling (e.g.
     * a trailing paragraph the parser left outside the inner list) is appended
     * to the most recently opened cell so multi-block content is never dropped.
     *
     * @return array<\Djot\Node\Block\ListItem>
     */
    protected function extractCells(ListItem $rowItem): array
    {
        $cells = [];
        foreach ($rowItem->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                foreach ($child->getChildren() as $cellItem) {
                    if ($cellItem instanceof ListItem) {
                        $cells[] = $cellItem;
                    }
                }

                continue;
            }

            // A stray block following the inner list belongs to the last cell.
            if ($cells !== []) {
                $cells[count($cells) - 1]->appendChild($child);
            }
        }

        return $cells;
    }

    /**
     * Render a single cell's content.
     *
     * A cell whose only child is an attribute-free paragraph collapses to its
     * inline content (no `<p>` wrapper), matching tight list-item/table-cell
     * rendering. Otherwise the block children render normally and keep their
     * wrappers.
     */
    protected function renderCell(ListItem $cell, HtmlRenderer $renderer): string
    {
        $children = $cell->getChildren();

        if (count($children) === 1 && $children[0] instanceof Paragraph && $children[0]->getAttributes() === []) {
            $html = rtrim($renderer->renderNodeFragment($children[0]), "\n");

            // Strip the single <p>…</p> wrapper to inline the content.
            if (preg_match('/^<p>(.*)<\/p>$/s', $html, $m) === 1) {
                return $m[1];
            }

            return $html;
        }

        $html = '';
        foreach ($children as $child) {
            $html .= $renderer->renderNodeFragment($child);
        }

        return rtrim($html, "\n");
    }

    /**
     * Build the `<table>` tag attributes.
     *
     * Drops the structural attributes consumed by this extension (`caption`,
     * `header-rows`, `header-cols`) and the auto `list-table` class (the
     * `<table>` tag is itself the styling hook); preserves any sibling classes
     * and other attributes in source order. Applies the same safe-mode
     * filtering the core renderer does.
     */
    protected function renderTableAttributes(Div $node, HtmlRenderer $renderer): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['caption'], $attrs['header-rows'], $attrs['header-cols']);

        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        if (isset($attrs['class'])) {
            $classes = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['class'])) ?: [],
                static fn (string $class): bool => $class !== '' && $class !== self::KIND,
            ));

            if ($classes === []) {
                unset($attrs['class']);
            } else {
                $attrs['class'] = implode(' ', $classes);
            }
        }

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escapeHtml((string)$key) . '="' . $renderer->escapeAttribute((string)$value) . '"';
        }

        return $html;
    }

    /**
     * Escape text for HTML content (caption / attribute names).
     *
     * Matches the core renderer's `escape()`: escapes only `<`, `>`, `&`
     * (ENT_NOQUOTES, djot keeps quotes literal) and converts the escaped-space
     * placeholder to `&nbsp;`.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }
}
