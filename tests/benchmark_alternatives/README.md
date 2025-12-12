# PHP Alternatives Benchmark

Compares djot-php against popular PHP Markdown parsers.

## Quick Start

```bash
# Install required libraries first
composer require --dev league/commonmark erusev/parsedown michelf/php-markdown

# Run benchmark
php tests/benchmark_alternatives/benchmark.php
```

## Libraries Compared

| Library | Version | Description |
|---------|---------|-------------|
| djot-php | dev | This library (Djot parser) |
| league/commonmark | 2.x | CommonMark spec compliant |
| league/commonmark (GFM) | 2.x | GitHub Flavored Markdown |
| erusev/parsedown | 1.7.x | Fast, popular Markdown parser |
| michelf/php-markdown | 2.x | Original PHP Markdown |
| michelf/php-markdown (Extra) | 2.x | With extra features |

## Options

```bash
php benchmark.php [options]

Options:
  --iterations=N   Number of iterations per test (default: 50)
  --warmup=N       Number of warmup iterations (default: 5)
  --json           Output results as JSON
  --help           Show help
```

## Output

Results are saved to `results/php-alternatives-YYYY-MM-DDTHH-MM-SS.json`

## Notes

- djot-php parses Djot syntax; others parse Markdown
- Test content is equivalent but uses each format's syntax
- This comparison measures raw parsing throughput
- Feature sets differ significantly between libraries
