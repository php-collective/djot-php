# Parser performance audit

Compared with `c1a4b2843134` on PHP 8.5.11, using a clean CLI configuration with ctype and mbstring. Each result is the median of 20 CPU-time samples per variant from four alternating fresh processes pinned to CPU 13. Ratios below show CPU throughput relative to the baseline.

| Workload | JIT off | Tracing JIT |
| --- | ---: | ---: |
| Mismatched backtick run, 48 KB body | 6.52× | 8.61× |
| Long emphasis body | 2.03× | 1.48× |
| Long braced body | 1.11× | 1.24× |
| 5,000 warnings in one paragraph | 1.44× | 1.33× |
| Raw splitting, one long table cell | 21.73× | 13.24× |
| Code-span checks, one long table cell | 44.44× | 14.76× |
| Unicode headings and paragraphs | 2.09× | 1.79× |
| Simple image paragraphs | 3.85× | 3.50× |
| Tight star list | 5.94× | 4.63× |
| Default core control | 0.97× | 0.98× |

The shared host was busy. Wall times and memory peaks are retained in the [raw results](../../tests/benchmark/parser-audit-results.json), along with input, output, and source hashes. The control results do not support a general core speedup claim. Long-cell numbers measure the table helpers, and streaming numbers measure the output buffer.

The retained changes cover raw table splitting, table code-span scans, code-span search advancement, braced closers, long emphasis scans, and warning locations. Short emphasis bodies keep the byte loop. Warning lookup walks forward through newline offsets and uses binary search for backward queries.

Skipping a mismatched backtick run now advances to its actual end. An unclosed code span after such a run consumes the remaining paragraph, including a final character or a terminal mismatched run. Regression tests record this behavior fix.

The default layout also accepts plain Unicode letters, marks, and numbers, simple whole-line images, and flat star/plus lists. Unicode formatting, special spaces, emoji, image titles/attributes, and ambiguous lists retain AST fallback. Mixed thematic breaks are checked before list routing; headings with empty slugs also fall back.

The renderer experiment accumulated child output in an array before `implode()`. It slowed isolated rendering by 3–6% and was reverted. The allocation profile for the 100-section fixture contains 2,101 nodes, including 1,000 text nodes, and retains 722,536 to 725,736 bytes after parsing in both variants. This includes parser state. The node API is preserved.

Validation: 2,932 tests and 20,007 assertions pass, including official fixtures and scanner, warning-position, and route parity checks. PHPStan, PHPCS, and Claude review pass.

Run from the candidate checkout after installing its development dependencies:

```sh
git worktree add --detach /tmp/djot-audit-base c1a4b2843134
python3 tests/benchmark/compare-parser-audit.py --engine djot \
  --baseline /tmp/djot-audit-base --cpu 13 --output /tmp/djot-audit.json
```

Choose an available CPU with `--cpu`. The baseline only supplies `src`; its vendor directory is unused. The driver checks source stability and exact output hashes across variants.
