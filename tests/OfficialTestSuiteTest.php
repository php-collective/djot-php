<?php

declare(strict_types=1);

namespace Djot\Test;

use Djot\DjotConverter;
use Djot\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use function count;

/**
 * Tests against the official djot.js test suite
 *
 * These tests are downloaded from https://github.com/jgm/djot.js/tree/main/test
 * and provide compatibility verification with the reference implementation.
 *
 * Current compatibility: 100% (237 tests passing)
 *
 * To run these tests:
 *   vendor/bin/phpunit tests/OfficialTestSuiteTest.php
 *
 * To generate a detailed compatibility report:
 *   php tests/compatibility-report.php
 */
#[Group('official')]
class OfficialTestSuiteTest extends TestCase
{
    protected DjotConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DjotConverter();
        // Official djot renders soft breaks as newlines
        $this->converter->getRenderer()->setSoftBreakMode(SoftBreakMode::Newline);
    }

    /**
     * Parse test cases from official .test files
     *
     * Format:
     * ```
     * input
     * .
     * expected output
     * ```
     *
     * @return array<string, array{input: string, expected: string, file: string, index: int}>
     */
    public static function parseTestFile(string $filename): array
    {
        $path = __DIR__ . '/official/' . $filename;
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        $tests = [];

        // Match code blocks containing test cases
        // Support both triple and quad backtick fences (quad backticks allow triple backticks in content)
        preg_match_all('/(`{3,})\n(.*?)\n\1/s', $content, $matches);

        $index = 0;
        foreach ($matches[2] as $block) {
            // Split on the separator line (a single `.`)
            $parts = preg_split('/\n\.\n/', $block, 2);
            if (count($parts) === 2) {
                $input = $parts[0];
                $expected = $parts[1];

                $testName = basename($filename, '.test') . '_' . $index;
                $tests[$testName] = [
                    'input' => $input,
                    'expected' => $expected,
                    'file' => $filename,
                    'index' => $index,
                ];
                $index++;
            }
        }

        return $tests;
    }

    /**
     * Get all test files from the official directory
     *
     * @return array<string>
     */
    public static function getTestFiles(): array
    {
        $dir = __DIR__ . '/official';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.test');

        return array_map('basename', $files ?: []);
    }

    /**
     * Load all tests from all official test files
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function officialTestProvider(): array
    {
        $allTests = [];

        foreach (self::getTestFiles() as $file) {
            $tests = self::parseTestFile($file);
            foreach ($tests as $name => $data) {
                $allTests[$name] = [
                    $data['input'],
                    $data['expected'],
                    $data['file'],
                    $data['index'],
                ];
            }
        }

        return $allTests;
    }

    /**
     * Run tests from official test suite
     *
     * Note: Some tests may fail due to implementation differences.
     * This is expected and documented.
     */
    #[DataProvider('officialTestProvider')]
    public function testOfficialSuite(string $input, string $expected, string $file, int $index): void
    {
        $result = $this->converter->convert($input);

        // Normalize whitespace for comparison
        $result = $this->normalizeOutput($result);
        $expected = $this->normalizeOutput($expected);

        $this->assertSame(
            $expected,
            $result,
            sprintf("Failed test #%d from %s\nInput:\n%s", $index, $file, $input),
        );
    }

    /**
     * Normalize output for comparison
     */
    protected function normalizeOutput(string $output): string
    {
        // Trim trailing whitespace from each line and normalize line endings
        $lines = explode("\n", $output);
        $lines = array_map('rtrim', $lines);

        // Remove trailing empty lines
        $lineCount = count($lines);
        while ($lineCount > 0 && $lines[$lineCount - 1] === '') {
            array_pop($lines);
            $lineCount--;
        }

        return implode("\n", $lines);
    }

    /**
     * Count passing and failing tests for reporting
     */
    public static function runCompatibilityReport(): array
    {
        $converter = new DjotConverter();
        $results = [
            'total' => 0,
            'passing' => 0,
            'failing' => 0,
            'by_file' => [],
        ];

        foreach (self::getTestFiles() as $file) {
            $tests = self::parseTestFile($file);
            $fileResults = ['total' => 0, 'passing' => 0, 'failing' => 0];

            foreach ($tests as $data) {
                $results['total']++;
                $fileResults['total']++;

                $result = $converter->convert($data['input']);

                // Normalize for comparison
                $resultNorm = trim($result);
                $expectedNorm = trim($data['expected']);

                // Normalize whitespace
                $resultNorm = preg_replace('/\s+/', ' ', $resultNorm);
                $expectedNorm = preg_replace('/\s+/', ' ', $expectedNorm);

                if ($resultNorm === $expectedNorm) {
                    $results['passing']++;
                    $fileResults['passing']++;
                } else {
                    $results['failing']++;
                    $fileResults['failing']++;
                }
            }

            $results['by_file'][$file] = $fileResults;
        }

        return $results;
    }
}
