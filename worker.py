#!/usr/bin/env python3
"""PiPDFBox PDF worker. Arguments are passed as an argv list, never through a shell."""
import os, sys, zipfile, subprocess, shutil
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from pypdf import PdfReader, PdfWriter
from pypdf.generic import DictionaryObject, NameObject, DecodedStreamObject


def make_number_overlay(width, height, label):
    writer = PdfWriter()
    page = writer.add_blank_page(width=width, height=height)

    font = DictionaryObject({
        NameObject("/Type"): NameObject("/Font"),
        NameObject("/Subtype"): NameObject("/Type1"),
        NameObject("/BaseFont"): NameObject("/Helvetica"),
        NameObject("/Encoding"): NameObject("/WinAnsiEncoding"),
    })

    page[NameObject("/Resources")] = DictionaryObject({
        NameObject("/Font"): DictionaryObject({
            NameObject("/F1"): font
        })
    })

    size = 10
    encoded = label.encode("cp1252")
    backslash = bytes((92,))
    escaped = (encoded.replace(backslash, backslash * 2)
                      .replace(b"(", backslash + b"(")
                      .replace(b")", backslash + b")"))

    text_width = len(encoded) * size * 0.52
    x = max(5, (float(width) - text_width) / 2)
    y = float(height) - 20

    lines = [
        b"q",
        b"1 1 1 rg",
        f"0 {float(height)-28} {float(width)} 28 re f".encode("ascii"),
        b"Q",
        b"BT",
        f"/F1 {size} Tf".encode("ascii"),
        b"0 0 0 rg",
        f"1 0 0 1 {x:.2f} {y:.2f} Tm".encode("ascii"),
        b"(" + escaped + b") Tj",
        b"ET",
    ]
    content = bytes((10,)).join(lines) + bytes((10,))

    stream = DecodedStreamObject()
    stream.set_data(content)
    page[NameObject("/Contents")] = writer._add_object(stream)

    from io import BytesIO
    result = BytesIO()
    writer.write(result)
    return PdfReader(result).pages[0]



def main():
    if len(sys.argv) < 4: raise ValueError('Ungültige Worker-Parameter')
    op, outdir, *raw_inputs = sys.argv[1:]
    reverse_second = '--reverse-second' in raw_inputs
    number_interleave = '--number-interleave' in raw_inputs
    reverse_inputs = '--reverse-inputs' in raw_inputs
    parallel_gs = '--parallel-gs' in raw_inputs
    profile_args = [p for p in raw_inputs if p.startswith('--profile=')]
    profile = profile_args[0].split('=', 1)[1] if profile_args else 'stark'
    output_type_args = [p for p in raw_inputs if p.startswith('--output-type=')]
    output_type = output_type_args[0].split('=', 1)[1] if output_type_args else 'pdfa-2'
    language_args = [p for p in raw_inputs if p.startswith('--language=')]
    language = language_args[0].split('=', 1)[1] if language_args else 'deu'
    inputs = [
        p for p in raw_inputs
        if p != '--reverse-second' and p != '--number-interleave' and p != '--reverse-inputs' and p != '--parallel-gs' and not p.startswith('--profile=') and not p.startswith('--prefix=') and not p.startswith('--output-type=') and not p.startswith('--language=')
    ]
    outdir = Path(outdir).resolve()
    for p in inputs:
        q=Path(p).resolve()
        if not q.is_file() or q.parent != outdir: raise ValueError('Ungültiger Eingabepfad')
    if op == 'merge':
        if len(inputs)<2: raise ValueError('Mindestens zwei PDFs erforderlich')
        writer=PdfWriter()
        if reverse_inputs:
            inputs.reverse()
        for p in inputs:
            reader=PdfReader(p, strict=True)
            if len(reader.pages)>1000: raise ValueError('Maximal 1000 Seiten pro Eingabedatei')
            if reader.is_encrypted: raise ValueError(f'Passwortgeschütztes PDF nicht unterstützt: {Path(p).name}')
            for page in reader.pages: writer.add_page(page)
        name='zusammengefuehrt.pdf'
        with open(outdir/name,'wb') as f: writer.write(f)
    elif op == 'split':
        if len(inputs)!=1: raise ValueError('Genau eine PDF erforderlich')
        reader=PdfReader(inputs[0], strict=True)
        if len(reader.pages)>1000: raise ValueError('Maximal 1000 Seiten pro PDF')
        if reader.is_encrypted: raise ValueError('Passwortgeschützte PDFs werden nicht unterstützt')
        if len(reader.pages)>500: raise ValueError('Maximal 500 Seiten pro PDF')
        names=[]
        for i,page in enumerate(reader.pages,1):
            w=PdfWriter(); w.add_page(page); name=f'Seite_{i:03d}.pdf'
            with open(outdir/name,'wb') as f: w.write(f)
            names.append(name)
        name='einzelseiten.zip'
        with zipfile.ZipFile(outdir/name,'w',zipfile.ZIP_DEFLATED) as z:
            for n in names: z.write(outdir/n,n); (outdir/n).unlink()
    elif op == 'interleave':
        if len(inputs)!=2: raise ValueError('Genau zwei PDFs erforderlich')
        a,b=[PdfReader(p,strict=True) for p in inputs]
        if len(a.pages)>1000 or len(b.pages)>1000: raise ValueError('Maximal 1000 Seiten pro Eingabedatei')
        if a.is_encrypted or b.is_encrypted: raise ValueError('Passwortgeschützte PDFs werden nicht unterstützt')
        w=PdfWriter()
        second_pages = list(b.pages)
        if reverse_second:
            second_pages.reverse()
        for i in range(max(len(a.pages),len(second_pages))):
            if i<len(a.pages): w.add_page(a.pages[i])
            if i<len(second_pages): w.add_page(second_pages[i])

        if number_interleave:
            total = len(w.pages)
            for index in range(total):
                page = w.pages[index]
                if page.rotation:
                    page.transfer_rotation_to_content()
                overlay = make_number_overlay(
                    float(page.mediabox.width),
                    float(page.mediabox.height),
                    f'- {index + 1} -'
                )
                page.merge_page(overlay)

        name='vorder-rueckseiten.pdf'
        with open(outdir/name,'wb') as f: w.write(f)
    elif op == 'number':
        if len(inputs) != 1:
            raise ValueError('Genau eine PDF erforderlich')

        reader = PdfReader(inputs[0], strict=True)
        if reader.is_encrypted:
            raise ValueError('Passwortgeschützte PDFs werden nicht unterstützt')
        if not reader.pages or len(reader.pages) > 500:
            raise ValueError('Die PDF muss zwischen 1 und 500 Seiten enthalten')

        prefix_args = [p for p in raw_inputs if p.startswith('--prefix=')]
        prefix = prefix_args[0].split('=', 1)[1] if prefix_args else ''
        prefix = prefix.strip()

        if len(prefix) > 150:
            raise ValueError('Der Vorspanntext darf maximal 150 Zeichen lang sein')

        writer = PdfWriter()
        total = len(reader.pages)

        for index, original_page in enumerate(reader.pages, 1):
            # pypdf 7 requires the page to belong to a writer before
            # merge_page() changes its content streams.
            page = writer.add_page(original_page)
            if page.rotation:
                page.transfer_rotation_to_content()

            width = float(page.mediabox.width)
            height = float(page.mediabox.height)
            label = f'{prefix} {index}/{total}'.strip()

            overlay = make_number_overlay(width, height, label)
            page.merge_page(overlay)

        name = 'paginiert.pdf'
        with open(outdir / name, 'wb') as f:
            writer.write(f)

    elif op in ('pdfa', 'ocr'):
        if len(inputs) != 1:
            raise ValueError('Genau eine PDF erforderlich')
        if output_type not in ('pdfa-1', 'pdfa-2', 'pdfa-3'):
            raise ValueError('Ungültige PDF/A-Version')
        if language not in ('deu', 'eng', 'deu+eng'):
            raise ValueError('Ungültige OCR-Sprache')
        source = Path(inputs[0])
        name = {'pdfa': 'archiv.pdf', 'ocr': 'durchsuchbar.pdf'}[op]
        destination = outdir / name
        ocrmypdf = shutil.which('ocrmypdf')
        if not ocrmypdf:
            raise RuntimeError('OCRmyPDF ist nicht installiert. Bitte install.sh ausführen.')
        command = [ocrmypdf, '--jobs', '1']
        if op == 'pdfa':
            # Textseiten überspringen, damit vorhandene Textebenen nicht als OCR-Konflikt abbrechen.
            command.extend(['--skip-text', '--tesseract-timeout', '0', '--output-type', output_type])
        else:
            command.extend([
                '--skip-text', '--language', language,
                '--rotate-pages', '--deskew', '--output-type', 'pdf'
            ])
        command.extend([str(source), str(destination)])
        try:
            subprocess.run(command, check=True, capture_output=True, text=True, timeout=840)
        except subprocess.CalledProcessError as exc:
            detail = (exc.stderr or exc.stdout or 'OCRmyPDF meldet einen unbekannten Fehler').strip()
            raise RuntimeError(detail[-1200:])
        except subprocess.TimeoutExpired:
            raise RuntimeError('Die Verarbeitung hat das Zeitlimit von 14 Minuten überschritten.')
        if not destination.is_file() or destination.stat().st_size == 0:
            raise RuntimeError('OCRmyPDF hat keine gültige Ausgabedatei erzeugt')
    elif op == 'compress':
        if len(inputs) != 1:
            raise ValueError('Genau eine PDF erforderlich')

        profiles = {
            'maximal': (90, 110, 0.35),
            'stark':   (130, 150, 0.50),
            'mittel':  (180, 200, 0.70),
        }
        if profile not in profiles:
            raise ValueError('Ungültige Komprimierungsstufe')

        color_dpi, gray_dpi, qfactor = profiles[profile]
        source = Path(inputs[0])
        name = 'komprimiert.pdf'
        qpdf_file = outdir / 'kandidat_qpdf.pdf'
        color_file = outdir / 'kandidat_farbe.pdf'
        gray_file = outdir / 'kandidat_grau.pdf'
        output_file = outdir / name

        qpdf = shutil.which('qpdf') or '/usr/bin/qpdf'
        gs = shutil.which('gs') or '/usr/bin/gs'

        try:
            subprocess.run(
                [qpdf, '--warning-exit-0', '--stream-data=compress',
                 '--object-streams=generate', str(source), str(qpdf_file)],
                check=True, capture_output=True, text=True, timeout=900
            )

            def make_candidate(destination, dpi, grayscale):
                cmd = [
                    gs, '-dSAFER', '-dBATCH', '-dNOPAUSE',
                    '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
                    '-dDownsampleColorImages=true',
                    '-dDownsampleGrayImages=true',
                    '-dDownsampleMonoImages=true',
                    '-dColorImageDownsampleType=/Bicubic',
                    '-dGrayImageDownsampleType=/Bicubic',
                    '-dMonoImageDownsampleType=/Subsample',
                    f'-dColorImageResolution={dpi}',
                    f'-dGrayImageResolution={dpi}',
                    '-dMonoImageResolution=300',
                    '-dColorImageDownsampleThreshold=1.0',
                    '-dGrayImageDownsampleThreshold=1.0',
                    '-dMonoImageDownsampleThreshold=1.0',
                    '-dAutoFilterColorImages=false',
                    '-dAutoFilterGrayImages=false',
                    '-dColorImageFilter=/DCTEncode',
                    '-dGrayImageFilter=/DCTEncode',
                    '-dPassThroughJPEGImages=false',
                    '-dPassThroughJPXImages=false',
                    f'-sOutputFile={destination}',
                ]
                if grayscale:
                    cmd.append('-sColorConversionStrategy=Gray')
                cmd.extend([
                    '-c',
                    f'<< /ColorImageDict << /QFactor {qfactor} /Blend 1 >> '
                    f'/GrayImageDict << /QFactor {qfactor} /Blend 1 >> '
                    '>> setdistillerparams',
                    '-f', str(source)
                ])
                subprocess.run(
                    cmd, check=True, capture_output=True,
                    text=True, timeout=900
                )
                if not destination.is_file() or destination.stat().st_size == 0:
                    raise RuntimeError('Ghostscript hat keine gültige Ausgabedatei erzeugt')

            if parallel_gs:
                # Experimenteller Pi-5-Modus: zwei unabhängige Ghostscript-Läufe
                # parallel. Wegen des höheren RAM-Spitzenbedarfs nur explizit aktivieren.
                with ThreadPoolExecutor(max_workers=2) as pool:
                    color_task = pool.submit(make_candidate, color_file, color_dpi, False)
                    gray_task = pool.submit(make_candidate, gray_file, gray_dpi, True)
                    color_task.result()
                    gray_task.result()
            else:
                make_candidate(color_file, color_dpi, False)
                make_candidate(gray_file, gray_dpi, True)

            # Graustufen nur berücksichtigen, wenn mindestens 20 % kleiner
            # als die Farbversion.
            candidates = [qpdf_file, color_file]
            if gray_file.stat().st_size <= color_file.stat().st_size * 0.80:
                candidates.append(gray_file)

            best = min(candidates, key=lambda p: p.stat().st_size)
            shutil.copy2(best, output_file)

            if output_file.stat().st_size == 0:
                raise RuntimeError('Die komprimierte PDF ist leer')

        finally:
            for candidate in (qpdf_file, color_file, gray_file):
                try:
                    candidate.unlink()
                except FileNotFoundError:
                    pass
    else: raise ValueError('Unbekannte Verarbeitung')
    print(name)

if __name__ == '__main__':
    try: main()
    except Exception as e:
        print(str(e)[:500],file=sys.stderr); sys.exit(1)
