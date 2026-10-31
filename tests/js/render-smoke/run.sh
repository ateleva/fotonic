#!/usr/bin/env bash
# Dev-only render smoke test of the Vault screens (server-side render, no browser): every screen renders and
# carries its key texts. Usage: bash tests/js/render-smoke/run.sh   (never shipped: /tests is in .distignore)
set -euo pipefail
cd "$(dirname "$0")/../../../src"
HERE="$(cd ../tests/js/render-smoke && pwd)"
OUT="$(mktemp -d)"
cp "$HERE/entry.jsx" ./_smoke_entry.jsx
trap 'rm -f ./_smoke_entry.jsx; rm -rf "$OUT"' EXIT
cat > "$OUT/vite.config.mjs" <<CFG
import react from '$(pwd)/node_modules/@vitejs/plugin-react/dist/index.js'
export default { root: '$(pwd)', plugins: [react()], build: { ssr: '$(pwd)/_smoke_entry.jsx', outDir: '$OUT/dist', emptyOutDir: true, minify: false }, ssr: { noExternal: true }, logLevel: 'error' }
CFG
npx vite build --config "$OUT/vite.config.mjs" >/dev/null
node --import "$HERE/pre.mjs" "$OUT/dist/_smoke_entry.js"
