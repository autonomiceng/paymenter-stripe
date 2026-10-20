#!/usr/bin/env python3
# Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
"""Build an offline, deterministic native Paymenter uploader ZIP."""
import argparse
import hashlib
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def build(output):
    output = Path(output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    entries = {str(p.relative_to(ROOT / 'extension')): p
               for p in (ROOT / 'extension' / 'StripeSaved').rglob('*') if p.is_file()}
    for name in ('LICENSE', 'UPSTREAM.md', 'NOTICES.md', 'README.md', 'docs'):
        source = ROOT / name
        for path in sorted(source.rglob('*')) if source.is_dir() else [source]:
            if path.is_file():
                entries['StripeSaved/' + str(path.relative_to(ROOT))] = path
    with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, source in sorted(entries.items()):
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, source.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(output.suffix + '.sha256').write_text(f'{digest}  {output.name}\n')
    print(f'{output}: {digest}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default='dist/StripeSaved-0.1.0.zip')
    build(parser.parse_args().output)
