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

            // Match reference definition: [label]: url
            if (preg_match('/^\[([^\]]+)\]:\s*(.+)$/', $line, $matches)) {
                $label = $matches[1];
                $url = trim($matches[2]);

                // Collect continuation lines
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if ($this->isBlankLine($nextLine) || $this->startsNewBlock($nextLine)) {
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
            } else {
                // Non-reference line, clear any pending attributes
                if (!$this->isBlankLine($line)) {
                    $pendingAttrs = [];
                }
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

        // Match block attribute: {.class} or {#id} or {key=value} or combinations
        // Allow } inside quoted strings
        if (!preg_match('/^\{(.+)\}\s*$/', $line, $matches)) {
            return null;
        }

        // Verify it looks like attributes (starts with ., #, or key=)
        $attrStr = $matches[1];
        if (!preg_match('/^[.#a-zA-Z_]/', $attrStr)) {
            return null;
        }

        $this->parseAttributeString($attrStr);

        return 1;
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

        // Parse key="double quoted value", key='single quoted value', or key=unquoted
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)="([^"]*)"|([a-zA-Z_][a-zA-Z0-9_-]*)=\'([^\']*)\'|([a-zA-Z_][a-zA-Z0-9_-]*)=([^\s}"\']+)/', $attrStr, $kvMatches, PREG_SET_ORDER)) {
            foreach ($kvMatches as $match) {
                if (($match[1] ?? '') !== '') {
                    // key="double quoted value"
                    $this->pendingAttributes[$match[1]] = $match[2] ?? '';
                } elseif (($match[3] ?? '') !== '') {
                    // key='single quoted value'
                    $this->pendingAttributes[$match[3]] = $match[4] ?? '';
                } elseif (($match[5] ?? '') !== '') {
                    // key=unquoted
                    $this->pendingAttributes[$match[5]] = $match[6] ?? '';
                }
            }
        }
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

        // Match opening fence: 3+ backticks or tildes
        if (!preg_match('/^(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $fence = $matches[1];
        $fenceChar = $fence[0]; // Either ` or ~
        $fenceLength = strlen($fence);
        $info = trim($matches[2]);
        $language = $info !== '' ? $info : null;

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (same char, equal or longer length)
            if (preg_match('/^' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;
                $closed = true;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

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

        $innerLines = [];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
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

        $this->applyPendingAttributes($div);
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
        // Match thematic break: 3+ * or - characters (with optional whitespace)
        if (!preg_match('/^\s*(\*{3,}|-{3,})\s*$/', $line)) {
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
        $this->applyPendingAttributes($blockQuote);
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

        $list = new ListBlock(
            $listInfo['type'],
            $listInfo['start'] ?? 1,
            true,
            $listInfo['marker'],
        );

        $i = $start;
        $count = count($lines);
        $hasBlankLine = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->isBlankLine($currentLine)) {
                $hasBlankLine = true;
                $i++;

                continue;
            }

            $itemInfo = $this->parseListItemMarker($currentLine);

            // Check if this is a list item of the same type
            if ($itemInfo === null || $itemInfo['type'] !== $listInfo['type'] || $itemInfo['marker'] !== $listInfo['marker']) {
                // Check for indented continuation
                if (preg_match('/^(\s{2,})(.*)$/', $currentLine, $indentMatch) && !$this->isBlankLine($lines[$i - 1] ?? '')) {
                    // This is a continuation of the previous list item
                    $lastItem = $list->getChildren()[count($list->getChildren()) - 1] ?? null;
                    if ($lastItem instanceof ListItem) {
                        $this->appendToLastParagraph($lastItem, trim($indentMatch[2]), $i);
                    }
                    $i++;

                    continue;
                }

                break;
            }

            if ($hasBlankLine) {
                $list->setTight(false);
            }

            $listItem = new ListItem($itemInfo['checked'] ?? null);
            $itemContent = $itemInfo['content'];

            // Collect item content lines
            $itemLines = [$itemContent];
            $i++;

            while ($i < $count) {
                $nextLine = $lines[$i];

                if ($this->isBlankLine($nextLine)) {
                    break;
                }

                // Check if next line starts a new list item or block
                if ($this->parseListItemMarker($nextLine) !== null || $this->startsNewBlock($nextLine)) {
                    break;
                }

                // Indented continuation
                if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                    $itemLines[] = $contMatch[1];
                    $i++;
                } else {
                    break;
                }
            }

            $this->parseBlocks($listItem, $itemLines, 0);
            $list->appendChild($listItem);
        }

        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * @return array{type: string, marker: string, content: string, start?: int, checked?: bool}|null
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
            return [
                'type' => ListBlock::TYPE_BULLET,
                'marker' => $matches[1],
                'content' => $matches[2],
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

        // Make sure it's not a table (tables have | at start and end)
        if (preg_match('/^\|.*\|$/', $line)) {
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

        // Table rows start and end with |
        if (!preg_match('/^\|.*\|$/', $line)) {
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

            // Check if this is a separator row
            if (preg_match('/^\|[\s:|-]+\|$/', $currentLine)) {
                $alignments = $this->parseTableAlignments($currentLine);
                $headerFound = true;

                // Mark previous row as header
                $children = $table->getChildren();
                if (count($children) > 0) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row
                        $headerRow = new TableRow(true);
                        foreach ($lastRow->getChildren() as $cell) {
                            if ($cell instanceof TableCell) {
                                $headerCell = new TableCell(true, $cell->getAlignment());
                                foreach ($cell->getChildren() as $child) {
                                    $headerCell->appendChild($child);
                                }
                                $headerRow->appendChild($headerCell);
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

        if (count($table->getChildren()) === 0) {
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
            $captionContent = $captionMatch[1];
            $captionStart++;

            // Caption can continue on indented lines
            while ($captionStart < $count && preg_match('/^\s+(\S.*)$/', $lines[$captionStart], $contMatch)) {
                $captionContent .= ' ' . $contMatch[1];
                $captionStart++;
            }

            $table->setCaption(trim($captionContent));
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

        // Split by | but not \|
        $cells = preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return array_map(fn ($cell) => str_replace('\\|', '|', $cell), $cells);
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

        // Match reference definition: [label]: url
        if (!preg_match('/^\[([^\]]+)\]:\s*(.+)$/', $line, $matches)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if ($this->isBlankLine($nextLine) || $this->startsNewBlock($nextLine)) {
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
        return (bool)preg_match('/^(#{1,6}\s|[-*+]\s|\d+[.)]\s|`{3,}|:{3,}|\|)/', $line);
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
