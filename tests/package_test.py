#!/usr/bin/env python3
"""Reject wrong-root, incomplete, injected and changed V10 archives."""
import importlib.util
import tempfile
import zipfile
from pathlib import Path

spec = importlib.util.spec_from_file_location('stock_package', Path(__file__).resolve().parents[1] / 'scripts/package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)


def archive(path, files):
    with zipfile.ZipFile(path, 'x', compression=zipfile.ZIP_DEFLATED) as zipped:
        for name, content in files.items():
            zipped.writestr(name, content)


with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    original = package.source_files()
    archive(root / 'valid.zip', original)
    assert package.verify(root / 'valid.zip')['version'] == package.version()
    first = next(iter(original))
    changed = dict(original)
    changed[first] += b'\nchanged\n'
    missing = dict(original)
    missing.pop(first)
    cases = {
        'wrong-root': {'public/plugins/addon/' + name: content for name, content in original.items()},
        'missing-file': missing,
        'changed-source': changed,
        'extra-private-file': dict(original, **{'cr_stock_subscribe/private/secret.txt': b'test fixture'}),
        'path-traversal': dict(original, **{'cr_stock_subscribe/../outside.txt': b'test fixture'}),
    }
    for label, files in cases.items():
        path = root / (label + '.zip')
        archive(path, files)
        try:
            package.verify(path)
        except ValueError:
            print('PASS reject ' + label)
        else:
            raise AssertionError(label + ' was accepted')
    print('PASS source-equivalent V10 archive')
