<?php

declare(strict_types=1);

namespace Djot\Util;

/**
 * String utility functions
 */
final class StringUtil
{
    /**
     * Find a safe code fence marker that doesn't conflict with content
     *
     * Returns backticks (` or ```) that don't appear in the content,
     * extending the marker length as needed.
     *
     * @param string $content The content that will be fenced
     * @param int $minTicks Minimum number of backticks (1 for inline, 3 for blocks)
     *
     * @return string Safe fence marker
     */
    public static function findSafeCodeFence(string $content, int $minTicks = 1): string
    {
        $backticks = str_repeat('`', $minTicks);

        while (str_contains($content, $backticks)) {
            $backticks .= '`';
        }

        return $backticks;
    }

    /**
     * Escape string for safe HTML output (attributes and text content)
     *
     * Escapes <, >, &, and quotes. Also converts the internal nbsp placeholder
     * (U+E000) to &nbsp; entity.
     */
    public static function escapeHtml(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }

    /**
     * Normalize a reference or footnote label for lookup.
     *
     * Leading and trailing whitespace is removed and every internal run of
     * whitespace becomes a single space, so a label a text editor has wrapped
     * still matches a definition written on one line.
     *
     * The character class is exactly djot.js's `normalizeLabel`
     * (`label.trim().replace(/[ \t\r\n]+/g, " ")`) rather than PHP's `\s`,
     * which also covers form feed and vertical tab. That difference is
     * observable: djot.js does not bind `[t][a<FF>b]` to `[a b]: url` and a
     * `\s`-based normalizer does.
     */
    public static function normalizeLabel(string $label): string
    {
        $collapsed = preg_replace('/[ \t\r\n]+/', ' ', $label) ?? $label;

        return trim($collapsed, " \t\r\n");
    }
}
