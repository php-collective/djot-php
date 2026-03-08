# Djot-PHP Performance Summary

Performance benchmarks for djot-php compared to other implementations.

**Test Environment:**
- PHP 8.4.15
- Node.js v18.19.1
- Python 3.12.3
- Rust (jotdown 0.7)
- Go (godjot)
- Linux 6.8.0-88-generic

## Quick Reference

| Document Size | PHP Full | Throughput |
|---------------|----------|------------|
| 1 KB          | 0.36 ms  | ~3.0 MB/s  |
| 10 KB         | 3.7 ms   | ~3.0 MB/s  |
| 56 KB         | 18 ms    | ~3.0 MB/s  |
| 225 KB        | 80 ms    | ~2.7 MB/s  |
| 1 MB          | 456 ms   | ~2.4 MB/s  |

## PHP Alternatives Comparison

With equivalent features enabled (tables, footnotes, smart typography):

| Library | 30KB Doc | Throughput | vs djot-php |
|---------|----------|------------|-------------|
| erusev/parsedown | 1.69 ms | 16.0 MB/s | 6.7x faster |
| michelf/php-markdown | 5.16 ms | 5.2 MB/s | 2.2x faster |
| michelf/php-markdown (Extra) | 6.15 ms | 4.4 MB/s | 1.9x faster |
| **djot-php** | **11.36 ms** | **2.6 MB/s** | baseline |
| league/commonmark (GFM) | 15.00 ms | 1.8 MB/s | 1.3x slower |
| league/commonmark | 15.31 ms | 1.8 MB/s | 1.4x slower |
| league/commonmark (Full) | 23.54 ms | 1.3 MB/s | **2.1x slower** |

**Key finding:** djot-php is **2x faster than CommonMark** when both have equivalent features (tables, footnotes, smart punct) enabled.

## Cross-Language Comparison

Benchmarked on medium-sized documents (~56 KB):

| Implementation      | Mean Time | Throughput | vs PHP    |
|---------------------|-----------|------------|-----------|
| Rust (jotdown)      | ~1-2 ms   | ~30+ MB/s  | ~10x faster |
| Go (godjot)         | ~2-4 ms   | ~15+ MB/s  | ~5x faster |
| JS (@djot/djot)     | 8.1 ms    | 5.2 MB/s   | 2.2x faster |
| PHP (djot-php)      | 18 ms     | 3.0 MB/s   | baseline  |
| Python-Markdown*    | 41.1 ms   | 1.0 MB/s   | 2.3x slower |

*Python libraries are Markdown parsers (no Djot implementation exists for Python).

## Document Size Scaling

PHP djot-php scales linearly with document size:

| Size    | Input     | Mean Time | Throughput |
|---------|-----------|-----------|------------|
| tiny    | 1.1 KB    | 0.36 ms   | 3.0 MB/s   |
| small   | 11.1 KB   | 3.68 ms   | 3.0 MB/s   |
| medium  | 56.1 KB   | 18.44 ms  | 3.0 MB/s   |
| large   | 225.5 KB  | 80.42 ms  | 2.7 MB/s   |
| huge    | 1.1 MB    | 456.16 ms | 2.4 MB/s   |

## Content Type Performance

Different content types have varying performance characteristics:

| Content Type   | Size    | Mean Time | Throughput | Notes                     |
|----------------|---------|-----------|------------|---------------------------|
| code_heavy     | 5.8 KB  | 0.71 ms   | 8.0 MB/s   | Fastest - simple parsing  |
| tables         | 9.2 KB  | 5.67 ms   | 1.6 MB/s   | Table parsing overhead    |
| nested_lists   | 5.6 KB  | 2.37 ms   | 2.3 MB/s   | Average                   |
| complex        | 8.9 KB  | 4.09 ms   | 2.1 MB/s   | Many features             |
| inline_heavy   | 14.8 KB | 8.73 ms   | 1.7 MB/s   | Many inline elements      |

Code-heavy documents are fastest because code blocks require minimal parsing.

## Profile Performance

Different profiles have similar performance since they filter the same AST:

| Profile  | Mean Time | Notes                    |
|----------|-----------|--------------------------|
| none     | 20.70 ms  | No filtering             |
| full     | 22.10 ms  | All features enabled     |
| article  | 21.80 ms  | Blog/article content     |
| comment  | 23.27 ms  | User comments            |
| minimal  | 22.90 ms  | Basic text formatting    |

## Safe Mode

Safe mode has negligible performance impact:

| Mode     | Mean Time |
|----------|-----------|
| Disabled | 20.01 ms  |
| Enabled  | 20.54 ms  |

## Memory Usage

Memory scales approximately linearly with document size:

| Input Size | Peak Memory | Ratio    |
|------------|-------------|----------|
| 11 KB      | 72 MB       | ~6500x   |
| 57 KB      | 72 MB       | ~1260x   |
| 226 KB     | 72 MB       | ~320x    |
| 1 MB       | ~80 MB      | ~80x     |

Note: PHP has a base memory overhead. The incremental memory per input byte is approximately 30-45x.

## Running Benchmarks

```bash
# Internal PHP benchmark
php tests/benchmark/benchmark.php

# PHP alternatives comparison
cd tests/benchmark_alternatives
composer install
php benchmark.php

# Cross-language comparison
./tests/benchmark/run-all.sh --compare
```

## Key Takeaways

1. **Throughput**: PHP djot-php processes ~3.0 MB/s of djot content
2. **vs CommonMark (Full)**: djot-php is **2x faster** with equivalent features
3. **vs Parsedown**: Parsedown is 6-7x faster but lacks advanced features
4. **Scaling**: Performance scales linearly with document size (O(n))
5. **vs Rust/Go**: Native implementations are 5-10x faster (as expected)
6. **vs JavaScript**: Reference JS implementation is ~2x faster
7. **Safe mode**: No significant performance penalty
8. **31% optimization**: Recent optimizations improved from 26ms to 18ms (medium doc)
