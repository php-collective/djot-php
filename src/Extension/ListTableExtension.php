<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Div;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Text;
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
 * Cells may span rows and columns using the same markers djot-php's native
 * pipe tables use, with the same continuation semantics: a cell whose sole
 * inline content is a lone `^` merges into the cell ABOVE (rowspan), and a
 * lone `<` merges into the cell to the LEFT (colspan). `colspan=3` is written
 * as two trailing `<` cells; `rowspan=N` as `N - 1` `^` cells in the rows
 * below the origin. Escape the marker (`\^`, `\<`) or attach an attribute to
 * keep it literal - an attributed cell is never treated as a span marker. The
 * resulting `<table>` matches the span markup the equivalent pipe table emits.
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
        //
        // Validate EVERY row before mutating the AST. `extractCells` collects any
        // stray block that should move into the previous cell as a deferred
        // append rather than applying it immediately - so a later row that forces
        // a defer leaves the original tree untouched. Otherwise the default div
        // renderer would render that stray block twice (once in its original
        // position, once inside the cell it was appended to), duplicating the
        // user's content for a malformed `list-table`.
        $rows = [];
        $pendingAppends = [];
        foreach ($outerList->getChildren() as $rowItem) {
            if (!$rowItem instanceof ListItem) {
                continue;
            }

            [$cells, $appends] = $this->extractCells($rowItem);

            // A row without an inner cell list (e.g. `- Row label` with direct
            // content rather than nested `- - cell` items) yields no cells. Such
            // a structure is not a clean table; defer to the default div so the
            // row's content is never silently dropped into an empty `<tr>`. We
            // return BEFORE applying any pending append, leaving the AST intact.
            if ($cells === []) {
                return null;
            }

            $rows[] = $cells;
            foreach ($appends as $append) {
                $pendingAppends[] = $append;
            }
        }

        if ($rows === []) {
            return null;
        }

        // All rows are valid - we will claim the div. Now it is safe to move the
        // stray blocks into their cells; the default renderer will never run.
        foreach ($pendingAppends as [$cell, $block]) {
            $cell->appendChild($block);
        }

        $headerRows = max(0, (int)($node->getAttribute('header-rows') ?? '0'));
        $headerCols = max(0, (int)($node->getAttribute('header-cols') ?? '0'));

        // Resolve `^` (rowspan) / `<` (colspan) span markers into a placed grid,
        // reusing the same continuation semantics as native pipe tables. Each
        // placed entry carries the origin cell plus its resolved span and the
        // effective column it starts at; marker cells are consumed (omitted).
        // `headerRows` is passed so a rowspan never crosses the thead/tbody
        // boundary (HTML cells cannot reliably span row groups).
        [$grid] = $this->resolveSpans($rows, $headerRows);

        $lines = [];

        $caption = $node->getAttribute('caption');
        if ($caption !== null && trim($caption) !== '') {
            $lines[] = '  <caption>' . $this->escapeHtml($caption) . '</caption>';
        }

        $renderRow = function (array $placedCells, bool $isHeaderRow) use ($renderer, $headerCols): string {
            $html = '';
            foreach ($placedCells as $placed) {
                $isHeaderCell = $isHeaderRow || $placed['col'] < $headerCols;
                $tag = $isHeaderCell ? 'th' : 'td';
                $spanAttrs = '';
                if ($placed['rowspan'] > 1) {
                    $spanAttrs .= ' rowspan="' . $placed['rowspan'] . '"';
                }
                if ($placed['colspan'] > 1) {
                    $spanAttrs .= ' colspan="' . $placed['colspan'] . '"';
                }
                $cellAttrs = $this->renderCellAttributes($placed['cell'], $renderer);
                $content = $this->renderCell($placed['cell'], $renderer);
                $html .= '<' . $tag . $cellAttrs . $spanAttrs . '>' . $content . '</' . $tag . '>';
            }

            return '<tr>' . $html . '</tr>';
        };

        $headRows = array_slice($grid, 0, $headerRows);
        $bodyRows = array_slice($grid, $headerRows);

        if ($headRows !== []) {
            $thead = '';
            foreach ($headRows as $placedCells) {
                $thead .= $renderRow($placedCells, true);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        if ($bodyRows !== []) {
            $tbody = '';
            foreach ($bodyRows as $placedCells) {
                $tbody .= '    ' . $renderRow($placedCells, false) . "\n";
            }
            $lines[] = "  <tbody>\n" . rtrim($tbody, "\n") . "\n  </tbody>";
        }

        $attrs = $this->renderTableAttributes($node, $renderer);

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    /**
     * Resolve `^` / `<` span markers across a ragged grid of row cells.
     *
     * Mirrors the continuation semantics of native pipe tables (see
     * `BlockParser` / `TableParser`): walking each row left to right, a `<`
     * cell grows the cell to its left (colspan) and is omitted, and a `^` cell
     * grows the cell directly above in the same effective column (rowspan) and
     * is omitted. Effective columns account for colspans and for rowspans
     * reserved by earlier rows, exactly like the pipe-table grid. Leading `<`
     * with no cell to the left, and `^` with no origin above, degrade to an
     * empty cell rather than being dropped (pipe-table parity).
     *
     * Returns `[$grid, $columnCount]` where `$grid` is a list of rows, each a
     * list of placed cells `['cell' => ListItem, 'col' => int, 'rowspan' =>
     * int, 'colspan' => int]` in left-to-right order, and `$columnCount` is the
     * effective width of the widest row. Short rows are padded with empty cells
     * so the grid stays rectangular (no content dropped).
     *
     * A rowspan is clamped at the `$headerRows` boundary: a `^` in the first
     * body row whose origin lives in the header rows does NOT extend that header
     * cell into the body. HTML cells cannot reliably span across `<thead>` /
     * `<tbody>` (browsers misrender), so the `^` degrades to a fresh empty body
     * cell instead and the header cell's rowspan stays within the header rows.
     *
     * @param array<array<\Djot\Node\Block\ListItem>> $rows
     * @param int $headerRows
     *
     * @return array{0: array<array<array{cell: \Djot\Node\Block\ListItem, col: int, rowspan: int, colspan: int}>>, 1: int}
     */
    protected function resolveSpans(array $rows, int $headerRows = 0): array
    {
        // Flat list of origin descriptors. Each is referenced from the grid by
        // its integer index, so the rowspan/colspan mutations below stay on a
        // single typed list instead of a nested-array shape.
        $descriptors = new SpanDescriptors();

        // grid[row][col] = descriptor index that occupies this grid position,
        // whether it originates here, spans in from the left (colspan), or spans
        // in from above (rowspan). Every column of every row is occupied, so a
        // `^` only ever consults the row immediately above it.
        $grid = [];
        // Per-row, left-to-right list of descriptor indices that ORIGINATE in
        // that row (authored cells and ragged padding), used to walk rows for
        // rendering. Rowspan/colspan continuations are NOT origins here.
        $rowOrigins = [];

        // Running effective width. Earlier rows are padded up to this so a later
        // `^` can attach to the empty cell directly above it, mirroring how the
        // equivalent pipe table pads ragged rows with real empty cells.
        $width = 0;

        foreach ($rows as $rowIndex => $cells) {
            $rowOrigins[$rowIndex] = [];
            $col = 0;
            $lastOriginIndex = null;

            // Each origin grows at most once per row even when several `^`
            // markers fall under the columns a single wide cell covers.
            $extendedThisRow = [];

            foreach ($cells as $cell) {
                $marker = $this->spanMarker($cell);

                // Clamp a rowspan at the header/body boundary. A `^` in the first
                // body row whose origin lives in the header rows would extend a
                // `<thead>` cell down into `<tbody>`; HTML cannot reliably span a
                // cell across row groups (browsers misrender), so here the `^`
                // is NOT a rowspan - it degrades to a fresh empty body cell, and
                // the header cell keeps its rowspan within the header rows.
                $crossesHeaderBoundary = false;
                if ($marker === '^' && isset($grid[$rowIndex - 1][$col]) && $rowIndex >= $headerRows) {
                    $originAbove = $descriptors->get($grid[$rowIndex - 1][$col]);
                    $crossesHeaderBoundary = $originAbove['row'] < $headerRows;
                }

                if ($marker === '^' && !$crossesHeaderBoundary && isset($grid[$rowIndex - 1][$col])) {
                    // Rowspan: the descriptor directly above (which may itself be
                    // a colspan origin) extends down into this row. A marker maps
                    // 1:1 to a source column - it advances the cursor by one and
                    // does not skip - mirroring the native pipe table's per-column
                    // rowspan resolution. Grow the origin once, then reserve its
                    // WHOLE rectangle here so a real cell never lands inside it.
                    $originIndex = $grid[$rowIndex - 1][$col];
                    if (!isset($extendedThisRow[$originIndex])) {
                        $descriptors->growRowspan($originIndex);
                        $extendedThisRow[$originIndex] = true;

                        $origin = $descriptors->get($originIndex);
                        for ($c = $origin['col']; $c < $origin['col'] + $origin['colspan']; $c++) {
                            $grid[$rowIndex][$c] = $originIndex;
                        }
                    }

                    $col++;
                    $lastOriginIndex = null;

                    continue;
                }

                // Real cells (and degraded markers) skip columns already reserved
                // by rowspan rectangles - their own row's or earlier rows'.
                //
                // Note: for malformed input where a real cell would land inside a
                // rowspan rectangle (a lone `^` under a colspan>1 cell, then more
                // cells in the same row), the native pipe table drops that cell;
                // here it is relocated to the next free column instead. We keep
                // the content deliberately - the extension's guarantee is never to
                // silently drop authored content - at the cost of one column of
                // pipe-table divergence on this malformed shape. Well-formed spans
                // (a `^` under every column a wide cell covers) match the pipe
                // table exactly.
                while (isset($grid[$rowIndex][$col])) {
                    $col++;
                }

                if ($marker === '<' && $lastOriginIndex !== null) {
                    // Colspan: grow the cell to the left, claim this column for it.
                    $descriptors->growColspan($lastOriginIndex);
                    $grid[$rowIndex][$col] = $lastOriginIndex;
                    $col++;

                    continue;
                }

                // A normal cell, a leading `<` with no left neighbor, a `^` with
                // no cell above, or a `^` clamped at the header/body boundary:
                // the markers degrade to an empty cell. A degraded marker is NOT
                // a colspan target, so a run of leading `<` yields one empty cell
                // each (pipe-table parity).
                $isEmpty = $marker !== null;
                $index = $descriptors->add($isEmpty ? $this->emptyCell() : $cell, $rowIndex, $col);
                $grid[$rowIndex][$col] = $index;
                $rowOrigins[$rowIndex][] = $index;
                $lastOriginIndex = $isEmpty ? null : $index;
                $col++;
            }

            $width = max($width, $col);

            // Pad this row up to the running width with empty origin cells so a
            // later `^` always has a real cell directly above it to extend.
            $this->padRow($descriptors, $grid, $rowOrigins, $rowIndex, $width);
        }

        return $this->buildGrid($descriptors, $rowOrigins, $grid, $width);
    }

    /**
     * Pad a row up to the target width with empty origin cells.
     *
     * Fills any free columns (gaps left by ragged input or by spans that did not
     * reach the running width) with fresh empty cells so every column of every
     * processed row is occupied. This is what lets a later `^` attach to the
     * cell directly above it, matching the pipe table's ragged-row padding.
     *
     * @param \Djot\Extension\SpanDescriptors $descriptors
     * @param array<int, array<int, int>> $grid
     * @param array<int, array<int, int>> $rowOrigins
     * @param int $width
     * @param int $rowIndex
     */
    protected function padRow(SpanDescriptors $descriptors, array &$grid, array &$rowOrigins, int $rowIndex, int $width): void
    {
        for ($col = 0; $col < $width; $col++) {
            if (isset($grid[$rowIndex][$col])) {
                continue;
            }

            $index = $descriptors->add($this->emptyCell(), $rowIndex, $col);
            $grid[$rowIndex][$col] = $index;
            $rowOrigins[$rowIndex][] = $index;
        }
    }

    /**
     * Assemble the rectangular render grid from the resolved descriptors.
     *
     * Walks each row's originating cells in order and pads with trailing empty
     * cells up to the widest effective column count, so ragged input still
     * yields a rectangular table and no content is dropped, matching the
     * no-span ragged behavior.
     *
     * @param \Djot\Extension\SpanDescriptors $descriptors
     * @param array<int, array<int, int>> $rowOrigins
     * @param array<int, array<int, int>> $grid
     * @param int $columnCount
     *
     * @return array{0: array<array<array{cell: \Djot\Node\Block\ListItem, col: int, rowspan: int, colspan: int}>>, 1: int}
     */
    protected function buildGrid(SpanDescriptors $descriptors, array $rowOrigins, array $grid, int $columnCount): array
    {
        $rendered = [];
        foreach ($rowOrigins as $rowIndex => $indices) {
            $cells = [];
            foreach ($indices as $index) {
                $cells[] = $descriptors->get($index);
            }

            // Highest column this row already covers (origins + rowspans from
            // above); pad the remaining gap with empty cells.
            $covered = 0;
            foreach (($grid[$rowIndex] ?? []) as $c => $_) {
                $covered = max($covered, $c + 1);
            }

            for ($c = $covered; $c < $columnCount; $c++) {
                $cells[] = [
                    'cell' => $this->emptyCell(),
                    'col' => $c,
                    'rowspan' => 1,
                    'colspan' => 1,
                ];
            }

            $rendered[$rowIndex] = $cells;
        }

        return [$rendered, $columnCount];
    }

    /**
     * Create an empty placeholder cell (an empty list item with no content).
     */
    protected function emptyCell(): ListItem
    {
        return new ListItem();
    }

    /**
     * Detect a span marker cell.
     *
     * Returns `'^'` or `'<'` when the cell's sole inline content is exactly that
     * marker - i.e. a single attribute-free paragraph whose only child is a Text
     * node equal to the marker. Anything else (escaped `\^`/`\<` parses to an
     * EscapedText node, an attribute wraps the text in a Span) is not a marker
     * and returns null, so the literal `^`/`<` content is kept.
     *
     * A cell that carries its OWN attributes (authored `-{.x} ^`, where the
     * attribute lands on the cell's list item, not its paragraph) is never a
     * span marker either - the documented escape rule keeps the literal `^`/`<`
     * content and the cell's attributes.
     */
    protected function spanMarker(ListItem $cell): ?string
    {
        // An attributed cell is literal content, never a span marker. The
        // attribute sits on the list item itself (e.g. `-{.x} ^`), so the
        // paragraph below may still look like a bare marker - check here first.
        if ($cell->getAttributes() !== []) {
            return null;
        }

        $children = $cell->getChildren();
        if (count($children) !== 1) {
            return null;
        }

        $paragraph = $children[0];
        if (!$paragraph instanceof Paragraph || $paragraph->getAttributes() !== []) {
            return null;
        }

        $inline = $paragraph->getChildren();
        if (count($inline) !== 1 || !$inline[0] instanceof Text) {
            return null;
        }

        $content = $inline[0]->getContent();
        if ($content === '^' || $content === '<') {
            return $content;
        }

        return null;
    }

    /**
     * Extract the cells of a row.
     *
     * A row like `- - A` / ` - B` parses to the outer item holding ONE inner
     * ListBlock whose items are the cells. The flatten-all-inner-lists loop
     * keeps multiple inner lists working too. Any non-list block sibling (e.g.
     * a trailing paragraph the parser left outside the inner list) belongs to
     * the most recently opened cell so multi-block content is never dropped.
     *
     * This method does NOT mutate the AST: it returns the cells plus a list of
     * pending `[cell, block]` appends. The caller applies them only once it has
     * decided to claim the div, so a deferred render leaves the tree untouched
     * (no duplicated content). See `renderListTable`.
     *
     * @return array{0: array<\Djot\Node\Block\ListItem>, 1: array<array{0: \Djot\Node\Block\ListItem, 1: \Djot\Node\Node}>}
     */
    protected function extractCells(ListItem $rowItem): array
    {
        $cells = [];
        $appends = [];
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
            // Record it; the caller applies the move only if the div is claimed.
            if ($cells !== []) {
                $appends[] = [$cells[count($cells) - 1], $child];
            }
        }

        return [$cells, $appends];
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
     * Build the per-cell attributes for a `<td>`/`<th>`.
     *
     * A cell authored with its own attributes (`-{.x} ^`, `-{#id} value`) emits
     * them onto the cell tag, in source order, with the same safe-mode filtering
     * the core renderer applies. The structural span attributes (`rowspan` /
     * `colspan`) are added separately by the caller and are not part of this.
     */
    protected function renderCellAttributes(ListItem $cell, HtmlRenderer $renderer): string
    {
        $attrs = $cell->getAttributes();
        if ($attrs === []) {
            return '';
        }

        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
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
