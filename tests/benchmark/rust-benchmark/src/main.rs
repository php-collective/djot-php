use serde::Serialize;
/// Rust Djot Benchmark
/// Benchmarks the jotdown crate - a Djot parser for Rust
///
/// https://github.com/hellux/jotdown
use std::env;
use std::fs;
use std::path::Path;
use std::time::Instant;

use jotdown::{html::Renderer, Parser, Render};

#[derive(Serialize)]
struct Stats {
    mean: f64,
    median: f64,
    min: f64,
    max: f64,
    stddev: f64,
    p95: f64,
    p99: f64,
}

#[derive(Serialize)]
struct FixtureResult {
    name: String,
    size_bytes: usize,
    stats: Stats,
    throughput_bps: f64,
}

#[derive(Serialize)]
struct BenchmarkResult {
    meta: Meta,
    name: String,
    version: String,
    conversion: Vec<FixtureResult>,
}

#[derive(Serialize)]
struct Meta {
    version: String,
    os: String,
}

fn calculate_stats(times: &mut Vec<f64>) -> Stats {
    times.sort_by(|a, b| a.partial_cmp(b).unwrap());
    let n = times.len();
    let sum: f64 = times.iter().sum();
    let mean = sum / n as f64;

    let median = if n % 2 == 0 {
        (times[n / 2 - 1] + times[n / 2]) / 2.0
    } else {
        times[n / 2]
    };

    let variance: f64 = times.iter().map(|t| (t - mean).powi(2)).sum::<f64>() / n as f64;
    let stddev = variance.sqrt();

    let p95_idx = ((n as f64 * 0.95) as usize).min(n - 1);
    let p99_idx = ((n as f64 * 0.99) as usize).min(n - 1);

    Stats {
        mean,
        median,
        min: times[0],
        max: times[n - 1],
        stddev,
        p95: times[p95_idx],
        p99: times[p99_idx],
    }
}

fn generate_content(target_bytes: usize) -> String {
    let mut content = String::from("# Large Document Test\n\n");
    let chunk =
        "Paragraph with *bold* and _italic_ text. A [link](https://example.com) and `code`.\n\n";
    while content.len() + chunk.len() <= target_bytes {
        content.push_str(chunk);
    }
    content
}

fn benchmark_jotdown(content: &str, iterations: usize, warmup: usize) -> Vec<f64> {
    // Warmup
    for _ in 0..warmup {
        let parser = Parser::new(content);
        let mut output = String::new();
        Renderer::default().push(parser, &mut output).unwrap();
    }

    // Benchmark
    let mut times = Vec::with_capacity(iterations);
    for _ in 0..iterations {
        let start = Instant::now();
        let parser = Parser::new(content);
        let mut output = String::new();
        Renderer::default().push(parser, &mut output).unwrap();
        times.push(start.elapsed().as_secs_f64() * 1000.0);
    }
    times
}

fn main() {
    let args: Vec<String> = env::args().collect();
    let iterations: usize = args
        .iter()
        .find(|a| a.starts_with("--iterations="))
        .and_then(|a| a.split('=').nth(1))
        .and_then(|s| s.parse().ok())
        .unwrap_or(50);
    let warmup: usize = args
        .iter()
        .find(|a| a.starts_with("--warmup="))
        .and_then(|a| a.split('=').nth(1))
        .and_then(|s| s.parse().ok())
        .unwrap_or(10);
    let json_output = args.iter().any(|a| a == "--json");

    // Load fixtures
    let fixtures_dir = Path::new("fixtures");
    let fixture_names = ["tiny", "small", "medium", "large", "huge"];

    let mut fixtures: Vec<(String, String)> = Vec::new();

    // Try to load pre-generated fixtures
    for name in &fixture_names {
        let path = fixtures_dir.join(format!("generated_{}.djot", name));
        if let Ok(content) = fs::read_to_string(&path) {
            fixtures.push((format!("generated_{}", name), content));
        }
    }

    // If no fixtures found, generate test content
    if fixtures.is_empty() {
        fixtures = vec![
            ("tiny".to_string(), generate_content(1024)),
            ("small".to_string(), generate_content(10 * 1024)),
            ("medium".to_string(), generate_content(50 * 1024)),
            ("large".to_string(), generate_content(200 * 1024)),
            ("huge".to_string(), generate_content(1024 * 1024)),
        ];
    }

    if !json_output {
        eprintln!("Rust Djot Benchmark (jotdown)");
        eprintln!("=============================");
        eprintln!("Iterations: {}, Warmup: {}", iterations, warmup);
        eprintln!();
    }

    let mut results: Vec<FixtureResult> = Vec::new();

    for (name, content) in &fixtures {
        let size = content.len();

        if !json_output {
            eprintln!("Fixture: {} ({} bytes)", name, size);
        }

        let mut times = benchmark_jotdown(content, iterations, warmup);
        let stats = calculate_stats(&mut times);
        let throughput = (size as f64 / stats.mean) * 1000.0;

        if !json_output {
            eprintln!(
                "  jotdown: {:.2} ms (throughput: {:.1} MB/s)",
                stats.mean,
                throughput / 1_000_000.0
            );
        }

        results.push(FixtureResult {
            name: name.clone(),
            size_bytes: size,
            stats,
            throughput_bps: throughput,
        });
    }

    let result = BenchmarkResult {
        meta: Meta {
            version: env!("CARGO_PKG_VERSION").to_string(),
            os: std::env::consts::OS.to_string(),
        },
        name: "jotdown".to_string(),
        version: "0.7".to_string(),
        conversion: results,
    };

    if json_output {
        println!("{}", serde_json::to_string(&result).unwrap());
    } else {
        eprintln!("\nDone.");
    }
}
