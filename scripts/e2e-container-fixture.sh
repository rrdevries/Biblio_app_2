#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html
set -a
# shellcheck disable=SC1091
source .local/e2e.env
set +a
export BIBLIO_E2E_ALLOW_FIXTURES=1

cd /var/www/html/web
wp eval-file /var/www/html/e2e/fixture.php "${1:-}"
