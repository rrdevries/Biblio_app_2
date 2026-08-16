#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

EXPECTED_WP_VERSION="7.0.2"
WP_LOCALE="nl_NL"
WP_URL="https://biblio-v2.ddev.site"

ddev start

if [ ! -f .env.local ]; then
  WP_ADMIN_PASSWORD="$(openssl rand -hex 24)"
  printf "WP_ADMIN_PASSWORD=%s\n" "$WP_ADMIN_PASSWORD" > .env.local
fi

set -a
source .env.local
set +a

if [ -f web/wp-includes/version.php ]; then
  CURRENT_WP_VERSION="$(ddev wp core version)"
  if [ "$CURRENT_WP_VERSION" != "$EXPECTED_WP_VERSION" ]; then
    echo "FOUT: WordPress $CURRENT_WP_VERSION gevonden; verwacht $EXPECTED_WP_VERSION."
    exit 1
  fi
else
  ddev wp core download --version="$EXPECTED_WP_VERSION" --locale="$WP_LOCALE" --force
fi

ddev wp core verify-checksums --version="$EXPECTED_WP_VERSION" --locale="$WP_LOCALE"

if ! ddev wp core is-installed >/dev/null 2>&1; then
  ddev wp core install \
    --url="$WP_URL" \
    --title="Biblio V2" \
    --admin_user="biblio_admin" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="admin@biblio.test" \
    --locale="$WP_LOCALE" \
    --skip-email

  ddev wp option update timezone_string "Europe/Amsterdam"
  ddev wp rewrite structure "/%postname%/"
  ddev wp rewrite flush
fi

echo "Biblio V2 lokale WordPress-baseline is gereed."
echo "WordPress: $(ddev wp core version)"
echo "URL: $(ddev wp option get siteurl)"
