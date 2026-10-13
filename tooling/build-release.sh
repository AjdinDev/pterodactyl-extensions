#!/usr/bin/env bash
set -euo pipefail

source_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
panel_dir=${PTERODACTYL_PANEL:?Set PTERODACTYL_PANEL to the configured stock Panel checkout}
panel_dir=$(cd -- "$panel_dir" && pwd)
output_dir=${RELEASE_OUTPUT_DIR:-"$source_dir/output/release"}
selection=${1:-all}

case "$selection" in
  all) extensions=(cs2-admins database-studio discord-status gamequery support-tickets) ;;
  cs2-admins|database-studio|discord-status|gamequery|support-tickets) extensions=("$selection") ;;
  *) echo "Unknown extension: $selection" >&2; exit 1 ;;
esac

cd "$source_dir"
printf '%s\n' 'ce76093cc247de8d408a50048937accbbdacfc0aa1eeb6d3159962e3983a4e0a  tooling/pterodactyl-sdk-2.0.0-beta.4.tgz' | sha256sum -c -
mkdir -p "$output_dir"
output_dir=$(cd -- "$output_dir" && pwd)
if [[ -n $(ls -A "$output_dir") ]]; then
  echo 'Choose an empty output directory; existing release files will not be overwritten.' >&2
  exit 1
fi

for extension in "${extensions[@]}"; do
  (
    cd "$source_dir/$extension"
    version=$(node --input-type=module -e '
      import fs from "node:fs";
      const manifest = JSON.parse(fs.readFileSync("extension.json"));
      const pkg = JSON.parse(fs.readFileSync("package.json"));
      if (manifest.id !== process.argv[1] || manifest.version !== pkg.version ||
          !/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(manifest.version)) {
        throw new Error("Extension ID/version must match its directory and package.json.");
      }
      console.log(manifest.version);
    ' "$extension")
    npm ci
    npm run typecheck
    php_roots=()
    for directory in src routes resources database; do
      if [[ -d "$directory" ]]; then php_roots+=("$directory"); fi
    done
    find "${php_roots[@]}" -type f -name '*.php' -print0 | xargs -0 -n 1 php -l
    npm run build
    cd "$panel_dir"
    php artisan p:extension:pack "$source_dir/$extension" \
      --output="$output_dir/$extension-$version.pteroext"
  )
done

python3 - "$source_dir" "$output_dir" "$panel_dir" "${extensions[@]}" <<'PY'
import hashlib
import json
import os
import subprocess
import sys
import zipfile
from pathlib import Path

source, output, panel = map(Path, sys.argv[1:4])
extensions = []
for extension in sys.argv[4:]:
    manifest = json.loads((source / extension / 'extension.json').read_text())
    archive = output / f"{extension}-{manifest['version']}.pteroext"
    with zipfile.ZipFile(archive) as package:
        bad = {'.env', 'node_modules', 'tests', 'test', 'coverage', 'tooling', 'local', 'tools'}
        for name in package.namelist():
            parts = Path(name).parts
            if Path(name).is_absolute() or any(part.startswith('.') or part in bad for part in parts):
                raise SystemExit(f'Unexpected archive entry: {archive.name}: {name}')
        if json.loads(package.read('extension.json')) != manifest:
            raise SystemExit(f'Manifest mismatch: {archive.name}')
        package.read(manifest['ui']['entry'])
        for notice in ('LICENSE', 'NOTICE'):
            if package.read(f'resources/legal/{notice}') != (source / notice).read_bytes():
                raise SystemExit(f'Missing or outdated {notice}: {archive.name}')
    extensions.append({'id': extension, 'version': manifest['version'], 'file': archive.name})

panel_commit = subprocess.check_output(['git', '-C', str(panel), 'rev-parse', 'HEAD'], text=True).strip()
if os.environ.get('PANEL_COMMIT', panel_commit) != panel_commit:
    raise SystemExit('Panel packager checkout does not match PANEL_COMMIT.')
info = {'source_commit': os.environ.get('GITHUB_SHA'), 'panel_commit': panel_commit,
        'sdk_sha256': hashlib.sha256((source / 'tooling/pterodactyl-sdk-2.0.0-beta.4.tgz').read_bytes()).hexdigest(),
        'extensions': extensions}
(output / 'BUILD-INFO.json').write_text(json.dumps(info, indent=2) + '\n')
files = sorted(output.glob('*.pteroext')) + [output / 'BUILD-INFO.json']
(output / 'SHA256SUMS').write_text(''.join(f'{hashlib.sha256(file.read_bytes()).hexdigest()}  {file.name}\n' for file in files))
print(f'Validated {len(extensions)} installable packages in {output}')
PY
