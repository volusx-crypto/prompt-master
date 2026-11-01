#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mkdir -p "${ROOT_DIR}/releases"

rm -f "${ROOT_DIR}/releases/ai-site-commander.zip" \
      "${ROOT_DIR}/releases/ai-gsc-autopilot.zip" \
      "${ROOT_DIR}/releases/mcp-server.zip"

(
  cd "${ROOT_DIR}/plugins"
  zip -rq "${ROOT_DIR}/releases/ai-site-commander.zip" ai-site-commander
  zip -rq "${ROOT_DIR}/releases/ai-gsc-autopilot.zip" ai-gsc-autopilot
)

(
  cd "${ROOT_DIR}"
  zip -rq "${ROOT_DIR}/releases/mcp-server.zip" mcp-server
)

echo "Packaged plugins in ${ROOT_DIR}/releases:"
ls -lh "${ROOT_DIR}/releases"
