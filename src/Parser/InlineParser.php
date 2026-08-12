<?php

declare(strict_types=1);

namespace Djot\Parser;

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
use Djot\Util\StringUtil;

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

    /**
     * Memoized per-text check: does the text contain any link/span trigger
     * (`](`, `][`, `]{`)? Lets parseLink skip the O(n) bracket-depth scan for
     * trigger-free text, so a deeply nested `[[[[x]]]]` run stays linear.
     */
    protected ?string $linkTriggerText = null;

    protected bool $linkTriggerPresent = false;

    /**
     * Cached abbreviation keys for the current pattern
     *
     * @var array<string, string>|null
     */
    protected ?array $cachedAbbreviations = null;

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
     * Cached single quote opener→closer matches for the current text block.
     *
     * Pre-computed once per parseInlines() call to avoid O(n²) scanning.
     * Keys are opener positions, values are closer positions.
     *
     * @var array<int, int>|null
     */
    protected ?array $singleQuoteMatchCache = null;

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
     */
    public function parse(Node $parent, string $text, int $sourceLine = 0): void
    {
        $this->delimiterStack = [];
        $this->currentLine = $sourceLine;
        $this->parseInlines($parent, $text);
    }

    protected function parseInlines(Node $parent, string $text): void
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

        $this->inlineDepth++;
        try {
            $this->parseInlinesImpl($parent, $text);
        } finally {
            $this->inlineDepth--;
        }
    }

    protected function parseInlinesImpl(Node $parent, string $text): void
    {
        $length = strlen($text);
        $pos = 0;
        $textBuffer = '';

        // Pre-compute single quote matches to avoid O(n²) complexity
        $this->singleQuoteMatchCache = $this->buildSingleQuoteMatchCache($text);

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
                $this->flushText($parent, $textBuffer);
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
                    $this->flushText($parent, $textBuffer);
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
                        $this->parseInlines($parent, $result['link_text']);
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
            }

            // Smart quotes
            if ($char === '"' || $char === "'") {
                $smartQuote = $this->parseSmartQuote($text, $pos, $char);
                $textBuffer .= $smartQuote;
                $pos++;

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

    protected function flushText(Node $parent, string $text): void
    {
        if ($text === '') {
            return;
        }

        // Check if there are any abbreviations to process
        $abbreviations = $this->blockParser->getAbbreviations();
        if ($abbreviations === []) {
            $parent->appendChild(new Text($text));

            return;
        }

        // Process abbreviations in the text
        $this->flushTextWithAbbreviations($parent, $text, $abbreviations);
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
        // Cache the regex pattern for abbreviations (built once per document)
        if ($this->cachedAbbreviations !== $abbreviations) {
            // Sort abbreviations by length (longest first) to match longer abbreviations first
            $abbrKeys = array_keys($abbreviations);
            usort($abbrKeys, fn ($a, $b) => strlen($b) - strlen($a));

            // Build a regex pattern that matches any abbreviation at word boundaries
            // We need to escape special regex characters in abbreviation keys
            $escapedKeys = array_map(fn ($key) => preg_quote($key, '/'), $abbrKeys);
            $this->abbreviationPattern = '/\b(' . implode('|', $escapedKeys) . ')\b/u';
            $this->cachedAbbreviations = $abbreviations;
        }

        // Split text by abbreviation matches, keeping the delimiters
        // Pattern is guaranteed to be set at this point
        /** @var string $pattern */
        $pattern = $this->abbreviationPattern;
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

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
                while ($searchPos < $length && $text[$searchPos] === '`') {
                    $searchPos++;
                }
                if ($searchPos < $length) {
                    $searchPos++;
                }

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

        return null;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Link|\Djot\Node\Inline\Span, pos: int}|array{unclosed_link: true, link_text: string, continue_pos: int}|null
     */
    protected function parseLink(string $text, int $pos): ?array
    {
        $length = strlen($text);

        // A link/image needs a closing `]`. Without this guard, every `[` runs
        // the char-by-char depth scan below to end-of-text, so an unbalanced run
        // like `[[[[...` is O(n^2). strpos is a C-level memchr that short-circuits
        // when no `]` follows.
        if (strpos($text, ']', $pos + 1) === false) {
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

        // Find closing ]
        $bracketDepth = 1;
        $textEnd = $pos + 1;
        while ($textEnd < $length && $bracketDepth > 0) {
            if ($text[$textEnd] === '[') {
                $bracketDepth++;
            } elseif ($text[$textEnd] === ']') {
                $bracketDepth--;
            } elseif ($text[$textEnd] === '\\' && $textEnd + 1 < $length) {
                $textEnd++; // Skip escaped char
            }
            if ($bracketDepth > 0) {
                $textEnd++;
            }
        }

        if ($bracketDepth !== 0) {
            return null;
        }

        $linkText = substr($text, $pos + 1, $textEnd - $pos - 1);
        $afterBracket = $textEnd + 1;

        // Inline link: [text](url) or [text](url){.class}
        if ($afterBracket < $length && $text[$afterBracket] === '(') {
            $urlStart = $afterBracket + 1;
            $parenDepth = 1;
            $urlEnd = $urlStart;

            while ($urlEnd < $length && $parenDepth > 0) {
                if ($text[$urlEnd] === '(') {
                    $parenDepth++;
                } elseif ($text[$urlEnd] === ')') {
                    $parenDepth--;
                } elseif ($text[$urlEnd] === '\\' && $urlEnd + 1 < $length) {
                    $urlEnd++;
                }
                if ($parenDepth > 0) {
                    $urlEnd++;
                }
            }

            if ($parenDepth === 0) {
                $url = substr($text, $urlStart, $urlEnd - $urlStart);
                // Remove newlines from URL (soft breaks are ignored in URLs)
                $url = str_replace(["\r\n", "\r", "\n"], '', $url);
                $url = trim($url);
                // Process escape sequences in URL (e.g., \* -> *)
                $url = preg_replace('/\\\\(.)/', '$1', $url) ?? $url;
                $link = new Link($url);
                $this->parseInlines($link, $linkText);

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

                $refDef = $this->blockParser->getReference($ref);
                if ($refDef !== null) {
                    // Track reference usage for validation
                    $this->blockParser->markReferenceUsed($ref, $this->currentLine);

                    $link = new Link($refDef->url);
                    // Store reference info for round-trip support
                    $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                    $this->parseInlines($link, $linkText);

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
                $this->blockParser->addUndefinedReferenceWarning($ref, $this->currentLine, $pos + 1);

                $link = new Link(null);
                // Store reference info for round-trip support
                $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                $this->parseInlines($link, $linkText);

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
            if ($attrEnd !== null) {
                $attrStr = substr($text, $afterBracket + 1, $attrEnd - $afterBracket - 1);
                if ($this->isValidAttrPayload($attrStr)) {
                    $span = new Span();
                    // Apply the gating block, then absorb any further
                    // consecutive attribute blocks.
                    $this->applyAttributesToNode($span, $attrStr);
                    $endPos = $this->applyConsecutiveAttributes($span, $text, $attrEnd + 1);
                    $this->parseInlines($span, $linkText);

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
        $end = strpos($text, '>', $pos);
        if ($end === false) {
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
        $openingRunEnd = $pos + strspn($text, $delimiter, $pos);
        // If the opening run extends to end of string (all delimiters), no valid emphasis
        if ($openingRunEnd >= $length) {
            return null;
        }
        // A closer needs the delimiter to appear again after the opening run.
        // Without this, every opener scans the whole tail looking for a close
        // that is not there (the other half of the O(n^2)).
        if (strpos($text, $delimiter, $openingRunEnd) === false) {
            return null;
        }
        // Skip the opening run to look for content and closing run
        $searchPos = $openingRunEnd;
        while ($searchPos < $length) {
            $char = $text[$searchPos];

            // Skip over attribute blocks {....} respecting quotes
            if ($char === '{') {
                $attrEnd = $this->findAttributeEnd($text, $searchPos);
                if ($attrEnd !== null) {
                    $searchPos = $attrEnd + 1;

                    continue;
                }
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

            // Check for closing delimiter
            if ($char === $delimiter) {
                // Check if this can be a closer (not preceded by whitespace)
                $beforeClose = $searchPos > 0 ? $text[$searchPos - 1] : ' ';
                if (!ctype_space($beforeClose)) {
                    // A braced closer (like _} or *}) can only close a braced opener
                    // Since we're looking for a non-braced closer, skip if followed by }
                    $afterClose = $text[$searchPos + 1] ?? '';
                    if ($afterClose === '}') {
                        $searchPos++;

                        continue;
                    }
                    // For runs of delimiters like *****, we want to find the LAST one
                    // to match our opener (outer-to-outer matching)
                    // Find the end of this run of closers
                    $runEnd = $searchPos;
                    while ($runEnd + 1 < $length && $text[$runEnd + 1] === $delimiter) {
                        $runEnd++;
                    }
                    // Use the last delimiter in this closing run
                    $actualClose = $runEnd;

                    // Check content isn't empty
                    $content = substr($text, $pos + 1, $actualClose - $pos - 1);
                    if ($content === '') {
                        $searchPos = $runEnd + 1;

                        continue;
                    }

                    $node = new $nodeClass();
                    $this->parseInlines($node, $content);

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
            }

            $searchPos++;
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
        $searchPos = $pos + 2;
        while ($searchPos < $length - 1) {
            if ($text[$searchPos] === $marker && $text[$searchPos + 1] === '}') {
                $content = substr($text, $pos + 2, $searchPos - $pos - 2);
                $node = new $nodeClass();
                $this->parseInlines($node, $content);

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
            $searchPos++;
        }

        return null;
    }

    protected function parseSmartQuote(string $text, int $pos, string $quote): string
    {
        $prevChar = $pos > 0 ? $text[$pos - 1] : ' ';
        $nextChar = $text[$pos + 1] ?? ' ';

        // Quote immediately after = is always an opener (attribute value start)
        if ($prevChar === '=') {
            return $quote === '"' ? $this->openDoubleQuote : $this->openSingleQuote;
        }

        // = acts as word boundary for quotes (e.g., key="value" in attributes)
        $prevIsSpace = ctype_space($prevChar) || $pos === 0;
        $nextIsSpace = ctype_space($nextChar);

        // A quote following another quote should also be considered as having "space" before
        // For example, "'Hello" at line start should produce "'Hello
        $prevIsQuoteOpener = ($prevChar === '"' || $prevChar === "'") && $prevIsSpace === false;
        if ($prevIsQuoteOpener) {
            if ($pos === 1) {
                // Previous quote was at position 0 (start of string)
                $prevIsSpace = true;
            } elseif ($pos >= 2) {
                // Check if the preceding quote was in an opener position
                $prevPrevChar = $text[$pos - 2];
                if (ctype_space($prevPrevChar)) {
                    $prevIsSpace = true;
                }
            }
        }

        // Single quote before digit is always apostrophe (e.g., '70s)
        if ($quote === "'" && ctype_digit($nextChar)) {
            return $this->apostrophe;
        }

        // A quote after ] or ) cannot be an opener
        if ($prevChar === ']' || $prevChar === ')') {
            return $quote === '"' ? $this->closeDoubleQuote : $this->closeSingleQuote;
        }

        if ($quote === '"') {
            // Opening if preceded by space or start, closing otherwise
            return $prevIsSpace && !$nextIsSpace ? $this->openDoubleQuote : $this->closeDoubleQuote;
        }

        // For single quotes, use pre-computed cache to determine if this could be an opener
        // A potential opener at position can only be an opener if there's a matching closer later
        if ($prevIsSpace && !$nextIsSpace) {
            // This could be an opener - check the pre-computed cache
            if (isset($this->singleQuoteMatchCache[$pos])) {
                return $this->openSingleQuote;
            }

            // No matching closer found, treat as apostrophe
            return $this->apostrophe;
        }

        // Check if this is mid-word (next char is a word character) — apostrophe
        if (preg_match('/\w/u', $nextChar)) {
            return $this->apostrophe;
        }

        // Closing single quote
        return $this->closeSingleQuote;
    }

    /**
     * Build a cache of all single quote opener→closer matches for the text.
     *
     * This is called once per parseInlines() to avoid O(n²) complexity
     * when processing many single quotes.
     *
     * @return array<int, int> Map of opener position to closer position
     */
    protected function buildSingleQuoteMatchCache(string $text): array
    {
        // No single quotes means nothing to match; skip the full byte scan.
        if (!str_contains($text, "'")) {
            return [];
        }

        $length = strlen($text);
        $matched = [];
        $openerStack = [];

        // Single forward pass: classify each quote and pair a closer with the
        // innermost still-open opener via a stack. The stack top is always the
        // largest-index unmatched opener seen so far, so popping it reproduces
        // the former "nearest preceding unmatched opener" pairing in O(n)
        // instead of the previous O(n²) closer-by-opener scan.
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== "'") {
                continue;
            }

            $prevChar = $i > 0 ? $text[$i - 1] : ' ';
            $nextChar = $text[$i + 1] ?? ' ';

            // Skip quotes before digits (always apostrophe)
            if (ctype_digit($nextChar)) {
                continue;
            }

            // Skip quotes after ] or )
            if ($prevChar === ']' || $prevChar === ')') {
                continue;
            }

            $prevIsSpace = ctype_space($prevChar) || $i === 0;
            $nextIsSpace = ctype_space($nextChar);
            $nextIsSpaceOrPunct = $nextIsSpace || $i === $length - 1
                || preg_match('/^[\p{P}\p{S}]/u', $nextChar) === 1;

            // A quote following another quote at line start should be considered opener
            $prevIsQuoteOpener = ($prevChar === '"' || $prevChar === "'");
            if ($prevIsQuoteOpener && !$prevIsSpace) {
                // $i >= 2 here because: $i=0 means prevChar=' ', so $prevIsQuoteOpener=false;
                // $i=1 means prevChar=$text[0], if quote, then $prevIsSpace=true (start of string)
                if ($i === 1) {
                    $prevIsSpace = true;
                } elseif (ctype_space($text[$i - 2])) {
                    $prevIsSpace = true;
                }
            }

            if ($prevIsSpace && !$nextIsSpace) {
                // Potential opener - push onto the stack of open quotes
                $openerStack[] = $i;
            } elseif (!$prevIsSpace && $nextIsSpaceOrPunct && $openerStack) {
                // Potential closer - pair with the innermost unmatched opener
                $matched[array_pop($openerStack)] = $i;
            }
            // Mid-word quotes are skipped (apostrophes)
        }

        return $matched;
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
        if ($attrEnd === null) {
            return null;
        }

        $attrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);

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

        // Empty attributes: hi{} - just skip them
        if (trim($attrStr) === '') {
            return [
                'textBuffer' => $textBuffer,
                'pos' => $attrEnd + 1,
            ];
        }

        // Find the preceding word to attach attributes to
        // A word is a sequence of alphanumeric characters (plus some allowed chars)
        $precedingWord = '';
        $wordStart = strlen($textBuffer);

        // Scan backwards to find word boundary
        // Per djot spec: a word is a sequence of non-ASCII-whitespace characters
        // However, smart/curly quotes act as word boundaries for attribute attachment
        while ($wordStart > 0) {
            $char = $textBuffer[$wordStart - 1];

            // Stop at ASCII whitespace
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                break;
            }

            // Check for multi-byte configured quote characters
            // These act as word boundaries for attribute attachment
            foreach ($this->getConfiguredQuoteStrings() as $quoteStr) {
                $quoteLen = strlen($quoteStr);
                if ($wordStart >= $quoteLen && substr($textBuffer, $wordStart - $quoteLen, $quoteLen) === $quoteStr) {
                    break 2;
                }
            }

            $wordStart--;
        }

        $textBufferLen = strlen($textBuffer);
        if ($wordStart < $textBufferLen) {
            $precedingWord = substr($textBuffer, $wordStart);
            $textBuffer = substr($textBuffer, 0, $wordStart);
        }

        // If no preceding word, attributes don't attach to anything
        // But they still consume the braces (according to the spec)
        if ($precedingWord === '') {
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
        $span->appendChild(new Text($precedingWord));
        $this->applyAttributesToNode($span, $attrStr);
        $parent->appendChild($span);

        return [
            'textBuffer' => '',
            'pos' => $attrEnd + 1,
        ];
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

    /**
     * Find the end of an attribute block, handling quoted strings
     */
    protected function findAttributeEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);
        $i = $pos + 1;
        $inQuote = null;
        $depth = 1;

        while ($i < $length) {
            $char = $text[$i];

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
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }

            $i++;
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
        $length = strlen($text);

        // Count opening backticks
        $openBackticks = 0;
        while ($pos + $openBackticks < $length && $text[$pos + $openBackticks] === '`') {
            $openBackticks++;
        }

        if ($openBackticks === 0) {
            return null;
        }

        $contentStart = $pos + $openBackticks;

        // Find matching closing backticks
        $closingPattern = str_repeat('`', $openBackticks);
        $searchPos = $contentStart;

        while ($searchPos < $length) {
            $closePos = strpos($text, $closingPattern, $searchPos);
            if ($closePos === false) {
                return null;
            }

            // Make sure we have exactly the right number of backticks (not more)
            $afterClose = $closePos + $openBackticks;
            if ($afterClose >= $length || $text[$afterClose] !== '`') {
                return $afterClose;
            }

            $searchPos = $closePos + 1;
        }

        return null;
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
        $length = strlen($text);
        if ($pos >= $length || $text[$pos] !== '(') {
            return null;
        }

        $parenDepth = 1;
        $i = $pos + 1;

        while ($i < $length && $parenDepth > 0) {
            $char = $text[$i];
            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === '\\' && $i + 1 < $length) {
                // Skip escaped character
                $i++;
            }
            if ($parenDepth > 0) {
                $i++;
            }
        }

        if ($parenDepth !== 0) {
            return null;
        }

        // Return position after the closing )
        return $i + 1;
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

        $end = strpos($text, '>', $pos);
        if ($end === false) {
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
        // Remove % ... % comments
        $result = preg_replace('/%[^%]*%/', '', $attrStr);
        if ($result === null) {
            return $attrStr;
        }

        // Remove % to end of string comments
        $percentPos = strpos($result, '%');
        if ($percentPos !== false) {
            $result = substr($result, 0, $percentPos);
        }

        return $result;
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
            if ($attrEnd === null) {
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
            $this->blockParser->addUndefinedFootnoteWarning($label, $this->currentLine, $pos + 1);
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
        $display = false;
        $dollarCount = 0;
        while ($pos + $dollarCount < $length && $text[$pos + $dollarCount] === '$') {
            $dollarCount++;
        }

        if ($dollarCount >= 2) {
            $display = true;
            $startPos = $pos + 2;
        } else {
            $startPos = $pos + 1;
        }

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

        return [
            'node' => new Math($content, $display),
            'pos' => $closePos + $backtickCount,
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
