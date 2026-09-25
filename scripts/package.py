#!/usr/bin/env python3
"""Build only explicitly listed module files; never include local config or secrets."""
import argparse
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('output', type=Path)
args = parser.parse_args()
files = [root / 'README.md', root / 'config.example.php']
for folder, suffix in [('src', '.php'), ('class', '.php'), ('core', '.php'), ('admin', '.php'), ('sql', '.sql'), ('langs', '.lang')]:
    files.extend(sorted((root / folder).rglob('*' + suffix)))
with ZipFile(args.output, 'w', ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, 'dolimednotif/' + str(path.relative_to(root)))
print(str(args.output.resolve()))
