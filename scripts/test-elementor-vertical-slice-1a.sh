#!/usr/bin/env bash

set -euo pipefail

STEP9_PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STEP9_DB_NAME="biblio_elementor_step9_import_test"
STEP9_URL="https://biblio-step9-import.invalid"
STEP9_ARTIFACT="${STEP9_PROJECT_ROOT}/config/elementor/vertical-slice-1a/"
STEP9_ARTIFACT+="biblio-vertical-slice-1a.zip"
STEP9_VERIFY="/var/www/html/scripts/verify-elementor-vertical-slice-1a.php"
STEP9_DB_CREATED=0
STEP9_UPLOAD_DIR=""

if [[ "${STEP9_DB_NAME}" != "biblio_elementor_step9_import_test" ]]; then
    echo "Refusing an unexpected database target." >&2
    exit 1
fi

cleanup() {
    if [[ "${STEP9_DB_CREATED}" == "1" ]]; then
        ddev mysql -e "DROP DATABASE \`${STEP9_DB_NAME}\`" >/dev/null
    fi

    if [[ -n "${STEP9_UPLOAD_DIR}" \
        && "${STEP9_UPLOAD_DIR}" == "${STEP9_PROJECT_ROOT}/.local/"* ]]; then
        rm -rf "${STEP9_UPLOAD_DIR}"
    fi
}

trap cleanup EXIT

cd "${STEP9_PROJECT_ROOT}"

if [[ ! -f "${STEP9_ARTIFACT}" ]]; then
    echo "Elementor artifact is missing: ${STEP9_ARTIFACT}" >&2
    exit 1
fi

STEP9_DDEV_DESCRIPTION="$(ddev describe -j)"
STEP9_DDEV_NAME="$(jq -r '.raw.name' <<<"${STEP9_DDEV_DESCRIPTION}")"
STEP9_DDEV_STATUS="$(jq -r '.raw.status' <<<"${STEP9_DDEV_DESCRIPTION}")"
STEP9_DDEV_URL="$(jq -r '.raw.primary_url' <<<"${STEP9_DDEV_DESCRIPTION}")"

if [[ "${STEP9_DDEV_NAME}" != "biblio-v2" \
    || "${STEP9_DDEV_STATUS}" != "running" \
    || "${STEP9_DDEV_URL}" != "https://biblio-v2.ddev.site" ]]; then
    echo "Refusing to run outside the local biblio-v2 DDEV runtime." >&2
    exit 1
fi

if [[ -n "$(ddev mysql -N -e "SHOW DATABASES LIKE '${STEP9_DB_NAME}'")" ]]; then
    echo "Refusing to reuse existing database ${STEP9_DB_NAME}." >&2
    exit 1
fi

unzip -t "${STEP9_ARTIFACT}" >/dev/null
mkdir -p "${STEP9_PROJECT_ROOT}/.local"
STEP9_UPLOAD_DIR="$(mktemp -d \
    "${STEP9_PROJECT_ROOT}/.local/step9-import-uploads.XXXXXX")"
STEP9_UPLOAD_CONTAINER="/var/www/html/.local/$(basename "${STEP9_UPLOAD_DIR}")"

ddev mysql -e "CREATE DATABASE \`${STEP9_DB_NAME}\` CHARACTER SET utf8mb4 \
COLLATE utf8mb4_unicode_ci"
STEP9_DB_CREATED=1
ddev mysql -e "GRANT ALL PRIVILEGES ON \`${STEP9_DB_NAME}\`.* TO 'db'@'%'"

step9_wp() {
    ddev exec env \
        DB_NAME="${STEP9_DB_NAME}" \
        DDEV_PRIMARY_URL="${STEP9_URL}" \
        wp --path=/var/www/html/web "$@"
}

STEP9_ADMIN_PASSWORD="step9-${RANDOM}-${RANDOM}-${RANDOM}"
step9_wp core install \
    --url="${STEP9_URL}" \
    --title="Biblio Step 9 Import" \
    --admin_user=biblio_step9 \
    --admin_password="${STEP9_ADMIN_PASSWORD}" \
    --admin_email=step9@example.invalid \
    --skip-email
unset STEP9_ADMIN_PASSWORD

step9_wp site empty --yes
step9_wp option update permalink_structure '/%postname%/'
step9_wp option update upload_path "${STEP9_UPLOAD_CONTAINER}"
step9_wp option update upload_url_path "${STEP9_URL}/uploads"
step9_wp rewrite flush
step9_wp theme activate twentytwentyfive
step9_wp plugin activate elementor elementor-pro biblio-core biblio-ui

step9_wp elementor kit import \
    /var/www/html/config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip \
    --include=content,settings \
    --user=1
step9_wp elementor flush-css --regenerate

[[ "$(step9_wp plugin get elementor --field=version)" == "4.2.3" ]]
[[ "$(step9_wp plugin get elementor-pro --field=version)" == "4.2.2" ]]
[[ "$(step9_wp plugin get biblio-core --field=version)" == "2.1.0" ]]
[[ "$(step9_wp plugin get biblio-ui --field=version)" == "0.2.0" ]]

step9_wp eval-file "${STEP9_VERIFY}" page --user=1
step9_wp eval-file "${STEP9_VERIFY}" assets-off --user=1

echo "OK: Elementor Vertical Slice 1a clean import test passed."
