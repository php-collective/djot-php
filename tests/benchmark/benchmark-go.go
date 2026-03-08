// Go Djot Benchmark
// Benchmarks the godjot library - a Djot parser for Go
//
// https://github.com/sivukhin/godjot
//
// Run:
//   go mod tidy
//   go build -o benchmark-go-bin benchmark-go.go
//   ./benchmark-go-bin --json

package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"time"

	"github.com/sivukhin/godjot/djot_parser"
	"github.com/sivukhin/godjot/html_writer"
)

type Stats struct {
	Mean   float64 `json:"mean"`
	Median float64 `json:"median"`
	Min    float64 `json:"min"`
	Max    float64 `json:"max"`
	Stddev float64 `json:"stddev"`
	P95    float64 `json:"p95"`
	P99    float64 `json:"p99"`
}

type FixtureResult struct {
	Name          string  `json:"name"`
	SizeBytes     int     `json:"size_bytes"`
	Stats         Stats   `json:"stats"`
	ThroughputBps float64 `json:"throughput_bps"`
}

type Meta struct {
	Version string `json:"version"`
	OS      string `json:"os"`
}

type BenchmarkResult struct {
	Meta       Meta            `json:"meta"`
	Name       string          `json:"name"`
	Version    string          `json:"version"`
	Conversion []FixtureResult `json:"conversion"`
}

func calculateStats(times []float64) Stats {
	n := len(times)
	if n == 0 {
		return Stats{}
	}

	sorted := make([]float64, n)
	copy(sorted, times)
	sort.Float64s(sorted)

	sum := 0.0
	for _, t := range sorted {
		sum += t
	}
	mean := sum / float64(n)

	var median float64
	if n%2 == 0 {
		median = (sorted[n/2-1] + sorted[n/2]) / 2
	} else {
		median = sorted[n/2]
	}

	variance := 0.0
	for _, t := range sorted {
		variance += (t - mean) * (t - mean)
	}
	variance /= float64(n)
	stddev := 0.0
	if variance > 0 {
		stddev = sqrt(variance)
	}

	p95Idx := int(float64(n)*0.95) - 1
	if p95Idx < 0 {
		p95Idx = 0
	}
	if p95Idx >= n {
		p95Idx = n - 1
	}

	p99Idx := int(float64(n)*0.99) - 1
	if p99Idx < 0 {
		p99Idx = 0
	}
	if p99Idx >= n {
		p99Idx = n - 1
	}

	return Stats{
		Mean:   mean,
		Median: median,
		Min:    sorted[0],
		Max:    sorted[n-1],
		Stddev: stddev,
		P95:    sorted[p95Idx],
		P99:    sorted[p99Idx],
	}
}

func sqrt(x float64) float64 {
	if x <= 0 {
		return 0
	}
	z := x
	for i := 0; i < 10; i++ {
		z = (z + x/z) / 2
	}
	return z
}

func generateContent(targetBytes int) string {
	content := "# Large Document Test\n\n"
	chunk := "Paragraph with *bold* and _italic_ text. A [link](https://example.com) and `code`.\n\n"
	for len(content) < targetBytes {
		content += chunk
	}
	if len(content) > targetBytes {
		content = content[:targetBytes]
	}
	return content
}

func benchmarkGodjot(content string, iterations, warmup int) []float64 {
	// Warmup
	for i := 0; i < warmup; i++ {
		ast := djot_parser.BuildDjotAst([]byte(content))
		html_writer.NewHtmlWriter().BuildHtml(&ast)
	}

	// Benchmark
	times := make([]float64, iterations)
	for i := 0; i < iterations; i++ {
		start := time.Now()
		ast := djot_parser.BuildDjotAst([]byte(content))
		html_writer.NewHtmlWriter().BuildHtml(&ast)
		times[i] = float64(time.Since(start).Nanoseconds()) / 1e6
	}
	return times
}

func main() {
	iterations := flag.Int("iterations", 50, "Number of benchmark iterations")
	warmup := flag.Int("warmup", 10, "Number of warmup iterations")
	jsonOutput := flag.Bool("json", false, "Output JSON format")
	flag.Parse()

	// Load fixtures
	fixturesDir := "fixtures"
	fixtureNames := []string{"tiny", "small", "medium", "large", "huge"}

	var fixtures []struct {
		name    string
		content string
	}

	// Try to load pre-generated fixtures
	for _, name := range fixtureNames {
		path := filepath.Join(fixturesDir, fmt.Sprintf("generated_%s.djot", name))
		content, err := os.ReadFile(path)
		if err == nil {
			fixtures = append(fixtures, struct {
				name    string
				content string
			}{
				name:    fmt.Sprintf("generated_%s", name),
				content: string(content),
			})
		}
	}

	// If no fixtures found, generate test content
	if len(fixtures) == 0 {
		fixtures = []struct {
			name    string
			content string
		}{
			{"tiny", generateContent(1024)},
			{"small", generateContent(10 * 1024)},
			{"medium", generateContent(50 * 1024)},
			{"large", generateContent(200 * 1024)},
			{"huge", generateContent(1024 * 1024)},
		}
	}

	if !*jsonOutput {
		fmt.Fprintln(os.Stderr, "Go Djot Benchmark (godjot)")
		fmt.Fprintln(os.Stderr, "==========================")
		fmt.Fprintf(os.Stderr, "Iterations: %d, Warmup: %d\n\n", *iterations, *warmup)
	}

	var results []FixtureResult

	for _, fixture := range fixtures {
		size := len(fixture.content)

		if !*jsonOutput {
			fmt.Fprintf(os.Stderr, "Fixture: %s (%d bytes)\n", fixture.name, size)
		}

		times := benchmarkGodjot(fixture.content, *iterations, *warmup)
		stats := calculateStats(times)
		throughput := (float64(size) / stats.Mean) * 1000.0

		if !*jsonOutput {
			fmt.Fprintf(os.Stderr, "  godjot: %.2f ms (throughput: %.1f MB/s)\n",
				stats.Mean, throughput/1_000_000)
		}

		results = append(results, FixtureResult{
			Name:          fixture.name,
			SizeBytes:     size,
			Stats:         stats,
			ThroughputBps: throughput,
		})
	}

	result := BenchmarkResult{
		Meta: Meta{
			Version: runtime.Version(),
			OS:      runtime.GOOS,
		},
		Name:       "godjot",
		Version:    "latest",
		Conversion: results,
	}

	if *jsonOutput {
		output, _ := json.Marshal(result)
		fmt.Println(string(output))
	} else {
		fmt.Fprintln(os.Stderr, "\nDone.")
	}
}
