#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
set -a
source .local/e2e.env
set +a
export BIBLIO_E2E_ALLOW_FIXTURES=1
wp --path=/var/www/html/web eval-file /var/www/html/e2e/account-fixture.php "${1:-}"
