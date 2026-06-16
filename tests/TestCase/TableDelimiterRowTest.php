<?php

declare(strict_types=1);

namespace Djot\Test\TestCase;

use Djot\DjotConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table delimiter (separator) row edge cases.
 *
 * - Trailing whitespace after a row's closing pipe is insignificant.
 * - A delimiter row with an empty cell (|---||) is not a delimiter row.
 *
 * Ported from carve-php (parity with carve-js / carve-rs).
 */
class TableDelimiterRowTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    public function testSeparatorRowWithTrailingWhitespaceStillPromotesHeader(): void
    {
        // Trailing whitespace after the closing pipe is insignificant; the
        // separator must still promote the first row to a header.
        $result = $this->converter->convert("| H | G |\n|---|   \n| a | b |");

        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th>H</th>', $result);
        $this->assertStringContainsString('<th>G</th>', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    public function testDataRowWithTrailingWhitespaceStillParsesAsTable(): void
    {
        $result = $this->converter->convert('| a |   ');

        $this->assertStringContainsString('<td>a</td>', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    public function testSeparatorRowWithEmptyCellIsNotASeparator(): void
    {
        // `|---||` has an empty second cell, so it is NOT a delimiter row: the
        // first row must not be promoted to a header.
        $result = $this->converter->convert("| H | G |\n|---||\n| a | b |");

        $this->assertStringNotContainsString('<th>', $result);
        $this->assertStringContainsString('<td>H</td>', $result);
    }
}
