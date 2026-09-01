#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

cleanup() {
  ./scripts/e2e-fixture.sh cleanup
  ./scripts/e2e-fixture.sh cleanup
}

trap cleanup EXIT
./scripts/test-biblio-e2e-fixture-guards.sh
cleanup
./scripts/e2e-fixture.sh verify-clean
BEFORE_FINGERPRINT="$(./scripts/e2e-fixture.sh fingerprint)"
echo "Biblio E2E non-fixture fingerprint before: $BEFORE_FINGERPRINT"
./scripts/e2e-fixture.sh setup
npx playwright test
cleanup
trap - EXIT
./scripts/e2e-fixture.sh verify-clean
AFTER_FINGERPRINT="$(./scripts/e2e-fixture.sh fingerprint)"
echo "Biblio E2E non-fixture fingerprint after: $AFTER_FINGERPRINT"

if [[ "$BEFORE_FINGERPRINT" != "$AFTER_FINGERPRINT" ]]; then
  echo "Biblio E2E non-fixture fingerprint changed." >&2
  exit 1
fi

echo "Biblio E2E cleanup is idempotent and the non-fixture fingerprint is unchanged."
