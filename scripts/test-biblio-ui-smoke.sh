#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIRECTORY="web/wp-content/plugins/biblio-ui"

ddev start >/dev/null

ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  sh -lc 'find . -type f -name "*.php" -print0 | xargs -0 -n1 php -l'

ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  php tests/smoke.php
