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
        $escaped = htmlspecialchars($value, ENT_COMPAT | ENT_HTML5, 'UTF-8');

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

    /**
     * Remove terminal control characters from document text.
     *
     * Strips C0 controls except tab and newline, DEL, and C1 controls
     * (U+0080 to U+009F), so rendered text cannot carry escape sequences
     * to a terminal. Invalid UTF-8 is scrubbed first: a stray 0x80-0x9F
     * byte is itself a C1 control to an 8-bit terminal.
     */
    public static function stripControlCharacters(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            $text = mb_scrub($text, 'UTF-8');
        }

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $text) ?? $text;
    }
}
