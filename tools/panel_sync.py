#!/usr/bin/env python3
"""Copy the Domains panel pieces and page types into this extension.

Usage: tools/panel_sync.py DOMAINS_CHECKOUT [--rev SHA] [--check]

Domains owns the pieces (Head, Value, Row, SettingsRow, Trace), the page types and their Blade views. This
script reads them from a Domains git checkout at one revision, moves them under this extension's namespace and
view namespace, and writes them with a header naming that revision. Class names, CSS class names and
data-piece markers stay exactly as in Domains, so a host skin draws every extension the same way.
This extension owns Panel/Presentation/State.php: its own status words, built from the shared block.
The same script runs in Backups and AE Stripe; it finds which one it is in from the checkout.
tools/panel_sync.lock.json records the revision, this script's hash and each written file's hash, and the
extension's tests fail when a copied file, the script or the lock no longer match.
"""
import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

REV = '08c94293c3a93f4afcd4704186dc0cf420dc5ea3'
SOURCE = 'Domains'
LOCK = 'tools/panel_sync.lock.json'
DOMAINS = 'Paymenter\\Extensions\\Servers\\Domains\\'
TARGETS = {
    'extension/Backups': ('Paymenter\\Extensions\\Others\\Backups\\', 'backups'),
    'extension/AeStripe': ('Paymenter\\Extensions\\Gateways\\AeStripe\\', 'gateways.ae-stripe'),
}
# Domains path prefix => path under the extension root, longest first. The namespace follows the path.
PLACES = [
    ('Domains/Admin/Support/Panel/', 'Panel/'),
    ('Domains/Admin/Support/', 'Panel/Support/'),
    ('Domains/Presentation/', 'Panel/Presentation/'),
    ('Domains/Support/', 'Panel/Support/'),
    ('Domains/resources/views/components/panel/', 'resources/views/panel/'),
    ('Domains/resources/views/partials/', 'resources/views/panel/'),
]
FILES = [
    *(f'Domains/Admin/Support/Panel/{name}.php' for name in ('Head', 'Value', 'Row', 'SettingsRow', 'Trace')),
    *(f'Domains/Admin/Support/Panel/Page/{name}.php' for name in (
        'PageType', 'Section', 'Column', 'Filter', 'DetailPage', 'RecordDetailPage', 'SettingsPage',
        'ResourceSettingsPage', 'ListPage', 'QueryListPage', 'RecordListPage')),
    *(f'Domains/Admin/Support/Panel/Page/Concerns/{name}.php' for name in (
        'RendersType', 'DetailType', 'SettingsType', 'ListType', 'BuildsList', 'RecordListType', 'ForRecord')),
    *(f'Domains/Admin/Support/{name}.php' for name in ('Sheet', 'HelpHint', 'EmptyTable', 'TableColumns', 'OneLine', 'DemoTag')),
    'Domains/Support/DemoData.php',
    *(f'Domains/Presentation/{name}.php' for name in (
        'Tone', 'Reader', 'Entry', 'Plain', 'Fact', 'StaffTime', 'TldNames', 'DomainName', 'Availability', 'Read')),
    'Domains/Presentation/Codes/Coded.php',
    *(f'Domains/resources/views/components/panel/{name}.blade.php' for name in (
        'fact', 'head-line', 'head-title', 'page-head', 'row', 'switch', 'trace', 'value')),
    'Domains/resources/views/partials/moment.blade.php',
]
# Written by each extension, never copied.
OWNED = {'Panel\\Presentation\\State'}
# Named by copied code only on paths an extension never calls: the History types' table, the old trace and
# Missing helpers in Sheet, Demo banners and seeded Domains records. They stay Domains-only until Domains drops them.
NOT_COPIED = {
    'Panel\\Support\\StatusMark', 'Panel\\Presentation\\Missing', 'Panel\\Page\\HistoryPage',
    'Panel\\Page\\RecordHistoryPage', 'Panel\\Support\\HistoryTable',
}


def source(checkout, rev, path):
    return subprocess.run(['git', '-C', str(checkout), 'show', f'{rev}:{path}'], check=True,
                          capture_output=True).stdout.decode()


def place(path):
    for prefix, target in PLACES:
        if path.startswith(prefix):
            return target + path[len(prefix):]
    raise ValueError(path)


def class_of(path):
    return place(path)[:-len('.php')].replace('/', '\\')


def rewrite(text, namespace, views):
    # Longest Domains namespace first, so the Panel prefix wins over Admin\Support.
    for prefix, target in PLACES[:4]:
        old = DOMAINS + prefix[len('Domains/'):].replace('/', '\\')
        text = text.replace(old.rstrip('\\'), (namespace + target.replace('/', '\\')).rstrip('\\'))
    text = text.replace('domains::components.panel.', views + '::panel.')
    return text.replace('domains::partials.moment', views + '::panel.moment')


def header(path, rev, text):
    line = f'Synced from {SOURCE} {rev} {path} by tools/panel_sync.py. Change it in {SOURCE} and run the script again.'
    if path.endswith('.blade.php'):
        return '{{-- ' + line + ' --}}\n' + text
    first, rest = text.split('\n', 1)
    if first != '<?php':
        raise ValueError(f'{path} does not start with <?php')
    return f'{first}\n// {line}\n{rest}'


def build(checkout, rev, root):
    namespace, views = TARGETS[root]
    copied = {class_of(path) for path in FILES if path.endswith('.php') and not path.endswith('.blade.php')}
    out = {}
    for path in FILES:
        text = rewrite(source(checkout, rev, path), namespace, views)
        # Every class the copy names in our namespace is copied, owned here, or on a path we never call.
        for name in re.findall(re.escape(namespace) + r'(Panel\\[A-Za-z\\]+)', text.replace('{', '')):
            name = name.rstrip('\\')
            if name in copied or name in OWNED or name in NOT_COPIED or any(f'{name}\\' in c for c in copied):
                continue
            raise ValueError(f'{path} names {name}, which is not copied')
        if 'domains::' in text and not path.endswith('/Sheet.php') and not path.endswith('/DemoData.php'):
            raise ValueError(f'{path} renders a Domains-only view')
        out[f'{root}/{place(path)}'] = header(path, rev, text)
    return out


def lock(files, rev):
    script = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    return json.dumps({'source': SOURCE, 'rev': rev, 'script': script,
                       'files': {name: hashlib.sha256(text.encode()).hexdigest() for name, text in sorted(files.items())}},
                      indent=1) + '\n'


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('domains', type=Path, help='a Domains git checkout')
    parser.add_argument('--rev', default=REV)
    parser.add_argument('--check', action='store_true', help='fail when the copy differs from this output')
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[1]
    roots = [root for root in TARGETS if (repo / root).is_dir()]
    if len(roots) != 1:
        sys.exit('Run this from a Backups or AE Stripe checkout.')
    rev = subprocess.run(['git', '-C', str(args.domains), 'rev-parse', '--verify', args.rev + '^{commit}'],
                         check=True, capture_output=True, text=True).stdout.strip()
    files = build(args.domains, rev, roots[0])
    files[LOCK] = lock(files, rev)
    stale = sorted(name for name, text in files.items()
                   if not (repo / name).is_file() or (repo / name).read_text() != text)
    if args.check:
        if stale:
            sys.exit('Panel copy differs from tools/panel_sync.py output: ' + ', '.join(stale))
        print(f'Panel copy matches {SOURCE} {rev}')
        return
    for name, text in files.items():
        (repo / name).parent.mkdir(parents=True, exist_ok=True)
        (repo / name).write_text(text)
    print(f'Wrote {len(files)} files from {SOURCE} {rev}')


if __name__ == '__main__':
    main()
