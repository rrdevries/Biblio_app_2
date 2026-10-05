#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
case "${1:-}" in setup|setup-mail-pending|cleanup|state|state-mail-pending|fingerprint) ;; *) exit 64 ;; esac
ddev exec --raw -- bash /var/www/html/scripts/account-e2e-container-fixture.sh "$1"
