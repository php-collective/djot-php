#!/usr/bin/env python3
"""Run serial, alternating PHP backport benchmarks and retain all samples."""

import argparse
import datetime
import json
import os
from pathlib import Path
import statistics
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--baseline', type=Path, required=True, help='Unchanged Djot checkout')
parser.add_argument('--candidate', type=Path, default=Path(__file__).resolve().parents[2])
parser.add_argument('--carve', type=Path, required=True, help='Carve checkout for the core comparison')
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--cpu', type=int, default=min(os.sched_getaffinity(0)))
parser.add_argument('--rounds', type=int, default=4)
parser.add_argument('--cases', nargs='+', choices=['core', 'core_ast', 'markers', 'fences', 'brackets', 'plain'])
parser.add_argument('--iterations', type=int, help='Override timed calls per trial')
args = parser.parse_args()
if args.rounds < 2 or args.cpu not in os.sched_getaffinity(0) or (args.iterations is not None and args.iterations < 1):
    parser.error('Use at least two rounds, an available CPU and positive iterations.')

flags = ['php', '-n', '-d', 'extension=ctype', '-d', 'extension=mbstring',
         '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=128M', '-d', 'opcache.jit=tracing']
engines = {'baseline': ('djot', args.baseline), 'candidate': ('djot', args.candidate), 'carve': ('carve', args.carve)}
record = {'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'cpu': args.cpu,
          'load_start': os.getloadavg(), 'command_flags': flags, 'revisions': {}, 'cases': {}, 'runs': []}
for label, (_, tree) in engines.items():
    record['revisions'][label] = {
        'head': subprocess.check_output(['git', '-C', str(tree), 'rev-parse', 'HEAD'], text=True).strip(),
        'source_dirty': bool(subprocess.check_output(['git', '-C', str(tree), 'status', '--porcelain', '--', 'src'], text=True)),
    }

harness = args.candidate / 'tests/benchmark/backports.php'
for case, iterations, trials in [('core', 50, 7), ('core_ast', 20, 5), ('markers', 30, 5),
                                ('fences', 30, 5), ('brackets', 3, 5), ('plain', 300, 5)]:
    if args.cases and case not in args.cases:
        continue
    iterations = args.iterations or iterations
    names = ['baseline', 'candidate', 'carve'] if case == 'core' else ['baseline', 'candidate']
    for round_number in range(args.rounds):
        order = names if round_number % 2 == 0 else list(reversed(names))
        for label in order:
            engine, tree = engines[label]
            command = ['taskset', '-c', str(args.cpu), *flags, str(harness), engine,
                       str(tree.resolve() / 'src'), case, str(iterations), str(trials)]
            process = subprocess.run(command, text=True, capture_output=True, timeout=180)
            if process.returncode:
                raise RuntimeError(process.stdout + process.stderr)
            row = json.loads(process.stdout)
            if not (row['jit']['enabled'] and row['jit']['on'] and row['jit']['kind'] == 5) or row['coverage_loaded']:
                raise RuntimeError('Expected active tracing JIT without coverage extensions.')
            revision = record['revisions'][label]
            previous_digest = revision.setdefault('source_tree_sha256', row['source_tree_sha256'])
            if previous_digest != row['source_tree_sha256']:
                raise RuntimeError('PHP source changed during the benchmark.')
            row.update(label=label, round=round_number, load=os.getloadavg())
            record['runs'].append(row)
            args.output.write_text(json.dumps(record, indent=2) + '\n')
            print(case, label, round_number, round(statistics.median(row['samples_ms']), 4), flush=True)
    summaries = {}
    for label in names:
        rows = [row for row in record['runs'] if row['case'] == case and row['label'] == label]
        samples = [sample for row in rows for sample in row['samples_ms']]
        if len({row['output_sha256'] for row in rows}) != 1:
            raise RuntimeError('Output changed between rounds.')
        median = statistics.median(samples)
        summaries[label] = {'median_ms': median, 'range_ms': [min(samples), max(samples)],
                            'mb_per_s': rows[0]['bytes'] / 1048576 / (median / 1000), 'samples': len(samples)}
    djot_rows = [row for row in record['runs'] if row['case'] == case and row['engine'] == 'djot']
    if len({row['input_sha256'] for row in djot_rows}) != 1 or len({row['output_sha256'] for row in djot_rows}) != 1:
        raise RuntimeError('Baseline and candidate input/output hashes differ.')
    record['cases'][case] = summaries
record.update(completed_at=datetime.datetime.now(datetime.timezone.utc).isoformat(), load_end=os.getloadavg())
args.output.write_text(json.dumps(record, indent=2) + '\n')
print(json.dumps(record['cases'], indent=2))
