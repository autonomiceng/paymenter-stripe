#!/usr/bin/env python3
# Copyright (c) 2026 Autonomic Engineering. MIT; see LICENSE.
"""Exercise release entry checks in disposable local repositories."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('build_release', Path(__file__).resolve().parents[1] / 'tools/build_release.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class ReleaseEntries(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix='stripe-release-')
        self.addCleanup(self.fixture.cleanup)
        self.root = Path(self.fixture.name) / 'repo'
        self.root.mkdir()
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True)
        (self.root / 'docs').mkdir()
        (self.root / 'docs/guide.md').write_text('public guide\n')
        subprocess.run(['git', '-C', str(self.root), 'add', 'docs/guide.md'], check=True)
        self.root_patch = patch.object(builder, 'ROOT', self.root)
        self.root_patch.start()
        self.addCleanup(self.root_patch.stop)

    def test_regular_tracked_file(self):
        self.assertEqual(builder.tracked_files(), ['docs/guide.md'])

    def test_leaf_symlink(self):
        (self.root / 'docs/guide.md').unlink()
        (self.root / 'docs/guide.md').symlink_to(self.outside_file())
        self.assert_refused()

    def outside_file(self):
        outside = Path(self.fixture.name) / 'outside'
        outside.mkdir(exist_ok=True)
        target = outside / 'guide.md'
        target.write_text('private synthetic fixture\n')
        return target

    def assert_refused(self):
        output = Path(self.fixture.name) / 'release.zip'
        with self.assertRaisesRegex(SystemExit, 'Refusing release entry'):
            builder.build(output)
        self.assertFalse(output.exists())

    def test_parent_symlink_outside_root(self):
        target = self.outside_file()
        (self.root / 'docs/guide.md').unlink()
        (self.root / 'docs').rmdir()
        (self.root / 'docs').symlink_to(target.parent, target_is_directory=True)
        self.assert_refused()

    def test_parent_symlink_inside_root(self):
        (self.root / 'docs').rename(self.root / 'real-docs')
        (self.root / 'docs').symlink_to(self.root / 'real-docs', target_is_directory=True)
        self.assert_refused()

    def test_nested_parent_symlink(self):
        (self.root / 'docs/guide.md').unlink()
        target = self.outside_file()
        (self.root / 'docs/nested').symlink_to(target.parent, target_is_directory=True)
        subprocess.run(['git', '-C', str(self.root), 'update-index', '--add', '--cacheinfo',
                        '100644,' + self.blob() + ',docs/nested/guide.md'], check=True)
        # Drop the original tracked path so refusal must come from the nested entry.
        subprocess.run(['git', '-C', str(self.root), 'rm', '--cached', '-q', 'docs/guide.md'], check=True)
        self.assert_refused()

    def blob(self):
        return subprocess.run(['git', '-C', str(self.root), 'hash-object', '-w', '--stdin'],
                              input=b'public\n', check=True, capture_output=True).stdout.decode().strip()

    def test_resolved_escape(self):
        # Defensive containment also rejects an out-of-root path without a symlink.
        self.outside_file()
        listing = subprocess.CompletedProcess([], 0, stdout=b'../outside/guide.md\0')
        with patch.object(builder.subprocess, 'run', return_value=listing):
            self.assert_refused()


if __name__ == '__main__':
    unittest.main()
