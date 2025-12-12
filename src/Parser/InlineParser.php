<?php

declare(strict_types=1);

namespace Djot\Parser;

use Djot\Node\Inline\Abbreviation;
use Djot\Node\Inline\Code;
use Djot\Node\Inline\Delete;
use Djot\Node\Inline\Emphasis;
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

/**
 * Inline parser for Djot
 *
 * Handles emphasis, strong, links, images, code spans, etc.
 */
class InlineParser
{
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
     * Cached abbreviation regex pattern (built once per document)
     */
    protected ?string $abbreviationPattern = null;

    /**
     * Cached abbreviation keys for the current pattern
     *
     * @var array<string, string>|null
     */
    protected ?array $cachedAbbreviations = null;

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
        $length = strlen($text);
        $pos = 0;
        $textBuffer = '';

        while ($pos < $length) {
            $char = $text[$pos];
            $nextChar = $text[$pos + 1] ?? '';

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
                    $textBuffer .= $escaped;
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
                    if (isset($result['node'])) {
                        $parent->appendChild($result['node']);
                        $pos = $result['pos'];

                        continue;
                    }
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
        if (!$this->customPatterns) {
            return null;
        }

        $remaining = substr($text, $pos);

        foreach ($this->customPatterns as $pattern => $callback) {
            // Anchor pattern to start
            $anchoredPattern = '/\A' . substr($pattern, 1, -1) . '/';

            if (preg_match($anchoredPattern, $remaining, $matches)) {
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
            $isRawInline = $afterClose < $length && $text[$afterClose] === '{'
                && $afterClose + 1 < $length && $text[$afterClose + 1] === '=';
            if ($isRawInline) {
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
                    // Mixed attributes like {=html #id} - treat attribute block as literal
                }
            }

            return [
                'node' => new Code($content),
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

                $endPos = $urlEnd + 1;

                // Check for attributes after link: [text](url){.class}
                if ($endPos < $length && $text[$endPos] === '{') {
                    $attrEnd = strpos($text, '}', $endPos);
                    if ($attrEnd !== false) {
                        $attrStr = substr($text, $endPos + 1, $attrEnd - $endPos - 1);
                        $this->applyAttributesToNode($link, $attrStr);
                        $endPos = $attrEnd + 1;
                    }
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

                $refDef = $this->blockParser->getReference($ref);
                if ($refDef !== null) {
                    $link = new Link($refDef->url);
                    $this->parseInlines($link, $linkText);

                    // Apply attributes from reference definition first
                    foreach ($refDef->attributes as $key => $value) {
                        if ($key === 'class') {
                            $link->addClass((string)$value);
                        } else {
                            $link->setAttribute($key, $value);
                        }
                    }

                    $endPos = $refEnd + 1;

                    // Check for attributes after reference link (override definition attrs)
                    if ($endPos < $length && $text[$endPos] === '{') {
                        $attrEnd = strpos($text, '}', $endPos);
                        if ($attrEnd !== false) {
                            $attrStr = substr($text, $endPos + 1, $attrEnd - $endPos - 1);
                            $this->applyAttributesToNode($link, $attrStr);
                            $endPos = $attrEnd + 1;
                        }
                    }

                    return [
                        'node' => $link,
                        'pos' => $endPos,
                    ];
                }

                // Reference not found - create link without href (null) and warn
                $this->blockParser->addUndefinedReferenceWarning($ref, $this->currentLine, $pos + 1);

                $link = new Link(null);
                $this->parseInlines($link, $linkText);

                $endPos = $refEnd + 1;

                // Check for attributes after reference link
                if ($endPos < $length && $text[$endPos] === '{') {
                    $attrEnd = strpos($text, '}', $endPos);
                    if ($attrEnd !== false) {
                        $attrStr = substr($text, $endPos + 1, $attrEnd - $endPos - 1);
                        $this->applyAttributesToNode($link, $attrStr);
                        $endPos = $attrEnd + 1;
                    }
                }

                return [
                    'node' => $link,
                    'pos' => $endPos,
                ];
            }
        }

        // Check for attribute span: [text]{.class}
        if ($afterBracket < $length && $text[$afterBracket] === '{') {
            $attrEnd = strpos($text, '}', $afterBracket);
            if ($attrEnd !== false) {
                $attrStr = substr($text, $afterBracket + 1, $attrEnd - $afterBracket - 1);
                $span = new Span();
                $this->applyAttributesToNode($span, $attrStr);
                $this->parseInlines($span, $linkText);

                return [
                    'node' => $span,
                    'pos' => $attrEnd + 1,
                ];
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

        // Unclosed links can't be images, and we need node/pos to exist
        if (isset($result['unclosed_link']) || !isset($result['node'])) {
            return null;
        }

        $link = $result['node'];
        if (!$link instanceof Link) {
            return null;
        }

        // Extract alt text from link children
        $alt = $this->extractText($link);

        $image = new Image($link->getDestination() ?? '', $alt, $link->getTitle());

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
        $end = strpos($text, '>', $pos);
        if ($end === false) {
            return null;
        }

        $content = substr($text, $pos + 1, $end - $pos - 1);

        // URL autolink
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s<>]*$/', $content)) {
            $link = new Link($content);
            $link->appendChild(new Text($content));

            return [
                'node' => $link,
                'pos' => $end + 1,
            ];
        }

        // Email autolink
        if (filter_var($content, FILTER_VALIDATE_EMAIL)) {
            $link = new Link('mailto:' . $content);
            $link->appendChild(new Text($content));

            return [
                'node' => $link,
                'pos' => $end + 1,
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
        // First, check if there are consecutive delimiters (opening run)
        $searchPos = $pos + 1;
        $openingRunEnd = $pos + 1;
        while ($openingRunEnd < $length && $text[$openingRunEnd] === $delimiter) {
            $openingRunEnd++;
        }
        // If the opening run extends to end of string (all delimiters), no valid emphasis
        if ($openingRunEnd >= $length) {
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

                    return [
                        'node' => $node,
                        'pos' => $actualClose + 1,
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
                // Generate curly quotes based on count
                $openQuote = $marker === "'" ? "\u{2018}" : "\u{201C}";
                $closeQuote = $marker === "'" ? "\u{2019}" : "\u{201D}";

                // For pairs like {''}, output left + right
                // For single {'}, output just right (used for apostrophe)
                if ($quoteCount === 1) {
                    $result = $closeQuote;
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

                return [
                    'node' => $node,
                    'pos' => $searchPos + 2,
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
            return $quote === '"' ? "\u{201C}" : "\u{2018}";
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
            return "\u{2019}"; // closing/apostrophe
        }

        // A quote after ] or ) cannot be an opener
        if ($prevChar === ']' || $prevChar === ')') {
            return $quote === '"' ? "\u{201D}" : "\u{2019}";
        }

        if ($quote === '"') {
            // Opening if preceded by space or start, closing otherwise
            return $prevIsSpace && !$nextIsSpace ? "\u{201C}" : "\u{201D}";
        }

        // For single quotes, use matching algorithm to determine if this could be an opener
        // A potential opener at position can only be an opener if there's a matching closer later
        if ($prevIsSpace && !$nextIsSpace) {
            // This could be an opener - check if there's a matching closer
            $matchingCloser = $this->findMatchingSingleQuoteCloser($text, $pos);
            if ($matchingCloser !== null) {
                return "\u{2018}"; // opening quote
            }

            // No matching closer found, treat as apostrophe
            return "\u{2019}";
        }

        // Closing/apostrophe
        return "\u{2019}";
    }

    /**
     * Find a matching single quote closer for a potential opener at $pos
     *
     * Returns the position of the closer if found, null otherwise.
     * Uses a matching algorithm similar to emphasis - potential openers and closers
     * are matched from innermost pairs outward.
     */
    protected function findMatchingSingleQuoteCloser(string $text, int $openerPos): ?int
    {
        $length = strlen($text);

        // Collect all potential openers and closers after this position
        $openers = [$openerPos];
        $closers = [];

        for ($i = $openerPos + 1; $i < $length; $i++) {
            if ($text[$i] !== "'") {
                continue;
            }

            $prevChar = $text[$i - 1] ?? ' ';
            $nextChar = $text[$i + 1] ?? ' ';
            $prevIsSpace = ctype_space($prevChar);
            // Closer can be followed by space, punctuation, or end of string
            $nextIsSpaceOrPunct = ctype_space($nextChar) || $i === $length - 1
                || preg_match('/^[\p{P}\p{S}]/u', $nextChar);

            // Skip quotes before digits (always apostrophe)
            if (ctype_digit($nextChar)) {
                continue;
            }

            // Skip quotes after ] or )
            if ($prevChar === ']' || $prevChar === ')') {
                continue;
            }

            $nextIsSpace = ctype_space($nextChar);
            if ($prevIsSpace && !$nextIsSpace) {
                // Could be opener (after space, before non-space)
                $openers[] = $i;
            } elseif (!$prevIsSpace && $nextIsSpaceOrPunct) {
                // Could be closer (after non-space, before space/punct)
                $closers[] = $i;
            } elseif (!$prevIsSpace) {
                // Mid-word quote (like Jane's) - typically apostrophe
                continue;
            }
        }

        // Now match openers with closers, innermost first
        // For each closer, find the nearest preceding unmatched opener
        $matched = [];
        foreach ($closers as $closer) {
            for ($j = count($openers) - 1; $j >= 0; $j--) {
                $opener = $openers[$j];
                if ($opener < $closer && !isset($matched[$opener])) {
                    $matched[$opener] = $closer;

                    break;
                }
            }
        }

        // Return the closer for our position, if any
        return $matched[$openerPos] ?? null;
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

            // Check for multi-byte UTF-8 curly quotes (3 bytes each)
            // These act as word boundaries for attribute attachment
            if (ord($char) >= 0x98 && ord($char) <= 0x9D && $wordStart >= 3) {
                $threeBytes = substr($textBuffer, $wordStart - 3, 3);
                // Check for curly quotes: " " ' ' (U+201C, U+201D, U+2018, U+2019)
                if (
                    $threeBytes === "\u{201C}" || $threeBytes === "\u{201D}" ||
                    $threeBytes === "\u{2018}" || $threeBytes === "\u{2019}"
                ) {
                    break;
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

        // Remove % to end of string comments
        $percentPos = strpos($result ?? $attrStr, '%');
        if ($percentPos !== false) {
            $result = substr($result ?? $attrStr, 0, $percentPos);
        }

        return $result ?? $attrStr;
    }

    /**
     * Apply attributes from a string to a node
     */
    protected function applyAttributesToNode(Node $node, string $attrStr): void
    {
        AttributeParser::applyToNode($node, $attrStr);
    }

    /**
     * Parse footnote reference [^label]
     *
     * @return array{node: \Djot\Node\Inline\FootnoteRef, pos: int}|null
     */
    protected function parseFootnoteRef(string $text, int $pos): ?array
    {
        // Match [^label]
        if (!preg_match('/\[\^([^\]]+)\]/', $text, $matches, 0, $pos)) {
            return null;
        }

        if (strpos($text, $matches[0], $pos) !== $pos) {
            return null;
        }

        $label = $matches[1];

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
        // Match :word:
        if (!preg_match('/:([a-zA-Z_][a-zA-Z0-9_-]*):/', $text, $matches, 0, $pos)) {
            return null;
        }

        if (strpos($text, $matches[0], $pos) !== $pos) {
            return null;
        }

        return [
            'node' => new Symbol($matches[1]),
            'pos' => $pos + strlen($matches[0]),
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
        // Strip inline formatting markers: _ * ~ ^ + = { }
        // But keep the content between them
        $label = preg_replace('/[_*~^+={}]/', '', $label) ?? $label;

        // Normalize whitespace: collapse multiple spaces/newlines to single space
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        // Trim
        return trim($label);
    }
}
