# D-MIG-READ-MAP-01 — Current V1 reading mapping contract

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-16

Task severity: **High**

Scope: product, reading and migration-mapping design only. This document adds
no production mapper, participant, schema, Core behavior, migration write,
REST or UI. V1 is source evidence, not product authority.

## 1. Decision inheritance

The authority order is:

1. accepted V2 ReadingRound and Personal Reading Truth canon;
2. the current ReadingRound and Personal Reading Truth implementation;
3. MIG-02-READ-01;
4. the closed CAT mapping and mapper contracts;
5. current Git and schema; and
6. the immutable CURRENT V1 source as reviewed mapping evidence.

The audit closes the following inheritance matrix without reopening settled
V2 semantics.

| Topic | Existing V2 decision | Current implementation | CURRENT source evidence | Mapping status | Open question? |
|---|---|---|---|---|---|
| Lifecycle | `outcome=null` is active; `completed|stopped` is ended; no `paused` | `ReadingRound`, `ReadingRoundOutcome`, ADR-007 | 48 finished-like, 2 stopped-like and 3 active-like stable rounds | Exact per §§4 and 8–10 | No |
| Completed | Ended `completed` requires a known finish date | `ReadingRoundPlan` plus `ReadingPeriod::ended()` | 48 stable rounds have explicit finish precision; 3 registrations explicitly have a partial finish | Map 51 completed rounds | No |
| Stopped | Ended `stopped` requires a known stop/finish date | `ReadingRoundOutcome::Stopped` | Two stable rounds have stop day and stop reason, with no finish | Map two stopped rounds | No |
| Active | Active requires an exact start day and one exact concrete physical source | `ReadingRoundPlan` accepts only an exact CAT Item source for active migration | Three rounds have exact start day but no Copy/Item/source reference | Preserve deferred; do not weaken V2 | No |
| Reading dates | Preserve day, month or year; never complete an unknown component | `ReadingDate` and `ReadingPeriod` | Round precision is 52 day + 1 month starts, 47 day + 1 month finishes and 2 day stops; registration partials are 3 month finishes | Exact typed dates only | No |
| Technical time | Technical timestamps are not reading dates | ADR-007; separate technical persistence fields | Raw ISO fields and audit times coexist with explicit partial-date objects | Use only explicit content date/precision fields | No |
| Rereads | Each genuine occurrence is a separate round; equality never deduplicates rounds | `ReadingRoundMigrationWriter` maps stable source identities independently | No Book has more than one round; one CAT-converged Work has a round plus an unknown-date registration | Preserve the ambiguous registration; invent no reread | No |
| Imported provenance | Controlled concrete imports use immutable `migration_imported` | Schema 1026 and `ReadingRoundMigrationWriter` | All mapped concrete occurrences are migration inputs | Inherited automatically from `ReadingRoundPlan` | No |
| Ownership | ReadingRound and Reading Truth are private and user-owned; Library is not owner | Explicit `UserId`, owner-scoped repositories and shared user×Work lock | Source contains no usable user identity | Use only the explicit migration target User | No |
| Personal Reading Truth | Closed states are `read_known_date_unknown`, `explicit_not_read`, `unknown` | `PersonalReadingTruthState` and `PersonalReadingTruthRecorder` | 392 unknown-date registrations; 330 explicit unread; 361 explicit unknown | Map after Work-level convergence rules | No |
| Effective status | Active round, completed round, read-known, explicit-not-read, unknown, default | `GetPersonalWorkReadingStatusService` and projections | Book status is a mixed summary/truth field | Do not add a parallel status model | No |
| First read/reread chronology | Only completed rounds and retained read-known evidence participate; no technical tie-break semantics | Reading sequence projection | No explicit reread example; one ambiguous alias overlap | Do not map the ambiguous marker beside the round | No |
| Work linkage | Exact committed CAT Work mapping only | `CatalogWorkMigrationParticipant` dependency | Every reading fact is structurally under one stable Book | `v1.book/<book-id>/work`; no title/ISBN lookup | No |
| Active source linkage | Exact CAT Item mapping only; availability/cardinality is not provenance | MIG-02-READ-01 | No stable round has a Copy/source field | Zero active plans | No |
| Historical registrations | Unknown-date truth is not a fictive concrete round | READ-MIG-01 | `unknown_date` and `partial_finish` are separate explicit modes | Truth for unknown date; round for known partial finish | No |
| Audit history | Audit/status trails do not override current typed structures | No V2 status-audit import target | 1,139 added events, 516 status events and one historical `paused` | Corroborating/retained evidence only | No |
| Read-history projection | Imported rounds appear normally, not as manual historical registrations | MIG-02-READ-01 normal owner history | Mapped concrete occurrences are source records, not user-entered V2 history | Normal imported history | No |

Verified baseline:

- branch `main`;
- HEAD `cb3842def69236c10dc0153596a4fcb41b65a9ce`;
- product `v2.001`;
- schema `1026`;
- Biblio Core `2.40.0`; and
- Biblio UI `0.20.0`.

## 2. CURRENT source evidence

The sole authoritative snapshot is:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

The only source data read for this decision was the existing read-only
extraction:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

| Provenance fact | Exact value |
|---|---|
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Files / bytes | 3,587 / 19,801,196 |
| Adapter | `current-v1-json-29` |
| Source family | `biblio-v1` |
| Source version | `books-29.authors-2.reading-goals-2` |

Direct terminal access to the designated ZIP remains denied by the macOS
permission boundary recorded by SOURCE-01. No re-extraction was attempted.
The extracted package manifest was recomputed read-only with the production
`FilesystemMigrationSourcePackageFactory` and matched the pinned digest, file
count and byte count exactly. The source tree was not modified and no output
was written below it.

## 3. Reading source shapes

`books.json` schema 29 contains all relevant reading evidence under a stable
Book ID. The reviewed structures are:

- Book-level `readMarker` and `readStatus`;
- zero or one current stable `readingRounds[]` record per Book;
- zero or one `readRegistration` object per Book;
- Book-level `readHistory[]` audit entries; and
- legacy top-level date projections such as `startedAt`, `finishedAt`,
  `startDates`, `finishDates`, `finishYear` and `finishMonth`.

A stable round has exactly the reviewed keys `id`, `startedAt`,
`startedAtPartial`, `finishedAt`, `finishedAtPartial`, `stoppedAt`,
`stoppedAtPartial`, `stopReason` and `pauses`. It has no Book, Copy, Item,
source or user field; its structural parent supplies only the Book reference.

A read registration has mode `unknown_date` or `partial_finish`. The latter
has one `{value, precision}` `partialDate`; all three observed precisions are
`month`. Registrations have no independent source ID, but the schema has one
named registration slot per stable Book.

The structures are not independent current occurrences. In this exact
population:

```text
448 readMarker=yes
= 53 Books with one stable round
+ 395 Books with one read registration
```

There are zero within-Book round/registration overlaps, zero positive Books
without either structure and zero `no|unknown` markers with a round or
registration. The positive Book status is therefore a current denormalized
summary over the stronger round/registration structure. `unread` and
`unknown`, for which no subordinate record exists, are the Book-level truth
slots themselves.

`readHistory` and legacy top-level date fields are corroborating audit or
projection evidence. They never create another ReadingRound or truth marker.

## 4. Stable ReadingRounds

All 53 stable round IDs are non-empty and unique. Each occurs under a distinct
Book. The exact lifecycle discriminator is the typed partial-date structure:

| Source shape | Count | V2 disposition |
|---|---:|---|
| `finishedAtPartial` present; no stop | 48 | `ReadingRoundOutcome::Completed` |
| `stoppedAtPartial` present; no finish | 2 | `ReadingRoundOutcome::Stopped` |
| no finish and no stop | 3 | Active semantics, but `preserved_deferred` because the concrete source is absent |

Every round has `startedAtPartial`; no round has both finish and stop. All 53
periods admit at least one valid chronology. The explicit partial dates agree
with their non-empty raw ISO companion values, but the raw instants are not
used as V2 reading dates or precision authority.

The matching Book statuses are exact: 48 `finished`, two `stopped` and three
`reading`. This corroborates, but does not replace, the stable round fields.

## 5. Book `readStatus`

Book-level status is a mixed structure:

| Status | Count | Exact role |
|---|---:|---|
| `finished` | 443 | Derived summary: 48 completed rounds + 392 unknown-date registrations + 3 partial-finish registrations |
| `reading` | 3 | Derived summary of the three active-like rounds |
| `stopped` | 2 | Derived summary of the two stopped rounds |
| `unread` | 330 | Independent explicit negative truth slot when no stronger reading structure exists |
| `unknown` | 361 | Independent explicit unknown truth slot when no stronger reading structure exists |

`readMarker` corroborates the same partition exactly: `yes` 448, `no` 330 and
`unknown` 361. A future snapshot with a marker/status disagreement, positive
status without a round/registration, or round/registration overlap must fail
closed; this population result is not a permissive heuristic.

The mapper does not emit a target from `finished`, `reading` or `stopped`
Book status. Their stronger subordinate source fact owns the plan. `unread`
and `unknown` enter the Work-convergence rules in §7 before a truth plan is
allowed.

## 6. Read registrations

There are 395 ID-less registrations and no Book has more than one:

| Mode | Count | Meaning and mapping |
|---|---:|---|
| `unknown_date` | 392 | Positive read knowledge with no usable reading date; candidate `PersonalReadingTruthState::ReadKnownDateUnknown` |
| `partial_finish` | 3 | One concrete completed registration with a known month finish; candidate source-free completed `ReadingRoundPlan` |

Every registration has Book `readStatus=finished` and `readMarker=yes`. Every
`partial_finish.partialDate` equals the sole month-valued finish projection on
that Book and the audit entry says finished with the same approximate month.
It therefore supplies exact completion semantics and month precision, not a
technical timestamp. Its V2 period is `ReadingPeriod::ended(null,
ReadingDate::month(year, month))`.

An `unknown_date` registration cannot become a concrete round because V2
requires a finish date for an ended round. Its source meaning is exactly “read
known, date unknown”, which is the existing Personal Reading Truth state.

## 7. Overlap and duplication

Within one Book there is no round/registration overlap. Work-level overlap
appears only after the closed CAT mapper converges the 17 two-Book canonical
ISBN groups onto 17 actual Works.

| CAT-converged pair shape | Groups | Mapping rule |
|---|---:|---|
| unknown-date registration + unknown-date registration | 12 | Emit one read-known truth plan from the deterministic CAT representative; account the alias registration as convergent duplicate evidence |
| completed stable round + unknown-date registration | 1 | Map the concrete round; preserve the registration as ambiguous duplicate-versus-earlier-read evidence; do not create a marker that would invent a reread |
| unread + unknown-date registration | 2 | Map read-known truth; preserve the conflicting unread fact; positive read evidence cannot coexist with `explicit_not_read` for one V2 Work |
| unread + unread | 2 | Emit one explicit-not-read truth plan from the deterministic CAT representative; account the alias status as convergent duplicate evidence |

This is not last-write-wins. The mapper uses the CAT representative/alias
group already established by `CurrentV1CatalogMapper`; it does not recompute
ISBN or choose by reading state. Distinct Book evidence that converges on one
Work is retained in findings even when only one product truth may be written.

There are two Work-level conflict groups and one ambiguous possible duplicate
or reread group. There are zero current within-Book status conflicts. The
contract above prevents 14 redundant truth writes, two contradictory negative
writes and one fictive reread marker.

## 8. Completed mapping

Map all 48 stable finished rounds as:

- source type `reading_round`;
- their unchanged stable source round ID;
- explicit migration target `UserId`;
- exact `catalog_work` dependency for the parent Book;
- `ReadingRoundOutcome::Completed`;
- `ReadingPeriod::ended()` using only `startedAtPartial` and
  `finishedAtPartial`; and
- no Item source, because an ended round may truthfully be source-free.

Map the three `partial_finish` registrations as additional source-free
completed plans with no start and a month-precision finish. No top-level ISO
time, audit time, import time, rating time or review time supplies a missing
component.

The result is 51 completed concrete plans. `ReadingRoundPlan` produces
immutable `migration_imported` provenance. No plan uses
`historical_manual`, `source_started` or `legacy_source_started`.

## 9. Stopped mapping

Map the two stable stopped rounds as:

- `ReadingRoundOutcome::Stopped`;
- exact day-precision start and stop dates from the partial-date objects;
- no Item source; and
- the unchanged stable round source ID.

The source explicitly distinguishes stop from finish and supplies a stop
reason in both cases. V2 has no stop-reason field in this mapping contract;
the reason remains retained source evidence and is not converted into outcome
or another enum.

No `paused`, missing-finish condition or free text is interpreted as stopped.

## 10. Active mapping

The three active-like rounds have an exact start day, no finish, no stop and
Book `readStatus=reading`. Their round objects contain no Copy, Item or other
physical-source reference. Each parent Book happens to have one Copy, but
cardinality and availability are not provenance and may not select it.

One of those parent Books also carries external-borrowed context and its Copy
is outside CAT Item activation. That context still does not prove that the
round used that Copy, and ExternalLoan migration is a separate lane.

Consequently:

- exact active mappings: 0;
- invalid/missing physical-source dependencies: 3; and
- disposition: `preserved_deferred` with a privacy-safe
  `active_round_missing_concrete_source` reason.

No Personal Reading Truth state represents active reading. The mapper must
not turn these facts into read-known, attach an arbitrary Item, create a
pseudo Item/ExternalLoan or weaken `ReadingRoundPlan`.

## 11. Paused evidence

The single `paused` occurrence is one historical `readHistory` status event.
It is not a current Book status, not a stable round lifecycle and not an entry
in any current round `pauses` array. Its Book now contains an explicit stopped
round with a stop day and stop reason.

The stopped round maps because of its current stable stop fields, not because
the historical event is reinterpreted. The `paused` audit occurrence is
`preserved_deferred` as `historical_paused_audit_evidence`. It creates no V2
status, outcome, date or audit event.

## 12. Personal Reading Truth

The Work-level truth candidates are:

- `unknown_date` registration →
  `PersonalReadingTruthState::ReadKnownDateUnknown`;
- Book `unread` + `readMarker=no` with no stronger fact →
  `PersonalReadingTruthState::ExplicitNotRead`; and
- Book `unknown` + `readMarker=unknown` with no stronger fact →
  `PersonalReadingTruthState::Unknown`.

They are resolved per actual CAT Work before planning. Section 7 selects one
truth source per converged Work and preserves conflicts/ambiguity. Section 19
quarantines candidates whose Book has no CAT Work mapping.

The final CURRENT target totals are:

| State | Product plans |
|---|---:|
| `read_known_date_unknown` | 378 |
| `explicit_not_read` | 326 |
| `unknown` | 360 |
| **Total** | **1,064** |

Reading Truth creates no round, reading date, source, Library ownership or
round provenance. A retained truth may later affect effective Work status and
sequence evidence exactly as READ-MIG-01 already defines.

## 13. Unknown-date handling

The 392 `unknown_date` registrations are accounted as:

| Disposition | Count |
|---|---:|
| Read-known truth plan | 378 |
| CAT-converged duplicate/no-op evidence | 12 |
| Preserved ambiguous beside a concrete completed round | 1 |
| Quarantined because the parent Book has no CAT Work target | 1 |
| **Total** | **392** |

No date is synthesized. In particular, source added/updated time, current
date, migration time, 1 January and the first day of a month are prohibited.

## 14. Partial dates

The V2 date mapping is exact:

- `precision=day`, `YYYY-MM-DD` → `ReadingDate::exact()`;
- `precision=month`, `YYYY-MM` → `ReadingDate::month()`; and
- `precision=year`, `YYYY` → `ReadingDate::year()`.

The current reading population contains no year-precision round/registration,
but the mapper must implement the accepted generic rule and fail closed on an
unknown precision or malformed calendar value.

Round start, finish and stop use their own explicit partial-date objects. The
three partial registrations supply only a month finish and therefore map with
`startedOn=null`. No precision is copied from a different field.

## 15. Rereads

The mapper never deduplicates stable rounds by Work or date. Every genuine
stable source round keeps its own identity and would remain a separate V2
round even when another source round later targets the same Work.

This CURRENT population has no Book with multiple rounds and no explicit
reread marker. The one CAT-converged round-plus-registration group is
ambiguous: the registration could duplicate the round or describe another
unknown-date read. Mapping it as read-known beside the round would make the
round a V2 reread without source proof, so the registration is preserved
deferred instead.

No first-read/reread flag is written. Normal V2 chronology derives from the
51 completed rounds and any safely mapped read-known truth. Technical time,
source ID and input order never break an uncertain chronology.

## 16. Work dependency

Every round or truth plan uses exactly:

```text
catalog_work : v1.book/<stable-source-book-id>/work
```

The dependency resolves through the committed CAT mapping in the same source
family and migration target. It remains correct for ordinary Books, unknown-
ISBN Books and repeated-ISBN aliases. Reading mapping does not look up Work by
title, ISBN, Author, Series, target row or fuzzy similarity and does not
re-run CAT identity logic.

The two invalid-ISBN Books have no CAT Work plan and follow §19. A dangling
reading target edge is forbidden.

## 17. Physical source dependency

Ended imported rounds may be source-free, so the 50 ended stable rounds and
three partial-finish registrations need only Work.

An active plan requires an explicit `catalog_item` source identity and the
current `ReadingRoundMigrationWriter` verifies that the Item exists in the
exact target Library and belongs to the exact mapped Work. None of the three
active source records identifies a Copy, so none can name
`v1.copy/<copy-id>/item`.

The following are prohibited substitutes:

- the only Copy under a Book;
- the first/available/owned Copy;
- an Item found by Edition, ISBN or Work;
- a CAT-quarantined or Item-ineligible Copy;
- an external-borrowed Copy coerced into an Item; and
- a fabricated ExternalLoan.

## 18. User ownership

All plans use the explicit migration target User. The plan User, planning
target User and run target User must be equal, and the platform User must be
active. No source field, current actor, administrator, first user, display
name or target Library supplies ownership.

ReadingRound and Personal Reading Truth remain user-owned. The explicit target
Library is only relevant when validating a concrete Item dependency; it never
becomes owner of reading data.

## 19. Source identities

Logical source identity remains stable and source-scoped:

| Meaning | Source type | Source ID |
|---|---|---|
| Stable V1 round | `reading_round` | unchanged stable `readingRounds[].id` |
| ID-less read-registration slot mapped as round | `reading_round` | `v1.book/<book-id>/read-registration` |
| ID-less read-registration slot mapped as truth | `reading_truth` | `v1.book/<book-id>/read-registration` |
| Book-level unread/unknown truth slot | `reading_truth` | `v1.book/<book-id>/read-status` |

The source type separates the target contract; the source ID identifies the
actual named source slot and does not pretend to be a person, target ID or
random round. Mode/state/date remain in the canonical payload hash, not the
identity. A later snapshot that changes one registration slot between target
classes must fail closed against prior trace rather than silently leave both
targets.

Work+date, Work+status, title, ISBN, migration time and generated UUID are not
source identities.

## 20. Typed mapping contract

### Concrete round output

The existing production output is `ReadingRoundPlan` inside a typed
`MigrationSourceRecord` for
`ReadingRoundMigrationParticipant::SOURCE_TYPE`. It contains:

- the explicit target `UserId`;
- exact CAT Work source ID;
- nullable `ReadingRoundOutcome`;
- exact `ReadingPeriod`; and
- an Item source ID only when the source explicitly proves one.

No change to `ReadingRoundMigrationParticipant` or
`ReadingRoundMigrationWriter` is required or allowed by this design.

### Personal Reading Truth output

The existing domain write is
`PersonalReadingTruthRecorder::recordForOwner(UserId, WorkId,
PersonalReadingTruthState)`. MIG-FND integration already proves that it can be
composed inside `CommitMigrationRecordService`, mapping target type
`personal_reading_truth` to the exact Work ID.

Current production has no registered `reading_truth` migration participant or
typed source plan. The implementation slice must therefore add the smallest
source-neutral typed bridge with exactly:

- explicit target `UserId`;
- exact CAT Work source ID;
- one `PersonalReadingTruthState`; and
- canonical target kind `personal_reading_truth`.

Its writer resolves one committed Work mapping, calls the existing recorder
inside the caller-owned MIG-FND transaction, verifies exact replay and emits
one `personal_reading_truth` target mapping. It must add the corresponding
reconciliation contract and target inspection. It must not parse CURRENT
fields, own a transaction, create a Work, weaken contradiction handling or
reuse a target through display metadata.

`CurrentV1ReadingMapper` should be a bounded collaborator of the existing
adapter-owned `CurrentV1CatalogMapper`, like the current Author,
classification and Item-local collaborators. It needs the already-computed
Book→CAT representative relation to apply §7 and must not register a second
mapper for the same adapter.

## 21. Preservation and quarantine

Use privacy-safe mapping findings/outcomes only:

| Condition | Disposition | Safe reason |
|---|---|---|
| Active round without explicit concrete source | `preserved_deferred` | `active_round_missing_concrete_source` |
| Historical paused audit occurrence | `preserved_deferred` | `historical_paused_audit_evidence` |
| Unknown-date registration beside a concrete round after CAT convergence | `preserved_deferred` | `ambiguous_duplicate_or_reread` |
| Unread conflicting with positive evidence after CAT convergence | `preserved_deferred` | `conflicting_book_read_status` |
| Duplicate evidence converging on one truth | derived/no-op mapping finding | `duplicate_reading_truth_evidence` |
| Parent Book has no CAT Work mapping | `quarantined` | `QuarantineReason::UnresolvedWorkIdentity` |
| Future structural/status/date contradiction | `quarantined` | applicable existing structural or reading-truth reason |

Preservation evidence may contain only stable source references and bounded
reason/status/date-shape metadata. It must not serialize titles, Notes,
reviews, free-text stop reasons, private timestamps or source payloads in
ordinary artifacts or errors.

The two CAT-quarantined Books contain two reading target candidates: one
unknown-date registration and one Book-level unknown truth. Both are
quarantined; neither creates a target edge. Neither Book has a stable round.

## 22. CURRENT mapping counts

The exact manifest produces:

| Mapping/accounting class | Count |
|---|---:|
| Stable rounds total | 53 |
| Stable completed mapped | 48 |
| Stable stopped mapped | 2 |
| Stable active mapped | 0 |
| Stable active preserved deferred | 3 |
| Stable rounds quarantined | 0 |
| Partial-finish registrations mapped to completed rounds | 3 |
| **Concrete ReadingRound plans** | **53** |
| Unknown-date registrations mapped to Reading Truth | 378 |
| Unknown-date registrations duplicate/no-op | 12 |
| Unknown-date registrations preserved ambiguous | 1 |
| Unknown-date registrations quarantined | 1 |
| Book statuses mapped to Reading Truth | 686 |
| Book statuses derived/duplicate no-op | 450 |
| Book statuses preserved as Work-level conflict | 2 |
| Book statuses quarantined | 1 |
| **Personal Reading Truth plans** | **1,064** |
| Work-level conflict groups | 2 |
| Ambiguous duplicate-versus-reread groups | 1 |
| Historical paused audit evidence preserved | 1 |
| Other `readHistory` events treated as audit/derived no-op | 1,654 |
| CAT-quarantined Books with reading candidates | 2 |
| Active rounds with valid/invalid physical dependency | 0 / 3 |

The Book-status accounting totals 1,139. Registration accounting totals 395.
Stable-round accounting totals 53. No count is to be hardcoded in mapper
behavior. The 1,655 audit events are one preserved `paused` occurrence plus
1,654 corroborating/derived no-ops. A changed population must be recomputed
and validated.

## 23. Downstream effects

- Reading History gains 51 completed and two stopped imported entries; no
  duplicate registration entries are created.
- The three unresolved active rounds do not attach to a wrong copy and remain
  visible to migration reconciliation as deferred source truth.
- Imported rounds render as ordinary history with
  `historical_registration=false`, count in normal completed/stopped totals
  and never gain manual-history deletion rights.
- Reading Truth supplies Work-level status without dates or round counts.
- The ambiguous alias registration is not mapped, so the concrete completed
  round is not falsely classified as a reread.
- Stable source round mappings remain available for later Note/Assessment
  plans that carry an exact round reference. No last/active/only-round fallback
  is introduced.
- Ratings, reviews, Notes, circulation and ExternalLoans are not migrated or
  changed by this slice.

## 24. Product questions

None. The existing V2 canon plus exact CURRENT evidence closes the mapping:

- Book `unread` is explicit negative evidence only when no stronger fact for
  the actual mapped Work contradicts it;
- the historical `paused` event is auxiliary evidence, not lifecycle;
- active rounds without an explicit physical source are preserved rather than
  fabricated; and
- CAT alias ambiguity is preserved whenever mapping would invent a reread.

This is **DESIGN GO** for the bounded mapper implementation. It is not a
cutover GO and does not promote the three deferred active rounds.

## 25. Required implementation slice

Recommended follow-up:

```text
MIG-02-READ-MAP-01 — Current V1 reading mapper
```

It must:

1. add `CurrentV1ReadingMapper` as a collaborator of the existing CURRENT
   batch mapper;
2. translate reviewed stable rounds and partial-finish registrations into
   `ReadingRoundPlan` without changing the existing participant;
3. add the narrow source-neutral `reading_truth` plan/participant/writer bridge
   to `PersonalReadingTruthRecorder` and MIG-FND;
4. use exact CAT Work mappings and the CAT representative relation;
5. emit the preservation, quarantine and duplicate findings in §§7 and 21;
6. extend reconciliation and target inspection for
   `personal_reading_truth`;
7. keep dry-run deterministic, privacy-safe and zero-write; and
8. run no apply/import or active-source repair.

The implementation may require a Core version bump but no schema or UI
change is indicated by this design.

## 26. Acceptance criteria

The implementation is acceptable only when automated and CURRENT evidence
proves all of the following:

- 48 stable completed and two stable stopped plans preserve exact periods;
- three partial-finish registrations become source-free month-precision
  completed plans;
- unknown-date registrations never become rounds or acquire dates;
- the three active rounds produce no plan and three preservation findings;
- the historical paused event produces no status/outcome/date mutation;
- Book positive summaries do not create duplicate targets;
- all four CAT-alias pair shapes in §7 follow their exact rule;
- one actual Work receives at most one Reading Truth write;
- the ambiguous round+registration pair does not gain a read-known marker;
- the two CAT-quarantined Books create no reading target edge;
- stable round IDs and deterministic slot identities replay exactly and
  divergent payloads fail closed;
- explicit target User is enforced and Library never becomes owner;
- every reading fact is mapped, derived/duplicate, preserved or quarantined;
- dry-run reports the recomputed counts without raw payload, private content or
  reading timestamps and writes zero product/MIG-FND rows;
- reconciliation validates ReadingRound and Personal Reading Truth targets and
  reports no unexplained source record or mapping anomaly; and
- focused tests, the relevant Core gate, privacy scan, reference validation,
  `git diff --check` and an independent review pass are green.

## 27. Current V1 data rule

Only the designated CURRENT extraction and its pinned manifest may be used.
The mapper must reject an unsupported adapter/version/shape or byte change.
It must not read another `/data/`, historical DATA-01/MIG-01 source, provider
or fixture as current truth and must never write into the source root.

## 28. Schema, Core and UI impact

This design changes documentation only:

- schema: remains `1026`;
- Biblio Core: remains `2.40.0`;
- Biblio UI: remains `0.20.0`;
- REST/UI behavior: unchanged; and
- migration/product writes: none.
