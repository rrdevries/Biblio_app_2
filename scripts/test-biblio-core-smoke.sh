#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${BIBLIO_DDEV_STARTED:-0}" != "1" ]; then
  ddev start >/dev/null
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

HTTP_STATUS="$(curl -s -o /dev/null -w "%{http_code}" https://biblio-v2.ddev.site)"
if [ "$HTTP_STATUS" != "200" ]; then
  echo "FOUT: lokale Biblio-site geeft HTTP $HTTP_STATUS."
  exit 1
fi

echo "OK: Biblio Core smoke-test geslaagd."
echo "Plugin: active"
echo "Class: loaded"
echo "Init hook: $HOOK_COUNT"
echo "HTTP: $HTTP_STATUS"
