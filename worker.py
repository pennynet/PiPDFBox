#!/usr/bin/env python3
"""PiPDFBox PDF worker. Arguments are passed as an argv list, never through a shell."""
import os, sys, zipfile, subprocess, shutil
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
    profile_args = [p for p in raw_inputs if p.startswith('--profile=')]
    profile = profile_args[0].split('=', 1)[1] if profile_args else 'stark'
    inputs = [
        p for p in raw_inputs
        if p != '--reverse-second' and not p.startswith('--profile=') and not p.startswith('--prefix=')
    ]
    outdir = Path(outdir).resolve()
    for p in inputs:
        q=Path(p).resolve()
        if not q.is_file() or q.parent != outdir: raise ValueError('Ungültiger Eingabepfad')
    if op == 'merge':
        if len(inputs)<2: raise ValueError('Mindestens zwei PDFs erforderlich')
        writer=PdfWriter()
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
            page = original_page
            if page.rotation:
                page.transfer_rotation_to_content()

            width = float(page.mediabox.width)
            height = float(page.mediabox.height)
            label = f'{prefix} {index}/{total}'.strip()

            overlay = make_number_overlay(width, height, label)
            page.merge_page(overlay)
            writer.add_page(page)

        name = 'nummeriert.pdf'
        with open(outdir / name, 'wb') as f:
            writer.write(f)

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
