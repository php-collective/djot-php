<?php

declare(strict_types=1);

namespace Djot\Converter;

use RuntimeException;

/**
 * Converts Markdown syntax to Djot syntax
 *
 * This performs a source-to-source transformation, not parsing.
 * It handles common Markdown patterns and converts them to their Djot equivalents.
 *
 * Key differences from Markdown that this converter handles:
 * - Blank lines are required around block elements (headings, code fences, lists)
 * - Nested lists require a blank line before the nested portion
 * - Emphasis uses _ (not *), strong uses * (not **)
 */
class MarkdownToDjot
{
    /**
     * @var array<string, true>
     */
    protected array $referenceLabels = [];

    public function __construct(protected bool $trustedRawHtml = false)
    {
    }

    /**
     * Convert Markdown text to Djot text
     */
    public function convert(string $markdown): string
    {
        $this->collectReferenceLabels($markdown);
        $lines = explode("\n", $markdown);
        $result = [];
        $inCodeBlock = false;
        $codeFence = '';
        $prevLineType = 'blank';
        $prevIndent = 0;

        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Track code blocks to avoid converting inside them
            if (!$inCodeBlock && preg_match('/^(`{3,}|~{3,})/', $line, $matches)) {
                // Ensure blank line before code fence
                if ($prevLineType !== 'blank' && $result !== []) {
                    $result[] = '';
                }
                $inCodeBlock = true;
                $codeFence = $matches[1][0]; // First char of fence
                // A foreign code-fence info string is a LANGUAGE, never a raw
                // block directive. Neutralize a leading `=` so untrusted
                // Markdown cannot mint a Djot ` ```=html ` raw-HTML block (which
                // the default renderer emits as live HTML); `=html` -> an inert,
                // escaped ` ``` html ` code block.
                $rest = (string)substr($line, strlen($matches[1]));
                if (str_starts_with(ltrim($rest), '=')) {
                    $lang = ltrim(ltrim(ltrim($rest), '='));
                    $result[] = $matches[1] . ($lang !== '' ? ' ' . $lang : '');
                } else {
                    $result[] = $line;
                }
                $prevLineType = 'code_fence';

                continue;
            }

            if ($inCodeBlock) {
                // Check for closing fence
                if (preg_match('/^(' . $codeFence . '{3,})\s*$/', $line)) {
                    $inCodeBlock = false;
                    $codeFence = '';
                    $result[] = $line;
                    // Ensure blank line after code fence if next line is non-blank
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
                    $prevLineType = 'code_fence';
                } else {
                    $result[] = $line;
                    $prevLineType = 'code';
                }

                continue;
            }

            // Detect line type and indentation
            $isBlank = $trimmed === '';
            $isHeading = (bool)preg_match('/^#{1,6}\s/', $trimmed);
            $currentIndent = strlen($line) - strlen(ltrim($line));
            $isList = (bool)preg_match('/^[-*+]\s|^\d+\.\s/', $trimmed);
            $isBlockquote = str_starts_with($trimmed, '>');
            $isNestedContent = $currentIndent > $prevIndent && $prevLineType === 'list';

            if ($isBlank) {
                $result[] = $line;
                $prevLineType = 'blank';
                $prevIndent = 0;

                continue;
            }

            // Add blank line before heading if previous wasn't blank/heading
            if ($isHeading && $prevLineType !== 'blank' && $prevLineType !== 'heading') {
                $result[] = '';
            }

            // Add blank line before blockquote if previous was non-blank content
            if ($isBlockquote && $prevLineType !== 'blank' && $prevLineType !== 'blockquote') {
                $result[] = '';
            }

            // Add blank line before top-level list if previous was non-blank, non-list content
            if ($isList && $currentIndent === 0 && $prevLineType !== 'blank' && $prevLineType !== 'list') {
                $result[] = '';
            }

            // Add blank line before nested list content to enable nesting in Djot
            if ($isList && $isNestedContent) {
                $result[] = '';
            }

            // Convert inline formatting
            $line = $this->convertInlineFormatting($line);
            $result[] = $line;

            // After heading, add blank line if next line is non-blank and not a heading
            if ($isHeading && $i + 1 < $lineCount) {
                $nextTrimmed = trim($lines[$i + 1]);
                if ($nextTrimmed !== '' && !preg_match('/^#{1,6}\s/', $nextTrimmed)) {
                    $result[] = '';
                }
            }

            // Update prev line type
            if ($isHeading) {
                $prevLineType = 'heading';
            } elseif ($isList) {
                $prevLineType = 'list';
            } elseif ($isBlockquote) {
                $prevLineType = 'blockquote';
            } else {
                $prevLineType = 'text';
            }

            // Track indentation for nested list detection
            if ($isList) {
                $prevIndent = $currentIndent;
            }
        }

        $output = implode("\n", $result);

        // Clean up excessive blank lines (more than 2 consecutive)
        $output = preg_replace('/\n{3,}/', "\n\n", $output) ?? $output;

        return $output;
    }

    /**
     * Convert inline Markdown formatting to Djot
     */
    protected function convertInlineFormatting(string $line): string
    {
        // Protect inline code spans from conversion
        $protected = [];
        $line = preg_replace_callback('/`[^`]+`/', function ($match) use (&$protected) {
            $placeholder = "\x00PROTECTED" . count($protected) . "\x00";
            $protected[$placeholder] = $match[0];

            return $placeholder;
        }, $line) ?? $line;

        // Djot has no pointy destination form. Remove the CommonMark wrapper
        // before its closing tag-shaped text can be mistaken for raw HTML.
        $line = preg_replace_callback(
            '/\]\(<([^>\n]+)>([ \t]+(?:"(?:\\\\.|[^"])*"|\'(?:\\\\.|[^\'])*\'|\([^)]*\)))?\)/',
            fn (array $match): string => ']('
                . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $match[1])
                . ')'
                . (isset($match[2]) ? '{title=' . $this->quoteMarkdownTitle($match[2]) . '}' : ''),
            $line,
        ) ?? $line;

        if ($this->trustedRawHtml) {
            $line = preg_replace_callback(
                '/<!--[\s\S]*?-->|<\/?[A-Za-z][A-Za-z0-9-]*(?:\s[^>]*|\s*\/?)>/',
                function (array $match) use (&$protected): string {
                    if (preg_match('/^<\/?(?:mark|ins|del|s|sup|sub|em|strong|b|i|code|kbd|samp|var)>$/i', $match[0]) === 1) {
                        return $match[0];
                    }
                    $placeholder = "\x00PROTECTED" . count($protected) . "\x00";
                    $protected[$placeholder] = $this->rawHtmlInline($match[0]);

                    return $placeholder;
                },
                $line,
            ) ?? $line;
        }

        // Markdown character references represent characters, not authored
        // ampersand text. Decode them before emitting Djot source.
        $line = preg_replace_callback(
            '/&(?:#[0-9]+|#x[0-9A-Fa-f]+|[A-Za-z][A-Za-z0-9]+);/',
            fn (array $match): string => $this->escapeDecodedEntity(
                html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ),
            $line,
        ) ?? $line;

        // A defined Markdown shortcut reference must stay a reference. Bare
        // `[label]` is a Djot span, so give the link its collapsed suffix.
        $line = preg_replace_callback(
            '/(?<![!\]])\[([^\]\n]+)\](?![\[(:])/',
            function (array $match): string {
                return isset($this->referenceLabels[$this->normalizeReferenceLabel($match[1])])
                    ? $match[0] . '[]'
                    : $match[0];
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/!\[([^\]\n]+)\](?![\[(:])/',
            function (array $match): string {
                return isset($this->referenceLabels[$this->normalizeReferenceLabel($match[1])])
                    ? $match[0] . '[]'
                    : $match[0];
            },
            $line,
        ) ?? $line;

        // Protect existing Djot syntax from double-conversion
        // Protect {-text-}, {=text=}, {^text^}, {~text~}
        $line = preg_replace_callback('/\{[-=^~][^}]+[-=^~]\}/', function ($match) use (&$protected) {
            $placeholder = "\x00PROTECTED" . count($protected) . "\x00";
            $protected[$placeholder] = $match[0];

            return $placeholder;
        }, $line) ?? $line;

        // Use placeholder to prevent re-matching
        $strongPlaceholders = [];

        // Convert ___bold italic___ to *_bold italic_* (Djot)
        $line = preg_replace_callback('/___(.+?)___/', function ($match) use (&$strongPlaceholders) {
            $placeholder = "\x00STRONG" . count($strongPlaceholders) . "\x00";
            $strongPlaceholders[$placeholder] = '*_' . $match[1] . '_*';

            return $placeholder;
        }, $line) ?? $line;

        // Convert ***bold italic*** to *_bold italic_* (Djot)
        // Match 3+ asterisks to avoid partial matches
        $line = preg_replace_callback('/(\*{3,})(.+?)(\*{3,})/', function ($match) use (&$strongPlaceholders) {
            $placeholder = "\x00STRONG" . count($strongPlaceholders) . "\x00";
            $strongPlaceholders[$placeholder] = '*_' . $match[2] . '_*';

            return $placeholder;
        }, $line) ?? $line;

        // Convert **bold with nested content** to *bold* (Djot strong)
        $line = preg_replace_callback('/\*\*(.+?)\*\*/', function ($match) use (&$strongPlaceholders) {
            $placeholder = "\x00STRONG" . count($strongPlaceholders) . "\x00";
            // Recursively convert any *italic* inside to _italic_
            $inner = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '_$1_', $match[1]) ?? $match[1];
            $strongPlaceholders[$placeholder] = '*' . $inner . '*';

            return $placeholder;
        }, $line) ?? $line;

        // Convert __bold__ to *bold* (Djot strong)
        $line = preg_replace_callback('/__(.+?)__/', function ($match) use (&$strongPlaceholders) {
            $placeholder = "\x00STRONG" . count($strongPlaceholders) . "\x00";
            $strongPlaceholders[$placeholder] = '*' . $match[1] . '*';

            return $placeholder;
        }, $line) ?? $line;

        // Convert *italic* to _italic_ (Djot emphasis)
        // Only match single asterisks not preceded/followed by asterisks
        // Skip if it looks like already-Djot *strong* (single word without spaces surrounded by single *)
        $line = preg_replace_callback('/(?<!\*)\*([^*]+)\*(?!\*)/', function ($match) {
            // If this looks like Djot strong (content has no internal formatting markers), leave it
            // This is a heuristic - can't be perfect without full parsing
            return '_' . $match[1] . '_';
        }, $line) ?? $line;

        // Convert ~~strikethrough~~ to {-strikethrough-} (Djot delete)
        $line = preg_replace('/~~([^~]+)~~/', '{-$1-}', $line) ?? $line;

        // Convert ==highlight== to {=highlight=} (Djot highlight, GFM extension)
        $line = preg_replace('/==([^=]+)==/', '{=$1=}', $line) ?? $line;

        // Convert ^superscript^ to ^superscript^ (already valid Djot, just ensure not double-wrapped)
        // No conversion needed - bare form is valid Djot

        // Convert ~subscript~ - already valid Djot bare form
        // No conversion needed - bare form is valid Djot

        // Convert HTML tags to Djot equivalents (for round-trip support)
        // These run AFTER Markdown extension conversions to avoid double-processing

        // <mark>text</mark> → {=text=}
        $line = preg_replace('/<mark>([^<]+)<\/mark>/i', '{=$1=}', $line) ?? $line;

        // <ins>text</ins> → {+text+}
        $line = preg_replace('/<ins>([^<]+)<\/ins>/i', '{+$1+}', $line) ?? $line;

        // <del>text</del> → {-text-} (alternative to ~~)
        $line = preg_replace('/<del>([^<]+)<\/del>/i', '{-$1-}', $line) ?? $line;

        // <s>text</s> → {-text-} (HTML5 strikethrough)
        $line = preg_replace('/<s>([^<]+)<\/s>/i', '{-$1-}', $line) ?? $line;

        // <sup>text</sup> → ^text^
        $line = preg_replace('/<sup>([^<]+)<\/sup>/i', '^$1^', $line) ?? $line;

        // <sub>text</sub> → ~text~
        $line = preg_replace('/<sub>([^<]+)<\/sub>/i', '~$1~', $line) ?? $line;

        // <em>text</em> → _text_
        $line = preg_replace('/<em>([^<]+)<\/em>/i', '_$1_', $line) ?? $line;

        // <strong>text</strong> → *text*
        $line = preg_replace('/<strong>([^<]+)<\/strong>/i', '*$1*', $line) ?? $line;

        // <b>text</b> → *text*
        $line = preg_replace('/<b>([^<]+)<\/b>/i', '*$1*', $line) ?? $line;

        // <i>text</i> → _text_
        $line = preg_replace('/<i>([^<]+)<\/i>/i', '_$1_', $line) ?? $line;

        // <code>text</code> → `text`
        $line = preg_replace('/<code>([^<]+)<\/code>/i', '`$1`', $line) ?? $line;

        // Semantic spans rendered via attribute syntax (matches HtmlToDjot).
        // <kbd>text</kbd> → [text]{kbd}
        $line = preg_replace('/<kbd>([^<]+)<\/kbd>/i', '[$1]{kbd}', $line) ?? $line;

        // <samp>text</samp> → [text]{samp}
        $line = preg_replace('/<samp>([^<]+)<\/samp>/i', '[$1]{samp}', $line) ?? $line;

        // <var>text</var> → [text]{var}
        $line = preg_replace('/<var>([^<]+)<\/var>/i', '[$1]{var}', $line) ?? $line;

        // <abbr title="...">text</abbr> → [text]{abbr="..."}
        // The quote char is captured and back-referenced so a title may contain
        // the other quote (e.g. title="Bob's API").
        $line = preg_replace_callback(
            '/<abbr\s+title=(["\'])(.*?)\1\s*>([^<]+)<\/abbr>/i',
            static fn (array $matches): string => '[' . $matches[3] . ']{abbr="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $matches[2]) . '"}',
            $line,
        ) ?? $line;

        // <abbr>text</abbr> → [text]{abbr}
        $line = preg_replace('/<abbr>([^<]+)<\/abbr>/i', '[$1]{abbr}', $line) ?? $line;

        // <dfn title="...">text</dfn> → [text]{dfn="..."}
        $line = preg_replace_callback(
            '/<dfn\s+title=(["\'])(.*?)\1\s*>([^<]+)<\/dfn>/i',
            static fn (array $matches): string => '[' . $matches[3] . ']{dfn="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $matches[2]) . '"}',
            $line,
        ) ?? $line;

        // <dfn>text</dfn> → [text]{dfn}
        $line = preg_replace('/<dfn>([^<]+)<\/dfn>/i', '[$1]{dfn}', $line) ?? $line;

        // Convert $$math$$ to $$`math` (Djot display math) - must come before inline
        $line = preg_replace('/\$\$([^$]+)\$\$/', '$$`$1`', $line) ?? $line;

        // Convert $math$ to $`math` (Djot inline math)
        // Only match $...$ that looks like math (not currency)
        $line = preg_replace_callback('/\$([^$\s][^$]*[^$\s]|\S)\$/', function ($match) {
            // Skip if it looks like currency ($5, $100)
            if (preg_match('/^\d/', $match[1])) {
                return $match[0];
            }

            return '$`' . $match[1] . '`';
        }, $line) ?? $line;

        // Restore strong placeholders
        foreach ($strongPlaceholders as $placeholder => $content) {
            $line = str_replace($placeholder, $content, $line);
        }

        // Restore protected content
        foreach ($protected as $placeholder => $content) {
            $line = str_replace($placeholder, $content, $line);
        }

        return $line;
    }

    protected function rawHtmlInline(string $html): string
    {
        preg_match_all('/`+/', $html, $runs);
        $width = 1;
        foreach ($runs[0] as $run) {
            $width = max($width, strlen($run) + 1);
        }
        $fence = str_repeat('`', $width);

        return $fence . $html . $fence . '{=html}';
    }

    protected function quoteMarkdownTitle(string $title): string
    {
        $title = trim($title);
        $title = substr($title, 1, -1);
        $title = preg_replace('/\\\\(.)/s', '$1', $title) ?? $title;

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $title) . '"';
    }

    protected function normalizeReferenceLabel(string $label): string
    {
        return strtolower(trim((string)preg_replace('/\s+/', ' ', $label)));
    }

    protected function collectReferenceLabels(string $markdown): void
    {
        $this->referenceLabels = [];
        $fence = null;
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})/', $line, $match) === 1) {
                $marker = $match[1][0];
                if ($fence === null) {
                    $fence = $marker;
                } elseif ($fence === $marker) {
                    $fence = null;
                }

                continue;
            }
            if ($fence === null && preg_match('/^[ ]{0,3}\[([^\]]+)\]:/', $line, $match) === 1) {
                $this->referenceLabels[$this->normalizeReferenceLabel($match[1])] = true;
            }
        }
    }

    protected function escapeDecodedEntity(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]{}~^<$="])/u', '\\\\$1', $text) ?? $text;
    }

    /**
     * Convert a Markdown file to Djot
     *
     * @throws \RuntimeException If file cannot be read
     */
    public function convertFile(string $inputPath): string
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException("File not found: {$inputPath}");
        }

        $content = file_get_contents($inputPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$inputPath}");
        }

        return $this->convert($content);
    }

    /**
     * Convert a Markdown file and save as Djot
     *
     * @throws \RuntimeException If file cannot be read or written
     */
    public function convertFileAndSave(string $inputPath, ?string $outputPath = null): void
    {
        $djot = $this->convertFile($inputPath);

        if ($outputPath === null) {
            // Replace .md extension with .djot
            $outputPath = preg_replace('/\.md$/i', '.djot', $inputPath) ?? $inputPath;
            if ($outputPath === $inputPath) {
                $outputPath .= '.djot';
            }
        }

        $result = file_put_contents($outputPath, $djot);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$outputPath}");
        }
    }
}
