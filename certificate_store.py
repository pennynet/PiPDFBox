"""Securely store public CA certificates with restrictive file permissions."""

import os
from pathlib import Path


def secure_certificate_replace(source: Path, destination: Path) -> None:
    """Set owner-only file permissions and atomically replace a certificate."""
    os.chmod(source, 0o600)
    os.replace(source, destination)
