#!/usr/bin/env python3
"""Check plugin source without loading V10 or reading site configuration."""
import os
import re
import subprocess
from pathlib import Path

from package import ROOT, SOURCE, source_files, version


def main():
    files = source_files()
    php = os.environ.get('PHP_BINARY', 'php')
    node = os.environ.get('NODE_BINARY', 'node')
    counts = {'php': 0, 'javascript': 0}
    for path in sorted(SOURCE.rglob('*')):
        if not path.is_file():
            continue
        if path.suffix == '.php':
            command = [php, '-l', str(path)]
            counts['php'] += 1
        elif path.suffix == '.js':
            command = [node, '--check', str(path)]
            counts['javascript'] += 1
        else:
            continue
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL)
    for name, raw in files.items():
        text = raw.decode('utf-8')
        if re.search(r'/(?:www/wwwroot|root)/|mf\.cooree\.loc\.cc|-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}', text):
            raise ValueError('Private content in runtime source: ' + name)
    notice = (SOURCE / 'logic/Notice.php').read_text()
    defaults = re.findall(r"'sms'=>'([^']+)'", notice)
    if len(defaults) != 4 or any(re.search(r'https?://|stock_(?:purchase|manage|notification)_url', text) for text in defaults):
        raise ValueError('Default SMS templates must remain link-free')
    if not (SOURCE / 'vendor/net_dns2/LICENSE').is_file():
        raise ValueError('Third-party license is missing')
    if any(str(p).endswith('.sql') for p in ROOT.rglob('*') if '.git' not in p.parts):
        raise ValueError('Database dumps do not belong in the repository')
    print('PASS version ' + version() + '; PHP ' + str(counts['php']) + '; JavaScript ' + str(counts['javascript']) + '; runtime files ' + str(len(files)))


if __name__ == '__main__':
    main()
