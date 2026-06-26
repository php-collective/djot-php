<?php

declare(strict_types=1);

namespace Djot\Test\TestCase\Renderer;

use Djot\DjotConverter;
use Djot\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Abbreviation expansion is an output-amplification DoS vector: a tiny source
 * `*[HT]: <huge>` with many `HT` occurrences expands to
 * `definition_len * occurrence_count` bytes. A per-render byte budget bounds the
 * cumulative expansion; once it is exceeded, further occurrences degrade to
 * plain key text (no `<abbr>` wrapper, no title). Normal documents stay
 * byte-identical because the budget sits far above any real document.
 */
class AbbreviationBudgetTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testNormalDocumentExpandsEveryOccurrence(): void
    {
        $djot = "HTML and HTML again.\n\n*[HTML]: HyperText Markup Language";

        $result = $this->converter->convert($djot);

        // Both occurrences expand normally; the budget never bites.
        $this->assertSame(2, substr_count($result, '<abbr title="HyperText Markup Language">'));
    }

    public function testAmplificationIsBounded(): void
    {
        $definition = str_repeat('A', 100000); // 100 KB definition
        $occurrences = 40;
        $djot = str_repeat('HT ', $occurrences) . "\n\n*[HT]: " . $definition;

        $result = $this->converter->convert($djot);

        $expanded = substr_count($result, '<abbr');

        // Some early occurrences expand, but not all: the budget forces the
        // tail to degrade to plain key text.
        $this->assertGreaterThan(0, $expanded);
        $this->assertLessThan($occurrences, $expanded);

        // Output stays far below the naive definition_len * occurrence_count
        // amplification (4 MB here); the budget caps it near ~1 MB.
        $this->assertLessThan($occurrences * strlen($definition), strlen($result));

        // A degraded occurrence renders as the bare key with no title.
        $this->assertStringContainsString('HT', $result);
    }

    public function testMarkdownDegradedKeyIsNotHtmlEscaped(): void
    {
        // A budget-degraded abbreviation in the Markdown renderer must emit the
        // key as ordinary Markdown text. A `&` in the key must stay literal, not
        // become `&amp;` (which is only correct inside the raw <abbr> element).
        $definition = str_repeat('A', 100000);
        $djot = str_repeat('A&B ', 40) . "\n\n*[A&B]: " . $definition;

        $converter = new DjotConverter(renderer: new MarkdownRenderer());
        $result = $converter->convert($djot);

        // At least one occurrence degraded to the literal key text.
        $this->assertStringContainsString('A&B', $result);
    }

    public function testBudgetResetsBetweenRenders(): void
    {
        $definition = str_repeat('B', 100000);
        $djot = str_repeat('HT ', 40) . "\n\n*[HT]: " . $definition;

        $first = $this->converter->convert($djot);
        $second = $this->converter->convert($djot);

        // A second render must not inherit the first render's spent budget.
        $this->assertSame(substr_count($first, '<abbr'), substr_count($second, '<abbr'));
    }
}
