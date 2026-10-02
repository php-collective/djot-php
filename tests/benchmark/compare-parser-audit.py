#!/usr/bin/env python3
"""Compare parser workloads in alternating fresh PHP processes."""
import argparse
import datetime
import json
import os
import pathlib
import statistics
import subprocess


def positive(value):
    number = int(value)
    if number < 1:
        raise argparse.ArgumentTypeError('expected a positive integer')
    return number


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--baseline', type=pathlib.Path, required=True)
parser.add_argument('--candidate', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[2])
parser.add_argument('--engine', choices=['djot', 'carve'], required=True)
parser.add_argument('--output', type=pathlib.Path, required=True)
parser.add_argument('--jit', choices=['off', 'tracing', 'both'], default='both')
parser.add_argument('--rounds', type=positive, default=4)
parser.add_argument('--cpu', type=int, default=min(os.sched_getaffinity(0)))
args = parser.parse_args()
if args.rounds < 2:
    parser.error('at least two rounds are required to alternate process order')
for root in [args.baseline, args.candidate]:
    if not (root / 'src').is_dir():
        parser.error(f'missing source directory: {root / "src"}')
if args.cpu not in os.sched_getaffinity(0):
    parser.error('CPU is outside the available affinity mask')

cases = {
    'djot': [('code_runs', 1000, 1), ('code_runs', 4000, 1), ('emphasis', 1000, 5),
             ('braced', 1000, 5), ('warnings', 100, 3), ('warnings', 1000, 2),
             ('warnings', 5000, 1), ('table_raw', 1000, 25), ('table_code', 1000, 25),
             ('unicode', 100, 2), ('images', 100, 2), ('lists', 100, 2),
             ('default_core', 100, 3), ('plain', 100, 5), ('core', 100, 2),
             ('parse', 100, 2), ('render', 100, 5)],
    'carve': [('marker_prose', 10000, 5), ('stream_ascii', 100000, 5),
              ('stream_escaped', 100000, 5), ('unicode', 100, 2), ('images', 100, 2),
              ('lists', 100, 2), ('default_core', 100, 3), ('plain', 100, 5),
              ('core', 100, 2)],
}
flags = ['php', '-n', '-d', 'extension=ctype', '-d', 'extension=mbstring',
         '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit_buffer_size=128M']
probe = pathlib.Path(__file__).with_name('parser-audit.php')
record = {'started_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
          'engine': args.engine, 'cpu': args.cpu, 'php_flags': flags,
          'load_start': os.getloadavg(), 'runs': [], 'summaries': []}
source_hashes = {}
for jit in (['off', 'tracing'] if args.jit == 'both' else [args.jit]):
    for case, size, iterations in cases[args.engine]:
        group = []
        for round_number in range(args.rounds):
            order = ['baseline', 'candidate'] if round_number % 2 == 0 else ['candidate', 'baseline']
            for label in order:
                root = (args.baseline if label == 'baseline' else args.candidate).resolve()
                command = ['taskset', '-c', str(args.cpu), *flags, '-d', f'opcache.jit={jit}',
                           str(probe), args.engine, str(root), case, str(size), str(iterations), '5']
                output = subprocess.run(command, check=True, text=True, capture_output=True, timeout=180)
                row = json.loads(output.stdout)
                if jit == 'tracing' and not (row['jit']['enabled'] and row['jit']['on']):
                    raise RuntimeError('tracing JIT did not start')
                if source_hashes.setdefault(label, row['source_tree_sha256']) != row['source_tree_sha256']:
                    raise RuntimeError(f'{label} source changed during the benchmark')
                row.update(label=label, round=round_number, mode=jit, load=os.getloadavg())
                group.append(row)
                record['runs'].append(row)
                args.output.write_text(json.dumps(record, indent=2) + '\n')
        if len({r['output_sha256'] for r in group}) != 1 or len({r['input_sha256'] for r in group}) != 1:
            raise RuntimeError(f'output or input mismatch for {case}/{size}')
        summary = {'case': case, 'size': size, 'mode': jit}
        for label in ['baseline', 'candidate']:
            selected = [r for r in group if r['label'] == label]
            summary[label] = {metric: statistics.median([sample for r in selected for sample in r[metric]])
                              for metric in ['cpu_ms', 'wall_ms']}
            summary[label]['peak_used_bytes'] = max(r['peak_used_bytes'] for r in selected)
        summary['cpu_speedup'] = summary['baseline']['cpu_ms'] / summary['candidate']['cpu_ms']
        record['summaries'].append(summary)
        args.output.write_text(json.dumps(record, indent=2) + '\n')
        print(f"{jit} {case}/{size}: {summary['cpu_speedup']:.3f}x CPU throughput", flush=True)
record['completed_at'] = datetime.datetime.now(datetime.timezone.utc).isoformat()
record['load_end'] = os.getloadavg()
args.output.write_text(json.dumps(record, indent=2) + '\n')
