<?php

declare(strict_types=1);

/**
 * Fuzz testing target for DjotConverter in strict mode
 *
 * Tests with warnings collection enabled.
 * Run with: php vendor/bin/php-fuzzer fuzz fuzz/target-strict.php fuzz/corpus/
 *
 * @var PhpFuzzer\Config $config
 */

require __DIR__ . '/../vendor/autoload.php';

use Djot\DjotConverter;

$config->setTarget(function (string $input): void {
    // Create converter with warning collection
    $converter = new DjotConverter(warnings: true);

    // Parse and convert the input
    $converter->convert($input);

    // Also get warnings to exercise that code path
    $converter->getWarnings();
});

// Limit input length
$config->setMaxLen(8192);

// Add dictionary
$config->addDictionary(__DIR__ . '/djot.dict');
