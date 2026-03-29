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
     * Convert Markdown text to Djot text
     */
    public function convert(string $markdown): string
    {
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
                if ($prevLineType !== 'blank' && count($result) > 0) {
                    $result[] = '';
                }
                $inCodeBlock = true;
                $codeFence = $matches[1][0]; // First char of fence
                $result[] = $line;
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
