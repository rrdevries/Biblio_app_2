#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
cleanup() { ./scripts/account-e2e-fixture.sh cleanup; }
trap cleanup EXIT
cleanup
before="$(./scripts/account-e2e-fixture.sh fingerprint)"
./scripts/account-e2e-fixture.sh setup
npx playwright test --config playwright.account.config.mjs settings.spec.mjs
cleanup
cleanup
after="$(./scripts/account-e2e-fixture.sh fingerprint)"
test "$before" = "$after"
trap - EXIT
echo "Settings E2E: fixture cleanup is idempotent; all pre-existing Core records and accounts unchanged."
