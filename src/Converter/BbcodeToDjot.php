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
            fn ($m) => '```' . strtolower($m[1]) . "\n" . trim($m[2]) . "\n```\n",
            $text,
        ) ?? $text;

        // [code]...[/code] -> ```\n...\n```
        $text = preg_replace_callback(
            '/\[code\](.*?)\[\/code\]/is',
            fn ($m) => "```\n" . trim($m[1]) . "\n```\n",
            $text,
        ) ?? $text;

        // Inline [c]...[/c] or [icode]...[/icode] -> `...`
        $text = preg_replace('/\[c\](.*?)\[\/c\]/is', '`$1`', $text) ?? $text;
        $text = preg_replace('/\[icode\](.*?)\[\/icode\]/is', '`$1`', $text) ?? $text;

        return $text;
    }

    protected function convertQuotes(string $text): string
    {
        // [quote=author]...[/quote] -> > **author wrote:**\n> ...
        $text = preg_replace_callback(
            '/\[quote=([^\]]+)\](.*?)\[\/quote\]/is',
            function ($m) {
                $author = trim($m[1]);
                $content = trim($m[2]);
                $lines = explode("\n", $content);
                $quoted = array_map(fn ($line) => '> ' . $line, $lines);

                return "> *{$author} wrote:*\n" . implode("\n", $quoted) . "\n\n";
            },
            $text,
        ) ?? $text;

        // [quote]...[/quote] -> > ...
        $text = preg_replace_callback(
            '/\[quote\](.*?)\[\/quote\]/is',
            function ($m) {
                $content = trim($m[1]);
                $lines = explode("\n", $content);
                $quoted = array_map(fn ($line) => '> ' . $line, $lines);

                return implode("\n", $quoted) . "\n\n";
            },
            $text,
        ) ?? $text;

        return $text;
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

                return $content . "\n";
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

                return $content . "\n";
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
                $title = !empty($m[1]) ? ' ' . trim($m[1]) : '';
                $content = trim($m[2]);

                return "::: spoiler{$title}\n{$content}\n:::\n";
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

                return implode("\n", $rows) . "\n\n";
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
