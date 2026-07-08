<?php

declare(strict_types=1);

namespace Djot\Converter;

/**
 * Converts BBCode markup to Djot
 *
 * Useful for migrating forum content to Djot format.
 */
class BbcodeToDjot
{
    /**
     * Convert BBCode to Djot markup
     */
    public function convert(string $bbcode): string
    {
        $djot = $bbcode;

        // Normalize line endings
        $djot = str_replace("\r\n", "\n", $djot);
        $djot = str_replace("\r", "\n", $djot);

        // Links and images first (before basic formatting escapes brackets)
        $djot = $this->convertLinks($djot);
        $djot = $this->convertImages($djot);

        // Basic formatting
        $djot = $this->convertBasicFormatting($djot);

        // Code blocks and inline code
        $djot = $this->convertCode($djot);

        // Quotes
        $djot = $this->convertQuotes($djot);

        // Lists
        $djot = $this->convertLists($djot);

        // Other elements
        $djot = $this->convertOther($djot);

        // Clean up
        $djot = $this->cleanup($djot);

        return $djot;
    }

    protected function convertBasicFormatting(string $text): string
    {
        // Bold [b]...[/b] -> *...*
        $text = preg_replace('/\[b\](.*?)\[\/b\]/is', '*$1*', $text) ?? $text;

        // Italic [i]...[/i] -> _..._
        $text = preg_replace('/\[i\](.*?)\[\/i\]/is', '_$1_', $text) ?? $text;

        // Underline [u]...[/u] -> {+...+} (using insert as closest equivalent)
        $text = preg_replace('/\[u\](.*?)\[\/u\]/is', '{+$1+}', $text) ?? $text;

        // Strikethrough [s]...[/s] -> {-...-}
        $text = preg_replace('/\[s\](.*?)\[\/s\]/is', '{-$1-}', $text) ?? $text;

        // Size [size=X]...[/size] - no direct equivalent, strip tags
        $text = preg_replace('/\[size=[^\]]*\](.*?)\[\/size\]/is', '$1', $text) ?? $text;

        // Color [color=X]...[/color] - no direct equivalent, strip tags
        $text = preg_replace('/\[color=[^\]]*\](.*?)\[\/color\]/is', '$1', $text) ?? $text;

        // Font [font=X]...[/font] - no direct equivalent, strip tags
        $text = preg_replace('/\[font=[^\]]*\](.*?)\[\/font\]/is', '$1', $text) ?? $text;

        return $text;
    }

    protected function convertLinks(string $text): string
    {
        // [url=http://...]text[/url] -> [text](url)
        $text = preg_replace(
            '/\[url=([^\]]+)\](.*?)\[\/url\]/is',
            '[$2]($1)',
            $text,
        ) ?? $text;

        // [url]http://...[/url] -> <url> (autolink)
        $text = preg_replace(
            '/\[url\](.*?)\[\/url\]/is',
            '<$1>',
            $text,
        ) ?? $text;

        // [email]...[/email] -> <mailto:...>
        $text = preg_replace(
            '/\[email\](.*?)\[\/email\]/is',
            '<mailto:$1>',
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertImages(string $text): string
    {
        // [img]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img\](.*?)\[\/img\]/is',
            '![]($1)',
            $text,
        ) ?? $text;

        // [img=WxH]url[/img] -> ![](url)
        $text = preg_replace(
            '/\[img=[^\]]*\](.*?)\[\/img\]/is',
            '![]($1)',
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertCode(string $text): string
    {
        // [code=lang]...[/code] -> ```lang\n...\n```
        $text = preg_replace_callback(
            '/\[code=([^\]]+)\](.*?)\[\/code\]/is',
            // Neutralize a leading `=` in the [code=..] language so untrusted
            // Bbcode cannot mint a Djot `=html` raw-HTML block (live HTML under
            // the default renderer). `[code= =html]` -> inert ```html block.
            fn ($m) => "\n\n```" . ltrim(ltrim(strtolower(trim($m[1])), '=')) . "\n" . trim($m[2]) . "\n```\n\n",
            $text,
        ) ?? $text;

        // [code]...[/code] -> ```\n...\n```
        $text = preg_replace_callback(
            '/\[code\](.*?)\[\/code\]/is',
            fn ($m) => "\n\n```\n" . trim($m[1]) . "\n```\n\n",
            $text,
        ) ?? $text;

        // Inline [c]...[/c] or [icode]...[/icode] -> `...`
        $text = preg_replace('/\[c\](.*?)\[\/c\]/is', '`$1`', $text) ?? $text;
        $text = preg_replace('/\[icode\](.*?)\[\/icode\]/is', '`$1`', $text) ?? $text;

        return $text;
    }

    protected function convertQuotes(string $text): string
    {
        // Use depth tracking to handle nested quotes properly
        return $this->parseQuotesWithDepth($text);
    }

    /**
     * Parse BBCode quotes with proper nesting support.
     *
     * Uses depth tracking to correctly match opening and closing tags,
     * then recursively processes nested quotes.
     */
    protected function parseQuotesWithDepth(string $text): string
    {
        $length = strlen($text);
        $i = 0;
        // Single left-to-right pass with a stack of open-quote content buffers
        // (O(n)). The previous version recursed on each closed quote's inner
        // content and re-scanned it, which is O(n^2) on deeply nested
        // `[quote]` (a converter DoS). Index 0 accumulates the top-level output;
        // each `[quote]` pushes a level, each `[/quote]` pops one, formats it as
        // a blockquote, and folds it into its parent -- producing the same
        // output the recursion did for well-formed input.
        /** @var array<int, string> $contents */
        $contents = [''];
        /** @var array<int, string|null> $authors */
        $authors = [null];
        $top = 0;

        while ($i < $length) {
            if (preg_match('/\G\[quote(?:[= ]([^\]]*))?\]/i', $text, $m, 0, $i)) {
                $contents[] = '';
                $authors[] = $m[1] ?? null;
                $top++;
                $i += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G\[\/quote\]/i', $text, $m, 0, $i)) {
                $i += strlen($m[0]);
                if ($top > 0) {
                    $blockquote = $this->formatAsBlockquote($contents[$top], $authors[$top]);
                    array_pop($contents);
                    array_pop($authors);
                    $top--;
                    $contents[$top] .= $blockquote;
                }
                // A stray `[/quote]` with no open quote is dropped.

                continue;
            }

            $contents[$top] .= $text[$i];
            $i++;
        }

        // Unclosed quotes: format each remaining open level as a blockquote,
        // innermost first, folding into its parent (matches the previous
        // "content runs to end of input" behavior).
        while ($top > 0) {
            $blockquote = $this->formatAsBlockquote($contents[$top], $authors[$top]);
            array_pop($contents);
            array_pop($authors);
            $top--;
            $contents[$top] .= $blockquote;
        }

        return $contents[0];
    }

    /**
     * Format content as a Djot blockquote.
     */
    protected function formatAsBlockquote(string $content, ?string $author): string
    {
        $content = trim($content);
        $lines = explode("\n", $content);
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);

        // Ensure blank line before blockquote for proper Djot block separation
        $output = "\n\n" . implode("\n", $quoted) . "\n";

        if ($author !== null && $author !== '') {
            $output .= '^ ' . $this->formatAttribution($author) . "\n";
        }

        return $output . "\n";
    }

    /**
     * Parse BBCode quote attribution and format as "name (datetime) #id".
     *
     * Handles formats like:
     * - username
     * - username date="2024-01-01"
     * - "9" name="user" date="2024-01-01 12:30"
     * - id="9" name="user" date="2024-01-01"
     */
    protected function formatAttribution(string $attribution): string
    {
        $attribution = trim($attribution);
        $remaining = $attribution;

        $id = null;
        $name = null;
        $datetime = null;

        // Extract id="..." or bare "..." at start (post/message ID)
        if (preg_match('/^["\'](\d+)["\']/', $remaining, $m)) {
            $id = $m[1];
            $remaining = trim(substr($remaining, strlen($m[0])));
        } elseif (preg_match('/\bid=["\']?(\d+)["\']?/i', $remaining, $m)) {
            $id = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract name="..."
        if (preg_match('/\bname=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $name = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract date="..." (may include time)
        if (preg_match('/\bdate=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $datetime = $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // Extract time="..." separately if present
        if (preg_match('/\btime=["\']([^"\']+)["\']/i', $remaining, $m)) {
            $datetime = $datetime !== null ? $datetime . ' ' . $m[1] : $m[1];
            $remaining = str_replace($m[0], '', $remaining);
        }

        // If no name attribute found, use remaining text as name
        $remaining = trim($remaining);
        if ($name === null && $remaining !== '') {
            $name = $remaining;
        }

        // Build output: name (datetime) #id
        $output = $name ?? '';

        if ($datetime !== null) {
            $output .= ' (' . $datetime . ')';
        }

        if ($id !== null) {
            $output .= ' #' . $id;
        }

        return trim($output);
    }

    protected function convertLists(string $text): string
    {
        // Ordered list [list=1]...[/list]
        $text = preg_replace_callback(
            '/\[list=1\](.*?)\[\/list\]/is',
            function ($m) {
                $content = $m[1];
                $counter = 1;
                $content = preg_replace_callback(
                    '/\[\*\](.*?)(?=\[\*\]|\z)/is',
                    function ($item) use (&$counter) {
                        $text = trim($item[1]);

                        return $counter++ . '. ' . $text . "\n";
                    },
                    $content,
                );

                // Ensure blank line before list for proper Djot block separation
                return "\n\n" . $content . "\n";
            },
            $text,
        ) ?? $text;

        // Unordered list [list]...[/list]
        $text = preg_replace_callback(
            '/\[list\](.*?)\[\/list\]/is',
            function ($m) {
                $content = $m[1];
                $content = preg_replace_callback(
                    '/\[\*\](.*?)(?=\[\*\]|\z)/is',
                    function ($item) {
                        $text = trim($item[1]);

                        return '- ' . $text . "\n";
                    },
                    $content,
                );

                // Ensure blank line before list for proper Djot block separation
                return "\n\n" . $content . "\n";
            },
            $text,
        ) ?? $text;

        return $text;
    }

    protected function convertOther(string $text): string
    {
        // [hr] -> ---
        $text = preg_replace('/\[hr\]/i', "\n---\n", $text) ?? $text;

        // [center]...[/center] - no equivalent, strip tags
        $text = preg_replace('/\[center\](.*?)\[\/center\]/is', '$1', $text) ?? $text;

        // [left]...[/left] - no equivalent, strip tags
        $text = preg_replace('/\[left\](.*?)\[\/left\]/is', '$1', $text) ?? $text;

        // [right]...[/right] - no equivalent, strip tags
        $text = preg_replace('/\[right\](.*?)\[\/right\]/is', '$1', $text) ?? $text;

        // [spoiler]...[/spoiler] -> ::: spoiler\n...\n:::
        $text = preg_replace_callback(
            '/\[spoiler(?:=([^\]]+))?\](.*?)\[\/spoiler\]/is',
            function ($m) {
                $titleAttr = !empty($m[1]) ? '{title="' . trim($m[1]) . "\"}\n" : '';
                $content = trim($m[2]);

                return "{$titleAttr}::: spoiler\n{$content}\n:::\n";
            },
            $text,
        ) ?? $text;

        // [table]...[/table] - basic table conversion
        $text = $this->convertTables($text);

        // [youtube]ID[/youtube] -> ![](https://youtube.com/watch?v=ID)
        $text = preg_replace(
            '/\[youtube\]([a-zA-Z0-9_-]+)\[\/youtube\]/i',
            '![YouTube Video](https://www.youtube.com/watch?v=$1)',
            $text,
        ) ?? $text;

        // [sup]...[/sup] -> ^...^
        $text = preg_replace('/\[sup\](.*?)\[\/sup\]/is', '^$1^', $text) ?? $text;

        // [sub]...[/sub] -> ~...~
        $text = preg_replace('/\[sub\](.*?)\[\/sub\]/is', '~$1~', $text) ?? $text;

        return $text;
    }

    protected function convertTables(string $text): string
    {
        return preg_replace_callback(
            '/\[table\](.*?)\[\/table\]/is',
            function ($m) {
                $content = $m[1];
                $rows = [];
                $isFirst = true;

                // Extract rows
                preg_match_all('/\[tr\](.*?)\[\/tr\]/is', $content, $rowMatches);

                foreach ($rowMatches[1] as $row) {
                    $cells = [];

                    // Extract cells (th or td)
                    preg_match_all('/\[t[hd]\](.*?)\[\/t[hd]\]/is', $row, $cellMatches);

                    foreach ($cellMatches[1] as $cell) {
                        $cells[] = trim($cell);
                    }

                    if ($cells) {
                        $rows[] = '| ' . implode(' | ', $cells) . ' |';

                        // Add separator after first row (header)
                        if ($isFirst) {
                            $separator = array_fill(0, count($cells), '---');
                            $rows[] = '| ' . implode(' | ', $separator) . ' |';
                            $isFirst = false;
                        }
                    }
                }

                // Ensure blank line before table for proper Djot block separation
                return "\n\n" . implode("\n", $rows) . "\n\n";
            },
            $text,
        ) ?? $text;
    }

    protected function cleanup(string $text): string
    {
        // Remove any remaining BBCode closing tags [/tag]
        $text = preg_replace('/\[\/[a-z][a-z0-9]*\]/i', '', $text) ?? $text;

        // Remove remaining BBCode opening tags with = attribute [tag=value]
        $text = preg_replace('/\[[a-z][a-z0-9]*=[^\]]*\]/i', '', $text) ?? $text;

        // Normalize multiple blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // Trim
        return trim($text) . "\n";
    }
}
