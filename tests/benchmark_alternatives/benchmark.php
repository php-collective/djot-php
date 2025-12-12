#!/usr/bin/env php
<?php
/**
 * PHP Markup Libraries Performance Comparison
 *
 * Compares djot-php against popular PHP Markdown parsers:
 * - league/commonmark (CommonMark spec compliant)
 * - erusev/parsedown (fast, popular)
 * - michelf/php-markdown (original PHP markdown)
 *
 * Usage:
 *   php tests/benchmark_alternatives/benchmark.php [--iterations=50] [--json]
 */

declare(strict_types=1);

// Use local vendor if available (isolated dependencies), otherwise fall back to main project
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    echo "Dependencies not installed. Run:\n";
    echo "  cd tests/benchmark_alternatives && composer install\n";
    exit(1);
}

use Composer\InstalledVersions;
use Djot\DjotConverter;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Michelf\Markdown;
use Michelf\MarkdownExtra;

// Parse CLI arguments
$options = getopt('', ['iterations:', 'warmup:', 'json', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
PHP Markup Libraries Performance Comparison

Usage: php benchmark-php-alternatives.php [options]

Options:
  --iterations=N   Number of iterations per test (default: 50)
  --warmup=N       Number of warmup iterations (default: 5)
  --json           Output results as JSON
  --help           Show this help

HELP;
    exit(0);
}

$iterations = (int)($options['iterations'] ?? 50);
$warmup = (int)($options['warmup'] ?? 5);
$jsonOutput = isset($options['json']);

// Generate test content in both Djot and Markdown formats
function generateDjotContent(int $paragraphs): string
{
    $content = "# Performance Test Document\n\n";

    for ($i = 0; $i < $paragraphs; $i++) {
        // Headings
        if ($i % 20 === 0) {
            $content .= '## Section ' . (int)($i / 20 + 1) . "\n\n";
        }

        // Regular paragraph with inline formatting
        $content .= "This is paragraph {$i} with *strong text* and _emphasized text_. ";
        $content .= "Here's a [link](https://example.com) and `inline code`.\n\n";

        // Lists every 10 paragraphs
        if ($i % 10 === 5) {
            $content .= "- List item one with *bold*\n";
            $content .= "- List item two with _italic_\n";
            $content .= "  - Nested item\n";
            $content .= "  - Another nested\n";
            $content .= "- List item three\n\n";
        }

        // Code block every 15 paragraphs
        if ($i % 15 === 10) {
            $content .= "```php\n";
            $content .= "function example{$i}(): int {\n";
            $content .= "    return {$i};\n";
            $content .= "}\n";
            $content .= "```\n\n";
        }

        // Blockquote every 20 paragraphs
        if ($i % 20 === 15) {
            $content .= "> This is a blockquote with *emphasis*.\n";
            $content .= "> Second line of the quote.\n\n";
        }
    }

    return $content;
}

function generateMarkdownContent(int $paragraphs): string
{
    $content = "# Performance Test Document\n\n";

    for ($i = 0; $i < $paragraphs; $i++) {
        // Headings
        if ($i % 20 === 0) {
            $content .= '## Section ' . (int)($i / 20 + 1) . "\n\n";
        }

        // Regular paragraph with inline formatting (Markdown uses ** for bold)
        $content .= "This is paragraph {$i} with **strong text** and *emphasized text*. ";
        $content .= "Here's a [link](https://example.com) and `inline code`.\n\n";

        // Lists every 10 paragraphs
        if ($i % 10 === 5) {
            $content .= "- List item one with **bold**\n";
            $content .= "- List item two with *italic*\n";
            $content .= "  - Nested item\n";
            $content .= "  - Another nested\n";
            $content .= "- List item three\n\n";
        }

        // Code block every 15 paragraphs
        if ($i % 15 === 10) {
            $content .= "```php\n";
            $content .= "function example{$i}(): int {\n";
            $content .= "    return {$i};\n";
            $content .= "}\n";
            $content .= "```\n\n";
        }

        // Blockquote every 20 paragraphs
        if ($i % 20 === 15) {
            $content .= "> This is a blockquote with *emphasis*.\n";
            $content .= "> Second line of the quote.\n\n";
        }
    }

    return $content;
}

// Benchmark function
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
        'stddev' => calculateStdDev($times),
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

// Test fixtures of different sizes
$fixtures = [
    'tiny' => ['paragraphs' => 10, 'label' => '~1 KB'],
    'small' => ['paragraphs' => 50, 'label' => '~5 KB'],
    'medium' => ['paragraphs' => 200, 'label' => '~20 KB'],
    'large' => ['paragraphs' => 500, 'label' => '~50 KB'],
    'huge' => ['paragraphs' => 1000, 'label' => '~100 KB'],
];

// Initialize parsers
$djot = new DjotConverter();
$commonmark = new CommonMarkConverter();
$gfm = new GithubFlavoredMarkdownConverter();
$parsedown = new Parsedown();
$parsedownExtra = class_exists(ParsedownExtra::class) ? new ParsedownExtra() : null;
$michelfMarkdown = new Markdown();
$michelfExtra = new MarkdownExtra();

$parsers = [
    'djot-php' => [
        'name' => 'djot-php',
        'version' => 'dev',
        'type' => 'djot',
        'parser' => fn ($content) => $djot->convert($content),
    ],
    'commonmark' => [
        'name' => 'league/commonmark',
        'version' => InstalledVersions::getPrettyVersion('league/commonmark') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $commonmark->convert($content)->getContent(),
    ],
    'gfm' => [
        'name' => 'league/commonmark (GFM)',
        'version' => InstalledVersions::getPrettyVersion('league/commonmark') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $gfm->convert($content)->getContent(),
    ],
    'parsedown' => [
        'name' => 'erusev/parsedown',
        'version' => InstalledVersions::getPrettyVersion('erusev/parsedown') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $parsedown->text($content),
    ],
    'michelf' => [
        'name' => 'michelf/php-markdown',
        'version' => InstalledVersions::getPrettyVersion('michelf/php-markdown') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $michelfMarkdown->transform($content),
    ],
    'michelf-extra' => [
        'name' => 'michelf/php-markdown (Extra)',
        'version' => InstalledVersions::getPrettyVersion('michelf/php-markdown') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $michelfExtra->transform($content),
    ],
];

if ($parsedownExtra) {
    $parsers['parsedown-extra'] = [
        'name' => 'erusev/parsedown-extra',
        'version' => InstalledVersions::getPrettyVersion('erusev/parsedown-extra') ?? 'unknown',
        'type' => 'markdown',
        'parser' => fn ($content) => $parsedownExtra->text($content),
    ];
}

// Run benchmarks
$results = [
    'meta' => [
        'php_version' => PHP_VERSION,
        'iterations' => $iterations,
        'warmup' => $warmup,
        'timestamp' => date('c'),
        'system' => php_uname(),
    ],
    'libraries' => [],
    'benchmarks' => [],
];

foreach ($parsers as $key => $parser) {
    $results['libraries'][$key] = [
        'name' => $parser['name'],
        'version' => $parser['version'],
        'type' => $parser['type'],
    ];
}

if (!$jsonOutput) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║              PHP Markup Libraries Performance Comparison                     ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
    echo 'PHP Version: ' . PHP_VERSION . "\n";
    echo "Iterations: {$iterations}, Warmup: {$warmup}\n";
    echo 'Date: ' . date('Y-m-d H:i:s') . "\n\n";

    echo "Libraries tested:\n";
    foreach ($parsers as $key => $parser) {
        echo "  • {$parser['name']} v{$parser['version']}\n";
    }
    echo "\n";
}

// Run benchmarks for each fixture size
foreach ($fixtures as $fixtureName => $fixtureConfig) {
    $djotContent = generateDjotContent($fixtureConfig['paragraphs']);
    $markdownContent = generateMarkdownContent($fixtureConfig['paragraphs']);

    $djotSize = strlen($djotContent);
    $mdSize = strlen($markdownContent);

    if (!$jsonOutput) {
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  Fixture: {$fixtureName} ({$fixtureConfig['label']})\n";
        echo '  Djot input: ' . formatSize($djotSize) . ', Markdown input: ' . formatSize($mdSize) . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        printf(
            "  %-28s %12s %12s %12s %12s\n",
            'Library',
            'Mean',
            'Median',
            'P95',
            'Throughput',
        );
        echo '  ' . str_repeat('─', 76) . "\n";
    }

    $fixtureResults = [];

    foreach ($parsers as $key => $parser) {
        $content = $parser['type'] === 'djot' ? $djotContent : $markdownContent;
        $size = $parser['type'] === 'djot' ? $djotSize : $mdSize;

        $stats = benchmark(fn () => ($parser['parser'])($content), $iterations, $warmup);
        $throughput = $size / ($stats['mean'] / 1000); // bytes per second

        $fixtureResults[$key] = [
            'input_size' => $size,
            'stats' => $stats,
            'throughput_bps' => $throughput,
        ];

        if (!$jsonOutput) {
            printf(
                "  %-28s %12s %12s %12s %10s/s\n",
                $parser['name'],
                formatMs($stats['mean']),
                formatMs($stats['median']),
                formatMs($stats['p95']),
                formatSize((int)$throughput),
            );
        }
    }

    $results['benchmarks'][$fixtureName] = [
        'config' => $fixtureConfig,
        'djot_size' => $djotSize,
        'markdown_size' => $mdSize,
        'results' => $fixtureResults,
    ];

    if (!$jsonOutput) {
        echo "\n";
    }
}

// Calculate relative performance comparison
if (!$jsonOutput) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                        Relative Performance (medium fixture)                 ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

    $mediumResults = $results['benchmarks']['medium']['results'];
    $djotMean = $mediumResults['djot-php']['stats']['mean'];

    printf("  %-28s %12s %15s\n", 'Library', 'Mean Time', 'vs djot-php');
    echo '  ' . str_repeat('─', 58) . "\n";

    // Sort by mean time
    uasort($mediumResults, fn ($a, $b) => $a['stats']['mean'] <=> $b['stats']['mean']);

    foreach ($mediumResults as $key => $result) {
        $mean = $result['stats']['mean'];
        $ratio = $mean / $djotMean;

        if ($ratio < 1) {
            $comparison = sprintf('%.2fx faster', 1 / $ratio);
        } elseif ($ratio > 1) {
            $comparison = sprintf('%.2fx slower', $ratio);
        } else {
            $comparison = 'baseline';
        }

        printf(
            "  %-28s %12s %15s\n",
            $parsers[$key]['name'],
            formatMs($mean),
            $key === 'djot-php' ? '(baseline)' : $comparison,
        );
    }

    echo "\n";
}

// Memory comparison
if (!$jsonOutput) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                           Memory Usage (medium fixture)                      ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

    printf("  %-28s %15s %15s\n", 'Library', 'Peak Memory', 'Memory Delta');
    echo '  ' . str_repeat('─', 60) . "\n";
}

$memoryResults = [];
$mediumDjot = generateDjotContent(200);
$mediumMd = generateMarkdownContent(200);

foreach ($parsers as $key => $parser) {
    $content = $parser['type'] === 'djot' ? $mediumDjot : $mediumMd;

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);

    // Run conversion
    $output = ($parser['parser'])($content);

    $memAfter = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);

    $memoryResults[$key] = [
        'memory_before' => $memBefore,
        'memory_after' => $memAfter,
        'memory_delta' => $memAfter - $memBefore,
        'memory_peak' => $memPeak,
        'output_size' => strlen($output),
    ];

    unset($output);

    if (!$jsonOutput) {
        printf(
            "  %-28s %15s %15s\n",
            $parser['name'],
            formatSize($memPeak),
            formatSize($memoryResults[$key]['memory_delta']),
        );
    }
}

$results['memory'] = $memoryResults;

if (!$jsonOutput) {
    echo "\n";
}

// Feature comparison note
if (!$jsonOutput) {
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                                    Notes                                     ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";
    echo "  • djot-php parses Djot syntax; others parse Markdown\n";
    echo "  • Test content is equivalent but uses each format's syntax\n";
    echo "  • Parsedown is known for speed; CommonMark for spec compliance\n";
    echo "  • djot-php includes smart typography, footnotes, and more features\n";
    echo "  • Lower times = better performance\n";
    echo "\n";

    echo "Feature comparison (supported features):\n";
    echo "  ┌─────────────────────────┬────────────┬────────────┬────────────┬───────────┐\n";
    echo "  │ Feature                 │ djot-php   │ CommonMark │ Parsedown  │ Michelf   │\n";
    echo "  ├─────────────────────────┼────────────┼────────────┼────────────┼───────────┤\n";
    echo "  │ Basic formatting        │     ✓      │     ✓      │     ✓      │     ✓     │\n";
    echo "  │ Tables                  │     ✓      │  GFM only  │     ✓      │   Extra   │\n";
    echo "  │ Footnotes               │     ✓      │     ✗      │     ✗      │   Extra   │\n";
    echo "  │ Definition lists        │     ✓      │     ✗      │     ✗      │   Extra   │\n";
    echo "  │ Task lists              │     ✓      │  GFM only  │     ✗      │     ✗     │\n";
    echo "  │ Smart typography        │     ✓      │     ✗      │     ✗      │     ✗     │\n";
    echo "  │ Math expressions        │     ✓      │     ✗      │     ✗      │     ✗     │\n";
    echo "  │ Attributes              │     ✓      │     ✗      │     ✗      │   Extra   │\n";
    echo "  │ Highlight/Insert/Delete │     ✓      │     ✗      │     ✗      │     ✗     │\n";
    echo "  │ Super/Subscript         │     ✓      │     ✗      │     ✗      │     ✗     │\n";
    echo "  │ Divs/Sections           │     ✓      │     ✗      │     ✗      │     ✗     │\n";
    echo "  │ Safe mode               │     ✓      │     ✓      │     ✓      │     ✓     │\n";
    echo "  └─────────────────────────┴────────────┴────────────┴────────────┴───────────┘\n";
    echo "\n";
}

// JSON output
if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
}

// Save results
$resultsDir = __DIR__ . '/results';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0755, true);
}

$filename = $resultsDir . '/php-alternatives-' . date('Y-m-d\TH-i-s') . '.json';
file_put_contents($filename, json_encode($results, JSON_PRETTY_PRINT));

if (!$jsonOutput) {
    echo "Results saved to: {$filename}\n";
}
