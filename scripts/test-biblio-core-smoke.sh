#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${BIBLIO_DDEV_STARTED:-0}" != "1" ]; then
  ddev start >/dev/null
fi

SMOKE_URL="https://biblio-v2.ddev.site"
if [ "${BIBLIO_ISOLATED_ACCEPTANCE:-0}" = "1" ]; then
  SMOKE_PROJECT="$(ddev exec printenv DDEV_SITENAME)"
  [[ "$SMOKE_PROJECT" =~ ^biblio-v2-cutover-[a-f0-9]{12}$ ]] || { echo "FOUT: geen disposable smoke-project." >&2; exit 1; }
  test "$(ddev exec printenv BIBLIO_REHEARSAL)" = "1"
  SMOKE_URL="$(ddev describe --json-output | jq -r 'if (.raw|type)=="array" then .raw[0].primary_url else .raw.primary_url end')"
  test "$SMOKE_URL" = "https://$SMOKE_PROJECT.ddev.site"
fi

STATUS="$(ddev wp plugin get biblio-core --field=status)"
if [ "$STATUS" != "active" ]; then
  echo "FOUT: Biblio Core is niet actief."
  exit 1
fi

CLASS_RESULT="$(ddev wp eval 'echo class_exists("Biblio\\Core\\Plugin") ? "OK" : "FOUT";')"
if [ "$CLASS_RESULT" != "OK" ]; then
  echo "FOUT: Biblio\\Core\\Plugin kan niet worden geladen."
  exit 1
fi

HOOK_COUNT="$(ddev wp eval 'echo did_action("biblio_core_initialized");')"
if [ "$HOOK_COUNT" -lt 1 ]; then
  echo "FOUT: biblio_core_initialized is niet uitgevoerd."
  exit 1
fi

HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" "$SMOKE_URL")"
if [ "$HTTP_STATUS" != "200" ]; then
  echo "FOUT: lokale Biblio-site geeft HTTP $HTTP_STATUS."
  exit 1
fi

echo "OK: Biblio Core smoke-test geslaagd."
echo "Plugin: active"
echo "Class: loaded"
echo "Init hook: $HOOK_COUNT"
echo "HTTP: $HTTP_STATUS"
