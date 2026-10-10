<?php

declare(strict_types=1);

namespace Djot\Parser;

use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Abbreviation;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
use Djot\Node\Inline\EscapedText;
use Djot\Node\Inline\FootnoteRef;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Highlight;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Insert;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Math;
use Djot\Node\Inline\RawInline;
use Djot\Node\Inline\SoftBreak;
use Djot\Node\Inline\Span;
use Djot\Node\Inline\Strong;
use Djot\Node\Inline\Subscript;
use Djot\Node\Inline\Superscript;
use Djot\Node\Inline\Symbol;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\Utility\AttributeParser;
use Djot\Parser\Utility\BacktickRunIndex;
use Djot\Util\StringUtil;
use LengthException;
use WeakMap;

/**
 * Inline parser for Djot
 *
 * Handles emphasis, strong, links, images, code spans, etc.
 */
class InlineParser
{
    /**
     * Characters that can begin a non-plain-text inline construct (escape, code
     * span, emphasis, link, autolink, math, smart quote/dash, attributes, ...).
     *
     * Any run of bytes containing none of these is plain text and is bulk-copied
     * in a single strcspn() scan instead of going through the per-character
     * dispatch in parseInlines(). Keep this in sync with the `$char === ...`
     * branches in that method.
     *
     * @var string
     */
    private const INLINE_SPECIAL_CHARS = "\\\n\$`:![<_*^~{\"'-.";

    /**
     * Maximum inline-recursion depth before remaining text is emitted literally
     * (DoS guard, see parseInlines()). Far deeper than any real document.
     *
     * @var int
     */
    protected const MAX_INLINE_DEPTH = 100;

    /**
     * Current inline-recursion depth (see self::MAX_INLINE_DEPTH).
     */
    protected int $inlineDepth = 0;

    /**
     * @var array<array{type: string, char: string, pos: int, node: \Djot\Node\Node}>
     */
    protected array $delimiterStack = [];

    /**
     * Current source line number for error reporting (0-indexed)
     */
    protected int $currentLine = 0;

    protected string $sourceText = '';

    /**
     * @var list<int>
     */
    private array $lineBreakOffsets = [];

    private int $warningLineIndex = 0;

    /**
     * @var list<string>
     */
    protected array $sourceTextLines = [];

    /**
     * @var list<int>
     */
    protected array $sourceLineMap = [];

    /**
     * @var list<int>
     */
    protected array $sourceColumnMap = [];

    protected int $textOrigin = 0;

    /**
     * Custom inline patterns: array of [pattern => callback]
     * Callback receives (string $match, array $groups, InlineParser $parser)
     * and should return a Node or null
     *
     * @var array<string, callable(string, array<string>, self): ?\Djot\Node\Node>
     */
    protected array $customPatterns = [];

    /**
     * Cached anchored patterns for custom inline patterns
     *
     * @var array<string, string>
     */
    protected array $anchoredPatternCache = [];

    /**
     * Cached abbreviation regex pattern (built once per document)
     */
    protected ?string $abbreviationPattern = null;

    private bool $wordAbbreviations = false;

    /**
     * Memoized per-text check: does the text contain any link/span trigger
     * (`](`, `][`, `]{`)? Lets parseLink skip the O(n) bracket-depth scan for
     * trigger-free text, so a deeply nested `[[[[x]]]]` run stays linear.
     */
    protected ?string $linkTriggerText = null;

    protected bool $linkTriggerPresent = false;

    private ?string $bracketText = null;

    /**
     * @var array<int, int|null>
     */
    private array $bracketEnds = [];

    private int|false $lastBracketCloser = false;

    private ?string $delimiterScanText = null;

    /**
     * @var array<string, array<int, true>>
     */
    private array $delimiterNoCloseFrom = [];

    /**
     * @var array<string, array{int, int}>
     */
    private array $openingDelimiterRuns = [];

    private ?string $attributeScanText = null;

    /**
     * @var array<int, int|null>
     */
    private array $attributeEnds = [];

    /**
     * @var array<int, true>
     */
    private array $invalidAttributeStarts = [];

    protected int $attributeWordBoundary = 0;

    protected int $attributeWordBeforeEmpty = 0;

    protected int $attributeWordBoundaryEnd = -1;

    private ?string $destinationScanText = null;

    /**
     * @var array<int, int|null>
     */
    private array $destinationEnds = [];

    private ?string $backtickScanText = null;

    private ?BacktickRunIndex $backtickRuns = null;

    private ?string $terminatorText = null;

    /**
     * @var array<string, int|false>
     */
    private array $lastTerminators = [];

    /**
     * Cached abbreviation keys for the current pattern
     *
     * @var array<string, string>|null
     */
    protected ?array $cachedAbbreviations = null;

    private ?AbbreviationMatcher $abbreviationMatcher = null;

    private bool $abbreviationPatternValid = true;

    /**
     * Smart quote characters (configurable via SmartQuotesExtension for locale support)
     */
    protected string $openDoubleQuote = "\u{201C}";

    protected string $closeDoubleQuote = "\u{201D}";

    protected string $openSingleQuote = "\u{2018}";

    protected string $closeSingleQuote = "\u{2019}";

    /**
     * Apostrophe character (always U+2019 RIGHT SINGLE QUOTATION MARK)
     *
     * Not configurable via extension — apostrophes are language-independent.
     */
    protected string $apostrophe = "\u{2019}";

    /**
     * @var \WeakMap<\Djot\Node\Inline\Text, int>
     */
    protected WeakMap $abbreviationSegments;

    protected int $abbreviationRun = 0;

    protected bool $abbreviationQuoteRun = false;

    protected ?Text $singleQuoteOpener = null;

    protected bool $singleQuoteOpen = false;

    protected bool $demoteSingleQuote = false;

    protected int $lastQuoteEnd = -1;

    protected string $lastQuoteContext = '';

    public function __construct(protected BlockParser $blockParser)
    {
    }

    /**
     * Register a custom inline pattern
     *
     * The pattern should be a regex that matches from the current position.
     * It will be anchored to the start automatically.
     *
     * Example - @mentions:
     * ```php
     * $parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function($match, $groups, $parser) {
     *     $link = new Link('https://example.com/users/' . $groups[1]);
     *     $link->appendChild(new Text('@' . $groups[1]));
     *     return $link;
     * });
     * ```
     *
     * Example - [[wiki-links]]:
     * ```php
     * $parser->addInlinePattern('/\[\[([^\]]+)\]\]/', function($match, $groups, $parser) {
     *     $link = new Link('/wiki/' . rawurlencode($groups[1]));
     *     $link->appendChild(new Text($groups[1]));
     *     return $link;
     * });
     * ```
     *
     * @param string $pattern Regex pattern (without anchors)
     * @param callable(string, array<string>, self): ?\Djot\Node\Node $callback
     */
    public function addInlinePattern(string $pattern, callable $callback): void
    {
        $this->customPatterns[$pattern] = $callback;
    }

    /**
     * Remove a custom inline pattern
     */
    public function removeInlinePattern(string $pattern): void
    {
        unset($this->customPatterns[$pattern]);
    }

    /**
     * Get all registered custom patterns
     *
     * @return array<string, callable>
     */
    public function getInlinePatterns(): array
    {
        return $this->customPatterns;
    }

    /**
     * Set locale-specific smart quote characters
     *
     * Apostrophes (mid-word and before digits) always remain U+2019
     * regardless of this setting.
     */
    public function setQuoteCharacters(
        string $openDoubleQuote,
        string $closeDoubleQuote,
        string $openSingleQuote,
        string $closeSingleQuote,
    ): void {
        $this->openDoubleQuote = $openDoubleQuote;
        $this->closeDoubleQuote = $closeDoubleQuote;
        $this->openSingleQuote = $openSingleQuote;
        $this->closeSingleQuote = $closeSingleQuote;
    }

    /**
     * Get the current smart quote characters
     *
     * @return array{openDouble: string, closeDouble: string, openSingle: string, closeSingle: string}
     */
    public function getQuoteCharacters(): array
    {
        return [
            'openDouble' => $this->openDoubleQuote,
            'closeDouble' => $this->closeDoubleQuote,
            'openSingle' => $this->openSingleQuote,
            'closeSingle' => $this->closeSingleQuote,
        ];
    }

    /**
     * Parse inline content
     *
     * @param \Djot\Node\Node $parent
     * @param string $text
     * @param int $sourceLine Source line number (0-indexed) for error reporting
     * @param list<int>|null $sourceLineMap Original source line for each line in $text
     * @param int|null $sourceColumn Authored column where the first line starts
     */
    public function parse(
        Node $parent,
        string $text,
        int $sourceLine = 0,
        ?array $sourceLineMap = null,
        ?int $sourceColumn = null,
    ): void {
        $this->abbreviationSegments = new WeakMap();
        $this->abbreviationRun = 0;
        $this->abbreviationQuoteRun = false;
        $this->singleQuoteOpener = null;
        $this->singleQuoteOpen = false;
        $this->demoteSingleQuote = false;
        $this->lastQuoteEnd = -1;
        $this->lastQuoteContext = '';
        $this->delimiterStack = [];
        $this->currentLine = $sourceLine;
        if ($this->blockParser->collectsWarnings()) {
            if ($sourceLineMap !== null && ($sourceLineMap[0] ?? -1) < 0) {
                $sourceLineMap = null;
            }
            $this->sourceText = $text;
            $this->sourceTextLines = explode("\n", $text);
            $this->lineBreakOffsets = [];
            $this->warningLineIndex = 0;
            $offset = 0;
            foreach ($this->sourceTextLines as $line) {
                $offset += strlen($line);
                $this->lineBreakOffsets[] = $offset++;
            }
            array_pop($this->lineBreakOffsets);
            $mappedStart = $sourceLine + ($sourceLineMap === null ? $this->blockParser->getLineOffset() : 0);
            $this->sourceLineMap = $sourceLineMap ?? range($mappedStart, $mappedStart + substr_count($text, "\n"));
            $this->sourceColumnMap = [];
            foreach ($this->sourceTextLines as $index => $line) {
                $this->sourceColumnMap[] = $index === 0 && $sourceColumn !== null
                    ? $sourceColumn
                    : $this->blockParser->sourceColumn($this->sourceLineMap[$index] ?? $mappedStart, $line, 1);
            }
        }
        $this->parseInlines($parent, $text, 0);
        if ($this->singleQuoteOpen && $this->demoteSingleQuote && $this->singleQuoteOpener !== null) {
            $this->singleQuoteOpener->setContent($this->apostrophe);
        }
        if ($this->blockParser->getAbbreviations() !== []) {
            $this->resolveAbbreviationSegments($parent);
        }
    }

    protected function parseInlines(Node $parent, string $text, int $origin = 0): void
    {
        // Inline-nesting DoS guard: deeply nested inline constructs (e.g. a bomb
        // of nested links `[[[...](#)](#)...`) recurse through parseInlines and
        // rescan balanced brackets at each level, which is ~O(n^2). Beyond this
        // depth the remaining text is emitted literally instead of recursing
        // further. Far deeper than any real document.
        if ($this->inlineDepth >= self::MAX_INLINE_DEPTH) {
            if ($text !== '') {
                $parent->appendChild(new Text($text));
            }

            return;
        }

        $outerBracketText = $this->bracketText;
        $outerBracketEnds = $this->bracketEnds;
        $outerLastCloser = $this->lastBracketCloser;
        $outerTriggerText = $this->linkTriggerText;
        $outerTriggerPresent = $this->linkTriggerPresent;
        $outerDelimiterText = $this->delimiterScanText;
        $outerDelimiterFailures = $this->delimiterNoCloseFrom;
        $outerDelimiterRuns = $this->openingDelimiterRuns;
        $outerAttributeText = $this->attributeScanText;
        $outerAttributeEnds = $this->attributeEnds;
        $outerInvalidAttributes = $this->invalidAttributeStarts;
        $outerDestinationText = $this->destinationScanText;
        $outerDestinationEnds = $this->destinationEnds;
        $outerBacktickText = $this->backtickScanText;
        $outerBacktickRuns = $this->backtickRuns;
        $outerTerminatorText = $this->terminatorText;
        $outerTerminators = $this->lastTerminators;
        $outerWordBoundary = $this->attributeWordBoundary;
        $outerWordBeforeEmpty = $this->attributeWordBeforeEmpty;
        $outerWordBoundaryEnd = $this->attributeWordBoundaryEnd;
        $this->attributeWordBoundary = count($parent->getChildren());
        $this->attributeWordBeforeEmpty = $this->attributeWordBoundary;
        $this->attributeWordBoundaryEnd = -1;
        $this->inlineDepth++;
        $previousOrigin = $this->textOrigin;
        $this->textOrigin = $origin;
        try {
            $this->parseInlinesImpl($parent, $text);
        } finally {
            $this->textOrigin = $previousOrigin;
            $this->bracketText = $outerBracketText;
            $this->bracketEnds = $outerBracketEnds;
            $this->lastBracketCloser = $outerLastCloser;
            $this->linkTriggerText = $outerTriggerText;
            $this->linkTriggerPresent = $outerTriggerPresent;
            $this->delimiterScanText = $outerDelimiterText;
            $this->delimiterNoCloseFrom = $outerDelimiterFailures;
            $this->openingDelimiterRuns = $outerDelimiterRuns;
            $this->attributeScanText = $outerAttributeText;
            $this->attributeEnds = $outerAttributeEnds;
            $this->invalidAttributeStarts = $outerInvalidAttributes;
            $this->destinationScanText = $outerDestinationText;
            $this->destinationEnds = $outerDestinationEnds;
            $this->backtickScanText = $outerBacktickText;
            $this->backtickRuns = $outerBacktickRuns;
            $this->terminatorText = $outerTerminatorText;
            $this->lastTerminators = $outerTerminators;
            $this->attributeWordBoundary = $outerWordBoundary;
            $this->attributeWordBeforeEmpty = $outerWordBeforeEmpty;
            $this->attributeWordBoundaryEnd = $outerWordBoundaryEnd;
            $this->inlineDepth--;
        }
    }

    /**
     * @return array{line: int, column: int}
     */
    protected function warningLocation(int $pos, string $marker): array
    {
        if (!$this->blockParser->collectsWarnings()) {
            return ['line' => $this->currentLine, 'column' => $pos + 1];
        }
        $absolute = $this->textOrigin + $pos;
        $breaks = $this->lineBreakOffsets;
        $lineIndex = $this->warningLineIndex;
        if ($lineIndex > 0 && $breaks[$lineIndex - 1] >= $absolute) {
            $low = 0;
            $high = $lineIndex;
            while ($low < $high) {
                $mid = intdiv($low + $high, 2);
                if ($breaks[$mid] < $absolute) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }
            $lineIndex = $low;
        } else {
            while (isset($breaks[$lineIndex]) && $breaks[$lineIndex] < $absolute) {
                $lineIndex++;
            }
        }
        $this->warningLineIndex = $lineIndex;
        $previousBreak = $breaks[$lineIndex - 1] ?? -1;
        $column = $absolute - $previousBreak;
        $sourceLine = $this->sourceLineMap[$lineIndex] ?? ($this->currentLine + $lineIndex);

        $fallback = ($this->sourceColumnMap[$lineIndex] ?? 1) + $column - 1;

        return [
            'line' => $sourceLine,
            'column' => $this->blockParser->sourceWarningColumn($sourceLine, $marker, $fallback),
        ];
    }

    protected function parseInlinesImpl(Node $parent, string $text): void
    {
        $length = strlen($text);
        $pos = 0;
        $textBuffer = '';
        $literalBrace = -1;

        while ($pos < $length) {
            // Fast path: bulk-copy a run of plain text in a single C-level scan,
            // bypassing the per-character dispatch below. Skipped when custom
            // inline patterns are registered, since those may match anywhere.
            if ($this->customPatterns === []) {
                $plain = strcspn($text, self::INLINE_SPECIAL_CHARS, $pos);
                if ($plain > 0) {
                    $textBuffer .= substr($text, $pos, $plain);
                    $pos += $plain;
                    if ($pos >= $length) {
                        break;
                    }
                }
            }

            $char = $text[$pos];
            $nextChar = $text[$pos + 1] ?? '';

            // A backslash at the very end of the content (no following
            // character) still produces a hard break
            if ($char === '\\' && $pos + 1 >= $length) {
                $this->flushText($parent, rtrim($textBuffer, " \t"));
                $textBuffer = '';
                $parent->appendChild(new HardBreak());
                $pos++;

                continue;
            }

            // Check for escape sequences
            if ($char === '\\' && $pos + 1 < $length) {
                $escaped = $text[$pos + 1];
                if ($escaped === "\n") {
                    // Hard break
                    $this->flushText($parent, rtrim($textBuffer, " \t"));
                    $textBuffer = '';
                    $parent->appendChild(new HardBreak());
                    $pos += 2;

                    continue;
                }
                // Check for hard break: \TAB or \ followed by optional whitespace then newline
                if ($escaped === "\t" || $escaped === ' ') {
                    // Look ahead for end of line (optional trailing whitespace then newline)
                    $lookAhead = $pos + 2;
                    while ($lookAhead < $length && ($text[$lookAhead] === ' ' || $text[$lookAhead] === "\t")) {
                        $lookAhead++;
                    }
                    if ($lookAhead < $length && $text[$lookAhead] === "\n") {
                        // This is a hard break - strip trailing whitespace from text buffer
                        $textBuffer = rtrim($textBuffer, " \t");
                        $this->flushText($parent, $textBuffer);
                        $textBuffer = '';
                        $parent->appendChild(new HardBreak());
                        $pos = $lookAhead + 1;

                        continue;
                    }
                    // Not at end of line - treat as escaped space/tab
                    if ($escaped === ' ') {
                        // Non-breaking space - use placeholder that renderer converts to &nbsp;
                        // We use U+E000 (private use area) to distinguish from literal NBSP
                        $textBuffer .= "\u{E000}";
                        $pos += 2;

                        continue;
                    }
                    // Escaped tab becomes literal tab
                    $textBuffer .= $escaped;
                    $pos += 2;

                    continue;
                }
                if (ctype_punct($escaped)) {
                    // Create EscapedText node for round-trip support
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild(new EscapedText($escaped));
                    $pos += 2;

                    continue;
                }
            }

            // Check custom patterns first (before built-in syntax)
            $customResult = $this->tryCustomPatterns($text, $pos);
            if ($customResult !== null) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $parent->appendChild($customResult['node']);
                $pos = $customResult['pos'];

                continue;
            }

            // Soft break (newline)
            if ($char === "\n") {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $parent->appendChild(new SoftBreak());
                $pos++;

                continue;
            }

            // Math: $`...` or $$`...`
            if ($char === '$') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseMath($text, $pos);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
                // Not math, add to buffer
                $textBuffer .= $char;
                $pos++;

                continue;
            }

            // Inline code (or raw inline `...`{=format})
            if ($char === '`') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseCodeSpan($text, $pos);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Symbol :name:
            if ($char === ':') {
                $result = $this->parseSymbol($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Image: ![alt](src)
            if ($char === '!' && $nextChar === '[') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseImage($text, $pos);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Footnote reference: [^label]
            if ($char === '[' && $nextChar === '^') {
                $result = $this->parseFootnoteRef($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Link: [text](url) or [text][ref]
            if ($char === '[') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseLink($text, $pos);
                if ($result !== null) {
                    // Check if this is an unclosed link (special handling)
                    if (isset($result['unclosed_link'])) {
                        // Output [ then parse linkText in isolation then output ](
                        $parent->appendChild(new Text('['));
                        $this->parseInlines($parent, $result['link_text'], $this->textOrigin + $pos + 1);
                        $parent->appendChild(new Text(']('));
                        $pos = $result['continue_pos'];

                        continue;
                    }
                    // At this point, result has node/pos (not unclosed_link)
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Autolink: <url> or <email>
            if ($char === '<') {
                $result = $this->parseAutolink($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            if ($literalBrace >= 0 && $literalBrace === $pos - 1 && str_contains('_*^~', $char)) {
                $textBuffer .= $char;
                $pos++;

                continue;
            }

            // Emphasis: _text_
            if ($char === '_') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '_', Emphasis::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Strong: *text*
            if ($char === '*') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '*', Strong::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Superscript: ^text^ or {^text^}
            if ($char === '^') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '^', Superscript::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Subscript: ~text~
            if ($char === '~') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '~', Subscript::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Explicit quote direction overrides the quote heuristic.
            if (
                $char === '{' && ($nextChar === '"' || $nextChar === "'")
                && ($text[$pos + 1 + strspn($text, $nextChar, $pos + 1)] ?? '') !== '}'
            ) {
                $this->emitSmartQuote($parent, $textBuffer, $text, $pos + 1, $nextChar, true);
                $this->lastQuoteEnd = $this->textOrigin + $pos + 2;
                $pos += 2;

                continue;
            }

            // Special braced syntax: {=highlight=}, {+insert+}, {-delete-}, or inline attributes {.class}
            if ($char === '{') {
                // First check for inline attributes that apply to preceding word
                $attrResult = $this->parseInlineAttributes($text, $pos, $textBuffer, $parent);
                if ($attrResult !== null) {
                    $textBuffer = $attrResult['textBuffer'];
                    $pos = $attrResult['pos'];

                    continue;
                }

                // Then try special braced syntax
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseBracedInline($text, $pos);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
                $literalBrace = $pos;
            }

            // Smart quotes
            if ($char === '"' || $char === "'") {
                if ($nextChar === '}') {
                    $this->emitSmartQuote($parent, $textBuffer, $text, $pos, $char, false);
                    $this->lastQuoteEnd = $this->textOrigin + $pos + 2;
                    $pos += 2;
                } else {
                    $this->emitSmartQuote($parent, $textBuffer, $text, $pos, $char);
                    $pos++;
                }

                continue;
            }

            // Smart dashes
            if ($char === '-' && $nextChar === '-') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseSmartDash($text, $pos);
                $textBuffer .= $result['text'];
                $pos = $result['pos'];

                continue;
            }

            // Ellipsis
            if ($char === '.' && substr($text, $pos, 3) === '...') {
                $textBuffer .= "\u{2026}";
                $pos += 3;

                continue;
            }

            // Regular character
            $textBuffer .= $char;
            $pos++;
        }

        $this->flushText($parent, $textBuffer);
    }

    protected function flushText(Node $parent, string $text, bool $quoteSegment = false): void
    {
        $abbreviations = $this->blockParser->getAbbreviations();
        $this->abbreviationQuoteRun = $this->abbreviationQuoteRun || $quoteSegment;
        if ($text !== '' && $abbreviations !== [] && !$this->abbreviationQuoteRun) {
            $this->flushTextWithAbbreviations($parent, $text, $abbreviations);
        } elseif ($text !== '') {
            $node = new Text($text);
            $parent->appendChild($node);
            if ($this->blockParser->getAbbreviations() !== []) {
                $this->abbreviationSegments[$node] = $this->abbreviationRun;
            }
        }
        if (!$quoteSegment) {
            $this->abbreviationQuoteRun = false;
            $this->abbreviationRun++;
        }
    }

    /**
     * Resolve quote-only flushes after demotion, rebuilding each child list once.
     */
    protected function resolveAbbreviationSegments(Node $parent): void
    {
        $children = array_values($parent->getChildren());
        $parent->removeChildren($children);
        $parts = [];
        $run = null;
        foreach ($children as $child) {
            $childRun = $child instanceof Text ? ($this->abbreviationSegments[$child] ?? null) : null;
            if ($parts !== [] && ($childRun === null || $childRun !== $run)) {
                $this->flushTextWithAbbreviations($parent, implode('', $parts), $this->blockParser->getAbbreviations());
                $parts = [];
            }
            if ($child instanceof Text && $childRun !== null) {
                $parts[] = $child->getContent();
                $run = $childRun;
            } else {
                $this->resolveAbbreviationSegments($child);
                $parent->appendChild($child);
            }
        }
        if ($parts !== []) {
            $this->flushTextWithAbbreviations($parent, implode('', $parts), $this->blockParser->getAbbreviations());
        }
    }

    /**
     * Flush text while replacing abbreviations with Abbreviation nodes
     *
     * @param \Djot\Node\Node $parent
     * @param string $text
     * @param array<string, string> $abbreviations
     */
    protected function flushTextWithAbbreviations(Node $parent, string $text, array $abbreviations): void
    {
        if ($this->cachedAbbreviations !== $abbreviations) {
            $keys = array_map(static fn (string|int $key): string => (string)$key, array_keys($abbreviations));
            $this->wordAbbreviations = $this::class === self::class;
            foreach ($keys as $key) {
                if (preg_match('/^[A-Za-z0-9]+$/D', $key) !== 1) {
                    $this->wordAbbreviations = false;

                    break;
                }
            }
            $this->abbreviationMatcher = null;
            $this->abbreviationPatternValid = true;
            if (!$this->wordAbbreviations) {
                usort($keys, static fn (string $a, string $b): int => strlen($b) - strlen($a));
                $escaped = array_map(static fn (string $key): string => preg_quote($key, '/'), $keys);
                $this->abbreviationPattern = '/\b(' . implode('|', $escaped) . ')\b/u';
                if (
                    $this::class === self::class && strlen($this->abbreviationPattern) >= 8192
                    && @preg_match($this->abbreviationPattern, '') === false
                ) {
                    $this->abbreviationPatternValid = false;
                    $validKeys = !in_array('', $keys, true);
                    foreach ($keys as $key) {
                        if (preg_match('//u', $key) !== 1) {
                            $validKeys = false;

                            break;
                        }
                    }
                    if ($validKeys) {
                        try {
                            $this->abbreviationMatcher = new AbbreviationMatcher($keys);
                        } catch (LengthException) {
                            $this->abbreviationMatcher = null;
                        }
                    }
                }
            }
            $this->cachedAbbreviations = $abbreviations;
        }

        if ($this->abbreviationMatcher !== null) {
            $parts = $this->abbreviationMatcher->split($text);
        } elseif (!$this->abbreviationPatternValid) {
            $parts = false;
        } elseif ($this->wordAbbreviations) {
            $matched = preg_match_all('/\b[A-Za-z0-9]++\b/u', $text, $words, PREG_OFFSET_CAPTURE);
            $parts = $matched === false ? false : [];
            if ($parts !== false) {
                $cursor = 0;
                foreach ($words[0] as [$word, $at]) {
                    if (!isset($abbreviations[$word])) {
                        continue;
                    }
                    if ($at > $cursor) {
                        $parts[] = substr($text, $cursor, $at - $cursor);
                    }
                    $parts[] = $word;
                    $cursor = $at + strlen($word);
                }
                if ($cursor < strlen($text)) {
                    $parts[] = substr($text, $cursor);
                }
            }
        } else {
            $parts = preg_split((string)$this->abbreviationPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        }

        if ($parts === false) {
            // Fallback: just output as plain text
            $parent->appendChild(new Text($text));

            return;
        }

        foreach ($parts as $part) {
            if (isset($abbreviations[$part])) {
                // This is an abbreviation match
                $abbr = new Abbreviation($abbreviations[$part]);
                $abbr->appendChild(new Text($part));
                $parent->appendChild($abbr);
            } else {
                // Regular text
                $parent->appendChild(new Text($part));
            }
        }
    }

    /**
     * Try to match custom inline patterns at the current position
     *
     * @return array{node: \Djot\Node\Node, pos: int}|null
     */
    protected function tryCustomPatterns(string $text, int $pos): ?array
    {
        if ($this->customPatterns === []) {
            return null;
        }

        foreach ($this->customPatterns as $pattern => $callback) {
            // Cache the anchored pattern (use \G to match at offset position)
            if (!isset($this->anchoredPatternCache[$pattern])) {
                $this->anchoredPatternCache[$pattern] = '/\G' . substr($pattern, 1, -1) . '/';
            }

            // Use offset parameter to avoid substr() allocation
            if (preg_match($this->anchoredPatternCache[$pattern], $text, $matches, 0, $pos)) {
                $node = $callback($matches[0], $matches, $this);
                if ($node !== null) {
                    return [
                        'node' => $node,
                        'pos' => $pos + strlen($matches[0]),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Code|\Djot\Node\Inline\RawInline, pos: int}|null
     */
    protected function parseCodeSpan(string $text, int $pos): ?array
    {
        // Count opening backticks
        $openBackticks = 0;
        $length = strlen($text);

        while ($pos + $openBackticks < $length && $text[$pos + $openBackticks] === '`') {
            $openBackticks++;
        }

        $contentStart = $pos + $openBackticks;
        $searchPos = $contentStart;

        // Find matching closing backticks
        // Handle edge case: backticks at end of text with no content after
        if ($searchPos >= $length) {
            return [
                'node' => new Code(''),
                'pos' => $length,
            ];
        }

        while ($searchPos < $length) {
            $closePos = strpos($text, str_repeat('`', $openBackticks), $searchPos);
            if ($closePos === false) {
                // No closing backticks found - in djot, unclosed code spans
                // extend to end of paragraph content
                $remaining = substr($text, $contentStart);

                return [
                    'node' => new Code($remaining),
                    'pos' => $length,
                ];
            }

            // Make sure we have exactly the right number of backticks (not more)
            // Check both before and after the match
            $afterClose = $closePos + $openBackticks;
            $beforeClose = $closePos > 0 ? $text[$closePos - 1] : '';
            $afterChar = $afterClose < $length ? $text[$afterClose] : '';

            // Skip if this is inside a longer run of backticks
            if ($beforeClose === '`' || $afterChar === '`') {
                // Move past this backtick run to find the next potential match
                $searchPos = $closePos + strspn($text, '`', $closePos);

                continue;
            }

            // Found exact match
            $content = substr($text, $contentStart, $closePos - $contentStart);

            // Strip single leading and trailing space if content starts/ends with backtick
            if (strlen($content) >= 2 && $content[0] === ' ' && $content[strlen($content) - 1] === ' ') {
                if (str_contains($content, '`')) {
                    $content = substr($content, 1, -1);
                }
            }

            // Check for raw inline format: `...`{=format}
            // Format must be ONLY {=format} with no other attributes
            $endPos = $afterClose;
            $hasRawInlineAttempt = $afterClose < $length && $text[$afterClose] === '{'
                && $afterClose + 1 < $length && $text[$afterClose + 1] === '=';
            if ($hasRawInlineAttempt) {
                $formatEnd = strpos($text, '}', $afterClose);
                if ($formatEnd !== false) {
                    $format = substr($text, $afterClose + 2, $formatEnd - $afterClose - 2);
                    // Only accept pure format (alphanumeric/hyphen), reject if mixed with other attributes
                    if (preg_match('/^[a-zA-Z0-9-]+$/', $format)) {
                        $endPos = $formatEnd + 1;

                        return [
                            'node' => new RawInline($content, $format),
                            'pos' => $endPos,
                        ];
                    }
                    // Mixed attributes like {=html #id} - treat attribute block as literal text
                    // Don't parse as trailing attributes either
                }
            }

            $code = new Code($content);

            // Check for trailing attributes: `code`{.class}{.more}
            // But NOT if there was a {= pattern (failed raw inline attempt should be literal)
            if (!$hasRawInlineAttempt && $endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($code, $text, $endPos);
            }

            return [
                'node' => $code,
                'pos' => $endPos,
            ];
        }

        return [
            'node' => new Code(substr($text, $contentStart)),
            'pos' => $length,
        ];
    }

    /**
     * Index nested pairs and failed openers in one scan, using Djot's escapes.
     */
    protected function findBalancedBracketEnd(string $text, int $open): ?int
    {
        if (array_key_exists($open, $this->bracketEnds)) {
            return $this->bracketEnds[$open];
        }
        $starts = [$open];
        $length = strlen($text);
        $at = $open + 1;
        while ($at < $length) {
            $at += strcspn($text, '[]\\', $at);
            if ($at >= $length) {
                break;
            }
            $char = $text[$at];
            if ($char === '\\' && $at + 1 < $length) {
                $at += 2;

                continue;
            }
            if ($char === '[') {
                $starts[] = $at;
            } elseif ($char === ']') {
                $start = array_pop($starts);
                $this->bracketEnds[$start] = $at;
                if ($starts === []) {
                    return $at;
                }
            }
            $at++;
        }
        foreach ($starts as $start) {
            $this->bracketEnds[$start] = null;
        }

        return null;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Link|\Djot\Node\Inline\Span, pos: int}|array{unclosed_link: true, link_text: string, continue_pos: int}|null
     */
    protected function parseLink(string $text, int $pos): ?array
    {
        $length = strlen($text);

        if ($text !== $this->bracketText) {
            $this->bracketText = $text;
            $this->bracketEnds = [];
            $this->lastBracketCloser = strrpos($text, ']');
        }
        if ($this->lastBracketCloser === false || $this->lastBracketCloser <= $pos) {
            return null;
        }

        // A link, reference, or inline span can only form when the matched `]`
        // is directly followed by `(`, `[`, or `{`. If the text contains none of
        // `](`, `][`, `]{`, nothing can start here, so skip the bracket-depth
        // scan below -- otherwise a deeply nested run like `[[[[x]]]]` is
        // O(n^2). The presence check is memoized per text.
        if ($text !== $this->linkTriggerText) {
            $this->linkTriggerText = $text;
            $this->linkTriggerPresent = strpos($text, '](') !== false
                || strpos($text, '][') !== false
                || strpos($text, ']{') !== false;
        }
        if (!$this->linkTriggerPresent) {
            return null;
        }

        $textEnd = $this->findBalancedBracketEnd($text, $pos);
        if ($textEnd === null) {
            return null;
        }

        $linkText = substr($text, $pos + 1, $textEnd - $pos - 1);
        $afterBracket = $textEnd + 1;

        // Inline link: [text](url) or [text](url){.class}
        if ($afterBracket < $length && $text[$afterBracket] === '(') {
            $urlStart = $afterBracket + 1;
            $destinationEnd = $this->cachedLinkDestinationEnd($text, $afterBracket);
            if ($destinationEnd !== null) {
                $urlEnd = $destinationEnd - 1;
                $url = substr($text, $urlStart, $urlEnd - $urlStart);
                // Remove newlines from URL (soft breaks are ignored in URLs)
                $url = str_replace(["\r\n", "\r", "\n"], '', $url);
                $url = trim($url);
                // Process escape sequences in URL (e.g., \* -> *)
                $url = preg_replace('/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]\^_`{|}~])/', '$1', $url) ?? $url;
                $link = new Link($url);
                $this->parseInlines($link, $linkText, $this->textOrigin + $pos + 1);

                // Track anchor links for validation
                if (preg_match('/^#(.+)$/', $url, $anchorMatch)) {
                    $this->blockParser->trackAnchorLink($anchorMatch[1], $this->currentLine, $pos + 1);
                }

                $endPos = $urlEnd + 1;

                // Check for attributes after link: [text](url){.class}{.more}
                if ($endPos < $length && $text[$endPos] === '{') {
                    $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                }

                return [
                    'node' => $link,
                    'pos' => $endPos,
                ];
            }

            // Unclosed parenthesis - not a valid link
            // Parse [text] as isolated inline content, then continue from after (
            // This prevents emphasis from crossing the [text]( boundary
            return [
                'unclosed_link' => true,
                'link_text' => $linkText,
                'continue_pos' => $urlStart, // Position after (
            ];
        }

        // Reference link: [text][ref] or [text][]{.class}
        if ($afterBracket < $length && $text[$afterBracket] === '[') {
            $refEnd = strpos($text, ']', $afterBracket + 1);
            if ($refEnd !== false) {
                $ref = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);

                // For empty reference [text][], use link text as reference
                // In this case, normalize to strip formatting markers
                if ($ref === '') {
                    $ref = $this->normalizeReferenceLabel($linkText);
                } else {
                    // Explicit reference [text][ref] - only normalize whitespace, keep formatting chars
                    $ref = preg_replace('/\s+/', ' ', trim($ref)) ?? $ref;
                }

                // Store original bracket content before normalization
                $originalRefBracket = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);

                if ($this->blockParser->defersReferences()) {
                    $location = $this->warningLocation($pos, substr($text, $pos, $refEnd - $pos + 1));
                    $link = new Link();
                    $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                    $this->parseInlines($link, $linkText, $this->textOrigin + $pos + 1);
                    $endPos = $this->applyConsecutiveAttributes($link, $text, $refEnd + 1);
                    $this->blockParser->deferReference($link, $ref, $location['line'], $location['column']);

                    return ['node' => $link, 'pos' => $endPos];
                }

                $refDef = $this->blockParser->getReference($ref);
                if ($refDef !== null) {
                    // Track reference usage for validation
                    $this->blockParser->markReferenceUsed($ref, $this->currentLine);

                    $link = new Link($refDef->url);
                    // Store reference info for round-trip support
                    $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                    $this->parseInlines($link, $linkText, $this->textOrigin + $pos + 1);

                    // Track anchor links for validation
                    if (preg_match('/^#(.+)$/', $refDef->url, $anchorMatch)) {
                        $this->blockParser->trackAnchorLink($anchorMatch[1], $this->currentLine, $pos + 1);
                    }

                    // Apply attributes from reference definition first
                    foreach ($refDef->attributes as $key => $value) {
                        if ($key === 'class') {
                            $link->addClass((string)$value);
                        } else {
                            $link->setAttribute($key, (string)$value);
                        }
                    }

                    $endPos = $refEnd + 1;

                    // Check for attributes after reference link (override definition attrs)
                    if ($endPos < $length && $text[$endPos] === '{') {
                        $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                    }

                    return [
                        'node' => $link,
                        'pos' => $endPos,
                    ];
                }

                // Reference not found - create link without href (null) and warn
                $location = $this->warningLocation($pos, substr($text, $pos, $refEnd - $pos + 1));
                $this->blockParser->addUndefinedReferenceWarning($ref, $location['line'], $location['column'], true);

                $link = new Link(null);
                // Store reference info for round-trip support
                $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                $this->parseInlines($link, $linkText, $this->textOrigin + $pos + 1);

                $endPos = $refEnd + 1;

                // Check for attributes after reference link
                if ($endPos < $length && $text[$endPos] === '{') {
                    $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                }

                return [
                    'node' => $link,
                    'pos' => $endPos,
                ];
            }
        }

        // Inline span [text]{attrs}. A bracketed run forms a <span> only when
        // the directly-abutting block is a valid attribute block: one that
        // yields an attribute, or an empty/whitespace/comment-only block (kept
        // so a default-attribute extension can target [x]{} / [x]{ }). A block
        // carrying unrecognized content ({???}, {=y=}) is not an attribute
        // block, so the brackets and block render literally - the bracket text
        // is still inline-parsed, e.g. [*x*]{???} -> [<strong>x</strong>]{???}.
        if ($afterBracket < $length && $text[$afterBracket] === '{') {
            $attrEnd = $this->findAttributeEnd($text, $afterBracket);
            if ($attrEnd !== null && !($this::class === self::class && $this->attributeScanText === $text && isset($this->invalidAttributeStarts[$afterBracket]))) {
                $attrStr = substr($text, $afterBracket + 1, $attrEnd - $afterBracket - 1);
                if ($this->isValidAttrPayload($attrStr)) {
                    $span = new Span();
                    // Apply the gating block, then absorb any further
                    // consecutive attribute blocks.
                    $this->applyAttributesToNode($span, $attrStr);
                    $endPos = $this->applyConsecutiveAttributes($span, $text, $attrEnd + 1);
                    $this->parseInlines($span, $linkText, $this->textOrigin + $pos + 1);

                    return [
                        'node' => $span,
                        'pos' => $endPos,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Image, pos: int}|null
     */
    protected function parseImage(string $text, int $pos): ?array
    {
        // Skip the !
        $result = $this->parseLink($text, $pos + 1);
        if ($result === null) {
            return null;
        }

        // Unclosed links can't be images
        if (isset($result['unclosed_link'])) {
            return null;
        }

        $link = $result['node'];
        if (!$link instanceof Link) {
            return null;
        }

        // Extract alt text from link children
        $alt = $this->extractText($link);

        $image = new Image($link->getDestination() ?? '', $alt, $link->getTitle());

        $this->blockParser->transferDeferredReference($link, $image);

        // Transfer reference label for round-trip support
        if ($link->getReferenceLabel() !== null) {
            $image->setReferenceLabel($link->getReferenceLabel());
        }

        // Transfer attributes from link to image
        foreach ($link->getAttributes() as $key => $value) {
            $image->setAttribute($key, $value);
        }

        return [
            'node' => $image,
            'pos' => $result['pos'],
        ];
    }

    protected function extractText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } else {
                $text .= $this->extractText($child);
            }
        }

        return $text;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Link, pos: int}|null
     */
    protected function parseAutolink(string $text, int $pos): ?array
    {
        $length = strlen($text);
        $end = $this->findAutolinkCloser($text, $pos);
        if ($end === null) {
            return null;
        }

        $content = substr($text, $pos + 1, $end - $pos - 1);

        // URL autolink
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s<>]*$/', $content)) {
            $link = new Link($content);
            $link->setAutolink(true);
            $link->appendChild(new Text($content));

            $endPos = $end + 1;

            // Check for trailing attributes: <url>{.class}{.more}
            if ($endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
            }

            return [
                'node' => $link,
                'pos' => $endPos,
            ];
        }

        // Email autolink
        if (filter_var($content, FILTER_VALIDATE_EMAIL)) {
            $link = new Link('mailto:' . $content);
            $link->setAutolink(true);
            $link->appendChild(new Text($content));

            $endPos = $end + 1;

            // Check for trailing attributes: <email>{.class}{.more}
            if ($endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
            }

            return [
                'node' => $link,
                'pos' => $endPos,
            ];
        }

        return null;
    }

    /**
     * Parse delimited inline elements like _emphasis_ or *strong*
     *
     * @param string $delimiter
     * @param int $pos
     * @param string $text
     * @param class-string<\Djot\Node\Node> $nodeClass
     *
     * @return array{node: \Djot\Node\Node, pos: int}|null
     */
    protected function parseDelimited(string $text, int $pos, string $delimiter, string $nodeClass): ?array
    {
        $length = strlen($text);

        // Check if this can be an opener (not preceded by whitespace for closer detection)
        $prevChar = $pos > 0 ? $text[$pos - 1] : ' ';
        $nextChar = $text[$pos + 1] ?? ' ';

        // Can't open if followed by whitespace
        if (ctype_space($nextChar)) {
            return null;
        }

        // Can't open if followed by } (closer marker in djot)
        if ($nextChar === '}') {
            return null;
        }

        // Find closing delimiter, skipping over attribute blocks and code spans
        // First, measure the consecutive opening run. strspn is a C-level scan;
        // a PHP char-by-char loop here made a long delimiter run (`****...`)
        // O(n^2), since every opener re-counts the run from its position.
        if ($this->delimiterScanText !== $text) {
            $this->delimiterScanText = $text;
            $this->delimiterNoCloseFrom = [];
            $this->openingDelimiterRuns = [];
        }
        $run = $this->openingDelimiterRuns[$delimiter] ?? null;
        if ($run !== null && $pos >= $run[0] && $pos < $run[1]) {
            $openingRunEnd = $run[1];
        } else {
            $openingRunEnd = $pos + strspn($text, $delimiter, $pos);
            $this->openingDelimiterRuns[$delimiter] = [$pos, $openingRunEnd];
        }
        if (isset($this->delimiterNoCloseFrom[$delimiter][$pos])) {
            return null;
        }
        // If the opening run extends to end of string (all delimiters), no valid emphasis
        if ($openingRunEnd >= $length) {
            return null;
        }
        // A closer needs the delimiter to appear again after the opening run.
        // Without this, every opener scans the whole tail looking for a close
        // that is not there (the other half of the O(n^2)).
        $firstClose = strpos($text, $delimiter, $openingRunEnd);
        if ($firstClose === false) {
            for ($opener = $pos; $opener < $openingRunEnd; $opener++) {
                $this->delimiterNoCloseFrom[$delimiter][$opener] = true;
            }

            return null;
        }
        // Skip the opening run to look for content and closing run
        $lastOpeningPos = $openingRunEnd - 1;
        if (ctype_space($text[$openingRunEnd]) || $text[$openingRunEnd] === '}') {
            $lastOpeningPos--;
        }
        $openers = range($pos, $lastOpeningPos);
        $searchPos = $openingRunEnd;
        $bulkScan = $firstClose - $openingRunEnd >= 32;
        $significant = $delimiter . '{`<]\\';
        $literalBrace = -1;
        while ($searchPos < $length) {
            if (isset($this->delimiterNoCloseFrom[$delimiter][$searchPos])) {
                break;
            }
            if ($bulkScan) {
                $searchPos += strcspn($text, $significant, $searchPos);
                if ($searchPos >= $length) {
                    break;
                }
            }
            $char = $text[$searchPos];

            // Only valid attributes can hide delimiters inside their payload.
            if ($char === '{') {
                $attrEnd = $this->findAttributeEnd($text, $searchPos);
                if (
                    $attrEnd !== null
                    && !($this->attributeScanText === $text && isset($this->invalidAttributeStarts[$searchPos]))
                    && AttributeParser::isValid(substr($text, $searchPos + 1, $attrEnd - $searchPos - 1))
                ) {
                    $searchPos = $attrEnd + 1;

                    continue;
                }
                $literalBrace = $searchPos;
            }

            // Skip over code spans `...`
            if ($char === '`') {
                $codeEnd = $this->findCodeSpanEnd($text, $searchPos);
                if ($codeEnd !== null) {
                    $searchPos = $codeEnd;

                    continue;
                }
            }

            // Skip over autolinks <...>
            if ($char === '<') {
                $autolinkEnd = $this->findAutolinkEnd($text, $searchPos);
                if ($autolinkEnd !== null) {
                    $searchPos = $autolinkEnd;

                    continue;
                }
            }

            // Skip over link destinations ](...)
            // This prevents emphasis delimiters inside URLs from closing emphasis
            // that started before the link. e.g. _[link](url_bar)_ should work.
            if ($char === ']' && $searchPos + 1 < $length && $text[$searchPos + 1] === '(') {
                $destEnd = $this->findLinkDestinationEnd($text, $searchPos + 1);
                if ($destEnd !== null) {
                    $searchPos = $destEnd;

                    continue;
                }
            }

            // Skip escape sequences
            if ($char === '\\' && $searchPos + 1 < $length) {
                $searchPos += 2;

                continue;
            }

            if ($char === $delimiter) {
                if ($literalBrace === $searchPos - 1) {
                    $searchPos++;

                    continue;
                }
                $before = $text[$searchPos - 1] ?? ' ';
                $after = $text[$searchPos + 1] ?? ' ';
                $lastOpener = $openers[count($openers) - 1];
                $canClose = !ctype_space($before) && $after !== '}' && $lastOpener !== $searchPos - 1;
                if ($canClose) {
                    $closingRunEnd = $searchPos + strspn($text, $delimiter, $searchPos);
                    $closerCount = $closingRunEnd - $searchPos;
                    if (($text[$closingRunEnd] ?? '') === '}') {
                        $closerCount--;
                    }
                    $openerCount = count($openers);
                    if ($closerCount < $openerCount) {
                        for ($closer = 0; $closer < $closerCount; $closer++) {
                            array_pop($openers);
                        }
                        $searchPos += $closerCount;

                        continue;
                    }
                    $actualClose = $searchPos + $openerCount - 1;
                    $content = substr($text, $pos + 1, $actualClose - $pos - 1);

                    $node = new $nodeClass();
                    $this->parseInlines($node, $content, $this->textOrigin + $pos + 1);

                    $endPos = $actualClose + 1;

                    // Check for trailing attributes: _text_{.class}{.more}
                    if ($endPos < $length && $text[$endPos] === '{') {
                        $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
                    }

                    return [
                        'node' => $node,
                        'pos' => $endPos,
                    ];
                }
                if (!ctype_space($after) && $after !== '}') {
                    $openers[] = $searchPos;
                }
            }

            $searchPos++;
        }

        // Cache each unmatched opener; later openers in the same run may still match.
        foreach ($openers as $opener) {
            $this->delimiterNoCloseFrom[$delimiter][$opener] = true;
        }

        return null;
    }

    /**
     * Parse braced inline syntax: {=highlight=}, {+insert+}, {-delete-}, {'} and {"}
     *
     * @return array{node: \Djot\Node\Node, pos: int}|null
     */
    protected function parseBracedInline(string $text, int $pos): ?array
    {
        $length = strlen($text);
        if ($pos + 2 >= $length) {
            return null;
        }

        $marker = $text[$pos + 1];

        // Handle braced quotes: {'} or {"} followed by optional quotes then }
        // {''} = left single quote + right single quote
        // {""} = left double quote + right double quote
        // {'} = right single quote only, {"} = right double quote only
        if ($marker === "'" || $marker === '"') {
            // Count consecutive quotes
            $quoteCount = 1;
            $quotePos = $pos + 2;
            while ($quotePos < $length && $text[$quotePos] === $marker) {
                $quoteCount++;
                $quotePos++;
            }
            // Must be followed by closing }
            if ($quotePos < $length && $text[$quotePos] === '}') {
                // Generate quotes based on count
                $openQuote = $marker === "'" ? $this->openSingleQuote : $this->openDoubleQuote;
                $closeQuote = $marker === "'" ? $this->closeSingleQuote : $this->closeDoubleQuote;

                // For pairs like {''}, output left + right
                // For single {'}, output apostrophe (always U+2019), {"} output close double
                if ($quoteCount === 1) {
                    $result = $marker === "'" ? $this->apostrophe : $closeQuote;
                } elseif ($quoteCount === 2) {
                    $result = $openQuote . $closeQuote;
                } else {
                    // For more, alternate open/close
                    $result = '';
                    for ($i = 0; $i < $quoteCount; $i++) {
                        $result .= ($i % 2 === 0) ? $openQuote : $closeQuote;
                    }
                }

                $endsOpen = $quoteCount > 1 && $quoteCount % 2 !== 0;
                if ($marker === "'") {
                    $this->singleQuoteOpen = $endsOpen;
                    $this->singleQuoteOpener = null;
                    $this->demoteSingleQuote = false;
                }
                $this->lastQuoteEnd = $this->textOrigin + $quotePos + 1;
                $this->lastQuoteContext = $marker === '"'
                    ? ($endsOpen ? '“' : '”') : ($endsOpen ? '‘' : '’');

                return [
                    'node' => new Text($result),
                    'pos' => $quotePos + 1,
                ];
            }
        }

        $nodeClass = match ($marker) {
            '=' => Highlight::class,
            '+' => Insert::class,
            '-' => Delete::class,
            '~' => Subscript::class,
            '^' => Superscript::class,
            '_' => Emphasis::class,
            '*' => Strong::class,
            default => null,
        };

        if ($nodeClass === null) {
            return null;
        }

        // Find closing: marker}
        // For braced syntax, we allow spaces inside (unlike bare delimiters)
        if (!$this->hasTerminatorAfter($text, $marker . '}', $pos + 2)) {
            return null;
        }
        $searchPos = strpos($text, $marker . '}', $pos + 2);
        if ($searchPos !== false) {
            $content = substr($text, $pos + 2, $searchPos - $pos - 2);
            $node = new $nodeClass();
            $this->parseInlines($node, $content, $this->textOrigin + $pos + 2);

            $endPos = $searchPos + 2;

            // Check for trailing attributes: {=text=}{.class}{.more}
            // But NOT if it's another braced inline like {=text=}{=more=}
            if ($endPos < $length && $text[$endPos] === '{') {
                $nextChar = $text[$endPos + 1] ?? '';
                // Braced inline markers that should NOT be treated as attributes
                if (!in_array($nextChar, ['=', '+', '-', '~', '^', '_', '*'], true)) {
                    $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
                }
            }

            return [
                'node' => $node,
                'pos' => $endPos,
            ];
        }

        return null;
    }

    protected function emitSmartQuote(
        Node $parent,
        string &$buffer,
        string $text,
        int $pos,
        string $quote,
        ?bool $forced = null,
    ): void {
        $prev = '';
        if ($pos > 0) {
            $tail = substr($text, max(0, $pos - 4), min(4, $pos));
            $tail = preg_replace('/^[\\x80-\\xBF]+/', '', $tail) ?? '';
            preg_match('/.\z/us', $tail, $match);
            $prev = $match[0] ?? $text[$pos - 1];
        }
        if ($this->lastQuoteEnd === $this->textOrigin + $pos) {
            $prev = $this->lastQuoteContext;
        }
        preg_match('/\G./us', $text, $match, 0, $pos + 1);
        $next = $match[0] ?? '';
        $space = static fn (string $c): bool => $c !== '' && str_contains(" \t\n\r\u{00A0}", $c);
        $alnum = static fn (string $c): bool => preg_match('/^[\p{L}\p{N}]$/u', $c) === 1;
        $opening = $prev === '' || $space($prev) || str_contains('([{-–—/=:', $prev)
            || $prev === '“' || $prev === '‘';
        if (
            in_array($prev, ['-', '–', '—'], true)
            && ($next === '' || $space($next) || str_contains("\"'.,;:!?)]", $next))
        ) {
            $opening = false;
        }
        $opening = $forced ?? $opening;
        $apostrophe = false;
        $closesSpan = $forced === false || !$space($prev);
        $glyph = $opening ? $this->openDoubleQuote : $this->closeDoubleQuote;
        if ($quote === "'") {
            $apostrophe = $forced === null && (ctype_digit($next) || (!$opening && $alnum($next)));
            if ($opening && $forced === null && $alnum($next)) {
                preg_match('/\G\p{L}+/u', $text, $wordMatch, 0, $pos + 1);
                $word = $wordMatch[0] ?? '';
                $end = $pos + 1 + strlen($word);
                preg_match('/\G./us', $text, $afterMatch, 0, min(strlen($text), $end + 1));
                $quoted = ($text[$end] ?? '') === "'" && !$alnum($afterMatch[0] ?? '');
                $elision = in_array(strtolower($word), [
                    'tis', 'tisn', 'twas', 'twasn', 'twere', 'twill', 'twould',
                    'em', 'cause', 'til', 'n', 'bout',
                ], true) && !$quoted;
                // The official doubled-quote shape keeps its nested opener.
                $doubled = $pos > 0 && $text[$pos - 1] === "'" && $prev === '‘';
                $apostrophe = $apostrophe || $elision || ($this->singleQuoteOpen && !$doubled);
            }
            $glyph = $apostrophe ? $this->apostrophe
                : ($opening ? $this->openSingleQuote : $this->closeSingleQuote);
            if (!$apostrophe && !$opening && $closesSpan) {
                $this->singleQuoteOpen = false;
                $this->singleQuoteOpener = null;
            }
        }
        $this->flushText($parent, $buffer, true);
        $buffer = '';
        $node = new Text($glyph);
        $parent->appendChild($node);
        if ($this->blockParser->getAbbreviations() !== []) {
            $this->abbreviationSegments[$node] = $this->abbreviationRun;
        }
        if ($quote === "'" && $opening && !$apostrophe && !$this->singleQuoteOpen) {
            $this->singleQuoteOpen = true;
            $this->singleQuoteOpener = $node;
            $this->demoteSingleQuote = $forced === null && $alnum($next)
                && $this->textOrigin + $pos !== 0 && $prev !== '“';
        }
        $this->lastQuoteEnd = $this->textOrigin + $pos + 1;
        $this->lastQuoteContext = $opening && ($quote === '"' || !$apostrophe)
            ? ($quote === '"' ? '“' : '‘') : ($quote === '"' ? '”' : '’');
    }

    /**
     * @return array{text: string, pos: int}
     */
    protected function parseSmartDash(string $text, int $pos): array
    {
        $length = strlen($text);
        $dashCount = 0;

        while ($pos + $dashCount < $length && $text[$pos + $dashCount] === '-') {
            $dashCount++;
        }

        // Convert dashes according to djot algorithm:
        // 1. If divisible by 3, all em-dashes
        // 2. If divisible by 2, all en-dashes
        // 3. Otherwise, em-dashes first, then en-dashes, with minimal en-dashes
        $emDash = "\u{2014}"; // —
        $enDash = "\u{2013}"; // –

        if ($dashCount === 1) {
            return [
                'text' => '-',
                'pos' => $pos + $dashCount,
            ];
        }

        if ($dashCount % 3 === 0) {
            // All em-dashes
            return [
                'text' => str_repeat($emDash, (int)($dashCount / 3)),
                'pos' => $pos + $dashCount,
            ];
        }

        if ($dashCount % 2 === 0) {
            // All en-dashes
            return [
                'text' => str_repeat($enDash, (int)($dashCount / 2)),
                'pos' => $pos + $dashCount,
            ];
        }

        // Mixed: find combination emCount*3 + enCount*2 = dashCount with minimal enCount
        // Start with max em-dashes and find the remainder for en-dashes
        $emCount = (int)($dashCount / 3);
        $remainder = $dashCount % 3;

        // remainder can be 1 or 2 (not 0, we handled that above)
        if ($remainder === 1) {
            // Can't make 1 with en-dashes, so trade one em-dash for two en-dashes
            // 3 + 1 = 4 → 2*2 = 4 ✓
            $emCount--;
            $enCount = 2;
        } else {
            // remainder is 2, which is one en-dash
            $enCount = 1;
        }

        return [
            'text' => str_repeat($emDash, $emCount) . str_repeat($enDash, $enCount),
            'pos' => $pos + $dashCount,
        ];
    }

    /**
     * Parse inline attributes that apply to preceding word: word{.class}
     *
     * @return array{textBuffer: string, pos: int}|null
     */
    protected function parseInlineAttributes(string $text, int $pos, string $textBuffer, Node $parent): ?array
    {
        $length = strlen($text);

        // Find the closing brace, handling quoted strings
        $attrEnd = $this->findAttributeEnd($text, $pos);
        if ($attrEnd === null || ($this->attributeScanText === $text && isset($this->invalidAttributeStarts[$pos]))) {
            return null;
        }

        $rawAttrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);
        $attrStr = trim($rawAttrStr);
        if (
            $rawAttrStr !== ltrim($rawAttrStr) && $attrStr !== ''
            && !preg_match('/^[.#%]|^[a-zA-Z][a-zA-Z0-9_:-]*=/', $attrStr)
        ) {
            return null;
        }

        // Check if this looks like valid attributes (starts with ., #, % comment, or key=)
        // Exclude _ * = + - ~ ^ which are braced inline markers
        if ($attrStr !== '' && !preg_match('/^[.#a-zA-Z%]/', $attrStr)) {
            return null;
        }

        // An invalid character anywhere in the spec invalidates it entirely;
        // the braces then stay literal text
        if (!AttributeParser::isValid($attrStr)) {
            return null;
        }

        // Remove comments from attributes: % ... % or % to end
        $attrStr = $this->removeAttributeComments($attrStr);

        $wordBoundary = $pos === $this->attributeWordBoundaryEnd
            ? $this->attributeWordBeforeEmpty : $this->attributeWordBoundary;

        // Empty specifiers end the word but allow adjacent attributes to attach.
        if (trim($attrStr) === '') {
            $this->flushText($parent, $textBuffer);
            $textBuffer = '';
            $this->attributeWordBeforeEmpty = $wordBoundary;
            $this->attributeWordBoundary = count($parent->getChildren());
            $this->attributeWordBoundaryEnd = $attrEnd + 1;

            return [
                'textBuffer' => $textBuffer,
                'pos' => $attrEnd + 1,
            ];
        }

        $wordStart = $this->attributeWordStart($textBuffer);
        $wordNodes = [];
        if ($wordStart < strlen($textBuffer)) {
            $wordNodes[] = new Text(substr($textBuffer, $wordStart));
        }
        $textBuffer = substr($textBuffer, 0, $wordStart);

        // Failed delimiters and escapes can flush parts of one ordinary word.
        $removeCount = 0;
        if ($wordStart === 0) {
            $children = $parent->getChildren();
            $lastIndex = count($children) - 1;
            for ($index = $lastIndex; $index >= $wordBoundary; $index--) {
                $child = $children[$index];
                if (!($child instanceof Text || $child instanceof EscapedText)) {
                    break;
                }
                $content = $child->getContent();
                $start = $child instanceof EscapedText ? 0 : $this->attributeWordStart($content);
                if ($start === strlen($content)) {
                    break;
                }
                if ($start > 0) {
                    $wordNodes[] = new Text(substr($content, $start));
                    $child->setContent(substr($content, 0, $start));

                    break;
                }
                $removeCount++;
                $wordNodes[] = $child;
            }
            unset($children);
            for ($offset = 0; $offset < $removeCount; $offset++) {
                $parent->removeChildAt($lastIndex - $offset);
            }
        }

        // If no preceding word, attributes don't attach to anything
        // But they still consume the braces (according to the spec)
        if ($wordNodes === []) {
            if ($parent instanceof Paragraph && $parent->getChildren() === [] && $textBuffer === '') {
                $location = $this->warningLocation($pos, substr($text, $pos, $attrEnd - $pos + 1));
                $this->blockParser->addUnattachedAttributeWarning($location['line'], $location['column'], true);
            }
            // Flush text and skip attributes - they produce nothing
            $this->flushText($parent, $textBuffer);

            return [
                'textBuffer' => '',
                'pos' => $attrEnd + 1,
            ];
        }

        // Flush any text before the word
        $this->flushText($parent, $textBuffer);

        // Create a span with the word and apply attributes
        $span = new Span();
        foreach (array_reverse($wordNodes) as $wordNode) {
            $span->appendChild($wordNode);
        }
        $this->applyAttributesToNode($span, $attrStr);
        $endPos = $this->applyConsecutiveAttributes($span, $text, $attrEnd + 1);
        $parent->appendChild($span);

        return [
            'textBuffer' => '',
            'pos' => $endPos,
        ];
    }

    protected function attributeWordStart(string $text): int
    {
        $start = strlen($text);
        $quotes = $this->getConfiguredQuoteStrings();
        while ($start > 0) {
            if ($start >= 3 && substr_compare($text, "\u{E000}", $start - 3, 3) === 0) {
                break;
            }
            if (str_contains(" \t\n\r\v\f", $text[$start - 1])) {
                break;
            }
            foreach ($quotes as $quote) {
                $length = strlen($quote);
                if ($length > 0 && $start >= $length && substr_compare($text, $quote, $start - $length, $length) === 0) {
                    break 2;
                }
            }
            $start--;
        }

        return $start;
    }

    /**
     * Get all unique configured quote strings for word boundary detection
     *
     * @return array<string>
     */
    protected function getConfiguredQuoteStrings(): array
    {
        return array_unique([
            $this->openDoubleQuote,
            $this->closeDoubleQuote,
            $this->openSingleQuote,
            $this->closeSingleQuote,
            $this->apostrophe,
        ]);
    }

    private function findAutolinkCloser(string $text, int $pos): ?int
    {
        if (!$this->hasTerminatorAfter($text, '>', $pos + 1)) {
            return null;
        }
        $end = $pos + 1 + strcspn($text, " \t\n\r\v\f<>", $pos + 1);
        if (($text[$end] ?? '') === '>') {
            return $end;
        }
        // The existing URL regex accepts a final newline before its end anchor.
        if (($text[$end] ?? '') === "\n" && ($text[$end + 1] ?? '') === '>') {
            return $end + 1;
        }

        // FILTER_VALIDATE_EMAIL rejects addresses longer than 320 bytes.
        // Keep quoted local parts, including dot-separated quoted segments.
        $emailWindow = substr($text, $pos + 1, 321);
        $emailEnd = strpos($emailWindow, '>');

        return $emailEnd === false ? null : $pos + 1 + $emailEnd;
    }

    private function hasTerminatorAfter(string $text, string $marker, int $pos): bool
    {
        if ($this->terminatorText !== $text) {
            $this->terminatorText = $text;
            $this->lastTerminators = [];
        }
        if (!array_key_exists($marker, $this->lastTerminators)) {
            $this->lastTerminators[$marker] = strrpos($text, $marker);
        }
        $last = $this->lastTerminators[$marker];

        return $last !== false && $last >= $pos;
    }

    /**
     * Find the end of an attribute block, handling quoted strings
     */
    protected function findAttributeEnd(string $text, int $pos): ?int
    {
        if ($this->attributeScanText !== $text) {
            $this->attributeScanText = $text;
            $this->attributeEnds = [];
            $this->invalidAttributeStarts = [];
        }
        if (!$this->hasTerminatorAfter($text, '}', $pos + 1)) {
            return null;
        }
        if (array_key_exists($pos, $this->attributeEnds)) {
            return $this->attributeEnds[$pos];
        }

        return $this->scanAttributeEnd($text, $pos);
    }

    /**
     * Record nested matches and failed starts outside quoted or escaped text.
     */
    protected function scanAttributeEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);
        $i = $pos + 1;
        $inQuote = null;
        $openers = [$pos];
        $inComment = false;

        while ($i < $length) {
            $char = $text[$i];
            if ($inQuote === null && $char === '%') {
                $inComment = !$inComment;
                $i++;

                continue;
            }
            if ($inComment && $char !== '}') {
                $i++;

                continue;
            }

            // Handle escape sequences
            if ($char === '\\' && $i + 1 < $length) {
                $i += 2;

                continue;
            }

            // Handle quotes
            if ($inQuote !== null) {
                if ($char === $inQuote) {
                    $inQuote = null;
                }
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inQuote = $char;
                $i++;

                continue;
            }

            if ($char === '{') {
                $opener = $openers[count($openers) - 1];
                // A nested brace outside quotes and comments invalidates the attribute.
                $this->invalidAttributeStarts[$opener] = true;
                $openers[] = $i;
            } elseif ($char === '}') {
                $opener = array_pop($openers);
                $this->attributeEnds[$opener] = $i;
                if ($openers === []) {
                    return $i;
                }
            }

            $i++;
        }

        foreach ($openers as $opener) {
            $this->attributeEnds[$opener] = null;
        }

        return null;
    }

    /**
     * Find the end of a code span starting at $pos
     *
     * @return int|null Position after the closing backticks, or null if not found
     */
    protected function findCodeSpanEnd(string $text, int $pos): ?int
    {
        $width = strspn($text, '`', $pos);
        if ($width === 0) {
            return null;
        }
        $contentStart = $pos + $width;
        if ($width === 1) {
            $start = strpos($text, '`', $contentStart);

            return $start === false ? null : $start + strspn($text, '`', $start);
        }
        if ($this->backtickScanText !== $text || $this->backtickRuns === null) {
            $this->backtickScanText = $text;
            $this->backtickRuns = new BacktickRunIndex($text);
        }

        // Preserve the lookahead's minimum-width rule for longer closing runs.
        return $this->backtickRuns->findCloser($contentStart, $width);
    }

    /**
     * Find the end of a link destination starting at $pos (which points to '(').
     *
     * This is a simpler version that only handles the destination part,
     * not the full link syntax. Used to skip over URL content when scanning
     * for emphasis closers.
     *
     * @return int|null Position after the closing ), or null if not found
     */
    protected function findLinkDestinationEnd(string $text, int $pos): ?int
    {
        return $this->cachedLinkDestinationEnd($text, $pos);
    }

    private function cachedLinkDestinationEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);
        if ($pos >= $length || $text[$pos] !== '(') {
            return null;
        }
        if ($this->destinationScanText !== $text) {
            $this->destinationScanText = $text;
            $this->destinationEnds = [];
        }
        if (!$this->hasTerminatorAfter($text, ')', $pos + 1)) {
            return null;
        }
        if (array_key_exists($pos, $this->destinationEnds)) {
            return $this->destinationEnds[$pos];
        }
        $openers = [$pos];
        for ($i = $pos + 1; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '(') {
                $openers[] = $i;
            } elseif ($char === ')') {
                $opener = array_pop($openers);
                $this->destinationEnds[$opener] = $i + 1;
                if ($openers === []) {
                    return $i + 1;
                }
            } elseif ($char === '\\' && $i + 1 < $length) {
                $i++;
            }
        }
        foreach ($openers as $opener) {
            $this->destinationEnds[$opener] = null;
        }

        return null;
    }

    /**
     * Find the end of an autolink starting at $pos
     *
     * @return int|null Position after the closing >, or null if not a valid autolink
     */
    protected function findAutolinkEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);

        if ($pos >= $length || $text[$pos] !== '<') {
            return null;
        }

        $end = $this->findAutolinkCloser($text, $pos);
        if ($end === null) {
            return null;
        }

        $content = substr($text, $pos + 1, $end - $pos - 1);

        // Check if it's a valid URL autolink
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s<>]*$/', $content)) {
            return $end + 1;
        }

        // Check if it's a valid email autolink
        if (filter_var($content, FILTER_VALIDATE_EMAIL)) {
            return $end + 1;
        }

        return null;
    }

    /**
     * Remove comments from attribute string: % ... % or % to end
     */
    protected function removeAttributeComments(string $attrStr): string
    {
        return AttributeParser::removeComments($attrStr);
    }

    /**
     * Apply attributes from a string to a node
     */
    protected function applyAttributesToNode(Node $node, string $attrStr): void
    {
        AttributeParser::applyToNode($node, $attrStr);
    }

    /**
     * Apply all consecutive attribute blocks to a node
     *
     * Per djot spec, multiple consecutive attribute blocks like {.foo}{.bar}
     * should merge. Classes combine, later values override earlier ones.
     *
     * @return int The final position after all attribute blocks
     */

    /**
     * Whether a `{...}` payload is a valid attribute block.
     *
     * Valid means it yields at least one attribute under the attribute grammar,
     * or it is empty/whitespace/comment-only - a valid empty block kept as a
     * bare <span> so a default-attribute extension can target it. A block
     * carrying unrecognized content (`{???}`, `{=y=}`) is not an attribute
     * block, so the surrounding bracketed run stays literal text.
     */
    protected function isValidAttrPayload(string $attrStr): bool
    {
        // An invalid character anywhere in the spec invalidates it entirely
        // (e.g. {#a<b}); the block then stays literal text.
        if (!AttributeParser::isValid($attrStr)) {
            return false;
        }

        if (AttributeParser::parse($attrStr) !== []) {
            return true;
        }

        return trim($this->removeAttributeComments($attrStr)) === '';
    }

    protected function applyConsecutiveAttributes(Node $node, string $text, int $startPos): int
    {
        $length = strlen($text);
        $pos = $startPos;

        while ($pos < $length && $text[$pos] === '{') {
            $attrEnd = $this->findAttributeEnd($text, $pos);
            if ($attrEnd === null || ($this::class === self::class && $this->attributeScanText === $text && isset($this->invalidAttributeStarts[$pos]))) {
                break;
            }

            $attrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);
            // Stop at the first block that is not a valid attribute block; it
            // (and anything after it) stays literal instead of being silently
            // consumed, e.g. the {???} in [x]{.a}{???}.
            if (!$this->isValidAttrPayload($attrStr)) {
                break;
            }

            $this->applyAttributesToNode($node, $attrStr);
            $pos = $attrEnd + 1;
        }

        return $pos;
    }

    /**
     * Parse footnote reference [^label]
     *
     * @return array{node: \Djot\Node\Inline\FootnoteRef, pos: int}|null
     */
    protected function parseFootnoteRef(string $text, int $pos): ?array
    {
        // A footnote REFERENCE may cross a line ending, like a reference link.
        // The label is normalized before lookup, so a reference a text editor
        // has wrapped still binds to the one-line definition. The definition
        // marker itself stays single-line; that half is the block parser's.
        if (!preg_match('/\G\[\^([^\]]+)\]/', $text, $matches, 0, $pos)) {
            return null;
        }

        $label = StringUtil::normalizeLabel($matches[1]);

        // Warn if footnote is not defined
        if (!$this->blockParser->hasFootnote($label)) {
            $location = $this->warningLocation($pos, $matches[0]);
            $this->blockParser->addUndefinedFootnoteWarning($label, $location['line'], $location['column'], true);
        }

        return [
            'node' => new FootnoteRef($label),
            'pos' => $pos + strlen($matches[0]),
        ];
    }

    /**
     * Parse math: $`...` for inline, $$`...` for display
     *
     * @return array{node: \Djot\Node\Inline\Math, pos: int}|null
     */
    protected function parseMath(string $text, int $pos): ?array
    {
        $length = strlen($text);

        // Check for display math $$
        $display = ($text[$pos] ?? '') === '$' && ($text[$pos + 1] ?? '') === '$';
        $startPos = $pos + ($display ? 2 : 1);

        // Must be followed by backtick
        if ($startPos >= $length || $text[$startPos] !== '`') {
            return null;
        }

        // Count opening backticks
        $backtickCount = 0;
        while ($startPos + $backtickCount < $length && $text[$startPos + $backtickCount] === '`') {
            $backtickCount++;
        }

        $contentStart = $startPos + $backtickCount;

        // Find closing backticks
        $closingBackticks = str_repeat('`', $backtickCount);
        $closePos = strpos($text, $closingBackticks, $contentStart);

        if ($closePos === false) {
            return null;
        }

        $content = substr($text, $contentStart, $closePos - $contentStart);

        $math = new Math($content, $display);
        $endPos = $this->applyConsecutiveAttributes($math, $text, $closePos + $backtickCount);

        return [
            'node' => $math,
            'pos' => $endPos,
        ];
    }

    /**
     * Parse symbol :name:
     *
     * @return array{node: \Djot\Node\Inline\Symbol, pos: int}|null
     */
    protected function parseSymbol(string $text, int $pos): ?array
    {
        // Match :word: - \G anchors at offset position, avoiding extra strpos check
        if (!preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_-]*):/', $text, $matches, 0, $pos)) {
            return null;
        }

        $symbol = new Symbol($matches[1]);
        $endPos = $pos + strlen($matches[0]);
        $length = strlen($text);

        // Check for trailing attributes: :symbol:{.class}{.more}
        if ($endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($symbol, $text, $endPos);
        }

        return [
            'node' => $symbol,
            'pos' => $endPos,
        ];
    }

    /**
     * Normalize a reference label for lookup.
     *
     * - Strip inline formatting markers (_, *, etc.)
     * - Collapse whitespace (including newlines) to single spaces
     * - Trim leading/trailing whitespace
     */
    protected function normalizeReferenceLabel(string $label): string
    {
        // Strip inline formatting markers: _ * ~ ^ + = { } ` [ ]
        // But keep the content between them
        $label = preg_replace('/[_*~^+={}`\[\]]/', '', $label) ?? $label;

        // Normalize whitespace: collapse multiple spaces/newlines to single space
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        // Trim
        return trim($label);
    }
}
