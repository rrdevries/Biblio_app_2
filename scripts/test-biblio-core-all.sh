#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_DIRECTORY="web/wp-content/plugins/biblio-core"

for command_name in cmp curl ddev diff git jq mktemp; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "FOUT: vereiste command ontbreekt: $command_name" >&2
    exit 1
  fi
done

STATUS_BEFORE="$(mktemp)"
STATUS_AFTER="$(mktemp)"
STARTED_AT="$SECONDS"

git status --porcelain=v1 --untracked-files=all > "$STATUS_BEFORE"

section() {
  printf '\n== %s ==\n' "$1"
}

verify_repository_state() {
  local exit_code="$?"

  trap - EXIT
  git status --porcelain=v1 --untracked-files=all > "$STATUS_AFTER"

  if ! cmp -s "$STATUS_BEFORE" "$STATUS_AFTER"; then
    printf '\nFOUT: de quality gate heeft de zichtbare repositorystatus gewijzigd.\n' >&2
    diff -u "$STATUS_BEFORE" "$STATUS_AFTER" >&2 || true
    exit_code=1
  fi

  rm -f "$STATUS_BEFORE" "$STATUS_AFTER"
  exit "$exit_code"
}

trap verify_repository_state EXIT

if [ ! -f "$PLUGIN_DIRECTORY/vendor/autoload.php" ]; then
  echo "FOUT: Biblio Core Composer-dependencies ontbreken." >&2
  echo "Voer uit: ddev composer --working-dir=$PLUGIN_DIRECTORY install" >&2
  exit 1
fi

if [ ! -x "$PLUGIN_DIRECTORY/vendor/bin/phpstan" ]; then
  echo "FOUT: PHPStan ontbreekt uit de gelockte Composer-dependencies." >&2
  echo "Voer uit: ddev composer --working-dir=$PLUGIN_DIRECTORY install" >&2
  exit 1
fi

section "DDEV"
ddev start >/dev/null
export BIBLIO_DDEV_STARTED=1
echo "OK: DDEV is beschikbaar."

section "Composer metadata"
ddev composer --working-dir="$PLUGIN_DIRECTORY" validate --strict --no-check-publish

section "Composer platform requirements"
ddev composer --working-dir="$PLUGIN_DIRECTORY" check-platform-reqs --lock

section "PHP syntax"
ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  sh -lc 'find . -path ./vendor -prune -o -type f -name "*.php" -print0 | xargs -0 -n1 php -l'

section "PHPStan"
ddev exec \
  --dir "/var/www/html/$PLUGIN_DIRECTORY" \
  vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress

section "Unit suite"
./scripts/test-biblio-core-unit.sh

section "Integration suite"
./scripts/test-biblio-core-integration.sh

section "WordPress smoke"
./scripts/test-biblio-core-smoke.sh

section "Manifest JSON"
jq empty manifest.json
echo "OK: manifest.json is geldige JSON."

section "Git whitespace"
git diff --check
git diff --cached --check
echo "OK: git diff --check is schoon."

section "Quality gate"
echo "OK: volledige Biblio Core quality gate geslaagd in $((SECONDS - STARTED_AT)) seconden."
