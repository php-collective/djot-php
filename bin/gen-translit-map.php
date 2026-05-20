#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates src/Renderer/ascii_translit_map.php from the ICU
 * "Any-Latin; Latin-ASCII" transliterator.
 *
 * The baked map is the deterministic fallback used by AsciiTransliterator
 * when ext-intl is unavailable. Generating it from ICU guarantees the
 * common (European / Cyrillic / Greek / punctuation) output is identical
 * with and without intl. Requires ext-intl to run.
 *
 * Usage: php bin/gen-translit-map.php
 */

if (!class_exists(Transliterator::class)) {
    fwrite(STDERR, "ext-intl is required to regenerate the map.\n");
    exit(1);
}

$tr = Transliterator::create('Any-Latin; Latin-ASCII');
if ($tr === null) {
    fwrite(STDERR, "Failed to create ICU transliterator.\n");
    exit(1);
}

// Latin-1/Extended, IPA, combining marks, Greek, Cyrillic, Latin Extended
// Additional, general punctuation, super/subscripts, currency, letterlike.
$ranges = [
    [0x00A0, 0x024F], [0x0250, 0x02AF], [0x0300, 0x036F], [0x0370, 0x03FF],
    [0x0400, 0x04FF], [0x1E00, 0x1EFF], [0x2000, 0x206F], [0x2070, 0x209F],
    [0x20A0, 0x20BF], [0x2100, 0x214F],
];

// A per-character map cannot reproduce ICU's context-sensitive rules (e.g.
// Greek `αυ` → `au` but `υ` alone → `y`). Baking only the context-free
// subset of such a script is *worse* than excluding it: `Αυγή` would give
// `Ae` from the map vs `Auge` from ICU. So each range is all-or-nothing —
// it is baked only if EVERY pure-ASCII code point in it is context-free.
// Context-sensitive scripts (Greek, …) are excluded wholesale and degrade
// to the generated `s-N` fallback when ext-intl is absent, exactly like
// CJK; with ext-intl they are still romanized.
$map = [];
foreach ($ranges as [$start, $end]) {
    $rangeEntries = [];
    $rangeIsContextFree = true;

    for ($cp = $start; $cp <= $end; $cp++) {
        $char = IntlChar::chr($cp);
        if ($char === null) {
            continue;
        }

        $ascii = $tr->transliterate($char);
        if ($ascii === false || $ascii === $char || preg_match('/^[\x00-\x7F]*$/', $ascii) !== 1) {
            continue;
        }

        $contextFree =
            $tr->transliterate($char . $char) === $ascii . $ascii
            && $tr->transliterate('a' . $char . 'a') === 'a' . $ascii . 'a'
            && $tr->transliterate('Z' . $char . 'Z') === 'Z' . $ascii . 'Z';
        if (!$contextFree) {
            $rangeIsContextFree = false;

            break;
        }

        $rangeEntries[$char] = $ascii;
    }

    if ($rangeIsContextFree) {
        $map += $rangeEntries;
    }
}
ksort($map);

$lines = [];
foreach ($map as $from => $to) {
    $lines[] = '    ' . var_export($from, true) . ' => ' . var_export($to, true) . ',';
}

$header = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * Unicode -> ASCII transliteration fallback map.
 *
 * Generated from the ICU "Any-Latin; Latin-ASCII" transliterator over the
 * Latin, IPA, combining-marks, Greek, Cyrillic, Latin-Extended-Additional,
 * punctuation, super/subscript, currency and letterlike ranges. Used by
 * the AsciiTransliterator only when ext-intl is unavailable, so the common
 * (European/Cyrillic/Greek/punctuation) output is byte-identical with or
 * without intl. Do not hand-edit; regenerate with `php bin/gen-translit-map.php`.
 *
 * @return array<string, string>
 */
return [
PHP;

$target = dirname(__DIR__) . '/src/Renderer/ascii_translit_map.php';
file_put_contents($target, $header . "\n" . implode("\n", $lines) . "\n];\n");

echo 'Wrote ' . count($map) . " entries to {$target}\n";
