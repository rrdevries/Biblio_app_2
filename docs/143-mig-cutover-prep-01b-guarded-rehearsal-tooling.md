# 143 — MIG-CUTOVER-PREP-01B guarded rehearsal tooling

Status: **FRESH PRECOMMIT ACCEPTANCE GO — EXACT-SHA CLOSURE PENDING**.
PREVIOUS PREP-01B ACCEPTANCE ATTEMPT: **INVALIDATED / INCONCLUSIVE**.
It is retained as forensic history, never proof of zero normal-database impact.
The newly authorized cycle passed prospective per-table read-only comparison
and isolated gates/smoke. This is not a retrospective drift exception. See §24.

## 1. Inheritance and boundary

High-risk implementation of docs/141, compatible with PREP-01A (docs/142).
This is rehearsal infrastructure, not acceptance of FINAL CURRENT SOURCE,
V1 freeze, final rehearsal, human QA or production authorization. Product
semantics, mapper identity rules and MIG-FND dispositions are unchanged.
The external project-phase handover is contextual; the newer repository
canon and explicit slice authorization govern this implementation.

## 2. Pre-coding audit

Audit preceded implementation; analysis and implementation were separate.

| Area | Baseline and required seam |
| --- | --- |
| A Apply runner | Internal `MigrationApplyRunner`, no public apply command; add observer before begin and at committed boundaries. |
| B Begin | `BeginMigrationRunService` validates explicit target transactionally; identical completed runs are reused. |
| C Commit | `CommitMigrationRecordService` atomically commits participant product state and ledger outcome; retain it. |
| D Order | Existing dependency ordering and registered participants remain authoritative; no second importer. |
| E Prepare | One `MigrationPlanPreparer` stream serves dry-run, apply and reconciliation. |
| F Digest | Existing target-bound plan-set digest; add final bundle/profile/registry binding and separate target-neutral digest. |
| G Preflight | Existing target, source, prepared-plan and prior-replay checks precede begin; insert exact rehearsal check at that last boundary. |
| H Ledger | Existing run, observation, mapping, preservation and quarantine tables/statuses; no new product schema. |
| I Resume/replay | Committed observations are skipped; completed identical run reused; require immutable checkpoint and exact full state. |
| J Prior mapping | Existing fail-closed compatible-run evidence lookup remains unchanged. |
| K Reconciliation | Core checks prepared plans against persisted targets; source-profile diagnostics previously rejected even reviewed auxiliary categories. Add narrowly typed reviewed admission, never override rejection outside Core. |
| L Trial guard | OPS-01 trial marker cannot authorize apply; use distinct positive cutover project/database/marker/runtime contract. |
| M Empty target | Older limited buckets insufficient; enumerate all 57 schema-1026 tables. |
| N Backup | Existing operational dump helpers lack phase-bound independent restore acceptance; add guarded native transport. |
| O Restore | Restore must target a positively identified disposable database and prove exact full baseline. |
| P Fingerprint | Extend beyond old summary buckets to all tables, DDL, row multisets, trigger semantics and DB charset/collation. |
| Q Target | Reuse personal migration validator; add ordinary subscriber/non-super-admin and exact identity binding. |
| R Runtime | Actual runtime/build inventory exists; compare complete observed inventory against accepted specification. |
| S Evidence | Reuse deterministic JSON principles; new immutable attempt/checkpoint/backup receipts with checksum sidecars, no bodies. |

DB-only recovery is sufficient for this bounded migration: registered
participants write Core/MIG-FND relational state; no attachment/media upload,
filesystem product object or provider lookup is part of a plan. Code, immutable
source and evidence are separately bound/retained. This does not establish a
general WordPress filesystem disaster-recovery policy.

## 3. Positive environment identity

`WpdbRehearsalTarget` requires a separate
`biblio-v2-cutover-<12 hex>` DDEV project, `biblio_cutover_<12 hex>` selected
database, positive process flag and resident `biblio_cutover_guard` marker.
The marker binds purpose, environment ID, project, database and clean Git SHA.
It is a disposable operational marker, not a Biblio schema migration.
Accepted identity also binds root, runtime, User and Library. Advisory locking,
disabled WP cron, explicit frozen-write flag and maintenance mode are required.
Provider HTTP requests are denied during guarded operations.

## 4. Production negative guard

Normal `biblio-v2`/`db`, production environment type, ordinary test database,
unknown/unmarked environments and wrong project/database fail closed. Negative
names alone never grant access. Native MariaDB clients prove that their endpoint
is the same server as the guarded wpdb connection using a connection-owned
random named lock. Credentials are child-process environment only, never argv
or receipts. Normal database fingerprinting uses a separate read-only port.

## 5. Full empty-target guard

`CoreTableNames::schema1026()` supplies all 57 tables. Schema health and exact
inventory are checked. Allowed: one validated personal Library, one direct
active Owner membership, one designation, nine standard Book Types and twelve
standard Genres with exact canonical IDs/keys/content; zero Subjects. Every
other Biblio table must be empty, including all MIG-FND state. Required WP
system/User state is retained in the full database baseline. Unexpected Biblio
tables, views, routines and events are rejected, not omitted from proof.

## 6. Explicit target validation

There is no actor/admin/first-User or Library fallback. Existing Core validation
checks the active target User, exact private personal Library and direct active
Owner relationship. Rehearsal additionally requires exactly the subscriber
role and no super-admin. Identity/seed and non-Biblio tables may not change
during apply. Rollback is allowed to remove post-baseline disposable state.

## 7. Runtime binding

Record actual WordPress, PHP, MariaDB, charset/collation, SQL mode, loaded
extensions, product/Core/UI/schema, DDEV and composer.lock hash; compare with
the accepted inventory. Git SHA must be present and working tree clean. Runtime
values are observations, not copied historical version assertions.

## 8. Candidate source and bundle

`ApprovedRehearsalSource` requires PREP-01A intake receipt, exact package
identity/archive/manifest/adapter, compatible final-population bundle and a
separate explicit `approved_for_rehearsal` review. A mechanically compatible
bundle is never auto-approved. Review binds package, bundle, source profile,
quarantine and parent-hash-scoped exception populations. Archive and extracted
manifest are rehashed before use and after operations. Real source approval is
not supplied by this slice; tests construct explicit synthetic approvals only.

## 9. Plan and intent digests

Immediately before Begin, prepare the actual complete plan again. Match the
candidate, mapper contracts, bundle, complete participant registry, source
profile, target-bound plan-set digest, target-neutral plan digest, build,
runtime and environment. Neutralization removes typed target User/Library
fields only; canonical seed IDs are required. A two-target test proves neutral
equality and target-bound inequality. The semantic intent has no random backup
ID/timestamp; the separate authorization digest binds the exact PRE receipt.

## 10. Rehearsal authorization

`RehearsalAuthorization` requires `AUTHORIZE REHEARSAL <exact digest>` and
bounded review ID, resume permission and named fault selection. Changing any
bound material invalidates confirmation. This is local operator intent, not a
cryptographic identity provider or production authorization. No reusable yes,
force, skip-guard or public general-purpose apply command exists.

## 11. PRE backup

`RehearsalBackupDirectory` accepts only restricted canonical local storage.
PRE_APPLY requires the fully empty target and exact accepted environment.
Native gzip SQL export includes complete DB tables and triggers; receipt binds
phase, target, source/build/runtime, byte size, SHA-256 and baseline. Unique
filenames and exclusive creation retain earlier evidence; backups/sidecars
become mode 0400 in mode-0700 storage and must never be Git-tracked.

## 12. Independent restore verification

A separate positively identified disposable probe database must restore each
backup successfully and reproduce the full original fingerprint before the
backup is accepted. Both primary and probe native endpoints are verified.
Restore checks immutable receipt, checksum, size, sidecar and gzip integrity
before destructive commands. Only the exact validated disposable database is
dropped/recreated. Re-select, marker, schema and fingerprint checks follow.
Trigger creation instant is excluded; definition, SQL mode, definer and
charset/collation are included. Row bytes are hashed without emitting bodies.

## 13. Real apply composition

`RehearsalComposition` uses Core production participants, repositories,
transaction services, target validator, reconciliation and `MigrationApplyRunner`.
`CurrentV1RehearsalMapper` binds existing mapper implementations to the exact
candidate population; it is not another importer. Observer hooks do not alter
participant ordering. Callback failures cannot strand a newly running run.
Existing observer-free callers preserve their semantics.

## 14. Named faults

`after_product_commit` stops after representative committed product state;
`after_preservation_commit` stops after a committed preserved-deferred outcome.
Both are explicitly bound to rehearsal authorization. There is no production
environment-variable fault switch. Evidence records preflight, begin, product
and preservation/quarantine commits, reconciliation, completion and verification.

## 15. Resume

Use the existing Interrupted state, immutable checkpoint receipt and exact
full target fingerprint. Before begin, validate source family/snapshot/hash,
source/migrator version, target, status and intent/authorization against the
stored run. Changed checkpoint or bound material fails before migration writes.
Resume reuses committed observations/products/preservations and continues only
missing work. Failed runs are not automatically approved for resume.

## 16. Replay

Completed identical apply returns the same MIG-FND run. Exact before/after full
database fingerprint proves zero new product targets, observations, mappings,
preservations, quarantine and timestamp changes. Only append-only filesystem
attempt/checkpoint receipts grow; no extra database audit run is allowed here.

## 17. Reconciliation classification

Core reconciliation must itself accept. Cutover classification is `accepted`,
`accepted_with_reviewed_quarantine` or `rejected`, never a new MIG-FND disposition.
`ReviewedSourceProfile` admits only an explicitly digest-reviewed exact adapter
profile with closed auxiliary-category/reason vocabulary. This implements
already accepted package-retained auxiliary evidence (docs/118 §16, docs/119
§13 and docs/130 §23); it does not reclassify unknown source data. Malformed,
unreadable/new diagnostics and unsupported/missing/failed/uncommitted records
remain rejection. The raw diagnostics remain visible as safe reasons/counts.

## 18. Exact quarantine allowlist

Review binds source type/ID/payload hash, reason, evidence hash and no-target.
Prepared quarantine must exactly equal it before begin; persisted quarantine
must exactly equal it at reconciliation. Missing, changed-reason and changed-
evidence allowlists reject without target writes. Circulation restricted
evidence is compared in memory, not exported. The separate fresh-source
`CIRCULATION_CUTOVER_REVIEW_REQUIRED` production gate is not resolved here.

## 19. Post-apply integrity and smoke

Real reconciliation checks prepared plans against exact persisted identity,
ownership, payload and relations. Unique ledger targets must match actual
domain counts (never CURRENT constants): Works/Editions/Items/details/ISBN,
Authors/credits/contributors, Series/memberships, containment/catalog context,
Rounds/truth, Notes, Ratings/Reviews and Wishlist. Validate Item Edition/Work,
orphan details and acyclic containment. Nonmigration provider/loan/publication
state stays empty. Preservation checks exact reason/locator/evidence and uses
the restricted resolver without returning content; goals have no product target.
Core read hooks cover My Biblio, Library, catalog, Authors, Series, Reading,
Notes and Wishlist. Safe representative target IDs support later human QA;
this is not Renée's QA and makes no UI changes.

## 20. POST backup

After completed, accepted and verified apply, create separate POST_APPLY
backup with completed run ID and reconciliation hash, using the same independent
restore proof. POST does not replace PRE and is never rollback authority.

## 21. Rollback

Restore the authorized PRE backup, never inverse participant writes. Validate
source, environment and protected database before restore, then verify exact
full baseline including all 57 Biblio tables, counts, MIG-FND and WP state.
Supported after success and either interruption, without needing successful
reconciliation. Evidence remains outside the restored DB.

## 22. Immutable evidence

Each append creates a fresh restricted attempt directory, content-addressed
JSON and checksum sidecar; no mutable latest pointer. Checkpoint/backup
verification reads this immutable authority rather than trusting a supplied
array. Attempts bind environment/source/bundle/plan/intent/build/target,
backup IDs/checksums, phase, classification, replay and performance. Failure
receipts expose closed reason codes, never arbitrary exception messages.

## 23. Privacy and security

Private Notes/Reviews/Reflection/acquisition/circulation bodies stay in source
and protected DB backups only. Evidence contains IDs/counts/hashes/reasons,
not raw mapper payloads. Synthetic canaries are scanned in evidence. Credentials
are not persisted in receipts. Local chmod/content-addressing detects accidental
mutation; it does not claim immunity from an operating-system owner deliberately
rewriting both code and evidence. All tests mutate only disposable fixtures.

## 24. Tests and closure protocol

Focused tests: `RehearsalGuardsTest`, `RehearsalSourceReviewTest`,
`GuardedRehearsalTest`, `RehearsalNativeBackupTest`. They cover all 57 forbidden
row cases, environment/runtime/authorization drift, exact source review,
immutable evidence, real participant apply, both interruption/resume routes,
replay, exact quarantine acceptance/refusal, native endpoint mismatch, independent
native backup/restore and successful/interrupted rollback. Existing target and
RUN/RECON tests remain part of the full gate.

Final gate: `./scripts/test-biblio-core-all.sh` (Composer/platform, syntax,
PHPStan, full unit/integration, WP smoke, manifest and whitespace). No browser/E2E.
Independent audit/review is separate from implementation. Review fixes include
native endpoint identity, unsupported DB-object rejection, checkpoint identity
before begin, stable semantic intent, callback failure lifecycle and canonical
seed neutrality. Final test/review results are reported in the completion report.

The INVALIDATED attempt's precommit gate completed with exit 0 in 590 seconds: 815 unit tests /
3,662 assertions (two existing PHPUnit notices), 612 integration tests /
7,928 assertions; Composer/platform, PHP syntax, PHPStan, WordPress smoke
(HTTP 200), manifest and whitespace passed. Focused rehearsal suites passed
5 unit / 145 assertions and 7 integration / 404 assertions. The actual
WP-CLI `eval-file --use-include` invocation in normal context refused at the
first environment guard, before setup/apply. Independent final code/docs
review: GO, conditional on the postcommit clean-SHA synthetic acceptance.

**Historical inconclusive evidence:** `.local/cutover-prep01b-validation/normal-before.json`
and `normal-after-gate.json` differ in both full data and schema hashes;
Biblio counts and User/Library/membership counts match. Read-only inspection
shows recent schema-health/WordPress/Elementor cache option rows, consistent
with ordinary WP smoke/bootstrap activity, but no per-table pre-gate snapshot
exists to prove that these are the only changes. Thus zero normal-database
impact is not proven. Do not restore/delete cache rows or silently replace the
baseline. The bounded forensic review did not resolve the gap. The old gate log hash is
`8da83d929bac2b0cb0cb8c526da64407a62b570a6785943d05346f9083723d18`.
After that full gate, the overlooked `Plugin::VERSION` constant was aligned
with the already bumped plugin header (`2.51.0`); the full gate must not be
represented as testing a final committed SHA or justifying the fresh cycle's
commit. No commit/push occurred during that invalidated attempt.

### Fresh prospective cycle

The user explicitly invalidated that attempt and authorized a completely new
acceptance cycle. `scripts/migration-normal-db-evidence.php` captures all normal
DB tables/columns in a read-only consistent PDO transaction, without loading
WordPress/config/plugins. It records counts, deterministic content/schema hashes,
all option-row hashes, all 57 Biblio tables, users/usermeta, triggers and database
charset/collation. No volatile table or field is excluded; schema includes
AUTO_INCREMENT. Artifacts have exclusive filenames, checksums and restricted
read-only permissions. `compare` requires exact watched-state equality.

The prospective baseline is `.local/cutover-prep01b-fresh-T7HvqKQz/normal-before.json`,
SHA-256 `5f88366d76f9c1b332150f065b3a28306e408141b6ccba13460ef026f67dba37`.
It DOES NOT establish equality with the pre-invalidated-run DB. All 73 current
tables are watched; read-only schema-version, empty-MIG-FND, identity/ownership
and obvious Item-reference sanity checks passed. Normal HTTP/application smoke
is deliberately not invoked, because WordPress can write operational state.

`scripts/test-migration-cutover-precommit.sh` copies the uncommitted candidate
into a new positively identified disposable DDEV project, not normal data or
config. Targeted tests and all Core gates run there. The smoke script's isolated
mode verifies the disposable project and exact URL before WordPress bootstrap
or HTTP; integration installation verifies its selected test database first.
The native transport test permits that positively flagged test host without
weakening production guards. No permanent maintenance file is used during the
full integration suite. After precommit GO, the original prospective baseline
continues to govern the exact-SHA postcommit rehearsal harness. Any difference
at either postcheck means HOLD, with no automatic second baseline.

### Resumed fresh precommit verdict — 2026-09-22

**GO — PROSPECTIVE ZERO NORMAL-DB IMPACT PROVEN** for the fresh precommit
cycle, not yet for postcommit exact-SHA closure. The usage-limit interruption
did not complete acceptance. Resume audit found HEAD still at PREP-01A, no
staged changes or intervening commits. The existing baseline checksum passed;
the direct pre-resume observation matched every watched object exactly.

Only affected tests were repeated during iteration. Database-default
charset/collation now participates in the full rehearsal fingerprint and native
restore verification. An independently restored database with wrong defaults
is refused. A RED/GREEN regression corrected the immutable identity guard's
table-name typo to `biblio_library_memberships`; changed membership bytes are
refused and exact PRE rollback is proven. PluginTest expects Core 2.51.0.
Final affected rehearsal suite: 8 tests / 419 assertions, passed.

The final broad gate ran once after those corrections, exclusively in
`biblio-v2-cutover-d713ab514700` with `BIBLIO_ISOLATED_ACCEPTANCE=1`:
815 unit tests / 3,662 assertions (two existing PHPUnit notices), 613
integration tests / 7,946 assertions, Composer/platform, syntax, PHPStan,
isolated WordPress plugin/init smoke and HTTP 200, manifest and whitespace.
Exit 0, 438 seconds. Independent final code review found no remaining blocker.
No normal WordPress/application smoke or cache cleanup was invoked.

Fresh evidence under `.local/cutover-prep01b-fresh-T7HvqKQz/`:

- `resume-audit-20260922.md` and `resume-review-20260922.md` record provenance.
- Main and isolated executable vectors are byte-identical, SHA-256
  `7a92aedf4883c41b4ff492eb3b68919e03126f472bd7b6727ef12025dd5eed44`.
- `resume-final-quality-gate-20260922.log`, SHA-256
  `7d845bcf8f8545c925e0dcfe5ef0a4977401a4083335b11b5ca8798cc9309555`.
- Immediate `normal-after-precommit-20260922.json`, SHA-256
  `9c70b4ae99c47dbcee7753f28088052894d3f7622157c17ca2991b417fb7a5de`.
- `precommit-comparison-20260922.json`: exact watched equality, no changed
  tables, global data/schema equal, no volatile exclusions. Observer, sanity
  flags and Core version also match the retained baseline; all sanity passed.

All 73 tables, 57 Biblio tables, six MIG-FND tables, users/usermeta,
Library/membership/configuration and all 185 option rows remain exact. This
authorizes only the previously requested single implementation commit stage.
The original baseline continues through exact-SHA acceptance. Closure evidence
is external and must identify the actual committed SHA; this document does not
pre-assert the outcome of that later run.

After the single implementation commit, run
`./scripts/test-migration-cutover-rehearsal.sh` with no arguments. It clones that
clean SHA into a unique disposable DDEV project, constructs only synthetic
source/approval, runs clean and both fault paths with native PRE/POST restore
proof, replay and rollback, and compares normal DB fingerprints. Artifacts live
under ignored `.local/cutover-prep01b-*/app/.local/evidence/` and self-identify the
actual SHA/dirty=false/runtime. They are not a FINAL rehearsal. Postcommit
evidence remains external to avoid a self-referential second docs commit;
any required follow-up commit needs separate authorization.

## 25. Versions and Git

Parent `92668dd2b9397d7efbdcf64601e4586bb9f06299`; remote safety ref
`origin/wip/shared-search-rebuild` verified at that parent. Product `v2.001`,
schema `1026` and UI `0.20.0` unchanged; Core `2.51.0`. Exactly one local
implementation commit after code/test/review GO, no push. Clean-SHA acceptance
must pass before reporting tooling closure. No dumps or source data tracked.

## 26. Remaining REHEARSAL-01 work

Explicitly designate/freeze/export/review real FINAL CURRENT SOURCE, complete
all population/exception/quarantine and circulation decisions, approve exact
runtime/target specification and operator intent, establish required durable
source/backup retention, run exact-source rehearsal and Renée's human QA, then
separately decide cutover authorization. None is inferred from synthetic GO.

## 27. Production gate

`FINAL CURRENT SOURCE NOT YET ACCEPTED`

`FINAL REHEARSAL NOT YET RUN`

`PRODUCTION APPLY NOT AUTHORIZED`
