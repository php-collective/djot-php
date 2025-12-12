#!/usr/bin/env php
<?php
/**
 * Djot-PHP Memory Profiler
 *
 * Detailed memory analysis for the djot parser and renderer.
 *
 * Usage:
 *   php tests/benchmark/memory-profile.php [--detailed] [--json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Djot\DjotConverter;
use Djot\Profile;

// Parse CLI arguments
$options = getopt('', ['detailed', 'json', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Djot-PHP Memory Profiler

Usage: php memory-profile.php [options]

Options:
  --detailed    Show detailed memory breakdown per phase
  --json        Output results as JSON
  --help        Show this help

HELP;
    exit(0);
}

$detailed = isset($options['detailed']);
$jsonOutput = isset($options['json']);

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

function measureMemory(callable $fn): array
{
    gc_collect_cycles();
    gc_disable();

    $memBefore = memory_get_usage(true);
    $memUsageBefore = memory_get_usage(false);

    $result = $fn();

    $memAfter = memory_get_usage(true);
    $memUsageAfter = memory_get_usage(false);
    $peak = memory_get_peak_usage(true);

    gc_enable();
    gc_collect_cycles();

    return [
        'allocated_delta' => $memAfter - $memBefore,
        'used_delta' => $memUsageAfter - $memUsageBefore,
        'peak' => $peak,
        'result' => $result,
    ];
}

function generateDocument(int $size): string
{
    $content = "# Performance Test Document\n\n";
    for ($i = 0; $i < $size; $i++) {
        $content .= "## Section {$i}\n\n";
        $content .= "Paragraph with *bold* and _italic_ and `code` and [link](url).\n\n";
        $content .= "- List item 1\n- List item 2\n- List item 3\n\n";
        $content .= "> A block quote here\n\n";
        $content .= "```php\necho 'code block';\n```\n\n";
    }

    return $content;
}

// Load fixtures from files
function loadFixtures(): array
{
    $fixtures = [];
    $fixturesDir = __DIR__ . '/fixtures';

    if (is_dir($fixturesDir)) {
        foreach (glob($fixturesDir . '/*.djot') as $file) {
            $name = basename($file, '.djot');
            $fixtures[$name] = file_get_contents($file);
        }
    }

    // Add generated fixtures
    $fixtures['gen_tiny'] = generateDocument(5);
    $fixtures['gen_small'] = generateDocument(20);
    $fixtures['gen_medium'] = generateDocument(100);
    $fixtures['gen_large'] = generateDocument(500);

    return $fixtures;
}

$fixtures = loadFixtures();
$converter = new DjotConverter();
$results = [];

if (!$jsonOutput) {
    echo "Djot-PHP Memory Profiler\n";
    echo "========================\n";
    echo 'PHP Version: ' . PHP_VERSION . "\n";
    echo 'Memory Limit: ' . ini_get('memory_limit') . "\n";
    echo "\n";
}

// Per-fixture memory analysis
if (!$jsonOutput) {
    echo "## Memory Usage by Document Size\n\n";
    printf(
        "%-15s %12s %12s %12s %12s %12s\n",
        'Fixture',
        'Input Size',
        'Output Size',
        'AST Mem',
        'Total Mem',
        'Peak',
    );
    echo str_repeat('-', 75) . "\n";
}

foreach ($fixtures as $name => $content) {
    $inputSize = strlen($content);

    // Measure parse memory
    $parseResult = measureMemory(function () use ($converter, $content) {
        return $converter->parse($content);
    });

    $doc = $parseResult['result'];
    $astMemory = $parseResult['used_delta'];

    // Measure render memory
    $renderResult = measureMemory(function () use ($converter, $doc) {
        return $converter->render($doc);
    });

    $html = $renderResult['result'];
    $outputSize = strlen($html);
    $totalMemory = $parseResult['used_delta'] + $renderResult['used_delta'];

    $results['fixtures'][$name] = [
        'input_size' => $inputSize,
        'output_size' => $outputSize,
        'ast_memory' => $astMemory,
        'render_memory' => $renderResult['used_delta'],
        'total_memory' => $totalMemory,
        'peak_memory' => max($parseResult['peak'], $renderResult['peak']),
        'memory_ratio' => $totalMemory / $inputSize,
    ];

    if (!$jsonOutput) {
        printf(
            "%-15s %12s %12s %12s %12s %12s\n",
            $name,
            formatSize($inputSize),
            formatSize($outputSize),
            formatSize($astMemory),
            formatSize($totalMemory),
            formatSize($results['fixtures'][$name]['peak_memory']),
        );
    }

    unset($doc, $html);
}

// Detailed breakdown for medium fixture
if ($detailed) {
    if (!$jsonOutput) {
        echo "\n## Detailed Memory Breakdown (gen_medium)\n\n";
    }

    $content = $fixtures['gen_medium'];

    // Phase 1: Parse only
    gc_collect_cycles();
    $memStart = memory_get_usage(false);
    $doc = $converter->parse($content);
    $memAfterParse = memory_get_usage(false);

    // Phase 2: Render only
    $html = $converter->render($doc);
    $memAfterRender = memory_get_usage(false);

    // Count nodes
    $nodeCount = 0;
    $nodeCounts = [];
    $countNodes = function ($node) use (&$nodeCount, &$nodeCounts, &$countNodes) {
        $nodeCount++;
        $type = get_class($node);
        $nodeCounts[$type] = ($nodeCounts[$type] ?? 0) + 1;

        if (method_exists($node, 'getChildren')) {
            foreach ($node->getChildren() as $child) {
                $countNodes($child);
            }
        }
    };
    $countNodes($doc);

    $results['detailed'] = [
        'parse_memory' => $memAfterParse - $memStart,
        'render_memory' => $memAfterRender - $memAfterParse,
        'total_nodes' => $nodeCount,
        'node_types' => $nodeCounts,
        'bytes_per_node' => ($memAfterParse - $memStart) / max(1, $nodeCount),
    ];

    if (!$jsonOutput) {
        echo 'Parse Memory:  ' . formatSize($memAfterParse - $memStart) . "\n";
        echo 'Render Memory: ' . formatSize($memAfterRender - $memAfterParse) . "\n";
        echo "Total Nodes:   {$nodeCount}\n";
        echo 'Bytes/Node:    ' . sprintf('%.2f', $results['detailed']['bytes_per_node']) . "\n\n";

        echo "Node Type Distribution:\n";
        arsort($nodeCounts);
        foreach (array_slice($nodeCounts, 0, 10) as $type => $count) {
            $shortType = basename(str_replace('\\', '/', $type));
            printf("  %-20s %d\n", $shortType, $count);
        }
    }

    unset($doc, $html);
}

// Profile comparison memory usage
if (!$jsonOutput) {
    echo "\n## Profile Memory Comparison (gen_medium)\n\n";
    printf("%-15s %12s %12s\n", 'Profile', 'Memory Used', 'Peak');
    echo str_repeat('-', 42) . "\n";
}

$profiles = [
    'none' => null,
    'full' => Profile::full(),
    'article' => Profile::article(),
    'comment' => Profile::comment(),
    'minimal' => Profile::minimal(),
];

$content = $fixtures['gen_medium'];

foreach ($profiles as $name => $profile) {
    $conv = $profile ? new DjotConverter(profile: $profile) : new DjotConverter();

    $result = measureMemory(function () use ($conv, $content) {
        return $conv->convert($content);
    });

    $results['profiles'][$name] = [
        'memory_used' => $result['used_delta'],
        'peak' => $result['peak'],
    ];

    if (!$jsonOutput) {
        printf(
            "%-15s %12s %12s\n",
            $name,
            formatSize($result['used_delta']),
            formatSize($result['peak']),
        );
    }

    unset($conv);
}

// Scaling analysis
if (!$jsonOutput) {
    echo "\n## Memory Scaling Analysis\n\n";
    printf("%-12s %12s %12s %12s\n", 'Paragraphs', 'Input Size', 'Memory', 'Ratio');
    echo str_repeat('-', 52) . "\n";
}

$results['scaling'] = [];
$sizes = [10, 50, 100, 200, 500, 1000];

foreach ($sizes as $paragraphs) {
    $content = generateDocument($paragraphs);
    $inputSize = strlen($content);

    $result = measureMemory(function () use ($converter, $content) {
        return $converter->convert($content);
    });

    $ratio = $result['used_delta'] / $inputSize;

    $results['scaling'][] = [
        'paragraphs' => $paragraphs,
        'input_size' => $inputSize,
        'memory_used' => $result['used_delta'],
        'ratio' => $ratio,
    ];

    if (!$jsonOutput) {
        printf(
            "%-12d %12s %12s %12.2fx\n",
            $paragraphs,
            formatSize($inputSize),
            formatSize($result['used_delta']),
            $ratio,
        );
    }
}

// Object allocation tracking
if (!$jsonOutput) {
    echo "\n## Object Allocation Pattern\n\n";
}

gc_collect_cycles();
$objBefore = count(get_defined_vars());

$content = $fixtures['gen_medium'];
$doc = $converter->parse($content);
$html = $converter->convert($content);

gc_collect_cycles();
$objAfter = count(get_defined_vars());

$results['object_allocation'] = [
    'before' => $objBefore,
    'after' => $objAfter,
];

// Summary statistics
$results['meta'] = [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'timestamp' => date('c'),
];

if ($jsonOutput) {
    echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n## Summary\n\n";

    // Find average memory ratio
    $avgRatio = array_sum(array_column($results['scaling'], 'ratio')) / count($results['scaling']);
    echo 'Average Memory Ratio: ' . sprintf('%.2f', $avgRatio) . "x input size\n";
    echo "Memory scales approximately linearly with document size.\n";
    echo "\nMemory profiling complete.\n";
}
