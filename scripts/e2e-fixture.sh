#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

readonly ACTION="${1:-}"
readonly ENV_FILE=".local/e2e.env"

case "$ACTION" in
  setup|cleanup|verify-clean|conflict-reset|conflict-activate|stale-end|note-stale-update|note-stale-delete|note-unavailable-delete|next-reading-reset|state|fingerprint) ;;
  *)
    echo "Usage: $0 {setup|cleanup|verify-clean|conflict-reset|conflict-activate|stale-end|note-stale-update|note-stale-delete|note-unavailable-delete|next-reading-reset|state|fingerprint}" >&2
    exit 64
    ;;
esac

mkdir -p .local

if [[ ! -f "$ENV_FILE" ]]; then
  umask 077
  {
    echo 'BIBLIO_E2E_BASE_URL=https://biblio-v2.ddev.site'
    echo 'BIBLIO_E2E_ACTOR_USERNAME=biblio_e2e_actor'
    echo "BIBLIO_E2E_ACTOR_PASSWORD=$(openssl rand -hex 32)"
    echo 'BIBLIO_E2E_OTHER_USERNAME=biblio_e2e_other'
    echo "BIBLIO_E2E_OTHER_PASSWORD=$(openssl rand -hex 32)"
  } > "$ENV_FILE"
  chmod 600 "$ENV_FILE"
fi

ddev exec --raw -- \
  bash /var/www/html/scripts/e2e-container-fixture.sh "$ACTION"
