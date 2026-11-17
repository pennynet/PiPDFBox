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

Compression invokes qpdf and then Ghostscript's `pdfwrite` device to create
alternative candidates. Ghostscript's rendering-thread option does not imply that
the PDF-writing/compression path will scale across four cores. PiPDFBox therefore
does not force four Ghostscript threads. Instead, the optional `parallel_gs` mode
runs the colour and grayscale candidate conversions concurrently (two processes
for one user job).

On a Raspberry Pi 5 with 8 GB RAM, a 282-page PDF (230 MB input) was measured three
times in each mode. Median compression time was 773.764 s with parallel Ghostscript
and 794.071 s in serial mode (about 2.6% faster). Both modes produced the same
36,334,011-byte, 282-page output. A separate `/usr/bin/time -v` run measured
52,464 KB maximum resident set size for the parallel run and 52,928 KB for serial.
These are process-level measurements, not a complete aggregate of every child
process, so monitor system memory when testing other workloads.

For fresh installations, the Pi 5 configuration now defaults to
`'parallel_gs' => true`. Set it to `false` in `/etc/pipdfbox/config.php` to
return to serial candidate generation. Existing installations keep their current
setting; changing the installer default does not overwrite an existing config.

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
