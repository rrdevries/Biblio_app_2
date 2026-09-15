# MIG-02-CIRC-01 — Deferred circulation preservation participant

Status: **GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

Scope: CURRENT-source mapping and MIG-FND preservation/quarantine only. No V2
loan product model, product write, schema, REST, UI, identity matching, source
mutation, production apply CLI or final-cutover policy is included.

## 1. Pre-coding audit

`CurrentV1SourceAdapter` already grouped one stable circulation ID into one
`v1.circulation_round` observation. Its canonical payload has separate
`book_occurrences` and `copy_occurrences`; nested source records retain `id`,
raw `borrowed|lent_out` type, `startDate`, `endDate`, free-text `counterparty`
and `notes`. Dates retain the source `value` and `precision`. In the accepted
source contract, a null end date is open and a concrete partial date is
closed.

MIG-FND already had every required capability. A source observation stores the
canonical payload plus source family/type/ID, snapshot and payload hash.
`MigrationRecordOutcome::preserved()` and `::quarantined()` write bounded
restricted evidence through the existing atomic commit boundary. Their rows
are unique per observation and foreign-key back to the original observation.
Disposition-only plans and reconciliation contracts require no target mapping.
Schema 1026 is therefore sufficient; no evidence store or schema change was
needed.

## 2. Decision inheritance

The implementation inherits D-MIG-LOAN-01 Option D without reinterpretation.
Coherent CURRENT circulation is preserved for later promotion. A material
Book/Copy lifecycle contradiction is quarantined. Neither outcome is a V2
loan, return, settlement, ownership transfer, Item-availability change or
user-visible history record.

## 3. Source circulation contract

The CURRENT adapter now attaches a `CirculationPlan` to the existing merged
observation without changing its canonical payload or payload hash. The plan
requires at least one real occurrence, matching stable IDs, reviewed Book/Copy
wrapper shapes, raw `borrowed|lent_out`, a source start date and a null or
typed source end date. Missing optional counterparty text remains missing; it
is never invented.

Book and Copy forms remain evidence about the same source identity. The plan
does not split them, choose precedence or infer identity from their free text.

## 4. Preservation rule

When every occurrence agrees on raw type and open/closed lifecycle state, the
participant plans and applies:

```text
disposition = preserved_deferred
reason_code = circulation_product_target_deferred
operations = []
mappings = []
```

The restricted evidence explicitly records `source_state=open|closed`, all
observed states/types, the exact merged payload, observation/run identity,
source family/type/ID, source snapshot and payload hash. Open records therefore
remain explicitly open; preservation never means returned, completed or
historically closed.

## 5. Conflict quarantine

When the same source identity has contradictory lifecycle states, or its
representations disagree on the raw circulation type, the participant plans
and applies `quarantined` with the existing schema-supported fixed reason
`ambiguous_circulation_semantics`. The safe explanation states only that
source representations conflict and no precedence was applied.

Both Book and Copy occurrences remain in restricted evidence. Neither wins,
and there is no product write.

## 6. Future-promotion evidence

The evidence path retains, where present:

- stable circulation ID and raw `borrowed|lent_out` type;
- explicit source open/closed state;
- start/end values and source precision;
- Book and Copy source references and both occurrence payloads;
- private counterparty and circulation notes;
- observation ID, run ID, source family/type/snapshot and payload hash; and
- disposition reason, conflict marker and MIG-FND future-processing state.

A future separately authorized `MIG-LOAN-BACKFILL-01` can follow the
preservation/quarantine `observation_id` foreign key to the immutable source
observation. It must use the then-approved loan model and retain this original
evidence.

## 7. Counterparty privacy and identity

Counterparty and notes remain restricted evidence only. They never become a
WordPress User, Biblio member, borrower, Author or other Person identity. No
name lookup, comparison, merge or account creation exists.

Dry-run and reconciliation expose only safe source identity/hash,
disposition/reason and counts. Synthetic privacy tests prove sentinel values
and raw payload keys are absent from artifacts and safe explanations while
remaining recoverable in MIG-FND evidence.

## 8. Planning and dry-run

Production composition registers the participant for exactly
`v1.circulation_round`. The public surface remains only profile and dry-run;
no apply command was added. `PlannedMigrationRecord` carries no fake product
operation or dependency.

The CURRENT read-only dry-run used explicit subscriber target user `227` and
its designated private Library. Its artifact is ignored/local and has SHA-256
`5e83ae725a1859cd949b1c96df1818df56d5ce663c459847dad101a043e4b418`.
The artifact confirms `zero_write=true`, and its checksum sidecar matches.

## 9. Reconciliation

`CurrentMigrationMappingContracts` registers circulation with zero target
rules. Preservation and quarantine are therefore complete explicit outcomes,
not unsupported source types and not broken/missing target mappings.

Synthetic apply reconciliation accepts two preserved observations plus one
quarantined observation, zero mappings, zero failed/uncommitted/unexplained
records and zero ExternalLoan writes. The CURRENT dry-run planning totals
account for all nine circulation observations with zero errors and unmatched
references.

## 10. Replay

The participant uses the unchanged MIG-FND observation and commit boundary.
An exact completed replay skips the two committed preservations and one
terminal quarantine without new evidence or product writes. A changed payload
for the same source identity in the same run reaches the existing divergent
observation conflict; it is never silently accepted as the old observation.

## 11. CURRENT snapshot verification

Only the SOURCE-01 read-only extraction of the explicitly designated CURRENT
ZIP was used. The dry-run recomputed the unchanged extracted manifest:
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.

Exact circulation planning result:

| Measure | Result |
|---|---:|
| `v1.circulation_round` observations | 9 |
| `preserved_deferred` | 8 |
| `quarantined` | 1 |
| operations/product loan writes | 0 |
| planning errors | 0 |
| unmatched references | 0 |
| unexplained circulation observations | 0 |

The one quarantined ID is the accepted Book-open/Copy-closed conflict. The
eight other IDs remain open in preserved meaning. Other unsupported CURRENT
source types remain outside this slice and were not turned into follow-on
work.

## 12. Explicitly deferred

ExternalLoan changes, InternalLoan, Item loan/availability state, borrower or
membership identity, loan REST/UI/history/return lifecycle, final-cutover
circulation policy, production apply CLI and `MIG-LOAN-BACKFILL-01` remain
explicitly outside this slice.

## 13. Tests and quality gates

Final validation evidence:

- circulation/adapter unit tests: 8 tests / 62 assertions;
- circulation plus RUN-01 integration: 9 tests / 96 assertions;
- complete unit suite: 713 tests / 2,845 assertions;
- complete integration suite: 562 tests / 6,429 assertions;
- complete PHP syntax and Composer/platform requirements: green;
- PHPStan: green;
- WordPress smoke, manifest JSON and whitespace: green;
- full Biblio Core quality gate: green in 1,310 seconds;
- CURRENT dry-run and artifact/checksum/privacy inspection: green.

An explicit independent second review of the final diff against the decision,
architecture, privacy boundary and regression risks is recorded in the
completion report.

No browser/E2E test is applicable because REST and UI are unchanged.

## 14. Current V1 data rule

No real CURRENT record or private value is committed. The ZIP and read-only
extraction were not modified. Tests contain synthetic data only. Historical
fixtures and counts were not used as current truth.

## 15. Versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.34.0 -> 2.35.0`.
- Biblio UI: `0.20.0` unchanged.

## 16. Git

Exactly one local implementation commit is created after all gates and the
independent second review. Nothing is pushed to `origin/main`.
