#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

cleanup() {
  ./scripts/e2e-fixture.sh cleanup
}

trap cleanup EXIT
./scripts/e2e-fixture.sh setup
npx playwright test
