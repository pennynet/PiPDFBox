#!/usr/bin/env python3
"""Sign a PDF with the D-TRUST card through OpenSC and pyHanko.

The private key stays on the smart card. Signature-PIN entry is delegated to
the reader's protected authentication path; PIN/CAN values are never handled
by this program.
"""
from __future__ import annotations

import asyncio
import subprocess
import sys
import tempfile
from pathlib import Path

# pyHanko and its pinned runtime dependencies are installed here by install.sh.
PACKAGE_DIR = Path("/opt/pipdfbox/python")
if PACKAGE_DIR.is_dir():
    sys.path.insert(0, str(PACKAGE_DIR))

from asn1crypto import x509 as asn1_x509
from cryptography import x509 as crypto_x509
from cryptography.hazmat.primitives.asymmetric import rsa
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import fields, signers


OPENSC_PREFIX = Path("/opt/opensc-0.27.1")
PKCS11_MODULE = OPENSC_PREFIX / "lib/opensc-pkcs11.so"
PKCS11_TOOL = OPENSC_PREFIX / "bin/pkcs11-tool"
SLOT_ID = 1
SIGNING_KEY_ID = bytes.fromhex("02")


class OpenSCPdfSigningError(RuntimeError):
    """Raised when OpenSC-backed PDF signing fails."""


class _OpenSCExternalSigner(signers.ExternalSigner):
    def __init__(
        self,
        certificate: asn1_x509.Certificate,
        signature_size: int,
    ) -> None:
        super().__init__(signing_cert=certificate, cert_registry=None)
        self._signature_size = signature_size

    async def async_sign_raw(
        self,
        data: bytes,
        digest_algorithm: str,
        dry_run: bool = False,
    ) -> bytes:
        if digest_algorithm.lower() != "sha256":
            raise OpenSCPdfSigningError("Nur SHA-256 wird unterstützt")

        if dry_run:
            return b"\x00" * self._signature_size

        with tempfile.TemporaryDirectory(prefix="pipdfbox-opensc-") as tmp:
            input_file = Path(tmp) / "signed-attributes.der"
            output_file = Path(tmp) / "signature.bin"
            input_file.write_bytes(data)

            command = [
                str(PKCS11_TOOL),
                "--module", str(PKCS11_MODULE),
                "--slot", str(SLOT_ID),
                "--login",
                "--sign",
                "--mechanism", "SHA256-RSA-PKCS",
                "--id", SIGNING_KEY_ID.hex(),
                "--input-file", str(input_file),
                "--output-file", str(output_file),
            ]

            try:
                process = await asyncio.create_subprocess_exec(
                    *command,
                    stdout=asyncio.subprocess.DEVNULL,
                    stderr=asyncio.subprocess.DEVNULL,
                )
                try:
                    await asyncio.wait_for(process.wait(), timeout=120)
                except TimeoutError:
                    process.kill()
                    await process.wait()
                    raise OpenSCPdfSigningError(
                        "Zeitüberschreitung bei der Karten-Signatur"
                    ) from None
            except OSError:
                raise OpenSCPdfSigningError(
                    "OpenSC konnte nicht gestartet werden"
                ) from None

            if process.returncode != 0:
                raise OpenSCPdfSigningError(
                    f"Signaturvorgang fehlgeschlagen (Code {process.returncode})"
                )

            try:
                signature = output_file.read_bytes()
            except OSError:
                raise OpenSCPdfSigningError(
                    "Die Karte hat keine Signatur geliefert"
                ) from None

            if len(signature) != self._signature_size:
                raise OpenSCPdfSigningError(
                    "Die Signatur hat eine unerwartete Länge"
                )

            return signature


def read_signing_certificate() -> bytes:
    """Read the public signing certificate without logging token metadata."""
    if not PKCS11_MODULE.is_file() or not PKCS11_TOOL.is_file():
        raise OpenSCPdfSigningError("OpenSC 0.27.1 ist nicht vollständig installiert")

    with tempfile.TemporaryDirectory(prefix="pipdfbox-cert-") as tmp:
        certificate_file = Path(tmp) / "signing-certificate.der"
        command = [
            str(PKCS11_TOOL),
            "--module", str(PKCS11_MODULE),
            "--slot", str(SLOT_ID),
            "--read-object",
            "--type", "cert",
            "--id", SIGNING_KEY_ID.hex(),
            "--output-file", str(certificate_file),
        ]
        try:
            subprocess.run(
                command,
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=30,
            )
            certificate_der = certificate_file.read_bytes()
        except (OSError, subprocess.SubprocessError):
            raise OpenSCPdfSigningError(
                "D-TRUST-Signaturzertifikat konnte nicht von der Karte gelesen werden"
            ) from None

    if not certificate_der:
        raise OpenSCPdfSigningError("Das Signaturzertifikat ist leer")
    return certificate_der


class OpenSCPdfSigner:
    """Create a PDF signature using the D-TRUST certificate and OpenSC."""

    def __init__(self, certificate_der: bytes) -> None:
        try:
            crypto_certificate = crypto_x509.load_der_x509_certificate(
                certificate_der
            )
            public_key = crypto_certificate.public_key()
            if not isinstance(public_key, rsa.RSAPublicKey):
                raise TypeError("certificate does not contain an RSA key")

            self._certificate = asn1_x509.Certificate.load(certificate_der)
            self._signature_size = (public_key.key_size + 7) // 8
        except Exception as exc:
            raise OpenSCPdfSigningError(
                "Das D-TRUST-Zertifikat enthält keinen gültigen RSA-Schlüssel"
            ) from exc

    def sign_pdf(self, input_pdf: Path, output_pdf: Path) -> Path:
        if not input_pdf.is_file():
            raise FileNotFoundError("Eingabe-PDF wurde nicht gefunden")
        if input_pdf.resolve() == output_pdf.resolve():
            raise ValueError("Ein- und Ausgabedatei müssen verschieden sein")
        if input_pdf.resolve().parent != output_pdf.resolve().parent:
            raise ValueError("Ein- und Ausgabedatei müssen im selben Auftragsordner liegen")
        if output_pdf.name != "signiert.pdf":
            raise ValueError("Ungültiger Name der Ausgabedatei")
        if output_pdf.exists():
            raise FileExistsError("Die signierte Ausgabedatei existiert bereits")

        metadata = signers.PdfSignatureMetadata(
            field_name="Signature1",
            md_algorithm="sha256",
            reason="Signiert mit D-TRUST",
        )
        external_signer = _OpenSCExternalSigner(
            self._certificate,
            self._signature_size,
        )
        pdf_signer = signers.PdfSigner(
            metadata,
            external_signer,
            new_field_spec=fields.SigFieldSpec(
                sig_field_name="Signature1",
                on_page=0,
                box=(48, 48, 547, 115),
            ),
        )

        created_output = False
        try:
            with input_pdf.open("rb") as source:
                writer = IncrementalPdfFileWriter(source)
                with output_pdf.open("xb") as destination:
                    created_output = True
                    pdf_signer.sign_pdf(writer, output=destination)
        except Exception as exc:
            if created_output:
                try:
                    output_pdf.unlink(missing_ok=True)
                except OSError:
                    pass
            if isinstance(exc, OpenSCPdfSigningError):
                raise
            raise OpenSCPdfSigningError("PDF-Signierung fehlgeschlagen") from exc

        return output_pdf


def main() -> int:
    if len(sys.argv) != 3:
        print("Aufruf: opensc.py EINGABE.pdf AUSGABE.pdf", file=sys.stderr)
        return 2

    input_pdf = Path(sys.argv[1]).resolve()
    output_pdf = Path(sys.argv[2]).resolve()

    try:
        certificate_der = read_signing_certificate()
        signer = OpenSCPdfSigner(certificate_der)
        signed_pdf = signer.sign_pdf(input_pdf, output_pdf)
    except Exception as exc:
        print(str(exc)[:300] or "PDF-Signierung fehlgeschlagen", file=sys.stderr)
        return 1

    print(signed_pdf.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
