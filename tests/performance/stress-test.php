#!/usr/bin/env php
<?php
/**
 * Djot-PHP Stress Test Suite
 *
 * Pushes the parser to its limits with extreme cases.
 *
 * Usage:
 *   php tests/performance/stress-test.php [--scenario=all] [--json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Djot\DjotConverter;
use Djot\Profile;

// Parse CLI arguments
$options = getopt('', ['scenario:', 'json', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Djot-PHP Stress Test Suite

Usage: php stress-test.php [options]

Options:
  --scenario=NAME   Run specific scenario (default: all)
                    Available: deep_nesting, many_paragraphs, huge_table,
                               inline_heavy, many_links, pathological,
                               concurrent, memory_pressure
  --json            Output results as JSON
  --help            Show this help

HELP;
    exit(0);
}

$requestedScenario = $options['scenario'] ?? 'all';
$jsonOutput = isset($options['json']);

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
        return sprintf('%.2f KB', $bytes / 1024);
    }
    return sprintf('%.2f MB', $bytes / (1024 * 1024));
}

function runScenario(string $name, string $description, callable $generator, int $iterations = 10): array
{
    global $jsonOutput;

    if (!$jsonOutput) {
        echo "\n### {$name}\n";
        echo "{$description}\n\n";
    }

    $converter = new DjotConverter();

    // Generate content
    $content = $generator();
    $size = strlen($content);

    if (!$jsonOutput) {
        echo "Input size: " . formatSize($size) . "\n";
    }

    // Warmup
    try {
        $converter->convert($content);
    } catch (Throwable $e) {
        return [
            'name' => $name,
            'status' => 'error',
            'error' => $e->getMessage(),
            'size' => $size,
        ];
    }

    // Benchmark
    $times = [];
    $peakMemory = 0;

    for ($i = 0; $i < $iterations; $i++) {
        gc_collect_cycles();
        $memBefore = memory_get_usage(true);

        $start = hrtime(true);
        try {
            $html = $converter->convert($content);
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'status' => 'error',
                'error' => $e->getMessage(),
                'size' => $size,
                'iteration' => $i,
            ];
        }
        $end = hrtime(true);

        $times[] = ($end - $start) / 1e6;
        $peakMemory = max($peakMemory, memory_get_peak_usage(true));
    }

    sort($times);
    $count = count($times);

    $result = [
        'name' => $name,
        'status' => 'success',
        'size' => $size,
        'output_size' => strlen($html ?? ''),
        'iterations' => $iterations,
        'stats' => [
            'min' => $times[0],
            'max' => $times[$count - 1],
            'mean' => array_sum($times) / $count,
            'median' => $count % 2 === 0
                ? ($times[$count / 2 - 1] + $times[$count / 2]) / 2
                : $times[(int) ($count / 2)],
            'p95' => $times[(int) ($count * 0.95)],
        ],
        'peak_memory' => $peakMemory,
        'throughput' => $size / ((array_sum($times) / $count) / 1000),
    ];

    if (!$jsonOutput) {
        echo "Output size: " . formatSize($result['output_size']) . "\n";
        echo "Mean time: " . formatMs($result['stats']['mean']) . "\n";
        echo "P95 time: " . formatMs($result['stats']['p95']) . "\n";
        echo "Peak memory: " . formatSize($result['peak_memory']) . "\n";
        echo "Throughput: " . formatSize((int) $result['throughput']) . "/s\n";
        echo "Status: ✓ PASS\n";
    }

    return $result;
}

// Scenario definitions
$scenarios = [
    'deep_nesting' => [
        'description' => 'Tests deeply nested list structures (20+ levels)',
        'generator' => function () {
            $content = "# Deep Nesting Test\n\n";
            $maxDepth = 25;

            for ($section = 0; $section < 5; $section++) {
                $content .= "## Section {$section}\n\n";

                // Build deep list
                for ($depth = 1; $depth <= $maxDepth; $depth++) {
                    $indent = str_repeat('  ', $depth - 1);
                    $content .= "{$indent}- Level {$depth}\n";
                }

                // Unwind
                for ($depth = $maxDepth - 1; $depth >= 1; $depth--) {
                    $indent = str_repeat('  ', $depth - 1);
                    $content .= "{$indent}- Back to level {$depth}\n";
                }
                $content .= "\n";
            }

            return $content;
        },
    ],

    'many_paragraphs' => [
        'description' => 'Tests 10,000 paragraphs of varying content',
        'generator' => function () {
            $content = "# Many Paragraphs Test\n\n";

            for ($i = 0; $i < 10000; $i++) {
                $type = $i % 5;
                switch ($type) {
                    case 0:
                        $content .= "Plain paragraph number {$i}.\n\n";
                        break;
                    case 1:
                        $content .= "Paragraph {$i} with *bold* and _italic_ text.\n\n";
                        break;
                    case 2:
                        $content .= "Paragraph {$i} with `code` and [link](url).\n\n";
                        break;
                    case 3:
                        $content .= "Paragraph {$i}: {+ins+} {-del-} {=mark=}\n\n";
                        break;
                    case 4:
                        $content .= "Paragraph {$i}: H{~2~}O and x{^2^}\n\n";
                        break;
                }
            }

            return $content;
        },
    ],

    'huge_table' => [
        'description' => 'Tests a 100x100 table (10,000 cells)',
        'generator' => function () {
            $content = "# Huge Table Test\n\n";
            $cols = 100;
            $rows = 100;

            // Header
            $content .= '|' . implode('|', array_map(fn($c) => " H{$c} ", range(1, $cols))) . "|\n";
            $content .= '|' . str_repeat('---|', $cols) . "\n";

            // Rows
            for ($r = 1; $r <= $rows; $r++) {
                $cells = [];
                for ($c = 1; $c <= $cols; $c++) {
                    $cells[] = " R{$r}C{$c} ";
                }
                $content .= '|' . implode('|', $cells) . "|\n";
            }

            return $content;
        },
    ],

    'inline_heavy' => [
        'description' => 'Tests paragraphs with 100+ inline elements each',
        'generator' => function () {
            $content = "# Inline Heavy Test\n\n";

            for ($p = 0; $p < 500; $p++) {
                $para = "P{$p}: ";
                for ($i = 0; $i < 20; $i++) {
                    $para .= "*b{$i}* _i{$i}_ `c{$i}` [l{$i}](u{$i}) ";
                    $para .= "{+a{$i}+} {-d{$i}-} {=m{$i}=} ";
                }
                $content .= $para . "\n\n";
            }

            return $content;
        },
    ],

    'many_links' => [
        'description' => 'Tests 5,000 links with reference definitions',
        'generator' => function () {
            $content = "# Many Links Test\n\n";

            // Inline links
            for ($i = 0; $i < 2500; $i++) {
                $content .= "Link {$i}: [text{$i}](https://example.com/path/{$i} \"Title {$i}\")\n\n";
            }

            // Reference style links
            for ($i = 0; $i < 2500; $i++) {
                $content .= "Reference {$i}: [ref{$i}][link{$i}]\n\n";
            }

            // Reference definitions
            for ($i = 0; $i < 2500; $i++) {
                $content .= "[link{$i}]: https://example.com/ref/{$i} \"Ref Title {$i}\"\n";
            }

            return $content;
        },
    ],

    'pathological' => [
        'description' => 'Tests pathological inputs (potential exponential cases)',
        'generator' => function () {
            $content = "# Pathological Input Test\n\n";

            // Repeated emphasis markers
            $content .= "## Emphasis Edge Cases\n\n";
            $content .= str_repeat('_', 100) . "text" . str_repeat('_', 100) . "\n\n";
            $content .= str_repeat('*', 100) . "text" . str_repeat('*', 100) . "\n\n";

            // Unclosed brackets (should not cause exponential blowup)
            $content .= "## Bracket Test\n\n";
            $content .= str_repeat('[', 50) . "text" . str_repeat(']', 50) . "\n\n";
            $content .= str_repeat('(', 50) . "text" . str_repeat(')', 50) . "\n\n";

            // Mixed markers
            $content .= "## Mixed Markers\n\n";
            $markers = ['*', '_', '`', '[', ']'];
            for ($i = 0; $i < 100; $i++) {
                $marker = $markers[$i % count($markers)];
                $content .= str_repeat($marker, 3);
            }
            $content .= "\n\n";

            // Long lines
            $content .= "## Long Lines\n\n";
            for ($i = 0; $i < 10; $i++) {
                $content .= str_repeat("word{$i} ", 1000) . "\n\n";
            }

            // Deeply nested quotes
            $content .= "## Nested Quotes\n\n";
            for ($i = 0; $i < 50; $i++) {
                $content .= str_repeat('> ', $i + 1) . "Level {$i}\n";
            }
            $content .= "\n";

            return $content;
        },
    ],

    'many_code_blocks' => [
        'description' => 'Tests 1,000 code blocks with various languages',
        'generator' => function () {
            $content = "# Many Code Blocks Test\n\n";
            $languages = ['php', 'javascript', 'python', 'rust', 'go', 'java', 'ruby', 'swift', 'kotlin', 'csharp'];

            for ($i = 0; $i < 1000; $i++) {
                $lang = $languages[$i % count($languages)];
                $content .= "## Block {$i}\n\n";
                $content .= "```{$lang}\n";
                $content .= "function example{$i}() {\n";
                $content .= "    // Code block {$i}\n";
                $content .= "    return {$i};\n";
                $content .= "}\n";
                $content .= "```\n\n";
            }

            return $content;
        },
    ],

    'many_footnotes' => [
        'description' => 'Tests 500 footnotes with references',
        'generator' => function () {
            $content = "# Many Footnotes Test\n\n";

            // Text with footnote references
            for ($i = 0; $i < 500; $i++) {
                $content .= "Sentence {$i} with footnote[^fn{$i}]. ";
                if ($i % 10 === 9) {
                    $content .= "\n\n";
                }
            }
            $content .= "\n\n";

            // Footnote definitions
            for ($i = 0; $i < 500; $i++) {
                $content .= "[^fn{$i}]: This is the content of footnote number {$i}. ";
                $content .= "It can contain *formatting* and `code`.\n\n";
            }

            return $content;
        },
    ],

    'memory_pressure' => [
        'description' => 'Tests memory handling with large documents (1MB+)',
        'generator' => function () {
            $targetSize = 1024 * 1024 * 2; // 2MB
            $content = "# Memory Pressure Test\n\n";

            while (strlen($content) < $targetSize) {
                $section = rand(0, 4);
                switch ($section) {
                    case 0:
                        $content .= "## Section " . strlen($content) . "\n\n";
                        $content .= "A paragraph with *bold* _italic_ `code` and [link](url).\n\n";
                        break;
                    case 1:
                        $content .= "- List item " . strlen($content) . "\n";
                        $content .= "  - Nested item\n";
                        $content .= "    - Deep nested\n\n";
                        break;
                    case 2:
                        $content .= "> Quote " . strlen($content) . "\n";
                        $content .= "> Second line\n\n";
                        break;
                    case 3:
                        $content .= "```\ncode block " . strlen($content) . "\n```\n\n";
                        break;
                    case 4:
                        $content .= "| A | B | C |\n|---|---|---|\n| 1 | 2 | 3 |\n\n";
                        break;
                }
            }

            return $content;
        },
    ],
];

// Run scenarios
$results = [];

if (!$jsonOutput) {
    echo "Djot-PHP Stress Test Suite\n";
    echo "==========================\n";
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "Memory Limit: " . ini_get('memory_limit') . "\n";
}

foreach ($scenarios as $name => $scenario) {
    if ($requestedScenario !== 'all' && $requestedScenario !== $name) {
        continue;
    }

    $results[$name] = runScenario(
        $name,
        $scenario['description'],
        $scenario['generator']
    );
}

// Summary
if (!$jsonOutput) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "SUMMARY\n";
    echo str_repeat("=", 60) . "\n\n";

    $passed = 0;
    $failed = 0;

    foreach ($results as $name => $result) {
        $status = $result['status'] === 'success' ? '✓' : '✗';
        $time = $result['status'] === 'success'
            ? formatMs($result['stats']['mean'])
            : $result['error'];

        printf("%-20s %s %s\n", $name, $status, $time);

        if ($result['status'] === 'success') {
            $passed++;
        } else {
            $failed++;
        }
    }

    echo "\nTotal: {$passed} passed, {$failed} failed\n";
}

// Output JSON
if ($jsonOutput) {
    $results['meta'] = [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'timestamp' => date('c'),
    ];
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
}
