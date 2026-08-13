#!/usr/bin/env bash
# .i18n/run.sh — full i18n pipeline for the free plugin (Eleva CRM for Photographers).
#
# Chains: extract source -> update .pot -> [merge new translations] ->
# compile .mo -> regenerate the JS sidecar -> doctor check confirming every
# source string will actually load.
#
# This plugin uses WP core's md5-hashed sidecar filename convention (auto-
# detected from the wp_enqueue_script() src in class-admin-page.php) rather
# than a handle-based name — see CLAUDE.md's i18n section. Don't pass
# --handle here; that's Fotonic Pro's convention, not this plugin's.
#
# Usage:
#   .i18n/run.sh [translations.json]
#
#   translations.json - optional; new {msgid, msgstr} pairs (or a list of
#                        such objects — see po_manager.py's own --help) to
#                        merge into the .po before compiling. Omit to just
#                        recompile and re-sidecar whatever's already
#                        translated in the .po as it stands.
#
# Not run automatically by `npm run build` — this touches translation
# content, not just code, so it stays a deliberate step. `npm run i18n:check`
# (read-only) is what CI/pre-release should actually gate on.

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

PLUGIN_PATH="$(pwd)"
TEXTDOMAIN="eleva-crm-for-photographers"
LOCALE="it_IT"
SCRIPTS="$PLUGIN_PATH/.i18n/scripts"
LANG_DIR="$PLUGIN_PATH/languages"
POT="$LANG_DIR/$TEXTDOMAIN.pot"
PO="$LANG_DIR/$TEXTDOMAIN-$LOCALE.po"
EXTRACTED="$(mktemp -t i18n_extracted.XXXXXX)"
trap 'rm -f "$EXTRACTED"' EXIT

echo "==> 1/5 extract source strings"
python3 "$SCRIPTS/extract_strings.py" "$PLUGIN_PATH" "$TEXTDOMAIN" > "$EXTRACTED"

echo "==> 2/5 update .pot"
python3 "$SCRIPTS/pot_manager.py" update "$POT" "$EXTRACTED"

if [ "${1:-}" != "" ]; then
  echo "==> 3/5 merge translations from $1"
  python3 "$SCRIPTS/po_manager.py" update "$PO" "$1"
else
  echo "==> 3/5 skipped (no translations file given — pass one to add new IT strings)"
fi

echo "==> 4/5 compile .mo"
msgfmt "$PO" -o "$LANG_DIR/$TEXTDOMAIN-$LOCALE.mo"
rm -f "$PO.bak"

echo "==> 5/5 regenerate JS sidecar (md5 auto-detected filename)"
python3 "$SCRIPTS/json_generator.py" "$PLUGIN_PATH" "$TEXTDOMAIN" "$LOCALE" "$PO" "$EXTRACTED"

echo ""
echo "==> doctor check"
python3 "$SCRIPTS/i18n_doctor.py" "$PLUGIN_PATH" "$TEXTDOMAIN" "$LOCALE"
