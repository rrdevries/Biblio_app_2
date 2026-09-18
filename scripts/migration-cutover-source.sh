#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${1:-}" != "prepare" ]; then
  echo "FOUT: alleen zero-write modus 'prepare' is beschikbaar." >&2
  exit 1
fi

ddev exec --dir /var/www/html php /var/www/html/scripts/migration-cutover-source.php "$@"
