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
     * @return array<string, mixed> Parsed attributes or empty array
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
        return preg_match('/^\|[\s:|-]+\|$/', $line) === 1 && str_contains($line, '-');
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
     * @return array<array{content: string, attributes: array<string, mixed>}> Array of cell data
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
            if (preg_match('/^\{([^{}]+)\}\s*/', $cellContent, $matches)) {
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
