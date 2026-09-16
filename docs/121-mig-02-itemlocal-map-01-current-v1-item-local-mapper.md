# MIG-02-ITEMLOCAL-MAP-01 — Current V1 Item-local mapper

Status: **GO / CLOSED**

Date: 2026-09-16

Task severity: **High**

Scope: the exact CURRENT SOURCE-01 Copy acquisition shape is mapped to the
existing typed Item-local target and combined with the closed CAT/CLASS
planning boundary. This slice adds no schema, REST, UI, archive/disposal,
asset, inventory-number, circulation promotion or apply/import command.

## 1. Pre-coding audit

The audit was completed before implementation against docs 103, 105–120,
current code and the immutable CURRENT extraction. It established that:

- `CurrentV1SourceAdapter` already validates and retains the exact Copy and
  Book fields required by D-MIG-ITEMLOCAL-MAP-01;
- `ItemLocalDetailsState` and `ItemLocalDetailsRecorder` already provide the
  complete nullable typed target and atomic Item-bound write boundary;
- CAT already separates classification from Item-local readiness but required
  one typed terminal non-Item outcome;
- MIG-FND already permits `preserved_deferred` outcomes with target mappings;
- the Item writer and reconciliation needed to make that coexistence explicit;
- schema 1026 represents every accepted target; and
- no product question or schema change remained.

The implementation verdict was therefore **GO**.

## 2. Immutable mapping contract

The mapper is bound to:

| Provenance fact | Exact value |
|---|---|
| Source family | `biblio-v1` |
| Adapter | `current-v1-json-29` |
| Source version | `books-29.authors-2.reading-goals-2` |
| Manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Mapping contract | `d-mig-itemlocal-map-01.2026-09-16` |

Changed manifest bytes fail as `source_changed`. Unknown acquisition shapes or
types fail as unsupported structure. No historical export, fixture, DATA-01,
provider or network source participates.

## 3. Typed outcomes

`CurrentV1ItemLocalMapper` returns one typed result per Copy:

1. `ITEM_ELIGIBLE` with either a non-empty `ItemLocalDetailsState` or reviewed
   absence represented by null;
2. `NOT_LIBRARY_ITEM_EXTERNAL_BORROWED`, which is terminal and suppresses the
   `CatalogItemPlan`; or
3. `INVALID_ITEM_LOCAL_ACQUISITION_DATE`, which fails closed and suppresses the
   Item plan.

The result also exposes whether preservation is required and its exact reason.
Reviewed null is therefore never confused with terminal non-Item state.

The exact active mapping is:

| CURRENT Copy value | V2 result |
|---|---|
| `acquisition.type=bought` | `acquisition_method=zelf_aangeschaft` |
| `acquisition.type=received` | `acquisition_method=gekregen` |
| missing `type` | method null; other exact fields retained |
| date precision `year`/`month`/`day` | exact `ItemAcquisitionDate` components |
| non-empty `acquisition.source` | exact `acquired_via` text |
| `type=borrowed` | no Item and no Item-local state |

Every unsupported Item-local field remains null. The mapper does not derive
amount/currency, Condition, signed state, limitation, dust jacket, inscription,
provenance, completeness, inventory number or Location.

## 4. CAT integration

The production CURRENT mapper now combines, per stable Copy ID:

```text
CAT eligibility
  + exact target Library
  + reviewed typed classification
  + reviewed Item-local eligibility/state
  = CatalogItemPlan
```

Terminal external-borrowed and invalid-date outcomes short-circuit Item
planning before an unresolved classification finding can create a false Item.
Classification-blocked owned Copies retain the exact Item-local findings and
remain blocked only on classification.

## 5. Preservation and privacy

Every Item-eligible CURRENT Copy has one or more source-number fields. The
derived Item plan therefore carries a `CatalogItemPreservationPlan` with:

- exact allowlisted reason `copy_auxiliary_evidence_preserved`;
- source payload SHA-256;
- bounded source reference; and
- only boolean/count flags for source numbers, Copy note, disposal and photo
  slot presence.

No private value enters the typed plan, dry-run artifact, CLI output, error or
committed documentation. Book-level nested/legacy acquisition projections are
accounted once as privacy-safe Book findings and never become Copy fallback or
a second active write. Borrowed acquisition and CIRC-01 evidence remain
separate findings.

## 6. One final Item disposition

An Item plan with auxiliary evidence is planned and committed as exactly:

```text
disposition = preserved_deferred
reason      = copy_auxiliary_evidence_preserved
mappings    = item + library_catalog_context
              + optional item_local_details
```

The Item, classification context and optional details still write atomically.
The preservation disposition records only the additional valid source meaning
that has no active V2.001 target.

Committed preserved Items with target mappings satisfy later dependency
resolution. Reconciliation requires the exact preserved disposition/reason and
the normal required Item/context/details mappings. Changed payload, missing or
broken target, changed active state or changed preservation semantics fails
closed. ReadingRound dependency lookup selects its required target type from a
multi-target Item observation without treating the classification-context
mapping as an error.

## 7. Exact CURRENT results

The immutable CURRENT mapping recomputes:

| Result | Count |
|---|---:|
| Copy records | 1,106 |
| Non-empty Item-local states | 72 |
| Reviewed Item-local absences | 1,029 |
| External-borrowed/non-Item Copies | 5 |
| Invalid Item-local dates | 0 |
| Copy auxiliary preservation findings | 1,101 |
| Book acquisition preservation findings | 68 |
| CAT-eligible and Item-local-ready | 1,099 |
| Classification-ready and Item-local-ready Item plans | 744 |
| Classification-blocked but Item-local-ready | 355 |
| Upstream invalid-ISBN Copies | 2 |

The final plan therefore contains 744 `catalog_item` observations. Each has the
single `preserved_deferred` outcome above. The existing circulation result
remains eight preserved and one quarantined. There are zero Item-local planning
errors, zero unmatched references and no `unresolved_item_local_dependency`.

## 8. Final-SHA isolated dry-run

The acceptance run uses only the guarded
`biblio-v2-migration-trial` / `biblio_migration_trial` environment, rebuilt
from the exact clean local implementation commit. The generated ignored
artifact records that commit in `build.git_revision`, reports
`working_tree_dirty=false`, Core `2.39.0`, schema `1026`, the exact CURRENT
manifest and `zero_write_confirmed=true`.

All 57 `wp_biblio_*` table counts are captured before and after and must be
byte-for-byte equal. No apply/import command is invoked. The exact final commit,
artifact checksum and table-count digest are reported with the closure handoff;
they are generated after the self-contained commit and are intentionally not
written back into that commit.

## 9. Verification and independent review

Synthetic tests cover exact bought/received/missing-type/date/source mapping,
all-null reviewed absence, borrowed terminal behavior, invalid-date quarantine,
auxiliary evidence privacy, Book-level preservation, CAT/classification
intersection, preserved Item writes with mappings, replay/reconciliation and a
later ReadingRound dependency on that preserved Item.

The final gate passed Composer metadata/platform, complete PHP syntax, PHPStan,
746 unit tests with two pre-existing PHPUnit notices (3,008 assertions), 570
integration tests (6,486 assertions), WordPress smoke, manifest JSON and
`git diff --check` in 512 seconds. An explicit independent second review
rechecks the final diff against docs 103 and 120, architecture, authorization,
privacy, replay, reconciliation and regression risk. No browser/E2E test
applies because UI and REST are unchanged.

## 10. Boundaries and versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.38.0 → 2.39.0`.
- Biblio UI: `0.20.0` unchanged.
- No source file is modified or re-extracted.
- No migration apply/import is exposed or run.
- Authors, Series, contained works, Wishlist, Archive/disposal, assets,
  assessments and final cutover remain outside this slice.

## Verdict

**GO / CLOSED.** The exact CURRENT Item-local mapping is complete, privacy-safe
and zero-write in dry-run. The truthful Item-plan intersection is 744; the five
external-borrowed physical sources do not become Library Items.
