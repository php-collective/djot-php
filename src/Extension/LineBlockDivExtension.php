<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\LineBlock;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\HardBreak;
use Djot\Node\Node;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\BlockParser;

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
     * Build one stanza paragraph: each line is inline-parsed and joined by a
     * hard break, so single newlines render as `<br>`.
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
            $inlineParser->parse($paragraph, $line, $baseLine + $index);
            if ($index < $last) {
                $paragraph->appendChild(new HardBreak());
            }
            $index++;
        }

        return $paragraph;
    }
}
