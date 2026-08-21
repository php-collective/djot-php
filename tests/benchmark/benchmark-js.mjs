#!/usr/bin/env node
/**
 * JavaScript Djot Benchmark
 *
 * Benchmarks the reference JavaScript djot implementation for comparison
 * with the PHP implementation.
 */

import { parse, renderHTML } from '@djot/djot';
import { readFileSync, readdirSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// Parse CLI arguments
const args = process.argv.slice(2);
const iterations = parseInt(args.find(a => a.startsWith('--iterations='))?.split('=')[1] || '100');
const warmup = parseInt(args.find(a => a.startsWith('--warmup='))?.split('=')[1] || '10');
const jsonOutput = args.includes('--json');

// Load fixtures
function loadFixtures() {
    const fixturesDir = join(__dirname, 'fixtures');
    const fixtures = {};

    if (existsSync(fixturesDir)) {
        const files = readdirSync(fixturesDir).filter(f => f.endsWith('.djot'));
        for (const file of files) {
            const name = file.replace('.djot', '');
            fixtures[name] = readFileSync(join(fixturesDir, file), 'utf-8');
        }
    }

    // Add generated fixtures
    fixtures['generated_tiny'] = generateFixture(1024);
    fixtures['generated_small'] = generateFixture(10 * 1024);
    fixtures['generated_medium'] = generateFixture(50 * 1024);
    fixtures['generated_large'] = generateFixture(200 * 1024);
    fixtures['generated_huge'] = generateFixture(1024 * 1024);

    return fixtures;
}

function generateFixture(targetBytes) {
    let content = '# Large Document Test\n\n';
    const chunk = 'Paragraph with *bold* and _italic_ text. A [link](https://example.com) and `code`.\n\n';
    while (Buffer.byteLength(content) + Buffer.byteLength(chunk) <= targetBytes) {
        content += chunk;
    }
    return content;
}

// Benchmark function
function benchmark(fn, iterations, warmup) {
    // Warmup
    for (let i = 0; i < warmup; i++) {
        fn();
    }

    // Collect timings
    const times = [];
    for (let i = 0; i < iterations; i++) {
        const start = process.hrtime.bigint();
        fn();
        const end = process.hrtime.bigint();
        times.push(Number(end - start) / 1e6); // Convert to milliseconds
    }

    times.sort((a, b) => a - b);
    const count = times.length;

    return {
        min: times[0],
        max: times[count - 1],
        mean: times.reduce((a, b) => a + b, 0) / count,
        median: count % 2 === 0
            ? (times[count / 2 - 1] + times[count / 2]) / 2
            : times[Math.floor(count / 2)],
        p95: times[Math.floor(count * 0.95)],
        p99: times[Math.floor(count * 0.99)],
        stddev: calculateStdDev(times),
        iterations
    };
}

function calculateStdDev(values) {
    const count = values.length;
    if (count < 2) return 0;

    const mean = values.reduce((a, b) => a + b, 0) / count;
    const variance = values.reduce((sum, val) => sum + Math.pow(val - mean, 2), 0) / (count - 1);
    return Math.sqrt(variance);
}

function formatMs(ms) {
    if (ms < 1) {
        return `${(ms * 1000).toFixed(2)} µs`;
    }
    if (ms < 1000) {
        return `${ms.toFixed(2)} ms`;
    }
    return `${(ms / 1000).toFixed(2)} s`;
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// Main benchmark
async function main() {
    const fixtures = loadFixtures();
    const results = {};

    if (!jsonOutput) {
        console.log('JavaScript Djot Benchmark (Reference Implementation)');
        console.log('====================================================');
        console.log(`Node.js Version: ${process.version}`);
        console.log(`Iterations: ${iterations}, Warmup: ${warmup}`);
        console.log('');
    }

    // Conversion benchmarks
    if (!jsonOutput) {
        console.log('## Document Size Benchmarks\n');
        console.log(`${'Fixture'.padEnd(20)} ${'Size'.padStart(10)} ${'Mean'.padStart(12)} ${'Median'.padStart(12)} ${'P95'.padStart(12)} ${'Throughput'.padStart(12)}`);
        console.log('-'.repeat(80));
    }

    results.conversion = {};
    for (const [name, content] of Object.entries(fixtures)) {
        const size = Buffer.byteLength(content, 'utf-8');

        const stats = benchmark(() => {
            const doc = parse(content);
            renderHTML(doc);
        }, iterations, warmup);

        const throughput = size / (stats.mean / 1000);

        results.conversion[name] = {
            size_bytes: size,
            stats,
            throughput_bps: throughput
        };

        if (!jsonOutput) {
            console.log(
                `${name.padEnd(20)} ${formatSize(size).padStart(10)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)} ${(formatSize(Math.round(throughput)) + '/s').padStart(12)}`
            );
        }
    }

    // Parse-only benchmarks
    if (!jsonOutput) {
        console.log('\n## Parse vs Render (generated_medium fixture)\n');
        console.log(`${'Phase'.padEnd(15)} ${'Mean'.padStart(12)} ${'Median'.padStart(12)} ${'P95'.padStart(12)}`);
        console.log('-'.repeat(55));
    }

    results.phases = {};
    const mediumContent = fixtures['generated_medium'] || fixtures['complex'] || Object.values(fixtures)[0];

    // Full conversion
    let stats = benchmark(() => {
        const doc = parse(mediumContent);
        renderHTML(doc);
    }, iterations, warmup);
    results.phases.full = stats;

    if (!jsonOutput) {
        console.log(`${'full'.padEnd(15)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)}`);
    }

    // Parse only
    stats = benchmark(() => {
        parse(mediumContent);
    }, iterations, warmup);
    results.phases.parse = stats;

    if (!jsonOutput) {
        console.log(`${'parse'.padEnd(15)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)}`);
    }

    // Render only
    const preParseDoc = parse(mediumContent);
    stats = benchmark(() => {
        renderHTML(preParseDoc);
    }, iterations, warmup);
    results.phases.render = stats;

    if (!jsonOutput) {
        console.log(`${'render'.padEnd(15)} ${formatMs(stats.mean).padStart(12)} ${formatMs(stats.median).padStart(12)} ${formatMs(stats.p95).padStart(12)}`);
    }

    // Memory usage
    if (!jsonOutput) {
        console.log('\n## Memory Usage\n');
    }

    results.memory = {};
    const heapUsed = process.memoryUsage().heapUsed;
    results.memory.heap_used = heapUsed;

    if (!jsonOutput) {
        console.log(`Heap Used: ${formatSize(heapUsed)}`);
    }

    // Metadata
    results.meta = {
        runtime: 'node',
        version: process.version,
        library: '@djot/djot',
        iterations,
        warmup,
        timestamp: new Date().toISOString()
    };

    if (jsonOutput) {
        console.log(JSON.stringify(results, null, 2));
    } else {
        console.log('\nBenchmark complete.');
    }
}

main().catch(console.error);
