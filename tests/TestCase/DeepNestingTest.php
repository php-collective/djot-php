<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use Djot\Node\Block\BlockQuote;
use PHPUnit\Framework\TestCase;

/**
 * Guards MAX_NESTING_DEPTH. Every block-container level recurses through
 * parseBlocks(), so deeply nested input used to exhaust the stack / memory.
 * Past the cap, content degrades to a literal paragraph instead of crashing.
 */
class DeepNestingTest extends TestCase
{
    private DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testDeeplyNestedBlockquotesDoNotCrash(): void
    {
        foreach ([1000, 5000, 50000] as $depth) {
            $html = $this->converter->convert(str_repeat('> ', $depth) . 'x');
            $this->assertStringContainsString('<blockquote>', $html);
        }
    }

    public function testDeeplyNestedDivsDoNotCrash(): void
    {
        $source = str_repeat(":::\n", 5000) . "x\n" . str_repeat(":::\n", 5000);
        $html = $this->converter->convert($source);
        $this->assertNotSame('', trim($html));
    }

    public function testModestNestingStillNests(): void
    {
        $doc = $this->converter->parse('> > > x');
        $depth = 0;
        $children = $doc->getChildren();
        while ($children !== [] && $children[0] instanceof BlockQuote) {
            $depth++;
            $children = $children[0]->getChildren();
        }
        $this->assertSame(3, $depth);
    }
}
