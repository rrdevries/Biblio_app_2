#!/usr/bin/env bash
# Synthetic PREP-01B acceptance only. No source-path argument and no production mode.
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
[ "$#" -eq 0 ] || { echo "This synthetic acceptance tool takes no arguments." >&2; exit 1; }
test -z "$(git status --porcelain)" || { echo "A clean committed candidate is required." >&2; exit 1; }
REHEARSAL_REPO="$(pwd -P)"
REHEARSAL_SHA="$(git rev-parse HEAD)"
REHEARSAL_NONCE="$(openssl rand -hex 6)"
REHEARSAL_PROJECT="biblio-v2-cutover-$REHEARSAL_NONCE"
mkdir -p "$REHEARSAL_REPO/.local"
REHEARSAL_ROOT="$(mktemp -d "$REHEARSAL_REPO/.local/cutover-prep01b-XXXXXXXX")"
chmod 700 "$REHEARSAL_ROOT"
./scripts/migration-trial.sh normal-fingerprint > "$REHEARSAL_ROOT/normal-before.json"
git clone --quiet --no-hardlinks "$REHEARSAL_REPO" "$REHEARSAL_ROOT/app"
git -C "$REHEARSAL_ROOT/app" checkout --quiet --detach "$REHEARSAL_SHA"
mkdir -p "$REHEARSAL_ROOT/app/web" "$REHEARSAL_ROOT/app/.local/evidence"
rsync -a --exclude='wp-config*.php' --exclude=wp-content --exclude=.maintenance \
  --include='wp-admin/***' --include='wp-includes/***' --include='/*.php' --exclude='*' \
  "$REHEARSAL_REPO/web/" "$REHEARSAL_ROOT/app/web/"
rsync -a "$REHEARSAL_REPO/web/wp-content/plugins/biblio-core/vendor/" "$REHEARSAL_ROOT/app/web/wp-content/plugins/biblio-core/vendor/"
printf 'name: %s\nweb_environment:\n  - WP_ENVIRONMENT_TYPE=local\n  - BIBLIO_REHEARSAL=1\n  - BIBLIO_REHEARSAL_ID=synthetic-%s\n  - BIBLIO_REHEARSAL_WRITES_FROZEN=1\n' \
  "$REHEARSAL_PROJECT" "$REHEARSAL_NONCE" > "$REHEARSAL_ROOT/app/.ddev/config.cutover.local.yaml"
(
  cd "$REHEARSAL_ROOT/app"
  test "$(ddev describe --json-output | jq -r 'if (.raw|type)=="array" then .raw[0].name else .raw.name end')" = "$REHEARSAL_PROJECT"
  ddev start >/dev/null
  test "$(ddev exec printenv DDEV_SITENAME)" = "$REHEARSAL_PROJECT"
  ddev wp config set DISABLE_WP_CRON true --raw >/dev/null
  printf '<?php $upgrading = time();\n' > web/.maintenance
  for REHEARSAL_FAULT in none after_product_commit after_preservation_commit; do
    REHEARSAL_DB="biblio_cutover_$(openssl rand -hex 6)"
    REHEARSAL_PROBE="biblio_cutover_$(openssl rand -hex 6)"
    # Both names are generated here; no existing database is dropped or reused.
    ddev mysql -uroot -proot -e "CREATE DATABASE \`$REHEARSAL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE \`$REHEARSAL_PROBE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$REHEARSAL_DB\`.* TO 'db'@'%'; GRANT ALL ON \`$REHEARSAL_PROBE\`.* TO 'db'@'%';" >/dev/null
    test "$(ddev exec env DB_NAME="$REHEARSAL_DB" wp --path=/var/www/html/web db query 'SELECT DATABASE()' --skip-column-names)" = "$REHEARSAL_DB"
    ddev exec env DB_NAME="$REHEARSAL_DB" wp --path=/var/www/html/web core install \
      --url="https://$REHEARSAL_PROJECT.ddev.site" --title=Synthetic --admin_user=synthetic_setup \
      --admin_password=synthetic-disposable-only --admin_email=synthetic@example.invalid --skip-email >/dev/null
    ddev exec env DB_NAME="$REHEARSAL_DB" wp --path=/var/www/html/web plugin activate biblio-core >/dev/null
    ddev exec env DB_NAME="$REHEARSAL_DB" BIBLIO_REHEARSAL_PROBE="$REHEARSAL_PROBE" BIBLIO_REHEARSAL_FAULT="$REHEARSAL_FAULT" \
      wp --path=/var/www/html/web eval-file --use-include /var/www/html/web/wp-content/plugins/biblio-core/tests/Rehearsal/acceptance.php
  done
  ddev stop >/dev/null
)
./scripts/migration-trial.sh normal-fingerprint > "$REHEARSAL_ROOT/normal-after.json"
cmp "$REHEARSAL_ROOT/normal-before.json" "$REHEARSAL_ROOT/normal-after.json"
test -z "$(git status --porcelain)"
printf 'SYNTHETIC_PREP01B_EVIDENCE=%s\n' "$REHEARSAL_ROOT"
printf 'FINAL REHEARSAL NOT RUN; PRODUCTION APPLY NOT AUTHORIZED\n'
