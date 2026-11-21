#!/usr/bin/env python3
"""Sign a PDF with the D-TRUST card through OpenSC and pyHanko.

The private key stays on the smart card. Signature-PIN entry is delegated to
the reader's protected authentication path; PIN/CAN values are never handled
by this program.
"""
from __future__ import annotations

import asyncio
import os
from dataclasses import replace
from datetime import datetime
from zoneinfo import ZoneInfo
import subprocess
import sys
import tempfile
from pathlib import Path
from urllib.parse import urlparse

# pyHanko and its pinned runtime dependencies are installed here by install.sh.
PACKAGE_DIR = Path("/opt/pipdfbox/python")
if PACKAGE_DIR.is_dir():
    sys.path.insert(0, str(PACKAGE_DIR))

from asn1crypto import x509 as asn1_x509
from cryptography import x509 as crypto_x509
from cryptography.hazmat.primitives.asymmetric import rsa
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.generic import DictionaryObject, NameObject, StreamObject
from pyhanko.pdf_utils.content import RawContent
from pyhanko.pdf_utils.layout import (
    AxisAlignment,
    BoxConstraints,
    Margins,
    SimpleBoxLayoutRule,
)
from pyhanko.pdf_utils.writer import PageObject
from pyhanko.sign import signers
from pyhanko.sign.fields import SigSeedSubFilter
from pyhanko.sign.timestamps import HTTPTimeStamper
from pyhanko_certvalidator import ValidationContext
from pyhanko.sign.signers.constants import DEFAULT_SIGNING_STAMP_STYLE
from pyhanko.stamp.appearances import CoordinateSystem


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
        super().__init__(signing_cert=certificate, cert_registry=None, embed_roots=True)
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



# Public D-TRUST CA certificates are persistent application data, not
# temporary signing material. The directory is owned by www-data.
CERTIFICATE_DIR = Path("/var/lib/pipdfbox/certificates")
SIGNATURE_CA_LABEL = "CA-Zertifikat fuer Signatur"
SIGNATURE_ROOT_LABEL = "Root-CA-Zertifikat fuer Signatur"
SIGNATURE_CA_FILE = CERTIFICATE_DIR / "dtrust-signature-ca.der"
SIGNATURE_ROOT_FILE = CERTIFICATE_DIR / "dtrust-signature-root.der"


def _read_card_certificate(label: str, destination: Path) -> bytes:
    """Export one public CA certificate by its unique PKCS#11 label."""
    command = [
        str(PKCS11_TOOL),
        "--module", str(PKCS11_MODULE),
        "--slot", str(SLOT_ID),
        "--read-object",
        "--type", "cert",
        "--label", label,
        "--output-file", str(destination),
    ]
    try:
        subprocess.run(
            command,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=120,
        )
        certificate_der = destination.read_bytes()
    except (OSError, subprocess.SubprocessError):
        raise OpenSCPdfSigningError(
            "D-TRUST-CA-Zertifikat konnte nicht von der Karte gelesen werden"
        ) from None
    if not certificate_der:
        raise OpenSCPdfSigningError("Ein D-TRUST-CA-Zertifikat ist leer")
    return certificate_der


def _parse_ca_certificate(certificate_der: bytes):
    """Parse a DER certificate and ensure it is a CA certificate."""
    try:
        certificate = crypto_x509.load_der_x509_certificate(certificate_der)
        constraints = certificate.extensions.get_extension_for_class(
            crypto_x509.BasicConstraints
        ).value
        usage = certificate.extensions.get_extension_for_class(
            crypto_x509.KeyUsage
        ).value
        if not constraints.ca or not usage.key_cert_sign:
            raise ValueError("certificate is not a signing CA")
        return certificate, asn1_x509.Certificate.load(certificate_der)
    except Exception as exc:
        raise OpenSCPdfSigningError(
            "Ein gespeichertes D-TRUST-CA-Zertifikat ist ungültig"
        ) from exc


def _ca_chain_matches(
    signing_certificate_der: bytes,
    signature_ca_der: bytes,
    root_ca_der: bytes,
) -> bool:
    """Check certificate issuer/subject links before trusting the cached pair."""
    try:
        signing_certificate = crypto_x509.load_der_x509_certificate(
            signing_certificate_der
        )
        signature_ca, _ = _parse_ca_certificate(signature_ca_der)
        root_ca, _ = _parse_ca_certificate(root_ca_der)
        return (
            signing_certificate.issuer == signature_ca.subject
            and signature_ca.issuer == root_ca.subject
            and root_ca.issuer == root_ca.subject
        )
    except Exception:
        return False


def load_dtrust_ca_chain(
    signing_certificate_der: bytes,
) -> tuple[asn1_x509.Certificate, asn1_x509.Certificate]:
    """Load cached D-TRUST CAs, exporting missing or inconsistent files once."""
    if not PKCS11_MODULE.is_file() or not PKCS11_TOOL.is_file():
        raise OpenSCPdfSigningError("OpenSC 0.27.1 ist nicht vollständig installiert")

    try:
        CERTIFICATE_DIR.mkdir(mode=0o700, parents=True, exist_ok=True)
        CERTIFICATE_DIR.chmod(0o700)
    except OSError:
        raise OpenSCPdfSigningError(
            "Zertifikatsspeicher kann nicht angelegt oder geöffnet werden"
        ) from None

    def cached(path: Path) -> bytes | None:
        try:
            data = path.read_bytes()
            _parse_ca_certificate(data)
            return data
        except (OSError, OpenSCPdfSigningError):
            return None

    signature_ca_der = cached(SIGNATURE_CA_FILE)
    root_ca_der = cached(SIGNATURE_ROOT_FILE)

    # If either file is absent or malformed, export the missing item(s).
    # A temporary file in the target directory allows atomic replacement.
    try:
        with tempfile.TemporaryDirectory(
            prefix="dtrust-ca-", dir=CERTIFICATE_DIR
        ) as tmp:
            tmp_dir = Path(tmp)
            if signature_ca_der is None:
                temp_file = tmp_dir / "signature-ca.der"
                signature_ca_der = _read_card_certificate(
                    SIGNATURE_CA_LABEL, temp_file
                )
                _parse_ca_certificate(signature_ca_der)
                os.replace(temp_file, SIGNATURE_CA_FILE)
            if root_ca_der is None:
                temp_file = tmp_dir / "signature-root.der"
                root_ca_der = _read_card_certificate(
                    SIGNATURE_ROOT_LABEL, temp_file
                )
                _parse_ca_certificate(root_ca_der)
                os.replace(temp_file, SIGNATURE_ROOT_FILE)
    except OSError:
        raise OpenSCPdfSigningError(
            "D-TRUST-CA-Zertifikate konnten nicht dauerhaft gespeichert werden"
        ) from None

    if not _ca_chain_matches(
        signing_certificate_der, signature_ca_der, root_ca_der
    ):
        # The card objects are authoritative for retrieval, but never accept
        # a cached pair whose issuer chain does not match the signing cert.
        try:
            with tempfile.TemporaryDirectory(
                prefix="dtrust-ca-", dir=CERTIFICATE_DIR
            ) as tmp:
                tmp_dir = Path(tmp)
                ca_tmp = tmp_dir / "signature-ca.der"
                root_tmp = tmp_dir / "signature-root.der"
                signature_ca_der = _read_card_certificate(
                    SIGNATURE_CA_LABEL, ca_tmp
                )
                root_ca_der = _read_card_certificate(
                    SIGNATURE_ROOT_LABEL, root_tmp
                )
                _parse_ca_certificate(signature_ca_der)
                _parse_ca_certificate(root_ca_der)
                if not _ca_chain_matches(
                    signing_certificate_der, signature_ca_der, root_ca_der
                ):
                    raise OpenSCPdfSigningError(
                        "Die D-TRUST-Zertifikatskette passt nicht zum Signaturzertifikat"
                    )
                os.replace(ca_tmp, SIGNATURE_CA_FILE)
                os.replace(root_tmp, SIGNATURE_ROOT_FILE)
        except OSError:
            raise OpenSCPdfSigningError(
                "D-TRUST-CA-Zertifikate konnten nicht dauerhaft gespeichert werden"
            ) from None

    try:
        _, signature_ca = _parse_ca_certificate(signature_ca_der)
        _, root_ca = _parse_ca_certificate(root_ca_der)
    except OpenSCPdfSigningError:
        raise
    return signature_ca, root_ca

def _inherited_page_value(page, key: str):
    """Read a page attribute, following the page tree for inherited values."""
    current = page
    while current is not None:
        value = current.get(key)
        if value is not None:
            return value.get_object() if hasattr(value, "get_object") else value

        parent = current.get("/Parent")
        if parent is None:
            return None
        current = parent.get_object() if hasattr(parent, "get_object") else parent

    return None


def _append_signature_page(
    writer: IncrementalPdfFileWriter,
) -> tuple[int, tuple[float, float, float, float]]:
    """Append a blank page matching the last page and return a top-positioned stamp box."""
    last_page_ref, _ = writer.prev.find_page_for_modification(-1)
    last_page = last_page_ref.get_object()

    media_box = _inherited_page_value(last_page, "/MediaBox")
    if media_box is None or len(media_box) != 4:
        raise OpenSCPdfSigningError(
            "Seitengröße der letzten PDF-Seite konnte nicht ermittelt werden"
        )

    media_llx, media_lly, media_urx, media_ury = (
        float(value) for value in media_box
    )
    if media_urx <= media_llx or media_ury <= media_lly:
        raise OpenSCPdfSigningError("Ungültige Seitengröße der letzten PDF-Seite")

    crop_box = _inherited_page_value(last_page, "/CropBox")
    if crop_box is None:
        crop_box = media_box
    crop_llx, crop_lly, crop_urx, crop_ury = (
        float(value) for value in crop_box
    )
    visible_width = crop_urx - crop_llx
    visible_height = crop_ury - crop_lly
    if visible_width <= 0 or visible_height <= 0:
        raise OpenSCPdfSigningError(
            "Ungültiger sichtbarer Seitenbereich der letzten PDF-Seite"
        )

    rotation = _inherited_page_value(last_page, "/Rotate")
    rotation_degrees = int(rotation or 0) % 360
    if rotation_degrees not in (0, 90, 180, 270):
        raise OpenSCPdfSigningError(
            "Nicht unterstützte Seitendrehung der letzten PDF-Seite"
        )

    pages_root = writer.prev.root["/Pages"]
    if hasattr(pages_root, "get_object"):
        pages_root = pages_root.get_object()
    page_count = int(pages_root["/Count"])
    if page_count < 1:
        raise OpenSCPdfSigningError("Das PDF enthält keine Seiten")

    new_page = PageObject(
        contents=writer.add_object(StreamObject(stream_data=b"")),
        media_box=writer.import_object(media_box),
        resources=DictionaryObject(),
    )
    if crop_box is not media_box:
        new_page[NameObject("/CropBox")] = writer.import_object(crop_box)
    if rotation is not None:
        new_page[NameObject("/Rotate")] = writer.import_object(rotation)

    writer.insert_page(new_page, after=page_count - 1)

    margin_x = min(48.0, visible_width * 0.08)
    margin_y = min(48.0, visible_height * 0.08)
    stamp_width = min(499.0, visible_width - 2 * margin_x)
    stamp_height = min(67.0, visible_height - 2 * margin_y)
    if stamp_width <= 0 or stamp_height <= 0:
        raise OpenSCPdfSigningError(
            "Die letzte PDF-Seite ist zu klein für den Signaturstempel"
        )

    if rotation_degrees == 0:
        box = (
            crop_llx + margin_x,
            crop_ury - margin_y - stamp_height,
            crop_llx + margin_x + stamp_width,
            crop_ury - margin_y,
        )
    elif rotation_degrees == 90:
        box = (
            crop_llx + margin_x,
            crop_lly + margin_y,
            crop_llx + margin_x + stamp_height,
            crop_lly + margin_y + stamp_width,
        )
    elif rotation_degrees == 180:
        box = (
            crop_urx - margin_x - stamp_width,
            crop_lly + margin_y,
            crop_urx - margin_x,
            crop_lly + margin_y + stamp_height,
        )
    else:
        box = (
            crop_urx - margin_x - stamp_height,
            crop_ury - margin_y - stamp_width,
            crop_urx - margin_x,
            crop_ury - margin_y,
        )

    return page_count, box


def _apply_signature_stamp(
    writer: IncrementalPdfFileWriter,
    page_index: int,
    box: tuple[float, float, float, float],
    signer_name: str | None,
) -> None:
    """Draw an award-style seal and the two signature text lines into page content."""
    llx, lly, urx, ury = box
    signing_time = datetime.now(ZoneInfo("Europe/Berlin"))
    timezone_name = "MESZ" if signing_time.dst() else "MEZ"
    timestamp = f"{signing_time:%d.%m.%Y} um {signing_time:%H:%M} {timezone_name}"

    stamp_height = ury - lly
    badge_size = min(44.0, stamp_height - 12.0)
    if badge_size <= 0:
        raise OpenSCPdfSigningError("Die Stempelfläche ist zu klein")

    badge_x = llx + 10.0
    badge_y = lly + (stamp_height - badge_size) / 2.0
    text_x = badge_x + badge_size + 10.0
    text_width = urx - text_x - 10.0
    if text_width <= 0:
        raise OpenSCPdfSigningError("Die Stempelfläche ist zu schmal")

    # Vektor-Siegel: goldene Medaille mit Stern und zwei Bändern.
    scale = badge_size / 44.0
    badge_art = f"""
q
{scale:g} 0 0 {scale:g} {badge_x:g} {badge_y:g} cm
0.68 0.46 0.13 RG
1.4 w
0.97 0.88 0.66 rg
15 19 m 10 2 l 19 7 l 21 19 l h B
29 19 m 34 2 l 25 7 l 23 19 l h B
0.99 0.95 0.83 rg
22 39 m 28.075 39 33 34.075 33 28 c
33 21.925 28.075 17 22 17 c
15.925 17 11 21.925 11 28 c
11 34.075 15.925 39 22 39 c h B
22 36 m 26.418 36 30 32.418 30 28 c
30 23.582 26.418 20 22 20 c
17.582 20 14 23.582 14 28 c
14 32.418 17.582 36 22 36 c h S
22 35 m 24 30.7 l 28.8 30.2 l 25.2 26.8 l
26.2 22 l 22 24.4 l 17.8 22 l
18.8 26.8 l 15.2 30.2 l 20 30.7 l h
0.68 0.46 0.13 rg f
Q
"""
    RawContent(data=badge_art.encode("ascii")).add_to_page(writer, page_index)

    left_layout = SimpleBoxLayoutRule(
        x_align=AxisAlignment.ALIGN_MIN,
        y_align=AxisAlignment.ALIGN_MID,
        margins=Margins.uniform(0),
    )
    centered_layout = SimpleBoxLayoutRule(
        x_align=AxisAlignment.ALIGN_MID,
        y_align=AxisAlignment.ALIGN_MID,
        margins=Margins.uniform(0),
    )

    stamp_style = replace(
        DEFAULT_SIGNING_STAMP_STYLE,
        text_box_style=replace(
            DEFAULT_SIGNING_STAMP_STYLE.text_box_style,
            font_size=10,
        ),
        background=None,
        border_width=0,
        stamp_text=(
            "Dokument qualifiziert elektronisch signiert von %(signer)s "
            "am %(ts)s"
        ),
        inner_content_layout=left_layout,
    )
    stamp = stamp_style.create_stamp(
        writer,
        BoxConstraints(width=text_width, height=20),
        {
            "signer": signer_name or "D-TRUST",
            "ts": timestamp,
        },
    )
    stamp.apply(
        dest_page=page_index,
        x=text_x,
        y=lly + stamp_height * 0.5,
        coords=CoordinateSystem.PAGE_DEFAULT,
    )


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

    def sign_pdf(
        self, input_pdf: Path, output_pdf: Path, visible_stamp: bool = False,
        use_timestamp: bool = False, use_b_lta: bool = False,
        tsa_url: str = "http://tsa2.baltstamp.lt",
    ) -> Path:
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

        if use_b_lta:
            signature_ca, root_ca = load_dtrust_ca_chain(
                self._certificate.dump()
            )
            validation_context = ValidationContext(
                trust_roots=[root_ca],
                other_certs=[signature_ca],
                allow_fetching=True,
            )
        else:
            validation_context = None
        metadata = signers.PdfSignatureMetadata(
            field_name="Signature1",
            md_algorithm="sha256",
            reason="Signiert mit D-TRUST",
            subfilter=SigSeedSubFilter.PADES,
            validation_context=validation_context,
            embed_validation_info=use_b_lta,
            use_pades_lta=use_b_lta,
        )
        external_signer = _OpenSCExternalSigner(
            self._certificate,
            self._signature_size,
        )
        created_output = False
        try:
            with input_pdf.open("rb") as source:
                writer = IncrementalPdfFileWriter(source, strict=False)
                if visible_stamp:
                    stamp_page, stamp_box = _append_signature_page(writer)
                    _apply_signature_stamp(
                        writer,
                        stamp_page,
                        stamp_box,
                        external_signer.subject_name,
                    )

                # Das Signaturfeld bleibt unsichtbar. Der optionale Stempel wurde
                # zuvor als normaler Seiteninhalt eingebettet und wird mit signiert.
                # B-LTA bettet Validierungsdaten in das DSS ein und hängt einen
                # zusätzlichen Dokumentzeitstempel an. Normales PAdES bleibt ohne
                # DSS-Erweiterung und ohne Zeitstempel.
                timestamper = HTTPTimeStamper(tsa_url) if (use_timestamp or use_b_lta) else None
                pdf_signer = signers.PdfSigner(
                    metadata,
                    external_signer,
                    timestamper=timestamper,
                )
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


    def sign_cades(
        self, input_pdf: Path, output_signature: Path,
        use_timestamp: bool = True,
        tsa_url: str = "http://tsa2.baltstamp.lt",
    ) -> Path:
        """Create a detached CAdES CMS signature for the exact PDF byte stream."""
        if not input_pdf.is_file():
            raise FileNotFoundError("Eingabe-PDF wurde nicht gefunden")
        if input_pdf.resolve() == output_signature.resolve():
            raise ValueError("Eingabe und Signaturdatei müssen verschieden sein")
        if input_pdf.resolve().parent != output_signature.resolve().parent:
            raise ValueError("Eingabe und Signaturdatei müssen im selben Auftragsordner liegen")
        if output_signature.name != "signiert.pdf.p7s":
            raise ValueError("Ungültiger Name der CAdES-Ausgabedatei")
        if output_signature.exists():
            raise FileExistsError("Die CAdES-Signaturdatei existiert bereits")

        external_signer = _OpenSCExternalSigner(
            self._certificate,
            self._signature_size,
        )
        timestamper = HTTPTimeStamper(tsa_url) if use_timestamp else None
        created_output = False
        try:
            with input_pdf.open("rb") as source:
                cms_signature = asyncio.run(
                    external_signer.async_sign_general_data(
                        input_data=source,
                        digest_algorithm="sha256",
                        detached=True,
                        use_cades=True,
                        timestamper=timestamper,
                    )
                )
            with output_signature.open("xb") as destination:
                created_output = True
                destination.write(cms_signature.dump())
        except Exception as exc:
            if created_output:
                try:
                    output_signature.unlink(missing_ok=True)
                except OSError:
                    pass
            if isinstance(exc, OpenSCPdfSigningError):
                raise
            raise OpenSCPdfSigningError("CAdES-Signierung fehlgeschlagen") from exc
        return output_signature


def main() -> int:
    if len(sys.argv) < 3:
        print(
            "Aufruf: opensc.py EINGABE.pdf AUSGABE [--profile=pades|cades] [--visible-stamp] [--b-lta] [--timestamp] [--tsa-url=URL]",
            file=sys.stderr,
        )
        return 2

    input_pdf = Path(sys.argv[1]).resolve()
    output_pdf = Path(sys.argv[2]).resolve()
    options = sys.argv[3:]
    visible_stamp = "--visible-stamp" in options
    use_timestamp = "--timestamp" in options
    use_b_lta = "--b-lta" in options
    profile_options = [arg[len("--profile="):] for arg in options if arg.startswith("--profile=")]
    profile = profile_options[0] if profile_options else "pades"
    tsa_options = [arg[len("--tsa-url="):] for arg in options if arg.startswith("--tsa-url=")]
    unknown_options = [
        arg for arg in options
        if arg not in ("--visible-stamp", "--timestamp", "--b-lta") and not arg.startswith("--tsa-url=") and not arg.startswith("--profile=")
    ]
    if unknown_options or len(tsa_options) > 1 or len(profile_options) > 1 or profile not in ("pades", "cades"):
        print("Ungültige Signieroption.", file=sys.stderr)
        return 2
    tsa_url = tsa_options[0] if tsa_options else "http://tsa2.baltstamp.lt"
    parsed_tsa_url = urlparse(tsa_url)
    if (
        parsed_tsa_url.scheme not in ("http", "https")
        or not parsed_tsa_url.hostname
        or parsed_tsa_url.username is not None
        or parsed_tsa_url.password is not None
        or parsed_tsa_url.fragment
    ):
        print("Ungültige Zeitserver-URL.", file=sys.stderr)
        return 2
    if profile == "cades" and visible_stamp:
        print("Ein sichtbarer Stempel ist bei einer abgetrennten CAdES-Signatur nicht möglich.", file=sys.stderr)
        return 2
    if profile == "cades" and use_b_lta:
        print("B-LTA ist nur für PAdES verfügbar.", file=sys.stderr)
        return 2
    if not (use_timestamp or use_b_lta) and tsa_options:
        print("Eine Zeitserver-URL kann nur mit --timestamp oder --b-lta angegeben werden.", file=sys.stderr)
        return 2

    try:
        if not input_pdf.is_file():
            raise FileNotFoundError("Eingabe-PDF wurde nicht gefunden")
        if output_pdf.exists():
            raise FileExistsError("Die signierte Ausgabedatei existiert bereits")
        certificate_der = read_signing_certificate()
        signer = OpenSCPdfSigner(certificate_der)
        if profile == "cades":
            signed_pdf = signer.sign_cades(
                input_pdf, output_pdf, use_timestamp=use_timestamp, tsa_url=tsa_url
            )
        else:
            signed_pdf = signer.sign_pdf(
                input_pdf, output_pdf, visible_stamp=visible_stamp,
                use_timestamp=use_timestamp, use_b_lta=use_b_lta, tsa_url=tsa_url
            )
    except (OpenSCPdfSigningError, FileNotFoundError, FileExistsError, ValueError) as exc:
        print(str(exc)[:300] or "PDF-Signierung fehlgeschlagen", file=sys.stderr)
        return 1
    except Exception:
        print("PDF-Signierung fehlgeschlagen", file=sys.stderr)
        return 1

    print(signed_pdf.name)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
