# MIG-02-PRESERVE-01 — Durable mapper-only preservation

Status: **GO / CLOSED — PRESERVATION APPLY BLOCKER RESOLVED; PRODUCTION APPLY
STILL NOT AUTHORIZED**

Date: 2026-09-17
Product: v2.001
Schema: 1026 (unchanged)
Biblio Core: 2.44.0
Biblio UI: 0.20.0 (unchanged)

## 1. Authority and boundary

This slice implements, without reinterpretation,
`docs/130-d-mig-preserve-01-durable-mapper-only-preservation.md`. The existing
MIG-FND, RUN-01 and RECON-01 contracts remain authoritative. V1 is source
evidence, not product authority.

The slice introduces no production apply command or authorization, product
model, promotion/backfill, REST/UI surface, package-storage system, Series,
Wishlist, Archive, contained-work migration or loan backfill. No CURRENT
apply/import was run.

## 2. Pre-coding audit

The baseline was exact: parent
`f4617cef2c082675f43d1d1ba79339fc4dd59a59`, clean `main`, product v2.001,
schema 1026, Core 2.43.0 and UI 0.20.0.

The audit established:

- dry-run already consumed CURRENT mapper output, while apply and
  reconciliation consumed raw adapter records;
- one registry participant owns each source type;
- CURRENT catalog mapping composes classification, Item-local, Author,
  Reading, Note and Assessment mappers after raw adapter enumeration;
- observations persist canonical payload hashes and optional payload JSON;
  outcome commit owns mappings, preservation and quarantine transactionally;
- schema 1026 permits a committed source observation plus preservation row
  with zero target mappings;
- prior target lookup was mapping-oriented and therefore unsuitable for
  no-target cross-run preservation;
- reconciliation used raw inspection as its executable universe;
- restart skipped terminal observations only within one run; completed-run
  handling preceded no mapper-aware preparation;
- artifacts were allowlisted, but mapper-only Reflection findings could not
  survive apply;
- Item auxiliary evidence and circulation already had participant-owned
  durable routes and must not be duplicated.

No missing product or architecture decision was found. The exact change seams
were runner preparation, apply preflight, reconciliation input, one closed
typed plan/participant, exact cross-run lookup, CURRENT Assessment handoff and
an internal package verifier.

## 3. Prepared mapping stream and digest

`MigrationPlanPreparer` produces one `PreparedMigrationPlan` from immutable
inspection plus exact User/Library target. It contains prepared typed records,
sanitized planning failures, diagnostic findings, mapper-contract identities
and a deterministic `plan_set_digest`.

The digest binds adapter ID, source family/version, manifest, explicit target,
current build provenance, mapping contracts, sorted record identities,
canonical payload hashes, references, dispositions/reasons and privacy-safe
findings. It contains no random ID, timestamp or restricted body.

Dry-run, apply and reconciliation use this same prepared semantic universe.
Apply independently prepares it and compares the accepted digest before
`BeginMigrationRunService`; a stale or changed plan therefore fails before the
first ledger write. Artifact contract version 3 exposes only safe prepared
provenance and the digest.

## 4. Typed no-target preservation

`PreservedSourceEvidencePlan` is a closed `TypedMigrationPlan`, not an
arbitrary key/value escape hatch. It binds:

- semantic source identity and evidence type;
- fixed `preserved_deferred` planning disposition and bounded reason;
- adapter, source family/version and manifest SHA-256;
- reviewed mapper-contract identity;
- source-relative file, collection, entity and field locator;
- canonical evidence SHA-256;
- closed privacy class and positive occurrence count.

Its canonical payload and evidence descriptor contain no restricted body.
`PreservedSourceEvidenceAdmissionRegistry` admits only an explicit
evidence-type/reason/privacy triple. Production currently admits only reviewed
CURRENT Reflection evidence.

`PreservedSourceEvidenceMigrationParticipant` contains no CURRENT semantics.
Its guard proves record, typed plan, observation and provenance agreement. The
existing `CommitMigrationRecordService` transaction commits the source
observation and `awaiting_future_processing` preservation row with exactly zero
product mappings. No fake Work, Note, Assessment or preservation target is
created.

## 5. Replay, divergence and reconciliation

The ledger lookup searches all committed preserved observations for the exact
target User, target Library, source family, fixed preservation source type and
semantic source ID. It intentionally has no payload-hash or run-status
prefilter, so committed evidence remains visible when its owning run is
running, interrupted, failed or completed.

Every match must equal the prepared record in payload hash, manifest, source
version, canonical payload, observation/preservation reason, evidence
descriptor and locator. Multiple equivalent matches are reusable; any one
divergent match fails closed. There is no newest/oldest selection and no
last-write-wins behavior.

Reconciliation retains raw source inspection for category accounting but uses
the prepared executable universe for expected observations. Exact prior
preservation may satisfy a deliberately omitted current-run observation. It
also verifies preservation disposition/reason/status and zero mappings.
Interruption/resume preflights already committed evidence, reuses it and
commits only missing records.

## 6. Privacy and recovery

MIG-FND stores only the privacy-safe descriptor, source-relative locator and
evidence hash. The immutable manifest-bound source package remains authority
for restricted content; no absolute developer path is durable identity.

`CurrentV1RestrictedSourceEvidenceResolver` is an internal non-returning
verifier. It admits only the reviewed CURRENT Reflection locator shape and
checks adapter/source/version, package manifest, exact Book identity, exact
field and the canonical source-slot-plus-body hash. Wrong provenance, missing
or duplicate source entities, changed bytes and changed bodies fail with a
fixed safe exception. No REST, UI or general content-reading API was added.

## 7. CURRENT mapper handoff

Each valid Reflection now yields one typed `preserved_source_evidence` record
with identity `v1.book/<book-id>/reflection`, evidence type
`current_v1_reflection`, reason `reflection_target_not_available`, restricted
privacy, logical `data/books.json#books/<id>/reflection` locator, manifest and
Assessment mapper-contract binding, and a distinct canonical evidence hash.

The privacy-safe finding remains diagnostic and links to its planned identity;
it does not become a second source observation. CURRENT Assessment planning is
therefore 15 Rating plans + one WrittenReview plan + five executable Reflection
preservation plans = 21 observations.

Only Reflection is wired by this slice. Item auxiliary evidence and
circulation remain on existing participant-owned routes. Classification,
Author, Reading and contained-work populations without a complete approved
executable preservation contract remain diagnostic or deferred; the 7,922
finding occurrences were not bulk-converted.

## 8. Synthetic and regression evidence

Targeted evidence collected during implementation:

- preservation apply/replay/divergence/resume/multiple-prior integration:
  `OK (9 tests, 137 assertions)`;
- restricted CURRENT recovery: `OK (2 tests, 6 assertions)`;
- CURRENT Assessment mapper: `OK (7 tests, 114 assertions)`;
- runner preparation/artifact behavior: `OK (10 tests, 46 assertions)`;
- migration shell/CURRENT dry-run regression: `OK (8 tests, 106 assertions)`;
- PHPStan: `No errors`.

The independent review found and closed three pre-commit blockers: prepared
provenance now fails before the first run write; reconciliation verifies the
exact descriptor and locator without loading restricted evidence into PHP; and
the acceptance suite now covers mixed mapped/preserved streams, active-first
interruption, finding linkage, multiple-prior divergence and every provenance
dimension. Its final verdict was GO, conditional only on the final full gate
and exact-commit trial.

The preservation integration proves zero dry-run writes, one observation plus
one preservation and zero mappings, exact reason/disposition, replay through
running/interrupted/failed/completed owning-run statuses, equivalent multiple
matches, divergent hash/reason/locator/contract/manifest refusal before a new
run write, stale-digest refusal, interruption after one commit, missing-only
resume, deterministic reconciliation and restricted-body absence from
artifacts and exceptions.

## 9. Final gates and CURRENT zero-write trial

The final pre-commit full gate passed: Composer metadata and platform checks,
PHP syntax, PHPStan, `777` unit tests with `3236` assertions, `589` integration
tests with `6711` assertions, WordPress smoke (`Plugin: active`, `Class:
loaded`, `Init hook: 1`, `HTTP: 200`), manifest JSON and `git diff --check`.

The exact committed trial revision and zero-write CURRENT evidence are recorded
in the completion report for this slice because a commit cannot contain its own
SHA. The CURRENT acceptance operation is dry-run only against the designated
ZIP/extraction and manifest; table fingerprints must be exactly equal before
and after. Production apply/import remains prohibited.

## 10. Gate state

The specific blocker “mapper-only `preserved_deferred` evidence cannot survive
apply” is resolved.

`PRODUCTION APPLY STILL NOT AUTHORIZED`: remaining CURRENT mapping lanes, the
final export, cutover rehearsal and explicit Renée authorization remain
separate gates.
