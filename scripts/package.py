#!/usr/bin/env python3
"""Build the V10 addon ZIP from the runtime directory only."""
import argparse
import hashlib
import json
import os
import re
import stat
import time
import zipfile
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'public/plugins/addon/cr_stock_subscribe'
PREFIX = 'cr_stock_subscribe/'


def version():
    match = re.search(r"const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';", (SOURCE / 'logic/Store.php').read_text())
    if not match:
        raise ValueError('Plugin version is missing')
    return match.group(1)


def source_files():
    files = {}
    for path in sorted(SOURCE.rglob('*')):
        if path.is_symlink():
            raise ValueError('Runtime sources must not be symlinks')
        if not path.is_file():
            continue
        relative = path.relative_to(SOURCE).as_posix()
        parts = PurePosixPath(relative).parts
        if any(part.startswith('.') or part in {'tests', 'private', 'cache', 'runtime', 'node_modules'} for part in parts):
            raise ValueError('Unexpected runtime file: ' + relative)
        if path.suffix not in {'.php', '.html', '.js', '.css', '.md'} and path.name != 'LICENSE':
            raise ValueError('Unexpected runtime file type: ' + relative)
        files[PREFIX + relative] = path.read_bytes()
    return files


def verify(archive):
    expected = source_files()
    with zipfile.ZipFile(archive) as zipped:
        names = zipped.namelist()
        if len(names) != len(set(names)) or set(names) != set(expected):
            raise ValueError('ZIP contents differ from the plugin runtime')
        for info in zipped.infolist():
            path = PurePosixPath(info.filename)
            if path.is_absolute() or '..' in path.parts or stat.S_ISLNK(info.external_attr >> 16):
                raise ValueError('Unsafe ZIP entry')
            if zipped.read(info.filename) != expected[info.filename]:
                raise ValueError('ZIP source mismatch: ' + info.filename)
        if zipped.testzip():
            raise ValueError('Invalid ZIP checksum')
    return {'version': version(), 'file_count': len(expected), 'sha256': hashlib.sha256(Path(archive).read_bytes()).hexdigest()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'dist')
    parser.add_argument('--verify', type=Path)
    args = parser.parse_args()
    if args.verify:
        print(json.dumps(verify(args.verify), indent=2))
        return
    args.output.mkdir(parents=True, exist_ok=True)
    archive = args.output / ('CrStockSubscribe-' + version() + '.zip')
    if archive.exists():
        raise FileExistsError('Choose a new output directory; existing packages are preserved')
    timestamp = time.gmtime(max(315532800, int(os.environ.get('SOURCE_DATE_EPOCH', str(int(time.time()))))))[:6]
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zipped:
        for name, content in source_files().items():
            info = zipfile.ZipInfo(name, timestamp)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            zipped.writestr(info, content)
    result = verify(archive)
    archive.with_suffix('.zip.sha256').write_text(result['sha256'] + '  ' + archive.name + '\n')
    print(json.dumps(dict(result, package=archive.name), indent=2))


if __name__ == '__main__':
    main()
