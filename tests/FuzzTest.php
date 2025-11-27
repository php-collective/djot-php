<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz testing for parser robustness
 *
 * These tests verify that the parser handles edge cases, malformed input,
 * and random/pathological inputs without crashing or producing invalid output.
 */
class FuzzTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    // ==================== Random Character Sequences ====================

    /**
     * @return array<string, array{0: string}>
     */
    public static function randomAsciiProvider(): array
    {
        $tests = [];
        $seed = 12345; // Fixed seed for reproducibility

        srand($seed);
        for ($i = 0; $i < 20; $i++) {
            $length = rand(10, 200);
            $str = '';
            for ($j = 0; $j < $length; $j++) {
                $str .= chr(rand(32, 126));
            }
            $tests["random_ascii_$i"] = [$str];
        }

        return $tests;
    }

    #[DataProvider('randomAsciiProvider')]
    public function testRandomAsciiDoesNotCrash(string $input): void
    {
        // Should not throw any exceptions
        $result = $this->converter->convert($input);

        // Result should be a valid string
        $this->assertIsString($result);
    }

    // ==================== Pathological Nesting ====================

    public function testDeeplyNestedBrackets(): void
    {
        $depth = 100;
        $input = str_repeat('[', $depth) . 'text' . str_repeat(']', $depth);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testDeeplyNestedParentheses(): void
    {
        $depth = 100;
        $input = '[text](' . str_repeat('(', $depth) . 'url' . str_repeat(')', $depth + 1);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testDeeplyNestedBlockquotes(): void
    {
        $depth = 50;
        $input = str_repeat('> ', $depth) . "deeply nested text\n";
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
        $this->assertStringContainsString('blockquote', $result);
    }

    public function testDeeplyNestedLists(): void
    {
        $depth = 30;
        $input = '';
        for ($i = 0; $i < $depth; $i++) {
            $input .= str_repeat('  ', $i) . "- Item $i\n";
        }
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testManyConsecutiveDelimiters(): void
    {
        // Many stars in a row
        $input = str_repeat('*', 1000);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testManyConsecutiveBackticks(): void
    {
        $input = str_repeat('`', 500) . 'code' . str_repeat('`', 500);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testVeryLongLine(): void
    {
        $input = str_repeat('a', 100000);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
        $this->assertStringContainsString('<p>', $result);
    }

    public function testManyShortLines(): void
    {
        $input = implode("\n", array_fill(0, 10000, 'a'));
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    // ==================== Malformed Input ====================

    public function testUnclosedBrackets(): void
    {
        $variants = [
            '[unclosed link',
            '[text](unclosed url',
            '[[nested [brackets',
            '![unclosed image',
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    public function testUnclosedDelimiters(): void
    {
        $variants = [
            '*unclosed strong',
            '_unclosed emphasis',
            '`unclosed code',
            '^unclosed superscript',
            '~unclosed subscript',
            '{=unclosed highlight',
            '{+unclosed insert',
            '{-unclosed delete',
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    public function testMismatchedDelimiters(): void
    {
        $variants = [
            '*text_',
            '_text*',
            '[text)',
            '(text]',
            '{text}text{',
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    public function testMalformedCodeFence(): void
    {
        $variants = [
            "```\nunclosed",
            "~~~\nunclosed",
            "```lang\ncode\n``", // Wrong number of closing backticks
            "```\n```\n```", // Multiple fences
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    public function testMalformedTable(): void
    {
        $variants = [
            '| incomplete',
            "| a | b\n|-",
            '|||',
            '| | | | | |',
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    // ==================== Special Character Sequences ====================

    public function testNullBytes(): void
    {
        $input = "text\x00with\x00nulls";
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testControlCharacters(): void
    {
        $input = "text\x01\x02\x03\x04\x05with\x06\x07control";
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testHighUnicode(): void
    {
        // Various Unicode characters including emoji, CJK, RTL
        $input = '👨‍👩‍👧‍👦 日本語 العربية עברית 中文 한국어 🎉🎊🎁';
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
        $this->assertStringContainsString('👨‍👩‍👧‍👦', $result);
    }

    public function testMixedLineEndings(): void
    {
        $input = "line1\rline2\nline3\r\nline4";
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testOnlyWhitespace(): void
    {
        $variants = [
            '',
            ' ',
            "\t",
            "\n",
            "\r\n",
            "   \n   \n   ",
            str_repeat(' ', 1000),
            str_repeat("\n", 1000),
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    // ==================== Pathological Patterns ====================

    /**
     * Test that emphasizes performance with repeated patterns
     */
    public function testRepeatedEmphasisOpeners(): void
    {
        // Many potential openers that never close
        $input = str_repeat('*a ', 500);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testRepeatedLinkOpeners(): void
    {
        // Many [ without closing
        $input = str_repeat('[a ', 500);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testBacktrackingPattern(): void
    {
        // Pattern that could cause backtracking issues
        $input = str_repeat('[', 20) . 'x' . str_repeat('](url)', 20);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testAlternatingDelimiters(): void
    {
        $input = str_repeat('*_', 500);
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    // ==================== Edge Cases ====================

    public function testEmptyElements(): void
    {
        $variants = [
            '**', // Empty strong
            '__', // Empty emphasis
            '``', // Empty code
            '[]', // Empty link text
            '[]()', // Empty link
            '![]', // Empty image
            '![]()', // Empty image with empty src
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    public function testOnlyPunctuation(): void
    {
        $input = '!@#$%^&*()_+-=[]{}|;:\'",.<>?/\\`~';
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testRepeatedSpecialSequences(): void
    {
        $variants = [
            str_repeat('---', 100), // Many thematic breaks
            str_repeat('> ', 100), // Many blockquote markers
            str_repeat('# ', 100), // Many heading markers
            str_repeat('- ', 100), // Many list markers
            str_repeat('| ', 100), // Many pipe characters
        ];

        foreach ($variants as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
        }
    }

    // ==================== Memory Safety ====================

    public function testLargeDocument(): void
    {
        // Generate a large but valid document
        $parts = [];
        for ($i = 0; $i < 1000; $i++) {
            $parts[] = "# Heading $i\n\nParagraph $i with *emphasis* and `code`.\n";
        }
        $input = implode("\n", $parts);

        $result = $this->converter->convert($input);
        $this->assertIsString($result);
        $this->assertStringContainsString('<h1 ', $result);
    }

    public function testManySmallDocuments(): void
    {
        // Parse many small documents to test for memory leaks
        $converter = new DjotConverter();

        for ($i = 0; $i < 1000; $i++) {
            $result = $converter->convert("Document $i with *emphasis*");
            $this->assertIsString($result);
        }
    }
}
