#!/usr/bin/env python3
"""Repeatable PiPDFBox worker benchmark using local, non-confidential PDFs."""
from __future__ import annotations

import argparse
import json
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path

from pypdf import PdfReader


def output_pages(path: Path) -> int | None:
    if path.suffix.lower() == ".pdf":
        return len(PdfReader(str(path), strict=True).pages)
    if path.suffix.lower() == ".zip":
        with zipfile.ZipFile(path) as archive:
            return sum(
                len(PdfReader(archive.open(name), strict=True).pages)
                for name in archive.namelist()
                if name.lower().endswith(".pdf")
            )
    return None


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("merge", "split", "interleave", "compress", "number"))
    parser.add_argument("inputs", nargs="+", type=Path)
    parser.add_argument("--worker", type=Path, default=Path(__file__).resolve().parents[1] / "worker.py")
    parser.add_argument("--profile", choices=("maximal", "stark", "mittel"), default="stark")
    parser.add_argument("--repeat", type=int, default=3)
    parser.add_argument("--parallel-gs", action="store_true", help="Farb- und Graustufen-Ghostscript-Läufe parallel ausführen")
    args = parser.parse_args()

    if args.repeat < 1:
        parser.error("--repeat muss mindestens 1 sein.")
    if any(not path.is_file() for path in args.inputs):
        parser.error("Alle Eingabedateien müssen existieren.")
    if not args.worker.is_file():
        parser.error(f"Worker nicht gefunden: {args.worker}")

    op = args.operation
    expected = {"merge": (2, 10), "split": (1, 1), "interleave": (2, 2),
                "compress": (1, 1), "number": (1, 1)}
    low, high = expected[op]
    if not low <= len(args.inputs) <= high:
        parser.error(f"{op} benötigt {low}" + (f" bis {high}" if high != low else "") + " Eingabedatei(en).")

    results = []
    for run in range(1, args.repeat + 1):
        with tempfile.TemporaryDirectory(prefix="pipdfbox-bench-") as temp:
            workdir = Path(temp)
            copied = []
            for index, source in enumerate(args.inputs, 1):
                target = workdir / f"input_{index}.pdf"
                shutil.copy2(source, target)
                copied.append(target)

            command = [sys.executable, str(args.worker), op, str(workdir)]
            if op == "compress":
                command.append(f"--profile={args.profile}")
                if args.parallel_gs:
                    command.append("--parallel-gs")
            command.extend(str(path) for path in copied)

            started = time.perf_counter()
            completed = subprocess.run(command, capture_output=True, text=True)
            elapsed = time.perf_counter() - started
            if completed.returncode:
                print(completed.stderr.strip() or completed.stdout.strip(), file=sys.stderr)
                print(f"Durchlauf {run} fehlgeschlagen.", file=sys.stderr)
                return completed.returncode

            output_name = completed.stdout.strip().splitlines()[-1]
            output = workdir / output_name
            if not output.is_file():
                print(f"Worker-Ausgabe fehlt: {output_name}", file=sys.stderr)
                return 2

            row = {
                "run": run,
                "seconds": round(elapsed, 3),
                "output_bytes": output.stat().st_size,
                "output_pages": output_pages(output),
            }
            results.append(row)
            print(f"Durchlauf {run}: {row['seconds']:.3f} s, "
                  f"{row['output_bytes']} Byte, {row['output_pages']} Seiten")

    times = [row["seconds"] for row in results]
    summary = {
        "operation": op,
        "profile": args.profile if op == "compress" else None,
        "parallel_gs": args.parallel_gs if op == "compress" else False,
        "runs": results,
        "median_seconds": round(statistics.median(times), 3),
        "min_seconds": round(min(times), 3),
        "max_seconds": round(max(times), 3),
    }
    print("\nJSON-Zusammenfassung:")
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
