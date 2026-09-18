# MIG-02-COPY-EXCL-01 — CURRENT V1 erroneous archived Copy exclusion

Status: **IMPLEMENTED — FINAL ACCEPTANCE REQUIRES THE CLEAN EXACT-SHA
ZERO-WRITE TRIAL; PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-18

Task severity: **High**

## 1. Decision inheritance

This slice implements, without reinterpretation, the manifest-bound product
decision in `docs/137-d-mig-copy-excl-01-current-v1-erroneous-archived-copy-exclusion.md`.
The exact 23 reviewed erroneous CURRENT Copy registrations are terminal
non-Items. Their only migration result is durable restricted no-target evidence.
This is not an Archive migration and does not authorize apply/import.

## 2. Pre-coding audit

The audit confirmed that the raw adapter emits stable `v1.copy` records, while
`CurrentV1CatalogMapper` composes CAT, classification and Item-local results
before creating `CatalogItemPlan`. `CurrentV1ItemEligibility` already held the
separate external-borrowed terminal outcome. MIG-FND already supplied typed
preservation, shared preparation, replay and reconciliation, but lacked both a
generic prepared-stream exclusion invariant and a scope-compatible cross-run
mapping lookup without payload/run-status filtering. Schema 1026 is sufficient.

## 3. Terminal Item eligibility architecture

`CurrentV1ItemLocalMapper` owns the CURRENT-specific reviewed decision and emits
`NOT_LIBRARY_ITEM_ERRONEOUS_LEGACY_COPY` before acquisition/details mapping.
`CurrentV1CatalogMapper` treats it as terminal before Item composition. It is
independent from `NOT_LIBRARY_ITEM_EXTERNAL_BORROWED`; no classification,
Item-local or CAT readiness can reactivate an excluded Copy.

## 4. Exact exclusion-set binding

`CurrentV1ReviewedCopyExclusionContract` is bound to manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`,
23 exact stable Copy IDs and full-row hashes, and set digest
`c1d54e96fd3330fa329a397fcc9f69c6556e6d4cd299c153be9c059145c9353e`.
Its identity is
`d-mig-copy-excl-01.2026-09-18:set:c1d54e96fd3330fa329a397f`.
A changed manifest, reviewed row, missing row or additional matching correction
shape fails closed. This is not a generic archived-row heuristic.

## 5. CAT integration

All 1,137 Work and 1,137 Edition plans remain in their accepted CAT stream.
Exclusion is keyed by exact Copy identity, not Book, ISBN, Work, Edition or
representative mapping. A valid sibling or converged bibliographic Copy remains
independently eligible.

## 6. Classification interaction

The ten classification-ready and 13 classification-blocked reviewed Copies all
receive the same terminal non-Item outcome. Work-level classification evidence
is unchanged; no Item classification-context operation is emitted for an
excluded Copy.

## 7. Item-local interaction

The reviewed population contains one non-empty Item-local state and 22 reviewed
absences. Exclusion is evaluated first, so none can create an Item-local target
or operation. The existing five external-borrowed outcomes remain separate.
Normal non-excluded Item-local totals remain 71 non-empty states and 1,007
reviewed absences; complete plans contain 26 details operations.

## 8. Durable preservation

Every excluded Copy emits exactly one `PreservedSourceEvidencePlan`:

```text
identity:      v1.copy/<stable-id>/erroneous-legacy-copy
evidence type: current_v1_erroneous_legacy_copy
reason:        erroneous_legacy_copy_not_carried_forward_v2
privacy:       restricted_source
locator:       data/books.json#copies/<encoded-id>/record
hash:          deterministic full Copy-row hash
dependencies:  none
targets:       none
```

The existing Copy auxiliary observation is not also emitted for those rows.
Restricted source values stay in the immutable package; durable state and
ordinary evidence contain only typed provenance, locator, reason and hashes.

## 9. Archive and delete absence

The implementation contains no Archive plan, participant, reason, time, actor,
restore event, product deletion or create-then-delete flow. A reviewed Copy
never becomes a V2 Item.

## 10. CAT alias safety

Fifteen excluded parent Books are CAT aliases and eight are unique. Tests prove
the exclusion remains source-Copy-specific and does not spread to a legitimate
sibling or a Copy converging on the same bibliographic identity.

## 11. Wishlist invariance

The 41 active Work-only Wishlist plans and 41 restricted auxiliary preservation
plans remain unchanged. The two overlapping Work-only plans are neither
fulfilled nor otherwise mutated, including the row whose raw legacy reason was
`wishlist_correction`.

## 12. Reading invariance

The one overlapping ReadingRound and nine overlapping Personal Reading Truth
plans remain Work-level truth. No substitute Item or Copy dependency is added.
The accepted 53 ReadingRound and 1,064 Reading Truth plan totals remain stable.

## 13. Circulation invariance

The exclusion population has zero circulation overlap. The global circulation
result remains eight preserved observations plus the one existing quarantine;
no circulation mapping, mutation or duplicate preservation is introduced.

## 14. Stale prior mapping preflight guard

Each exclusion preservation declares its forbidden legacy source identity
`catalog_item:v1.copy/<stable-id>/item`. `MigrationPlanPreparer` rejects a
prepared stream containing both identities. Before a new run is created, the
preservation participant searches committed mappings for the exact target User,
target Library, source family, source type and source ID across all compatible
runs, without payload or run-status prefilter. Any prior target mapping fails
closed. Reconciliation runs the same preflight. No historical row or target is
deleted, archived or repaired.

## 15. Prepared-stream effect

The exact CURRENT recomputation produces:

| Measure | Result |
|---|---:|
| terminal Copy exclusions | 23 |
| `catalog_item` plans | 734 |
| Item creation/classification operations | 734 / 734 |
| Item-local details operations | 26 |
| `preserved_source_evidence` plans | 98 |
| total executable records | 6,592 |
| exclusion quarantine / unmatched / planning errors | 0 / 0 / 0 |

Ten old complete Item plans disappear and 23 durable preservation plans enter,
for a net increase of 13 executable records from 6,579 to 6,592.

## 16. Previous versus new Item totals

The result is produced from the real CAT, classification, Item-local and
terminal-eligibility intersection: 744 previous complete plans minus ten
otherwise-complete excluded Copies equals 734. It is not derived by subtracting
23 from a source total.

## 17. Reconciliation and replay

Dry-run, apply preflight and reconciliation consume the same prepared stream.
Equivalent preservation replays idempotently; changed evidence, provenance,
contract or forbidden target history fails closed. Synthetic reconciliation
proves the preservation state and zero product mappings.

## 18. CURRENT global dry-run status

The reviewed CURRENT plan contains 6,592 executable records, including 734
Items, 98 preservation records, 53 ReadingRounds, 1,064 Reading Truth plans and
41 active Wishlist plans. It has zero planning error, zero unmatched reference
and no unsupported source type. The one global quarantine remains the known
circulation observation; it is not part of this exclusion lane. This does not
constitute global cutover acceptance.

## 19. Zero-write and exact-SHA evidence

Final acceptance is the guarded dry-run of the clean detached implementation
commit in the isolated trial database. The artifact must self-identify that
commit, `working_tree_dirty=false`, exact source manifest, target User/Library,
plan digest and `zero_write=true`. All 57 Biblio table fingerprints, MIG-FND
counts and normal-database guards must be byte-identical before/after. The
post-commit completion report records the authoritative artifact checksum,
fingerprints and exact Git SHA; no self-referential commit SHA is embedded in
this same implementation commit.

## 20. Quality gates

Acceptance requires focused exclusion/CAT/Item-local/preservation/preflight/
reconciliation regressions, full Core unit and integration suites, PHP syntax,
PHPStan, Composer metadata/platform, WordPress smoke, manifest JSON,
`git diff --check`, privacy scan and an independent second review. The exact
results are recorded with the final post-commit trial report.

## 21. Versions and Git boundary

Product remains `v2.001`, schema remains `1026`, Biblio Core becomes `2.48.0`
and Biblio UI remains `0.20.0`. `scripts/migration-trial.sh` receives only the
mechanical Core expectation update. This slice is one local implementation
commit; it is not pushed.

## 22. Verdict boundary

Technical GO requires the clean exact-SHA trial and every gate above to pass.
Even then: **PRODUCTION APPLY NOT AUTHORIZED**. The known circulation quarantine,
fresh-export/cutover rehearsal and explicit Renée authorization remain separate.
