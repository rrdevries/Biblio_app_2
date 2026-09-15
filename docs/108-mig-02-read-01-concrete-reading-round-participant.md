# MIG-02-READ-01 — Concrete ReadingRound migration participant

Status: **GO / CLOSED**

Date: 2026-09-15

Product: `v2.001`

Schema: `1026`

Biblio Core: `2.31.0`

Biblio UI: `0.20.0`

## 1. Decision inheritance

This slice inherits ADR-007, READ-MIG-01, MIG-FND-01, MIG-02-RUN-01 and
MIG-02-CAT-01. The 2026-09-15 product decision adds immutable
`migration_imported` provenance for a controlled import of a concrete
ReadingRound. Provenance is not lifecycle, outcome, source interpretation or
permission to relax a date invariant.

The only lifecycle is active or ended. The only ended outcomes are
`completed` and `stopped`; `paused` does not exist. Rereading creates another
round. Personal Reading Truth remains the separate boundary for Work-level
knowledge such as read-known/date-unknown.

## 2. Typed participant contract

Production registers one participant for logical source type `reading_round`.
`ReadingRoundPlan` contains only approved V2 target meaning:

- explicit target `UserId`;
- exact CAT Work source identity;
- nullable canonical outcome, from which lifecycle is derived;
- a precision-preserving `ReadingPeriod`;
- an optional explicit CAT Item source identity.

The stable source-round identity is `MigrationSourceRecord::sourceId()` and is
not reconstructed from User, Work, state or dates. The canonical payload has
no raw V1 names or values. Plan and apply are guarded by the same deterministic
payload hash.

## 3. State, date and source rules

An active plan has `outcome=null`, no finish date, an exact start day and an
explicit Item source mapping. An ended plan has `completed|stopped` and a
mandatory finish date; its optional start and finish retain exactly day,
month or year precision. Ended rounds may be source-free or may use an
explicit Item source. No current V2 rule permits an ended concrete round with
unknown finish date, so that shape fails before apply.

The participant never infers active from a missing end date, completed from a
rating/date/text, stopped from a legacy status, or Item from mere availability.
ExternalLoan migration and circulation mapping remain deferred because no
corresponding MIG-02 source participant has been accepted.

## 4. Exact dependencies and ownership

Every plan declares `catalog_work`; an explicit Item source additionally
declares `catalog_item`. Apply accepts exactly one committed Work mapping in
the current MIG-FND target/source-family scope and checks that the Work still
exists. An Item mapping must resolve to an existing Item in the run's target
Library whose Edition belongs to that exact Work. There is no title, ISBN,
Edition-title, Author, provider or network fallback.

The plan User, planning target User and run target User must be identical, and
the platform User must be active. The resulting ReadingRound is owned by that
User; neither administrator nor Library becomes owner. Existing reads remain
owner-scoped and non-enumerating.

## 5. Mapping, replay and transactions

`ReadingRoundMigrationWriter` joins the caller-owned
`CommitMigrationRecordService` transaction and starts no transaction or retry.
After resolving the Work it takes the established user×Work mutation lock.
The ReadingRound insert and truthful `reading_round → reading_round` mapping
therefore commit or roll back with the MIG-FND outcome.

Prior reuse requires one exact target mapping, the same payload hash, an
existing owner-scoped round, exact Work/source/outcome/period and provenance
`migration_imported`. Reverse trace validation prevents another source-round
identity from sharing that target. Changed payload, unexpected/multiple
mapping or divergent canonical state fails closed. User+Work or date equality
never deduplicates rounds, so first read, rereads and stopped attempts remain
separate occurrences.

## 6. Read and delete semantics

The existing Reading History projection treats an imported ended round as a
normal entry and sets `historical_registration=false`. Catalog completed and
stopped counts include it normally, while the exact
`historical_completed_rounds` predicate remains restricted to
`historical_manual`. Completed imported rounds participate in the existing
first-read/reread/indeterminate chronology. The special historical hard-delete
service still accepts only `historical_manual`, so `migration_imported` is not
deletable through that route.

No Personal Reading Truth row is created or changed by this participant.

## 7. Schema 1026

Schema 1026 changes no table or column. It replaces the named
`reading_rounds_provenance` and `reading_rounds_start_shape` checks so they add
the exact `migration_imported` shapes. Existing `legacy_source_started`,
`source_started` and `historical_manual` meanings and all outcome, calendar,
period, source-XOR, timestamp, version, FK and active-source uniqueness checks
remain intact. The migration is forward-only, health-checked and retry-safe
for exact source or completed target state.

## 8. Dry-run and normal reads

RUN-01 dry-run uses the production participant and the exact typed plan. Its
artifact contains the allowlisted create/reuse operation and dependency
references, but not the typed payload, outcome or period. Before/after counts
cover every Core table and establish zero ReadingRound, Personal Reading Truth
or MIG-FND writes. No migration-only read endpoint exists; integration proof
uses the normal owner history, catalog projection and reading-sequence service.

## 9. Explicitly deferred

This slice does not inspect or request a current V1 export and does not decide:

- which V1 records are concrete rounds;
- source status, paused, outcome, date or source mapping;
- reread reconstruction from raw source data;
- concrete round versus Personal Reading Truth mapping;
- ExternalLoan/circulation import;
- Notes, assessments, Wishlist, archive, reconciliation or correction/removal
  of imported reading history;
- apply CLI, trial or cutover.

Historical V1 fixtures remain evidence only, never current mapping authority.

## 10. Acceptance evidence

Focused unit and integration coverage proves typed state/date validation,
schema upgrade/retry/drift rejection with data preservation, exact Work/Item
dependencies, ownership/privacy, distinct same-Work rounds, exact cross-run
replay, reverse-target and changed-payload failure, separate round-write,
mapping-write and late-outcome rollback, exact-replay convergence, distinct
user×Work serialization, persisted source correction, zero-write dry-run,
normal history consumption, first-read participation, manual-count exclusion
and delete refusal. The final complete Core gate passed with 702 unit tests /
2769 assertions and 547 integration tests / 6203 assertions; Composer/platform,
PHP syntax, PHPStan, WordPress smoke, manifest and `git diff --check` were green.
The two pre-existing PHPUnit notices remain non-blocking. Independent re-review
reported GO with no remaining blocker or high-severity issue.

No browser/E2E run is required because neither UI nor REST changes.
