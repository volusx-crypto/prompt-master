#!/usr/bin/env bash
# Build an installable WordPress plugin zip.
set -euo pipefail
cd "$(dirname "$0")"

rm -f plugin-harmony.zip

zip -r -q plugin-harmony.zip plugin-harmony \
  -x "*/.DS_Store" \
  -x "__MACOSX/*" \
  -x "*/.*"

echo "ساخته شد: $(pwd)/plugin-harmony.zip"
unzip -l plugin-harmony.zip | tail -n 3
