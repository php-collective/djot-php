<?php

declare(strict_types=1);

namespace Djot\Parser;

use Djot\Exception\ParseException;
use Djot\Exception\ParseWarning;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Block\Caption;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Comment;
use Djot\Node\Block\DefinitionDescription;
use Djot\Node\Block\DefinitionList;
use Djot\Node\Block\DefinitionTerm;
use Djot\Node\Block\Div;
use Djot\Node\Block\Figure;
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
use Djot\Node\Inline\Image;
use Djot\Node\Node;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\Block\ListParser;
use Djot\Parser\Block\TableParser;
use Djot\Parser\Utility\AttributeParser;
use Djot\Parser\Utility\IndentationHelper;

/**
 * Block-level parser for Djot
 */
class BlockParser
{
    protected InlineParser $inlineParser;

    protected ListParser $listParser;

    protected TableParser $tableParser;

    protected FencedBlockParser $fencedBlockParser;

    /**
     * @var array<string, \Djot\Parser\ReferenceDefinition>
     */
    protected array $references = [];

    /**
     * @var array<string, \Djot\Node\Block\Footnote>
     */
    protected array $footnotes = [];

    /**
     * Abbreviation definitions: maps abbreviation text to its definition
     *
     * @var array<string, string>
     */
    protected array $abbreviations = [];

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

    /**
     * When true, enables "significant newlines" mode:
     * - Block elements can interrupt paragraphs without blank lines
     * - Nested blocks in lists don't need blank lines
     * - More markdown-like, chat-friendly parsing
     *
     * This deviates from djot spec but provides more intuitive behavior
     * for chat messages, comments, and quick notes.
     */
    protected bool $significantNewlines = false;

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
        bool $significantNewlines = false,
    ) {
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->significantNewlines = $significantNewlines;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
    }

    /**
     * Enable or disable significant newlines mode
     *
     * When enabled:
     * - Lists, blockquotes, code blocks can interrupt paragraphs
     * - Nested blocks in list items don't need preceding blank lines
     * - Indentation alone triggers block nesting
     *
     * This provides markdown-like behavior for chat and quick note use cases.
     */
    public function setSignificantNewlines(bool $value): self
    {
        $this->significantNewlines = $value;

        return $this;
    }

    /**
     * Check if significant newlines mode is enabled
     */
    public function getSignificantNewlines(): bool
    {
        return $this->significantNewlines;
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
        $this->abbreviations = [];
        $this->pendingAttributes = [];
        $this->warnings = [];
        $this->lineOffset = 0;
        $document = new Document();
        $lines = $this->splitLines($input);

        // First pass: extract reference definitions, footnotes, abbreviations, and heading references
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);
        $this->extractAbbreviations($lines);
        $this->extractHeadingReferences($lines);

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
                $pendingAttrs = AttributeParser::parse($attrMatches[1]);
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
                    if (IndentationHelper::isBlankLine($nextLine)) {
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
            if (!IndentationHelper::isBlankLine($line)) {
                $pendingAttrs = [];
            }

            $i++;
        }
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

                // Determine base indentation (2 spaces for footnotes)
                $baseIndent = 2;

                // Collect continuation lines (indented or blank)
                $contentLines = [];
                if (trim($content) !== '') {
                    $contentLines[] = $content;
                }
                $j = $i + 1;
                $hasContent = false;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        // Add blank line to preserve structure
                        $contentLines[] = '';
                        $j++;

                        continue;
                    }
                    // Check if line has at least base indentation (2 spaces or 1 tab)
                    if (preg_match('/^(?:[ ]{' . $baseIndent . '}|\t)(.*)$/', $nextLine, $contMatch)) {
                        $contentLines[] = $contMatch[1];
                        $hasContent = true;
                        $j++;
                    } elseif (!$hasContent && preg_match('/^\s+(.+)$/', $nextLine, $contMatch)) {
                        // Allow flexible indentation for first content line
                        $contentLines[] = $contMatch[1];
                        $hasContent = true;
                        $j++;
                    } else {
                        break;
                    }
                }

                // Remove trailing blank lines
                $lineCount = count($contentLines);
                while ($lineCount > 0 && $contentLines[$lineCount - 1] === '') {
                    array_pop($contentLines);
                    $lineCount--;
                }

                $footnote = new Footnote($label);
                if ($contentLines) {
                    $this->parseBlocks($footnote, $contentLines, 0);
                }
                $this->footnotes[$label] = $footnote;
            }

            $i++;
        }
    }

    /**
     * Extract abbreviation definitions from the document
     *
     * Syntax: *[ABBR]: Full Definition Text
     *
     * This is an extension feature inspired by PHP Markdown Extra.
     *
     * @param array<string> $lines
     */
    protected function extractAbbreviations(array $lines): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Match abbreviation definition: *[abbr]: definition
            if (preg_match('/^\*\[([^\]]+)\]:\s*(.*)$/', $line, $matches)) {
                $abbr = $matches[1];
                $definition = trim($matches[2]);

                // Collect continuation lines (indented)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new abbreviation definition
                    if (preg_match('/^\*\[([^\]]+)\]:/', $nextLine)) {
                        break;
                    }
                    if ($this->startsNewBlock($nextLine)) {
                        break;
                    }
                    // Continuation line (indented)
                    if (preg_match('/^\s+(.+)$/', $nextLine, $contMatch)) {
                        $definition .= ' ' . $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                // Store the abbreviation (case-sensitive)
                $this->abbreviations[$abbr] = $definition;
                $i = $j;

                continue;
            }

            $i++;
        }
    }

    /**
     * Extract heading IDs as implicit reference definitions
     * This allows [Heading][] style links to headings
     *
     * @param array<string> $lines
     */
    protected function extractHeadingReferences(array $lines): void
    {
        $usedIds = [];
        $pendingId = null;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Check for explicit ID attribute before heading: {#custom-id}
            if (preg_match('/^\{#([^\s}]+)\}\s*$/', $line, $attrMatch)) {
                $pendingId = $attrMatch[1];

                continue;
            }

            // Match heading: optional leading spaces, 1-6 # characters, followed by space(s) and content
            // Space after # is syntax delimiter, not indentation - must be space(s) per spec, not tab
            if (preg_match('/^[ ]{0,3}(#{1,6})(?: +(.*))?$/', $line, $matches)) {
                $headingText = isset($matches[2]) ? trim($matches[2]) : '';

                // Collect continuation lines
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (trim($nextLine) === '' || preg_match('/^[ ]{0,3}#{1,6}/', $nextLine)) {
                        break;
                    }
                    if (!$this->startsNewBlock($nextLine)) {
                        $headingText .= ' ' . trim($nextLine);
                        $j++;
                    } else {
                        break;
                    }
                }

                // Generate ID from heading text (same algorithm as renderer)
                if ($pendingId !== null) {
                    $id = $pendingId;
                    $pendingId = null;
                } else {
                    // Strip inline markup to get plain text for ID
                    $plainText = preg_replace('/[*_`\[\]{}]/', '', $headingText) ?? $headingText;
                    $id = str_replace(' ', '-', trim($plainText));
                    // Handle duplicate IDs
                    $baseId = $id;
                    $counter = 1;
                    while (isset($usedIds[$id])) {
                        $id = $baseId . '-' . $counter;
                        $counter++;
                    }
                }
                $usedIds[$id] = true;

                // Register as reference if not already defined
                // Use normalized heading text as the label (for [Heading][] style links)
                $label = preg_replace('/\s+/', ' ', trim($headingText)) ?? $headingText;
                if (!isset($this->references[$label])) {
                    $this->references[$label] = new ReferenceDefinition('#' . $id, []);
                }
            } else {
                // Non-heading, non-attribute line - clear pending ID
                if (!IndentationHelper::isBlankLine($line)) {
                    $pendingId = null;
                }
            }
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
            if (IndentationHelper::isBlankLine($line)) {
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
            // Fenced comment must come before thematic break (%%% vs ---)
            // Comment and raw block must come before code block since ``` =format is a special case
            // Caption must come before paragraph to catch `^ caption text`
            $consumed = $this->tryParseFencedComment($parent, $lines, $i)
                ?? $this->tryParseComment($parent, $lines, $i)
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
                ?? $this->tryParseAbbreviationDefinition($lines, $i)
                ?? $this->tryParseCaption($parent, $lines, $i)
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
            // Exclude % which starts comments (handled by tryParseComment)
            if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                return null;
            }

            // Check if attributes precede a reference definition - if so, skip storing them
            // (they were already applied during extractReferences)
            $count = count($lines);
            $nextIdx = $start + 1;
            while ($nextIdx < $count && IndentationHelper::isBlankLine($lines[$nextIdx])) {
                $nextIdx++;
            }
            if ($nextIdx < $count && preg_match('/^\[([^\]]+)\]:/', $lines[$nextIdx])) {
                // Attributes precede a reference definition, don't store them as block attrs
                return 1;
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
                // Exclude % which starts comments (handled by tryParseComment)
                if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
        $this->pendingAttributes = AttributeParser::parseAndMerge($this->pendingAttributes, $attrStr);
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

        // Use FencedBlockParser to detect code fence opener
        $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceChar = $fenceInfo['char'];
        $fenceLength = $fenceInfo['length'];
        $info = $fenceInfo['info'];
        $indentLen = strlen($fenceInfo['indent']);

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            // Remove indent from content lines (up to the same amount as opening fence)
            $currentLine = $this->fencedBlockParser->removeIndent($currentLine, $indentLen);

            $content .= $currentLine . "\n";
            $i++;
        }

        // If not closed and using backticks, check if this should be inline code
        if (!$closed && $fenceChar === '`') {
            if ($i === $start + 1 && $info !== '') {
                // Single line unclosed fence with content - treat as inline code in a paragraph
                return null;
            }
            if ($content === '' && $info === '') {
                // Empty unclosed fence (just ``` with nothing) - treat as inline code
                return null;
            }
        }

        if (!$closed) {
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

        $language = $info !== '' ? $info : null;

        $codeBlock = new CodeBlock(trim($content, "\n"), $language);
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

        // Use FencedBlockParser to check for comment opener
        if (!$this->fencedBlockParser->isCommentOpener($line)) {
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
     * Try to parse a fenced comment block %%% ... %%%
     *
     * This is an extension that allows multi-line comments with blank lines,
     * which the standard {% %} syntax cannot handle.
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFencedComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceLength = $fenceInfo['length'];
        $contentLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isFencedCommentCloser($currentLine, $fenceLength)) {
                $closed = true;
                $i++;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed fenced comment', $start, 1, true);
        }

        // Trim trailing empty lines but preserve internal blank lines
        while ($contentLines && trim(end($contentLines)) === '') {
            array_pop($contentLines);
        }

        $content = implode("\n", $contentLines);

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

        // Use FencedBlockParser to detect raw block opener
        $rawInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if ($rawInfo === null) {
            return null;
        }

        $fenceLength = $rawInfo['length'];
        $format = $rawInfo['format'];

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, '`', $fenceLength)) {
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

        $rawBlock = new RawBlock(trim($content, "\n"), $format);
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

        // Use FencedBlockParser to detect div opener
        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $fenceLength = $divInfo['length'];
        $className = $divInfo['className'];

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
            if (!$inCodeBlock) {
                $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($currentLine);
                if ($codeFenceInfo !== null) {
                    $inCodeBlock = true;
                    $codeBlockFence = $codeFenceInfo['char'];
                    $codeBlockFenceLength = $codeFenceInfo['length'];
                    $innerLines[] = $currentLine;
                    $i++;

                    continue;
                }
            }
            if ($inCodeBlock) {
                // Check for closing code fence
                if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $codeBlockFence, $codeBlockFenceLength)) {
                    $inCodeBlock = false;
                }
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }

            // Check for closing fence (equal or longer) - only when not in code block
            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $fenceLength)) {
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

        // Fast early exit: headings start with # (possibly after up to 3 spaces)
        $trimmed = ltrim($line, ' ');
        if (!isset($trimmed[0]) || $trimmed[0] !== '#') {
            return null;
        }

        // Match heading: optional leading spaces, 1-6 # characters, optionally followed by space(s) and content
        // Can be: "## Heading", "##", "   ## Heading", "##\n", etc.
        // Space after # is syntax delimiter - must be space(s) per spec, not tab
        if (!preg_match('/^[ ]{0,3}(#{1,6})(?: +(.*))?$/', $line, $matches)) {
            return null;
        }

        $level = strlen($matches[1]);
        $content = isset($matches[2]) ? trim($matches[2]) : '';

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);
        while ($i < $count) {
            $nextLine = $lines[$i];

            // Empty line ends the heading
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // Check for continuation with # prefix (same level or less) - these continue the heading
            // e.g., "# Heading\n# more" becomes "Heading\nmore" for a level-1 heading
            if (preg_match('/^[ ]{0,3}#{1,' . $level . '} +(.+)$/', $nextLine, $contMatch)) {
                if ($content !== '') {
                    $content .= "\n";
                }
                $content .= $contMatch[1];
                $i++;
            } elseif (preg_match('/^[ ]{0,3}#{1,6}(?: |$)/', $nextLine)) {
                // Different level heading marker (or empty heading) starts a new heading
                break;
            } elseif (!$this->startsNewBlock($nextLine)) {
                // "Lazy" continuation - plain text continues the heading
                if ($content !== '') {
                    $content .= "\n";
                }
                $content .= $nextLine;
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

        // Fast early exit: block quotes start with >
        if (!isset($line[0]) || $line[0] !== '>') {
            return null;
        }

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

            if (IndentationHelper::isBlankLine($currentLine)) {
                break;
            }

            // Continue with "> " prefix (space required per spec)
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
        if (preg_match('/^[>#\-*+\d`:|]/', $termLine) || IndentationHelper::isBlankLine($termLine)) {
            return null;
        }

        // Next line must start with : (definition marker)
        if (!preg_match('/^: +(.*)$/', $defLine)) {
            return null;
        }

        $defList = new DefinitionList();
        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines between items
            if (IndentationHelper::isBlankLine($currentLine)) {
                $i++;

                continue;
            }

            // Check if this line is a term (followed by : definition)
            if ($i + 1 < $count && !preg_match('/^[>#\-*+\d`:|]/', $currentLine)) {
                $nextLine = $lines[$i + 1];
                if (preg_match('/^: +(.*)$/', $nextLine)) {
                    // Parse term
                    $term = new DefinitionTerm();
                    $this->inlineParser->parse($term, trim($currentLine), $i);
                    $defList->appendChild($term);
                    $i++;

                    // Parse definitions (can have multiple)
                    while ($i < $count) {
                        $defLineContent = $lines[$i];
                        if (preg_match('/^: +(.*)$/', $defLineContent, $defMatch)) {
                            $defContent = $defMatch[1];

                            // Collect continuation lines
                            $defLines = [$defContent];
                            $i++;
                            while ($i < $count) {
                                $contLine = $lines[$i];
                                if (IndentationHelper::isBlankLine($contLine)) {
                                    break;
                                }
                                if (preg_match('/^\s+(.+)$/', $contLine, $contMatch)) {
                                    $defLines[] = $contMatch[1];
                                    $i++;
                                } elseif (preg_match('/^: +/', $contLine)) {
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
        $listInfo = $this->listParser->parseListItemMarker($line);
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
            $listInfo = $this->listParser->disambiguateListStyle($listInfo, $lines, $start);
        }

        // Get the base indentation of this list
        $baseIndent = IndentationHelper::getLeadingSpaces($line);

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
        $firstItem = true; // Track first item to use listInfo directly

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingSpaces($currentLine);

            // If line is less indented than base, we're done with this list
            if ($currentIndent < $baseIndent) {
                break;
            }

            // Check for indented continuation (after blank line = nested content)
            if ($lastItemHadBlankAfter && $currentIndent > $baseIndent) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = $this->listParser->getLastListItem($list);
                if ($lastItem !== null) {
                    // Check if the first indented content is a list marker or regular text
                    // Blank line followed by indented TEXT = loose list (multiple paragraphs)
                    // Blank line followed by indented LIST MARKER = tight nesting
                    $trimmedCurrent = ltrim($currentLine);
                    $firstContentIsListMarker = $this->listParser->parseListItemMarker($trimmedCurrent) !== null;
                    if (!$firstContentIsListMarker) {
                        // Indented text after blank = loose list
                        $list->setTight(false);
                    }

                    // Collect all indented content at this new level
                    $subLines = [];
                    $subIndent = $currentIndent;
                    // Track the maximum content indent we've seen (for detecting drop-back to marker level)
                    $maxContentIndent = $currentIndent;
                    $sawBlankLine = false;
                    $brokeForParentContent = false;
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            $subLines[] = '';
                            $sawBlankLine = true;
                            $i++;

                            continue;
                        }
                        $lineIndent = IndentationHelper::getLeadingSpaces($subLine);

                        // If we've seen content at a higher indent level (actual nested content),
                        // and now we're back at the marker level (subIndent) after a blank line,
                        // this content belongs to the parent level - break to let parent handle it
                        if ($lineIndent === $subIndent && $maxContentIndent > $subIndent && $sawBlankLine) {
                            // Set flags so parent loop handles this as continuation content
                            $lastItemHadBlankAfter = true;
                            $brokeForParentContent = true;

                            break;
                        }

                        // Check if line has at least the subIndent level
                        if ($lineIndent >= $subIndent) {
                            // Track the highest content indent seen
                            if ($lineIndent > $maxContentIndent) {
                                $maxContentIndent = $lineIndent;
                            }
                            // Remove subIndent worth of indentation (handling tabs)
                            $subLines[] = IndentationHelper::stripLeadingIndent($subLine, $subIndent);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent === $baseIndent) {
                            // Line is at base indent - check if it starts a new block or list item
                            $trimmedLine = ltrim($subLine);
                            $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                break;
                            }
                            // Content at base indent that's not a matching list marker
                            // Check if it's a block element - if so, end list content collection
                            // Use isBlockElementStart() which detects blocks regardless of mode
                            if ($this->isBlockElementStart($trimmedLine) || $this->startsNewBlock($trimmedLine)) {
                                break;
                            }
                            // Otherwise it's lazy continuation at base level - include in nested content
                            $subLines[] = $trimmedLine;
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent > $baseIndent) {
                            // Line is at intermediate indent (between base and nested content)
                            // This content belongs to a parent list level, not current nested content
                            break;
                        } else {
                            // End of list
                            break;
                        }
                    }
                    // Remove trailing blank lines from subLines
                    $subLineCount = count($subLines);
                    while ($subLineCount > 0 && $subLines[$subLineCount - 1] === '') {
                        array_pop($subLines);
                        $subLineCount--;
                    }
                    // Parse nested content
                    if ($subLines !== []) {
                        $this->parseBlocks($lastItem, $subLines, 0);
                    }
                    // In djot, blank lines within nested content don't make the parent list loose
                    // The list is only loose if there's a blank line directly after item content
                    // (before nested content starts), which is already handled elsewhere
                    // Only reset if we didn't break to handle content at parent level
                    if (!$brokeForParentContent) {
                        $lastItemHadBlankAfter = false;
                    }

                    continue;
                }
            }

            // For first item, use the already-parsed listInfo (may have been disambiguated)
            // For subsequent items, parse fresh
            $trimmedLine = ltrim($currentLine);
            if ($firstItem) {
                $itemInfo = $listInfo;
                $firstItem = false;
            } else {
                // Only match items at the same indentation level
                if ($currentIndent !== $baseIndent) {
                    break;
                }
                $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);

                // Check if this is a list item of the same type, marker, and style
                if ($itemInfo === null || !$this->listParser->itemMatchesList($listInfo, $itemInfo)) {
                    break;
                }
            }

            // If there was a blank line before this item, list is loose
            if ($lastItemHadBlankAfter) {
                $list->setTight(false);
            }

            $listItem = new ListItem($itemInfo['taskMarker'] ?? null);
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            $itemLines = [$itemContent];
            $i++;
            $lastItemHadBlankAfter = false;
            $hasNonMarkerContinuation = false;

            // Calculate content indent (base + marker width, typically 2 for "- ")
            $contentIndent = $baseIndent + 2;

            while ($i < $count) {
                $nextLine = $lines[$i];

                if (IndentationHelper::isBlankLine($nextLine)) {
                    break;
                }

                $nextIndent = IndentationHelper::getLeadingSpaces($nextLine);
                $nextTrimmed = ltrim($nextLine);

                // Check if next line starts a new list item at same level (base indent)
                if ($nextIndent === $baseIndent) {
                    $nextInfo = $this->listParser->parseListItemMarker($nextTrimmed);
                    if ($nextInfo !== null) {
                        break;
                    }
                    // Non-list content at base indent - check if it starts another block
                    if ($this->startsNewBlock($nextTrimmed)) {
                        break;
                    }
                }

                // Check for list item attributes (must be at content indent, be a standalone attribute block)
                if (
                    $nextIndent >= $contentIndent &&
                    preg_match('/^\{([^{}]+)\}\s*$/', $nextTrimmed, $attrMatch)
                ) {
                    // This is a list item attribute line - don't add to content
                    break;
                }

                // Content at content indent or more is continuation (even if it looks like a list marker)
                // In djot, "  - b" after "- a" (no blank line) is literal text, not a nested list
                // Unless significantNewlines is enabled, then indented block markers start nested blocks
                if ($nextIndent >= $contentIndent) {
                    // Check if significantNewlines mode allows immediate nested blocks
                    if ($this->significantNewlines) {
                        // Check for any block starter (list, blockquote, code fence, div)
                        if ($this->startsNewBlock($nextTrimmed) || $this->listParser->parseListItemMarker($nextTrimmed) !== null) {
                            // This is a nested block - break out to let normal nesting handle it
                            break;
                        }
                    }
                    // Properly indented continuation - include with original indentation relative to content
                    $itemLines[] = IndentationHelper::stripLeadingIndent($nextLine, $contentIndent);
                } else {
                    // Lazy continuation (not properly indented but not at base level either)
                    $itemLines[] = $nextTrimmed;
                }
                $hasNonMarkerContinuation = true;
                $i++;
            }

            // Check for list item attributes on the next line
            $itemAttributes = [];
            if ($i < $count) {
                $potentialAttrLine = $lines[$i];
                $trimmedAttrLine = ltrim($potentialAttrLine);
                // Check if it's an attribute block at content indent level
                if (
                    preg_match('/^\{([^{}]+)\}\s*$/', $trimmedAttrLine, $attrMatch) &&
                    IndentationHelper::getLeadingSpaces($potentialAttrLine) >= $contentIndent
                ) {
                    $itemAttributes = AttributeParser::parse($attrMatch[1]);
                    $i++;
                }
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

            // In significantNewlines mode, check for immediate nested content (any block type)
            if ($this->significantNewlines && $i < $count) {
                $nextLine = $lines[$i];
                $nextIndent = IndentationHelper::getLeadingSpaces($nextLine);

                // If there's indented content that could be a nested block
                if ($nextIndent >= $contentIndent) {
                    $subLines = [];
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            break;
                        }
                        $lineIndent = IndentationHelper::getLeadingSpaces($subLine);
                        if ($lineIndent >= $contentIndent) {
                            $subLines[] = IndentationHelper::stripLeadingIndent($subLine, $contentIndent);
                            $i++;
                        } elseif ($lineIndent === $baseIndent) {
                            // Back to parent level - check if it's a sibling item
                            break;
                        } else {
                            break;
                        }
                    }
                    if ($subLines !== []) {
                        $this->parseBlocks($listItem, $subLines, 0);
                    }
                }
            }

            // Apply attributes to list item
            if ($itemAttributes !== []) {
                foreach ($itemAttributes as $key => $value) {
                    $listItem->setAttribute($key, $value);
                }
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
     * Parse djot-style definition list (: term with indented definition)
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDjotDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        $defList = new DefinitionList();

        // Save pending attributes for the definition list before parsing children
        $defListAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];

        $i = $start;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Skip blank lines
            if (IndentationHelper::isBlankLine($line)) {
                $i++;

                continue;
            }

            // Must start with ": " (space is syntax delimiter, not tab)
            if (!preg_match('/^: +(.*)$/', $line, $matches)) {
                break;
            }

            // Collect all consecutive terms (multiple `: term` lines can share one definition)
            $terms = [];
            $codeFenceInfo = null;

            while ($i < $count) {
                $termLine = $lines[$i];

                // Skip blank lines between terms
                if (IndentationHelper::isBlankLine($termLine)) {
                    $i++;

                    continue;
                }

                // Check if this is a term line
                if (!preg_match('/^: +(.*)$/', $termLine, $termMatch)) {
                    break;
                }

                $termContent = $termMatch[1];

                // Special case: if term starts with code fence, term is empty and fence is part of definition
                $termStartsWithCodeFence = preg_match('/^(`{3,}|~{3,})/', $termContent, $fenceMatch);

                if ($termStartsWithCodeFence) {
                    // Code fence starts definition - create empty term and break
                    $codeFenceInfo = $termContent;
                    $terms[] = ['lines' => [], 'attributes' => []];
                    $i++;

                    break;
                }

                $termLines = [$termContent];
                $termAttributes = [];
                $i++;

                // Collect continuation lines for term (before blank line, single-space indent)
                while ($i < $count) {
                    $nextLine = $lines[$i];
                    if (IndentationHelper::isBlankLine($nextLine)) {
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

                // Check for term attributes on the next line (standalone attribute block)
                if ($i < $count) {
                    $potentialAttrLine = $lines[$i];
                    if (preg_match('/^\{([^{}]+)\}\s*$/', $potentialAttrLine, $attrMatch)) {
                        $termAttributes = AttributeParser::parse($attrMatch[1]);
                        $i++;
                    }
                }

                $terms[] = ['lines' => $termLines, 'attributes' => $termAttributes];

                // Check if next non-blank line is another term or definition content
                $peekIdx = $i;
                while ($peekIdx < $count && IndentationHelper::isBlankLine($lines[$peekIdx])) {
                    $peekIdx++;
                }

                // If next content is indented definition, stop collecting terms
                if ($peekIdx < $count && preg_match('/^  /', $lines[$peekIdx])) {
                    break;
                }
            }

            // Create term nodes
            foreach ($terms as $termData) {
                $term = new DefinitionTerm();
                $termLines = $termData['lines'];
                if ($termLines !== []) {
                    $this->inlineParser->parse($term, implode("\n", $termLines), $start);
                }
                // Apply term attributes
                if ($termData['attributes'] !== []) {
                    foreach ($termData['attributes'] as $key => $value) {
                        $term->setAttribute($key, $value);
                    }
                }
                $defList->appendChild($term);
            }

            // Now collect definition content (after blank line, 2-space indent)
            // Blank lines within definition content create separate dd elements
            $defLines = [];

            // If term started with code fence, add it to definition content
            if ($codeFenceInfo !== null) {
                $defLines[] = $codeFenceInfo;
            }

            while ($i < $count) {
                $defLine = $lines[$i];

                if (IndentationHelper::isBlankLine($defLine)) {
                    $defLines[] = '';
                    $i++;

                    continue;
                }

                // Check for next term (space is syntax delimiter, not tab)
                if (preg_match('/^: +/', $defLine)) {
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

            // Create definition node(s)
            // Split by blank lines - each block becomes a separate dd
            if ($defLines !== []) {
                $blocks = $this->splitByBlankLines($defLines);
                foreach ($blocks as $block) {
                    $def = new DefinitionDescription();
                    $defAttributes = [];

                    // Check if last line is a standalone attribute block for the dd
                    $blockCount = count($block);
                    if ($blockCount > 0 && preg_match('/^\{([^{}]+)\}\s*$/', $block[$blockCount - 1], $attrMatch)) {
                        $defAttributes = AttributeParser::parse($attrMatch[1]);
                        array_pop($block);
                    }

                    $this->parseBlocks($def, $block, 0);

                    // Apply definition attributes
                    if ($defAttributes !== []) {
                        foreach ($defAttributes as $key => $value) {
                            $def->setAttribute($key, $value);
                        }
                    }
                    $defList->appendChild($def);
                }
            } else {
                // Term with no definition content - create empty dd
                $defList->appendChild(new DefinitionDescription());
            }
        }

        if (count($defList->getChildren()) === 0) {
            return null;
        }

        // Apply the saved attributes to the definition list
        if ($defListAttributes !== []) {
            $defList->setAttributes($defListAttributes);
        }
        $parent->appendChild($defList);

        return $i - $start;
    }

    /**
     * Split lines into blocks separated by blank lines
     *
     * @param array<string> $lines
     *
     * @return array<array<string>>
     */
    protected function splitByBlankLines(array $lines): array
    {
        $blocks = [];
        $current = [];

        // Skip leading blank lines using index (avoid O(n) array_shift)
        $start = 0;
        $count = count($lines);
        while ($start < $count && $lines[$start] === '') {
            $start++;
        }

        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];
            if ($line === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $line;
            }
        }

        // Don't forget the last block
        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
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
        if ($this->tableParser->isTableRow($line)) {
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

        // Use TableParser to check if this is a valid table row
        if (!$this->tableParser->isTableRow($line)) {
            return null;
        }

        $table = new Table();
        $i = $start;
        $count = count($lines);
        $alignments = [];
        $headerFound = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Strip row attributes for validation (|...|{.class} → |...|)
            $lineWithoutRowAttrs = $this->tableParser->stripRowAttributes($currentLine);

            if (!preg_match('/^\|.*\|$/', $lineWithoutRowAttrs)) {
                break;
            }

            // Check if this is a separator row (attributes ignored on separator rows)
            if ($this->tableParser->isSeparatorRow($lineWithoutRowAttrs)) {
                $alignments = $this->tableParser->parseTableAlignments($lineWithoutRowAttrs);
                $headerFound = true;

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if (count($children) > 0) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row with alignments
                        $headerRow = new TableRow(true);
                        // Preserve row attributes from original row
                        $headerRow->setAttributes($lastRow->getAttributes());
                        $cellIndex = 0;
                        foreach ($lastRow->getChildren() as $cell) {
                            if ($cell instanceof TableCell) {
                                $alignment = $alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT;
                                $headerCell = new TableCell(true, $alignment);
                                // Preserve cell attributes from original cell
                                $headerCell->setAttributes($cell->getAttributes());
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

            // Extract row attributes (|...|{.class})
            $rowAttributes = $this->tableParser->extractRowAttributes($currentLine);

            // Parse regular row
            $row = new TableRow(false);
            if ($rowAttributes) {
                $row->setAttributes($rowAttributes);
            }

            // Parse cells with their attributes
            $cellsWithAttrs = $this->tableParser->parseTableCellsWithAttributes($currentLine);

            foreach ($cellsWithAttrs as $index => $cellData) {
                $alignment = $alignments[$index] ?? TableCell::ALIGN_DEFAULT;
                $cell = new TableCell(false, $alignment);
                if ($cellData['attributes']) {
                    $cell->setAttributes($cellData['attributes']);
                }
                $this->inlineParser->parse($cell, trim($cellData['content']), $i);
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

        // Caption parsing is now handled by tryParseCaption

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
            if (IndentationHelper::isBlankLine($nextLine)) {
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
            if (IndentationHelper::isBlankLine($nextLine)) {
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
     * Skip abbreviation definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseAbbreviationDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match abbreviation definition: *[abbr]: definition
        if (!preg_match('/^\*\[([^\]]+)\]:\s*/', $line)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Check if next line starts a new abbreviation definition
            if (preg_match('/^\*\[([^\]]+)\]:/', $nextLine)) {
                break;
            }
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            if (preg_match('/^\s+(.+)$/', $nextLine)) {
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

            // Check for unclosed brace in content - if present, don't break on new block
            // This handles cases like: text{a=x\n# not-a-heading
            $hasUnclosedBrace = $this->hasUnclosedBrace($content);

            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            if (!$hasUnclosedBrace && $this->startsNewBlock($nextLine)) {
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

    /**
     * Try to parse a caption line (^ caption text).
     *
     * Captions apply to the immediately preceding block:
     * - Table → adds <caption> element
     * - Paragraph with single Image → wraps in <figure> with <figcaption>
     * - BlockQuote → wraps in <figure> with <figcaption>
     *
     * @param \Djot\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCaption(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Caption syntax: `^ caption text` (caret followed by space)
        if (!preg_match('/^\^ (.*)$/', $line, $matches)) {
            return null;
        }

        $captionLines = [$matches[1]];
        $i = $start + 1;
        $count = count($lines);

        // Caption can continue on non-blank lines that don't start a new block
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Stop at block-level elements
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            // Stop at new table
            if (preg_match('/^\|/', $nextLine)) {
                break;
            }
            $captionLines[] = $nextLine;
            $i++;
        }

        $captionText = implode("\n", $captionLines);

        // Get the last child to attach the caption to
        $children = $parent->getChildren();
        if (!$children) {
            // No preceding block to attach caption to - treat as regular paragraph
            return null;
        }

        $lastChild = $children[count($children) - 1];

        $linesConsumed = $i - $start;

        // Handle Table - add caption directly to table
        if ($lastChild instanceof Table) {
            $caption = new Caption();
            $this->inlineParser->parse($caption, $captionText, $start);
            $lastChild->setCaption($caption);

            return $linesConsumed;
        }

        // Handle BlockQuote - wrap in figure
        if ($lastChild instanceof BlockQuote) {
            $figure = new Figure();

            // Transfer attributes from blockquote to figure
            foreach ($lastChild->getAttributes() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            // Create caption
            $caption = new Caption();
            $this->inlineParser->parse($caption, $captionText, $start);

            // Build figure: blockquote + caption
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            // Replace blockquote with figure in parent
            $parent->replaceChild(count($children) - 1, $figure);

            return $linesConsumed;
        }

        // Handle Paragraph containing only an Image - wrap in figure
        if ($lastChild instanceof Paragraph) {
            $paragraphChildren = $lastChild->getChildren();
            if (count($paragraphChildren) === 1 && $paragraphChildren[0] instanceof Image) {
                $image = $paragraphChildren[0];

                $figure = new Figure();

                // Transfer attributes from image to figure
                foreach ($image->getAttributes() as $key => $value) {
                    if ($key !== 'src' && $key !== 'alt' && $key !== 'title') {
                        $figure->setAttribute($key, $value);
                        $image->removeAttribute($key);
                    }
                }

                // Create caption
                $caption = new Caption();
                $this->inlineParser->parse($caption, $captionText, $start);

                // Build figure: image + caption
                $figure->appendChild($image);
                $figure->appendChild($caption);

                // Replace paragraph with figure in parent
                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }
        }

        // No valid preceding block for caption - treat as regular paragraph
        return null;
    }

    protected function appendToLastParagraph(Node $parent, string $content, int $line): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    protected function startsNewBlock(string $line): bool
    {
        // Quick check: empty lines don't start blocks
        if ($line === '' || !isset($line[0])) {
            return false;
        }

        // Caption `^ text` can always interrupt paragraphs (special case for figure captions)
        // Quick first-char check before regex
        if ($line[0] === '^' && isset($line[1]) && $line[1] === ' ') {
            return true;
        }

        // In significantNewlines mode, block elements can interrupt paragraphs
        if ($this->significantNewlines) {
            return $this->startsNewBlockSignificant($line);
        }

        // Standard djot behavior:
        // NO block elements can interrupt paragraphs - they all require a blank line
        // See: https://djot.net - "Paragraphs can never be interrupted by other block-level elements"
        return false;
    }

    /**
     * Check if line starts a new block in significantNewlines mode
     *
     * In this mode, more elements can interrupt paragraphs:
     * - Block quotes (>)
     * - Ordered lists (1. 2. etc)
     * - Code fences (```)
     * - Fenced divs (:::)
     */
    protected function startsNewBlockSignificant(string $line): bool
    {
        // Use first-char switch to avoid unnecessary regex checks
        $first = $line[0];

        switch ($first) {
            case '#':
                // Headings: #{1,6}\s
                return preg_match('/^#{1,6}\s/', $line) === 1;
            case '-':
            case '*':
            case '+':
                // Unordered lists or thematic breaks
                if (isset($line[1]) && $line[1] === ' ') {
                    return true; // Unordered list
                }

                // Thematic breaks: *\s*\*\s*\* or -\s*-\s*-
                return preg_match('/^(\*\s*\*\s*\*|-\s*-\s*-)/', $line) === 1;
            case '|':
                // Tables
                return true;
            case '>':
                // Block quotes
                return true;
            case '`':
                // Code fences: `{3,}
                return isset($line[1], $line[2]) && $line[1] === '`' && $line[2] === '`';
            case ':':
                // Fenced divs: :{3,}
                return isset($line[1], $line[2]) && $line[1] === ':' && $line[2] === ':';
            default:
                // Only 1. or 1) can interrupt paragraphs (CommonMark rule)
                // Prevents "1985. That year..." from becoming a list
                if ($first === '1') {
                    return preg_match('/^1[.)]\s/', $line) === 1;
                }

                return false;
        }
    }

    /**
     * Check if line starts a block element that should terminate list content collection.
     *
     * This is different from startsNewBlock() which is about paragraph interruption.
     * Block elements at column 0 (or less than list indent) should always break out
     * of list content collection, regardless of significantNewlines mode.
     *
     * @param string $line The trimmed line to check
     */
    protected function isBlockElementStart(string $line): bool
    {
        // Headings
        if (preg_match('/^#{1,6}(?: |$)/', $line)) {
            return true;
        }

        // Code fences (``` or ~~~)
        if (preg_match('/^[`~]{3,}/', $line)) {
            return true;
        }

        // Fenced divs (::: but not definition list :)
        if (preg_match('/^:{3,}/', $line)) {
            return true;
        }

        // Comment fences (%%%)
        if (preg_match('/^%{3,}/', $line)) {
            return true;
        }

        // Thematic breaks (---, ***, ___)
        if (preg_match('/^([-*_])[ \t]*\1[ \t]*\1/', $line)) {
            return true;
        }

        // Block quotes
        if (preg_match('/^>/', $line)) {
            return true;
        }

        // Tables (starting with |)
        if (preg_match('/^\|/', $line)) {
            return true;
        }

        // Definition list terms (: followed by space or content)
        if (preg_match('/^: /', $line)) {
            return true;
        }

        // List markers - these indicate a new list at this level
        // Bullet lists: -, *, + followed by space
        if (preg_match('/^[-*+] /', $line)) {
            return true;
        }

        // Ordered lists: digit(s) or letter followed by . or ) and space
        if (preg_match('/^(\d+|[a-zA-Z])[.)] /', $line)) {
            return true;
        }

        // Task lists: - [ ] or - [x]
        if (preg_match('/^- \[[xX ]\] /', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if text has an unclosed brace (for attribute blocks)
     */
    protected function hasUnclosedBrace(string $text): bool
    {
        $depth = 0;
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];

            // Handle escape sequences
            if ($char === '\\' && $i + 1 < $len) {
                $i++;

                continue;
            }

            // Handle quotes
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;

                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                $inQuote = false;

                continue;
            }

            // Count braces only outside quotes
            if (!$inQuote) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
            }
        }

        return $depth > 0;
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
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
     * Get all abbreviation definitions
     *
     * @return array<string, string> Map of abbreviation text to definition
     */
    public function getAbbreviations(): array
    {
        return $this->abbreviations;
    }

    /**
     * Get the definition for a specific abbreviation
     */
    public function getAbbreviation(string $abbr): ?string
    {
        return $this->abbreviations[$abbr] ?? null;
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
