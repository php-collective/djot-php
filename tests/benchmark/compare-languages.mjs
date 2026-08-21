#!/usr/bin/env node
/**
 * Cross-Language Benchmark Comparison
 *
 * Runs benchmarks for PHP, JavaScript, and Python implementations
 * and produces a unified comparison report.
 */

import { execSync, spawn } from 'child_process';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const resultsDir = join(__dirname, 'results');

// CLI arguments
const args = process.argv.slice(2);
const iterations = args.find(a => a.startsWith('--iterations='))?.split('=')[1] || '50';
const warmup = args.find(a => a.startsWith('--warmup='))?.split('=')[1] || '10';
const outputFormat = args.includes('--html') ? 'html' : (args.includes('--json') ? 'json' : 'console');
const djotOnly = args.includes('--djot-only');

// Ensure results directory exists
if (!existsSync(resultsDir)) {
    mkdirSync(resultsDir, { recursive: true });
}

function runCommand(cmd, description) {
    console.log(`Running: ${description}...`);
    try {
        const result = execSync(cmd, {
            cwd: __dirname,
            encoding: 'utf-8',
            timeout: 300000, // 5 minutes
            maxBuffer: 50 * 1024 * 1024
        });
        return JSON.parse(result);
    } catch (error) {
        console.error(`Failed: ${description}`);
        console.error(error.message);
        return null;
    }
}

function formatMs(ms) {
    if (ms < 1) return `${(ms * 1000).toFixed(2)} µs`;
    if (ms < 1000) return `${ms.toFixed(2)} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function calculateSpeedup(baseline, comparison) {
    if (!baseline || !comparison) return 'N/A';
    const speedup = baseline / comparison;
    if (speedup >= 1) {
        return `${speedup.toFixed(2)}x faster`;
    } else {
        return `${(1/speedup).toFixed(2)}x slower`;
    }
}

async function main() {
    console.log('Cross-Language Djot Benchmark Comparison');
    console.log('========================================\n');

    const results = {
        php: null,
        javascript: null,
        python: null,
        rust: null,
        go: null
    };

    // Run PHP benchmark
    console.log('1. Running PHP benchmark...');
    try {
        results.php = runCommand(
            `php -d opcache.enable_cli=1 benchmark.php --iterations=${iterations} --warmup=${warmup} --json`,
            'PHP djot-php'
        );
        if (results.php) {
            console.log('   ✓ PHP benchmark complete\n');
        }
    } catch (e) {
        console.log('   ✗ PHP benchmark failed\n');
    }

    // Run JavaScript benchmark
    console.log('2. Running JavaScript benchmark...');
    try {
        // Check if node_modules exists
        if (!existsSync(join(__dirname, 'node_modules'))) {
            console.log('   Installing npm dependencies...');
            execSync('npm install', { cwd: __dirname, stdio: 'ignore' });
        }
        results.javascript = runCommand(
            `node benchmark-js.mjs --iterations=${iterations} --warmup=${warmup} --json`,
            'JavaScript @djot/djot'
        );
        if (results.javascript) {
            console.log('   ✓ JavaScript benchmark complete\n');
        }
    } catch (e) {
        console.log('   ✗ JavaScript benchmark failed\n');
    }

    // Run Python benchmark
    console.log('3. Running Python benchmark...');
    try {
        if (djotOnly) {
            console.log('   - skipped: --djot-only compares Djot implementations\n');
        } else {
            results.python = runCommand(
                `python3 benchmark-python.py --iterations=${iterations} --warmup=${warmup} --json`,
                'Python markdown libraries'
            );
            if (results.python) {
                console.log('   ✓ Python benchmark complete\n');
            }
        }
    } catch (e) {
        console.log('   ✗ Python benchmark failed (missing dependencies?)\n');
    }

    // Run Rust benchmark
    console.log('4. Running Rust benchmark...');
    try {
        results.rust = runCommand(
            `cargo run --release --quiet --manifest-path=rust-benchmark/Cargo.toml -- --iterations=${iterations} --warmup=${warmup} --json`,
            'Rust jotdown'
        );
        if (results.rust) {
            console.log('   ✓ Rust benchmark complete\n');
        }
    } catch (e) {
        console.log('   ✗ Rust benchmark failed (cargo not installed?)\n');
    }

    // Run Go benchmark
    console.log('5. Running Go benchmark...');
    try {
        results.go = runCommand(
            `go run -mod=mod benchmark-go.go --iterations=${iterations} --warmup=${warmup} --json`,
            'Go godjot'
        );
        if (results.go) {
            console.log('   ✓ Go benchmark complete\n');
        }
    } catch (e) {
        console.log('   ✗ Go benchmark failed (go not installed?)\n');
    }

    // Save raw results
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    writeFileSync(
        join(resultsDir, `benchmark-${timestamp}.json`),
        JSON.stringify(results, null, 2)
    );

    // Generate comparison report
    if (outputFormat === 'json') {
        console.log(JSON.stringify(results, null, 2));
        return;
    }

    console.log('\n' + '='.repeat(80));
    console.log('COMPARISON REPORT');
    console.log('='.repeat(80) + '\n');

    // Find common fixtures for comparison
    const phpConversion = results.php?.conversion || {};
    const jsConversion = results.javascript?.conversion || {};

    // Helper to find fixture with fallback names
    const getFixture = (conv, ...names) => {
        for (const name of names) {
            if (conv[name]) return conv[name];
        }
        return null;
    };

    // Compare on medium fixture (PHP uses "medium", JS uses "generated_medium")
    console.log('## Performance Comparison (medium fixture)\n');
    console.log(`${'Implementation'.padEnd(25)} ${'Mean'.padStart(12)} ${'Median'.padStart(12)} ${'P95'.padStart(12)} ${'Throughput'.padStart(14)}`);
    console.log('-'.repeat(80));

    // PHP
    const phpMedium = getFixture(phpConversion, 'medium', 'generated_medium');
    if (phpMedium) {
        const stats = phpMedium.stats;
        const throughput = phpMedium.throughput_bps;
        console.log(
            `${'PHP (djot-php)'.padEnd(25)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
        );
    }

    // JavaScript
    const jsMedium = getFixture(jsConversion, 'generated_medium', 'medium');
    if (jsMedium) {
        const stats = jsMedium.stats;
        const throughput = jsMedium.throughput_bps;
        console.log(
            `${'JS (@djot/djot)'.padEnd(25)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
        );
    }

    // Python libraries
    if (results.python?.libraries) {
        for (const [key, lib] of Object.entries(results.python.libraries)) {
            const pyConv = lib.conversion || {};
            const pyMedium = getFixture(pyConv, 'generated_medium', 'medium');
            if (pyMedium?.stats) {
                const stats = pyMedium.stats;
                const throughput = pyMedium.throughput_bps;
                console.log(
                    `${'Py (' + lib.name.substring(0, 18) + ')'.padEnd(25)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
                );
            }
        }
    }

    // Rust (jotdown)
    if (results.rust?.conversion) {
        const rsConv = results.rust.conversion || [];
        const rsMedium = rsConv.find(f => f.name === 'generated_medium' || f.name === 'medium');
        if (rsMedium?.stats) {
            const stats = rsMedium.stats;
            const throughput = rsMedium.throughput_bps;
            const name = results.rust.name || 'jotdown';
            console.log(
                `${'Rust (' + name + ')'.padEnd(25)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
            );
        }
    }

    // Go (godjot)
    if (results.go?.conversion) {
        const goConv = results.go.conversion || [];
        const goMedium = goConv.find(f => f.name === 'generated_medium' || f.name === 'medium');
        if (goMedium?.stats) {
            const stats = goMedium.stats;
            const throughput = goMedium.throughput_bps;
            const name = results.go.name || 'godjot';
            console.log(
                `${'Go (' + name + ')'.padEnd(25)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
            );
        }
    }

    // Relative performance comparison
    console.log('\n## Relative Performance (PHP as baseline)\n');

    const phpMean = phpMedium?.stats?.mean;
    if (phpMean) {
        console.log('PHP djot-php: 1.00x (baseline)');

        if (jsMedium?.stats?.mean) {
            const jsMean = jsMedium.stats.mean;
            const ratio = phpMean / jsMean;
            if (ratio >= 1) {
                console.log(`JS @djot/djot: ${ratio.toFixed(2)}x faster than PHP`);
            } else {
                console.log(`JS @djot/djot: ${(1/ratio).toFixed(2)}x slower than PHP`);
            }
        }

        if (results.python?.libraries) {
            for (const [key, lib] of Object.entries(results.python.libraries)) {
                const pyConv = lib.conversion || {};
                const pyMedium = getFixture(pyConv, 'generated_medium', 'medium');
                const pyMean = pyMedium?.stats?.mean;
                if (pyMean) {
                    const ratio = phpMean / pyMean;
                    if (ratio >= 1) {
                        console.log(`Py ${lib.name}: ${ratio.toFixed(2)}x faster than PHP`);
                    } else {
                        console.log(`Py ${lib.name}: ${(1/ratio).toFixed(2)}x slower than PHP`);
                    }
                }
            }
        }

        if (results.rust?.conversion) {
            const rsConv = results.rust.conversion || [];
            const rsMedium = rsConv.find(f => f.name === 'generated_medium' || f.name === 'medium');
            const rsMean = rsMedium?.stats?.mean;
            if (rsMean) {
                const name = results.rust.name || 'jotdown';
                const ratio = phpMean / rsMean;
                if (ratio >= 1) {
                    console.log(`Rust ${name}: ${ratio.toFixed(2)}x faster than PHP`);
                } else {
                    console.log(`Rust ${name}: ${(1/ratio).toFixed(2)}x slower than PHP`);
                }
            }
        }

        if (results.go?.conversion) {
            const goConv = results.go.conversion || [];
            const goMedium = goConv.find(f => f.name === 'generated_medium' || f.name === 'medium');
            const goMean = goMedium?.stats?.mean;
            if (goMean) {
                const name = results.go.name || 'godjot';
                const ratio = phpMean / goMean;
                if (ratio >= 1) {
                    console.log(`Go ${name}: ${ratio.toFixed(2)}x faster than PHP`);
                } else {
                    console.log(`Go ${name}: ${(1/ratio).toFixed(2)}x slower than PHP`);
                }
            }
        }
    }

    // Document size scaling
    console.log('\n## Document Size Scaling (PHP djot-php)\n');
    const sizeFixtureNames = [
        ['small', 'generated_small'],
        ['medium', 'generated_medium'],
        ['large', 'generated_large'],
        ['huge', 'generated_huge'],
    ];
    console.log(`${'Size'.padEnd(20)} ${'Input'.padStart(10)} ${'Mean'.padStart(12)} ${'Throughput'.padStart(14)}`);
    console.log('-'.repeat(60));

    for (const names of sizeFixtureNames) {
        const fixture = getFixture(phpConversion, ...names);
        if (fixture) {
            const size = fixture.size_bytes;
            const mean = fixture.stats.mean;
            const throughput = fixture.throughput_bps;
            console.log(
                `${names[0].padEnd(20)} ${formatSize(size).padStart(10)} ${formatMs(mean).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(14)}`
            );
        }
    }

    // Summary
    console.log('\n## Summary\n');
    console.log('Runtime versions:');
    if (results.php?.meta) {
        console.log(`  PHP: ${results.php.meta.php_version}`);
    }
    if (results.javascript?.meta) {
        console.log(`  Node.js: ${results.javascript.meta.version}`);
    }
    if (results.python?.meta) {
        console.log(`  Python: ${results.python.meta.version}`);
    }

    console.log(`\nResults saved to: ${join(resultsDir, `benchmark-${timestamp}.json`)}`);

    if (outputFormat === 'html') {
        generateHtmlReport(results, timestamp);
    }
}

function generateHtmlReport(results, timestamp) {
    const html = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Djot-PHP Benchmark Results</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 2rem; }
        h1 { color: #333; }
        table { border-collapse: collapse; margin: 1rem 0; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 0.5rem 1rem; text-align: right; }
        th { background: #f5f5f5; }
        td:first-child, th:first-child { text-align: left; }
        .faster { color: green; }
        .slower { color: red; }
        .chart { height: 300px; margin: 2rem 0; }
        pre { background: #f5f5f5; padding: 1rem; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Djot-PHP Benchmark Results</h1>
    <p>Generated: ${new Date().toISOString()}</p>

    <h2>Raw Results</h2>
    <pre>${JSON.stringify(results, null, 2)}</pre>
</body>
</html>`;

    writeFileSync(join(resultsDir, `benchmark-${timestamp}.html`), html);
    console.log(`HTML report saved to: ${join(resultsDir, `benchmark-${timestamp}.html`)}`);
}

main().catch(console.error);
