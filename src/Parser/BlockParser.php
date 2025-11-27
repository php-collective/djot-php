<?php

declare(strict_types=1);

namespace Djot\Parser;

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
     * @var array<string, string>
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

    public function __construct()
    {
        $this->inlineParser = new InlineParser($this);
    }

    public function parse(string $input): Document
    {
        $this->references = [];
        $this->footnotes = [];
        $this->pendingAttributes = [];
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

        while ($i < $count) {
            $line = $lines[$i];

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

                $this->references[$label] = $url;
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
     * Try to parse block attributes {.class #id key=value}
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockAttributes(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match block attribute: {.class} or {#id} or {key=value} or combinations
        if (!preg_match('/^\{([^}]+)\}\s*$/', $line, $matches)) {
            return null;
        }

        $attrStr = $matches[1];
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

        // Parse key=value or key="value"
        if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)=(["\']?)([^"\'\s}]*)\2/', $attrStr, $kvMatches, PREG_SET_ORDER)) {
            foreach ($kvMatches as $match) {
                $this->pendingAttributes[$match[1]] = $match[3];
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

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (same char, equal or longer length)
            if (preg_match('/^' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
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

                    break;
                }
                $content .= $currentLine . "\n";
            }

            $i++;
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

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if (preg_match('/^`{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
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

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if (preg_match('/^:{' . $fenceLength . ',}\s*$/', $currentLine)) {
                $i++;

                break;
            }

            $innerLines[] = $currentLine;
            $i++;
        }

        // Parse inner content as blocks
        $this->parseBlocks($div, $innerLines, 0);
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
        $this->inlineParser->parse($heading, trim($content));
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

        // Match block quote: > followed by space or end of line
        if (!preg_match('/^>\s?(.*)$/', $line, $matches)) {
            return null;
        }

        $blockQuote = new BlockQuote();
        $innerLines = [$matches[1]];

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->isBlankLine($currentLine)) {
                break;
            }

            // Continue with > prefix
            if (preg_match('/^>\s?(.*)$/', $currentLine, $matches)) {
                $innerLines[] = $matches[1];
                $i++;
            } elseif (!$this->startsNewBlock($currentLine)) {
                // Lazy continuation (blank lines already handled above)
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
                    $this->inlineParser->parse($term, trim($currentLine));
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
                        $this->appendToLastParagraph($lastItem, trim($indentMatch[2]));
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
            $this->inlineParser->parse($paragraph, $contentLine);
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
                $this->inlineParser->parse($cell, trim($cellContent));
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

        $label = $matches[1];
        $url = trim($matches[2]);

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if ($this->isBlankLine($nextLine) || $this->startsNewBlock($nextLine)) {
                break;
            }
            if (preg_match('/^\s+(\S.*)$/', $nextLine, $contMatch)) {
                $url .= $contMatch[1];
                $i++;
            } else {
                break;
            }
        }

        $this->references[$label] = $url;

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
        $this->inlineParser->parse($paragraph, $content);
        $this->applyPendingAttributes($paragraph);
        $parent->appendChild($paragraph);

        return $i - $start;
    }

    protected function appendToLastParagraph(Node $parent, string $content): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->inlineParser->parse($lastChild, ' ' . $content);
        }
    }

    protected function isBlankLine(string $line): bool
    {
        return trim($line) === '';
    }

    protected function startsNewBlock(string $line): bool
    {
        // Check if line starts a new block element
        return (bool)preg_match('/^(#{1,6}\s|[>]|[-*+]\s|\d+[.)]\s|`{3,}|:{3,}|\|)/', $line);
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $input));
    }

    public function getReference(string $label): ?string
    {
        return $this->references[$label] ?? null;
    }

    public function hasFootnote(string $label): bool
    {
        return isset($this->footnotes[$label]);
    }
}
