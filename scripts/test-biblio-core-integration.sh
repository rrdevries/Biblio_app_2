#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIRECTORY="web/wp-content/plugins/biblio-core"
TEST_DATABASE="biblio_core_test"

if [ "$TEST_DATABASE" != "biblio_core_test" ]; then
  echo "FOUT: onveilige integratietest-databasenaam."
  exit 1
fi

if [ ! -f "$PLUGIN_DIRECTORY/vendor/autoload.php" ]; then
  echo "FOUT: Biblio Core Composer-dependencies ontbreken."
  echo "Voer uit: ddev composer --working-dir=$PLUGIN_DIRECTORY install"
  exit 1
fi

ddev start >/dev/null

cleanup() {
  ddev mysql -uroot -proot -e \
    "REVOKE ALL PRIVILEGES ON \`biblio_core_test\`.* FROM 'db'@'%';" \
    >/dev/null 2>&1 || true
  ddev mysql -uroot -proot -e \
    "DROP DATABASE IF EXISTS \`biblio_core_test\`;" \
    >/dev/null 2>&1 || true
}

trap cleanup EXIT

ddev mysql -uroot -proot -e \
  "DROP DATABASE IF EXISTS \`biblio_core_test\`; \
CREATE DATABASE \`biblio_core_test\` \
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
GRANT ALL PRIVILEGES ON \`biblio_core_test\`.* TO 'db'@'%';"

ddev exec env DB_NAME="$TEST_DATABASE" wp \
  --path=/var/www/html/web core install \
  --url=https://biblio-core.test \
  --title="Biblio Core Integration" \
  --admin_user=biblio_integration_admin \
  --admin_password=integration-test-only-not-a-secret \
  --admin_email=integration@example.invalid \
  --skip-email >/dev/null

ddev exec env DB_NAME="$TEST_DATABASE" wp \
  --path=/var/www/html/web plugin activate biblio-core >/dev/null

ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  env DB_NAME="$TEST_DATABASE" \
  vendor/bin/phpunit --configuration phpunit.integration.xml
