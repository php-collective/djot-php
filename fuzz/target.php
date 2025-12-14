<?php

declare(strict_types=1);

/**
 * Fuzz testing target for DjotConverter
 *
 * This file is used by nikic/php-fuzzer to find bugs in the parser.
 * Run with: php vendor/bin/php-fuzzer fuzz fuzz/target.php fuzz/corpus/
 *
 * @var PhpFuzzer\Config $config
 */

require __DIR__ . '/../vendor/autoload.php';

use Djot\DjotConverter;

$converter = new DjotConverter();

$config->setTarget(function (string $input) use ($converter): void {
    // Parse and convert the input
    // Exceptions are expected for malformed input, but Error exceptions indicate bugs
    $converter->convert($input);
});

// Limit input length - most parser bugs are found with smaller inputs
$config->setMaxLen(8192);

// Add dictionary of common djot syntax fragments
$config->addDictionary(__DIR__ . '/djot.dict');
