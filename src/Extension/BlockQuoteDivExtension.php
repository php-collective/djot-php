<?php

declare(strict_types=1);

namespace Djot\Extension;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use Djot\Node\Node;
use Djot\Parser\Block\FencedBlockParser;
use Djot\Parser\BlockParser;

/**
 * Adds a fenced blockquote div: `::: >`.
 *
 * A `:::` div whose only token is a greater-than sign is treated as a
 * blockquote - the same `<blockquote>` the `>`-prefixed form produces, but
 * without prefixing every line. This lets a quote own block content (lists,
 * nested fences, tables) where the per-line `>` form would need the marker on
 * every line: lazy continuation only folds plain paragraph text into a quote,
 * never new block structure.
 *
 * Syntax:
 * ```
 * ::: >
 * - item one
 * - item two
 * :::
 * ```
 *
 * The gt is consumed as the marker, so the output is a `<blockquote>`, not a
 * literal `class=">"`. This is why no core change is needed: `>` is not a
 * meaningful class, so intercepting it cannot collide with real usage - the
 * same reasoning behind {@see LineBlockDivExtension}'s `|`.
 *
 * A `^ attribution` line after the closing `:::` wraps the quote in a
 * `<figure>`/`<figcaption>` via the core caption handler - no extra code here.
 *
 * Example usage:
 * ```php
 * $converter = new DjotConverter();
 * $converter->addExtension(new BlockQuoteDivExtension());
 * $html = $converter->convert($djot);
 * ```
 */
class BlockQuoteDivExtension implements ExtensionInterface
{
    /**
     * Opener: 3+ colons, then only a gt (optional surrounding spaces/tabs).
     *
     * @var string
     */
    protected const OPENER = '/^(:{3,})[ \t]*>[ \t]*$/';

    public function register(DjotConverter $converter): void
    {
        $converter->getParser()->addBlockPattern(self::OPENER, $this->parseBlockQuoteDiv(...));
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param \Djot\Node\Node $parent
     * @param \Djot\Parser\BlockParser $blockParser
     */
    protected function parseBlockQuoteDiv(array $lines, int $start, Node $parent, BlockParser $blockParser): ?int
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

        $blockQuote = new BlockQuote();

        // Consume the preceding `{...}` block before parsing the body, otherwise
        // the still-pending attributes are claimed by the first inner block
        // instead of the blockquote (and never reach the caption's figure).
        $attributes = $blockParser->consumePendingAttributes();
        if ($attributes !== []) {
            $blockQuote->setAttributes($attributes);
        }

        $blockParser->parseBlockContent($blockQuote, $innerLines);

        $parent->appendChild($blockQuote);

        return $consumed;
    }

    /**
     * Collect the lines between the opener and its matching closing fence.
     *
     * Uses the core {@see FencedBlockParser} detectors so code-fence and
     * div-closer recognition stay identical to the built-in div parser: a `:::`
     * inside a fenced code block is not treated as the closer. A nested div uses
     * a longer fence (djot semantics), so the closer is the first bare `:::` run
     * of at least the opener length. Returns null when no closer is found, so the
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
}
