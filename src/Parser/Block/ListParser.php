<?php

declare(strict_types=1);

namespace Djot\Parser\Block;

use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;

/**
 * Parser for list blocks (bullet, ordered, task lists).
 *
 * This class handles parsing of:
 * - Bullet lists (-, *, +)
 * - Ordered lists (1., 1), (1), roman numerals, alphabetical)
 * - Task lists (- [ ], - [x])
 *
 * Definition lists are handled by DefinitionListParser.
 */
class ListParser
{
    /**
     * Roman numeral values for conversion
     *
     * @var array<string, int>
     */
    protected const ROMAN_VALUES = [
        'I' => 1,
        'V' => 5,
        'X' => 10,
        'L' => 50,
        'C' => 100,
        'D' => 500,
        'M' => 1000,
    ];

    /**
     * Characters used in roman numerals (lowercase)
     *
     * @var string
     */
    protected const ROMAN_CHARS = 'ivxlcdm';

    /**
     * Parse a list item marker from a line.
     *
     * @param string $line The line to parse
     *
     * Attributes in curly braces that immediately follow the marker (no space
     * before the brace) attach to the list item itself, per the djot proposal
     * jgm/djot#262, e.g. `+{.blue} text` or `(a){.bar} text`. They are returned
     * raw (without the braces) in the `attrs` key; a space before the brace
     * instead makes it ordinary item content (a block attribute), so it is not
     * captured here.
     *
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, attrs?: string}|null
     */
    public function parseListItemMarker(string $line): ?array
    {
        // Task list: - [.] where . is any single character
        // Standard markers: ' ' (unchecked), 'x'/'X' (checked)
        // Extended markers: '-' (cancelled), '/' (partial), '>' (deferred), etc.
        if (preg_match('/^(?<marker>[-*+]) +\[(?<task>.)\](?:\{(?<attrs>[^{}]+)\})? +(?<content>.*)$/', $line, $matches)) {
            $taskMarker = $matches['task'];

            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_TASK,
                'marker' => $matches['marker'],
                'content' => $matches['content'],
                'checked' => strtolower($taskMarker) === 'x',
                'taskMarker' => $taskMarker,
            ], $matches['attrs']);
        }

        // Bullet list: -, +, or *
        // A marker followed by a space (or end of line) is a valid item; a bare
        // marker alone on its line is an empty item (djot allows marker + newline).
        if (preg_match('/^([-*+])(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            $marker = $matches[1];
            $content = $matches[3] ?? '';

            // Don't treat as list if content ends with same marker (likely emphasis)
            if ($marker === '*' || $marker === '-') {
                $trimmed = rtrim($content);
                if ($trimmed !== '' && substr($trimmed, -1) === $marker) {
                    $inner = substr($trimmed, 0, -1);
                    if (trim($inner) !== '' && !str_contains($inner, "\n")) {
                        return null;
                    }
                }
            }

            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $marker,
                'content' => $content,
            ], $matches[2] ?? '');
        }

        // Ordered list: 1. or 1) or (1) - bare marker (no content) is an empty item.
        if (preg_match('/^(\d+)([.)])(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[4] ?? '',
                'start' => (int)$matches[1],
            ], $matches[3] ?? '');
        }

        if (preg_match('/^\((\d+)\)(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => '()',
                'content' => $matches[3] ?? '',
                'start' => (int)$matches[1],
            ], $matches[2] ?? '');
        }

        // Roman numeral ordered list
        if (preg_match('/^([ivxlcdmIVXLCDM]+)([.)])(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            $roman = $matches[1];
            $isLower = ctype_lower($roman[0]);
            $start = $this->romanToInt(strtoupper($roman));
            if ($start > 0) {
                $result = [
                    'type' => ListBlock::TYPE_ORDERED,
                    'marker' => $matches[2],
                    'content' => $matches[4] ?? '',
                    'start' => $start,
                    'style' => $isLower ? 'i' : 'I',
                ];
                if (strlen($roman) === 1) {
                    $alphaStart = ord(strtolower($roman)) - ord('a') + 1;
                    $result['ambiguous'] = true;
                    $result['alpha_start'] = $alphaStart;
                    $result['alpha_style'] = $isLower ? 'a' : 'A';
                }

                return $this->withMarkerAttrs($result, $matches[3] ?? '');
            }
        }

        if (preg_match('/^\(([ivxlcdmIVXLCDM]+)\)(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            $roman = $matches[1];
            $isLower = ctype_lower($roman[0]);
            $start = $this->romanToInt(strtoupper($roman));
            if ($start > 0) {
                $result = [
                    'type' => ListBlock::TYPE_ORDERED,
                    'marker' => '()',
                    'content' => $matches[3] ?? '',
                    'start' => $start,
                    'style' => $isLower ? 'i' : 'I',
                ];
                if (strlen($roman) === 1) {
                    $alphaStart = ord(strtolower($roman)) - ord('a') + 1;
                    $result['ambiguous'] = true;
                    $result['alpha_start'] = $alphaStart;
                    $result['alpha_style'] = $isLower ? 'a' : 'A';
                }

                return $this->withMarkerAttrs($result, $matches[2] ?? '');
            }
        }

        // Alpha ordered list: a. or A. or a) or A) or (a) or (A)
        if (preg_match('/^([a-zA-Z])([.)])(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            $letter = $matches[1];
            $isLower = ctype_lower($letter);
            $start = ord(strtolower($letter)) - ord('a') + 1;

            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[4] ?? '',
                'start' => $start,
                'style' => $isLower ? 'a' : 'A',
            ], $matches[3] ?? '');
        }

        if (preg_match('/^\(([a-zA-Z])\)(?:\{([^{}]+)\})?(?: +(.*))?$/', $line, $matches)) {
            $letter = $matches[1];
            $isLower = ctype_lower($letter);
            $start = ord(strtolower($letter)) - ord('a') + 1;

            return $this->withMarkerAttrs([
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => '()',
                'content' => $matches[3] ?? '',
                'start' => $start,
                'style' => $isLower ? 'a' : 'A',
            ], $matches[2] ?? '');
        }

        // Definition list: `: term`, or a bare `:` (empty term and definition)
        if (preg_match('/^:(?: +(.*))?$/', $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_DEFINITION,
                'marker' => ':',
                'content' => $matches[1] ?? '',
            ];
        }

        return null;
    }

    /**
     * Attach the raw marker-adjacent attribute string to a parsed marker result.
     *
     * Empty strings (no `{...}` after the marker) are dropped so the `attrs` key
     * is only present when the item actually carries marker attributes.
     *
     * @param array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, attrs?: string} $result The parsed marker result
     * @param string $attrs Raw attribute string captured after the marker (no braces)
     *
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, attrs?: string}
     */
    protected function withMarkerAttrs(array $result, string $attrs): array
    {
        if ($attrs !== '') {
            $result['attrs'] = $attrs;
        }

        return $result;
    }

    /**
     * Disambiguate between roman numeral and alphabetical list styles.
     *
     * For single-letter markers that could be either roman (i, v, x, l, c, d, m)
     * or alphabetical, looks ahead at subsequent items to determine the style.
     *
     * @param array<string, mixed> $listInfo The parsed list info with ambiguous flag
     * @param array<string> $lines All lines being parsed
     * @param int $start Starting line index
     *
     * @return array<string, mixed> Updated list info with resolved style
     */
    public function disambiguateListStyle(array $listInfo, array $lines, int $start): array
    {
        $marker = $listInfo['marker'];
        $firstMarkerLetter = null;
        $firstIsLower = null;

        // Extract the letter from the first marker for comparison
        if (preg_match('/^([ivxlcdmIVXLCDM])/', $lines[$start], $m)) {
            $firstMarkerLetter = strtolower($m[1]);
            $firstIsLower = ctype_lower($m[1]);
        } elseif (preg_match('/^\(([ivxlcdmIVXLCDM])\)/', $lines[$start], $m)) {
            $firstMarkerLetter = strtolower($m[1]);
            $firstIsLower = ctype_lower($m[1]);
        }

        $hasMultiCharRoman = false;
        $hasNonRomanLetter = false;
        $allSameLetter = true;
        $lineCount = count($lines);

        // Look ahead at subsequent items
        for ($i = $start + 1; $i < $lineCount; $i++) {
            $line = $lines[$i];

            // Stop at blank lines or non-list content
            if (trim($line) === '') {
                continue;
            }

            // Check if this line is a list item with the same marker type
            $itemInfo = $this->parseListItemMarker($line);
            if ($itemInfo === null || $itemInfo['marker'] !== $marker) {
                break;
            }

            // Extract the marker text (preserve original case for comparison)
            $markerTextRaw = null;
            if ($marker === '()') {
                if (preg_match('/^\(([^)]+)\)/', $line, $m)) {
                    $markerTextRaw = $m[1];
                }
            } else {
                if (preg_match('/^([a-zA-Z]+)[.)]/', $line, $m)) {
                    $markerTextRaw = $m[1];
                }
            }

            if ($markerTextRaw === null) {
                break;
            }

            // Check if case matches - different case means different list style
            $itemIsLower = ctype_lower($markerTextRaw[0]);
            if ($firstIsLower !== null && $itemIsLower !== $firstIsLower) {
                break;
            }

            $markerText = strtolower($markerTextRaw);

            // Check for multi-character roman numerals
            if (strlen($markerText) > 1 && preg_match('/^[ivxlcdm]+$/', $markerText)) {
                $hasMultiCharRoman = true;

                break;
            }

            // Check if it's a letter not used in roman numerals
            if (strlen($markerText) === 1 && !str_contains(self::ROMAN_CHARS, $markerText)) {
                $hasNonRomanLetter = true;

                break;
            }

            // Check if all letters are the same
            if ($firstMarkerLetter !== null && $markerText !== $firstMarkerLetter) {
                $allSameLetter = false;
            }
        }

        // Decision logic
        if ($hasMultiCharRoman) {
            return $listInfo;
        }

        if ($hasNonRomanLetter) {
            $listInfo['start'] = $listInfo['alpha_start'];
            $listInfo['style'] = $listInfo['alpha_style'];
            unset($listInfo['ambiguous'], $listInfo['alpha_start'], $listInfo['alpha_style']);

            return $listInfo;
        }

        return $listInfo;
    }

    /**
     * Convert roman numeral string to integer.
     *
     * @param string $roman Roman numeral string (uppercase)
     *
     * @return int The integer value, or 0 if invalid
     */
    public function romanToInt(string $roman): int
    {
        $result = 0;
        $prev = 0;
        $length = strlen($roman);

        for ($i = $length - 1; $i >= 0; $i--) {
            $char = $roman[$i];
            if (!isset(self::ROMAN_VALUES[$char])) {
                return 0;
            }
            $value = self::ROMAN_VALUES[$char];

            if ($value < $prev) {
                $result -= $value;
            } else {
                $result += $value;
            }
            $prev = $value;
        }

        return $result;
    }

    /**
     * Get the last list item from a list block.
     *
     * @param \Djot\Node\Block\ListBlock $list The list block
     *
     * @return \Djot\Node\Block\ListItem|null The last item, or null if empty
     */
    public function getLastListItem(ListBlock $list): ?ListItem
    {
        $children = $list->getChildren();
        $count = count($children);
        if ($count === 0) {
            return null;
        }
        $last = $children[$count - 1];

        return $last instanceof ListItem ? $last : null;
    }

    /**
     * Check if list items match (same type, marker, and style).
     *
     * @param array<string, mixed> $listInfo The list info
     * @param array<string, mixed> $itemInfo The item info
     *
     * @return bool True if they match
     */
    public function itemMatchesList(array $listInfo, array $itemInfo): bool
    {
        if ($itemInfo['type'] !== $listInfo['type']) {
            return false;
        }
        if ($itemInfo['marker'] !== $listInfo['marker']) {
            return false;
        }

        $listStyle = $listInfo['style'] ?? null;
        $itemStyle = $itemInfo['style'] ?? null;

        if (($listStyle === null) !== ($itemStyle === null)) {
            return false;
        }

        if ($listStyle === $itemStyle) {
            return true;
        }

        // Handle ambiguous markers (e.g., 'c' could be alpha or roman)
        // If list is alphabetic and item could be alphabetic, continue the list
        if (isset($itemInfo['ambiguous']) && isset($itemInfo['alpha_style'])) {
            if ($listStyle === $itemInfo['alpha_style']) {
                return true;
            }
        }

        return false;
    }
}
