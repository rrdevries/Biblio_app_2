# MIG-02-ASSESS-MAP-01 — CURRENT V1 assessment mapper

Status: **GO / CLOSED — PRODUCTION APPLY BLOCKED**

Date: 2026-09-17

## 1. Pre-coding audit

The implementation audit confirmed the exact doc-128 source shapes: one integer
Rating slot on each Book, one Review array with a sole CURRENT occurrence and
one singular Reflection string slot. The designated manifest contains 15
positive Ratings, one Review and five non-empty Reflections. The distribution
is 1×1, 3×1, 4×7 and 5×6; 1,124 zero values are empty slots. Exactly one Rating
belongs to a CAT duplicate-ISBN group and its peer carries no assessment.
Neither CAT-quarantined Book carries assessment evidence.

Schema 1026 already contains Rating and WrittenReview persistence, nullable
`assessed_at`, nullable ReadingRound links, owner/cardinality constraints and
private publication separation. `HistoricalAssessmentRecorder` already owns
canonical product creation without opening a transaction. No suitable typed
migration plans or participants existed. No schema or product decision was
missing after the explicit Reflection scope decision.

## 2. Decision inheritance

Doc 128 remains semantic authority. Rating and WrittenReview remain separate;
Reflection is neither. Active plans use only the explicit migration target User
and the exact CAT Work mapping. There is no title, ISBN, Edition, Item, actor,
admin, owner or name fallback; no Round, publication or missing timestamp is
inferred.

For Reflections, semantic disposition and persistence are deliberately
separate:

```text
semantic disposition: PRESERVE_DEFERRED
reason: reflection_target_not_available
persistence in this slice: privacy-safe dry-run finding only
```

## 3. Mapper architecture

`CurrentV1AssessmentMapper` is wired after CAT, classification, Item-local,
Author, Reading and Note mapping. It accepts only the reviewed manifest under
contract `d-mig-assess-map-01.2026-09-17`, consumes CAT's ready Work
representative map and performs no writes.

The mapper creates two distinct typed record kinds:

- `historical_rating` with `HistoricalRatingPlan`;
- `historical_written_review` with `HistoricalWrittenReviewPlan`.

There is no generic `AssessmentPlan`. Findings use privacy-safe source
identities, dispositions, reason codes and optional SHA-256 evidence only.

## 4. Rating migration participant

`HistoricalRatingPlan` binds target User, Work source ID, canonical
`RatingValue`, nullable `assessed_at` and an explicitly nullable Round source
ID. CURRENT always supplies null time and null Round. Its canonical payload
also fixes private visibility, no publication and version 1.

`HistoricalRatingMigrationParticipant` declares only the exact Work dependency
unless a separately reviewed plan explicitly supplies a Round. The writer
requires one committed mapping, validates run scope, target cardinality, replay
payload and reverse mapping exclusivity, then delegates to the existing
recorder.

## 5. WrittenReview migration participant

`HistoricalWrittenReviewPlan` keeps validated `ReviewContent`, the exact source
instant as `assessed_at`, target User, Work source ID and null CURRENT Round.
It fixes private visibility, no publication and version 1.

The separate participant applies the same exact dependency, replay and
cardinality rules. Review body and timestamp are part of the protected typed
payload/hash but are absent from plans projected into artifacts, findings,
logs and exceptions.

## 6. HistoricalAssessmentRecorder reuse

`HistoricalAssessmentMigrationWriter` resolves committed Work and optional
Round mappings and calls only `HistoricalAssessmentRecorder` for product
creation. The outer `CommitMigrationRecordService` remains the sole transaction
owner, so late failure rolls back product and mapping together. No assessment
persistence, ownership, publication or domain invariant is duplicated.

## 7. Rating mapping

Each positive slot uses identity `v1.book/<book-id>/rating`. The value is not
identity and passes through `RatingValue::fromStars`. Zero produces no
observation. The 15 CURRENT plans remain Work-scoped, private, unpublished,
without Round and with `assessed_at = NULL`.

## 8. Review mapping

The sole Review uses identity `v1.book/<book-id>/review/1`. Content passes
through `ReviewContent`; the exact validated UTC millisecond instant becomes
`assessed_at`. No rewrite, Note conversion, Round or publication is added.

## 9. Reflection preservation

Each non-empty valid Reflection uses
`v1.book/<book-id>/reflection` and emits one finding with disposition
`preserved_deferred`, reason `reflection_target_not_available` and a
deterministic evidence hash over the structural slot and restricted body. The
body never leaves the immutable source package.

This slice creates no durable Reflection source observation, Reflection plan,
participant, product target or mapper-aware apply seam. The five findings are
dry-run evidence only, not the definitive cutover preservation mechanism.

## 10. Work / User dependencies

Active assessment plans contain the explicit target User and depend only on
their parent Book's `v1.book/<book-id>/work` identity. Apply requires its exact
committed `catalog_work → work` mapping and the validated run Library context.
Missing, foreign, broken or wrongly typed dependencies fail closed.

## 11. ReadingRound boundary

CURRENT supplies no explicit Rating or Review Round relation. Both plans use a
null Round. The mapper neither reads nor mutates ReadingRound outcome,
Personal Reading Truth, status or reread chronology.

## 12. Alias / conflict handling

CAT representative resolution is reused without ISBN special-casing. Two
source Ratings or Reviews converging on one canonical Work are quarantined as a
cardinality conflict, even when equal. No record order, maximum, average,
representative or content equality chooses a winner. The CURRENT source has no
such conflict.

## 13. Privacy

Dry-run records expose only operation and dependency identities. Findings
contain no Review or Reflection text or source timestamp. Reflection hashes
permit separate reconciliation of the five structural observations without
turning body content into identity. Synthetic tests use synthetic private text
only.

## 14. CURRENT mapping totals

| Outcome | Count |
|---|---:|
| Rating active plans | 15 |
| WrittenReview active plans | 1 |
| Reflection `preserved_deferred` findings | 5 |
| quarantined | 0 |
| alias/cardinality conflicts | 0 |
| unmatched dependencies | 0 |
| total accounted assessment-like observations | 21 |

These totals are recomputed from the pinned source, not hardcoded in runtime
logic.

## 15. Reconciliation

The mapping contract requires exactly one `rating` or `written_review` entity
for each active observation. Target inspection validates the run User, exact
Work mapping, nullable Round, canonical value/content, historical time and
version 1. Exact replay reuses; divergent payload, wrong target state, multiple
mapping or reverse-source reuse fails closed.

The five Reflections reconcile in dry-run through distinct source-slot findings
and evidence hashes only. They do not enter durable MIG-FND reconciliation in
this slice.

## 16. Zero-write proof

The final acceptance run uses only profile/dry-run against the exact clean
committed SHA in the guarded isolated trial. All 57 Biblio table fingerprints
must be byte-identical before and after; the normal environment guard must also
remain unchanged. No Rating, WrittenReview, publication or MIG-FND row is
written and no apply/import command is run.

## 17. Trial build provenance

The guarded trial is `biblio-v2-migration-trial` with database
`biblio_migration_trial`, schema 1026, Core 2.43.0, UI 0.20.0, adapter
`current-v1-json-29`, source version
`books-29.authors-2.reading-goals-2` and manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
The exact self-identifying implementation SHA, artifact checksum and table
fingerprints are retained in ignored trial evidence and the completion report;
the commit cannot contain its own final SHA without changing that SHA.

## 18. Explicitly deferred

Deferred are the Reflection product model/UI, durable Reflection MIG-FND
observation, Reflection participant, mapper-aware apply seam, assessment
publication workflow, Series, Wishlist, Archive, contained works, loan backfill
and every production apply/import.

Before any real CURRENT apply/import, a separate bounded slice must design and
implement durable preservation of mapper-only / auxiliary
`preserved_deferred` evidence with replay and reconciliation. Until it is GO /
CLOSED:

```text
PRODUCTION APPLY = BLOCKED
```

## 19. Tests / quality gates

Focused unit and integration coverage proves mapping shape, private typed plans,
exact dependencies, alias conflicts, participant writes, replay/divergence,
rollback, target reconciliation, dry-run determinism, zero writes and artifact
privacy. Final acceptance additionally runs the full Core gate, PHP syntax,
PHPStan, Composer platform requirements, WordPress smoke, manifest validation,
whitespace validation, exact source/trial checks and an independent review.

## 20. Current V1 data rule

Only the designated immutable ZIP and validated extraction are CURRENT:

- ZIP SHA-256: `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
- manifest: `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`;
- adapter/version: `current-v1-json-29` /
  `books-29.authors-2.reading-goals-2`.

The source is not re-extracted, changed, substituted or used as product
authority, and it receives no output.

## 21. Schema/Core/UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.42.0 → 2.43.0`.
- Biblio UI: `0.20.0` unchanged.

## 22. Git

The slice is delivered as exactly one local commit with message
`feat: map current V1 assessments`. It is not pushed. The isolated trial is
rebuilt from that exact clean SHA, and both worktrees finish clean.

## Verdict

**GO / CLOSED — PRODUCTION APPLY BLOCKED.** Rating and WrittenReview mapping is
implemented within the bounded contract; all five Reflections remain
semantically preserved through privacy-safe dry-run findings only. No
apply/import or Reflection product/apply path is introduced.
