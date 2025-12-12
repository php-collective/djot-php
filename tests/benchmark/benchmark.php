#!/usr/bin/env php
<?php
/**
 * Djot-PHP Performance Benchmark
 *
 * Measures parsing and rendering performance across different document sizes
 * and complexity levels.
 *
 * Usage:
 *   php tests/benchmark/benchmark.php [--iterations=100] [--warmup=10] [--json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Djot\DjotConverter;
use Djot\Profile;

// Parse CLI arguments
$options = getopt('', ['iterations:', 'warmup:', 'json', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Djot-PHP Performance Benchmark

Usage: php benchmark.php [options]

Options:
  --iterations=N   Number of iterations per test (default: 100)
  --warmup=N       Number of warmup iterations (default: 10)
  --json           Output results as JSON
  --help           Show this help

HELP;
    exit(0);
}

$iterations = (int)($options['iterations'] ?? 100);
$warmup = (int)($options['warmup'] ?? 10);
$jsonOutput = isset($options['json']);

// Test fixtures
$fixtures = [
    'tiny' => generateFixture('tiny', 10),
    'small' => generateFixture('small', 100),
    'medium' => generateFixture('medium', 500),
    'large' => generateFixture('large', 2000),
    'huge' => generateFixture('huge', 10000),
    'complex' => generateComplexFixture(),
    'tables' => generateTableFixture(),
    'code_heavy' => generateCodeHeavyFixture(),
    'inline_heavy' => generateInlineHeavyFixture(),
    'nested_lists' => generateNestedListsFixture(),
];

function generateFixture(string $name, int $paragraphs): string
{
    $content = "# Document: {$name}\n\n";
    for ($i = 0; $i < $paragraphs; $i++) {
        $content .= "This is paragraph {$i} with some *bold* and _italic_ text. ";
        $content .= "Here's a [link](https://example.com) and `inline code`.\n\n";
    }

    return $content;
}

function generateComplexFixture(): string
{
    $content = <<<'DJOT'
# Complex Document Test

This document tests various djot features for performance benchmarking.

## Headings and Paragraphs

Regular paragraph with *strong emphasis* and _regular emphasis_.
Another line with `inline code` and a [link](https://example.com "Title").

### Nested Content

> Block quote with multiple lines.
> Second line of the quote.
>
> Another paragraph in the quote.

## Lists

- Unordered item 1
- Unordered item 2
  - Nested item 2.1
  - Nested item 2.2
    - Deep nested 2.2.1
- Unordered item 3

1. Ordered item 1
2. Ordered item 2
3. Ordered item 3

- [ ] Task unchecked
- [x] Task checked

## Definition Lists

: Term 1
  Definition of term 1

: Term 2
  Definition of term 2

## Code Blocks

```python
def hello_world():
    """A simple function."""
    print("Hello, World!")
    return 42

class Example:
    def __init__(self):
        self.value = 100
```

```javascript
function fibonacci(n) {
    if (n <= 1) return n;
    return fibonacci(n - 1) + fibonacci(n - 2);
}
```

## Tables

| Column A | Column B | Column C | Column D |
|----------|----------|----------|----------|
| Cell 1   | Cell 2   | Cell 3   | Cell 4   |
| Cell 5   | Cell 6   | Cell 7   | Cell 8   |
| Cell 9   | Cell 10  | Cell 11  | Cell 12  |

## Inline Elements

This paragraph has {+inserted+} and {-deleted-} text.
Also {=highlighted=} and H{~2~}O with {^superscript^}.

## Math

Inline math: $E = mc^2$

Display math:

$$
\int_0^\infty e^{-x^2} dx = \frac{\sqrt{\pi}}{2}
$$

## Footnotes

This has a footnote[^1] and another[^2].

[^1]: First footnote content.
[^2]: Second footnote content.

## Divs and Attributes

::: warning
This is a warning block.
:::

{.special #unique-id}
This paragraph has attributes.

## Smart Typography

"Quoted text" with 'single quotes' and... ellipsis.
Em-dash---and en-dash--here.

DJOT;

    // Repeat content to make it larger
    return str_repeat($content, 5);
}

function generateTableFixture(): string
{
    $content = "# Table-Heavy Document\n\n";
    for ($t = 0; $t < 20; $t++) {
        $content .= "## Table {$t}\n\n";
        $content .= "| Col A | Col B | Col C | Col D | Col E |\n";
        $content .= "|-------|-------|-------|-------|-------|\n";
        for ($r = 0; $r < 10; $r++) {
            $content .= "| A{$r}   | B{$r}   | C{$r}   | D{$r}   | E{$r}   |\n";
        }
        $content .= "\n";
    }

    return $content;
}

function generateCodeHeavyFixture(): string
{
    $content = "# Code-Heavy Document\n\n";
    $languages = ['php', 'javascript', 'python', 'rust', 'go', 'java'];

    for ($i = 0; $i < 50; $i++) {
        $lang = $languages[$i % count($languages)];
        $content .= "## Code Block {$i}\n\n";
        $content .= "```{$lang}\n";
        $content .= "// This is code block {$i}\n";
        $content .= "function example{$i}() {\n";
        $content .= "    // Some code here\n";
        $content .= "    return {$i};\n";
        $content .= "}\n";
        $content .= "```\n\n";
    }

    return $content;
}

function generateInlineHeavyFixture(): string
{
    $content = "# Inline-Heavy Document\n\n";
    for ($p = 0; $p < 100; $p++) {
        $content .= "Paragraph {$p}: ";
        $content .= '*bold* _italic_ `code` ';
        $content .= '[link](url) ![img](img.jpg) ';
        $content .= '{+ins+} {-del-} {=mark=} ';
        $content .= 'H{~2~}O x{^2^} ';
        $content .= ':symbol: $math$ ';
        $content .= "\"smart quotes\" and---dashes.\n\n";
    }

    return $content;
}

function generateNestedListsFixture(): string
{
    $content = "# Nested Lists Document\n\n";
    for ($l = 0; $l < 20; $l++) {
        $content .= "## List Group {$l}\n\n";
        $content .= "- Level 1 Item A\n";
        $content .= "  - Level 2 Item A.1\n";
        $content .= "    - Level 3 Item A.1.1\n";
        $content .= "      - Level 4 Item A.1.1.1\n";
        $content .= "    - Level 3 Item A.1.2\n";
        $content .= "  - Level 2 Item A.2\n";
        $content .= "- Level 1 Item B\n";
        $content .= "  - Level 2 Item B.1\n";
        $content .= "- Level 1 Item C\n\n";

        $content .= "1. Ordered 1\n";
        $content .= "   1. Nested 1.1\n";
        $content .= "   2. Nested 1.2\n";
        $content .= "2. Ordered 2\n";
        $content .= "3. Ordered 3\n\n";
    }

    return $content;
}

function benchmark(callable $fn, int $iterations, int $warmup): array
{
    // Warmup
    for ($i = 0; $i < $warmup; $i++) {
        $fn();
    }

    // Collect timings
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $end = hrtime(true);
        $times[] = ($end - $start) / 1e6; // Convert to milliseconds
    }

    sort($times);
    $count = count($times);

    return [
        'min' => $times[0],
        'max' => $times[$count - 1],
        'mean' => array_sum($times) / $count,
        'median' => $count % 2 === 0
            ? ($times[$count / 2 - 1] + $times[$count / 2]) / 2
            : $times[(int)($count / 2)],
        'p95' => $times[(int)($count * 0.95)],
        'p99' => $times[(int)($count * 0.99)],
        'stddev' => calculateStdDev($times),
        'iterations' => $iterations,
    ];
}

function calculateStdDev(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = array_sum($values) / $count;
    $variance = array_sum(array_map(fn ($x) => pow($x - $mean, 2), $values)) / ($count - 1);

    return sqrt($variance);
}

function formatMs(float $ms): string
{
    if ($ms < 1) {
        return sprintf('%.2f µs', $ms * 1000);
    }
    if ($ms < 1000) {
        return sprintf('%.2f ms', $ms);
    }

    return sprintf('%.2f s', $ms / 1000);
}

function formatSize(int $bytes): string
{
    if ($bytes < 1024) {
        return "{$bytes} B";
    }
    if ($bytes < 1024 * 1024) {
        return sprintf('%.1f KB', $bytes / 1024);
    }

    return sprintf('%.1f MB', $bytes / (1024 * 1024));
}

// Run benchmarks
$results = [];
$converter = new DjotConverter();

if (!$jsonOutput) {
    echo "Djot-PHP Performance Benchmark\n";
    echo "==============================\n";
    echo 'PHP Version: ' . PHP_VERSION . "\n";
    echo "Iterations: {$iterations}, Warmup: {$warmup}\n";
    echo "\n";
}

// Basic conversion benchmarks
if (!$jsonOutput) {
    echo "## Document Size Benchmarks\n\n";
    printf(
        "%-15s %10s %12s %12s %12s %12s\n",
        'Fixture',
        'Size',
        'Mean',
        'Median',
        'P95',
        'Throughput',
    );
    echo str_repeat('-', 75) . "\n";
}

foreach ($fixtures as $name => $content) {
    $size = strlen($content);

    $stats = benchmark(function () use ($converter, $content) {
        $converter->convert($content);
    }, $iterations, $warmup);

    $throughput = $size / ($stats['mean'] / 1000); // bytes per second

    $results['conversion'][$name] = [
        'size_bytes' => $size,
        'stats' => $stats,
        'throughput_bps' => $throughput,
    ];

    if (!$jsonOutput) {
        printf(
            "%-15s %10s %12s %12s %12s %10s/s\n",
            $name,
            formatSize($size),
            formatMs($stats['mean']),
            formatMs($stats['median']),
            formatMs($stats['p95']),
            formatSize((int)$throughput),
        );
    }
}

// Profile benchmarks
if (!$jsonOutput) {
    echo "\n## Profile Benchmarks (medium fixture)\n\n";
    printf("%-15s %12s %12s %12s\n", 'Profile', 'Mean', 'Median', 'P95');
    echo str_repeat('-', 55) . "\n";
}

$profiles = [
    'none' => null,
    'full' => Profile::full(),
    'article' => Profile::article(),
    'comment' => Profile::comment(),
    'minimal' => Profile::minimal(),
];

$mediumContent = $fixtures['medium'];

foreach ($profiles as $name => $profile) {
    $conv = $profile ? new DjotConverter(profile: $profile) : new DjotConverter();

    $stats = benchmark(function () use ($conv, $mediumContent) {
        $conv->convert($mediumContent);
    }, $iterations, $warmup);

    $results['profiles'][$name] = $stats;

    if (!$jsonOutput) {
        printf(
            "%-15s %12s %12s %12s\n",
            $name,
            formatMs($stats['mean']),
            formatMs($stats['median']),
            formatMs($stats['p95']),
        );
    }
}

// SafeMode benchmark
if (!$jsonOutput) {
    echo "\n## SafeMode Benchmarks (medium fixture)\n\n";
    printf("%-15s %12s %12s %12s\n", 'Mode', 'Mean', 'Median', 'P95');
    echo str_repeat('-', 55) . "\n";
}

$safeModes = [
    'disabled' => false,
    'enabled' => true,
];

foreach ($safeModes as $name => $safeMode) {
    $conv = new DjotConverter(safeMode: $safeMode);

    $stats = benchmark(function () use ($conv, $mediumContent) {
        $conv->convert($mediumContent);
    }, $iterations, $warmup);

    $results['safeMode'][$name] = $stats;

    if (!$jsonOutput) {
        printf(
            "%-15s %12s %12s %12s\n",
            $name,
            formatMs($stats['mean']),
            formatMs($stats['median']),
            formatMs($stats['p95']),
        );
    }
}

// Parse-only benchmark
if (!$jsonOutput) {
    echo "\n## Parse vs Render (medium fixture)\n\n";
    printf("%-15s %12s %12s %12s\n", 'Phase', 'Mean', 'Median', 'P95');
    echo str_repeat('-', 55) . "\n";
}

// Full conversion (parse + render)
$stats = benchmark(function () use ($converter, $mediumContent) {
    $converter->convert($mediumContent);
}, $iterations, $warmup);
$results['phases']['full'] = $stats;

if (!$jsonOutput) {
    printf(
        "%-15s %12s %12s %12s\n",
        'full',
        formatMs($stats['mean']),
        formatMs($stats['median']),
        formatMs($stats['p95']),
    );
}

// Parse only
$stats = benchmark(function () use ($converter, $mediumContent) {
    $converter->parse($mediumContent);
}, $iterations, $warmup);
$results['phases']['parse'] = $stats;

if (!$jsonOutput) {
    printf(
        "%-15s %12s %12s %12s\n",
        'parse',
        formatMs($stats['mean']),
        formatMs($stats['median']),
        formatMs($stats['p95']),
    );
}

// Memory usage
if (!$jsonOutput) {
    echo "\n## Memory Usage\n\n";
}

$memoryResults = [];
foreach (['small', 'medium', 'large'] as $fixtureName) {
    $content = $fixtures[$fixtureName];

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);

    $doc = $converter->parse($content);
    $html = $converter->convert($content);

    $memAfter = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);

    $memoryResults[$fixtureName] = [
        'input_size' => strlen($content),
        'output_size' => strlen($html),
        'memory_delta' => $memAfter - $memBefore,
        'memory_peak' => $memPeak,
    ];

    unset($doc, $html);
}

$results['memory'] = $memoryResults;

if (!$jsonOutput) {
    printf(
        "%-10s %12s %12s %12s %12s\n",
        'Fixture',
        'Input',
        'Output',
        'Delta',
        'Peak',
    );
    echo str_repeat('-', 60) . "\n";
    foreach ($memoryResults as $name => $mem) {
        printf(
            "%-10s %12s %12s %12s %12s\n",
            $name,
            formatSize($mem['input_size']),
            formatSize($mem['output_size']),
            formatSize($mem['memory_delta']),
            formatSize($mem['memory_peak']),
        );
    }
}

// Summary
$results['meta'] = [
    'php_version' => PHP_VERSION,
    'iterations' => $iterations,
    'warmup' => $warmup,
    'timestamp' => date('c'),
];

if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n## Summary\n\n";
    $complexStats = $results['conversion']['complex']['stats'];
    $throughput = $results['conversion']['complex']['throughput_bps'];
    echo "Complex document ({$results['conversion']['complex']['size_bytes']} bytes):\n";
    echo '  Mean: ' . formatMs($complexStats['mean']) . "\n";
    echo '  Throughput: ' . formatSize((int)$throughput) . "/s\n";
    echo "\nBenchmark complete.\n";
}
