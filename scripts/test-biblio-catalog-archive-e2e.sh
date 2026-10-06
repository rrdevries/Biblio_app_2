#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
cleanup() { ./scripts/account-e2e-fixture.sh cleanup; }
trap cleanup EXIT
cleanup
catalog_before="$(./scripts/account-e2e-fixture.sh fingerprint)"
echo "Core/account fingerprint before: $catalog_before"
./scripts/account-e2e-fixture.sh setup
npx playwright test --config playwright.account.config.mjs catalog-archive.spec.mjs
cleanup
cleanup
catalog_after="$(./scripts/account-e2e-fixture.sh fingerprint)"
echo "Core/account fingerprint after: $catalog_after"
test "$catalog_before" = "$catalog_after"
trap - EXIT
echo "Catalogus archive E2E: both stored preferences checked; fixture cleanup idempotent; existing Core/account fingerprint unchanged."
