#!/usr/bin/env python3
# Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
"""Build an offline, deterministic native Paymenter uploader ZIP."""
import argparse
import hashlib
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCES = ('extension/StripeSaved', 'LICENSE', 'UPSTREAM.md', 'NOTICES.md', 'README.md', 'docs')


def tracked_files():
    # Package only git-tracked files: untracked .env or credential fixtures never ship.
    listing = subprocess.run(['git', '-C', str(ROOT), 'ls-files', '-z', '--', *SOURCES],
                             check=True, capture_output=True).stdout.decode()
    paths = sorted(name for name in listing.split('\0') if name)
    for name in paths:
        path = ROOT / name
        components = [path, *path.parents]
        if (any(component.is_symlink() for component in components if component != ROOT and ROOT in component.parents)
                or not path.resolve().is_relative_to(ROOT)
                or not path.is_file()):
            raise SystemExit(f'Refusing release entry {name}: not a regular tracked file')
    return paths


def build(output):
    output = Path(output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    entries = {}
    for name in tracked_files():
        path = ROOT / name
        if name.startswith('extension/'):
            entries[name.removeprefix('extension/')] = path
        else:
            entries['StripeSaved/' + name] = path
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
