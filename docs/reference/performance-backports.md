# Carve PHP performance backports

The October 2 core benchmark includes blank-separated nested lists and aligned
table headers. Both made Djot's borrowed HTML route decline the entire document.
The backport accepts a bounded subset of those constructs while preserving
Djot's list tightness, continuation lines, table headers and alignment styles.
Ordinary compact indented list markers still continue a paragraph; a blank
before the nested list gives it structure. Ambiguous boundaries use the AST.

The parser changes adapt Carve's bracket indexing and marker/fence screens:

- Index nested bracket pairs and failed openers once per inline run. Preserve
  Djot's existing escape rules and restore caches after recursive parsing.
- Skip the list marker regex cascade when the first bytes cannot form a marker.
  Subclasses retain the original cascade.
- Skip code fence closer regexes on payload lines whose first non-whitespace
  byte is not the fence character. Preserve the existing whitespace rules.

Carve's bulk HTML indentation and streaming writer changes do not apply directly
to Djot's compact renderer. Its codec and source-frame changes depend on APIs
that Djot does not share. These backports retain the 64 KiB speculation bound,
configuration gates and public AST route. Unclosed outer link labels and
reference labels requiring whitespace normalization now use the AST; regression
tests cover the two existing facade mismatches found during review.

## Paired measurements

Measured October 2, 2026 with PHP 8.5.11, active tracing JIT and no coverage
extensions. Four serial rounds reverse engine order. Each fresh process warms
20 calls and retains every timed sample. Baseline Djot is `9db632d`; Carve is
`988f3d340`. Candidate source digests, input/output hashes, runtime flags, load
observations and every sample are in [the raw record](./performance-backports.json).

The completed implementation's core run uses CPU affinity 13, ten calls per
trial and 28 samples per engine. The host was heavily loaded. Native syntax
differs: Djot receives 62,782 bytes and Carve 62,632.

| Engine | Median ms | Throughput MiB/s |
|---|---:|---:|
| Baseline Djot | 32.474 | 1.84 |
| Updated Djot | 4.715 | 12.70 |
| Current Carve | 5.873 | 10.17 |

Updated Djot is 6.89x faster than baseline Djot and has 25% higher throughput
than current Carve in this run. Most of the gain comes from accepting nested
lists and aligned tables on the borrowed route. Each Djot baseline/candidate
output hash matches, and each engine's default output matches its own AST.
The shared benchmark's normalized HTML projection matches hierarchy, text,
emphasis, links and code; it omits table alignment.

The earlier session uses CPU affinity 12 and 50 calls per core trial. It
measured baseline Djot at 17.073 ms, updated Djot at 3.238 ms and Carve at
3.936 ms: 5.27x over baseline and 22% higher throughput than Carve. This
session precedes the two conservative inline fallback fixes. Both fixes leave
the core fixture's route and output unchanged. Its phase measurements are:

| Workload | Baseline Djot ms | Updated Djot ms | Speedup |
|---|---:|---:|---:|
| List items with ordinary continuation lines | 6.493 | 5.792 | 1.12x |
| Code fence with 8,192 payload lines | 2.109 | 1.658 | 1.27x |
| 4,096 failed bracket openers, AST route | 44.884 | 3.625 | 12.38x |
| Plain paragraphs | 0.437 | 0.438 | 1.00x |

The explicit AST core control varied from roughly 15 to 44 ms in the earlier
session. Its aggregate medians are 25.116 ms baseline and 23.993 ms candidate.
This run does not support a general AST speedup or a ranking across all
documents. The plain control is flat. The final run followed a sharp increase
in machine contention; absolute timings across these sessions are not an
engine-only comparison.

## Reproduce

Install candidate dependencies, then supply unchanged baseline and Carve
checkouts. The PHP harness selects each requested source directory and verifies
it through reflection. The Python driver requires Linux CPU affinity support.

```bash
python3 tests/benchmark/compare-backports.py \
  --baseline /path/to/djot-baseline \
  --carve /path/to/carve-php \
  --cpu 13 --cases core --iterations 10 --output /tmp/backports.json
```

Omit `--cases` and `--iterations` to run every phase with the original counts.
`core_ast` and `brackets` explicitly configure the AST converter. The raw
`borrowed_accepted` field records eligibility, even when configuration chooses
the AST. The 150-section core fixture reproduces the current Carve benchmark
input hashes. Loose nested lists, unsupported indentation, table spans,
repeated separators and ragged aligned rows retain the complete parser.
