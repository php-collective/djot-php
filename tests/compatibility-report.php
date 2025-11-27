#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate a compatibility report against the official djot.js test suite
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Djot\DjotConverter;

function parseTestFile(string $filename): array
{
    $path = __DIR__ . '/official/' . $filename;
    if (!file_exists($path)) {
        return [];
    }

    $content = file_get_contents($path);
    $tests = [];

    // Match code blocks containing test cases
    preg_match_all('/```\n(.*?)\n```/s', $content, $matches);

    $index = 0;
    foreach ($matches[1] as $block) {
        // Split on the separator line (a single `.`)
        $parts = preg_split('/\n\.\n/', $block, 2);
        if (count($parts) === 2) {
            $input = $parts[0];
            $expected = $parts[1];

            $tests[] = [
                'input' => $input,
                'expected' => $expected,
                'index' => $index,
            ];
            $index++;
        }
    }

    return $tests;
}

function normalizeOutput(string $output): string
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

$converter = new DjotConverter();
$dir = __DIR__ . '/official';
$files = glob($dir . '/*.test');

$results = [];
$totalTests = 0;
$totalPassing = 0;

echo "# Djot PHP Compatibility Report\n";
echo "==============================\n\n";
echo "Comparison against official djot.js test suite\n";
echo "Source: https://github.com/jgm/djot.js/tree/main/test\n\n";

foreach ($files as $filepath) {
    $file = basename($filepath);
    $tests = parseTestFile($file);
    $passing = 0;
    $failing = 0;
    $failedTests = [];

    foreach ($tests as $test) {
        $result = $converter->convert($test['input']);
        $resultNorm = normalizeOutput($result);
        $expectedNorm = normalizeOutput($test['expected']);

        if ($resultNorm === $expectedNorm) {
            $passing++;
        } else {
            $failing++;
            $failedTests[] = [
                'index' => $test['index'],
                'input' => $test['input'],
                'expected' => $expectedNorm,
                'actual' => $resultNorm,
            ];
        }
    }

    $total = $passing + $failing;
    $pct = $total > 0 ? round($passing / $total * 100) : 0;
    $totalTests += $total;
    $totalPassing += $passing;

    $results[$file] = [
        'total' => $total,
        'passing' => $passing,
        'failing' => $failing,
        'percentage' => $pct,
        'failures' => $failedTests,
    ];
}

// Summary
$totalPct = $totalTests > 0 ? round($totalPassing / $totalTests * 100) : 0;
echo "## Summary\n\n";
echo "| File | Total | Passing | Failing | Compatibility |\n";
echo "|------|-------|---------|---------|---------------|\n";

foreach ($results as $file => $data) {
    printf(
        "| %s | %d | %d | %d | %d%% |\n",
        $file,
        $data['total'],
        $data['passing'],
        $data['failing'],
        $data['percentage'],
    );
}

echo "|------|-------|---------|---------|---------------|\n";
printf("| **TOTAL** | **%d** | **%d** | **%d** | **%d%%** |\n\n", $totalTests, $totalPassing, $totalTests - $totalPassing, $totalPct);

// Detailed failures
echo "## Detailed Failures\n\n";

foreach ($results as $file => $data) {
    if (empty($data['failures'])) {
        continue;
    }

    echo "### $file\n\n";

    foreach (array_slice($data['failures'], 0, 3) as $failure) {
        echo "**Test #{$failure['index']}**\n\n";
        echo "Input:\n```\n" . $failure['input'] . "\n```\n\n";
        echo "Expected:\n```html\n" . $failure['expected'] . "\n```\n\n";
        echo "Actual:\n```html\n" . $failure['actual'] . "\n```\n\n";
        echo "---\n\n";
    }

    if (count($data['failures']) > 3) {
        echo '... and ' . (count($data['failures']) - 3) . " more failures\n\n";
    }
}
