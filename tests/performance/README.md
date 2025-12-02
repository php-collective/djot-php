# Djot-PHP Performance Benchmarks

Comprehensive performance benchmarking suite for djot-php, including cross-language comparisons.

## Quick Start

```bash
# Run PHP benchmarks only
php tests/performance/benchmark.php

# Run with cross-language comparison
./tests/performance/run-all.sh --compare

# Quick benchmark (fewer iterations)
./tests/performance/run-all.sh --quick
```

## Benchmark Scripts

### PHP Benchmarks

| Script | Description |
|--------|-------------|
| `benchmark.php` | Main benchmark: document sizes, profiles, phases |
| `memory-profile.php` | Detailed memory analysis |
| `stress-test.php` | Edge case and stress testing |

### Cross-Language Comparison

| Script | Description |
|--------|-------------|
| `benchmark-js.mjs` | JavaScript @djot/djot (reference implementation) |
| `benchmark-python.py` | Python markdown libraries |
| `compare-languages.mjs` | Unified comparison runner |

### Utilities

| Script | Description |
|--------|-------------|
| `run-all.sh` | Master runner script |
| `generate-report.php` | HTML report generator |

## Usage Examples

### Basic PHP Benchmark

```bash
php tests/performance/benchmark.php --iterations=100 --warmup=10
php tests/performance/benchmark.php --json > results.json
```

### Memory Profiling

```bash
php tests/performance/memory-profile.php
php tests/performance/memory-profile.php --detailed
php tests/performance/memory-profile.php --json
```

### Stress Testing

```bash
# Run all stress tests
php tests/performance/stress-test.php

# Run specific scenario
php tests/performance/stress-test.php --scenario=pathological
php tests/performance/stress-test.php --scenario=deep_nesting
```

Available scenarios:
- `deep_nesting` - Deeply nested lists (20+ levels)
- `many_paragraphs` - 10,000 paragraphs
- `huge_table` - 100x100 table (10,000 cells)
- `inline_heavy` - Paragraphs with 100+ inline elements
- `many_links` - 5,000 links with references
- `pathological` - Potential exponential edge cases
- `many_code_blocks` - 1,000 code blocks
- `many_footnotes` - 500 footnotes
- `memory_pressure` - 2MB+ documents

### Cross-Language Comparison

```bash
# Install JavaScript dependencies
cd tests/performance && npm install

# Run JavaScript benchmark
node benchmark-js.mjs

# Install Python dependencies
pip install -r requirements.txt

# Run Python benchmark
python3 benchmark-python.py

# Run full comparison
node compare-languages.mjs
```

### Generate HTML Report

```bash
# Generate from latest results
php tests/performance/generate-report.php

# Generate from specific results file
php tests/performance/generate-report.php results/benchmark-*.json
```

## Output Formats

All benchmarks support `--json` flag for JSON output:

```bash
php benchmark.php --json | jq '.conversion.complex'
```

## Fixtures

Test fixtures are located in `fixtures/`:

| File | Description |
|------|-------------|
| `simple.djot` | Basic paragraphs, lists, links |
| `complex.djot` | All djot features |
| `stress.djot` | Extreme cases |
| `readme.djot` | Real-world README simulation |

Generated fixtures are also used for scaling tests.

## Metrics

### Timing Metrics

- **Mean** - Average execution time
- **Median** - Middle value (less affected by outliers)
- **P95** - 95th percentile (worst 5% of runs)
- **P99** - 99th percentile
- **Min/Max** - Range of times
- **StdDev** - Standard deviation

### Throughput

Measured in bytes per second (B/s, KB/s, MB/s).

### Memory

- **Allocated** - Memory allocated from system
- **Used** - Actually used memory
- **Peak** - Maximum memory during execution

## Interpreting Results

### Document Size Scaling

Look for linear scaling (O(n)) as document size increases. Non-linear scaling indicates potential algorithmic issues.

### Profile Performance

Different profiles should show similar performance since they filter the same AST.

### Cross-Language Comparison

- **JavaScript @djot/djot** - Reference implementation, expected to be fast
- **Python markdown** - Similar functionality, different language

Note: Djot and Markdown syntaxes differ, so this is an approximate comparison of parsing throughput.

## CI Integration

Add to your CI pipeline:

```yaml
- name: Run performance benchmarks
  run: |
    php tests/performance/benchmark.php --iterations=50 --json > benchmark.json
    php tests/performance/stress-test.php
```

## Requirements

### PHP Benchmark
- PHP 8.2+
- Composer dependencies installed

### JavaScript Comparison
- Node.js 18+
- npm install (runs automatically)

### Python Comparison
- Python 3.8+
- `pip install markdown markdown-it-py mistune commonmark`
