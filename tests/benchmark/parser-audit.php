<?php

declare(strict_types=1);

[$script, $engine, $root, $case, $size, $iterations, $trials] = array_pad($argv, 7, null);
$size = (int)($size ?? 1000);
$iterations = (int)($iterations ?? 5);
$trials = (int)($trials ?? 7);
if (!in_array($engine, ['djot', 'carve'], true) || !is_dir((string)$root) || $size < 1 || $iterations < 1 || $trials < 1) {
    throw new InvalidArgumentException('Expected engine, source root, case, and positive size/iterations/trials.');
}
$loader = require __DIR__ . '/../../vendor/autoload.php';
$prefix = $engine === 'djot' ? 'Djot\\' : 'MarkupCarve\\Carve\\';
$loader->setPsr4($prefix, $root . '/src');
$class = $prefix . ($engine === 'djot' ? 'DjotConverter' : 'CarveConverter');
$em = $engine === 'djot' ? '_' : '/';
$source = match ($case) {
    'code_runs'=>'``' . str_repeat('plain prose ', $size) . '```tail',
    'emphasis'=>$em . rtrim(str_repeat('ordinary words ', $size)) . $em . "\n",
    'braced'=>'{=' . str_repeat('ordinary words ', $size) . '=}',
    'warnings'=>str_repeat("prefix [x][missing] suffix\n", $size),
    'unicode'=>str_repeat("## Café 東京\n\nUne phrase française et 日本語.\n\n", $size),
    'images'=>str_repeat("![a picture](https://example.com/image.png)\n\n", $size),
    'lists'=>implode("\n", array_map(fn ($n) => "* list item $n\n* another item $n", range(1, $size))),
    'plain'=>str_repeat("ordinary paragraph words\n\n", $size),
    'core', 'default_core', 'parse', 'render'=>implode("\n\n", array_map(fn ($n) => "## Section $n\n\n" . 'Some ' . $em . 'formatted' . $em . " text with [a link](https://example.com).\n\n| A | B |\n|---|---|\n| value $n | another value |", range(1, $size))),
    'stream_ascii'=>str_repeat('ordinary payload ', $size),
    'stream_escaped'=>str_repeat("<>&\u{00A0}😀", $size),
    'marker_prose'=>implode("\n", array_map(fn ($n) => "Ordinary paragraph $n with a unique tail", range(1, $size))),
    'table_raw', 'table_code'=> '|' . str_repeat('words in a long cell ', $size) . ($case === 'table_code' ? '`a|b`' : 'a\\|b') . '|tail|',
};
$converter = $case === 'warnings' ? new $class(warnings: true) : (in_array($case, ['core', 'parse', 'render', 'code_runs', 'emphasis', 'braced']) ? $class::create() : new $class());
$run = fn () => $converter->convert($source);
if ($case === 'render') {
    $doc = $converter->parse($source);
    $run = fn () => $converter->getRenderer()->render($doc);
}
if ($case === 'parse') {
    $run = function () use ($converter, $source) {
        $doc = $converter->parse($source);

        return (string)count($doc->getChildren());
    };
}
if (str_starts_with($case, 'stream_')) {
    $outputClass = $prefix . 'Performance\\HtmlOutput';
    $run = function () use ($outputClass, $source) {
        $hash = hash_init('sha256');
        $output = new $outputClass(fn ($part) => hash_update($hash, $part));
        $output->text($source);
        $output->finish();

        return hash_final($hash);
    };
}
if ($case === 'marker_prose') {
    $parserClass = $prefix . 'Parser\\Block\\ListParser';
    $parser = new $parserClass();
    $lines = explode("\n", $source);
    $run = function () use ($parser, $lines) {
        $count = 0;
        foreach ($lines as $line) {
            $count += (int)($parser->parseListItemMarker($line) !== null);
        }

        return (string)$count;
    };
}
if (str_starts_with($case, 'table_')) {
    $parserClass = $prefix . 'Parser\\Block\\TableParser';
    $parser = new $parserClass();
    $run = $case === 'table_raw' ? fn () => json_encode($parser->parseTableCellsRaw($source)) : fn () => json_encode([$parser->hasUnclosedCodeSpan($source), $parser->lineEndsWithPipeOutsideCodeSpan($source)]);
}
$output = $run();
if ($case === 'parse') {
    $output = $converter->getRenderer()->render($converter->parse($source));
}
if ($case === 'warnings') {
    $output .= json_encode(array_map(fn ($w) => [$w->getMessage(), $w->getLine(), $w->getColumn()], $converter->getWarnings()));
}
for ($i = 0; $i < 20; $i++) {
    $run();
}
$cpu = [];
$wall = [];
for ($trial = 0; $trial < $trials; $trial++) {
    $start = hrtime(true);
    $before = getrusage();
    for ($i = 0; $i < $iterations; $i++) {
        $run();
    }
    $after = getrusage();
    $wall[] = (hrtime(true) - $start) / 1e6 / $iterations;
    $cpu[] = ((($after['ru_utime.tv_sec'] + $after['ru_stime.tv_sec']) - ($before['ru_utime.tv_sec'] + $before['ru_stime.tv_sec'])) * 1e3 + (($after['ru_utime.tv_usec'] + $after['ru_stime.tv_usec']) - ($before['ru_utime.tv_usec'] + $before['ru_stime.tv_usec'])) / 1e3) / $iterations;
}
$peakUsedBytes = memory_get_peak_usage();
$peakAllocatedBytes = memory_get_peak_usage(true);
$allocationProfile = null;
if ($case === 'parse') {
    $profileConverter = $class::create();
    gc_collect_cycles();
    $beforeParseBytes = memory_get_usage();
    $profileDocument = $profileConverter->parse($source);
    $retainedBytes = memory_get_usage() - $beforeParseBytes;
    $types = [];
    $pending = [$profileDocument];
    while ($pending !== []) {
        $node = array_pop($pending);
        $types[$node::class] = ($types[$node::class] ?? 0) + 1;
        foreach ($node->getChildren() as $child) {
            $pending[] = $child;
        }
    }
    ksort($types);
    $allocationProfile = ['retained_parse_bytes' => $retainedBytes, 'node_count' => array_sum($types), 'node_types' => $types];
}
$reflection = new ReflectionClass($class);
if (realpath(dirname($reflection->getFileName())) !== realpath($root . '/src')) {
    throw new RuntimeException('Wrong source');
}

$hashes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $hashes[substr($file->getPathname(), strlen($root . '/src') + 1)] = hash_file('sha256', $file->getPathname());
    }
}
ksort($hashes);
echo json_encode(['source_tree_sha256' => hash('sha256', json_encode($hashes)), 'php_version' => PHP_VERSION, 'allocation_profile' => $allocationProfile, 'peak_used_bytes' => $peakUsedBytes, 'engine' => $engine, 'case' => $case, 'size' => $size, 'bytes' => strlen($source), 'cpu_ms' => $cpu, 'wall_ms' => $wall, 'input_sha256' => hash('sha256', $source), 'output_sha256' => hash('sha256', $output), 'peak_bytes' => $peakAllocatedBytes, 'jit' => function_exists('opcache_get_status') ? (opcache_get_status(false)['jit'] ?? null) : null]), "\n";
