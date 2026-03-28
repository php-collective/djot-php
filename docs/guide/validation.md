# Validation

djot-php provides built-in validation features to detect issues in your Djot documents. This is useful for linting content, catching errors in CI pipelines, or providing feedback in editors.

## The Problem

Documents can have issues that don't prevent rendering but indicate problems:

```djot
Check the [installation guide][install] for details.

See footnote[^missing] for more info.

[unused]: https://example.com/never-linked
```

Without validation, these issues go unnoticed until users encounter broken links.

## Enabling Warnings

Enable warnings to collect non-fatal issues during parsing:

```php
use Djot\DjotConverter;

$converter = new DjotConverter(warnings: true);
$html = $converter->convert($content);

if ($converter->hasWarnings()) {
    foreach ($converter->getWarnings() as $warning) {
        echo $warning . "\n";
    }
}
```

## What Warnings Detect

| Issue | Example | Category |
|-------|---------|----------|
| Undefined reference | `[text][missing]` | `reference` |
| Undefined footnote | `[^missing]` | `footnote` |
| Unused reference | Defined but never linked | `reference` |
| Broken anchor link | `[link](#nonexistent)` | `anchor` |

## Strict Mode

For fatal errors that should stop processing, enable strict mode:

```php
use Djot\DjotConverter;
use Djot\Exception\ParseException;

$converter = new DjotConverter(strict: true);

try {
    $html = $converter->convert($content);
} catch (ParseException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getSourceLine() . "\n";
    echo "Column: " . $e->getSourceColumn() . "\n";
}
```

## What Strict Mode Catches

| Error | Description |
|-------|-------------|
| Unclosed code fence | ` ``` ` without closing |
| Unclosed div | `::: note` without `:::` |
| Unclosed comment | `{% comment` without `%}` |
| Unclosed raw block | `` ``` {=html} `` without closing |

## Combining Both Modes

Use both warnings and strict mode together for comprehensive validation:

```php
$converter = new DjotConverter(warnings: true, strict: true);

try {
    $html = $converter->convert($content);

    // Check for non-fatal warnings
    foreach ($converter->getWarnings() as $warning) {
        echo "[WARN] " . $warning . "\n";
    }
} catch (ParseException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
```

## Working with Warnings

The `ParseWarning` class provides detailed information:

```php
foreach ($converter->getWarnings() as $warning) {
    // Basic info
    echo $warning->getMessage();  // "Undefined reference 'missing'"
    echo $warning->getLine();     // 5
    echo $warning->getColumn();   // 12

    // Additional context
    echo $warning->getCategory();   // "reference"
    echo $warning->getSuggestion(); // "Define with [missing]: url"

    // Convert to array (useful for JSON APIs)
    $data = $warning->toArray();
}
```

## Validating Documents

Here's a complete example for validating a document or file:

```php
use Djot\DjotConverter;
use Djot\Exception\ParseException;

function validateDjot(string $content): array
{
    $errors = [];
    $warnings = [];

    $converter = new DjotConverter(warnings: true, strict: true);

    try {
        $converter->convert($content);

        foreach ($converter->getWarnings() as $warning) {
            $warnings[] = [
                'message' => $warning->getMessage(),
                'line' => $warning->getLine(),
                'column' => $warning->getColumn(),
                'category' => $warning->getCategory(),
                'suggestion' => $warning->getSuggestion(),
            ];
        }
    } catch (ParseException $e) {
        $errors[] = [
            'message' => $e->getMessage(),
            'line' => $e->getSourceLine(),
            'column' => $e->getSourceColumn(),
        ];
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
    ];
}

// Usage
$result = validateDjot(file_get_contents('document.djot'));

if (!$result['valid']) {
    echo "Document has errors:\n";
    foreach ($result['errors'] as $error) {
        printf("  Line %d: %s\n", $error['line'], $error['message']);
    }
}

if ($result['warnings']) {
    echo "Warnings:\n";
    foreach ($result['warnings'] as $warning) {
        printf("  Line %d: %s\n", $warning['line'], $warning['message']);
    }
}
```

## CI/CD Integration

Use validation in your build pipeline:

```php
#!/usr/bin/env php
<?php
// validate-docs.php

require_once 'vendor/autoload.php';

use Djot\DjotConverter;
use Djot\Exception\ParseException;

$exitCode = 0;
$files = glob('docs/**/*.djot');

foreach ($files as $file) {
    $converter = new DjotConverter(warnings: true, strict: true);

    try {
        $converter->convert(file_get_contents($file));

        foreach ($converter->getWarnings() as $warning) {
            fprintf(STDERR, "%s:%d: warning: %s\n",
                $file, $warning->getLine(), $warning->getMessage());
            $exitCode = 1;
        }
    } catch (ParseException $e) {
        fprintf(STDERR, "%s:%d: error: %s\n",
            $file, $e->getSourceLine(), $e->getMessage());
        $exitCode = 1;
    }
}

exit($exitCode);
```

Run in CI:

```bash
php validate-docs.php || exit 1
```

## Clearing Warnings

When reusing a converter instance, clear warnings between documents:

```php
$converter = new DjotConverter(warnings: true);

$converter->convert($doc1);
$warnings1 = $converter->getWarnings();

$converter->clearWarnings();

$converter->convert($doc2);
$warnings2 = $converter->getWarnings();
```

## See Also

- [Safe Mode](./safe-mode) - XSS protection for untrusted input
- [Profiles](./profiles) - Restrict features by context
- [API Reference](/reference/api) - Full API documentation
