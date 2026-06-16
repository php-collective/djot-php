<?php

declare(strict_types=1);

namespace Djot\Parser\Block;

use Djot\Node\Block\TableCell;
use Djot\Parser\Utility\AttributeParser;

/**
 * Parser for table blocks.
 *
 * This class handles parsing of:
 * - Table rows (| cell | cell |)
 * - Table alignments from separator rows
 * - Table cells with code span awareness
 * - Row attributes (|...|{.class})
 * - Cell attributes (|{.class} content |)
 */
class TableParser
{
    /**
     * Check if a line could be a table row.
     * A line must start with | and end with | (optionally followed by row attributes).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line could be a table row
     */
    public function isTableRow(string $line): bool
    {
        // Fast early exit: tables start with |
        if (!isset($line[0]) || $line[0] !== '|') {
            return false;
        }

        // Trailing whitespace after the closing pipe is insignificant (parity
        // with carve-js / carve-rs); strip it before the structural checks.
        $line = rtrim($line, " \t");

        // Strip row attributes if present (|...|{.class})
        $lineWithoutRowAttrs = $this->stripRowAttributes($line);

        // Table rows start and end with |
        if (!preg_match('/^\|.*\|$/', $lineWithoutRowAttrs)) {
            return false;
        }

        // Verify the line truly ends with | outside of code spans
        return $this->lineEndsWithPipeOutsideCodeSpan($lineWithoutRowAttrs);
    }

    /**
     * Strip row attributes from end of line.
     *
     * @param string $line The line to process
     *
     * @return string Line without trailing row attributes
     */
    public function stripRowAttributes(string $line): string
    {
        // Row attributes appear after final pipe: |...|{.class}
        if (preg_match('/^(.*\|)\{([^{}]+)\}\s*$/', $line, $matches)) {
            return $matches[1];
        }

        return $line;
    }

    /**
     * Extract row attributes from end of line.
     *
     * @param string $line The line to process
     *
     * @return array<string, string> Parsed attributes or empty array
     */
    public function extractRowAttributes(string $line): array
    {
        if (preg_match('/\|\{([^{}]+)\}\s*$/', $line, $matches)) {
            return AttributeParser::parse($matches[1]);
        }

        return [];
    }

    /**
     * Check if a line is a separator row (contains |, -, with optional : and spaces).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is a separator row
     */
    public function isSeparatorRow(string $line): bool
    {
        // Trailing whitespace after the closing pipe is insignificant.
        $line = rtrim($line, " \t");

        $len = strlen($line);
        if ($len < 2 || $line[0] !== '|' || $line[$len - 1] !== '|') {
            return false;
        }

        // Every cell must be a delimiter cell: optional whitespace, an optional
        // leading ':', one or more '-', an optional trailing ':', optional
        // whitespace. An EMPTY cell (`|---||`) or any other content disqualifies
        // the row -- it is then an ordinary data row (matches carve-js/carve-rs).
        $cells = $this->parseTableCells($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^\s*:?-+:?\s*$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse table alignments from a separator row.
     *
     * @param string $separatorLine The separator row line
     *
     * @return array<string> Array of alignment constants
     */
    public function parseTableAlignments(string $separatorLine): array
    {
        $alignments = [];
        $cells = $this->parseTableCells($separatorLine);

        foreach ($cells as $cell) {
            $cell = trim($cell);
            if (str_starts_with($cell, ':') && str_ends_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_CENTER;
            } elseif (str_ends_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_RIGHT;
            } elseif (str_starts_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_LEFT;
            } else {
                $alignments[] = TableCell::ALIGN_DEFAULT;
            }
        }

        return $alignments;
    }

    /**
     * Parse separator widths from a separator row for round-trip preservation.
     *
     * @param string $separatorLine The separator row line
     *
     * @return array<int> Array of separator widths (number of dashes per column)
     */
    public function parseSeparatorWidths(string $separatorLine): array
    {
        $widths = [];
        $cells = $this->parseTableCells($separatorLine);

        foreach ($cells as $cell) {
            // Count only the dashes (excluding colons and whitespace)
            $dashes = preg_replace('/[^-]/', '', $cell) ?? '';
            $widths[] = strlen($dashes);
        }

        return $widths;
    }

    /**
     * Parse table cells from a row, respecting code spans and escaped pipes.
     *
     * @param string $line The table row line
     *
     * @return array<string> Array of cell contents
     */
    public function parseTableCells(string $line): array
    {
        // Strip row attributes first
        $line = $this->stripRowAttributes($line);

        // Trailing whitespace after the closing pipe is insignificant.
        $line = rtrim($line, " \t");

        // Remove leading and trailing |
        $line = substr($line, 1, -1);

        // Split by | but not \| and not | inside code spans
        $cells = [];
        $currentCell = '';
        $inCode = false;
        $codeDelimLength = 0;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Track code spans (backticks)
            if ($char === '`' && !$inCode) {
                // Count backticks for code span opener
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $currentCell .= substr($line, $i, $backtickCount);
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                // Check for matching closing backticks
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $currentCell .= substr($line, $i, $backtickCount);
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }

            // Check for escaped pipe
            if ($char === '\\' && $i + 1 < $length && $line[$i + 1] === '|') {
                $currentCell .= '|';
                $i++; // Skip the |

                continue;
            }

            // Cell delimiter (unescaped | outside code span)
            if ($char === '|' && !$inCode) {
                $cells[] = $currentCell;
                $currentCell = '';

                continue;
            }

            $currentCell .= $char;
        }

        // Add the last cell
        $cells[] = $currentCell;

        return $cells;
    }

    /**
     * Parse table cells with their attributes.
     * Cell attributes appear at the start: |{.class} content |
     *
     * @param string $line The table row line
     *
     * @return array<array{content: string, attributes: array<string, string>}> Array of cell data
     */
    public function parseTableCellsWithAttributes(string $line): array
    {
        $cells = $this->parseTableCells($line);
        $result = [];

        foreach ($cells as $cellContent) {
            $attributes = [];
            $content = $cellContent;

            // Check for cell attribute at start: {.class} content
            // Attribute must be immediately at start (no leading whitespace)
            // to distinguish from inline formatting like {=highlight=}
            // Also exclude inline markers: {=...=}, {+...+}, {-...-}, etc.
            // Fast path: only run regex if cell starts with {
            if (isset($cellContent[0]) && $cellContent[0] === '{' && preg_match('/^\{([^{}]+)\}\s*/', $cellContent, $matches)) {
                $inner = $matches[1];
                // Only treat as attribute if it's NOT an inline formatting marker
                // Inline markers have same char at start and end: =text=, +text+, -text-, etc.
                if (!$this->isInlineMarker($inner)) {
                    $attributes = AttributeParser::parse($inner);
                    // Remove the attribute from content
                    $content = substr($cellContent, strlen($matches[0]));
                }
            }

            $result[] = [
                'content' => $content,
                'attributes' => $attributes,
            ];
        }

        return $result;
    }

    /**
     * Check if content inside {...} is an inline formatting marker.
     *
     * Inline markers: =text=, +text+, -text-, ~text~, ^text^, _text_, *text*
     * Also quote markers: ', ", '', ""
     *
     * @param string $inner The content inside {...}
     *
     * @return bool True if it's an inline marker (not an attribute)
     */
    protected function isInlineMarker(string $inner): bool
    {
        // Quote markers: ' or " (any number)
        if (preg_match('/^[\'"]+$/', $inner)) {
            return true;
        }

        // Inline formatting: marker at start and end (=text=, +text+, -text-, ~text~, ^text^)
        if (strlen($inner) >= 3) {
            $firstChar = $inner[0];
            $lastChar = $inner[strlen($inner) - 1];
            $inlineMarkers = ['=', '+', '-', '~', '^', '_', '*'];
            if (in_array($firstChar, $inlineMarkers, true) && $firstChar === $lastChar) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a line has unclosed code spans.
     *
     * @param string $line The line to check
     *
     * @return bool True if there's an unclosed code span
     */
    public function hasUnclosedCodeSpan(string $line): bool
    {
        // Fast path: no backticks means no code spans at all
        if (!str_contains($line, '`')) {
            return false;
        }

        $length = strlen($line);
        $inCode = false;
        $codeDelimLength = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '`' && !$inCode) {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }
        }

        return $inCode;
    }

    /**
     * Parse table cells from a row WITHOUT respecting code spans.
     *
     * This is used for look-ahead when checking if continuation rows
     * can close unclosed code spans. It simply splits on | characters.
     *
     * @param string $line The table row line
     *
     * @return array<string> Array of cell contents
     */
    public function parseTableCellsRaw(string $line): array
    {
        // Strip row attributes first
        $line = $this->stripRowAttributes($line);

        // Trailing whitespace after the closing pipe is insignificant.
        $line = rtrim($line, " \t");

        // Must start with | to be a potential table row
        if (!str_starts_with($line, '|')) {
            return [];
        }

        // Remove leading |
        $line = substr($line, 1);

        // Remove trailing | if present
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        // Simple split on |, handling escaped pipes
        $cells = [];
        $currentCell = '';
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Check for escaped pipe
            if ($char === '\\' && $i + 1 < $length && $line[$i + 1] === '|') {
                $currentCell .= '|';
                $i++; // Skip the |

                continue;
            }

            // Cell delimiter
            if ($char === '|') {
                $cells[] = $currentCell;
                $currentCell = '';

                continue;
            }

            $currentCell .= $char;
        }

        // Add the last cell
        $cells[] = $currentCell;

        return $cells;
    }

    /**
     * Check if a line looks like a table row but has unclosed code spans.
     *
     * This is used to detect rows where a code span starts but continues
     * into a continuation row.
     *
     * @param string $line The line to check
     *
     * @return bool True if line looks like a table row but has unclosed code span
     */
    public function isPotentialTableRowWithUnclosedCodeSpan(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] !== '|') {
            return false;
        }

        // Strip row attributes if present
        $lineWithoutRowAttrs = $this->stripRowAttributes($line);

        // Must start with |
        if (!str_starts_with($lineWithoutRowAttrs, '|')) {
            return false;
        }

        // Check if it has an unclosed code span
        return $this->hasUnclosedCodeSpan($lineWithoutRowAttrs);
    }

    /**
     * Check if combining base content with continuation content results in balanced code spans.
     *
     * @param string $baseContent The base cell content
     * @param string $continuationContent The continuation cell content
     *
     * @return bool True if the combined content has balanced code spans
     */
    public function combinedContentHasBalancedCodeSpans(string $baseContent, string $continuationContent): bool
    {
        $combined = $baseContent . ' ' . $continuationContent;

        return !$this->hasUnclosedCodeSpan($combined);
    }

    /**
     * Validate that merged cell contents result in a valid table row.
     *
     * @param array<string> $mergedCells The merged cell contents
     *
     * @return bool True if all cells have balanced code spans
     */
    public function mergedCellsAreValid(array $mergedCells): bool
    {
        foreach ($mergedCells as $cell) {
            if ($this->hasUnclosedCodeSpan($cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a cell contains a rowspan marker (^).
     * A cell with only ^ (and optional whitespace) indicates it's spanned from the cell above.
     *
     * @param string $cellContent The cell content to check
     *
     * @return bool True if the cell is a rowspan marker
     */
    public function isRowspanMarker(string $cellContent): bool
    {
        return trim($cellContent) === '^';
    }

    /**
     * Check if a cell contains a colspan marker (<).
     * A cell with only < (and optional whitespace) indicates it's spanned from the cell to the left.
     * The < points toward the source cell, consistent with ^ pointing up toward its source.
     *
     * @param string $cellContent The cell content to check
     *
     * @return bool True if the cell is a colspan marker
     */
    public function isColspanMarker(string $cellContent): bool
    {
        return trim($cellContent) === '<';
    }

    /**
     * Check if a line is a continuation row (starts with +).
     * Continuation rows use + prefix instead of | to signal that the contents
     * get added to the cells from the previous row.
     *
     * Syntax: + cell1 | cell2 | cell3 |
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is a continuation row
     */
    public function isContinuationRow(string $line): bool
    {
        // Continuation rows start with + and end with |
        $trimmed = ltrim($line);

        if (!str_starts_with($trimmed, '+')) {
            return false;
        }

        // Check for standard case: ends with | outside code spans
        if ($this->lineEndsWithPipeOutsideCodeSpan($trimmed)) {
            return true;
        }

        // Also accept continuation rows that might close a code span from the previous row
        // These have an "orphan" closing backtick that makes the | look like it's inside a code span
        return $this->isPotentialContinuationRowWithCodeSpan($trimmed);
    }

    /**
     * Check if a line is a potential continuation row that contains code span syntax.
     *
     * This handles the case where a continuation row closes a code span started
     * in the previous row.
     *
     * @param string $line The trimmed line (starting with +)
     *
     * @return bool True if this looks like a continuation row with code span
     */
    protected function isPotentialContinuationRowWithCodeSpan(string $line): bool
    {
        // Must start with + and contain |
        if (!str_starts_with($line, '+') || !str_contains($line, '|')) {
            return false;
        }

        // Check if it ends with | (even if inside "code span")
        $trimmed = rtrim($line);

        return str_ends_with($trimmed, '|');
    }

    /**
     * Parse cells from a continuation row.
     * Continuation rows start with + instead of |.
     *
     * @param string $line The continuation row line (starting with +)
     *
     * @return array<string> Array of cell contents
     */
    public function parseContinuationCells(string $line): array
    {
        $trimmed = ltrim($line);

        // Replace leading + with | for parsing
        $normalizedLine = '|' . substr($trimmed, 1);

        return $this->parseTableCells($normalizedLine);
    }

    /**
     * Merge cell contents from continuation lines.
     * Each cell's content is joined with a space.
     *
     * @param array<string> $baseCells The cells from the base row
     * @param array<string> $continuationCells The cells from the continuation row
     *
     * @return array<string> Merged cell contents
     */
    public function mergeCellContents(array $baseCells, array $continuationCells): array
    {
        $result = [];
        $count = max(count($baseCells), count($continuationCells));

        for ($i = 0; $i < $count; $i++) {
            $base = trim($baseCells[$i] ?? '');
            $continuation = trim($continuationCells[$i] ?? '');

            if ($base !== '' && $continuation !== '') {
                // Join with space (like soft breaks in paragraphs)
                $result[] = $base . ' ' . $continuation;
            } elseif ($continuation !== '') {
                $result[] = $continuation;
            } else {
                $result[] = $base;
            }
        }

        return $result;
    }

    /**
     * Check if a line ends with | outside of code spans.
     * Used to verify table row syntax (| `a |` is not a table because final | is in code span).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line ends with | outside code spans
     */
    public function lineEndsWithPipeOutsideCodeSpan(string $line): bool
    {
        $length = strlen($line);

        // Fast path: no backticks means no code spans to worry about
        if (!str_contains($line, '`')) {
            return $line[$length - 1] === '|';
        }

        $inCode = false;
        $codeDelimLength = 0;
        $lastPipeOutsideCode = -1;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Track code spans
            if ($char === '`' && !$inCode) {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }

            // Track pipe positions outside code spans
            if ($char === '|' && !$inCode) {
                $lastPipeOutsideCode = $i;
            }
        }

        // The line ends with | outside code span if the last | is at the end
        return $lastPipeOutsideCode === $length - 1;
    }
}
