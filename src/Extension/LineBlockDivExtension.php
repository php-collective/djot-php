<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Inline\Text;
use Djot\Node\Node;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\BlockParser;
use Djot\Parser\InlineParser;

/**
 * Adds a fenced line-block div: `::: |`.
 *
 * A `:::` div whose only class token is a pipe is treated as a line block,
 * the same node the `|`-prefixed form produces - but without prefixing every
 * line. Inside it, each soft line break becomes a hard break (`<br>`) and
 * leading whitespace is preserved, so verse, addresses, lyrics, and signature
 * blocks keep their shape. A blank line separates stanzas (each becomes its own
 * paragraph). Inline djot (emphasis, links, ...) still parses normally.
 *
 * Syntax:
 * ```
 * ::: |
 * The limerick packs laughs anatomical
 *   Into space that is quite economical.
 *
 * But the good ones I've seen
 *   So seldom are clean
 * :::
 * ```
 *
 * The pipe is consumed as the marker, so the output is a `line-block` div, not
 * a literal `class="|"`. This is why no core change is needed: a `|` is not a
 * meaningful class, so intercepting it cannot collide with real usage.
 *
 * Rationale (djot#29): sidesteps both objections to a `|`-prefix line block -
 * no per-line pipe to confuse with pipe tables, and a language-neutral symbol
 * rather than an English keyword div class.
 *
 * Example usage:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new LineBlockDivExtension());
 * $html = $converter->convert($djot);
 * ```
 */
class LineBlockDivExtension implements ExtensionInterface
{
    /**
     * Opener: 3+ colons, then only a pipe (optional surrounding spaces/tabs).
     *
     * @var string
     */
    protected const OPENER = '/^(:{3,})[ \t]*\|[ \t]*$/';

    public function register(DjotConverter $converter): void
    {
        $converter->getParser()->addBlockPattern(self::OPENER, $this->parseLineBlockDiv(...));
    }

    /**
     * @param array<string> $lines
     * @param \Djot\Parser\BlockParser $blockParser
     * @param \Djot\Node\Node $parent
     * @param int $start
     */
    protected function parseLineBlockDiv(array $lines, int $start, Node $parent, BlockParser $blockParser): ?int
    {
        if (preg_match(self::OPENER, $lines[$start], $matches) !== 1) {
            return null; // @codeCoverageIgnore - pattern already matched
        }

        $fenceLength = strlen($matches[1]);
        $innerLines = $this->collectInnerLines($lines, $start, $fenceLength, $consumed);
        if ($innerLines === null) {
            // Unclosed fence: leave it for the core parser to report.
            return null;
        }

        $lineBlock = new LineBlock();
        foreach ($this->splitStanzas($innerLines) as $offset => $stanza) {
            $lineBlock->appendChild($this->buildStanza($blockParser, $stanza, $start + 1 + $offset));
        }

        $attributes = $blockParser->consumePendingAttributes();
        if ($attributes !== []) {
            $lineBlock->setAttributes($attributes);
        }

        $parent->appendChild($lineBlock);

        return $consumed;
    }

    /**
     * Collect the lines between the opener and its matching closing fence.
     *
     * Uses the core {@see FencedBlockParser} detectors so code-fence and
     * div-closer recognition stay identical to the built-in div parser: a `:::`
     * inside a fenced code block is not treated as the closer, and an indented or
     * info-string code fence is recognized the same way. A nested div uses a
     * longer fence (djot semantics), so the closer is the first bare `:::` run of
     * at least the opener length. Returns null when no closer is found, so the
     * caller can decline the match.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $fenceLength
     * @param int|null $consumed Set to the number of lines consumed (opener..closer).
     *
     * @return array<string>|null
     */
    protected function collectInnerLines(array $lines, int $start, int $fenceLength, ?int &$consumed): ?array
    {
        $fences = new FencedBlockParser();
        $inner = [];
        $inCode = false;
        $codeChar = '';
        $codeLength = 0;
        $count = count($lines);
        $i = $start + 1;

        while ($i < $count) {
            $line = $lines[$i];

            if (!$inCode) {
                $opener = $fences->parseCodeFenceOpener($line);
                if ($opener !== null) {
                    $inCode = true;
                    $codeChar = $opener['char'];
                    $codeLength = $opener['length'];
                    $inner[] = $line;
                    $i++;

                    continue;
                }
            }
            if ($inCode) {
                if ($fences->isCodeFenceCloser($line, $codeChar, $codeLength)) {
                    $inCode = false;
                }
                $inner[] = $line;
                $i++;

                continue;
            }

            if ($fences->isDivFenceCloser($line, $fenceLength)) {
                $consumed = $i + 1 - $start;

                return $inner;
            }

            $inner[] = $line;
            $i++;
        }

        return null;
    }

    /**
     * Split inner lines into stanzas on blank lines.
     *
     * @param array<string> $innerLines
     *
     * @return array<int, array<int, string>> Stanza index (line offset of its
     *   first line) => its lines, leading whitespace preserved.
     */
    protected function splitStanzas(array $innerLines): array
    {
        $stanzas = [];
        $current = [];
        $offset = 0;
        foreach ($innerLines as $index => $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $stanzas[$offset] = $current;
                    $current = [];
                }

                continue;
            }
            if ($current === []) {
                $offset = $index;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $stanzas[$offset] = $current;
        }

        return $stanzas;
    }

    /**
     * Build one stanza paragraph: each line keeps its significant whitespace and
     * is joined to the next by a hard break, so single newlines render as `<br>`.
     *
     * @param \Djot\Parser\BlockParser $blockParser
     * @param array<int, string> $stanza
     * @param int $baseLine
     */
    protected function buildStanza(BlockParser $blockParser, array $stanza, int $baseLine): Paragraph
    {
        $paragraph = new Paragraph();
        $inlineParser = $blockParser->getInlineParser();
        $last = count($stanza) - 1;
        $index = 0;
        foreach ($stanza as $line) {
            $this->appendLine($paragraph, $inlineParser, $line, $baseLine + $index);
            if ($index < $last) {
                $paragraph->appendChild(new HardBreak());
            }
            $index++;
        }

        return $paragraph;
    }

    /**
     * Append one verse line, preserving its significant whitespace.
     *
     * In a line block every space the author typed is meaningful (Pandoc's
     * definition), so a verse keeps not only its leading indent but also the
     * medial gaps used for alignment - the caesura of Old English verse, columns
     * in an address, chords aligned above lyrics. The rule:
     *
     * - **Leading** whitespace (indentation): always preserved, even one space.
     * - **Inner / trailing** runs of **two or more** columns (medial gaps,
     *   inline alignment): preserved.
     * - A **single** inner space stays an ordinary, collapsible space, so a long
     *   line can still wrap between words.
     *
     * Each preserved column is emitted via the internal non-breaking-space
     * placeholder (U+E000, the same sentinel the parser uses for an escaped
     * space): the HTML renderer turns it into `&nbsp;`, Markdown keeps a real
     * U+00A0 (so the gap survives a round-trip and is never read as indented
     * code), and plain text / ANSI use an ordinary space. A private-use character
     * is used so it never collides with a literal U+00A0 in the author's text.
     * Tabs expand to four-column stops. Text segments between gaps are
     * inline-parsed, so emphasis, links, and the rest still work.
     *
     * @param \Djot\Node\Block\Paragraph $paragraph
     * @param \Djot\Parser\InlineParser $inlineParser
     * @param string $line
     * @param int $lineNo
     */
    protected function appendLine(Paragraph $paragraph, InlineParser $inlineParser, string $line, int $lineNo): void
    {
        $length = strlen($line);
        $offset = 0;
        $column = 0;
        $text = '';
        $seenContent = false;

        while ($offset < $length) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t") {
                $text .= $char;
                $seenContent = true;
                $column++;
                $offset++;

                continue;
            }

            $width = 0;
            while ($offset < $length && ($line[$offset] === ' ' || $line[$offset] === "\t")) {
                if ($line[$offset] === "\t") {
                    $width += 4 - (($column + $width) % 4);
                } else {
                    $width++;
                }
                $offset++;
            }
            $column += $width;

            // Leading indent is always significant; an inner or trailing gap only
            // from two columns up. A lone inner space stays a normal, wrappable space.
            if (!$seenContent || $width >= 2) {
                if ($text !== '') {
                    $inlineParser->parse($paragraph, $text, $lineNo);
                    $text = '';
                }
                $paragraph->appendChild(new Text(str_repeat("\u{E000}", $width)));

                continue;
            }

            $text .= ' ';
        }

        if ($text !== '') {
            $inlineParser->parse($paragraph, $text, $lineNo);
        }
    }
}
