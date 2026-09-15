# MIG-02-OPS-01 — Isolated trial and rollback runbook

Status: **HUMAN GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

Renée subsequently completed the human identity/login checkpoint and
designated the exact CURRENT ZIP profiled by MIG-02-SOURCE-01. The versions and
SHA below remain the historical OPS-01 proof baseline; the later source-profile
build is recorded separately in doc 112.

Scope: local operations and release safety only. This runbook is both the
reusable operator procedure and the closure evidence. It adds no current V1
adapter, source mapping, migration participant, apply CLI, real import, product
schema, UI behavior or production-cutover automation.

## 1. Pre-coding safety audit

The normal runtime is DDEV project `biblio-v2`, URL
`https://biblio-v2.ddev.site`, database `db`. `web/wp-config-ddev.php` uses an
explicit `DB_NAME` environment value and otherwise defaults to `db`. At audit
time this was the only registered DDEV project; the database server contained
no trial database. Runtime was schema `1026`, Biblio Core `2.33.0`, Biblio UI
`0.20.0`, WordPress `7.0.2`, and clean Git HEAD
`4f3eb7e17b1971be6aaea2ceadb6d5915800d7e2`.

The integration runner creates and destroys only literal
`biblio_core_test`, injects that exact `DB_NAME` into WordPress/PHPUnit and
removes it on exit. This construction protects normal `db`, but it has no
durable login target, backup or positive database marker and is therefore not
the migration-trial target.

DDEV 1.25.3 provides project snapshots and explicit database export/import.
Because `ddev import-db` otherwise defaults to destructive import into `db`,
raw import/restore commands are not an operator interface for this trial.

## 2. Isolation architecture

`scripts/migration-trial.sh prepare` creates a detached, clean Git worktree at
the exact current committed SHA under ignored `.local/migration-trial/worktree`.
That worktree owns a separate DDEV project, container/volume, hostname and
database:

```text
project   biblio-v2-migration-trial
URL       https://biblio-v2-migration-trial.ddev.site
database  biblio_migration_trial
```

The trial copies only the installed WordPress runtime code, default theme,
translations and Composer vendor dependencies required to run the committed
Biblio plugins. It does not copy `wp-config*`, provider configuration,
credentials, uploads, the normal database or normal catalog/user content.
Tracked Biblio code comes from the SHA-pinned worktree, not from a file copy.

The distinct DDEV project/volume is the primary isolation boundary. The
non-default database name and marker checks are defense in depth.

## 3. Positive target guard

Before any reset, synthetic mutation or restore payload, one shared guard must
prove all of the following:

- canonical DDEV approot is the detached trial worktree;
- DDEV project is exactly `biblio-v2-migration-trial`;
- primary URL is exactly the trial URL;
- configured and selected database are both `biblio_migration_trial`;
- expected database is neither `db` nor `biblio_core_test`;
- `BIBLIO_MIGRATION_TRIAL=1` is present;
- the ignored local trial ID matches the DDEV environment;
- the same trial ID, project and database occur in the database-resident
  `biblio_migration_trial_guard` operational marker;
- actual Git SHA matches the recorded SHA and the worktree is clean; and
- the exact destructive confirmation argument is present.

Missing, unknown, ambiguous or contradictory state returns nonzero before the
payload. `scripts/test-migration-trial-guards.sh` exercises normal project/URL/
`db`, missing/wrong marker, wrong root, dirty build, missing confirmation,
unsafe path containment and a sentinel payload that must not run.

The first creation path is separate: it proves the exact trial project/root/
URL/environment/database and requires the dedicated database to be absent.
It never adopts an unknown existing database. The operational marker is then
created before WordPress provisioning. Routine reset/restore cannot use this
initial exception.

## 4. Deterministic baseline creation

From the normal repository root, only with a clean committed worktree:

```bash
scripts/migration-trial.sh prepare
```

This creates the isolated DDEV project and explicit database, installs the
fresh WordPress baseline, activates Biblio Core and Biblio UI, migrates through
the current schema, creates one clearly titled trial-only
`/mijn-bibliotheek/` page, and provisions through the existing Core identity
command:

```text
login         migration-trial-target
email         migration-trial-target@example.invalid
WordPress     subscriber, non-super-admin
Library       one designated personal Mijn Bibliotheek
membership    active Owner + Direct
```

The generated admin password is held only in a mode-0600 ignored local file.
The target password is not accepted, printed or committed; WordPress sends its
normal reset notification to the isolated Mailpit instance. Environment-
specific user and Library IDs are read from command output and stored only in
ignored local state.

Recreation is explicit and destructive only inside the already marked target:

```bash
scripts/migration-trial.sh recreate --confirm-trial-destruction
```

No arbitrary row deletion or normal-data clone is used.

## 5. Baseline validation

```bash
scripts/migration-trial.sh validate
```

Validation proves marker/project/database/root/SHA/cleanliness first and then:

- WordPress `7.0.2` is installed;
- schema option is exactly `1026`;
- active Core header/runtime version is `2.33.0`;
- active UI header/runtime version is `0.20.0`;
- trial site URL and published trial page are exact;
- target IDs validate through `biblio identity validate --require-empty`;
- membership is active Owner + Direct;
- WordPress role is exactly `subscriber` and `super_admin=false`;
- all 23 current IDENTITY-01 cleanliness buckets total zero;
- all 57 schema-1026 Biblio tables exist;
- only one Library, its one membership/designation and standard seeded
  classification rows may be nonzero; and
- no MIG-FND run state or other Biblio product rows exist.

The current `--require-empty` buckets are: other Library memberships, Items,
catalog contexts, custom classification terms, Locations, Collections and
memberships, archive periods, publications, activity, metadata observations/
lookup snapshots; plus target-user External Loans, ReadingRounds, Personal
Reading Truth, Notes, Ratings, Reviews, Hierna-lezen list/entries/undo and
Wishlist entries/history. Standard seed terms and the exact target identity
anchor are allowed. The OPS check additionally requires all other Biblio and
MIG-FND rows to be zero; it does not weaken the existing validator.

An HTTP 200 from the unambiguous trial URL is the automated smoke boundary.

## 6. Backup and verification

```bash
scripts/migration-trial.sh backup
```

The helper performs an explicit DDEV export of only
`biblio_migration_trial`. Each immutable filename contains short Git SHA,
schema, UTC creation identity and a random suffix. Existing files are never
overwritten. The ignored backup root contains:

- the non-empty gzip SQL export;
- a SHA-256 sidecar; and
- JSON provenance with purpose, trial ID, project, database, URL, schema,
Core/UI versions, exact Git SHA/dirty state, explicit target IDs, bytes and
checksum, plus the exact canonical backup path.

Backup verification requires nonzero size, successful gzip integrity and a
matching checksum. Restore repeats gzip verification and additionally requires
matching canonical path, URL, target IDs, trial/project/DB/purpose marker,
SHA/schema/Core/UI provenance.

## 7. Controlled mutation and restore

Only the positively guarded isolated target may receive the bounded synthetic
probe:

```bash
scripts/migration-trial.sh mutate --confirm-trial-destruction
```

This writes only the trial-only WordPress option
`biblio_migration_trial_restore_probe` and proves its exact value exists. It
does not create or reinterpret migration/product data.

Restore accepts only an existing `.sql.gz` inside the dedicated backup root:

```bash
scripts/migration-trial.sh restore --confirm-trial-destruction \
  <exact-BACKUP_PATH>
```

After guarded explicit-database import, the helper automatically reproves the
marker, absence of the synthetic probe and the complete baseline validation.
It rejects a source/artifact/other path, missing file, missing/mismatched
sidecar or changed provenance before import.

One complete proof is bundled as:

```bash
scripts/migration-trial.sh cycle --confirm-trial-destruction
```

Acceptance runs this twice. Each cycle produces a separate ignored evidence
JSON and immutable backup and proves baseline → backup → mutation present →
restore → mutation absent → baseline valid.

## 8. Normal database immutability

Before and after operational proof:

```bash
scripts/migration-trial.sh normal-fingerprint
```

The read-only command itself verifies normal project `biblio-v2`, URL and
database `db`. It emits no row content: only deterministic full-data and schema
SHA-256 values, a digest of exact per-Biblio-table row counts, and aggregate
WordPress-user/Library/membership counts. Equality proves normal schema,
Biblio rows and user/Library anchors were unchanged by the trial operations.

## 9. Source, output, backup and evidence separation

```bash
scripts/migration-trial.sh paths
```

The ignored roots are distinct siblings:

```text
.local/migration-source/current/          future immutable source packages
.local/migration-trial/artifacts/         profile/dry-run/reconciliation output
.local/migration-trial/backups/           V2 trial baseline backups only
.local/migration-trial/evidence/          operational proof only
.local/migration-trial/state/             IDs/config/secrets, local only
```

These paths are inside the isolated worktree. No CURRENT source directory is
created or populated by this slice. A backup is never accepted from the source
or artifact tree; migration output may never be written into source or backup.
RUN-01's existing source-root containment rejection remains authoritative.

When Renée later designates exactly one CURRENT export, the operator must make
it read-only, fingerprint bytes and manifest immediately, record both digests,
treat changed bytes as a different snapshot and never write generated output
into its tree. A V2 baseline backup is not a V1 source package and may not be
profiled as one.

## 10. Failure and build immutability

Profile, dry-run and any later rehearsal apply must record the exact clean Git
SHA. A code change creates a different migrator build even if version numbers
are equal. Backups are immutable identities and are not overwritten.

If a future trial apply fails:

1. stop migration activity;
2. preserve logs and generated artifacts;
3. do not repair individual product rows;
4. restore the isolated baseline through the guarded helper;
5. revalidate the baseline;
6. correct code/mapping in its own approved slice; and
7. start a new rehearsal with explicit build and source identity.

The eventual production cutover follows the same principle—backup, final
immutable source, apply, reconciliation, accept or full restore—but this local
slice neither implements nor proves production cutover tooling.

## 11. Human login checkpoint

Renée must use only:

```text
https://biblio-v2-migration-trial.ddev.site/mijn-bibliotheek/
```

The site title/page/description explicitly say `MIGRATION TRIAL`; the normal
site is `https://biblio-v2.ddev.site`. Retrieve the target password-reset mail
from the trial Mailpit UI, then verify as the target account:

1. login succeeds;
2. Mijn Bibliotheek opens;
3. normal empty-catalog access works;
4. Add Book access is present;
5. expected Owner capabilities work; and
6. no admin/super-admin controls are present.

Automation did not fake this acceptance. Renée confirmed the checkpoint; the
exact status is now:

**HUMAN GO / CLOSED**

After that human GO, Renée designated the one CURRENT V1 export profiled in
doc 112. No apply follows automatically.

## 12. Explicitly deferred

No CURRENT V1 adapter/export, V1 mapping, apply CLI, real import, Series/
Collections/Assessment/Wishlist/Archive adapter, loan decision, delta
migration, product schema, production rollback/cutover automation or UI
redesign is included. Schema remains `1026`; product remains `v2.001`; Core
remains `2.33.0`; UI remains `0.20.0`.

Generated databases, exports, checksums, evidence, credentials and source
packages are ignored/local and never committed.
