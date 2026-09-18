#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

REPO_ROOT="$(pwd -P)"
TRIAL_HOME="$REPO_ROOT/.local/migration-trial"
TRIAL_APP_ROOT="$TRIAL_HOME/worktree"
TRIAL_LOCAL_ROOT="$TRIAL_APP_ROOT/.local/migration-trial"
TRIAL_STATE_FILE="$TRIAL_LOCAL_ROOT/state/trial.env"
TRIAL_TARGET_FILE="$TRIAL_LOCAL_ROOT/state/target.json"
TRIAL_SECRET_FILE="$TRIAL_LOCAL_ROOT/state/admin-secret.env"
TRIAL_SOURCE_ROOT="$TRIAL_APP_ROOT/.local/migration-source/current"
TRIAL_ARTIFACT_ROOT="$TRIAL_LOCAL_ROOT/artifacts"
TRIAL_BACKUP_ROOT="$TRIAL_LOCAL_ROOT/backups"
TRIAL_EVIDENCE_ROOT="$TRIAL_LOCAL_ROOT/evidence"
TRIAL_PROJECT="biblio-v2-migration-trial"
TRIAL_DATABASE="biblio_migration_trial"
TRIAL_URL="https://biblio-v2-migration-trial.ddev.site"
NORMAL_PROJECT="biblio-v2"
NORMAL_DATABASE="db"
EXPECTED_SCHEMA="1026"
EXPECTED_CORE_VERSION="2.48.0"
EXPECTED_UI_VERSION="0.20.0"
EXPECTED_WORDPRESS_VERSION="7.0.2"
TRIAL_TARGET_LOGIN="migration-trial-target"
TRIAL_TARGET_EMAIL="migration-trial-target@example.invalid"
TRIAL_TARGET_DISPLAY_NAME="Migration Trial Target"
MARKER_TABLE="biblio_migration_trial_guard"
RESTORE_PROBE_OPTION="biblio_migration_trial_restore_probe"
DESTRUCTIVE_CONFIRMATION=""
LAST_BACKUP_PATH=""

# shellcheck source=scripts/lib/migration-trial-guard.sh
source "$REPO_ROOT/scripts/lib/migration-trial-guard.sh"

usage() {
  cat <<'EOF'
MIG-02-OPS-01 isolated migration trial tooling

Usage:
  scripts/migration-trial.sh prepare
  scripts/migration-trial.sh recreate --confirm-trial-destruction
  scripts/migration-trial.sh validate
  scripts/migration-trial.sh backup
  scripts/migration-trial.sh mutate --confirm-trial-destruction [token]
  scripts/migration-trial.sh restore --confirm-trial-destruction <backup.sql.gz>
  scripts/migration-trial.sh cycle --confirm-trial-destruction
  scripts/migration-trial.sh normal-fingerprint
  scripts/migration-trial.sh paths

Raw ddev import-db, snapshot restore, DROP DATABASE and reset commands are not
operator interfaces for this trial. Use only the guarded commands above.
EOF
}

trial_ddev() {
  (
    cd "$TRIAL_APP_ROOT"
    ddev "$@"
  )
}

normal_ddev() {
  (
    cd "$REPO_ROOT"
    ddev "$@"
  )
}

state_value() {
  local key="$1"
  awk -F= -v wanted="$key" '$1 == wanted { print substr($0, index($0, "=") + 1); exit }' "$TRIAL_STATE_FILE"
}

write_state() {
  local trial_id="$1"
  local git_sha="$2"
  local target_user_id="${3:-}"
  local target_library_id="${4:-}"

  mkdir -p "$(dirname "$TRIAL_STATE_FILE")"
  {
    printf 'TRIAL_ID=%s\n' "$trial_id"
    printf 'TRIAL_GIT_SHA=%s\n' "$git_sha"
    printf 'TRIAL_PROJECT=%s\n' "$TRIAL_PROJECT"
    printf 'TRIAL_DATABASE=%s\n' "$TRIAL_DATABASE"
    printf 'TRIAL_URL=%s\n' "$TRIAL_URL"
    printf 'TARGET_USER_ID=%s\n' "$target_user_id"
    printf 'TARGET_LIBRARY_ID=%s\n' "$target_library_id"
  } > "$TRIAL_STATE_FILE"
  chmod 600 "$TRIAL_STATE_FILE"
}

canonical_existing_path() {
  local path="$1"
  local directory
  local base
  directory="$(dirname "$path")"
  base="$(basename "$path")"
  [ -d "$directory" ] || migration_trial_fail "map bestaat niet: $directory" || return
  printf '%s/%s\n' "$(cd "$directory" && pwd -P)" "$base"
}

describe_value() {
  local field="$1"
  trial_ddev describe --json-output \
    | jq -r --arg field "$field" '
        if (.raw | type) == "array" then .raw[0][$field]
        else .raw[$field]
        end // empty
      '
}

marker_value() {
  local column="$1"
  trial_ddev mysql -N -s -D "$TRIAL_DATABASE" \
    -e "SELECT \`$column\` FROM \`$MARKER_TABLE\` WHERE singleton=1" \
    2>/dev/null
}

require_state() {
  [ -f "$TRIAL_STATE_FILE" ] \
    || migration_trial_fail "trial state ontbreekt; voer prepare uit." || return
}

require_trial_guard() {
  local expected_trial_id
  local expected_git_sha
  local actual_root
  local actual_project
  local actual_url
  local configured_database
  local selected_database
  local environment_marker
  local environment_trial_id
  local database_trial_id
  local database_project
  local database_name
  local database_purpose
  local database_git_sha
  local actual_git_sha
  local dirty_state="clean"

  require_state || return
  expected_trial_id="$(state_value TRIAL_ID)"
  expected_git_sha="$(state_value TRIAL_GIT_SHA)"
  [ "$(state_value TRIAL_PROJECT)" = "$TRIAL_PROJECT" ] \
    || migration_trial_fail "lokale state bevat verkeerd project." || return
  [ "$(state_value TRIAL_DATABASE)" = "$TRIAL_DATABASE" ] \
    || migration_trial_fail "lokale state bevat verkeerde database." || return
  [ "$(state_value TRIAL_URL)" = "$TRIAL_URL" ] \
    || migration_trial_fail "lokale state bevat verkeerde URL." || return

  actual_root="$(describe_value approot)"
  actual_root="$(cd "$actual_root" && pwd -P)"
  actual_project="$(describe_value name)"
  actual_url="$(describe_value primary_url)"
  configured_database="$(trial_ddev exec printenv DB_NAME)"
  selected_database="$(trial_ddev exec wp --path=web db query 'SELECT DATABASE()' --skip-column-names)"
  environment_marker="$(trial_ddev exec printenv BIBLIO_MIGRATION_TRIAL)"
  environment_trial_id="$(trial_ddev exec printenv BIBLIO_MIGRATION_TRIAL_ID)"
  database_trial_id="$(marker_value trial_id)"
  database_project="$(marker_value project_name)"
  database_name="$(marker_value database_name)"
  database_purpose="$(marker_value purpose)"
  database_git_sha="$(marker_value git_sha)"
  actual_git_sha="$(git -C "$TRIAL_APP_ROOT" rev-parse HEAD)"
  if [ -n "$(git -C "$TRIAL_APP_ROOT" status --porcelain)" ]; then
    dirty_state="dirty"
  fi

  migration_trial_assert_identity \
    "$TRIAL_APP_ROOT" "$actual_root" \
    "$TRIAL_PROJECT" "$actual_project" \
    "$TRIAL_URL" "$actual_url" \
    "$TRIAL_DATABASE" "$configured_database" "$selected_database" \
    "$expected_trial_id" "$environment_marker" "$environment_trial_id" \
    "$database_trial_id" "$database_project" "$database_name" "$database_purpose" "$database_git_sha" \
    "$expected_git_sha" "$actual_git_sha" "$dirty_state"
}

require_destructive_guard() {
  require_trial_guard || return
  migration_trial_assert_confirmation "$DESTRUCTIVE_CONFIRMATION" || return
  echo "Bevestigd trialtarget: project=$TRIAL_PROJECT database=$TRIAL_DATABASE url=$TRIAL_URL"
}

write_trial_config() {
  local trial_id="$1"
  local git_sha="$2"
  local config="$TRIAL_APP_ROOT/.ddev/config.migration-trial.local.yaml"

  {
    printf 'name: %s\n' "$TRIAL_PROJECT"
    printf 'performance_mode: none\n'
    printf 'web_environment:\n'
    printf '  - WP_ENVIRONMENT_TYPE=local\n'
    printf '  - DB_NAME=%s\n' "$TRIAL_DATABASE"
    printf '  - BIBLIO_MIGRATION_TRIAL=1\n'
    printf '  - BIBLIO_MIGRATION_TRIAL_ID=%s\n' "$trial_id"
    printf '  - BIBLIO_TRIAL_EXPECTED_GIT_SHA=%s\n' "$git_sha"
  } > "$config"
}

copy_wordpress_runtime() {
  [ -d "$REPO_ROOT/web/wp-admin" ] || migration_trial_fail "normale WordPress core ontbreekt." || return
  [ -d "$REPO_ROOT/web/wp-includes" ] || migration_trial_fail "normale WordPress includes ontbreken." || return
  [ -f "$REPO_ROOT/web/wp-content/plugins/biblio-core/vendor/autoload.php" ] \
    || migration_trial_fail "Biblio Core dependencies ontbreken." || return

  mkdir -p "$TRIAL_APP_ROOT/web"
  rsync -a "$REPO_ROOT/web/wp-admin/" "$TRIAL_APP_ROOT/web/wp-admin/"
  rsync -a "$REPO_ROOT/web/wp-includes/" "$TRIAL_APP_ROOT/web/wp-includes/"
  rsync -a \
    --exclude='wp-config.php' \
    --exclude='wp-config-ddev.php' \
    --include='*.php' \
    --include='license.txt' \
    --include='readme.html' \
    --exclude='*' \
    "$REPO_ROOT/web/" "$TRIAL_APP_ROOT/web/"
  mkdir -p "$TRIAL_APP_ROOT/web/wp-content/plugins/biblio-core/vendor"
  rsync -a \
    "$REPO_ROOT/web/wp-content/plugins/biblio-core/vendor/" \
    "$TRIAL_APP_ROOT/web/wp-content/plugins/biblio-core/vendor/"
  if [ -d "$REPO_ROOT/web/wp-content/themes/twentytwentyfive" ]; then
    mkdir -p "$TRIAL_APP_ROOT/web/wp-content/themes"
    rsync -a \
      "$REPO_ROOT/web/wp-content/themes/twentytwentyfive/" \
      "$TRIAL_APP_ROOT/web/wp-content/themes/twentytwentyfive/"
  fi
  if [ -d "$REPO_ROOT/web/wp-content/languages" ]; then
    mkdir -p "$TRIAL_APP_ROOT/web/wp-content/languages"
    rsync -a \
      "$REPO_ROOT/web/wp-content/languages/" \
      "$TRIAL_APP_ROOT/web/wp-content/languages/"
  fi
}

assert_bootstrap_environment() {
  local actual_root
  local actual_project
  local actual_url
  local configured_database
  local environment_marker
  local environment_trial_id
  local trial_id

  require_state || return
  trial_id="$(state_value TRIAL_ID)"
  actual_root="$(describe_value approot)"
  actual_root="$(cd "$actual_root" && pwd -P)"
  actual_project="$(describe_value name)"
  actual_url="$(describe_value primary_url)"
  configured_database="$(trial_ddev exec printenv DB_NAME)"
  environment_marker="$(trial_ddev exec printenv BIBLIO_MIGRATION_TRIAL)"
  environment_trial_id="$(trial_ddev exec printenv BIBLIO_MIGRATION_TRIAL_ID)"

  [ "$actual_root" = "$TRIAL_APP_ROOT" ] || migration_trial_fail "bootstrap approot wijkt af." || return
  [ "$actual_project" = "$TRIAL_PROJECT" ] || migration_trial_fail "bootstrap project wijkt af." || return
  [ "$actual_url" = "$TRIAL_URL" ] || migration_trial_fail "bootstrap URL wijkt af." || return
  [ "$configured_database" = "$TRIAL_DATABASE" ] || migration_trial_fail "bootstrap DB_NAME wijkt af." || return
  [ "$configured_database" != "$NORMAL_DATABASE" ] || migration_trial_fail "normale db is verboden." || return
  [ "$environment_marker" = "1" ] || migration_trial_fail "bootstrap marker ontbreekt." || return
  [ "$environment_trial_id" = "$trial_id" ] || migration_trial_fail "bootstrap trial-ID wijkt af." || return
  [ "$(git -C "$TRIAL_APP_ROOT" rev-parse HEAD)" = "$(state_value TRIAL_GIT_SHA)" ] \
    || migration_trial_fail "bootstrap SHA wijkt af." || return
  [ -z "$(git -C "$TRIAL_APP_ROOT" status --porcelain)" ] \
    || migration_trial_fail "bootstrap worktree is niet schoon." || return
}

create_marker_table() {
  local trial_id
  local git_sha
  trial_id="$(state_value TRIAL_ID)"
  git_sha="$(state_value TRIAL_GIT_SHA)"

  trial_ddev mysql -D "$TRIAL_DATABASE" -e \
    "CREATE TABLE \`$MARKER_TABLE\` (singleton TINYINT UNSIGNED NOT NULL PRIMARY KEY, trial_id CHAR(32) NOT NULL, project_name VARCHAR(64) NOT NULL, database_name VARCHAR(64) NOT NULL, purpose VARCHAR(64) NOT NULL, git_sha CHAR(40) NOT NULL, CONSTRAINT trial_guard_singleton CHECK (singleton=1)); INSERT INTO \`$MARKER_TABLE\` (singleton,trial_id,project_name,database_name,purpose,git_sha) VALUES (1,'$trial_id','$TRIAL_PROJECT','$TRIAL_DATABASE','MIG-02-OPS-01','$git_sha');"
}

ensure_admin_secret() {
  mkdir -p "$(dirname "$TRIAL_SECRET_FILE")"
  if [ ! -f "$TRIAL_SECRET_FILE" ]; then
    umask 077
    printf 'TRIAL_ADMIN_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "$TRIAL_SECRET_FILE"
  fi
  chmod 600 "$TRIAL_SECRET_FILE"
}

install_trial_baseline() {
  local admin_password
  local bootstrap_output
  local target_user_id
  local target_library_id
  local trial_id
  local git_sha

  ensure_admin_secret
  admin_password="$(state_value_from "$TRIAL_SECRET_FILE" TRIAL_ADMIN_PASSWORD)"

  printf '%s\n' "$admin_password" | trial_ddev exec wp --path=web core install \
    --url="$TRIAL_URL" \
    --title="Biblio V2 — MIGRATION TRIAL" \
    --admin_user=migration_trial_admin \
    --admin_email=migration-trial-admin@example.invalid \
    --locale=nl_NL \
    --skip-email \
    --prompt=admin_password >/dev/null
  trial_ddev exec wp --path=web option update timezone_string Europe/Amsterdam >/dev/null
  trial_ddev exec wp --path=web option update blogdescription \
    "GEÏSOLEERDE MIGRATIETRIAL — bevat geen normale Biblio-data" >/dev/null
  trial_ddev exec wp --path=web rewrite structure '/%postname%/' >/dev/null
  trial_ddev exec wp --path=web plugin activate biblio-core biblio-ui >/dev/null
  trial_ddev exec wp --path=web post create \
    --post_type=page \
    --post_title='Mijn Bibliotheek — MIGRATION TRIAL' \
    --post_name=mijn-bibliotheek \
    --post_status=publish \
    --post_content='[biblio_library_app]' \
    --porcelain >/dev/null

  bootstrap_output="$(trial_ddev exec wp --path=web biblio identity bootstrap \
    --user-login="$TRIAL_TARGET_LOGIN" \
    --user-email="$TRIAL_TARGET_EMAIL" \
    --display-name="$TRIAL_TARGET_DISPLAY_NAME")"
  printf '%s\n' "$bootstrap_output" > "$TRIAL_TARGET_FILE"
  jq -e . "$TRIAL_TARGET_FILE" >/dev/null
  target_user_id="$(jq -r '.target_user_id' "$TRIAL_TARGET_FILE")"
  target_library_id="$(jq -r '.target_library_id' "$TRIAL_TARGET_FILE")"
  trial_id="$(state_value TRIAL_ID)"
  git_sha="$(state_value TRIAL_GIT_SHA)"
  write_state "$trial_id" "$git_sha" "$target_user_id" "$target_library_id"
}

state_value_from() {
  local file="$1"
  local key="$2"
  awk -F= -v wanted="$key" '$1 == wanted { print substr($0, index($0, "=") + 1); exit }' "$file"
}

prepare_trial() {
  local git_sha
  local trial_id
  local database_exists

  [ -z "$(git status --porcelain)" ] \
    || migration_trial_fail "prepare vereist een schone hoofdworktree." || return
  [ ! -e "$TRIAL_APP_ROOT" ] \
    || migration_trial_fail "trialworktree bestaat al; gebruik validate of guarded recreate." || return
  git_sha="$(git rev-parse HEAD)"
  trial_id="$(openssl rand -hex 16)"

  mkdir -p "$TRIAL_HOME"
  git worktree add --detach "$TRIAL_APP_ROOT" "$git_sha"
  copy_wordpress_runtime
  write_trial_config "$trial_id" "$git_sha"
  write_state "$trial_id" "$git_sha"
  mkdir -p "$TRIAL_ARTIFACT_ROOT" "$TRIAL_BACKUP_ROOT" "$TRIAL_EVIDENCE_ROOT"

  trial_ddev start
  assert_bootstrap_environment
  database_exists="$(trial_ddev mysql -N -s -e \
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$TRIAL_DATABASE'")"
  [ "$database_exists" = "0" ] \
    || migration_trial_fail "onbekende bestaande trialdatabase wordt niet overgenomen." || return

  trial_ddev mysql -e \
    "CREATE DATABASE \`$TRIAL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$TRIAL_DATABASE\`.* TO 'db'@'%';"
  create_marker_table
  install_trial_baseline
  validate_trial
}

payload_recreate_database() {
  trial_ddev mysql -e \
    "DROP DATABASE \`$TRIAL_DATABASE\`; CREATE DATABASE \`$TRIAL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$TRIAL_DATABASE\`.* TO 'db'@'%';"
  create_marker_table
  install_trial_baseline
}

recreate_trial() {
  migration_trial_execute_guarded require_destructive_guard payload_recreate_database
  validate_trial
}

all_biblio_counts() {
  trial_ddev mysql -N -s -D "$TRIAL_DATABASE" -e \
    "SET SESSION group_concat_max_len=1000000; SELECT GROUP_CONCAT(CONCAT('SELECT ', QUOTE(table_name), ' AS table_name, COUNT(*) AS row_count FROM ', CHAR(96), table_name, CHAR(96)) ORDER BY table_name SEPARATOR ' UNION ALL ') INTO @trial_count_sql FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'wp_biblio_%'; PREPARE trial_count_stmt FROM @trial_count_sql; EXECUTE trial_count_stmt; DEALLOCATE PREPARE trial_count_stmt;"
}

assert_trial_table_semantics() {
  local counts
  local disallowed
  local table_count
  local trial_id
  local target_user_id
  local target_library_id

  counts="$(all_biblio_counts)"
  table_count="$(printf '%s\n' "$counts" | awk 'NF == 2 { count++ } END { print count + 0 }')"
  [ "$table_count" = "57" ] || migration_trial_fail "verwacht 57 Biblio-tabellen, vond $table_count." || return

  disallowed="$(printf '%s\n' "$counts" | awk '
    $2 != 0 && $1 != "wp_biblio_libraries" &&
    $1 != "wp_biblio_library_memberships" &&
    $1 != "wp_biblio_personal_library_designations" &&
    $1 != "wp_biblio_library_book_types" &&
    $1 != "wp_biblio_library_genres" &&
    $1 != "wp_biblio_library_subjects" { print }
  ')"
  [ -z "$disallowed" ] \
    || migration_trial_fail "trialbaseline bevat onverwachte Biblio-rijen: $disallowed" || return
  [ "$(printf '%s\n' "$counts" | awk '$1 == "wp_biblio_libraries" { print $2 }')" = "1" ] \
    || migration_trial_fail "trialbaseline moet exact één Library bevatten." || return
  [ "$(printf '%s\n' "$counts" | awk '$1 == "wp_biblio_library_memberships" { print $2 }')" = "1" ] \
    || migration_trial_fail "trialbaseline moet exact één membership bevatten." || return
  [ "$(printf '%s\n' "$counts" | awk '$1 == "wp_biblio_personal_library_designations" { print $2 }')" = "1" ] \
    || migration_trial_fail "trialbaseline moet exact één designation bevatten." || return

  trial_id="$(state_value TRIAL_ID)"
  target_user_id="$(state_value TARGET_USER_ID)"
  target_library_id="$(state_value TARGET_LIBRARY_ID)"
  [ -n "$trial_id" ] && [ -n "$target_user_id" ] && [ -n "$target_library_id" ] \
    || migration_trial_fail "expliciete trialidentity ontbreekt." || return
  [ "$(trial_ddev mysql -N -s -D "$TRIAL_DATABASE" -e "SELECT COUNT(*) FROM wp_biblio_migration_runs WHERE target_user_id='$target_user_id' OR target_library_id='$target_library_id'")" = "0" ] \
    || migration_trial_fail "trialbaseline bevat MIG-FND runstate." || return
}

validate_trial() {
  local target_user_id
  local target_library_id
  local identity_json
  local schema
  local core_version
  local ui_version
  local wp_version
  local site_url
  local page_status
  local http_code

  require_trial_guard
  target_user_id="$(state_value TARGET_USER_ID)"
  target_library_id="$(state_value TARGET_LIBRARY_ID)"
  [ -n "$target_user_id" ] && [ -n "$target_library_id" ] \
    || migration_trial_fail "target IDs ontbreken." || return

  identity_json="$(trial_ddev exec wp --path=web biblio identity validate \
    --target-user-id="$target_user_id" \
    --target-library-id="$target_library_id" \
    --require-empty)"
  printf '%s\n' "$identity_json" | jq -e '
    .target_user_id != "" and
    .target_library_id != "" and
    .membership_status == "active" and
    .management_role == "owner" and
    .use_access == "direct" and
    .wordpress_roles == ["subscriber"] and
    .super_admin == false and
    .cleanliness == "empty" and
    ([.content_counts[]] | add) == 0
  ' >/dev/null

  schema="$(trial_ddev exec wp --path=web option get biblio_core_schema_version)"
  core_version="$(trial_ddev exec wp --path=web plugin get biblio-core --field=version)"
  ui_version="$(trial_ddev exec wp --path=web plugin get biblio-ui --field=version)"
  wp_version="$(trial_ddev exec wp --path=web core version)"
  site_url="$(trial_ddev exec wp --path=web option get siteurl)"
  page_status="$(trial_ddev exec wp --path=web post list --post_type=page --name=mijn-bibliotheek --field=post_status)"
  [ "$schema" = "$EXPECTED_SCHEMA" ] || migration_trial_fail "schema $schema; verwacht $EXPECTED_SCHEMA." || return
  [ "$core_version" = "$EXPECTED_CORE_VERSION" ] || migration_trial_fail "Core $core_version; verwacht $EXPECTED_CORE_VERSION." || return
  [ "$ui_version" = "$EXPECTED_UI_VERSION" ] || migration_trial_fail "UI $ui_version; verwacht $EXPECTED_UI_VERSION." || return
  [ "$wp_version" = "$EXPECTED_WORDPRESS_VERSION" ] || migration_trial_fail "WordPress $wp_version; verwacht $EXPECTED_WORDPRESS_VERSION." || return
  [ "$site_url" = "$TRIAL_URL" ] || migration_trial_fail "siteurl wijkt af: $site_url" || return
  [ "$page_status" = "publish" ] || migration_trial_fail "Mijn Bibliotheek-trialpagina ontbreekt." || return
  trial_ddev exec wp --path=web plugin is-active biblio-core
  trial_ddev exec wp --path=web plugin is-active biblio-ui
  assert_trial_table_semantics
  http_code="$(curl -k -sS -o /dev/null -w '%{http_code}' "$TRIAL_URL/mijn-bibliotheek/")"
  [ "$http_code" = "200" ] || migration_trial_fail "trial HTTP smoke gaf $http_code." || return

  jq -n \
    --arg project "$TRIAL_PROJECT" \
    --arg database "$TRIAL_DATABASE" \
    --arg url "$TRIAL_URL" \
    --arg schema "$schema" \
    --arg core "$core_version" \
    --arg ui "$ui_version" \
    --arg wordpress "$wp_version" \
    --arg git_sha "$(state_value TRIAL_GIT_SHA)" \
    --arg target_user_id "$target_user_id" \
    --arg target_library_id "$target_library_id" \
    '{status:"PASS",project:$project,database:$database,url:$url,schema:$schema,core_version:$core,ui_version:$ui,wordpress_version:$wordpress,git_sha:$git_sha,dirty:false,target_user_id:$target_user_id,target_library_id:$target_library_id,role:"subscriber",super_admin:false,identity_require_empty:"PASS",http_smoke:200}'
}

backup_trial() {
  local stamp
  local nonce
  local short_sha
  local backup_path
  local checksum
  local bytes
  local observed_schema
  local observed_core
  local observed_ui
  local observed_url
  local target_user_id
  local target_library_id

  validate_trial >/dev/null
  observed_schema="$(trial_ddev exec wp --path=web option get biblio_core_schema_version)"
  observed_core="$(trial_ddev exec wp --path=web plugin get biblio-core --field=version)"
  observed_ui="$(trial_ddev exec wp --path=web plugin get biblio-ui --field=version)"
  observed_url="$(trial_ddev exec wp --path=web option get siteurl)"
  target_user_id="$(state_value TARGET_USER_ID)"
  target_library_id="$(state_value TARGET_LIBRARY_ID)"
  stamp="$(date -u +%Y%m%dT%H%M%SZ)"
  nonce="$(openssl rand -hex 4)"
  short_sha="$(state_value TRIAL_GIT_SHA | cut -c1-12)"
  backup_path="$TRIAL_BACKUP_ROOT/mig02-trial-baseline-${short_sha}-schema${EXPECTED_SCHEMA}-${stamp}-${nonce}.sql.gz"
  [ ! -e "$backup_path" ] || migration_trial_fail "backup wordt nooit overschreven: $backup_path" || return
  [ ! -e "$backup_path.sha256" ] || migration_trial_fail "checksum-sidecar wordt nooit overschreven." || return
  [ ! -e "$backup_path.json" ] || migration_trial_fail "provenance-sidecar wordt nooit overschreven." || return

  mkdir -p "$TRIAL_BACKUP_ROOT"
  trial_ddev export-db --database="$TRIAL_DATABASE" --file="$backup_path"
  [ -s "$backup_path" ] || migration_trial_fail "backup is leeg." || return
  gzip -t "$backup_path"
  checksum="$(shasum -a 256 "$backup_path" | awk '{print $1}')"
  bytes="$(wc -c < "$backup_path" | tr -d ' ')"
  printf '%s  %s\n' "$checksum" "$backup_path" > "$backup_path.sha256"
  jq -n \
    --arg purpose MIG-02-OPS-01 \
    --arg trial_id "$(state_value TRIAL_ID)" \
    --arg project "$TRIAL_PROJECT" \
    --arg database "$TRIAL_DATABASE" \
    --arg url "$observed_url" \
    --arg git_sha "$(state_value TRIAL_GIT_SHA)" \
    --arg schema "$observed_schema" \
    --arg core "$observed_core" \
    --arg ui "$observed_ui" \
    --arg target_user_id "$target_user_id" \
    --arg target_library_id "$target_library_id" \
    --arg backup_path "$backup_path" \
    --arg sha256 "$checksum" \
    --argjson bytes "$bytes" \
    '{purpose:$purpose,trial_id:$trial_id,project:$project,database:$database,url:$url,git_sha:$git_sha,dirty:false,schema:$schema,core_version:$core,ui_version:$ui,target_user_id:$target_user_id,target_library_id:$target_library_id,backup_path:$backup_path,backup_sha256:$sha256,backup_bytes:$bytes}' \
    > "$backup_path.json"
  LAST_BACKUP_PATH="$backup_path"
  echo "BACKUP_PATH=$backup_path"
  echo "BACKUP_SHA256=$checksum"
  echo "BACKUP_BYTES=$bytes"
}

payload_mutate() {
  local token="$1"
  trial_ddev exec wp --path=web option update "$RESTORE_PROBE_OPTION" "$token" --autoload=no >/dev/null
}

mutate_trial() {
  local token="${1:-probe-$(openssl rand -hex 8)}"
  migration_trial_execute_guarded require_destructive_guard payload_mutate "$token"
  [ "$(trial_ddev exec wp --path=web option get "$RESTORE_PROBE_OPTION")" = "$token" ] \
    || migration_trial_fail "synthetische mutatie is niet aantoonbaar." || return
  echo "RESTORE_PROBE=$token"
}

verify_backup_provenance() {
  local backup_path="$1"
  local canonical_backup
  local actual_checksum
  local actual_bytes
  local sidecar_checksum
  local sidecar_path

  canonical_backup="$(canonical_existing_path "$backup_path")"
  migration_trial_assert_backup_path "$canonical_backup" "$TRIAL_BACKUP_ROOT"
  [ -f "$canonical_backup.sha256" ] || migration_trial_fail "backup-checksum ontbreekt." || return
  [ -f "$canonical_backup.json" ] || migration_trial_fail "backup-provenance ontbreekt." || return
  [ ! -L "$canonical_backup.sha256" ] || migration_trial_fail "checksum mag geen symlink zijn." || return
  [ ! -L "$canonical_backup.json" ] || migration_trial_fail "provenance mag geen symlink zijn." || return
  gzip -t "$canonical_backup"
  actual_checksum="$(shasum -a 256 "$canonical_backup" | awk '{print $1}')"
  actual_bytes="$(wc -c < "$canonical_backup" | tr -d ' ')"
  [ "$(wc -l < "$canonical_backup.sha256" | tr -d ' ')" = "1" ] \
    || migration_trial_fail "checksum-sidecar moet exact één regel bevatten." || return
  sidecar_checksum="$(awk 'NR == 1 { print $1 }' "$canonical_backup.sha256")"
  sidecar_path="$(awk 'NR == 1 { sub(/^[^ ]+  /, ""); print }' "$canonical_backup.sha256")"
  [ "$sidecar_checksum" = "$actual_checksum" ] \
    || migration_trial_fail "checksum-sidecar wijkt af van gekozen backup." || return
  [ "$sidecar_path" = "$canonical_backup" ] \
    || migration_trial_fail "checksum-sidecar wijst naar een andere backup." || return
  jq -e \
    --arg trial_id "$(state_value TRIAL_ID)" \
    --arg project "$TRIAL_PROJECT" \
    --arg database "$TRIAL_DATABASE" \
    --arg git_sha "$(state_value TRIAL_GIT_SHA)" \
    --arg schema "$EXPECTED_SCHEMA" \
    --arg core "$EXPECTED_CORE_VERSION" \
    --arg ui "$EXPECTED_UI_VERSION" \
    --arg url "$TRIAL_URL" \
    --arg target_user_id "$(state_value TARGET_USER_ID)" \
    --arg target_library_id "$(state_value TARGET_LIBRARY_ID)" \
    --arg backup_path "$canonical_backup" \
    --arg backup_sha256 "$actual_checksum" \
    --argjson backup_bytes "$actual_bytes" '
      .purpose == "MIG-02-OPS-01" and
      .trial_id == $trial_id and
      .project == $project and
      .database == $database and
      .url == $url and
      .git_sha == $git_sha and
      .dirty == false and
      .schema == $schema and
      .core_version == $core and
      .ui_version == $ui and
      .target_user_id == $target_user_id and
      .target_library_id == $target_library_id and
      .backup_path == $backup_path and
      .backup_sha256 == $backup_sha256 and
      .backup_bytes == $backup_bytes and
      (.backup_bytes > 0)
    ' "$canonical_backup.json" >/dev/null
  zgrep -Fq "$MARKER_TABLE" "$canonical_backup" \
    || migration_trial_fail "backup bevat geen trial-markertabel." || return
  zgrep -Fq "$(state_value TRIAL_ID)" "$canonical_backup" \
    || migration_trial_fail "backup bevat niet de verwachte trial-ID." || return
  zgrep -Fq "$(state_value TRIAL_GIT_SHA)" "$canonical_backup" \
    || migration_trial_fail "backup bevat niet de verwachte build-SHA." || return
  zgrep -Fq "$TRIAL_PROJECT" "$canonical_backup" \
    || migration_trial_fail "backup bevat niet het verwachte trialproject." || return
  zgrep -Fq "$TRIAL_DATABASE" "$canonical_backup" \
    || migration_trial_fail "backup bevat niet de verwachte trialdatabase." || return
  zgrep -Fq "MIG-02-OPS-01" "$canonical_backup" \
    || migration_trial_fail "backup bevat niet het verwachte markerdoel." || return
  printf '%s\n' "$canonical_backup"
}

payload_restore() {
  local backup_path="$1"
  trial_ddev import-db \
    --database="$TRIAL_DATABASE" \
    --file="$backup_path" \
    --no-progress
}

restore_trial() {
  local backup_path="$1"
  local verified_backup
  require_destructive_guard
  verified_backup="$(verify_backup_provenance "$backup_path")"
  migration_trial_execute_guarded require_destructive_guard payload_restore "$verified_backup"
  require_trial_guard
  if trial_ddev exec wp --path=web option get "$RESTORE_PROBE_OPTION" >/dev/null 2>&1; then
    migration_trial_fail "synthetische mutatie bleef na restore bestaan."
    return 1
  fi
  validate_trial
}

cycle_trial() {
  local token
  local evidence_path
  local backup_checksum

  validate_trial >/dev/null
  backup_trial
  token="probe-$(openssl rand -hex 8)"
  mutate_trial "$token"
  [ "$(trial_ddev exec wp --path=web option get "$RESTORE_PROBE_OPTION")" = "$token" ] \
    || migration_trial_fail "mutatiebewijs ontbreekt voor restore." || return
  restore_trial "$LAST_BACKUP_PATH" >/dev/null
  evidence_path="$TRIAL_EVIDENCE_ROOT/cycle-$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 4).json"
  backup_checksum="$(jq -r '.backup_sha256' "$LAST_BACKUP_PATH.json")"
  mkdir -p "$TRIAL_EVIDENCE_ROOT"
  jq -n \
    --arg status PASS \
    --arg trial_id "$(state_value TRIAL_ID)" \
    --arg project "$TRIAL_PROJECT" \
    --arg database "$TRIAL_DATABASE" \
    --arg git_sha "$(state_value TRIAL_GIT_SHA)" \
    --arg backup "$LAST_BACKUP_PATH" \
    --arg backup_sha256 "$backup_checksum" \
    --arg probe "$token" \
    '{status:$status,trial_id:$trial_id,project:$project,database:$database,git_sha:$git_sha,backup:$backup,backup_sha256:$backup_sha256,mutation_probe:$probe,mutation_present_before_restore:true,mutation_absent_after_restore:true,post_restore_validation:"PASS"}' \
    > "$evidence_path"
  echo "CYCLE_EVIDENCE=$evidence_path"
}

normal_fingerprint() {
  local description
  local project
  local url
  local database
  local data_sha
  local schema_sha
  local counts
  local counts_sha
  local user_count
  local library_count
  local membership_count

  description="$(normal_ddev describe --json-output)"
  project="$(printf '%s\n' "$description" | jq -r 'if (.raw|type)=="array" then .raw[0].name else .raw.name end')"
  url="$(printf '%s\n' "$description" | jq -r 'if (.raw|type)=="array" then .raw[0].primary_url else .raw.primary_url end')"
  database="$(normal_ddev exec wp --path=web db query 'SELECT DATABASE()' --skip-column-names)"
  [ "$project" = "$NORMAL_PROJECT" ] || migration_trial_fail "normale fingerprint: verkeerd project." || return
  [ "$url" = "https://biblio-v2.ddev.site" ] || migration_trial_fail "normale fingerprint: verkeerde URL." || return
  [ "$database" = "$NORMAL_DATABASE" ] || migration_trial_fail "normale fingerprint: verkeerde database." || return

  data_sha="$(normal_ddev exec wp --path=web db export - \
    --skip-comments --skip-dump-date --single-transaction --order-by-primary \
    2>/dev/null | shasum -a 256 | awk '{print $1}')"
  schema_sha="$(normal_ddev exec wp --path=web db export - \
    --no-data --skip-comments --skip-dump-date \
    2>/dev/null | shasum -a 256 | awk '{print $1}')"
  counts="$(normal_ddev mysql -N -s -D "$NORMAL_DATABASE" -e \
    "SET SESSION group_concat_max_len=1000000; SELECT GROUP_CONCAT(CONCAT('SELECT ', QUOTE(table_name), ' AS table_name, COUNT(*) AS row_count FROM ', CHAR(96), table_name, CHAR(96)) ORDER BY table_name SEPARATOR ' UNION ALL ') INTO @normal_count_sql FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'wp_biblio_%'; PREPARE normal_count_stmt FROM @normal_count_sql; EXECUTE normal_count_stmt; DEALLOCATE PREPARE normal_count_stmt;")"
  counts_sha="$(printf '%s\n' "$counts" | shasum -a 256 | awk '{print $1}')"
  user_count="$(normal_ddev mysql -N -s -D "$NORMAL_DATABASE" -e 'SELECT COUNT(*) FROM wp_users')"
  library_count="$(normal_ddev mysql -N -s -D "$NORMAL_DATABASE" -e 'SELECT COUNT(*) FROM wp_biblio_libraries')"
  membership_count="$(normal_ddev mysql -N -s -D "$NORMAL_DATABASE" -e 'SELECT COUNT(*) FROM wp_biblio_library_memberships')"

  jq -n \
    --arg project "$project" \
    --arg database "$database" \
    --arg data_sha256 "$data_sha" \
    --arg schema_sha256 "$schema_sha" \
    --arg biblio_counts_sha256 "$counts_sha" \
    --argjson wordpress_users "$user_count" \
    --argjson libraries "$library_count" \
    --argjson memberships "$membership_count" \
    '{project:$project,database:$database,data_sha256:$data_sha256,schema_sha256:$schema_sha256,biblio_counts_sha256:$biblio_counts_sha256,counts:{wordpress_users:$wordpress_users,libraries:$libraries,memberships:$memberships}}'
}

show_paths() {
  migration_trial_assert_separate_roots \
    "$TRIAL_SOURCE_ROOT" "$TRIAL_ARTIFACT_ROOT" \
    "$TRIAL_BACKUP_ROOT" "$TRIAL_EVIDENCE_ROOT"
  cat <<EOF
trial_worktree=$TRIAL_APP_ROOT
future_source_root=$TRIAL_SOURCE_ROOT
migration_artifact_root=$TRIAL_ARTIFACT_ROOT
trial_backup_root=$TRIAL_BACKUP_ROOT
trial_evidence_root=$TRIAL_EVIDENCE_ROOT
trial_state_root=$(dirname "$TRIAL_STATE_FILE")
EOF
}

command="${1:-}"
shift || true

case "$command" in
  prepare)
    [ "$#" -eq 0 ] || { usage; exit 2; }
    prepare_trial
    ;;
  recreate)
    DESTRUCTIVE_CONFIRMATION="${1:-}"
    [ "$#" -eq 1 ] || { usage; exit 2; }
    recreate_trial
    ;;
  validate)
    [ "$#" -eq 0 ] || { usage; exit 2; }
    validate_trial
    ;;
  backup)
    [ "$#" -eq 0 ] || { usage; exit 2; }
    backup_trial
    ;;
  mutate)
    DESTRUCTIVE_CONFIRMATION="${1:-}"
    [ "$#" -le 2 ] || { usage; exit 2; }
    mutate_trial "${2:-}"
    ;;
  restore)
    DESTRUCTIVE_CONFIRMATION="${1:-}"
    [ "$#" -eq 2 ] || { usage; exit 2; }
    restore_trial "$2"
    ;;
  cycle)
    DESTRUCTIVE_CONFIRMATION="${1:-}"
    [ "$#" -eq 1 ] || { usage; exit 2; }
    cycle_trial
    ;;
  normal-fingerprint)
    [ "$#" -eq 0 ] || { usage; exit 2; }
    normal_fingerprint
    ;;
  paths)
    [ "$#" -eq 0 ] || { usage; exit 2; }
    show_paths
    ;;
  *)
    usage
    exit 2
    ;;
esac
