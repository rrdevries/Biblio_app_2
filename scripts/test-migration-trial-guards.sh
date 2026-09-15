#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# shellcheck source=scripts/lib/migration-trial-guard.sh
source scripts/lib/migration-trial-guard.sh

PASS_COUNT=0
PAYLOAD_RAN=0

pass() {
  PASS_COUNT=$((PASS_COUNT + 1))
}

expect_failure() {
  local label="$1"
  shift
  if "$@" >/dev/null 2>&1; then
    echo "FOUT: guard accepteerde onveilige case: $label" >&2
    exit 1
  fi
  pass
}

safe_identity() {
  migration_trial_assert_identity \
    "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
    "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
    "https://biblio-v2-migration-trial.ddev.site" \
    "https://biblio-v2-migration-trial.ddev.site" \
    "biblio_migration_trial" "biblio_migration_trial" \
    "biblio_migration_trial" "0123456789abcdef0123456789abcdef" \
    "1" "0123456789abcdef0123456789abcdef" \
    "0123456789abcdef0123456789abcdef" \
    "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"
}

payload() {
  PAYLOAD_RAN=1
}

safe_identity
pass

expect_failure "normal project" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2" \
  "https://biblio-v2-migration-trial.ddev.site" "https://biblio-v2.ddev.site" \
  "biblio_migration_trial" "db" "db" \
  "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" "biblio-v2" "db" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "normal db" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "db" "db" "db" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "db" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "missing marker" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" "" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "wrong trial id" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "ffffffffffffffffffffffffffffffff" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "wrong database purpose" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "UNRELATED" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "wrong database git sha" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
  "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "wrong app root" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/other" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"

expect_failure "dirty build" migration_trial_assert_identity \
  "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
  "biblio-v2-migration-trial" "biblio-v2-migration-trial" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "https://biblio-v2-migration-trial.ddev.site" \
  "biblio_migration_trial" "biblio_migration_trial" \
  "biblio_migration_trial" "0123456789abcdef0123456789abcdef" "1" \
  "0123456789abcdef0123456789abcdef" \
  "0123456789abcdef0123456789abcdef" \
  "biblio-v2-migration-trial" "biblio_migration_trial" "MIG-02-OPS-01" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
  "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "dirty"

expect_failure "missing confirmation" migration_trial_assert_confirmation ""
migration_trial_assert_confirmation "--confirm-trial-destruction"
pass

migration_trial_assert_separate_roots \
  "/tmp/trial/source" "/tmp/trial/artifacts" \
  "/tmp/trial/backups" "/tmp/trial/evidence"
pass
expect_failure "artifact inside source" migration_trial_assert_separate_roots \
  "/tmp/trial/source" "/tmp/trial/source/output" \
  "/tmp/trial/backups" "/tmp/trial/evidence"

BACKUP_TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$BACKUP_TEST_ROOT"' EXIT
printf 'not-a-real-dump\n' > "$BACKUP_TEST_ROOT/real.sql.gz"
ln -s "$BACKUP_TEST_ROOT/real.sql.gz" "$BACKUP_TEST_ROOT/link.sql.gz"
expect_failure "backup symlink" migration_trial_assert_backup_path \
  "$BACKUP_TEST_ROOT/link.sql.gz" "$BACKUP_TEST_ROOT"

failing_guard() { return 1; }
migration_trial_execute_guarded failing_guard payload || true
[ "$PAYLOAD_RAN" -eq 0 ] || {
  echo "FOUT: destructieve payload liep na falende guard." >&2
  exit 1
}
pass

unsafe_normal_guard() {
  migration_trial_assert_identity \
    "/tmp/biblio-trial/worktree" "/tmp/biblio-trial/worktree" \
    "biblio-v2-migration-trial" "biblio-v2" \
    "https://biblio-v2-migration-trial.ddev.site" "https://biblio-v2.ddev.site" \
    "biblio_migration_trial" "db" "db" \
    "0123456789abcdef0123456789abcdef" "1" \
    "0123456789abcdef0123456789abcdef" \
    "0123456789abcdef0123456789abcdef" "biblio-v2" "db" "MIG-02-OPS-01" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" \
    "4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2" "clean"
}
PAYLOAD_RAN=0
migration_trial_execute_guarded unsafe_normal_guard payload >/dev/null 2>&1 || true
[ "$PAYLOAD_RAN" -eq 0 ] || {
  echo "FOUT: destructieve payload liep tegen normaal DDEV/db." >&2
  exit 1
}
pass

echo "MIG-02-OPS-01 guardtests: $PASS_COUNT checks geslaagd."
