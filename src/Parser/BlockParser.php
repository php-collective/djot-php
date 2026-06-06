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
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\Block\ListParser;
use Djot\Parser\Block\TableParser;
use Djot\Parser\Utility\AttributeParser;
use Djot\Parser\Utility\IndentationHelper;
use Djot\Renderer\HeadingIdTracker;

/**
 * Block-level parser for Djot
 */
class BlockParser
{
    /**
     * Neutral starting point for incremental brace scanning.
     *
     * @var array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    private const INITIAL_BRACE_STATE = ['depth' => 0, 'inQuote' => false, 'quoteChar' => '', 'pendingEscape' => false];

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
     * @var array<string, string>
     */
    protected array $pendingAttributes = [];

    /**
     * Source lines for pending block attributes.
     *
     * @var array<string>
     */
    protected array $pendingAttributeSourceLines = [];

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
     * References that have been used (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<string, int> Maps reference label to line where used
     */
    protected array $usedReferences = [];

    /**
     * Anchor links found during parsing (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<array{fragment: string, line: int, column: int}>
     */
    protected array $anchorLinks = [];

    /**
     * Heading IDs generated during heading reference extraction
     * Used for anchor link validation
     *
     * @var array<string, true>
     */
    protected array $headingIds = [];

    /**
     * Labels in $references that were registered by a heading (not by an
     * explicit `[label]: url` definition). Only those are rewritten by the
     * post-parse `rewriteHeadingReferences` pass — an explicit definition
     * always wins over the heading's auto-id.
     *
     * @var array<string, true>
     */
    protected array $headingReferenceLabels = [];

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
     * When true, a top-level block element (lists, blockquotes, headings,
     * tables, thematic breaks, and code/div/comment fences) may interrupt a
     * paragraph without a preceding blank line. This is the behavior the
     * now-deprecated significantNewlines flag exposed for the top level; it
     * also governs the lone-marker rule.
     */
    protected bool $blocksInterruptParagraphs = false;

    /**
     * When true, indentation alone can introduce nested blocks inside list items
     * without enabling global paragraph interruption.
     *
     * @deprecated Broad deprecated lever superseded by the two granular levers
     *   blocksInterruptParagraphs (non-list blocks) and nestedListsWithoutBlankLine (sublists).
     */
    protected bool $nestedBlocksInLists = false;

    /**
     * When true, indentation alone can introduce a nested *list* inside a list
     * item without a blank line, while every other block type stays
     * spec-strict. Focused successor to the deprecated nestedBlocksInLists.
     */
    protected bool $nestedListsWithoutBlankLine = false;

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
        bool $significantNewlines = false,
        bool $nestedBlocksInLists = false,
        bool $blocksInterruptParagraphs = false,
        bool $nestedListsWithoutBlankLine = false,
    ) {
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        // significantNewlines is the deprecated union of blocksInterruptParagraphs
        // and nestedListsWithoutBlankLine (NOT the broad nestedBlocksInLists).
        $this->blocksInterruptParagraphs = $blocksInterruptParagraphs || $significantNewlines;
        $this->nestedBlocksInLists = $nestedBlocksInLists;
        $this->nestedListsWithoutBlankLine = $nestedListsWithoutBlankLine || $significantNewlines;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
    }

    /**
     * Enable or disable significant newlines mode.
     *
     * @deprecated Use setBlocksInterruptParagraphs() and/or
     *   setNestedListsWithoutBlankLine(). significantNewlines is the union of both.
     */
    public function setSignificantNewlines(bool $value): self
    {
        $this->blocksInterruptParagraphs = $value;
        $this->nestedListsWithoutBlankLine = $value;

        return $this;
    }

    /**
     * Check if significant newlines mode is enabled.
     *
     * @deprecated Use getBlocksInterruptParagraphs() / getNestedListsWithoutBlankLine().
     *   Returns the top-level interruption bit for backward compatibility.
     */
    public function getSignificantNewlines(): bool
    {
        return $this->blocksInterruptParagraphs;
    }

    /**
     * Enable or disable top-level paragraph interruption by block elements.
     */
    public function setBlocksInterruptParagraphs(bool $value): self
    {
        $this->blocksInterruptParagraphs = $value;

        return $this;
    }

    /**
     * Check if top-level paragraph interruption is enabled.
     */
    public function getBlocksInterruptParagraphs(): bool
    {
        return $this->blocksInterruptParagraphs;
    }

    /**
     * Enable or disable nested blocks in list items without blank lines.
     *
     * @deprecated Broad nesting of all block types in list items. Prefer setBlocksInterruptParagraphs() (non-list blocks) and/or setNestedListsWithoutBlankLine() (sublists).
     */
    public function setNestedBlocksInLists(bool $value): self
    {
        $this->nestedBlocksInLists = $value;

        return $this;
    }

    /**
     * Check if nested blocks in list items are enabled.
     *
     * @deprecated Prefer getBlocksInterruptParagraphs() / getNestedListsWithoutBlankLine().
     */
    public function getNestedBlocksInLists(): bool
    {
        return $this->nestedBlocksInLists;
    }

    /**
     * Enable or disable nested lists in list items without blank lines.
     */
    public function setNestedListsWithoutBlankLine(bool $value): self
    {
        $this->nestedListsWithoutBlankLine = $value;

        return $this;
    }

    /**
     * Check if nested lists in list items without blank lines are enabled.
     */
    public function getNestedListsWithoutBlankLine(): bool
    {
        return $this->nestedListsWithoutBlankLine;
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
    protected function addWarning(
        string $message,
        int $line,
        int $column = 1,
        bool $isError = false,
        ?string $category = null,
        ?string $suggestion = null,
    ): void {
        // Convert from 0-indexed to 1-indexed for user-facing messages
        $line = $line + $this->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->warnings[] = new ParseWarning($message, $line, $column, $category, $suggestion);
        }
    }

    public function parse(string $input): Document
    {
        $this->references = [];
        $this->footnotes = [];
        $this->abbreviations = [];
        $this->pendingAttributes = [];
        $this->pendingAttributeSourceLines = [];
        $this->warnings = [];
        $this->usedReferences = [];
        $this->anchorLinks = [];
        $this->headingIds = [];
        $this->headingReferenceLabels = [];
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

        // Third pass (post-parse): now that the AST exists we can pre-reserve
        // every explicit `{#id}` (heading or non-heading, including inline
        // attributes) and rewrite implicit heading references to the same
        // deduped ids the renderer will emit, so `[Heading][]` anchors stay
        // in sync with the rendered section id.
        $this->rewriteHeadingReferences($document);

        // Validate references and anchor links if warnings are enabled
        if ($this->collectWarnings) {
            $this->validateReferences();
            $this->validateAnchorLinks($document);
        }

        // Store abbreviations on document for round-trip support
        if ($this->abbreviations !== []) {
            $document->setAbbreviations($this->abbreviations);
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

            // Match reference definition: [label]: url
            // - whitespace required after colon (jgm/djot.js#107)
            // - URL must be a single non-whitespace token; trailing junk like Markdown
            //   `"Title"` makes the line not a reference definition (matches djot.js)
            if (preg_match('/^\[([^\]]+)\]:(?:[ \t]+(\S*))?[ \t]*$/', $line, $matches)) {
                // Normalize label: collapse whitespace, trim
                $label = preg_replace('/\s+/', ' ', trim($matches[1]));
                $url = trim($matches[2] ?? '');

                // Collect continuation lines (URL can start on continuation line)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new reference definition
                    if (preg_match('/^\[([^\]]+)\]:(?=[ \t]|$)/', $nextLine)) {
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

                $this->references[$label] = new ReferenceDefinition($url, $pendingAttrs, $i);
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

            // Match footnote definition: [^label]: content (requires whitespace after colon)
            if (preg_match('/^\[\^([^\]]+)\]:(?:[ \t]+(.*))?[ \t]*$/', $line, $matches)) {
                $label = $matches[1];
                $content = $matches[2] ?? '';

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

            // Match abbreviation definition: *[abbr]: definition (requires whitespace after colon)
            if (preg_match('/^\*\[([^\]]+)\]:(?:[ \t]+(.*))?[ \t]*$/', $line, $matches)) {
                $abbr = $matches[1];
                $definition = trim($matches[2] ?? '');

                // Collect continuation lines (indented)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new abbreviation definition
                    if (preg_match('/^\*\[([^\]]+)\]:(?=[ \t]|$)/', $nextLine)) {
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
        $headingIdTracker = new HeadingIdTracker();
        $pendingId = null;
        $count = count($lines);

        // NOTE: this pass is line-based and runs *before* the AST exists, so
        // it cannot reliably pre-reserve every explicit id the way the
        // renderer's AST-walking `reserveExplicitIds` does. As a result, the
        // implicit-reference href computed here can disagree with the rendered
        // section id when a heading's auto-id collides with a non-heading
        // explicit id elsewhere in the document. Tracked as a follow-up.

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
                $headingText = trim($matches[2] ?? '');

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

                $heading = new Heading(strlen($matches[1]));
                if ($pendingId !== null) {
                    $heading->setAttribute('id', $pendingId);
                    $pendingId = null;
                }
                $this->inlineParser->parse($heading, $headingText, $i);

                $plainText = $headingIdTracker->getPlainText($heading);
                $id = $headingIdTracker->getIdForHeading($heading);
                $this->headingIds[$id] = true;

                // Register as reference if not already defined
                // Use normalized plain text as the label (for [Heading][] style links)
                $label = preg_replace('/\s+/', ' ', trim($plainText)) ?? $plainText;
                if (!isset($this->references[$label])) {
                    $this->references[$label] = new ReferenceDefinition('#' . $id, [], $i);
                    // Mark so the post-parse rewrite knows this came from a
                    // heading; an explicit `[label]: url` always wins.
                    $this->headingReferenceLabels[$label] = true;
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
     * Rewrite implicit heading references against the parsed AST
     *
     * `extractHeadingReferences()` runs before the AST exists, so its
     * estimated heading ids can disagree with the renderer once explicit
     * `{#id}` attributes (especially on non-heading blocks or inline
     * elements) force the renderer to dedupe. This post-parse pass walks
     * the document with the same `reserveExplicitIds` the renderer uses,
     * computes the actual heading ids, and re-targets both the references
     * map and any built reference-Link nodes accordingly.
     */
    protected function rewriteHeadingReferences(Document $document): void
    {
        $tracker = new HeadingIdTracker();
        $tracker->reserveExplicitIds($document);

        /** @var array<string, string> $newUrlByLabel */
        $newUrlByLabel = [];
        $this->collectHeadingIds($document, $tracker, $newUrlByLabel);

        // Only rewrite labels that came from a heading — an explicit
        // `[label]: url` reference always wins over the heading's id.
        $headingOnly = [];
        foreach ($newUrlByLabel as $label => $url) {
            if (isset($this->headingReferenceLabels[$label])) {
                $headingOnly[$label] = $url;
            }
        }

        foreach ($headingOnly as $label => $url) {
            if (isset($this->references[$label])) {
                $old = $this->references[$label];
                $this->references[$label] = new ReferenceDefinition($url, $old->attributes, $old->line);
            }
        }

        $this->retargetHeadingLinks($document, $headingOnly, $tracker);
    }

    /**
     * @param \Djot\Node\Node $node
     * @param \Djot\Renderer\HeadingIdTracker $tracker
     * @param array<string, string> $out
     */
    protected function collectHeadingIds(Node $node, HeadingIdTracker $tracker, array &$out): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $id = $tracker->getIdForHeading($child);
                // Remember the renderer-visible id so `validateAnchorLinks`
                // doesn't flag valid links to deduped headings as broken.
                $this->headingIds[$id] = true;

                $plain = $tracker->getPlainText($child);
                $label = preg_replace('/\s+/', ' ', trim($plain)) ?? $plain;
                // First-wins: an implicit `[Foo][]` link resolves to the
                // first heading with that label, matching the line-based
                // pass and djot.js. Later duplicates only get deduped ids.
                if (!isset($out[$label])) {
                    $out[$label] = '#' . $id;
                }

                continue;
            }
            $this->collectHeadingIds($child, $tracker, $out);
        }
    }

    /**
     * @param \Djot\Node\Node $node
     * @param array<string, string> $newUrlByLabel
     * @param \Djot\Renderer\HeadingIdTracker $tracker
     */
    protected function retargetHeadingLinks(Node $node, array $newUrlByLabel, HeadingIdTracker $tracker): void
    {
        foreach ($node->getChildren() as $child) {
            // Both reference Links (`[Text][]`) and reference Images
            // (`![Text][]`) carry the implicit-reference label and need
            // their target rewritten when a heading id is deduped. Images
            // store their text in the `alt` string; Links keep it as child
            // nodes — extract each appropriately for the lookup key.
            if (($child instanceof Link || $child instanceof Image) && $child->getReferenceLabel() !== null) {
                $refLabel = $child->getReferenceLabel();
                if ($refLabel === '') {
                    $raw = $child instanceof Link ? $tracker->getPlainText($child) : $child->getAlt();
                    $key = preg_replace('/\s+/', ' ', trim($raw)) ?? '';
                } else {
                    $key = preg_replace('/\s+/', ' ', trim($refLabel)) ?? $refLabel;
                }
                if ($key !== '' && isset($newUrlByLabel[$key])) {
                    if ($child instanceof Link) {
                        $child->setDestination($newUrlByLabel[$key]);
                    } else {
                        $child->setSource($newUrlByLabel[$key]);
                    }
                }
            }
            $this->retargetHeadingLinks($child, $newUrlByLabel, $tracker);
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
        if ($this->customBlockPatterns === []) {
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
            if ($nextIdx < $count && preg_match('/^\[([^\]]+)\]:(?=[ \t]|$)/', $lines[$nextIdx])) {
                // Attributes precede a reference definition, don't store them as block attrs
                return 1;
            }

            $this->parseAttributeString($attrStr);
            $this->pendingAttributeSourceLines[] = $line;

            return 1;
        }

        // Try multi-line attributes: { on first line, } on a later line
        // Collect lines until we find the closing }
        $count = count($lines);
        $attrContent = substr($line, 1); // Remove opening {
        $sourceLines = [$line];
        $i = $start + 1;

        while ($i < $count) {
            $nextLine = $lines[$i];
            $sourceLines[] = $nextLine;

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
                array_push($this->pendingAttributeSourceLines, ...$sourceLines);

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
        if ($this->pendingAttributes !== []) {
            $node->setAttributes($this->pendingAttributes);
            $this->pendingAttributes = [];
            $this->pendingAttributeSourceLines = [];
        }
    }

    /**
     * Consume and return pending block attributes
     *
     * This allows custom block pattern callbacks to retrieve any block attributes
     * that were defined on the line(s) before the block started. The attributes
     * are cleared after retrieval.
     *
     * Example usage in a custom block callback:
     * ```php
     * $parser->addBlockPattern('/^---(\w+)/', function($lines, $start, $parent, $parser) {
     *     $myNode = new MyCustomNode();
     *     $attrs = $parser->consumePendingAttributes();
     *     if (!empty($attrs)) {
     *         $myNode->setAttributes($attrs);
     *     }
     *     $parent->appendChild($myNode);
     *     return 1;
     * });
     * ```
     *
     * @return array<string, string> The pending attributes (empty array if none)
     */
    public function consumePendingAttributes(): array
    {
        $attrs = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $this->pendingAttributeSourceLines = [];

        return $attrs;
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

        // An unterminated single-line backtick fence whose info string carries
        // internal whitespace is not a code block but an inline verbatim span
        // (e.g. "``` not a code block" -> <p><code> not a code block</code></p>,
        // per the djot.js reference and the official conformance suite). A bare
        // fence ("```") or a single-token language specifier ("``` php") still
        // opens an (empty) code block. Fences with content lines use the
        // enhanced lang+label syntax and are left untouched.
        if (
            !$closed
            && $fenceChar === '`'
            && $i === $start + 1
            && $info !== ''
            && preg_match('/\s/', $info) === 1
        ) {
            return null;
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
        $openerAttributes = $divInfo['attributes'];

        $div = new Div();
        if ($className !== '') {
            foreach (preg_split('/\s+/', $className) ?: [] as $class) {
                if ($class !== '') {
                    $div->addClass($class);
                }
            }
        }

        // Save and clear pending attributes - they apply to the div, not inner content
        $divAttributes = $this->pendingAttributes;
        $divAttributeSourceLines = $this->pendingAttributeSourceLines;
        $this->pendingAttributes = [];
        $this->pendingAttributeSourceLines = [];

        $innerLines = [];
        $sourceLines = [...$divAttributeSourceLines, $line];
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
                    $sourceLines[] = $currentLine;
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
                $sourceLines[] = $currentLine;
                $innerLines[] = $currentLine;
                $i++;

                continue;
            }

            // Check for closing fence (equal or longer) - only when not in code block
            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $fenceLength)) {
                $sourceLines[] = $currentLine;
                $i++;
                $closed = true;

                break;
            }

            $sourceLines[] = $currentLine;
            $innerLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed div', $start, 1, true);
        } else {
            $div->setSource(implode("\n", $sourceLines));
        }

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0);
        $this->lineOffset = $previousOffset;

        // Apply opener-line attributes (from `::: {#id .class key=value}` syntax)
        foreach ($openerAttributes as $name => $value) {
            $div->setAttribute($name, $value);
        }

        // Apply the saved attributes to the div, merging classes instead of replacing
        foreach ($divAttributes as $name => $value) {
            if ($name === 'class') {
                // Merge class attributes instead of replacing
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    $div->addClass($class);
                }
            } else {
                $div->setAttribute($name, $value);
            }
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
        $content = trim($matches[2] ?? '');

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

        $char = $starCount >= $dashCount ? '*' : '-';
        $thematicBreak = new ThematicBreak($char);
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
        $this->pendingAttributeSourceLines = [];

        $innerLines = [];
        $lazyState = [
            'inFence' => false,
            'fenceChar' => '',
            'fenceLength' => 0,
            'inComment' => false,
            'commentLength' => 0,
            'paragraphOpen' => false,
        ];

        if (preg_match('/^> (.*)$/', $line, $matches)) {
            $innerLines[] = $matches[1];
            $this->trackBlockQuoteLazyState($matches[1], $lazyState);
        } elseif (preg_match('/^>$/', $line)) {
            $innerLines[] = '';
            $this->trackBlockQuoteLazyState('', $lazyState);
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
                $this->trackBlockQuoteLazyState($matches[1], $lazyState);
                $i++;
            } elseif (preg_match('/^>$/', $currentLine)) {
                // Empty block quote line (just >)
                $innerLines[] = '';
                $this->trackBlockQuoteLazyState('', $lazyState);
                $i++;
            } elseif ($lazyState['paragraphOpen'] && !$this->startsNewBlock($currentLine)) {
                // Lazy continuation only extends an OPEN paragraph (djot rule).
                // A non-">" line inside an open code fence/comment, or after a
                // block that left no open paragraph (a just-opened div, a closed
                // fence), terminates the quote instead of being swallowed.
                $innerLines[] = $currentLine;
                $this->trackBlockQuoteLazyState($currentLine, $lazyState);
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
     * Track verbatim/paragraph state across a blockquote's collected inner lines.
     *
     * A non-">" line lazily continues a blockquote only when an open paragraph is
     * available to extend (the djot/CommonMark lazy-continuation rule). Inside an
     * open code fence or fenced comment, or after a structural line that leaves no
     * open paragraph (a just-opened div, a closed fence), such a line must instead
     * terminate the quote - otherwise it is wrongly swallowed into the fence/div.
     *
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{inFence:bool,fenceChar:string,fenceLength:int,inComment:bool,commentLength:int,paragraphOpen:bool} $state
     *     Running state, mutated in place.
     */
    private function trackBlockQuoteLazyState(string $content, array &$state): void
    {
        if ($state['inComment']) {
            if ($this->fencedBlockParser->isFencedCommentCloser($content, $state['commentLength'])) {
                $state['inComment'] = false;
            }
            $state['paragraphOpen'] = false;

            return;
        }

        if ($state['inFence']) {
            if ($this->fencedBlockParser->isCodeFenceCloser($content, $state['fenceChar'], $state['fenceLength'])) {
                $state['inFence'] = false;
            }
            $state['paragraphOpen'] = false;

            return;
        }

        if (IndentationHelper::isBlankLine($content)) {
            $state['paragraphOpen'] = false;

            return;
        }

        // A fence/comment/div opener starts a new block only when it is allowed to:
        // djot paragraphs are not interrupted by block elements without a blank line,
        // so a fence-looking line mid-paragraph is just paragraph text. With
        // blocksInterruptParagraphs enabled, openers DO interrupt an open paragraph.
        if (!$state['paragraphOpen'] || $this->blocksInterruptParagraphs) {
            $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($content);
            if ($fenceInfo !== null) {
                $state['inFence'] = true;
                $state['fenceChar'] = $fenceInfo['char'];
                $state['fenceLength'] = $fenceInfo['length'];
                $state['paragraphOpen'] = false;

                return;
            }

            $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($content);
            if ($commentInfo !== null) {
                $state['inComment'] = true;
                $state['commentLength'] = $commentInfo['length'];
                $state['paragraphOpen'] = false;

                return;
            }

            if ($this->fencedBlockParser->parseDivFenceOpener($content) !== null) {
                // Div opener/closer line is structural; it opens no paragraph itself.
                $state['paragraphOpen'] = false;

                return;
            }
        }

        // Any other non-blank line is paragraph-ish content (plain text, an open
        // paragraph's continuation, or a block that opens with text on the same line:
        // list item, heading, nested quote) - all leave an open paragraph a lazy line
        // may continue.
        $state['paragraphOpen'] = true;
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

        /** @var string $listType */
        $listType = $listInfo['type'];
        /** @var int $listStart */
        $listStart = $listInfo['start'] ?? 1;
        /** @var string|null $listMarker */
        $listMarker = $listInfo['marker'] ?? null;
        /** @var string|null $listStyle */
        $listStyle = $listInfo['style'] ?? null;

        $list = new ListBlock(
            $listType,
            $listStart,
            true, // Start as tight
            $listMarker,
            $listStyle,
        );

        // Save and clear pending attributes - they apply to the list, not inner content
        $listAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $this->pendingAttributeSourceLines = [];

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
                            // After a blank line, content dropping back to base indent
                            // starts a new block outside the list - let parent handle it.
                            if ($sawBlankLine) {
                                $lastItemHadBlankAfter = true;
                                $brokeForParentContent = true;

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

            /** @var string|null $taskMarker */
            $taskMarker = $itemInfo['taskMarker'] ?? null;
            $listItem = new ListItem($taskMarker);
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            /** @var array<string> $itemLines */
            $itemLines = [$itemContent];
            $i++;
            $lastItemHadBlankAfter = false;
            $hasNonMarkerContinuation = false;

            // Calculate content indent based on list type and marker width
            // For bullet lists (including task lists): use 2 (for "- ")
            // For ordered lists: use actual marker width (varies with number length)
            // Task list checkbox is considered part of content, not marker
            if ($listType === ListBlock::TYPE_ORDERED) {
                // Ordered list marker width = length of trimmed line - length of content
                // Examples: "1. " = 3, "10. " = 4, "(1) " = 4, "(10) " = 5
                $markerWidth = strlen($trimmedLine) - strlen($itemContent);
            } else {
                // Bullet and task lists use 2-char base marker ("- " or "* " or "+ ")
                $markerWidth = 2;
            }
            $contentIndent = $baseIndent + $markerWidth;

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
                // Unless nested blocks in lists are enabled, indented block markers
                // are treated as plain continuation text here.
                if ($nextIndent >= $contentIndent) {
                    // If the active list-nesting mode treats this indented line
                    // as a nested block, break out so normal nesting handles it.
                    if ($this->allowsImmediateNestedBlock($nextTrimmed, $lines, $i)) {
                        break;
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

            // Check for list item attributes on the next line.
            //
            // Rule: a standalone {...} line attaches to the <li> ONLY when it
            // is the last content line of the item. If another block follows
            // within the same item, push the {...} back into itemLines so it
            // is parsed as a standard djot block attribute for the following
            // block inside the item. This keeps the list / item intact instead
            // of terminating it on a mid-item {...} line.
            $itemAttributes = [];
            $parseItemLinesAsBlocks = false;
            if ($i < $count) {
                $potentialAttrLine = $lines[$i];
                $trimmedAttrLine = ltrim($potentialAttrLine);
                if (
                    preg_match('/^\{([^{}]+)\}\s*$/', $trimmedAttrLine, $attrMatch) &&
                    IndentationHelper::getLeadingSpaces($potentialAttrLine) >= $contentIndent
                ) {
                    // Peek ahead: is there more item content at content indent
                    // (non-blank, non-sibling-marker)?
                    $hasMoreItemContent = false;
                    if ($i + 1 < $count) {
                        $peekLine = $lines[$i + 1];
                        if (!IndentationHelper::isBlankLine($peekLine)) {
                            $peekIndent = IndentationHelper::getLeadingSpaces($peekLine);
                            if ($peekIndent >= $contentIndent) {
                                $hasMoreItemContent = true;
                            }
                        }
                    }

                    // @todo #189: Default mode still treats tight nested-list
                    // markers as continuation text here. nestedBlocksInLists
                    // handles the natural nested-item/block-attribute case.
                    $peekLineIsAttribute = $hasMoreItemContent
                        && preg_match('/^\{([^{}]+)\}\s*$/', ltrim($lines[$i + 1]), $peekAttrMatch);

                    if ($peekLineIsAttribute) {
                        $itemAttributes = AttributeParser::parseAndMerge($itemAttributes, $attrMatch[1]);
                        $itemAttributes = AttributeParser::parseAndMerge($itemAttributes, $peekAttrMatch[1]);
                        $i += 2;

                        while ($i < $count) {
                            $contLine = $lines[$i];
                            if (IndentationHelper::isBlankLine($contLine)) {
                                break;
                            }
                            $contIndent = IndentationHelper::getLeadingSpaces($contLine);
                            if ($contIndent < $contentIndent) {
                                break;
                            }
                            $contTrimmed = ltrim($contLine);
                            if (!preg_match('/^\{([^{}]+)\}\s*$/', $contTrimmed, $contAttrMatch)) {
                                break;
                            }
                            $itemAttributes = AttributeParser::parseAndMerge($itemAttributes, $contAttrMatch[1]);
                            $i++;
                        }

                        if ($i < $count && !IndentationHelper::isBlankLine($lines[$i])) {
                            $contIndent = IndentationHelper::getLeadingSpaces($lines[$i]);
                            if ($contIndent >= $contentIndent) {
                                if ($itemLines !== [] && $itemLines[array_key_last($itemLines)] !== '') {
                                    $itemLines[] = '';
                                }
                                $hasNonMarkerContinuation = true;
                                $parseItemLinesAsBlocks = true;
                                while ($i < $count) {
                                    $contLine = $lines[$i];
                                    if (IndentationHelper::isBlankLine($contLine)) {
                                        break;
                                    }
                                    $contIndent = IndentationHelper::getLeadingSpaces($contLine);
                                    if ($contIndent >= $contentIndent) {
                                        $itemLines[] = IndentationHelper::stripLeadingIndent($contLine, $contentIndent);
                                        $i++;
                                    } else {
                                        break;
                                    }
                                }
                            }
                        }
                    } elseif ($hasMoreItemContent) {
                        // {...} is not the item's attribute — it is a block
                        // attribute for the next block in the item. Push it back
                        // into itemLines (stripped of the item's content indent)
                        // and keep consuming further indented continuation lines.
                        // Insert a blank-line separator first so parseBlocks
                        // recognizes the previously-consumed paragraph as closed
                        // and reads {...} as a real block attribute for the
                        // following block, instead of folding everything into
                        // one paragraph (standard djot: paragraphs cannot be
                        // interrupted without a blank line).
                        if ($itemLines !== [] && $itemLines[array_key_last($itemLines)] !== '') {
                            $itemLines[] = '';
                        }
                        $itemLines[] = IndentationHelper::stripLeadingIndent($potentialAttrLine, $contentIndent);
                        $hasNonMarkerContinuation = true;
                        $parseItemLinesAsBlocks = true;
                        $i++;
                        while ($i < $count) {
                            $contLine = $lines[$i];
                            if (IndentationHelper::isBlankLine($contLine)) {
                                // A blank line ends the tight continuation here;
                                // any further indented content will be picked up
                                // by the existing loose-list path below.
                                break;
                            }
                            $contIndent = IndentationHelper::getLeadingSpaces($contLine);
                            if ($contIndent >= $contentIndent) {
                                $itemLines[] = IndentationHelper::stripLeadingIndent($contLine, $contentIndent);
                                $i++;
                            } else {
                                break;
                            }
                        }
                    } else {
                        $itemAttributes = AttributeParser::parseOrdered($attrMatch[1]);
                        $i++;
                    }
                }
            }

            // For tight lists with continuation lines, check if content starts with
            // a block element. If so, parse as blocks; otherwise parse as plain text.
            // This prevents "-like" lines from being parsed as nested lists while
            // still allowing blockquotes, code blocks, etc. to be properly recognized.
            if ($hasNonMarkerContinuation) {
                $firstLine = $itemLines[0];
                if ($parseItemLinesAsBlocks || $this->isBlockElementStart($firstLine)) {
                    // Content starts with a block element (blockquote, code fence,
                    // etc.) or we pushed a {...} back into itemLines that must be
                    // recognized as a block attribute for the next block.
                    $this->parseBlocks($listItem, $itemLines, 0);
                } else {
                    $paragraph = new Paragraph();
                    $this->inlineParser->parse($paragraph, implode("\n", $itemLines), $start);
                    $listItem->appendChild($paragraph);
                }
            } else {
                $this->parseBlocks($listItem, $itemLines, 0);
            }

            // When a list-nesting mode is active, check for immediate nested
            // content after the initial item paragraph.
            $nestingModeActive = $this->nestedBlocksInLists
                || $this->nestedListsWithoutBlankLine
                || $this->blocksInterruptParagraphs;
            if ($nestingModeActive && $i < $count) {
                $nextLine = $lines[$i];
                $nextIndent = IndentationHelper::getLeadingSpaces($nextLine);

                // Broad nestedBlocksInLists collects any indented content (its
                // original behavior). The granular levers only enter nesting
                // when the leading line actually opens a nestable block.
                $enterNesting = $nextIndent >= $contentIndent;
                if ($enterNesting && !$this->nestedBlocksInLists) {
                    $enterNesting = $this->allowsImmediateNestedBlock(ltrim($nextLine), $lines, $i);
                }

                // If there's indented content that could be a nested block
                if ($enterNesting) {
                    $subLines = [];
                    $nestedIndent = $nextIndent;
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            // Continue across blank lines (same as standard nesting path)
                            $subLines[] = '';
                            $i++;

                            continue;
                        }
                        $lineIndent = IndentationHelper::getLeadingSpaces($subLine);
                        // Membership is the item's content indent, not the first
                        // nested line's (possibly deeper) indent: a line that drops
                        // back to the content indent is still item content and must
                        // be kept here, otherwise an over-indented first nested line
                        // would detach it into a top-level block and end the list early.
                        if ($lineIndent < $contentIndent) {
                            // Back to the parent (or a shallower) level: this item's
                            // nested content is done.
                            break;
                        }
                        // Normalize the over-indented nested block by its own indent
                        // so it starts at column 0 (required for the sub-parse to
                        // recognize it as a block); lines that fall back to the item
                        // content indent are stripped by that instead, keeping them
                        // in the item rather than letting them escape.
                        $strip = $lineIndent >= $nestedIndent ? $nestedIndent : $contentIndent;
                        $subLines[] = IndentationHelper::stripLeadingIndent($subLine, $strip);
                        $i++;
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
        $this->pendingAttributeSourceLines = [];

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

                // Check for continuation marker `: +` - not a new term, breaks term collection
                if ($termContent === '+') {
                    break;
                }

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
            // Use `: +` marker to create additional dd elements for the same term
            $defLines = [];
            $allDefBlocks = [];

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

                // Check for continuation marker `: +` - creates new dd for same term
                if ($defLine === ': +') {
                    if ($defLines !== []) {
                        $allDefBlocks[] = $defLines;
                        $defLines = [];
                    }
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

            // Add final block
            if ($defLines !== []) {
                $allDefBlocks[] = $defLines;
            }

            // Create definition node(s)
            if ($allDefBlocks !== []) {
                foreach ($allDefBlocks as $block) {
                    $def = new DefinitionDescription();
                    $defAttributes = [];

                    // Skip leading/trailing blank lines
                    while ($block !== [] && $block[0] === '') {
                        array_shift($block);
                    }
                    while ($block !== [] && end($block) === '') {
                        array_pop($block);
                    }

                    // Check if last line is a standalone attribute block for the dd
                    $blockCount = count($block);
                    if ($blockCount > 0 && preg_match('/^\{([^{}]+)\}\s*$/', $block[$blockCount - 1], $attrMatch)) {
                        $defAttributes = AttributeParser::parse($attrMatch[1]);
                        array_pop($block);
                    }

                    if ($block !== []) {
                        $this->parseBlocks($def, $block, 0);
                    }

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
        $count = count($lines);

        // Use TableParser to check if this is a valid table row
        if (!$this->tableParser->isTableRow($line)) {
            // Check if it's a potential table row with unclosed code span
            // that might be closed by continuation rows
            if (!$this->tableParser->isPotentialTableRowWithUnclosedCodeSpan($line)) {
                return null;
            }

            // Look ahead for continuation rows that might close the code span
            if (!$this->canCloseCodeSpanWithContinuations($lines, $start, $count)) {
                return null;
            }
        }

        $table = new Table();
        $i = $start;
        $alignments = [];
        $headerFound = false;
        $hasRowspans = false;

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

                // Store separator widths for round-trip preservation
                $separatorWidths = $this->tableParser->parseSeparatorWidths($lineWithoutRowAttrs);
                $table->setSeparatorWidths($separatorWidths);

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if ($children !== []) {
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
                                // Preserve rowspan and colspan from original cell
                                $headerCell = new TableCell(
                                    true,
                                    $alignment,
                                    $cell->getRowspan(),
                                    $cell->getColspan(),
                                );
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

            // Parse cells with their attributes
            $cellsWithAttrs = $this->tableParser->parseTableCellsWithAttributes($currentLine);

            // Store cell contents and attributes for potential merging
            $mergedCells = array_map(fn ($c) => $c['content'], $cellsWithAttrs);
            $cellAttributes = array_map(fn ($c) => $c['attributes'], $cellsWithAttrs);
            $baseLineForRow = $i;

            $i++;

            // Check for continuation rows (lines starting with +)
            while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
                $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
                $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
                $i++;
            }

            // Rebuild cellsWithAttrs with merged content
            $mergedCellsWithAttrs = [];
            foreach ($mergedCells as $idx => $content) {
                $mergedCellsWithAttrs[] = [
                    'content' => $content,
                    'attributes' => $cellAttributes[$idx] ?? [],
                ];
            }

            // Process colspan markers (<) - must process before creating cells
            // Cells marked with < are merged into the cell to their left
            $processedCells = [];
            $colspanAccumulator = 1;

            for ($cellIdx = count($mergedCellsWithAttrs) - 1; $cellIdx >= 0; $cellIdx--) {
                $cellData = $mergedCellsWithAttrs[$cellIdx];
                if ($this->tableParser->isColspanMarker($cellData['content'])) {
                    // This cell is a colspan marker, add to accumulator
                    $colspanAccumulator++;
                } else {
                    // Regular cell, apply accumulated colspan
                    $cellData['colspan'] = $colspanAccumulator;
                    array_unshift($processedCells, $cellData);
                    $colspanAccumulator = 1;
                }
            }

            // Parse regular row
            $row = new TableRow(false);
            if ($rowAttributes) {
                $row->setAttributes($rowAttributes);
            }

            // Store row data for rowspan processing
            // Track column positions for cells accounting for rowspan markers
            $rowCellData = [];
            $colPosition = 0;

            foreach ($processedCells as $index => $cellData) {
                $colspan = $cellData['colspan'];

                // Check for rowspan marker
                if ($this->tableParser->isRowspanMarker($cellData['content'])) {
                    // Mark this position for rowspan processing
                    $rowCellData[] = [
                        'type' => 'rowspan_marker',
                        'colPosition' => $colPosition,
                    ];
                    $colPosition += $colspan;
                } else {
                    $alignment = $alignments[$index] ?? TableCell::ALIGN_DEFAULT;
                    $cell = new TableCell(false, $alignment, 1, $colspan);
                    if ($cellData['attributes']) {
                        $cell->setAttributes($cellData['attributes']);
                    }
                    $trimmedContent = trim($cellData['content']);
                    if ($trimmedContent !== '' && $this->isPlainText($trimmedContent)) {
                        $cell->appendChild(new Text($trimmedContent));
                    } else {
                        $this->inlineParser->parse($cell, $trimmedContent, $baseLineForRow);
                    }
                    $row->appendChild($cell);
                    $rowCellData[] = [
                        'type' => 'cell',
                        'cell' => $cell,
                        'colPosition' => $colPosition,
                    ];
                    $colPosition += $colspan;
                }
            }

            // Process rowspan markers - find cells above that should span down
            // We need to track column positions considering rowspan markers in previous rows
            $tableChildren = $table->getChildren();
            $currentRowIndex = count($tableChildren); // Index where current row will be added

            // Track which cells have already been extended in this row
            // (multiple ^ markers under a colspan should only extend once)
            $extendedCells = [];

            foreach ($rowCellData as $cellInfo) {
                if ($cellInfo['type'] === 'rowspan_marker') {
                    $targetCol = $cellInfo['colPosition'];

                    // Look in previous rows for the cell that spans into this column
                    for ($prevRowIdx = $currentRowIndex - 1; $prevRowIdx >= 0; $prevRowIdx--) {
                        $prevRow = $tableChildren[$prevRowIdx];
                        if (!($prevRow instanceof TableRow)) {
                            continue;
                        }

                        // Calculate which column position each cell occupies in this row
                        // considering that some positions may be occupied by rowspans from above
                        $cellFound = $this->findCellAtColumnForRowspan(
                            $tableChildren,
                            $prevRowIdx,
                            $targetCol,
                            $currentRowIndex,
                        );

                        if ($cellFound !== null) {
                            // Only extend each cell once per row (handles multiple ^ under colspan)
                            $cellId = spl_object_id($cellFound);
                            if (!isset($extendedCells[$cellId])) {
                                $cellFound->setRowspan($cellFound->getRowspan() + 1);
                                $extendedCells[$cellId] = true;
                                $hasRowspans = true;
                            }

                            break;
                        }
                    }
                }
            }

            // Remove cells that overlap with spanning cells from previous rows
            // This handles the case where a cell has both rowspan and colspan,
            // and the intersection area contains content that should be dropped
            // Only needed when rowspans exist (avoids O(n²) scan for simple tables)
            if ($hasRowspans) {
                $this->removeOverlappingCells($table, $row, $rowCellData, $currentRowIndex);
            }

            $table->appendChild($row);
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
     * Find a cell at a specific column position that can span into the target row.
     *
     * This method handles the complexity of finding cells when previous rows
     * may have rowspan markers (missing cells) and rowspans from even earlier rows.
     *
     * @param array<\Djot\Node\Node> $tableRows All rows parsed so far
     * @param int $rowIndex The row index to search in
     * @param int $targetCol The column position to find
     * @param int $targetRowIndex The row index we're trying to extend into
     *
     * @return \Djot\Node\Block\TableCell|null The cell if found and valid for extension
     */
    protected function findCellAtColumnForRowspan(
        array $tableRows,
        int $rowIndex,
        int $targetCol,
        int $targetRowIndex,
    ): ?TableCell {
        $row = $tableRows[$rowIndex];
        if (!($row instanceof TableRow)) {
            return null;
        }

        // Build a map of which columns are occupied by cells from this row
        // or by rowspans from earlier rows
        $columnOccupancy = $this->buildColumnOccupancyMap($tableRows, $rowIndex);

        // Find which cell (if any) from this row occupies the target column
        $cells = $row->getChildren();
        $cellColPosition = 0;

        foreach ($cells as $cell) {
            if (!($cell instanceof TableCell)) {
                continue;
            }

            // Skip columns that are occupied by rowspans from earlier rows
            while (isset($columnOccupancy[$cellColPosition]) && $columnOccupancy[$cellColPosition] !== $rowIndex) {
                $cellColPosition++;
            }

            $colspan = $cell->getColspan();
            $rowspan = $cell->getRowspan();

            // Check if this cell covers the target column
            if ($cellColPosition <= $targetCol && $targetCol < $cellColPosition + $colspan) {
                // Check if this cell's rowspan already reaches the target row
                $rowsSpanned = $rowIndex + $rowspan;
                if ($rowsSpanned >= $targetRowIndex) {
                    return $cell;
                }
            }

            $cellColPosition += $colspan;
        }

        return null;
    }

    /**
     * Build a map of which row's cell occupies each column position.
     *
     * @param array<\Djot\Node\Node> $tableRows All rows parsed so far
     * @param int $upToRowIndex Build occupancy up to this row index
     *
     * @return array<int, int> Map of column position => row index that occupies it
     */
    protected function buildColumnOccupancyMap(array $tableRows, int $upToRowIndex): array
    {
        $occupancy = [];

        for ($rowIdx = 0; $rowIdx <= $upToRowIndex; $rowIdx++) {
            $row = $tableRows[$rowIdx] ?? null;
            if (!($row instanceof TableRow)) {
                continue;
            }

            $cells = $row->getChildren();
            $colPos = 0;

            foreach ($cells as $cell) {
                if (!($cell instanceof TableCell)) {
                    continue;
                }

                // Skip columns already occupied by earlier rowspans
                while (isset($occupancy[$colPos]) && $occupancy[$colPos] + $this->getCellRowspanAt($tableRows, $occupancy[$colPos], $colPos) > $rowIdx) {
                    $colPos++;
                }

                $colspan = $cell->getColspan();
                $rowspan = $cell->getRowspan();

                // Mark columns as occupied by this cell's row
                for ($c = 0; $c < $colspan; $c++) {
                    $occupancy[$colPos + $c] = $rowIdx;
                }

                $colPos += $colspan;
            }
        }

        return $occupancy;
    }

    /**
     * Get the rowspan of a cell at a specific row and column position.
     *
     * @param array<\Djot\Node\Node> $tableRows All rows
     * @param int $rowIdx Row index
     * @param int $colPos Column position
     */
    protected function getCellRowspanAt(array $tableRows, int $rowIdx, int $colPos): int
    {
        $row = $tableRows[$rowIdx] ?? null;
        if (!($row instanceof TableRow)) {
            return 1;
        }

        $cells = $row->getChildren();
        $currentCol = 0;

        foreach ($cells as $cell) {
            if (!($cell instanceof TableCell)) {
                continue;
            }

            $colspan = $cell->getColspan();
            if ($currentCol <= $colPos && $colPos < $currentCol + $colspan) {
                return $cell->getRowspan();
            }
            $currentCol += $colspan;
        }

        return 1;
    }

    /**
     * Remove cells from a row that overlap with spanning cells from previous rows.
     *
     * This handles the edge case where a cell has both rowspan and colspan:
     * when a rowspan marker extends such a cell, cells in the "intersection"
     * area of the current row must be removed to avoid invalid overlapping HTML.
     *
     * @param \Djot\Node\Block\Table $table The table being built
     * @param \Djot\Node\Block\TableRow $row The current row
     * @param array<array{type: string, colPosition: int, cell?: \Djot\Node\Block\TableCell}> $rowCellData Cell data with positions
     * @param int $currentRowIndex The index where this row will be added
     */
    protected function removeOverlappingCells(
        Table $table,
        TableRow $row,
        array $rowCellData,
        int $currentRowIndex,
    ): void {
        $tableChildren = $table->getChildren();
        if ($tableChildren === []) {
            return;
        }

        // Build a set of column positions that are occupied by spanning cells from previous rows
        $occupiedColumns = [];

        foreach ($tableChildren as $rowIdx => $prevRow) {
            if (!($prevRow instanceof TableRow)) {
                continue;
            }

            $colPos = 0;
            foreach ($prevRow->getChildren() as $cell) {
                if (!($cell instanceof TableCell)) {
                    continue;
                }

                // Skip columns occupied by even earlier rowspans
                while (isset($occupiedColumns[$colPos])) {
                    $colPos++;
                }

                $colspan = $cell->getColspan();
                $rowspan = $cell->getRowspan();

                // Check if this cell's span reaches into the current row
                if ($rowIdx + $rowspan > $currentRowIndex) {
                    // Mark all columns covered by this cell as occupied
                    for ($c = 0; $c < $colspan; $c++) {
                        $occupiedColumns[$colPos + $c] = true;
                    }
                }

                $colPos += $colspan;
            }
        }

        if ($occupiedColumns === []) {
            return;
        }

        // Find cells in the current row that are in occupied positions and remove them
        $cellsToRemove = [];
        foreach ($rowCellData as $cellInfo) {
            if ($cellInfo['type'] === 'cell' && isset($cellInfo['cell'])) {
                $cellColPos = $cellInfo['colPosition'];
                if (isset($occupiedColumns[$cellColPos])) {
                    $cellsToRemove[] = $cellInfo['cell'];
                }
            }
        }

        // Remove the overlapping cells from the row
        foreach ($cellsToRemove as $cellToRemove) {
            $row->removeChild($cellToRemove);
        }
    }

    /**
     * Check if a row with unclosed code spans can be closed by continuation rows.
     *
     * This looks ahead for continuation rows and checks if merging their content
     * would result in balanced code spans.
     *
     * @param array<string> $lines All lines
     * @param int $start Starting line index
     * @param int $count Total line count
     *
     * @return bool True if continuation rows can close the code spans
     */
    protected function canCloseCodeSpanWithContinuations(array $lines, int $start, int $count): bool
    {
        $baseLine = $lines[$start];

        // Parse cells from base row (using raw parsing that ignores code span issues)
        $baseCells = $this->tableParser->parseTableCellsRaw($baseLine);
        if ($baseCells === []) {
            return false;
        }

        $mergedCells = $baseCells;
        $i = $start + 1;

        // Look for continuation rows
        while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
            $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
            $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
            $i++;
        }

        // Check if we found any continuations and if merged content is valid
        if ($i === $start + 1) {
            // No continuation rows found
            return false;
        }

        return $this->tableParser->mergedCellsAreValid($mergedCells);
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

        // Match footnote definition: [^label]: content (requires whitespace after colon)
        if (!preg_match('/^\[\^([^\]]+)\]:(?=[ \t]|$)/', $line)) {
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

        // Match reference definition: [label]: url
        // URL must be a single non-whitespace token; see extractReferences().
        if (!preg_match('/^\[([^\]]+)\]:(?:[ \t]+(\S*))?[ \t]*$/', $line, $matches)) {
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
            if (preg_match('/^\[([^\]]+)\]:(?=[ \t]|$)/', $nextLine)) {
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

        // Match abbreviation definition: *[abbr]: definition (requires whitespace after colon)
        if (!preg_match('/^\*\[([^\]]+)\]:(?=[ \t]|$)/', $line)) {
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
            if (preg_match('/^\*\[([^\]]+)\]:(?=[ \t]|$)/', $nextLine)) {
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
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line);

        $i = $start + 1;
        $count = count($lines);

        // Track brace nesting incrementally. Re-scanning the whole (growing)
        // $content on every continuation line made paragraph parsing O(n^2) in
        // the number of lines; carrying the state forward keeps it linear.
        $braceState = $this->scanBraceState($content, self::INITIAL_BRACE_STATE);

        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // An unclosed brace in the content so far suppresses block
            // interruption (e.g. `text{a=x` then `# not-a-heading`).
            if ($braceState['depth'] <= 0 && $this->startsNewBlock($nextLine, $lines, $i)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $nextLine = ltrim($nextLine);
            $segment = "\n" . $nextLine;
            $content .= $segment;
            $braceState = $this->scanBraceState($segment, $braceState);
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

    /**
     * Determine whether a continuation line should interrupt the current block (paragraph etc.).
     *
     * @param string $line The continuation line being inspected
     * @param array<string>|null $lines All source lines, when lookahead is available (prose
     *     interruption only). When null, a lone marker keeps the legacy "interrupts" behavior.
     * @param int $index Index of $line within $lines (so the next line is $lines[$index + 1])
     */
    protected function startsNewBlock(string $line, ?array $lines = null, int $index = -1): bool
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

        // Fenced comments `%%%` can always interrupt paragraphs
        // Comments should be invisible and not require extra formatting
        if ($line[0] === '%' && isset($line[1], $line[2]) && $line[1] === '%' && $line[2] === '%') {
            return true;
        }

        // When enabled, block elements can interrupt paragraphs without a blank line
        if ($this->blocksInterruptParagraphs) {
            return $this->startsNewBlockSignificant($line, $lines, $index);
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
     *
     * @param string $line The continuation line being inspected
     * @param array<string>|null $lines All source lines, when prose lookahead is
     *     available; null keeps the legacy "lone marker interrupts" behavior.
     * @param int $index Index of $line within $lines
     */
    protected function startsNewBlockSignificant(string $line, ?array $lines = null, int $index = -1): bool
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
                // Unordered lists or thematic breaks. blocksInterruptParagraphs
                // is an opt-in markdown/chat-like mode, so a line-leading marker
                // interrupts without a blank line (it would otherwise drop a
                // genuine single-line or lazily-wrapped list).
                if (isset($line[1]) && $line[1] === ' ') {
                    return true; // Unordered list
                }

                // Thematic breaks: *\s*\*\s*\* or -\s*-\s*-
                return preg_match('/^(\*\s*\*\s*\*|-\s*-\s*-)/', $line) === 1;
            case '|':
                // Tables: a single "| a | b |" row is already a valid table, but
                // a pipe in prose ("a\n| b als Oder.") is not a row, so validate
                // before interrupting to avoid splitting prose into stray blocks.
                return $this->tableParser->isTableRow($line);
            case '>':
                // Block quotes (single-line and lazily-wrapped quotes interrupt).
                return true;
            case '`':
                // Code fences: `{3,}
                return isset($line[1], $line[2]) && $line[1] === '`' && $line[2] === '`';
            case ':':
                // Fenced divs: :{3,}
                return isset($line[1], $line[2]) && $line[1] === ':' && $line[2] === ':';
            case '%':
                // Fenced comments: %{3,}
                return isset($line[1], $line[2]) && $line[1] === '%' && $line[2] === '%';
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
     * Decide whether an indented line under an already-open list item should
     * open a nested block (vs. be folded in as plain continuation text).
     *
     * - nestedBlocksInLists (deprecated, broad): any block element.
     * - nestedListsWithoutBlankLine: list markers only (compact sublists), the
     *   narrow subset that always nests a sublist marker without lookahead.
     * - blocksInterruptParagraphs: any block interrupts the item's lead
     *   paragraph, mirroring top-level interruption including the lone-marker
     *   lookahead. A list is a block, so sublists are covered here too (the
     *   lookahead folds an ambiguous lone "- x" / "> x" in as continuation),
     *   which makes nestedListsWithoutBlankLine a subset of this behavior.
     *
     * @param string $trimmed The left-trimmed candidate line.
     * @param array<string> $lines All lines being parsed (lookahead context).
     * @param int $index Index of the candidate line within $lines.
     */
    protected function allowsImmediateNestedBlock(string $trimmed, array $lines, int $index): bool
    {
        if ($this->nestedBlocksInLists) {
            return $this->isBlockElementStart($trimmed);
        }

        $isListMarker = $this->listParser->parseListItemMarker($trimmed) !== null;

        // Narrow subset: always nest a sublist marker, no lookahead.
        if ($this->nestedListsWithoutBlankLine && $isListMarker) {
            return true;
        }

        if ($this->blocksInterruptParagraphs) {
            // Use the SAME lone-marker-aware decision the top-level
            // paragraph-interruption path uses, so an indented "> 5" / "| x" / a
            // sublist marker under a list item is treated identically to top
            // level, while unambiguous openers (#, code fence, :::, ---) and
            // real (multi-item) sublists interrupt.
            return $this->startsNewBlockSignificant($trimmed, $lines, $index);
        }

        return false;
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

        // List markers (bullet, ordered, and task). Delegate to the canonical
        // list-marker parser so nested-block detection recognizes every marker
        // form it supports - including "(1)", "(a)", and roman numerals such as
        // "iv." - instead of a narrower subset that silently degraded those into
        // plain paragraph text.
        if ($this->listParser->parseListItemMarker($line) !== null) {
            return true;
        }

        return false;
    }

    /**
     * Check if text has an unclosed brace (for attribute blocks)
     */
    protected function hasUnclosedBrace(string $text): bool
    {
        return $this->scanBraceState($text, self::INITIAL_BRACE_STATE)['depth'] > 0;
    }

    /**
     * Scan a text segment for brace nesting, carrying state across segments.
     *
     * Used to detect an unclosed attribute brace in a paragraph (`text{a=x`)
     * without re-scanning the whole accumulated content on every continuation
     * line. Quote state, brace depth and a dangling backslash (an escape that
     * straddles the segment boundary) are threaded through so scanning a string
     * in one call or split across calls yields the identical result.
     *
     * @param string $segment
     * @param array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool} $state
     *
     * @return array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    protected function scanBraceState(string $segment, array $state): array
    {
        $depth = $state['depth'];
        $inQuote = $state['inQuote'];
        $quoteChar = $state['quoteChar'];
        $len = strlen($segment);
        $i = 0;

        // A backslash at the end of the previous segment escapes this segment's
        // first character.
        if ($state['pendingEscape'] && $len > 0) {
            $i = 1;
        }
        $pendingEscape = false;

        for (; $i < $len; $i++) {
            $char = $segment[$i];

            // Handle escape sequences
            if ($char === '\\') {
                if ($i + 1 < $len) {
                    $i++;

                    continue;
                }

                // Trailing backslash escapes the next segment's first character.
                $pendingEscape = true;

                break;
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

        return ['depth' => $depth, 'inQuote' => $inQuote, 'quoteChar' => $quoteChar, 'pendingEscape' => $pendingEscape];
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
    }

    /**
     * Validate reference definitions vs usage
     * Generates warnings for unused references.
     * Note: Undefined references are warned about inline during parsing.
     */
    protected function validateReferences(): void
    {
        // Check for unused reference definitions (defined but never used)
        // Skip heading auto-references (URLs start with #)
        // Skip footnote definitions (labels start with ^)
        foreach ($this->references as $label => $def) {
            if (
                !isset($this->usedReferences[$label])
                && !str_starts_with($def->url, '#')
                && !str_starts_with((string)$label, '^')
            ) {
                $this->addWarning(
                    "Reference '{$label}' defined but never used",
                    $def->line,
                    1,
                    false,
                    'reference',
                    null,
                );
            }
        }
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? null;
    }

    /**
     * Mark a reference as used (for validation warnings)
     * Only tracks when collectWarnings is enabled.
     */
    public function markReferenceUsed(string $label, int $line): void
    {
        if ($this->collectWarnings && !isset($this->usedReferences[$label])) {
            $this->usedReferences[$label] = $line;
        }
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
        $this->addWarning(
            "Undefined reference '{$ref}'",
            $line,
            $column,
            false,
            'reference',
            "Define with [{$ref}]: url or use inline link",
        );
    }

    /**
     * Add warning for undefined footnote (called from InlineParser)
     */
    public function addUndefinedFootnoteWarning(string $label, int $line, int $column): void
    {
        $this->addWarning("Undefined footnote '{$label}'", $line, $column, false);
    }

    /**
     * Track an anchor link for validation (called from InlineParser)
     * Only tracks when collectWarnings is enabled.
     */
    public function trackAnchorLink(string $fragment, int $line, int $column): void
    {
        if ($this->collectWarnings) {
            $this->anchorLinks[] = [
                'fragment' => $fragment,
                'line' => $line,
                'column' => $column,
            ];
        }
    }

    /**
     * Validate anchor links point to existing IDs in the document
     *
     * Checks all links with `#fragment` destinations against:
     * - Heading IDs (from heading auto-references)
     * - Explicit `{#id}` attributes on any element
     */
    protected function validateAnchorLinks(Document $document): void
    {
        if ($this->anchorLinks === []) {
            return;
        }

        // Collect all known anchor targets
        $knownIds = $this->headingIds;

        // From explicit {#id} attributes on any node in the AST
        $this->collectExplicitIds($document, $knownIds);

        // Validate each tracked anchor link
        foreach ($this->anchorLinks as $anchor) {
            if (!isset($knownIds[$anchor['fragment']])) {
                $this->addWarning(
                    "Broken anchor link '#{$anchor['fragment']}' — no element with this ID exists",
                    $anchor['line'],
                    $anchor['column'],
                    false,
                    'anchor',
                    null,
                );
            }
        }
    }

    /**
     * Recursively collect explicit {#id} attributes from the AST
     *
     * @param \Djot\Node\Node $node
     * @param array<string, bool> $ids
     */
    protected function collectExplicitIds(Node $node, array &$ids): void
    {
        if ($node->hasAttribute('id')) {
            $id = $node->getAttribute('id');
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectExplicitIds($child, $ids);
        }
    }

    /**
     * Get the inline parser for registering custom patterns
     */
    public function getInlineParser(): InlineParser
    {
        return $this->inlineParser;
    }

    /**
     * Check if text contains only plain characters (no inline markup triggers).
     *
     * Used to skip the inline parser for simple table cell content,
     * creating a Text node directly instead.
     */
    protected function isPlainText(string $text): bool
    {
        // Can't shortcut if custom patterns or abbreviations are registered
        if ($this->inlineParser->getInlinePatterns() || $this->abbreviations) {
            return false;
        }

        // Check for any character that triggers inline parsing
        return strpbrk($text, '\\`*_[{^~<$:!"\'-.\n') === false;
    }
}
