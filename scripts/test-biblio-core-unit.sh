#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIRECTORY="web/wp-content/plugins/biblio-core"

if [ ! -f "$PLUGIN_DIRECTORY/vendor/autoload.php" ]; then
  echo "FOUT: Biblio Core Composer-dependencies ontbreken."
  echo "Voer uit: ddev composer --working-dir=$PLUGIN_DIRECTORY install"
  exit 1
fi

ddev start >/dev/null
ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  vendor/bin/phpunit --configuration phpunit.xml
