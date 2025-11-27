<?php

declare(strict_types=1);

namespace Djot\Parser;

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
                if ($escaped === ' ') {
                    // Non-breaking space
                    $textBuffer .= "\u{00A0}";
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

            // Special braced syntax: {=highlight=}, {+insert+}, {-delete-}
            if ($char === '{') {
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
        if ($text !== '') {
            $parent->appendChild(new Text($text));
        }
    }

    /**
     * Try to match custom inline patterns at the current position
     *
     * @return array{node: \Djot\Node\Node, pos: int}|null
     */
    protected function tryCustomPatterns(string $text, int $pos): ?array
    {
        if (empty($this->customPatterns)) {
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
        while ($searchPos < $length) {
            $closePos = strpos($text, str_repeat('`', $openBackticks), $searchPos);
            if ($closePos === false) {
                return null;
            }

            // Make sure we have exactly the right number of backticks
            $afterClose = $closePos + $openBackticks;
            if ($afterClose >= $length || $text[$afterClose] !== '`') {
                $content = substr($text, $contentStart, $closePos - $contentStart);

                // Strip single leading and trailing space if content starts/ends with backtick
                if (strlen($content) >= 2 && $content[0] === ' ' && $content[strlen($content) - 1] === ' ') {
                    if (str_contains($content, '`')) {
                        $content = substr($content, 1, -1);
                    }
                }

                // Check for raw inline format: `...`{=format}
                $endPos = $afterClose;
                if ($afterClose < $length && $text[$afterClose] === '{' && $afterClose + 1 < $length && $text[$afterClose + 1] === '=') {
                    $formatEnd = strpos($text, '}', $afterClose);
                    if ($formatEnd !== false) {
                        $format = substr($text, $afterClose + 2, $formatEnd - $afterClose - 2);
                        $endPos = $formatEnd + 1;

                        return [
                            'node' => new RawInline($content, $format),
                            'pos' => $endPos,
                        ];
                    }
                }

                return [
                    'node' => new Code($content),
                    'pos' => $endPos,
                ];
            }

            $searchPos = $closePos + 1;
        }

        return null;
    }

    /**
     * @return array{node: \Djot\Node\Inline\Link|\Djot\Node\Inline\Span, pos: int}|null
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
                $url = trim(substr($text, $urlStart, $urlEnd - $urlStart));
                $link = new Link($url);
                $this->parseInlines($link, $linkText);

                $endPos = $urlEnd + 1;

                // Check for attributes after link: [text](url){.class}
                if ($endPos < $length && $text[$endPos] === '{') {
                    $attrEnd = strpos($text, '}', $endPos);
                    if ($attrEnd !== false) {
                        $attrStr = substr($text, $endPos + 1, $attrEnd - $endPos - 1);
                        $this->parseAttributes($link, $attrStr);
                        $endPos = $attrEnd + 1;
                    }
                }

                return [
                    'node' => $link,
                    'pos' => $endPos,
                ];
            }
        }

        // Reference link: [text][ref] or [text][]{.class}
        if ($afterBracket < $length && $text[$afterBracket] === '[') {
            $refEnd = strpos($text, ']', $afterBracket + 1);
            if ($refEnd !== false) {
                $ref = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);
                if ($ref === '') {
                    $ref = $linkText;
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
                            $this->parseAttributes($link, $attrStr);
                            $endPos = $attrEnd + 1;
                        }
                    }

                    return [
                        'node' => $link,
                        'pos' => $endPos,
                    ];
                }

                // Reference not found - warn
                $this->blockParser->addUndefinedReferenceWarning($ref, $this->currentLine, $pos + 1);
            }
        }

        // Check for attribute span: [text]{.class}
        if ($afterBracket < $length && $text[$afterBracket] === '{') {
            $attrEnd = strpos($text, '}', $afterBracket);
            if ($attrEnd !== false) {
                $attrStr = substr($text, $afterBracket + 1, $attrEnd - $afterBracket - 1);
                $span = new Span();
                $this->parseAttributes($span, $attrStr);
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

        $link = $result['node'];
        if (!$link instanceof Link) {
            return null;
        }

        // Extract alt text from link children
        $alt = $this->extractText($link);

        $image = new Image($link->getDestination(), $alt, $link->getTitle());

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

        // Find closing delimiter
        $searchPos = $pos + 1;
        while ($searchPos < $length) {
            $closePos = strpos($text, $delimiter, $searchPos);
            if ($closePos === false) {
                return null;
            }

            // Check if this can be a closer (not preceded by whitespace)
            $beforeClose = $closePos > 0 ? $text[$closePos - 1] : ' ';
            if (!ctype_space($beforeClose)) {
                $content = substr($text, $pos + 1, $closePos - $pos - 1);
                $node = new $nodeClass();
                $this->parseInlines($node, $content);

                return [
                    'node' => $node,
                    'pos' => $closePos + 1,
                ];
            }

            $searchPos = $closePos + 1;
        }

        return null;
    }

    /**
     * Parse braced inline syntax: {=highlight=}, {+insert+}, {-delete-}
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
        $nodeClass = match ($marker) {
            '=' => Highlight::class,
            '+' => Insert::class,
            '-' => Delete::class,
            default => null,
        };

        if ($nodeClass === null) {
            return null;
        }

        // Find closing: marker}
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

        $prevIsSpace = ctype_space($prevChar) || $pos === 0;
        $nextIsSpace = ctype_space($nextChar);

        if ($quote === '"') {
            // Opening if preceded by space or start, closing otherwise
            return $prevIsSpace && !$nextIsSpace ? "\u{201C}" : "\u{201D}";
        }

        // Single quote
        return $prevIsSpace && !$nextIsSpace ? "\u{2018}" : "\u{2019}";
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

        // Convert dashes: --- = em-dash, -- = en-dash
        $result = '';
        $remaining = $dashCount;

        while ($remaining > 0) {
            if ($remaining >= 3) {
                $result .= "\u{2014}"; // em-dash
                $remaining -= 3;
            } elseif ($remaining >= 2) {
                $result .= "\u{2013}"; // en-dash
                $remaining -= 2;
            } else {
                $result .= '-';
                $remaining--;
            }
        }

        return [
            'text' => $result,
            'pos' => $pos + $dashCount,
        ];
    }

    protected function parseAttributes(Node $node, string $attrStr): void
    {
        // Parse .class, #id, key=value
        preg_match_all('/\.([^\s.#=]+)|#([^\s.#=]+)|([^\s.#=]+)=(["\']?)([^"\'}\s]*)\4/', $attrStr, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $node->addClass($match[1]);
            } elseif (!empty($match[2])) {
                $node->setAttribute('id', $match[2]);
            } elseif (!empty($match[3])) {
                $node->setAttribute($match[3], $match[5] ?? '');
            }
        }
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
}
