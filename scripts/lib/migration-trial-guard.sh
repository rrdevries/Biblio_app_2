#!/usr/bin/env bash

# Shared fail-closed predicates for the MIG-02 isolated trial tooling.
# This file deliberately performs no DDEV or database operation by itself.

migration_trial_fail() {
  echo "FOUT: $*" >&2
  return 1
}

migration_trial_assert_identity() {
  local expected_root="$1"
  local actual_root="$2"
  local expected_project="$3"
  local actual_project="$4"
  local expected_url="$5"
  local actual_url="$6"
  local expected_database="$7"
  local configured_database="$8"
  local selected_database="$9"
  local expected_trial_id="${10}"
  local environment_marker="${11}"
  local environment_trial_id="${12}"
  local database_trial_id="${13}"
  local database_project="${14}"
  local database_name="${15}"
  local database_purpose="${16}"
  local database_git_sha="${17}"
  local expected_git_sha="${18}"
  local actual_git_sha="${19}"
  local dirty_state="${20}"

  [ -n "$expected_root" ] || migration_trial_fail "verwachte trial-root ontbreekt." || return
  [ "$actual_root" = "$expected_root" ] || migration_trial_fail "verkeerde DDEV approot: $actual_root" || return
  [ "$expected_project" = "biblio-v2-migration-trial" ] || migration_trial_fail "onveilige verwachte projectnaam." || return
  [ "$actual_project" = "$expected_project" ] || migration_trial_fail "verkeerd DDEV-project: $actual_project" || return
  [ "$expected_url" = "https://biblio-v2-migration-trial.ddev.site" ] || migration_trial_fail "onveilige verwachte trial-URL." || return
  [ "$actual_url" = "$expected_url" ] || migration_trial_fail "verkeerde DDEV-URL: $actual_url" || return
  [ -n "$expected_database" ] || migration_trial_fail "verwachte trialdatabase ontbreekt." || return
  [ "$expected_database" != "db" ] || migration_trial_fail "normale database db is verboden." || return
  [ "$expected_database" != "biblio_core_test" ] || migration_trial_fail "integratietestdatabase is geen trialtarget." || return
  [ "$configured_database" = "$expected_database" ] || migration_trial_fail "DB_NAME wijst niet naar de trialdatabase." || return
  [ "$selected_database" = "$expected_database" ] || migration_trial_fail "actief geselecteerde database wijkt af." || return
  [ -n "$expected_trial_id" ] || migration_trial_fail "lokale trial-ID ontbreekt." || return
  [ "$environment_marker" = "1" ] || migration_trial_fail "trial-omgevingsmarker ontbreekt." || return
  [ "$environment_trial_id" = "$expected_trial_id" ] || migration_trial_fail "trial-ID in omgeving wijkt af." || return
  [ "$database_trial_id" = "$expected_trial_id" ] || migration_trial_fail "database-marker ontbreekt of wijkt af." || return
  [ "$database_project" = "$expected_project" ] || migration_trial_fail "database-marker noemt verkeerd project." || return
  [ "$database_name" = "$expected_database" ] || migration_trial_fail "database-marker noemt verkeerde database." || return
  [ "$database_purpose" = "MIG-02-OPS-01" ] || migration_trial_fail "database-marker noemt verkeerd doel." || return
  [[ "$expected_git_sha" =~ ^[0-9a-f]{40}$ ]] \
    || migration_trial_fail "ongeldige verwachte Git SHA." || return
  [ "$database_git_sha" = "$expected_git_sha" ] || migration_trial_fail "database-marker noemt verkeerde Git SHA." || return
  [ "$actual_git_sha" = "$expected_git_sha" ] || migration_trial_fail "trialcode draait op verkeerde Git SHA." || return
  [ "$dirty_state" = "clean" ] || migration_trial_fail "trialworktree is niet schoon." || return
}

migration_trial_assert_confirmation() {
  local supplied="$1"
  [ "$supplied" = "--confirm-trial-destruction" ] \
    || migration_trial_fail "destructieve trialactie vereist --confirm-trial-destruction."
}

migration_trial_path_is_within() {
  local child="$1"
  local parent="$2"

  [ "$child" = "$parent" ] && return 0
  case "$child" in
    "$parent"/* ) return 0 ;;
  esac

  return 1
}

migration_trial_assert_separate_roots() {
  local source_root="$1"
  local artifact_root="$2"
  local backup_root="$3"
  local evidence_root="$4"
  local first
  local second

  for first in "$source_root" "$artifact_root" "$backup_root" "$evidence_root"; do
    [ -n "$first" ] || migration_trial_fail "een lokale opslagroot ontbreekt." || return
    for second in "$source_root" "$artifact_root" "$backup_root" "$evidence_root"; do
      [ "$first" = "$second" ] && continue
      if migration_trial_path_is_within "$first" "$second"; then
        migration_trial_fail "source, artifacts, backups en evidence moeten afzonderlijk zijn."
        return 1
      fi
    done
  done
}

migration_trial_assert_backup_path() {
  local backup_path="$1"
  local backup_root="$2"

  [ -f "$backup_path" ] || migration_trial_fail "backup bestaat niet: $backup_path" || return
  [ ! -L "$backup_path" ] || migration_trial_fail "backup mag geen symlink zijn." || return
  migration_trial_path_is_within "$backup_path" "$backup_root" \
    || migration_trial_fail "backup ligt buiten de trial-backuproot." || return
  case "$backup_path" in
    *.sql.gz ) ;;
    * ) migration_trial_fail "backup moet een .sql.gz-bestand zijn." || return ;;
  esac
}

migration_trial_execute_guarded() {
  local guard_function="$1"
  local payload_function="$2"
  shift 2

  "$guard_function" || return 1
  "$payload_function" "$@"
}
