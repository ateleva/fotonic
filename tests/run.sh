#!/usr/bin/env bash
# Dev-only test runner, excluded from every release. Usage: bash tests/run.sh [filter]
set -u
cd "$(dirname "$0")/.."
SOCK="${FTNC_DB_SOCKET:-$HOME/Library/Application Support/Local/run/JdpHqGxur/mysql/mysqld.sock}"
FILTER="${1:-}"
status=0
# These tests clear and rewrite the site's real Vault encryption state (the job and the covered-fields list) while
# they run. With the app open in a browser, its next Vault status read then starts a new "encrypt your existing
# data" job and shows that window after every unlock. They are skipped unless asked for, with the browser closed.
GUARDED="test-encrypt-fields.php test-pro-vault-fields.php"
for f in tests/unit/*.php tests/integration/*.php; do
  [ -e "$f" ] || continue
  case "$f" in *"$FILTER"*) ;; *) continue ;; esac
  for g in $GUARDED; do
    if [[ "$f" == *"$g" && -z "${FTNC_VAULT_STATE_TESTS:-}" ]]; then
      echo "== $f (skipped: it rewrites the live Vault encryption state; FTNC_VAULT_STATE_TESTS=1 runs it, with the browser closed)"
      continue 2
    fi
  done
  echo "== $f"
  if [[ "$f" == tests/integration/* ]]; then
    php -d mysqli.default_socket="$SOCK" -d error_reporting=24575 "$f" || status=1
  else
    php -d error_reporting=24575 "$f" || status=1
  fi
done
exit $status
