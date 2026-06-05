<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Node;
use Djot\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Covers the incremental brace scan used while collecting a paragraph.
 *
 * The scan must (a) keep its original semantics - an unclosed attribute brace
 * in the paragraph so far suppresses block interruption - and (b) stay linear
 * in the number of lines. Re-scanning the whole growing content on every line
 * previously made a single multi-line paragraph parse in O(n^2).
 */
class ParagraphBraceScanTest extends TestCase
{
    private function hasHeading(Node $doc): bool
    {
        foreach ($doc->getChildren() as $child) {
            if ($child instanceof Heading) {
                return true;
            }
        }

        return false;
    }

    public function testUnclosedBraceSuppressesInterruption(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("text{a=x\n# heading");

        $this->assertCount(1, $doc->getChildren());
        $this->assertInstanceOf(Paragraph::class, $doc->getChildren()[0]);
        $this->assertFalse($this->hasHeading($doc));
    }

    public function testClosedBraceAllowsInterruption(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("text{a=x}\n# heading");

        $this->assertTrue($this->hasHeading($doc));
    }

    public function testBraceInsideQuoteIsNotCounted(): void
    {
        // The `}` lives inside a quoted value, so the `{` stays unclosed and the
        // heading must not interrupt - exercises quote state carried across the
        // segment boundary.
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("text{a=\"}\"\n# heading");

        $this->assertFalse($this->hasHeading($doc));
    }

    public function testPlainParagraphStillInterrupts(): void
    {
        $parser = new BlockParser(blocksInterruptParagraphs: true);
        $doc = $parser->parse("text\n# heading");

        $this->assertTrue($this->hasHeading($doc));
    }

    /**
     * Regression guard for the O(n^2) paragraph scan. A 3000-line single
     * paragraph parses in milliseconds when linear; the previous quadratic
     * behavior took tens of seconds, so a generous absolute bound reliably
     * separates the two without being timing-flaky.
     */
    public function testLargeSingleParagraphParsesInLinearTime(): void
    {
        $lines = [];
        for ($i = 0; $i < 3000; $i++) {
            $lines[] = "continuation line $i of one big paragraph here";
        }
        $source = implode("\n", $lines);

        $parser = new BlockParser();

        $start = hrtime(true);
        $doc = $parser->parse($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertCount(1, $doc->getChildren());
        $this->assertLessThan(3.0, $elapsed, "3000-line paragraph took {$elapsed}s (expected sub-second; quadratic regression?)");
    }
}
