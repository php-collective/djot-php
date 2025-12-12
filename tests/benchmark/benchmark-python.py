#!/usr/bin/env python3
"""
Python Markdown Benchmark

Benchmarks Python markdown libraries for comparison with djot-php.
Since djot doesn't have a mature Python implementation, we compare
against the popular markdown libraries.

Requirements:
    pip install markdown markdown-it-py mistune commonmark
"""

import argparse
import json
import os
import sys
import time
import tracemalloc
from pathlib import Path
from statistics import mean, median, stdev
from typing import Callable, Dict, Any, List

# Try to import markdown libraries
LIBRARIES = {}

try:
    import markdown
    LIBRARIES['markdown'] = {
        'name': 'Python-Markdown',
        'version': markdown.__version__,
        'convert': lambda text: markdown.markdown(text)
    }
except ImportError:
    pass

try:
    from markdown_it import MarkdownIt
    md_it = MarkdownIt()
    LIBRARIES['markdown_it'] = {
        'name': 'markdown-it-py',
        'version': '3.0.0',  # Approximate
        'convert': lambda text: md_it.render(text)
    }
except ImportError:
    pass

try:
    import mistune
    LIBRARIES['mistune'] = {
        'name': 'mistune',
        'version': mistune.__version__ if hasattr(mistune, '__version__') else '3.x',
        'convert': lambda text: mistune.html(text)
    }
except ImportError:
    pass

try:
    import commonmark
    parser = commonmark.Parser()
    renderer = commonmark.HtmlRenderer()
    LIBRARIES['commonmark'] = {
        'name': 'commonmark',
        'version': '0.9.1',
        'convert': lambda text: renderer.render(parser.parse(text))
    }
except ImportError:
    pass


def generate_markdown_fixture(paragraphs: int) -> str:
    """Generate a markdown fixture with given number of paragraphs."""
    content = "# Generated Document\n\n"
    for i in range(paragraphs):
        content += f"Paragraph {i} with **bold** and *italic* text. "
        content += f"A [link](https://example.com) and `code`.\n\n"
    return content


def load_fixtures() -> Dict[str, str]:
    """Load benchmark fixtures from files and generate additional ones."""
    fixtures = {}
    fixtures_dir = Path(__file__).parent / 'fixtures'

    # Load file fixtures and convert djot to approximate markdown
    if fixtures_dir.exists():
        for f in fixtures_dir.glob('*.djot'):
            name = f.stem
            content = f.read_text()
            # Basic djot to markdown conversion (they're similar enough)
            fixtures[name] = content

    # Generate sized fixtures
    fixtures['generated_small'] = generate_markdown_fixture(100)
    fixtures['generated_medium'] = generate_markdown_fixture(500)
    fixtures['generated_large'] = generate_markdown_fixture(2000)
    fixtures['generated_huge'] = generate_markdown_fixture(10000)

    return fixtures


def benchmark(fn: Callable, iterations: int, warmup: int) -> Dict[str, Any]:
    """Run benchmark and collect statistics."""
    # Warmup
    for _ in range(warmup):
        fn()

    # Collect timings
    times = []
    for _ in range(iterations):
        start = time.perf_counter_ns()
        fn()
        end = time.perf_counter_ns()
        times.append((end - start) / 1e6)  # Convert to milliseconds

    times.sort()
    count = len(times)

    return {
        'min': times[0],
        'max': times[-1],
        'mean': mean(times),
        'median': median(times),
        'p95': times[int(count * 0.95)],
        'p99': times[int(count * 0.99)],
        'stddev': stdev(times) if count > 1 else 0,
        'iterations': iterations
    }


def format_ms(ms: float) -> str:
    """Format milliseconds for display."""
    if ms < 1:
        return f"{ms * 1000:.2f} µs"
    if ms < 1000:
        return f"{ms:.2f} ms"
    return f"{ms / 1000:.2f} s"


def format_size(size: int) -> str:
    """Format bytes for display."""
    if size < 1024:
        return f"{size} B"
    if size < 1024 * 1024:
        return f"{size / 1024:.1f} KB"
    return f"{size / (1024 * 1024):.1f} MB"


def main():
    parser = argparse.ArgumentParser(description='Python Markdown Benchmark')
    parser.add_argument('--iterations', type=int, default=100, help='Number of iterations')
    parser.add_argument('--warmup', type=int, default=10, help='Warmup iterations')
    parser.add_argument('--json', action='store_true', help='Output JSON')
    args = parser.parse_args()

    if not LIBRARIES:
        print("No markdown libraries found. Please install:")
        print("  pip install markdown markdown-it-py mistune commonmark")
        sys.exit(1)

    fixtures = load_fixtures()
    results = {'libraries': {}}

    if not args.json:
        print("Python Markdown Libraries Benchmark")
        print("=" * 60)
        print(f"Python Version: {sys.version.split()[0]}")
        print(f"Iterations: {args.iterations}, Warmup: {args.warmup}")
        print(f"Libraries: {', '.join(lib['name'] for lib in LIBRARIES.values())}")
        print()

    # Benchmark each library
    for lib_key, lib_info in LIBRARIES.items():
        lib_name = lib_info['name']
        convert_fn = lib_info['convert']

        if not args.json:
            print(f"\n## {lib_name}\n")
            print(f"{'Fixture':<20} {'Size':>10} {'Mean':>12} {'Median':>12} {'P95':>12} {'Throughput':>12}")
            print("-" * 80)

        results['libraries'][lib_key] = {
            'name': lib_name,
            'version': lib_info['version'],
            'conversion': {}
        }

        for name, content in fixtures.items():
            size = len(content.encode('utf-8'))

            try:
                stats = benchmark(lambda c=content: convert_fn(c), args.iterations, args.warmup)
                throughput = size / (stats['mean'] / 1000)

                results['libraries'][lib_key]['conversion'][name] = {
                    'size_bytes': size,
                    'stats': stats,
                    'throughput_bps': throughput
                }

                if not args.json:
                    print(f"{name:<20} {format_size(size):>10} {format_ms(stats['mean']):>12} "
                          f"{format_ms(stats['median']):>12} {format_ms(stats['p95']):>12} "
                          f"{format_size(int(throughput)) + '/s':>12}")
            except Exception as e:
                if not args.json:
                    print(f"{name:<20} ERROR: {e}")
                results['libraries'][lib_key]['conversion'][name] = {'error': str(e)}

    # Memory benchmarks
    if not args.json:
        print("\n## Memory Usage (generated_medium fixture)\n")

    medium_content = fixtures.get('generated_medium', list(fixtures.values())[0])
    results['memory'] = {}

    for lib_key, lib_info in LIBRARIES.items():
        lib_name = lib_info['name']
        convert_fn = lib_info['convert']

        try:
            tracemalloc.start()
            convert_fn(medium_content)
            current, peak = tracemalloc.get_traced_memory()
            tracemalloc.stop()

            results['memory'][lib_key] = {
                'current': current,
                'peak': peak
            }

            if not args.json:
                print(f"{lib_name:<20} Current: {format_size(current):>10}  Peak: {format_size(peak):>10}")
        except Exception as e:
            if not args.json:
                print(f"{lib_name:<20} ERROR: {e}")

    # Metadata
    results['meta'] = {
        'runtime': 'python',
        'version': sys.version.split()[0],
        'iterations': args.iterations,
        'warmup': args.warmup,
        'timestamp': time.strftime('%Y-%m-%dT%H:%M:%S%z')
    }

    if args.json:
        print(json.dumps(results, indent=2))
    else:
        print("\nBenchmark complete.")


if __name__ == '__main__':
    main()
