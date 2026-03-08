<?php

declare(strict_types=1);

namespace Djot\Parser\Block;

/**
 * Parser utilities for fenced blocks (code blocks, divs, raw blocks).
 *
 * This class handles detection and content extraction for:
 * - Code blocks (``` or ~~~)
 * - Divs (:::)
 * - Raw blocks (``` =format)
 * - Comments ({% ... %})
 */
class FencedBlockParser
{
    /**
     * Check if a line opens a code block fence.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, char: string, length: int, info: string, indent: string}|null
     */
    public function parseCodeFenceOpener(string $line): ?array
    {
        // Fast early exit: code blocks start with ` or ~ (possibly after whitespace)
        $trimmed = ltrim($line);
        if ($trimmed === '' || ($trimmed[0] !== '`' && $trimmed[0] !== '~')) {
            return null;
        }

        // Match opening fence: 3+ backticks or tildes, optionally with leading whitespace
        if (!preg_match('/^(\s*)(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $indent = $matches[1];
        $fence = $matches[2];
        $fenceChar = $fence[0];
        $fenceLength = strlen($fence);
        $info = trim($matches[3]);

        // Check for inline code on a single line: ``` foo ``` should be inline code
        if ($fenceChar === '`') {
            $closingPattern = '/`{' . $fenceLength . ',}/';
            if (preg_match($closingPattern, $info)) {
                return null;
            }
        }

        return [
            'fence' => $fence,
            'char' => $fenceChar,
            'length' => $fenceLength,
            'info' => $info,
            'indent' => $indent,
        ];
    }

    /**
     * Check if a line closes a code block fence.
     *
     * @param string $line The line to check
     * @param string $fenceChar The fence character (` or ~)
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isCodeFenceCloser(string $line, string $fenceChar, int $fenceLength): bool
    {
        return preg_match('/^\s*' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $line) === 1;
    }

    /**
     * Check if a line opens a div fence.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int, className: string}|null
     */
    public function parseDivFenceOpener(string $line): ?array
    {
        // Fast early exit: divs start with :
        if (!isset($line[0]) || $line[0] !== ':') {
            return null;
        }

        // Match opening fence: 3+ colons with optional class
        if (!preg_match('/^(:{3,})\s*(.*)$/', $line, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
            'className' => trim($matches[2]),
        ];
    }

    /**
     * Check if a line closes a div fence.
     *
     * @param string $line The line to check
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isDivFenceCloser(string $line, int $fenceLength): bool
    {
        return preg_match('/^:{' . $fenceLength . ',}\s*$/', $line) === 1;
    }

    /**
     * Check if a line opens a raw block.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int, format: string}|null
     */
    public function parseRawBlockOpener(string $line): ?array
    {
        // Fast early exit: raw blocks start with ` and contain =
        if (!isset($line[0]) || $line[0] !== '`' || !str_contains($line, '=')) {
            return null;
        }

        // Match opening fence with =format: ``` =html (space before = is syntax delimiter)
        if (!preg_match('/^(`{3,}) +=(\w+) *$/', $line, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
            'format' => $matches[2],
        ];
    }

    /**
     * Check if a line opens a comment block.
     *
     * @param string $line The line to check
     *
     * @return bool True if this line opens a comment
     */
    public function isCommentOpener(string $line): bool
    {
        return str_contains($line, '{%') && str_starts_with(trim($line), '{%');
    }

    /**
     * Check if a line opens a fenced comment block (%%%).
     *
     * This is an extension to support multi-line comments with blank lines,
     * which the standard {% %} syntax cannot handle.
     *
     * @param string $line The line to check
     *
     * @return array{fence: string, length: int}|null
     */
    public function parseFencedCommentOpener(string $line): ?array
    {
        $trimmed = trim($line);

        // Fast early exit: fenced comments start with %
        if (!isset($trimmed[0]) || $trimmed[0] !== '%') {
            return null;
        }

        // Match opening fence: 3+ percent signs
        if (!preg_match('/^(%{3,})\s*$/', $trimmed, $matches)) {
            return null;
        }

        return [
            'fence' => $matches[1],
            'length' => strlen($matches[1]),
        ];
    }

    /**
     * Check if a line closes a fenced comment block.
     *
     * @param string $line The line to check
     * @param int $fenceLength The minimum fence length required
     *
     * @return bool True if this line closes the fence
     */
    public function isFencedCommentCloser(string $line, int $fenceLength): bool
    {
        return preg_match('/^\s*%{' . $fenceLength . ',}\s*$/', $line) === 1;
    }

    /**
     * Remove indent from a content line.
     *
     * @param string $line The line to process
     * @param int $indentLen Maximum indent to remove
     *
     * @return string The line with indent removed
     */
    public function removeIndent(string $line, int $indentLen): string
    {
        if ($indentLen > 0 && preg_match('/^(\s{0,' . $indentLen . '})(.*)$/', $line, $lineMatch)) {
            return $lineMatch[2];
        }

        return $line;
    }
}
