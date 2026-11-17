#!/usr/bin/env python3
"""Remove PiPDFBox job directories older than 24 hours."""
import fcntl
import re
import shutil
import time
from pathlib import Path

DATA_DIR = Path("/var/lib/pipdfbox")
JOBS_DIR = DATA_DIR / "jobs"
LOCK_FILE = DATA_DIR / "worker.lock"
MAX_AGE_SECONDS = 24 * 60 * 60


def main():
    if not JOBS_DIR.is_dir():
        return
    with LOCK_FILE.open("a") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            # A PDF worker is running; retry on the next timer tick.
            return

        cutoff = time.time() - MAX_AGE_SECONDS
        for job_dir in JOBS_DIR.iterdir():
            if not re.fullmatch(r"[a-f0-9]{32}", job_dir.name):
                continue
            if job_dir.is_symlink() or not job_dir.is_dir():
                continue
            try:
                if job_dir.stat(follow_symlinks=False).st_mtime < cutoff:
                    shutil.rmtree(job_dir)
            except FileNotFoundError:
                pass


if __name__ == "__main__":
    main()
