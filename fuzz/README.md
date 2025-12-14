# Fuzz Testing

This directory contains fuzz testing infrastructure for the djot-php parser using [nikic/php-fuzzer](https://github.com/nikic/PHP-Fuzzer).

## Setup

```bash
composer install
```

## Running Fuzz Tests

### Basic fuzzing (default mode):
```bash
composer fuzz
# or directly:
php vendor/bin/php-fuzzer fuzz fuzz/target.php fuzz/corpus/
```

### Fuzzing with warnings enabled:
```bash
composer fuzz-strict
# or directly:
php vendor/bin/php-fuzzer fuzz fuzz/target-strict.php fuzz/corpus/
```

## How It Works

The fuzzer generates random inputs based on:
1. Initial seed corpus in `corpus/`
2. Dictionary of djot syntax fragments in `djot.dict`
3. Mutations of discovered interesting inputs

It looks for:
- Uncaught `Error` exceptions (bugs)
- Timeouts (infinite loops)
- Warnings/notices converted to errors

## Files

- `target.php` - Main fuzz target for DjotConverter
- `target-strict.php` - Fuzz target with warning collection enabled
- `djot.dict` - Dictionary of djot syntax fragments
- `corpus/` - Initial seed inputs

## Crash Investigation

When a crash is found:

```bash
# Minimize the crash to smallest reproducing input
php vendor/bin/php-fuzzer minimize-crash fuzz/target.php crash-HASH.txt

# Run single input for debugging
php vendor/bin/php-fuzzer run-single fuzz/target.php minimized-HASH.txt
```

## Coverage Report

Generate a coverage report:
```bash
php vendor/bin/php-fuzzer report-coverage fuzz/target.php fuzz/corpus/ coverage/
```
