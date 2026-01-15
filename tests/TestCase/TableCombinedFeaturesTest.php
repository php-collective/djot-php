<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for combined table features: continuation rows (+) with spans (^ and <).
 */
class TableCombinedFeaturesTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testContinuationRowWithRowspan(): void
    {
        $djot = <<<'DJOT'
| Category       | Item   |
|----------------|--------|
| Fresh Fruits   | Apple  |
+ from local     |        |
+ farms          |        |
| ^              | Banana |
| ^              | Orange |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Category "Fresh Fruits from local farms" should span 3 rows
        $this->assertStringContainsString('rowspan="3"', $html);
        $this->assertStringContainsString('Fresh Fruits from local farms', $html);
        $this->assertStringContainsString('Apple', $html);
        $this->assertStringContainsString('Banana', $html);
        $this->assertStringContainsString('Orange', $html);
    }

    public function testContinuationRowWithColspan(): void
    {
        $djot = <<<'DJOT'
| Name  | Full Address      | <      |
|-------|-------------------|--------|
| Alice | 123 Main St       | <      |
+       | Springfield       | <      |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Address column should span 2 columns and have merged content
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('123 Main St Springfield', $html);
    }

    public function testContinuationWithBothRowspanAndColspan(): void
    {
        $djot = <<<'DJOT'
| Category | Item   | Details     | <      |
|----------|--------|-------------|--------|
| Fruits   | Apple  | Red fruit   | <      |
+          |        | very tasty  | <      |
| ^        | Banana | Yellow      | <      |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Should have rowspan on Category
        $this->assertStringContainsString('rowspan="2"', $html);
        // Should have colspan on Details
        $this->assertStringContainsString('colspan="2"', $html);
        // Should have merged content
        $this->assertStringContainsString('Red fruit very tasty', $html);
    }

    public function testRowspanMarkerInContinuationRowMergedAsContent(): void
    {
        // Note: Span markers in continuation rows are merged as content,
        // not treated as span directives. This is because content merging
        // happens before span detection.
        $djot = <<<'DJOT'
| A     | B     |
|-------|-------|
| Cell1 | Cell2 |
+ ^     | more2 |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // ^ gets merged as content, not treated as rowspan
        $this->assertStringContainsString('Cell1 ^', $html);
        $this->assertStringContainsString('Cell2 more2', $html);
    }

    public function testColspanMarkerInContinuationRowMergedAsContent(): void
    {
        // Note: Span markers in continuation rows are merged as content,
        // not treated as span directives.
        $djot = <<<'DJOT'
| A     | B     | C     |
|-------|-------|-------|
| Cell1 | Cell2 | Cell3 |
+ more1 | <     | more3 |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // < gets merged as content, not treated as colspan
        $this->assertStringContainsString('Cell1 more1', $html);
        $this->assertStringContainsString('Cell2 &lt;', $html); // HTML escaped
        $this->assertStringContainsString('Cell3 more3', $html);
    }

    public function testMultipleSpansWithContinuation(): void
    {
        $djot = <<<'DJOT'
| Category | Sub    | Item   | Notes           |
|----------|--------|--------|-----------------|
| Produce  | Fruits | Apple  | Red and sweet   |
+          |        |        | and very crunchy|
| ^        | ^      | Banana | Yellow curved   |
| ^        | Veggies| Carrot | Orange root     |
+          |        |        | vegetable       |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Category spans 3 rows
        $this->assertStringContainsString('rowspan="3"', $html);
        // Sub "Fruits" spans 2 rows
        $this->assertStringContainsString('rowspan="2"', $html);
        // Notes should have merged content
        $this->assertStringContainsString('Red and sweet and very crunchy', $html);
        $this->assertStringContainsString('Orange root vegetable', $html);
    }

    public function testEmptyRowspanCellWithContinuation(): void
    {
        $djot = <<<'DJOT'
| A          | B     |
|------------|-------|
| Multi-line | Data1 |
+ content    |       |
| ^          | Data2 |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('Multi-line content', $html);
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('Data1', $html);
        $this->assertStringContainsString('Data2', $html);
    }

    public function testHeaderRowWithColspanAndDataContinuation(): void
    {
        $djot = <<<'DJOT'
| Name  | Contact Info | <     |
|-------|--------------|-------|
| Alice | email@test   | x1234 |
+       | @example.com |       |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // Header should have colspan
        $this->assertStringContainsString('colspan="2"', $html);
        // Data row should have merged email
        $this->assertStringContainsString('email@test @example.com', $html);
    }
}
