# MIG-02-READ-MAP-01 — Current V1 reading mapper

Status: **GO / CLOSED**

Date: 2026-09-17

Task severity: **High**

Scope: the exact CURRENT reading structures are translated to the existing
ReadingRound contract and the existing Personal Reading Truth domain model.
This slice adds no schema, reading state, active-source inference, REST, UI or
apply/import path.

## 1. Pre-coding audit

The required read-only audit was completed before production changes against
docs 00–03, 06, 63, 86, 105–107, 112, 117, 120, 124, ADR-007, current code and
the immutable extraction. It established the exact adapter shapes, the
existing `ReadingRoundPlan` and migration writer behavior, Personal Reading
Truth ownership and recorder API, CAT source identities, mapper/participant
composition, replay and reconciliation rules, alias convergence and schema
sufficiency. No missing product decision remained.

## 2. Decision inheritance

Docs/124 is implemented without reinterpretation. V2 remains authoritative:
there is no `paused` lifecycle, Personal Reading Truth is not a fictive round,
ended imported rounds may be source-free, and active imported rounds require
one explicit concrete Item source. The explicit migration target User remains
the sole owner; Library ownership, current actor, administrator and source
content never supply identity.

## 3. Mapper architecture

`CurrentV1ReadingMapper` is a manifest-bound collaborator of
`CurrentV1CatalogMapper`:

```text
CurrentV1SourceAdapter
→ CurrentV1CatalogMapper representative/alias relation
→ CurrentV1ReadingMapper
→ typed ReadingRoundPlan / ReadingTruthPlan records
→ source-neutral participants and reconciliation
```

The mapping contract is
`d-mig-read-map-01.2026-09-16:35a18156490f103d4b6b610f`. Raw CURRENT enums
never enter a source-neutral participant. Mapping findings contain only stable
source identities, counts, dispositions, reason codes and planned identities.

## 4. Reading Truth participant

The new bounded `reading_truth` participant contains only the explicit target
User, exact CAT Work source dependency and one existing
`PersonalReadingTruthState`. Its writer joins the caller-owned MIG-FND
transaction, resolves one committed Work mapping, calls
`PersonalReadingTruthRecorder::recordForOwner`, and maps target type
`personal_reading_truth` to that exact Work ID.

Exact replay verifies source payload, owner, Work, target type, current stored
state and reverse source mapping. Changed payload, wrong User, missing/broken
Work, unexpected target type, another source mapping or changed target state
fails closed. The participant creates no new reading state and never writes a
ReadingRound.

## 5. Completed rounds

Forty-eight stable rounds use their exact stable IDs and typed start/finish
partials. Three ID-less `partial_finish` slots use deterministic
`v1.book/<book-id>/read-registration` identities and a month-precision finish
with no invented start. All 51 use outcome `completed` and immutable
`migration_imported` provenance through the unchanged existing participant.

## 6. Stopped rounds

Two stable rounds use outcome `stopped`, their explicit day-precision start
and stop partials, and no Item. Free-text stop reason remains retained source
evidence and is not converted into a new enum or artifact field.

## 7. Active source rounds

The three active-like stable rounds create no plan. Each becomes
`preserved_deferred` with `active_round_missing_concrete_source`. The mapper
does not inspect Copy cardinality, availability, classification, acquisition,
external-borrowed context or target Items to fabricate provenance.

## 8. Paused evidence

The single historical `paused` audit event becomes one
`historical_paused_audit_evidence` preservation finding. It creates no V2
status, outcome or date. The other 1,654 read-history events are explicit
`derived_read_history_evidence` no-op findings.

## 9. Book status and registration overlap

Positive Book status is derived whenever a stable round or registration owns
the stronger fact. It never creates a second round or truth plan. The 17 CAT
alias groups follow docs/124 exactly: 12 duplicate read-known pairs produce
one truth plus duplicate evidence; one round-plus-registration pair preserves
the registration as ambiguous; two unread-plus-read-known pairs keep the
positive truth and preserve the negative conflict; and two unread pairs
produce one explicit-not-read truth each.

There is no last-write-wins rule, enum strength ordering or reread inference.
Stable source occurrences remain distinct and are never deduplicated by Work
or date.

## 10. Personal Reading Truth

The exact CURRENT plan population is:

| Existing state | Plans |
|---|---:|
| `read_known_date_unknown` | 378 |
| `explicit_not_read` | 326 |
| `unknown` | 360 |
| **Total** | **1,064** |

Unknown-date registrations never acquire a date or round. Absence of a round
never becomes explicit-not-read. `unknown` remains an active explicit truth
state rather than being collapsed into absence.

## 11. Date precision

The mapper accepts only the reviewed `{value, precision}` forms and constructs
`ReadingDate::year`, `ReadingDate::month` or `ReadingDate::exact`. It never
copies precision between fields and never substitutes technical, import,
current or migration time. Invalid calendar values or unknown precision fail
closed.

## 12. Work and User dependencies

Every active output depends only on
`catalog_work:v1.book/<stable-book-id>/work`. CAT aliases retain their own
source dependency and resolve through the existing committed alias mapping to
the canonical Work. No title, ISBN, Edition, Item, Author or fuzzy lookup is
performed. Plan User, planning User and migration-run User must agree exactly
and the platform User must be active.

## 13. Preservation and conflicts

Valid unrepresentable evidence is preserved; structurally blocked evidence is
quarantined. The final reading-specific findings are three missing active
sources, one historical paused event, one ambiguous duplicate-versus-reread,
two conflicting unread facts, 14 duplicate truth facts and two unresolved CAT
Work candidates. No raw title, Note, review, stop reason or technical timestamp
enters ordinary output.

## 14. CURRENT mapping totals

| Accounting class | Count |
|---|---:|
| Stable source rounds | 53 |
| Stable completed / stopped plans | 48 / 2 |
| Stable active plans / preserved | 0 / 3 |
| Registration-derived completed plans | 3 |
| **Concrete ReadingRound plans** | **53** |
| Book-status-derived truth plans | 686 |
| Registration-derived truth plans | 378 |
| **Personal Reading Truth plans** | **1,064** |
| Registration duplicate / ambiguous / CAT-blocked | 12 / 1 / 1 |
| Book status derived-or-duplicate / conflict / CAT-blocked | 450 / 2 / 1 |
| CAT alias groups / exact duplicate truth evidence | 17 / 14 |
| Paused preserved / other audit-derived | 1 / 1,654 |
| Reading-specific planning errors / unmatched references | 0 / 0 |

No count is a runtime constant. All totals are recomputed from adapter records
and the reviewed CAT representative relation.

## 15. Reconciliation

`CurrentMigrationMappingContracts` now requires one
`personal_reading_truth` entity mapping for every committed `reading_truth`
observation. `CoreMigrationTargetInspector` verifies exact run User, Work
dependency, target Work ID and stored truth state. The full restart test covers
first application, interrupted resume, exact later-run reuse, changed source,
target inspection and deterministic reconciliation together with ReadingRound.

Dry-run mapping findings account stable rounds, registrations, Book statuses,
audit events, duplicates, preservation, conflicts and CAT blocks without
creating MIG-FND observations.

## 16. Zero-write proof

The final CURRENT dry-run is executed only from the clean detached trial
worktree after the implementation commit. Before/after fingerprints cover all
57 Biblio tables and must be byte-for-byte equal. The ignored artifact and its
SHA-256 receipt contain `zero_write_confirmed=true`; neither product nor
MIG-FND tables are written and no apply/import command is invoked.

## 17. Trial build provenance

The guarded trial is `biblio-v2-migration-trial` on database
`biblio_migration_trial`, with explicit subscriber/non-super-admin target
identity, schema 1026, Core 2.41.0, UI 0.20.0, adapter
`current-v1-json-29`, manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`
and the mapping contract above. The post-commit ignored artifact/evidence and
the completion report record the exact final Git SHA; it cannot be embedded in
the commit whose identity it proves.

## 18. Explicitly deferred

Notes, Assessments, Series, Wishlist, Archive, contained works, active-source
reconciliation, lending backfill, reading UI, production apply/import and
final cutover remain separately authorized and are not started.

## 19. Tests and quality gates

Focused mapper tests cover completed, stopped and active shapes, year/month/day
precision, all three truth states, paused evidence, all four alias patterns,
CAT quarantine and deterministic output. Participant integration covers exact
owner, all states, Work dependencies, exact replay and divergent target state.
The existing ReadingRound participant and full reconciliation/restart tests
remain green. Final acceptance additionally runs the full Core gate, PHP
syntax, PHPStan, Composer/platform, WordPress smoke, manifest, whitespace and
privacy checks plus an independent second review. Browser/E2E is excluded
because REST and UI are unchanged.

## 20. Current V1 data rule

Only the designated archive and validated read-only extraction are CURRENT:

- ZIP SHA-256: `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
- extracted manifest: `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`;
- adapter/source version: `current-v1-json-29` /
  `books-29.authors-2.reading-goals-2`.

The source is not re-extracted, changed or substituted and receives no output.

## 21. Schema, Core and UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.40.0 → 2.41.0`.
- Biblio UI: `0.20.0` unchanged.

## 22. Git

The slice is delivered as exactly one local commit with message
`feat: map current V1 reading data`. It is not pushed. After commit, the
isolated trial worktree is repinned to that exact SHA and remains clean. The
main worktree also finishes clean.

## Verdict

**GO / CLOSED.** CURRENT reading evidence is mapped without fabricated dates,
Items, rereads, ownership or duplicate truth, and without running apply/import.
