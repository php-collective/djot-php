<?php

declare(strict_types=1);

namespace Djot\Parser;

use Djot\Exception\ParseException;
use Djot\Exception\ParseWarning;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Footnote;
use Djot\Node\Block\Heading;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\ListBlock;
use Djot\Node\Block\ListItem;
use Djot\Node\Block\Paragraph;
use Djot\Node\Block\RawBlock;
use Djot\Node\Block\Table;
use Djot\Node\Block\TableCell;
use Djot\Node\Block\TableRow;
use Djot\Node\Block\ThematicBreak;
use Djot\Node\Document;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Node;

/**
 * Block-level parser for Djot
 */
class BlockParser
{
    protected InlineParser $inlineParser;

    /**
     * @var array<string, \Djot\Parser\ReferenceDefinition>
     */
    protected array $references = [];

    /**
     * @var array<string, \Djot\Node\Block\Footnote>
     */
    protected array $footnotes = [];

    /**
     * Pending block attributes to apply to next block
     *
     * @var array<string, mixed>
     */
    protected array $pendingAttributes = [];

    /**
     * Whether to collect warnings during parsing
     */
    protected bool $collectWarnings = false;

    /**
     * Whether to throw on parse errors
     */
    protected bool $strictMode = false;

    /**
     * Collected warnings during parsing
     *
     * @var array<\Djot\Exception\ParseWarning>
     */
    protected array $warnings = [];

    /**
     * Current line offset for nested parsing (0-indexed internally, 1-indexed for errors)
     */
    protected int $lineOffset = 0;

    /**
     * Custom block patterns: array of [pattern => callback]
     * Callback receives (array $lines, int $startIndex, Node $parent, BlockParser $parser)
     * and should return number of lines consumed, or null if not matched
     *
     * @var array<string, callable(array<string>, int, \Djot\Node\Node, self): ?int>
     */
    protected array $customBlockPatterns = [];

    public function __construct(bool $collectWarnings = false, bool $strictMode = false)
    {
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->inlineParser = new InlineParser($this);
    }

    /**
     * Register a custom block pattern
     *
     * The pattern should match the first line of the block.
     * The callback receives the full lines array, start index, parent node, and parser,
     * and should return the number of lines consumed (or null if not a match).
     *
     * Example - :::spoiler blocks:
     * ```php
     * $parser->addBlockPattern('/^:::spoiler\s*$/', function($lines, $start, $parent, $parser) {
     *     $endPattern = '/^:::\s*$/';
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && !preg_match($endPattern, $lines[$i])) {
     *         $content[] = $lines[$i];
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'spoiler');
     *     // Parse content inside
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start + 1; // +1 for closing :::
     * });
     * ```
     *
     * Example - custom admonitions:
     * ```php
     * $parser->addBlockPattern('/^!!!\s*(note|warning|danger)\s*$/', function($lines, $start, $parent, $parser) {
     *     $type = trim(substr($lines[$start], 3));
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && preg_match('/^\s+/', $lines[$i])) {
     *         $content[] = ltrim($lines[$i]);
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'admonition ' . $type);
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start;
     * });
     * ```
     *
     * @param string $pattern Regex pattern to match the first line
     * @param callable(array<string>, int, \Djot\Node\Node, self): ?int $callback
     */
    public function addBlockPattern(string $pattern, callable $callback): void
    {
        $this->customBlockPatterns[$pattern] = $callback;
    }

    /**
     * Remove a custom block pattern
     */
    public function removeBlockPattern(string $pattern): void
    {
        unset($this->customBlockPatterns[$pattern]);
    }

    /**
     * Get all registered custom block patterns
     *
     * @return array<string, callable>
     */
    public function getBlockPatterns(): array
    {
        return $this->customBlockPatterns;
    }

    /**
     * Parse block content (for use in custom block callbacks)
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     */
    public function parseBlockContent(Node $parent, array $lines): void
    {
        $this->parseBlocks($parent, $lines, 0);
    }

    /**
     * Enable or disable warning collection
     */
    public function setCollectWarnings(bool $collect): self
    {
        $this->collectWarnings = $collect;

        return $this;
    }

    /**
     * Enable or disable strict mode
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }

    /**
     * Get collected warnings
     *
     * @return array<\Djot\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Clear collected warnings
     */
    public function clearWarnings(): self
    {
        $this->warnings = [];

        return $this;
    }

    /**
     * Add a warning or throw exception in strict mode
     *
     * @throws \Djot\Exception\ParseException In strict mode for errors
     */
    protected function addWarning(string $message, int $line, int $column = 1, bool $isError = false): void
    {
        // Convert from 0-indexed to 1-indexed for user-facing messages
        $line = $line + $this->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->warnings[] = new ParseWarning($message, $line, $column);
        }
    }

    public function parse(string $input): Document
    {
        $this->references = [];
        $this->footnotes = [];
        $this->pendingAttributes = [];
        $this->warnings = [];
        $this->lineOffset = 0;
        $document = new Document();
        $lines = $this->splitLines($input);

        // First pass: extract reference definitions and footnotes
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);

        // Second pass: parse blocks
        $this->parseBlocks($document, $lines, 0);

        // Append footnotes section if any
        foreach ($this->footnotes as $footnote) {
            $document->appendChild($footnote);
        }

        return $document;
    }

    /**
     * Extract reference link definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractReferences(array $lines): void
    {
        $i = 0;
        $count = count($lines);
        $pendingAttrs = [];

        while ($i < $count) {
            $line = $lines[$i];

            // Check for attributes that may precede a reference definition
            if (preg_match('/^\{([^}]+)\}\s*$/', $line, $attrMatches)) {
                $pendingAttrs = $this->parseInlineAttributes($attrMatches[1]);
                $i++;

                continue;
            }

            // Match reference definition: [label]: url (url can be empty, on next line)
            if (preg_match('/^\[([^\]]+)\]:\s*(.*)$/', $line, $matches)) {
                // Normalize label: collapse whitespace, trim
                $label = preg_replace('/\s+/', ' ', trim($matches[1]));
                $url = trim($matches[2]);

                // Collect continuation lines (URL can start on continuation line)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if ($this->isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new reference definition
                    if (preg_match('/^\[([^\]]+)\]:/', $nextLine)) {
                        break;
                    }
                    if ($this->startsNewBlock($nextLine)) {
                        break;
                    }
                    if (preg_match('/^\s+(\S.*)$/', $nextLine, $contMatch)) {
                        $url .= $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                $this->references[$label] = new ReferenceDefinition($url, $pendingAttrs);
                $pendingAttrs = [];
                $i = $j;

                continue;
            }

            // Non-reference line, clear any pending attributes
            if (!$this->isBlankLine($line)) {
                $pendingAttrs = [];
            }

            $i++;
        }
    }

    /**
     * Parse inline attributes from a string like ".class #id title=foo"
     *
     * @return array<string, mixed>
     */
    protected function parseInlineAttributes(string $attrStr): array
    {
        $attrs = [];

        // Match: .class, #id, key="quoted value", key='quoted value', key=unquoted
        preg_match_all('/\.([^\s.#=]+)|#([^\s.#=]+)|([^\s.#=]+)="([^"]*)"|([^\s.#=]+)=\'([^\']*)\'|([^\s.#=]+)=([^\s}"\']+)/', $attrStr, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                // Class attribute
                $existing = $attrs['class'] ?? '';
                $attrs['class'] = trim($existing . ' ' . $match[1]);
            } elseif (!empty($match[2])) {
                // ID attribute
                $attrs['id'] = $match[2];
            } elseif (!empty($match[3])) {
                // key="double quoted value"
                $attrs[$match[3]] = $match[4] ?? '';
            } elseif (isset($match[5])) {
                // key='single quoted value'
                $attrs[$match[5]] = $match[6] ?? '';
            } elseif (isset($match[7])) {
                // key=unquoted
                $attrs[$match[7]] = $match[8] ?? '';
            }
        }

        return $attrs;
    }

    /**
     * Extract footnote definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractFootnotes(array $lines): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Match footnote definition: [^label]: content
            if (preg_match('/^\[\^([^\]]+)\]:\s*(.*)$/', $line, $matches)) {
                $label = $matches[1];
                $content = $matches[2];

                // Collect continuation lines (indented)
                $contentLines = [$content];
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if ($this->isBlankLine($nextLine)) {
                        $j++;

                        continue;
                    }
                    if (preg_match('/^\s+(.+)$/', $nextLine, $contMatch)) {
                        $contentLines[] = $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                $footnote = new Footnote($label);
                $this->parseBlocks($footnote, $contentLines, 0);
                $this->footnotes[$label] = $footnote;
            }

            $i++;
        }
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     */
    protected function parseBlocks(Node $parent, array $lines, int $indent): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Skip blank lines
            if ($this->isBlankLine($line)) {
                $i++;

                continue;
            }

            // Try to parse block attributes first
            $attrConsumed = $this->tryParseBlockAttributes($lines, $i);
            if ($attrConsumed !== null) {
                $i += $attrConsumed;

                continue;
            }

            // Try custom block patterns first (before built-in syntax)
            $customConsumed = $this->tryCustomBlockPatterns($parent, $lines, $i);
            if ($customConsumed !== null) {
                $i += $customConsumed;

                continue;
            }

            // Try to match block elements in order of precedence
            // Comment and raw block must come before code block since ``` =format is a special case
            $consumed = $this->tryParseComment($parent, $lines, $i)
                ?? $this->tryParseRawBlock($parent, $lines, $i)
                ?? $this->tryParseCodeBlock($parent, $lines, $i)
                ?? $this->tryParseDiv($parent, $lines, $i)
                ?? $this->tryParseHeading($parent, $lines, $i)
                ?? $this->tryParseThematicBreak($parent, $line, $i)
                ?? $this->tryParseBlockQuote($parent, $lines, $i)
                ?? $this->tryParseDefinitionList($parent, $lines, $i)
                ?? $this->tryParseList($parent, $lines, $i)
                ?? $this->tryParseLineBlock($parent, $lines, $i)
                ?? $this->tryParseTable($parent, $lines, $i)
                ?? $this->tryParseFootnoteDefinition($lines, $i)
                ?? $this->tryParseReferenceDefinition($lines, $i)
                ?? $this->tryParseParagraph($parent, $lines, $i);

            $i += $consumed;
        }
    }

    /**
     * Try to match custom block patterns at the current position
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryCustomBlockPatterns(Node $parent, array $lines, int $start): ?int
    {
        if (empty($this->customBlockPatterns)) {
            return null;
        }

        $line = $lines[$start];

        foreach ($this->customBlockPatterns as $pattern => $callback) {
            if (preg_match($pattern, $line)) {
                $consumed = $callback($lines, $start, $parent, $this);
                if ($consumed !== null) {
                    return $consumed;
                }
            }
        }

        return null;
    }

    /**
     * Try to parse block attributes {.class #id key=value}
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockAttributes(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Must start with {
        if (!str_starts_with($line, '{')) {
            return null;
        }

        // Check for empty attribute block {} - just skip it
        if (preg_match('/^\{\}\s*$/', $line)) {
            return 1;
        }

        // Check for single-line attribute: {.class} or {#id} or {key=value}
        if (preg_match('/^\{(.+)\}\s*$/', $line, $matches)) {
            $attrStr = $matches[1];
            // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
            if (!preg_match('/^[.#a-zA-Z%]/', $attrStr)) {
                return null;
            }
            $this->parseAttributeString($attrStr);

            return 1;
        }

        // Try multi-line attributes: { on first line, } on a later line
        // Collect lines until we find the closing }
        $count = count($lines);
        $attrContent = substr($line, 1); // Remove opening {
        $i = $start + 1;

        while ($i < $count) {
            $nextLine = $lines[$i];

            // Check if this line ends the attribute block
            if (preg_match('/^(.*)\}\s*$/', $nextLine, $closeMatch)) {
                $attrContent .= ' ' . $closeMatch[1];
                $attrStr = trim($attrContent);

                // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
                if (!preg_match('/^[.#a-zA-Z%]/', $attrStr)) {
                    return null;
                }
                $this->parseAttributeString($attrStr);

                return $i - $start + 1;
            }

            // Continuation line (must be indented)
            if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                $attrContent .= ' ' . $contMatch[1];
                $i++;
            } else {
                // Not a valid continuation
                return null;
            }
        }

        return null;
    }

    /**
     * Parse attribute string and add to pending attributes
     */
    protected function parseAttributeString(string $attrStr): void
    {
        // Parse .class
        if (preg_match_all('/\.([^\s.#=}]+)/', $attrStr, $classMatches)) {
            $existingClass = $this->pendingAttributes['class'] ?? '';
            $newClasses = implode(' ', $classMatches[1]);
            $this->pendingAttributes['class'] = trim($existingClass . ' ' . $newClasses);
        }

        // Parse #id
        if (preg_match('/#([^\s.#=}]+)/', $attrStr, $idMatch)) {
            $this->pendingAttributes['id'] = $idMatch[1];
        }

        // Parse key="double quoted value" (with escape support), key='single quoted value', or key=unquoted
        // The regex uses ([^"\\]|\\.)* to match content with escaped characters
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)="((?:[^"\\\\]|\\\\.)*)"|([a-zA-Z_][a-zA-Z0-9_-]*)=\'((?:[^\'\\\\]|\\\\.)*)\'|([a-zA-Z_][a-zA-Z0-9_-]*)=([^\s}"\']+)/', $attrStr, $kvMatches, PREG_SET_ORDER)) {
            foreach ($kvMatches as $match) {
                if (($match[1] ?? '') !== '') {
                    // key="double quoted value"
                    $this->pendingAttributes[$match[1]] = $this->processAttributeEscapes($match[2] ?? '');
                } elseif (($match[3] ?? '') !== '') {
                    // key='single quoted value'
                    $this->pendingAttributes[$match[3]] = $this->processAttributeEscapes($match[4] ?? '');
                } elseif (($match[5] ?? '') !== '') {
                    // key=unquoted
                    $this->pendingAttributes[$match[5]] = $match[6] ?? '';
                }
            }
        }
    }

    /**
     * Process escape sequences in attribute values
     *
     * Handles \\ -> \ and \" -> " (and other escaped characters)
     */
    protected function processAttributeEscapes(string $value): string
    {
        // Replace escape sequences: \X -> X for any character X
        return preg_replace('/\\\\(.)/', '$1', $value) ?? $value;
    }

    /**
     * Apply pending attributes to a node and clear them
     */
    protected function applyPendingAttributes(Node $node): void
    {
        if (!empty($this->pendingAttributes)) {
            $node->setAttributes($this->pendingAttributes);
            $this->pendingAttributes = [];
        }
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCodeBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match opening fence: 3+ backticks or tildes, optionally with leading whitespace
        if (!preg_match('/^(\s*)(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $indent = $matches[1];
        $fence = $matches[2];
        $fenceChar = $fence[0]; // Either ` or ~
        $fenceLength = strlen($fence);
        $info = trim($matches[3]);

        // Check for inline code on a single line: ``` foo ``` should be inline code
        // If the info string contains closing backticks of same or greater length, it's inline code
        if ($fenceChar === '`') {
            $closingPattern = '/`{' . $fenceLength . ',}/';
            if (preg_match($closingPattern, $info)) {
                // This looks like inline code on a single line, let paragraph parser handle it
                return null;
            }
        }

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;
        $indentLen = strlen($indent);

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (same char, equal or longer length), with optional indent
            if (preg_match('/^\s*' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;
                $closed = true;

                break;
            }

            // Remove indent from content lines (up to the same amount as opening fence)
            if ($indentLen > 0 && preg_match('/^(\s{0,' . $indentLen . '})(.*)$/', $currentLine, $lineMatch)) {
                $currentLine = $lineMatch[2];
            }

            $content .= $currentLine . "\n";
            $i++;
        }

        // If not closed and this is a single line with backticks and content, treat as inline code
        // But if no content (just ``` by itself), treat as empty code block
        if (!$closed && $fenceChar === '`' && $i === $start + 1 && $info !== '') {
            // Single line unclosed fence with content - treat as inline code in a paragraph
            return null;
        }

        if (!$closed) {
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

        $language = $info !== '' ? $info : null;

        $codeBlock = new CodeBlock(rtrim($content, "\n"), $language);
        $this->applyPendingAttributes($codeBlock);
        $parent->appendChild($codeBlock);

        return $i - $start;
    }

    /**
     * Try to parse a comment block {% ... %}
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match comment opening: {%
        if (!str_starts_with(trim($line), '{%')) {
            return null;
        }

        $content = '';
        $i = $start;
        $count = count($lines);
        $inComment = false;
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (!$inComment) {
                // Look for opening {%
                $openPos = strpos($currentLine, '{%');
                if ($openPos !== false) {
                    $inComment = true;
                    $afterOpen = substr($currentLine, $openPos + 2);
                    // Check if closing is on same line
                    $closePos = strpos($afterOpen, '%}');
                    if ($closePos !== false) {
                        $content .= substr($afterOpen, 0, $closePos);
                        $i++;
                        $closed = true;

                        break;
                    }
                    $content .= $afterOpen . "\n";
                }
            } else {
                // Look for closing %}
                $closePos = strpos($currentLine, '%}');
                if ($closePos !== false) {
                    $content .= substr($currentLine, 0, $closePos);
                    $i++;
                    $closed = true;

                    break;
                }
                $content .= $currentLine . "\n";
            }

            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed comment', $start, 1, true);
        }

        // Comments are stored but not rendered
        $comment = new Comment(trim($content));
        $parent->appendChild($comment);

        return $i - $start;
    }

    /**
     * Try to parse a raw block ``` =format
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseRawBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match opening fence with =format: ``` =html or ```=html
        if (!preg_match('/^(`{3,})\s+=(\w+)\s*$/', $line, $matches)) {
            return null;
        }

        $fence = $matches[1];
        $fenceLength = strlen($fence);
        $format = $matches[2];

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if (preg_match('/^`{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;
                $closed = true;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed raw block', $start, 1, true);
        }

        $rawBlock = new RawBlock(rtrim($content, "\n"), $format);
        $this->applyPendingAttributes($rawBlock);
        $parent->appendChild($rawBlock);

        return $i - $start;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDiv(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match opening fence: 3+ colons with optional class
        if (!preg_match('/^(:{3,})\s*(.*)$/', $line, $matches)) {
            return null;
        }

        $fence = $matches[1];
        $fenceLength = strlen($fence);
        $className = trim($matches[2]);

        $div = new Div();
        if ($className !== '') {
            $div->addClass($className);
        }

        // Save and clear pending attributes - they apply to the div, not inner content
        $divAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $innerLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;
        $inCodeBlock = false;
        $codeBlockFence = '';
        $codeBlockFenceLength = 0;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Track code blocks so we don't mistake ::: inside code blocks as closing fences
            if (!$inCodeBlock && preg_match('/^(`{3,}|~{3,})/', $currentLine, $codeFenceMatch)) {
                $inCodeBlock = true;
                $codeBlockFence = $codeFenceMatch[1][0]; // ` or ~
                $codeBlockFenceLength = strlen($codeFenceMatch[1]);
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }
            if ($inCodeBlock) {
                // Check for closing code fence
                if (preg_match('/^' . preg_quote($codeBlockFence, '/') . '{' . $codeBlockFenceLength . ',}\s*$/', $currentLine)) {
                    $inCodeBlock = false;
                }
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }

            // Check for closing fence (equal or longer) - only when not in code block
            if (preg_match('/^:{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;
                $closed = true;

                break;
            }

            $innerLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed div', $start, 1, true);
        }

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0);
        $this->lineOffset = $previousOffset;

        // Apply the saved attributes to the div
        if ($divAttributes !== []) {
            $div->setAttributes($divAttributes);
        }
        $parent->appendChild($div);

        return $i - $start;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHeading(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match heading: 1-6 # characters followed by space
        if (!preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
            return null;
        }

        $level = strlen($matches[1]);
        $content = $matches[2];

        // Collect continuation lines (lines starting with same # or plain text)
        $i = $start + 1;
        $count = count($lines);
        while ($i < $count) {
            $nextLine = $lines[$i];
            // Check for continuation with # prefix
            if (preg_match('/^#{1,' . $level . '}\s+(.+)$/', $nextLine, $contMatch)) {
                $content .= ' ' . $contMatch[1];
                $i++;
            } else {
                break;
            }
        }

        $heading = new Heading($level);
        $this->inlineParser->parse($heading, trim($content), $start);
        $this->applyPendingAttributes($heading);
        $parent->appendChild($heading);

        return $i - $start;
    }

    protected function tryParseThematicBreak(Node $parent, string $line, int $start): ?int
    {
        // Match thematic break: 3+ * or - characters (with optional spaces between)
        // Examples: ***, ---, * * *, - - -, *-*-*-*, **   **
        $stripped = preg_replace('/\s+/', '', $line);
        if ($stripped === null || strlen($stripped) < 3) {
            return null;
        }

        // Must contain only * and/or - characters
        if (!preg_match('/^[\*\-]+$/', $stripped)) {
            return null;
        }

        // Must have at least 3 of the marker characters total
        $starCount = substr_count($stripped, '*');
        $dashCount = substr_count($stripped, '-');

        // Valid if we have 3+ stars OR 3+ dashes OR a mix totaling 3+
        if ($starCount + $dashCount < 3) {
            return null;
        }

        $thematicBreak = new ThematicBreak();
        $this->applyPendingAttributes($thematicBreak);
        $parent->appendChild($thematicBreak);

        return 1;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match block quote: > followed by space or end of line (NOT >text or >>)
        // The > must be followed by a space or be at end of line
        if (!preg_match('/^> (.*)$/', $line, $matches) && !preg_match('/^>$/', $line)) {
            return null;
        }

        $blockQuote = new BlockQuote();

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $innerLines = [];

        if (preg_match('/^> (.*)$/', $line, $matches)) {
            $innerLines[] = $matches[1];
        } elseif (preg_match('/^>$/', $line)) {
            $innerLines[] = '';
        }

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->isBlankLine($currentLine)) {
                break;
            }

            // Continue with "> " prefix (space required)
            if (preg_match('/^> (.*)$/', $currentLine, $matches)) {
                $innerLines[] = $matches[1];
                $i++;
            } elseif (preg_match('/^>$/', $currentLine)) {
                // Empty block quote line (just >)
                $innerLines[] = '';
                $i++;
            } elseif (!$this->startsNewBlock($currentLine)) {
                // Lazy continuation - includes lines starting with ">" but no space
                // These become literal ">text" in the paragraph
                $innerLines[] = $currentLine;
                $i++;
            } else {
                break;
            }
        }

        $this->parseBlocks($blockQuote, $innerLines, 0);

        // Apply the saved attributes to the blockquote
        if ($quoteAttributes !== []) {
            $blockQuote->setAttributes($quoteAttributes);
        }
        $parent->appendChild($blockQuote);

        return $i - $start;
    }

    /**
     * Try to parse a definition list
     *
     * Term
     * : Definition
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        // Look ahead: need a term line followed by : definition
        if ($start + 1 >= count($lines)) {
            return null;
        }

        $termLine = $lines[$start];
        $defLine = $lines[$start + 1];

        // Term must not start with special characters
        if (preg_match('/^[>#\-*+\d`:|]/', $termLine) || $this->isBlankLine($termLine)) {
            return null;
        }

        // Next line must start with : (definition marker)
        if (!preg_match('/^:\s+(.*)$/', $defLine)) {
            return null;
        }

        $defList = new DefinitionList();
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines between items
            if ($this->isBlankLine($currentLine)) {
                $i++;

                continue;
            }

            // Check if this line is a term (followed by : definition)
            if ($i + 1 < $count && !preg_match('/^[>#\-*+\d`:|]/', $currentLine)) {
                $nextLine = $lines[$i + 1];
                if (preg_match('/^:\s+(.*)$/', $nextLine)) {
                    // Parse term
                    $term = new DefinitionTerm();
                    $this->inlineParser->parse($term, trim($currentLine), $i);
                    $defList->appendChild($term);
                    $i++;

                    // Parse definitions (can have multiple)
                    while ($i < $count) {
                        $defLineContent = $lines[$i];
                        if (preg_match('/^:\s+(.*)$/', $defLineContent, $defMatch)) {
                            $defContent = $defMatch[1];

                            // Collect continuation lines
                            $defLines = [$defContent];
                            $i++;
                            while ($i < $count) {
                                $contLine = $lines[$i];
                                if ($this->isBlankLine($contLine)) {
                                    break;
                                }
                                if (preg_match('/^\s+(.+)$/', $contLine, $contMatch)) {
                                    $defLines[] = $contMatch[1];
                                    $i++;
                                } elseif (preg_match('/^:\s+/', $contLine)) {
                                    // Another definition
                                    break;
                                } else {
                                    break;
                                }
                            }

                            $def = new DefinitionDescription();
                            $this->parseBlocks($def, $defLines, 0);
                            $defList->appendChild($def);
                        } else {
                            break;
                        }
                    }

                    continue;
                }
            }

            break;
        }

        if (count($defList->getChildren()) === 0) {
            return null;
        }

        $this->applyPendingAttributes($defList);
        $parent->appendChild($defList);

        return $i - $start;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Try to match list item marker
        $listInfo = $this->parseListItemMarker($line);
        if ($listInfo === null) {
            return null;
        }

        // Definition lists are handled separately in djot
        if ($listInfo['type'] === ListBlock::TYPE_DEFINITION) {
            return $this->tryParseDjotDefinitionList($parent, $lines, $start);
        }

        // Disambiguate roman vs alphabetical for single-letter markers
        // by looking at subsequent items
        if (!empty($listInfo['ambiguous'])) {
            $listInfo = $this->disambiguateListStyle($listInfo, $lines, $start);
        }

        $list = new ListBlock(
            $listInfo['type'],
            $listInfo['start'] ?? 1,
            true, // Start as tight
            $listInfo['marker'],
            $listInfo['style'] ?? null,
        );

        // Save and clear pending attributes - they apply to the list, not inner content
        $listAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $i = $start;
        $count = count($lines);
        $lastItemHadBlankAfter = false;
        $markerIndent = $listInfo['marker_indent'] ?? 0;
        $firstItem = true; // Track first item to use listInfo directly

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if ($this->isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $i++;

                continue;
            }

            // Check for indented continuation (after blank line = nested content)
            $hasIndent = preg_match('/^\s+/', $currentLine);

            if ($lastItemHadBlankAfter && $hasIndent) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = $this->getLastListItem($list);
                if ($lastItem !== null) {
                    $list->setTight(false);
                    // Collect all indented content
                    $subLines = [];
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if ($this->isBlankLine($subLine)) {
                            $subLines[] = '';
                            $i++;

                            continue;
                        }
                        // Check if line has any indentation
                        if (preg_match('/^\s+/', $subLine)) {
                            // Remove up to two spaces of indentation (aligns with content after marker)
                            $subLines[] = preg_replace('/^ {1,2}/', '', $subLine) ?? $subLine;
                            $i++;
                        } else {
                            // Check if it's a same-level list item
                            $itemInfo = $this->parseListItemMarker($subLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                break;
                            }

                            // End of list
                            break;
                        }
                    }
                    // Parse nested content
                    if ($subLines !== []) {
                        $this->parseBlocks($lastItem, $subLines, 0);
                    }
                    $lastItemHadBlankAfter = false;

                    continue;
                }
            }

            // For first item, use the already-parsed listInfo (may have been disambiguated)
            // For subsequent items, parse fresh
            if ($firstItem) {
                $itemInfo = $listInfo;
                $firstItem = false;
            } else {
                $itemInfo = $this->parseListItemMarker($currentLine);

                // Check if this is a list item of the same type, marker, and style
                if ($itemInfo === null || $itemInfo['type'] !== $listInfo['type'] || $itemInfo['marker'] !== $listInfo['marker']) {
                    break;
                }

                // For ordered lists with styles (roman/alpha), also check style matches
                if (isset($listInfo['style']) && isset($itemInfo['style']) && $itemInfo['style'] !== $listInfo['style']) {
                    break;
                }
            }

            // If there was a blank line before this item, list is loose
            if ($lastItemHadBlankAfter) {
                $list->setTight(false);
            }

            $listItem = new ListItem($itemInfo['checked'] ?? null);
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            $itemLines = [$itemContent];
            $i++;
            $lastItemHadBlankAfter = false;
            $hasNonMarkerContinuation = false;

            while ($i < $count) {
                $nextLine = $lines[$i];

                if ($this->isBlankLine($nextLine)) {
                    break;
                }

                // Check if next line starts a new list item at same level
                $nextInfo = $this->parseListItemMarker($nextLine);
                if ($nextInfo !== null) {
                    break;
                }

                // Check if it starts another block
                if ($this->startsNewBlock($nextLine)) {
                    break;
                }

                // Non-indented continuation is literal text (djot behavior)
                // Strip up to two leading spaces for alignment with content after marker
                // (2 = typical marker width like "- " or "* ")
                $nextLine = preg_replace('/^ {1,2}/', '', $nextLine) ?? $nextLine;
                $itemLines[] = $nextLine;
                $hasNonMarkerContinuation = true;
                $i++;
            }

            // For tight lists with continuation lines, parse as plain text
            // This prevents "-like" lines from being parsed as nested lists
            if ($hasNonMarkerContinuation) {
                $paragraph = new Paragraph();
                $this->inlineParser->parse($paragraph, implode("\n", $itemLines), $start);
                $listItem->appendChild($paragraph);
            } else {
                $this->parseBlocks($listItem, $itemLines, 0);
            }
            $list->appendChild($listItem);
        }

        // Apply the saved attributes to the list
        if ($listAttributes !== []) {
            $list->setAttributes($listAttributes);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Get the last list item from a list
     */
    protected function getLastListItem(ListBlock $list): ?ListItem
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
     * Get indentation level (number of leading spaces / 2, rounded down)
     */
    protected function getIndentLevel(string $line): int
    {
        if (preg_match('/^(\s+)/', $line, $matches)) {
            return (int)(strlen($matches[1]) / 2);
        }

        return 0;
    }

    /**
     * Remove N levels of indentation from a line
     */
    protected function removeIndent(string $line, int $levels): string
    {
        $spaces = $levels * 2;

        return preg_replace('/^\s{0,' . $spaces . '}/', '', $line) ?? $line;
    }

    /**
     * Parse djot-style definition list (: term with indented definition)
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDjotDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        $defList = new DefinitionList();
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Skip blank lines
            if ($this->isBlankLine($line)) {
                $i++;

                continue;
            }

            // Must start with ": "
            if (!preg_match('/^:\s+(.*)$/', $line, $matches)) {
                break;
            }

            // The term is the content after ": "
            $termContent = $matches[1];
            $termLines = [$termContent];
            $i++;

            // Collect continuation lines for term (before blank line, single-space indent)
            while ($i < $count) {
                $nextLine = $lines[$i];
                if ($this->isBlankLine($nextLine)) {
                    break;
                }
                // Single space continuation is part of term
                if (preg_match('/^ ([^ ].*)$/', $nextLine, $contMatch)) {
                    $termLines[] = $contMatch[1];
                    $i++;
                } else {
                    break;
                }
            }

            // Create term node
            $term = new DefinitionTerm();
            $this->inlineParser->parse($term, implode("\n", $termLines), $start);
            $defList->appendChild($term);

            // Now collect definition content (after blank line, 2-space indent)
            $defLines = [];
            while ($i < $count) {
                $defLine = $lines[$i];

                if ($this->isBlankLine($defLine)) {
                    $defLines[] = '';
                    $i++;

                    continue;
                }

                // Check for next term
                if (preg_match('/^:\s+/', $defLine)) {
                    break;
                }

                // Definition content must be indented by 2 spaces
                if (preg_match('/^  (.*)$/', $defLine, $defMatch)) {
                    $defLines[] = $defMatch[1];
                    $i++;
                } else {
                    break;
                }
            }

            // Create definition node
            $def = new DefinitionDescription();
            if ($defLines !== []) {
                // Remove leading blank lines
                while ($defLines !== [] && $defLines[0] === '') {
                    array_shift($defLines);
                }
                // Remove trailing blank lines
                $defLineCount = count($defLines);
                while ($defLineCount > 0 && $defLines[$defLineCount - 1] === '') {
                    array_pop($defLines);
                    $defLineCount--;
                }
                $this->parseBlocks($def, $defLines, 0);
            }
            $defList->appendChild($def);
        }

        if (count($defList->getChildren()) === 0) {
            return null;
        }

        $this->applyPendingAttributes($defList);
        $parent->appendChild($defList);

        return $i - $start;
    }

    /**
     * Disambiguate between roman numeral and alphabetical list styles
     * by looking at subsequent list items.
     *
     * Rules:
     * - If any subsequent item is multi-char roman (ii, iv, etc) -> roman
     * - If any subsequent item is NOT a valid roman letter (j, k, etc) -> alphabetical
     * - If items repeat the same letter (i, i, i) -> roman (alpha would require sequence)
     * - Otherwise -> roman (default for ambiguous single letters like i, v, x)
     *
     * @param array<string, mixed> $listInfo
     * @param array<string> $lines
     * @param int $start
     *
     * @return array<string, mixed>
     */
    protected function disambiguateListStyle(array $listInfo, array $lines, int $start): array
    {
        $marker = $listInfo['marker'];
        $firstMarkerLetter = null;

        // Extract the letter from the first marker for comparison
        if (preg_match('/^([ivxlcdmIVXLCDM])/', $lines[$start], $m)) {
            $firstMarkerLetter = strtolower($m[1]);
        } elseif (preg_match('/^\(([ivxlcdmIVXLCDM])\)/', $lines[$start], $m)) {
            $firstMarkerLetter = strtolower($m[1]);
        }

        $hasMultiCharRoman = false;
        $hasNonRomanLetter = false;
        $allSameLetter = true;
        $romanChars = 'ivxlcdm';

        // Look ahead at subsequent items
        for ($i = $start + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Stop at blank lines or non-list content
            if ($this->isBlankLine($line)) {
                continue;
            }

            // Check if this line is a list item with the same marker type
            $itemInfo = $this->parseListItemMarker($line);
            if ($itemInfo === null || $itemInfo['marker'] !== $marker) {
                break;
            }

            // Extract the marker text
            $markerText = null;
            if ($marker === '()') {
                if (preg_match('/^\(([^)]+)\)/', $line, $m)) {
                    $markerText = strtolower($m[1]);
                }
            } else {
                if (preg_match('/^([a-zA-Z]+)[.)]/', $line, $m)) {
                    $markerText = strtolower($m[1]);
                }
            }

            if ($markerText === null) {
                break;
            }

            // Check for multi-character roman numerals
            if (strlen($markerText) > 1 && preg_match('/^[ivxlcdm]+$/', $markerText)) {
                $hasMultiCharRoman = true;

                break;
            }

            // Check if it's a letter not used in roman numerals
            if (strlen($markerText) === 1 && strpos($romanChars, $markerText) === false) {
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
            // Clearly roman - keep original interpretation
            return $listInfo;
        }

        if ($hasNonRomanLetter) {
            // Must be alphabetical since the sequence includes non-roman letters
            $listInfo['start'] = $listInfo['alpha_start'];
            $listInfo['style'] = $listInfo['alpha_style'];
            unset($listInfo['ambiguous'], $listInfo['alpha_start'], $listInfo['alpha_style']);

            return $listInfo;
        }

        // If all items are the same single letter, it's likely roman (numbering that restarts)
        // Otherwise, default to roman for ambiguous single letters
        return $listInfo;
    }

    /**
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool, style?: string, marker_indent?: int}|null
     */
    protected function parseListItemMarker(string $line): ?array
    {
        // Task list: - [ ] or - [x] or - [X]
        if (preg_match('/^[-*+]\s+\[([ xX])\]\s+(.*)$/', $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_TASK,
                'marker' => '-',
                'content' => $matches[2],
                'checked' => strtolower($matches[1]) === 'x',
            ];
        }

        // Bullet list: -, +, or *
        if (preg_match('/^([-*+])\s+(.*)$/', $line, $matches)) {
            $marker = $matches[1];
            $content = $matches[2];

            // Don't treat as list if content ends with same marker (likely emphasis)
            // e.g., "* foo *" should be emphasis, not a list
            if ($marker === '*' || $marker === '-') {
                $trimmed = rtrim($content);
                if ($trimmed !== '' && substr($trimmed, -1) === $marker) {
                    // Check if there's non-whitespace between markers
                    $inner = substr($trimmed, 0, -1);
                    if (trim($inner) !== '' && !str_contains($inner, "\n")) {
                        return null;
                    }
                }
            }

            return [
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $marker,
                'content' => $content,
            ];
        }

        // Ordered list: 1. or 1) or (1)
        if (preg_match('/^(\d+)([.)])\s+(.*)$/', $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[3],
                'start' => (int)$matches[1],
            ];
        }

        if (preg_match('/^\((\d+)\)\s+(.*)$/', $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => '()',
                'content' => $matches[2],
                'start' => (int)$matches[1],
            ];
        }

        // Roman numeral ordered list: i. or I. or i) or I) or (i) or (I)
        // Single letters are ambiguous - could be alpha or roman
        // Return both possibilities and let the list parser disambiguate based on subsequent items
        if (preg_match('/^([ivxlcdmIVXLCDM]+)([.)])\s+(.*)$/', $line, $matches)) {
            $roman = $matches[1];
            $isLower = ctype_lower($roman[0]);
            $start = $this->romanToInt(strtoupper($roman));
            if ($start > 0) {
                $result = [
                    'type' => ListBlock::TYPE_ORDERED,
                    'marker' => $matches[2],
                    'content' => $matches[3],
                    'start' => $start,
                    'style' => $isLower ? 'i' : 'I',
                ];
                // For single letters that are ambiguous, add alternate interpretation
                if (strlen($roman) === 1) {
                    $alphaStart = ord(strtolower($roman)) - ord('a') + 1;
                    $result['ambiguous'] = true;
                    $result['alpha_start'] = $alphaStart;
                    $result['alpha_style'] = $isLower ? 'a' : 'A';
                }

                return $result;
            }
        }

        if (preg_match('/^\(([ivxlcdmIVXLCDM]+)\)\s+(.*)$/', $line, $matches)) {
            $roman = $matches[1];
            $isLower = ctype_lower($roman[0]);
            $start = $this->romanToInt(strtoupper($roman));
            if ($start > 0) {
                $result = [
                    'type' => ListBlock::TYPE_ORDERED,
                    'marker' => '()',
                    'content' => $matches[2],
                    'start' => $start,
                    'style' => $isLower ? 'i' : 'I',
                ];
                // For single letters that are ambiguous, add alternate interpretation
                if (strlen($roman) === 1) {
                    $alphaStart = ord(strtolower($roman)) - ord('a') + 1;
                    $result['ambiguous'] = true;
                    $result['alpha_start'] = $alphaStart;
                    $result['alpha_style'] = $isLower ? 'a' : 'A';
                }

                return $result;
            }
        }

        // Alpha ordered list: a. or A. or a) or A) or (a) or (A)
        // Only single letters - multi-letter checked above as roman
        if (preg_match('/^([a-zA-Z])([.)])\s+(.*)$/', $line, $matches)) {
            $letter = $matches[1];
            $isLower = ctype_lower($letter);
            $start = ord(strtolower($letter)) - ord('a') + 1;

            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => $matches[2],
                'content' => $matches[3],
                'start' => $start,
                'style' => $isLower ? 'a' : 'A',
            ];
        }

        if (preg_match('/^\(([a-zA-Z])\)\s+(.*)$/', $line, $matches)) {
            $letter = $matches[1];
            $isLower = ctype_lower($letter);
            $start = ord(strtolower($letter)) - ord('a') + 1;

            return [
                'type' => ListBlock::TYPE_ORDERED,
                'marker' => '()',
                'content' => $matches[2],
                'start' => $start,
                'style' => $isLower ? 'a' : 'A',
            ];
        }

        // Definition list: :
        if (preg_match('/^:\s+(.*)$/', $line, $matches)) {
            return [
                'type' => ListBlock::TYPE_DEFINITION,
                'marker' => ':',
                'content' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * Try to parse a line block (preserves line breaks)
     *
     * | Line one
     * | Line two
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseLineBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Line block lines start with | followed by space (not |---|)
        // Must distinguish from tables which have | at both start and end
        if (!preg_match('/^\|\s(.*)$/', $line, $matches)) {
            return null;
        }

        // Make sure it's not a table (tables have | at start and end outside of code spans)
        if (preg_match('/^\|.*\|$/', $line) && $this->lineEndsWithPipeOutsideCodeSpan($line)) {
            return null;
        }

        // Line blocks should have at least 2 consecutive lines starting with |
        // A single line like `| `a |`` should be a paragraph, not a line block
        $count = count($lines);
        $hasSecondLine = ($start + 1 < $count) && preg_match('/^\|/', $lines[$start + 1]);
        if (!$hasSecondLine) {
            return null;
        }

        $lineBlock = new LineBlock();
        $contentLines = [];
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (preg_match('/^\|\s(.*)$/', $currentLine, $matches)) {
                $contentLines[] = $matches[1];
                $i++;
            } elseif (preg_match('/^\|$/', $currentLine)) {
                // Empty line block line
                $contentLines[] = '';
                $i++;
            } else {
                break;
            }
        }

        // Parse each line as a paragraph with hard breaks between them
        $paragraph = new Paragraph();
        foreach ($contentLines as $index => $contentLine) {
            $this->inlineParser->parse($paragraph, $contentLine, $start + $index);
            if ($index < count($contentLines) - 1) {
                $paragraph->appendChild(new HardBreak());
            }
        }
        $lineBlock->appendChild($paragraph);

        $this->applyPendingAttributes($lineBlock);
        $parent->appendChild($lineBlock);

        return $i - $start;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Table rows start and end with | (but the ending | must be outside code spans)
        if (!preg_match('/^\|.*\|$/', $line)) {
            return null;
        }

        // Verify the line truly ends with | outside of code spans
        // A line like `| `a |`` has its final | inside a code span, so it's not a table
        if (!$this->lineEndsWithPipeOutsideCodeSpan($line)) {
            return null;
        }

        $table = new Table();
        $i = $start;
        $count = count($lines);
        $alignments = [];
        $headerFound = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (!preg_match('/^\|.*\|$/', $currentLine)) {
                break;
            }

            // Check if this is a separator row (contains |, -, with optional : and spaces)
            // Must have at least one - to be a separator (| | is not a separator)
            if (preg_match('/^\|[\s:|-]+\|$/', $currentLine) && str_contains($currentLine, '-')) {
                $alignments = $this->parseTableAlignments($currentLine);
                $headerFound = true;

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if (count($children) > 0) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row with alignments
                        $headerRow = new TableRow(true);
                        $cellIndex = 0;
                        foreach ($lastRow->getChildren() as $cell) {
                            if ($cell instanceof TableCell) {
                                $alignment = $alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT;
                                $headerCell = new TableCell(true, $alignment);
                                foreach ($cell->getChildren() as $child) {
                                    $headerCell->appendChild($child);
                                }
                                $headerRow->appendChild($headerCell);
                                $cellIndex++;
                            }
                        }
                        // Replace last row
                        $table->replaceChild(count($children) - 1, $headerRow);
                    }
                }
                $i++;

                continue;
            }

            // Parse regular row
            $row = new TableRow(false);
            $cells = $this->parseTableCells($currentLine);

            foreach ($cells as $index => $cellContent) {
                $alignment = $alignments[$index] ?? TableCell::ALIGN_DEFAULT;
                $cell = new TableCell(false, $alignment);
                $this->inlineParser->parse($cell, trim($cellContent), $i);
                $row->appendChild($cell);
            }

            $table->appendChild($row);
            $i++;
        }

        // A separator-only table is valid (creates empty table)
        // Only return null if we didn't parse anything at all
        if (count($table->getChildren()) === 0 && !$headerFound) {
            return null;
        }

        $this->applyPendingAttributes($table);
        $parent->appendChild($table);

        // Check for caption: ^ Caption text (can have blank line before it)
        $captionStart = $i;
        if ($captionStart < $count && $this->isBlankLine($lines[$captionStart])) {
            $captionStart++;
        }

        if ($captionStart < $count && preg_match('/^\^\s+(.+)$/', $lines[$captionStart], $captionMatch)) {
            $captionLines = [$captionMatch[1]];
            $captionStart++;

            // Caption can continue on non-blank lines that don't start a new block
            while ($captionStart < $count) {
                $nextLine = $lines[$captionStart];
                if ($this->isBlankLine($nextLine)) {
                    break;
                }
                // Stop at block-level elements
                if ($this->startsNewBlock($nextLine)) {
                    break;
                }
                // Stop at new table or caption
                if (preg_match('/^\|/', $nextLine) || preg_match('/^\^/', $nextLine)) {
                    break;
                }
                $captionLines[] = $nextLine;
                $captionStart++;
            }

            // Join with newlines and parse inline content into a temporary container
            $captionContent = implode("\n", $captionLines);
            $captionContainer = new Paragraph();
            $this->inlineParser->parse($captionContainer, $captionContent, $start);
            // Transfer children to table's caption
            foreach ($captionContainer->getChildren() as $child) {
                $table->addCaptionChild($child);
            }
            $i = $captionStart;
        }

        return $i - $start;
    }

    /**
     * Skip footnote definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFootnoteDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match footnote definition: [^label]: content
        if (!preg_match('/^\[\^([^\]]+)\]:\s*/', $line)) {
            return null;
        }

        // Skip the footnote definition and any continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if ($this->isBlankLine($nextLine)) {
                $i++;

                continue;
            }
            if (preg_match('/^\s+/', $nextLine)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    /**
     * @return array<string>
     */
    protected function parseTableAlignments(string $separatorLine): array
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
     * @return array<string>
     */
    protected function parseTableCells(string $line): array
    {
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
     * Check if a line has unclosed code spans
     */
    protected function hasUnclosedCodeSpan(string $line): bool
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
     * Check if a line ends with | outside of code spans
     * Used to verify table row syntax (| `a |` is not a table because final | is in code span)
     */
    protected function lineEndsWithPipeOutsideCodeSpan(string $line): bool
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

    /**
     * Skip reference definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseReferenceDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match reference definition: [label]: url (url can be empty, on next line)
        if (!preg_match('/^\[([^\]]+)\]:\s*(.*)$/', $line, $matches)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if ($this->isBlankLine($nextLine)) {
                break;
            }
            // Check if next line starts a new reference definition
            if (preg_match('/^\[([^\]]+)\]:/', $nextLine)) {
                break;
            }
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            if (preg_match('/^\s+(\S.*)$/', $nextLine, $contMatch)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    /**
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseParagraph(Node $parent, array $lines, int $start): int
    {
        $line = $lines[$start];
        $content = $line;

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];

            if ($this->isBlankLine($nextLine) || $this->startsNewBlock($nextLine)) {
                break;
            }

            // Strip leading indentation from continuation lines (up to 3 spaces)
            $nextLine = preg_replace('/^   /', '', $nextLine) ?? $nextLine;
            $content .= "\n" . $nextLine;
            $i++;
        }

        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $content, $start);
        $this->applyPendingAttributes($paragraph);
        $parent->appendChild($paragraph);

        return $i - $start;
    }

    protected function appendToLastParagraph(Node $parent, string $content, int $line): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    protected function isBlankLine(string $line): bool
    {
        return trim($line) === '';
    }

    protected function startsNewBlock(string $line): bool
    {
        // Check if line starts a new block element
        // Note: Block quotes (>) are NOT included here - they don't interrupt paragraphs
        // Block quotes can only start after a blank line or at document start
        // Note: Ordered lists (\d+[.)]) are NOT included - they don't interrupt paragraphs in djot
        // Note: Fenced divs (:::) are NOT included - they don't interrupt paragraphs in djot
        // Only unordered lists (-*+) can interrupt paragraphs
        return (bool)preg_match('/^(#{1,6}\s|[-*+]\s|`{3,}|\|)/', $line);
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
    }

    /**
     * Convert roman numeral to integer
     */
    protected function romanToInt(string $roman): int
    {
        $values = [
            'I' => 1,
            'V' => 5,
            'X' => 10,
            'L' => 50,
            'C' => 100,
            'D' => 500,
            'M' => 1000,
        ];

        $result = 0;
        $prev = 0;
        $length = strlen($roman);

        for ($i = $length - 1; $i >= 0; $i--) {
            $char = $roman[$i];
            if (!isset($values[$char])) {
                return 0; // Invalid roman numeral
            }
            $value = $values[$char];
            if ($value < $prev) {
                $result -= $value;
            } else {
                $result += $value;
            }
            $prev = $value;
        }

        return $result;
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? null;
    }

    public function hasFootnote(string $label): bool
    {
        return isset($this->footnotes[$label]);
    }

    /**
     * Add warning for undefined reference (called from InlineParser)
     */
    public function addUndefinedReferenceWarning(string $ref, int $line, int $column): void
    {
        $this->addWarning("Undefined reference '{$ref}'", $line, $column, false);
    }

    /**
     * Add warning for undefined footnote (called from InlineParser)
     */
    public function addUndefinedFootnoteWarning(string $label, int $line, int $column): void
    {
        $this->addWarning("Undefined footnote '{$label}'", $line, $column, false);
    }

    /**
     * Get the inline parser for registering custom patterns
     */
    public function getInlineParser(): InlineParser
    {
        return $this->inlineParser;
    }
}
