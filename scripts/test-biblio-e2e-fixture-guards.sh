#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .local/e2e.env ]]; then
  ./scripts/e2e-fixture.sh verify-clean >/dev/null
fi

run_refusal() {
  local label="$1"
  local expected="$2"
  local override="$3"
  local action="${4:-verify-clean}"
  local output
  local status

  set +e
  output="$(ddev exec --raw -- bash -lc "cd /var/www/html; set -a; source .local/e2e.env; set +a; ${override}; cd web; wp eval-file /var/www/html/e2e/fixture.php ${action}" 2>&1)"
  status=$?
  set -e

  if [[ $status -eq 0 || "$output" != *"$expected"* ]]; then
    echo "FAIL: fixture guard did not refuse ${label}." >&2
    echo "$output" >&2
    exit 1
  fi

  echo "PASS: fixture guard refused ${label}."
}

run_refusal \
  "missing opt-in" \
  "explicit fixture opt-in is missing" \
  "unset BIBLIO_E2E_ALLOW_FIXTURES"
run_refusal \
  "non-local WordPress" \
  "WordPress environment must be exactly local" \
  "export BIBLIO_E2E_ALLOW_FIXTURES=1; export WP_ENVIRONMENT_TYPE=production"
run_refusal \
  "wrong DDEV project" \
  "runtime is not the exact DDEV project" \
  "export BIBLIO_E2E_ALLOW_FIXTURES=1; export DDEV_PROJECT=not-biblio-v2"
run_refusal \
  "wrong local host" \
  "runtime URL is not the exact local DDEV host" \
  "export BIBLIO_E2E_ALLOW_FIXTURES=1; export DDEV_PRIMARY_URL=https://not-biblio-v2.ddev.site"
run_refusal \
  "cleanup against a non-E2E username" \
  "formal fixture usernames must remain exact" \
  "export BIBLIO_E2E_ALLOW_FIXTURES=1; export BIBLIO_E2E_ACTOR_USERNAME=biblio_dev" \
  "cleanup"
