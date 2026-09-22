#!/usr/bin/env bash
# Dirty-candidate quality acceptance in a separate disposable DDEV project.
# A separately captured immutable normal-DB baseline must already exist.
set -euo pipefail
umask 077
cd "$(dirname "$0")/.."
test "$#" -eq 1 || { echo "Usage: $0 <fresh-cycle-directory>" >&2; exit 1; }
PRECOMMIT_REPO="$(pwd -P)"
PRECOMMIT_CYCLE="$1"
[[ "$PRECOMMIT_CYCLE" == "$PRECOMMIT_REPO/.local/cutover-prep01b-fresh-"* ]] || exit 1
test "$(cd "$PRECOMMIT_CYCLE" && pwd -P)" = "$PRECOMMIT_CYCLE"
test -f "$PRECOMMIT_CYCLE/normal-before.json.sha256"
test ! -e "$PRECOMMIT_CYCLE/app"
PRECOMMIT_NONCE="$(openssl rand -hex 6)"
PRECOMMIT_PROJECT="biblio-v2-cutover-$PRECOMMIT_NONCE"
git clone --quiet --no-hardlinks "$PRECOMMIT_REPO" "$PRECOMMIT_CYCLE/app"
git -C "$PRECOMMIT_CYCLE/app" checkout --quiet --detach "$(git rev-parse HEAD)"
# Copy only tracked/unignored candidate files, not normal DB/config/source/media.
git ls-files -z --cached --others --exclude-standard | rsync -a --from0 --files-from=- "$PRECOMMIT_REPO/" "$PRECOMMIT_CYCLE/app/"
rsync -a --exclude='wp-config*.php' --exclude=wp-content --exclude=.maintenance \
  --include='wp-admin/***' --include='wp-includes/***' --include='/*.php' --exclude='*' \
  "$PRECOMMIT_REPO/web/" "$PRECOMMIT_CYCLE/app/web/"
rsync -a "$PRECOMMIT_REPO/web/wp-content/plugins/biblio-core/vendor/" "$PRECOMMIT_CYCLE/app/web/wp-content/plugins/biblio-core/vendor/"
mkdir -p "$PRECOMMIT_CYCLE/app/web/wp-content/themes"
test -d "$PRECOMMIT_REPO/web/wp-content/themes/twentytwentyfive"
rsync -a "$PRECOMMIT_REPO/web/wp-content/themes/twentytwentyfive" "$PRECOMMIT_CYCLE/app/web/wp-content/themes/"
printf 'name: %s\nweb_environment:\n  - WP_ENVIRONMENT_TYPE=local\n  - BIBLIO_REHEARSAL=1\n' "$PRECOMMIT_PROJECT" > "$PRECOMMIT_CYCLE/app/.ddev/config.cutover.local.yaml"
(
  cd "$PRECOMMIT_CYCLE/app"
  test "$(ddev describe --json-output | jq -r 'if (.raw|type)=="array" then .raw[0].name else .raw.name end')" = "$PRECOMMIT_PROJECT"
  ddev start >/dev/null
  test "$(ddev exec printenv DDEV_SITENAME)" = "$PRECOMMIT_PROJECT"
  test "$(ddev wp db query 'SELECT DATABASE()' --skip-column-names)" = "db"
  ddev wp core install --url="https://$PRECOMMIT_PROJECT.ddev.site" --title=Synthetic \
    --admin_user=synthetic_setup --admin_password=synthetic-disposable-only \
    --admin_email=synthetic@example.invalid --skip-email >/dev/null
  ddev wp plugin activate biblio-core >/dev/null
  ddev wp theme activate twentytwentyfive >/dev/null
  export BIBLIO_DDEV_STARTED=1 BIBLIO_ISOLATED_ACCEPTANCE=1
  ddev exec --dir /var/www/html/web/wp-content/plugins/biblio-core \
    vendor/bin/phpunit --configuration=phpunit.xml --filter=Rehearsal --display-phpunit-notices
  ./scripts/test-biblio-core-integration.sh --filter Rehearsal
  ./scripts/test-biblio-core-all.sh
  ddev stop >/dev/null
)
printf 'ISOLATED_PRECOMMIT_PROJECT=%s\nISOLATED_PRECOMMIT_PATH=%s\n' "$PRECOMMIT_PROJECT" "$PRECOMMIT_CYCLE/app"
