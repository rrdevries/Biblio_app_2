# MIG-02-NOTE-01 — Historical private Note migration participant

Status: **GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

## 1. Pre-coding audit

The slice started from clean local `main` at
`784404fb6d0c5c03b230c41179530bcb8f7adae5`, 42 commits ahead of
`origin/main`. Product was `v2.001`, installed/source schema `1026`, active
Biblio Core `2.31.0` and active Biblio UI `0.20.0`.

Current Note truth is one immutable owner plus Work, optional same-owner/same-
Work ReadingRound context, canonical safe HTML, required technical UTC
creation/update instants and positive optimistic version. There is no Library
scope, publication, visibility state, Item/Edition relation, soft delete,
provenance or historical business-time field.

## 2. Decision inheritance

This slice inherits F2.7, MIG-FND-01, MIG-02-RUN-01, MIG-02-CAT-01 and
MIG-02-READ-01. V1 remains evidence rather than authority. No historical
fixture or current export was used, and no source text, status, timestamp or
relationship was interpreted.

## 3. Participant contract

Production registers logical source type `private_note`. `PrivateNotePlan`
contains explicit target User, Work source identity, optional ReadingRound
source identity, already-approved `PrivateNoteContent` and explicit technical
creation/update instants. The stable source Note identity remains the
`MigrationSourceRecord::sourceId()`.

The source record, typed plan, planned record and observation must retain one
canonical payload hash. Planning and apply use the same typed object.

## 4. Note ownership / privacy

Plan User, planning target User and MIG-FND run target User must match, and the
platform User must be active. The resulting Note owner is that exact User.
There is no current-actor, administrator, first-user, display-name, Library-
owner or migration-privilege fallback. No public state exists or is added.

## 5. Work dependency

Planning declares one `catalog_work` dependency. Apply accepts exactly one
committed `catalog_work → work` mapping in the same source-family and exact
target user+Library scope, and the mapped Work must still exist. No title,
ISBN, Edition, Item, Author or Series lookup exists.

## 6. ReadingRound dependency

The dependency is optional. When present, apply requires exactly one committed
`reading_round → reading_round` mapping and an existing Round for the target
User and mapped Work. Missing, wrongly typed, cross-owner or cross-Work targets
fail closed. Work-only Notes remain valid; no date, ordering or nearest-Round
inference exists.

## 7. Note content

Planning and apply reuse `StrictPrivateNoteContentPolicy`. It accepts only
valid UTF-8, visible text, at most 65,535 bytes and the exact safe HTML elements
`p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `blockquote` without attributes.
Invalid or non-canonical content fails with a privacy-safe reason; no Note body
is included in the message.

## 8. Historical timestamps

The current Note aggregate and table contain only required technical
`created_at` and `updated_at`, constrained by supported UTC range and
`updated_at >= created_at`. The plan therefore requires both explicit,
already-approved instants and persists them exactly. It does not inject a
clock, import time or current time.

There is no nullable/independent `noted_at` semantic. A future mapping that
needs unknown Note time or an independent business instant must HOLD for a new
product decision; this slice does not speculate or add schema.

## 9. Migration mapping / identity

Successful apply returns one truthful `private_note → private_note` mapping
with created/reused disposition. MIG-FND source identity, not content, Work,
Round or timestamp similarity, controls replay identity. Identical content
under distinct source IDs therefore creates distinct Notes.

## 10. Transaction / rollback

`PrivateNoteMigrationWriter` starts no transaction and performs no transaction
retry. It reuses the existing aggregate, bounded opaque-ID collision path and
owner-/Round-validating repository inside `CommitMigrationRecordService`.
Injected Note persistence, mapping persistence and late outcome failures prove
that Note and ledger outcome roll back together.

## 11. Replay / conflicts

Prior reuse requires one exact mapping, equal payload hash and equal canonical
owner, Work, optional Round, content, technical timestamps and initial version.
Changed payload or later target mutation fails closed rather than editing the
Note. Reverse trace validation prevents another source identity from sharing
the target, and target contexts never reuse one another's mapping.

## 12. Privacy-safe artifacts

Dry-run emits only `create_or_reuse_private_note` plus Work and optional Round
dependency identities. `PlannedMigrationRecord::toArray()` omits the typed
payload, so Note body and timestamps never enter the artifact. Failure text is
source-neutral and content-free.

## 13. Normal read proof

The existing `GetMyPrivateNotesForWorkService` returns the migrated Note to its
owner through the render-validation boundary, returns no Note for another User
and performs no write. No migration-only read endpoint exists.

## 14. Dry-run integration

A synthetic typed adapter exercises the production participant twice. The
artifact bytes are deterministic, all Core table counts remain equal and the
private body plus timestamps are absent.

## 15. Explicitly deferred

Current V1 adapter/export, Note source-field mapping, Note-vs-Review or
reflection classification, source text conversion, Rating/Review migration,
Wishlist, Collections/Series, loans, archive, reconciliation, apply CLI/trial,
cutover and Note UI redesign remain separate. No network or provider is used.

## 16. Tests / quality gates

Focused evidence before the final gate:

- `PrivateNotePlanTest`: 3 tests / 4 assertions;
- `PrivateNoteMigrationParticipantTest`: 7 tests / 53 assertions;
- `MigrationRunnerShellTest`: 5 tests / 45 assertions;
- existing `PrivateNotePersistenceTest`: 8 tests / 147 assertions;
- PHP syntax and `git diff --check`: green.

The one complete Core gate passed in 574 seconds:

- Composer metadata and PHP 8.3 platform requirements: green;
- full PHP syntax scan: green;
- PHPStan: no errors;
- unit suite: 705 tests / 2,773 assertions, with 2 PHPUnit notices and no
  failures;
- integration suite: 555 tests / 6,266 assertions;
- WordPress smoke: plugin active, class loaded, init hook 1 and HTTP 200;
- manifest JSON and final `git diff --check`: green.

The explicit independent second review re-read the final diff against the
brief, canonical Note/MIG-FND contracts, authorization, privacy, transaction
ownership, replay and regression risk. It found no blocking or material
finding. In particular, typed Note content is retained only in the established
MIG-FND observation evidence boundary needed for hashing/replay; repository
database errors are suppressed there, while artifact, operation, dependency
and failure text remain content-free.

## 17. Actual V1 data rule

No current V1 export was requested or inspected. Historical MIG-01, DATA-01,
`.local/fixture-source`, old ZIPs and counts remain historical evidence only.
The future explicitly designated export decides whether Notes exist and how
source identity, content format, Work/Round relation and timestamp semantics
map into this already-typed V2 boundary.

## 18. Schema/Core/UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.31.0 → 2.32.0`.
- Biblio UI: `0.20.0` unchanged.

## 19. Git

One local implementation commit is created only after final gates and review.
Nothing is pushed. No safety branch is needed because the slice started from a
clean committed `main` and changes only the bounded participant, tests,
composition, version and canonical closure evidence.
