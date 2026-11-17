# Pi 5 performance plan

Target hardware: Raspberry Pi 5, 8 GB RAM.

## Execution policy

PiPDFBox runs at most one PDF worker at a time. The PHP front end takes an exclusive
`flock` lock on `/var/lib/pipdfbox/worker.lock` immediately before starting
the worker. Concurrent requests wait for the lock; the lock is released even if the
worker call exits through PHP's `finally` block. This serializes worker execution
across PHP-FPM processes without changing the installer or system services.

The upload and validation stages can still overlap. Only the expensive PDF worker
is serialized. Requests waiting behind a long job are still subject to the existing
PHP-FPM and PHP execution timeouts.

## Why we do not blindly set four Ghostscript threads

PiPDFBox uses pypdf for merge, split, interleave and page numbering. Those code paths
are not automatically parallel just because the Pi has four CPU cores.

Compression currently invokes qpdf and then Ghostscript's `pdfwrite` device to
create alternative candidates. Ghostscript's rendering-thread option does not imply
that the PDF-writing/compression path will scale across four cores. A thread flag
should only be added after measurements demonstrate a benefit for this exact device
and workload. The next optimization candidate is to benchmark running the colour
and grayscale Ghostscript candidate conversions concurrently; that uses two
processes for one user job, not two simultaneous user jobs, and must be checked for
peak memory use first.

## Benchmark procedure on the Pi 5

Use copies of representative, non-confidential PDFs. Keep the same input files,
profiles, software versions and power/cooling conditions for each comparison.

Record versions:

```sh
uname -a
python3 --version
python3 -c 'import pypdf; print("pypdf", pypdf.__version__)'
gs --version
qpdf --version
nproc
free -h
```

For a repeatable elapsed-time and output-size comparison, use the included
benchmark harness (it makes temporary copies and never modifies the source PDFs):

```sh
python3 -m pip install pypdf
python3 tests/benchmark_worker.py compress /path/to/representative.pdf --profile stark --repeat 3
python3 tests/benchmark_worker.py merge /path/to/first.pdf /path/to/second.pdf --repeat 3
python3 tests/benchmark_worker.py number /path/to/representative.pdf --repeat 3
```

The harness reports each run and median/minimum/maximum time. To measure peak memory
on Linux, also wrap one individual worker invocation in `/usr/bin/time -v` as shown
below:

```sh
TESTDIR=$(mktemp -d)
cp /path/to/representative.pdf "$TESTDIR/input.pdf"
/usr/bin/time -v python3 /opt/pipdfbox/worker.py \
  compress "$TESTDIR" --profile=stark "$TESTDIR/input.pdf"
```

For merge, place at least two PDFs in the test directory and pass both paths after
the output directory. For split and numbering, pass one input PDF. For interleave,
pass two PDFs. Use fresh output directories between runs.

During a run, optionally observe system load in another terminal:

```sh
watch -n 1 'uptime; free -h; vcgencmd measure_temp'
```

Record, per operation: input file sizes, page counts, elapsed seconds, peak resident
memory from `time -v`, output size, and whether the result is visually correct.
Do not compare runs made while thermal throttling or other heavy workloads differ.

## Acceptance criteria

- Never more than one active PiPDFBox worker process.
- Existing operation results remain valid and visually equivalent.
- Performance changes are retained only when repeated measurements show a useful
  speedup without unsafe peak memory use or a material quality regression.
- The production `main` branch and an installed PiPDFBox instance are not modified
  by this development branch.
