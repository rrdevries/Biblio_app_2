#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/test-biblio-core-unit.sh
./scripts/test-biblio-core-integration.sh
