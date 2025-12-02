# Djot-PHP Performance Summary

Performance benchmarks for djot-php compared to other implementations.

**Test Environment:**
- PHP 8.4.15
- Node.js v18.19.1
- Python 3.12.3
- Linux 6.8.0-88-generic

## Quick Reference

| Document Size | PHP Parse | PHP Render | PHP Full | Throughput |
|---------------|-----------|------------|----------|------------|
| 1 KB          | 0.35 ms   | 0.15 ms    | 0.50 ms  | ~2.3 MB/s  |
| 10 KB         | 3.5 ms    | 1.5 ms     | 5.0 ms   | ~2.3 MB/s  |
| 50 KB         | 18 ms     | 8 ms       | 26 ms    | ~2.2 MB/s  |
| 100 KB        | 35 ms     | 15 ms      | 50 ms    | ~2.2 MB/s  |
| 1 MB          | 404 ms    | 194 ms     | 531 ms   | ~1.9 MB/s  |
| 10 MB         | 4.3 s     | 2.6 s      | 6.3 s    | ~1.6 MB/s  |

## Large Document Processing

### 1 MB Document

| Metric            | PHP (djot-php) | JS (@djot/djot) |
|-------------------|----------------|-----------------|
| Parse Time        | 404 ms         | 191 ms          |
| Render Time       | 194 ms         | 51 ms           |
| Full Conversion   | 531 ms         | 248 ms          |
| Parse Memory      | 38 MB          | -               |
| Render Memory     | 2 MB           | -               |
| Peak Memory       | 44 MB          | -               |
| Output Size       | 1.6 MB         | 1.6 MB          |

### 10 MB Document

| Metric            | PHP (djot-php) | JS (@djot/djot) |
|-------------------|----------------|-----------------|
| Parse Time        | 4.3 s          | ~1.9 s*         |
| Render Time       | 2.6 s          | ~0.5 s*         |
| Full Conversion   | 6.3 s          | ~2.5 s*         |
| Parse Memory      | 326 MB         | -               |
| Render Memory     | 18 MB          | -               |
| Peak Memory       | 408 MB         | -               |
| Output Size       | 16 MB          | 16 MB           |

*JS times estimated from linear scaling

### Memory Scaling

Memory usage scales approximately 40x the input size:

| Input   | Parse Mem | Render Mem | Peak Mem | Output  |
|---------|-----------|------------|----------|---------|
| 1 MB    | 38 MB     | 2 MB       | 44 MB    | 1.6 MB  |
| 10 MB   | 326 MB    | 18 MB      | 408 MB   | 16 MB   |

## Cross-Language Comparison

Benchmarked on medium-sized documents (~56 KB):

| Implementation      | Mean Time | Throughput | vs PHP    |
|---------------------|-----------|------------|-----------|
| PHP (djot-php)      | 18.1 ms   | 3.0 MB/s   | baseline  |
| JS (@djot/djot)     | 8.1 ms    | 5.2 MB/s   | 2.2x faster |
| Python-Markdown     | 41.1 ms   | 1.0 MB/s   | 2.3x slower |
| markdown-it-py      | 36.8 ms   | 1.2 MB/s   | 2.0x slower |

## Document Size Scaling

PHP djot-php scales linearly with document size:

| Size    | Input     | Mean Time | Throughput |
|---------|-----------|-----------|------------|
| tiny    | 1.1 KB    | 0.50 ms   | 2.3 MB/s   |
| small   | 11.1 KB   | 5.0 ms    | 2.3 MB/s   |
| medium  | 56.1 KB   | 26.0 ms   | 2.2 MB/s   |
| large   | 225.5 KB  | 105 ms    | 2.2 MB/s   |
| huge    | 1.1 MB    | 538 ms    | 2.2 MB/s   |

## Parse vs Render Breakdown

For a typical document, parsing takes ~75% of total time:

| Phase   | Time (medium doc) | Percentage |
|---------|-------------------|------------|
| Parse   | 20.8 ms           | ~75%       |
| Render  | 5.1 ms            | ~25%       |
| **Total** | **25.9 ms**     | 100%       |

## Profile Performance

Different profiles have similar performance since they filter the same AST:

| Profile  | Mean Time | Notes                    |
|----------|-----------|--------------------------|
| none     | 24.9 ms   | No filtering             |
| full     | 32.4 ms   | All features enabled     |
| article  | 35.2 ms   | Blog/article content     |
| comment  | 31.5 ms   | User comments            |
| minimal  | 31.1 ms   | Basic text formatting    |

## Safe Mode

Safe mode has negligible performance impact:

| Mode     | Mean Time |
|----------|-----------|
| Disabled | 27.4 ms   |
| Enabled  | 25.0 ms   |

## Memory Usage

Memory scales approximately linearly with document size:

| Input Size | Peak Memory | Ratio    |
|------------|-------------|----------|
| 11 KB      | 68 MB       | ~6000x   |
| 57 KB      | 68 MB       | ~1200x   |
| 226 KB     | 70 MB       | ~310x    |
| 1 MB       | ~80 MB      | ~80x     |

Note: PHP has a base memory overhead. The incremental memory per input byte is approximately 30-45x.

## Content Type Performance

Different content types have varying performance characteristics:

| Content Type   | Size    | Mean Time | Throughput | Notes                     |
|----------------|---------|-----------|------------|---------------------------|
| code_heavy     | 5.9 KB  | 0.66 ms   | 9.0 MB/s   | Fastest - simple parsing  |
| tables         | 9.4 KB  | 3.9 ms    | 2.4 MB/s   | Average                   |
| nested_lists   | 5.7 KB  | 2.6 ms    | 2.2 MB/s   | Average                   |
| complex        | 9.1 KB  | 6.8 ms    | 1.3 MB/s   | Many features             |
| inline_heavy   | 15.1 KB | 11.5 ms   | 1.3 MB/s   | Many inline elements      |

Code-heavy documents are fastest because code blocks require minimal parsing.

## Stress Test Results

All stress tests pass successfully:

| Scenario        | Input Size | Mean Time | Status |
|-----------------|------------|-----------|--------|
| deep_nesting    | 3.5 KB     | 1.4 ms    | PASS   |
| pathological    | 62.6 KB    | 22.0 ms   | PASS   |
| many_paragraphs | 556 KB     | 280 ms    | PASS   |
| huge_table      | 121 KB     | 45 ms     | PASS   |
| inline_heavy    | 198 KB     | 95 ms     | PASS   |
| memory_pressure | 2 MB       | 1.1 s     | PASS   |

## Running Benchmarks

```bash
# Quick PHP benchmark
php tests/performance/benchmark.php

# Full benchmark with cross-language comparison
./tests/performance/run-all.sh --compare

# Memory profiling
php tests/performance/memory-profile.php --detailed

# Stress testing
php tests/performance/stress-test.php

# Generate HTML report
php tests/performance/generate-report.php
```

## Key Takeaways

1. **Throughput**: PHP djot-php processes ~2-3 MB/s of djot content
2. **Scaling**: Performance scales linearly with document size (O(n))
3. **vs JavaScript**: Reference JS implementation is ~2x faster
4. **vs Python**: PHP is ~2x faster than Python markdown libraries
5. **Large documents**: 1 MB in ~0.5s (44 MB RAM), 10 MB in ~6s (408 MB RAM)
6. **Memory**: Scales ~40x input size (1 MB input → 44 MB peak)
7. **Safe mode**: No significant performance penalty
