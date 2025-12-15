<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Edge case tests to verify parser robustness
 *
 * These tests ensure the parser handles malformed, unusual,
 * and boundary-condition inputs without crashing.
 */
class EdgeCaseTest extends TestCase
{
    private DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyAndWhitespaceProvider(): array
    {
        return [
            'empty string' => [''],
            'single space' => [' '],
            'multiple spaces' => ['     '],
            'single tab' => ["\t"],
            'single newline' => ["\n"],
            'multiple newlines' => ["\n\n\n\n\n"],
            'crlf' => ["\r\n"],
            'mixed whitespace' => [" \t\n \r\n \t "],
            'only carriage returns' => ["\r\r\r"],
        ];
    }

    #[DataProvider('emptyAndWhitespaceProvider')]
    public function testEmptyAndWhitespaceInputs(string $input): void
    {
        // Should not throw
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unclosedDelimitersProvider(): array
    {
        return [
            'unclosed emphasis' => ['*unclosed'],
            'unclosed strong' => ['**unclosed'],
            'unclosed underscore' => ['_unclosed'],
            'unclosed double underscore' => ['__unclosed'],
            'unclosed link bracket' => ['[unclosed link'],
            'unclosed link paren' => ['[link](unclosed'],
            'unclosed code backtick' => ['`unclosed code'],
            'unclosed code fence' => ["```\nunclosed fence"],
            'unclosed attribute' => ['{.class'],
            'unclosed math inline' => ['$unclosed math'],
            'unclosed math display' => ["$$\nunclosed display math"],
            'unclosed superscript' => ['^{unclosed'],
            'unclosed subscript' => ['~{unclosed'],
        ];
    }

    #[DataProvider('unclosedDelimitersProvider')]
    public function testUnclosedDelimiters(string $input): void
    {
        // Should not throw - unclosed delimiters should be treated as literal text
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deepNestingProvider(): array
    {
        return [
            'deeply nested emphasis' => [str_repeat('*', 50) . 'text' . str_repeat('*', 50)],
            'deeply nested brackets' => [str_repeat('[', 50) . 'text' . str_repeat(']', 50)],
            'deeply nested quotes' => [str_repeat('> ', 100) . 'text'],
            'deeply nested lists' => [implode("\n", array_map(fn ($i) => str_repeat('  ', $i) . '- item', range(0, 50)))],
            'nested code fences' => ["```\n````\n`````\ncode\n`````\n````\n```"],
        ];
    }

    #[DataProvider('deepNestingProvider')]
    public function testDeepNesting(string $input): void
    {
        // Should handle deep nesting without stack overflow
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function longInputProvider(): array
    {
        return [
            'very long line' => [str_repeat('a', 10000)],
            'many short lines' => [str_repeat("line\n", 5000)],
            'long word with emphasis' => ['*' . str_repeat('a', 5000) . '*'],
            'many repeated headers' => [str_repeat("# Header\n\n", 1000)],
            'large table' => [self::generateLargeTable(100, 10)],
        ];
    }

    private static function generateLargeTable(int $rows, int $cols): string
    {
        $header = '| ' . implode(' | ', array_fill(0, $cols, 'Col')) . " |\n";
        $separator = '|' . str_repeat('---|', $cols) . "\n";
        $row = '| ' . implode(' | ', array_fill(0, $cols, 'Cell')) . " |\n";

        return $header . $separator . str_repeat($row, $rows);
    }

    #[DataProvider('longInputProvider')]
    public function testLongInputs(string $input): void
    {
        // Should handle long inputs without timeout or memory issues
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function specialCharactersProvider(): array
    {
        return [
            'null byte' => ["text\x00text"],
            'unicode replacement char' => ["text\xEF\xBF\xBDtext"],
            'emoji' => ['Hello 👋 World 🌍'],
            'rtl text' => ['مرحبا بالعالم'],
            'mixed scripts' => ['Hello مرحبا 你好 🌍'],
            'zero width chars' => ["text\u{200B}text\u{200C}text"],
            'control characters' => ["text\x01\x02\x03text"],
            'backspace' => ["text\x08text"],
            'bell' => ["text\x07text"],
            'high unicode' => ['𝕳𝖊𝖑𝖑𝖔'],
        ];
    }

    #[DataProvider('specialCharactersProvider')]
    public function testSpecialCharacters(string $input): void
    {
        // Should handle special characters gracefully
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapeSequencesProvider(): array
    {
        return [
            'escaped backslash' => ['\\\\'],
            'many escaped backslashes' => [str_repeat('\\\\', 100)],
            'escaped everything' => ['\\*\\[\\]\\(\\)\\`\\#\\-\\+\\.\\!'],
            'backslash at end' => ['text\\'],
            'only backslashes' => ['\\\\\\\\\\\\'],
            'escaped newline' => ["text\\\ntext"],
        ];
    }

    #[DataProvider('escapeSequencesProvider')]
    public function testEscapeSequences(string $input): void
    {
        // Should handle escape sequences without issues
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function htmlEntityProvider(): array
    {
        return [
            'valid entity' => ['&amp;'],
            'invalid entity' => ['&invalid;'],
            'numeric entity' => ['&#65;'],
            'hex entity' => ['&#x41;'],
            'unclosed entity' => ['&amp'],
            'entity in code' => ['`&amp;`'],
            'many entities' => [str_repeat('&amp;', 1000)],
            'malformed numeric' => ['&#99999999999;'],
            'malformed hex' => ['&#xZZZ;'],
        ];
    }

    #[DataProvider('htmlEntityProvider')]
    public function testHtmlEntities(string $input): void
    {
        // Should handle HTML entities properly
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mixedSyntaxProvider(): array
    {
        return [
            'link in emphasis' => ['*[link](url)*'],
            'emphasis in link' => ['[*text*](url)'],
            'code in emphasis' => ['*`code`*'],
            'emphasis in code' => ['`*not emphasis*`'],
            'nested quotes with list' => ["> - item\n> - item"],
            'header in quote' => ['> # Header'],
            'table after list' => ["- item\n\n| a | b |\n|---|---|\n| 1 | 2 |"],
            'all inline elements' => ['*_`^sub~{=mark=}+ins+-del-`_*'],
        ];
    }

    #[DataProvider('mixedSyntaxProvider')]
    public function testMixedSyntax(string $input): void
    {
        // Should handle mixed syntax combinations
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function boundaryConditionsProvider(): array
    {
        return [
            'single character' => ['a'],
            'single special char' => ['*'],
            'two chars opening' => ['*a'],
            'two chars closing' => ['a*'],
            'min header' => ['# a'],
            'header no space' => ['#text'],
            'list no space' => ['-text'],
            'min code fence' => ["```\n```"],
            'min table' => ["| a |\n|---|\n| b |"],
            'link empty text' => ['[](url)'],
            'link empty url' => ['[text]()'],
            'image empty alt' => ['![](url)'],
            'attribute empty' => ['{}'],
            'ref empty label' => ['[]:url'],
        ];
    }

    #[DataProvider('boundaryConditionsProvider')]
    public function testBoundaryConditions(string $input): void
    {
        // Should handle boundary conditions
        $result = $this->converter->convert($input);
        $this->assertIsString($result);
    }

    public function testStrictModeWithMalformedInput(): void
    {
        $converter = new DjotConverter(warnings: true);

        // Various malformed inputs that might generate warnings
        $inputs = [
            '[undefined reference]',
            '[link][missing]',
            '![image][missing]',
            "```unknownlang\ncode\n```",
        ];

        foreach ($inputs as $input) {
            $result = $converter->convert($input);
            $this->assertIsString($result);
        }

        // Should have collected some warnings
        $warnings = $converter->getWarnings();
        $this->assertIsArray($warnings);
    }

    public function testRepeatedConversions(): void
    {
        // Test that the converter works correctly across multiple conversions
        $inputs = [
            '# Header',
            '*emphasis*',
            '[link](url)',
            '`code`',
        ];

        foreach ($inputs as $input) {
            $result = $this->converter->convert($input);
            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        }
    }

    public function testConverterReuse(): void
    {
        // Convert multiple documents with the same converter instance
        for ($i = 0; $i < 100; $i++) {
            $result = $this->converter->convert("# Document $i\n\nContent $i");
            $this->assertStringContainsString("Document $i", $result);
        }
    }
}
