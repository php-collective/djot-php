<?php

declare(strict_types=1);

// Usage: php backports.php ENGINE SRC-DIR CASE [ITERATIONS] [TRIALS] [INPUT-FILE]
$loader = require __DIR__ . '/../../vendor/autoload.php';

[$script, $engine, $sourceDir, $case, $iterations, $trials, $inputFile] = array_pad($argv, 7, null);
$iterations = (int)($iterations ?? 50);
$trials = (int)($trials ?? 7);
if (!in_array($engine, ['djot', 'carve'], true) || !is_dir((string)$sourceDir) || $iterations < 1 || $trials < 1) {
    throw new RuntimeException('Expected ENGINE (djot|carve), SRC-DIR, CASE, positive ITERATIONS and TRIALS.');
}
$sourceDir = realpath($sourceDir);
$prefix = $engine === 'djot' ? 'Djot\\' : 'MarkupCarve\\Carve\\';
$loader->setPsr4($prefix, $sourceDir);
$converterClass = $prefix . ($engine === 'djot' ? 'DjotConverter' : 'CarveConverter');
$layoutClass = $prefix . 'Performance\\BorrowedHtmlLayout';
// The bracket stress case measures the inline parser through an explicit AST route.
$converter = in_array($case, ['core_ast', 'brackets'], true) ? $converterClass::create() : new $converterClass();
$reflection = new ReflectionClass($converter);
if (realpath(dirname($reflection->getFileName())) !== $sourceDir) {
    throw new RuntimeException('Autoloader did not select the requested source directory.');
}
$source = match ($case) {
    'core', 'core_ast' => coreSource($engine),
    'markers' => str_repeat("- list item\n  ordinary continuation prose\n", 1024),
    'fences' => "```\n" . str_repeat("ordinary code payload\n", 8192) . "```\n",
    'brackets' => str_repeat('[', 4096) . "[ok](https://example.com)\n",
    'plain' => str_repeat("Ordinary paragraph text.\n\n", 200),
    default => throw new RuntimeException('Unknown case.'),
};
if ($inputFile !== null) {
    $source = file_get_contents($inputFile);
}
$render = static fn (): string => $converter->convert($source);
$output = $render();
$owned = $converterClass::create()->convert($source);
if ($output !== $owned) {
    throw new RuntimeException('Facade output differs from the AST pipeline.');
}
$layout = (new $layoutClass())->render($source);
$sourceHashes = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
foreach ($walk as $file) {
    if ($file->getExtension() === 'php') {
        $sourceHashes[substr($file->getPathname(), strlen($sourceDir) + 1)] = hash_file('sha256', $file->getPathname());
    }
}
ksort($sourceHashes);
for ($i = 0; $i < 20; $i++) {
    $render();
}
$samples = [];
for ($trial = 0; $trial < $trials; $trial++) {
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $render();
    }
    $samples[] = (hrtime(true) - $start) / 1e6 / $iterations;
}
$jit = function_exists('opcache_get_status') ? (opcache_get_status(false)['jit'] ?? []) : [];
echo json_encode([
    'engine' => $engine,
    'source_dir' => 'src',
    'source_tree_sha256' => hash('sha256', json_encode($sourceHashes)),
    'case' => $case,
    'bytes' => strlen($source),
    'input_sha256' => hash('sha256', $source),
    'output_sha256' => hash('sha256', $output),
    'ast_parity' => true,
    'borrowed_accepted' => $layout !== null,
    'php' => PHP_VERSION,
    'jit' => $jit,
    'coverage_loaded' => extension_loaded('pcov') || extension_loaded('xdebug'),
    'iterations' => $iterations,
    'warmup' => 20,
    'samples_ms' => $samples,
]) . "\n";

function coreSource(string $engine): string
{
    $emphasis = $engine === 'djot' ? '_emphasis_' : '/emphasis/';
    $gap = $engine === 'djot' ? "\n" : '';
    $source = "# Shared JavaScript core benchmark\n\n[site]: https://example.com\n\n";
    for ($i = 1; $i <= 150; $i++) {
        $source .= "## Section {$i}\n\n"
            . "Paragraph {$i} has *strong*, {$emphasis}, `inline code`, and a [link][site].\n\n"
            . "- first list item\n- second list item\n{$gap}  - nested item with *strong*\n  - another nested item\n\n"
            . "> A block quote with {$emphasis} and a [direct link](https://example.com/path).\n\n"
            . "```js\nfunction section{$i}(value) {\n  return value + {$i};\n}\n```\n\n"
            . "| Name | Value | Note |\n|:---|---:|:---:|\n| alpha | 1 | first |\n| beta | 2 | second |\n\n---\n\n";
    }

    return rtrim($source) . "\n";
}
