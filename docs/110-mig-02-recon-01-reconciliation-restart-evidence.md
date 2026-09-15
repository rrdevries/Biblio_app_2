# MIG-02-RECON-01 — Reconciliation and restart evidence

Status: **GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

Scope: source-neutral migration infrastructure and acceptance evidence only.
No current V1 adapter, source-field mapping, product semantic change, schema
change, public apply CLI, repair command, trial or cutover is included.

## 1. Pre-coding audit

The slice started from clean local `main` at
`5352a0df053743c9db9ac6a3de203c7fcecdae84`, 43 commits ahead of
`origin/main`. Product was `v2.001`, schema `1026`, Biblio Core `2.32.0` and
Biblio UI `0.20.0`.

MIG-FND already represented run states `planned|running|interrupted|failed|
completed`, observation states `observed|processing|committed|
retryable_failure|terminal`, six dispositions and zero-to-many created/reused
mapping edges. Begin, deterministic identity, observation replay, target lock
and the atomic commit boundary were sufficient. Missing were complete source-
versus-ledger accounting, typed target validation, dependency-safe internal
apply orchestration and acceptance-grade restart proof. Re-enumeration is the
correct checkpoint; no durable cursor or lifecycle extension is necessary.

RUN-01 artifact format was version 1. Category counts are adapter-provided,
source-type counts are derived from actual enumeration, and production has no
current V1 adapter or expected V1 totals.

## 2. Reconciliation model

`MigrationReconciliationService` is read-only. It receives the exact immutable
source inspection, a privacy-safe MIG-FND snapshot, injected source-type mapping
contracts and injected target-state inspection. It cannot rewrite observations,
mappings, dispositions or product records.

The typed ledger snapshot excludes payload JSON and quarantine/preservation
evidence. It exposes only run scope, source identity/hash, processing,
disposition/reason and committed mapping edges.

## 3. Source accounting

Each enumerated type+ID+payload-hash must match one ledger observation. The
report separates dispositions, uncommitted, unexplained and unexpected state.
Its acceptance equation is `enumerated = dispositions + uncommitted +
unexplained`. Adapter category/type totals, unknown categories and structural
findings remain explicit; unknown or unsupported data is never forced into a
fabricated mapping.

Each adapter can additionally declare source-neutral category strategies. A
strategy assigns every enumerated source type to one category and separately
counts category records that intentionally do not become observations under a
stable reason code. Acceptance rejects missing strategies, duplicate type
ownership and category totals that do not reconcile exactly.

## 4. Target and mapping accounting

Created/reused totals come only from committed MIG-FND edges. Per-source
contracts validate required and optional target types. Run-scoped reads prevent
cross-target counting, while typed repositories validate target existence and
User/Library scope.

The stored MIG-FND run is authoritative for source and target scope; a caller-
supplied mismatch fails closed. Observation family/snapshot drift and mapping
edges whose run and observation disagree remain visible as unexpected,
unclassified and broken ledger state rather than disappearing from totals.

Each current target type has a maximum-one cardinality unless a future contract
explicitly declares otherwise. Typed inspection also validates the actual
Edition→Work, Item→Edition/Work, contributor→Author/Work, ReadingRound→Work/Item
and Private Note→Work/optional-Round graph, including current canonical replay
facts; a merely existing but wrongly linked target fails reconciliation.

Current checks cover Work, Edition, canonical ISBN claim, Item,
LibraryCatalogContext, Item-local details, Author, contributor credit,
WorkContributor, ReadingRound and Private Note. A missing/wrong/cross-target
mapping is reported as broken and never repaired.

## 5. Entity and relation accounting

Mapping rules classify edges as entity or relation. The full synthetic run
proves seven entity edges (Work, Edition, Item, Author, contributor credit,
ReadingRound, Note) and two relation edges (LibraryCatalogContext,
WorkContributor). Contributor and Item observations retain legitimate multiple
mappings without being counted as multiple source observations.

## 6. Preservation and quarantine

Preserved and quarantined observations remain in disposition totals and are
grouped by source type and stable reason code. Neither class globally blocks
acceptance; unexplained loss does.

## 7. Acceptance rules

The typed result exposes independent flags for exact source accounting,
complete strategies, zero uncommitted, zero unexplained drops, zero unexpected
observations, zero failures, zero unresolved required references and zero
broken committed mappings. The internal coordinator requires this stronger
read gate before the existing completion transition. Schema 1026 and all
current lifecycle states remain unchanged.

## 8. Reconciliation artifact

Artifact format 2 adds dry-run planning reconciliation and internal
`apply_reconciliation` evidence. It includes source/build/target/run provenance,
participant/disposition/reason totals, entity/relation created/reused totals,
uncommitted/unexplained/unresolved/broken counts, acceptance flags and
execution-local created/reused/skipped counts.

Canonical JSON contains no run timestamp. Repeated reads of the same source,
build, database and run state are byte-identical. The existing writer atomically
replaces JSON plus companion SHA-256 outside the source root; reconciliation
filenames include target scope. Artifacts remain ignored/local.

## 9. Privacy

Artifacts contain only bounded provenance, counts, stable codes, privacy-safe
source identities/hashes and necessary target IDs. No Note body, raw migration
payload/evidence, credential or secret is emitted. Tests assert a private Note
sentinel and `payload_json` are absent.

## 10. Interruption and resume

`MigrationApplyRunner` is internal and has no CLI registration. It uses the
shared inspection/plan, orders records by explicit dependencies, observes the
canonical payload and invokes participants only through
`CommitMigrationRecordService`. A deterministic boundary interrupts after N
finalized observations.

Restart rebuilds the immutable inspection, resumes the existing run, skips
committed observations and processes remaining/retryable records. No cursor,
best-effort policy or nested transaction is added.

## 11. Clean versus resumed equivalence

The synthetic package exercises all seven CAT/AUTH/READ/NOTE participants. An
interrupted+resumed target and equivalent clean target produce equal
participant/disposition/entity/relation totals and equal target-local semantic
graph counts. Opaque generated IDs are deliberately not compared.

## 12. Identical second-run proof

The exact same package/adapter/target resolves to the completed MIG-FND run.
All seven observations are skipped, execution-local created targets are zero,
no product writer runs, no relation duplicates appear and repeated artifacts
have the same checksum. Historical run mappings retain their original truthful
created/reused evidence.

## 13. Changed-source handling

Changing one byte changes the manifest digest and produces a distinct run; it
cannot resume the old snapshot. Exact unchanged logical payload reuses all nine
existing target edges and creates zero targets. Existing participant tests
retain divergent-payload rejection. No delta migration is added.

## 14. Broken-target detection

After an isolated complete run, the test deletes its mapped Private Note.
Reconciliation reports one broken target, rejects acceptance and leaves it
absent. There is no auto-repair.

## 15. Dry-run integration

Dry-run format 2 reports enumerated/planned observations, participants,
operations, unsupported types, planning errors and unmatched references, with
`applied=false` and `accepted=false`. The existing before/after table-count
proof remains zero-write for product and MIG-FND.

## 16. CLI and apply boundary

The public surface remains only `wp biblio migration profile|dry-run`. No
`apply` or `reconcile` command is registered. Without a supported current
adapter and reviewed mappings, a production command would be misleading.

## 17. Remaining readiness blockers

RECON-01 closes reconciliation/restart infrastructure but does not authorize a
real trial or current-export request. Remaining considerations are conditional
source populations/mappings, Renée's evidence-bound open-circulation decision,
an isolated trial DB plus tested backup/restore, and a current supported source
adapter/version.

## 18. Tests and quality gates

Focused evidence:

- `MigrationReconciliationRestartTest`: 3 tests / 112 assertions;
- `MigrationFoundationTest`: 11 tests / 124 assertions;
- `MigrationRunnerShellTest`: 5 tests / 49 assertions;
- `MigrationRunnerTest`: planning-reconciliation unit coverage;
- focused PHP syntax/PHPStan: green.

Final acceptance evidence:

- Composer metadata/platform: green;
- complete PHP syntax and PHPStan: green;
- unit suite: 705 tests / 2,783 assertions (two existing PHPUnit notices);
- integration suite: 558 tests / 6,385 assertions;
- WordPress smoke: plugin active, class loaded, init hook 1, HTTP 200;
- manifest JSON and `git diff --check`: green;
- complete Core gate: green in 444 seconds;
- independent review: GO, no blocker/high findings.

The reviewer noted one non-blocking concurrency residual: target validation
uses several repository reads rather than one database snapshot, so a
simultaneous normal product mutation can conservatively produce a mixed/failed
read. Reconciliation never repairs it and a later read observes current state.
No browser/E2E was required because REST/UI did not change.

## 19. Actual V1 data rule

No current V1 export was requested, read or inferred. MIG-01, DATA-01,
`.local/fixture-source`, old ZIPs and old counts remain historical evidence.
Only a future export explicitly designated by Renée supplies real inventory,
reviewed mapping expectations and real reconciliation totals.

## 20. Versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.32.0 -> 2.33.0`.
- Biblio UI: `0.20.0` unchanged.

## 21. Git

Exactly one local implementation commit is created after final gates and
independent review. Nothing is pushed. No safety branch is needed because the
slice began from a clean committed `main` and is bounded to migration
infrastructure, tests, versions and closure evidence.
