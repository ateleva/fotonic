#!/usr/bin/env bash
# Dev-only test runner, excluded from every release. Usage: bash tests/run.sh [filter]
set -u
cd "$(dirname "$0")/.."
SOCK="${FTNC_DB_SOCKET:-$HOME/Library/Application Support/Local/run/JdpHqGxur/mysql/mysqld.sock}"
FILTER="${1:-}"
status=0
for f in tests/unit/*.php tests/integration/*.php; do
  [ -e "$f" ] || continue
  case "$f" in *"$FILTER"*) ;; *) continue ;; esac
  echo "== $f"
  if [[ "$f" == tests/integration/* ]]; then
    php -d mysqli.default_socket="$SOCK" -d error_reporting=24575 "$f" || status=1
  else
    php -d error_reporting=24575 "$f" || status=1
  fi
done
exit $status
