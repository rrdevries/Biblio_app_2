# MIG-02-SERIES-MAP-01 — Current V1 Series mapper

Status: **GO / CLOSED — PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-17
Product: v2.001
Schema: 1026 (unchanged)
Biblio Core: 2.45.0
Biblio UI: 0.20.0 (unchanged)

## 1. Pre-coding audit

The implementation started from clean `main` at
`a8c2af9723ba9f7693b78a0a0d6fa4295ac925a8`. The audit verified the exact
CURRENT source shape, the schema-1026 Series tables and domain values, the CAT
Work alias boundary, prepared-plan stream, MIG-FND replay/reconciliation and
MIG-02-PRESERVE-01 admissions before production code changed.

`Series` remains a central binary-ID record with an exact mutable display name.
`WorkSeriesMembership` remains unique on Work + Series and carries only a
nullable `SeriesPosition`. No role, kind, group, lifecycle, confirmation or
provider field exists. Schema 1026 is sufficient and unchanged.

## 2. Decision inheritance

The mapper implements `docs/132-d-mig-series-map-01-current-v1-series-mapping.md`
without reinterpretation. Its exact-byte identity exception is bound to the
reviewed CURRENT manifest and does not become a general product identity rule.
V1 remains source evidence, not product authority.

## 3. Mapper architecture

`CurrentV1SeriesMapper` is composed inside `CurrentV1CatalogMapper`, after CAT
has established each Book's exact Work source identity and duplicate-ISBN
representative. Source-specific name grouping, position classification and
preservation admission remain in this mapper. The new source-neutral plans,
participants and writer contain no CURRENT name or position heuristics.

## 4. Series plan and participant

`CatalogSeriesPlan` carries only the exact display name and mapping-contract
binding. `CatalogSeriesMigrationParticipant` creates or reuses a Series only
through MIG-FND source mapping. Its target ID is deterministic from the
semantic Series source identity; an occupied target without that source
mapping fails closed. An unrelated existing Series is never selected by name.

## 5. Membership plan and participant

`CatalogWorkSeriesPlan` carries the exact CAT Work dependency, exact Series
dependency, nullable `SeriesPosition` and mapper-contract binding. The
participant resolves both committed mappings and writes one Work-Series edge.
Exact compatible edges converge; a changed positive position, missing target,
wrong mapping type or divergent replay fails closed.

## 6. Exact-name identity grouping

The Series source namespace is:

```text
v1.series/exact-raw-name-v1/<unpadded-RFC-4648-base64url-of-exact-UTF8-bytes>
```

There is no Unicode normalization, case folding, punctuation stripping,
whitespace normalization, fuzzy matching or provider lookup. Byte-identical
names share one source identity. Case-, punctuation- and whitespace-different
names remain distinct.

## 7. Display names

The exact decoded source bytes become the display name. Domain validation
still requires valid UTF-8, a non-empty value after trim and at most 512
characters, but the mapper does not cosmetically rewrite an accepted value.

## 8. Work dependency

Every membership depends only on
`catalog_work:v1.book/<book-id>/work`. There is no title, ISBN, Edition, Item,
Author or arbitrary target lookup. CAT aliases retain their own Book-scoped
membership occurrence while resolving through the CAT representative mapping.

## 9. Role and type boundary

The plans contain no role and no Series type. `Hoofddeel`, `numbered`,
`ordered_unnumbered`, `unordered`, confirmation, completeness, group and
lifecycle remain absent. Position presence does not imply any of them.

## 10. Position mapping

The 102 reviewed ordinary non-negative integer values map exactly, including
`0`. Missing values stay `NULL`. Gaps stay gaps. No value is compacted,
renumbered, rounded or inferred from source order.

The three compound decimal values and two contextual year labels create active
memberships with unknown position plus separate preservation plans. They never
enter `series_position`.

## 11. Deferred position evidence

Each unsafe raw value produces one `PreservedSourceEvidencePlan` with type
`current_v1_series_position`, reason
`series_position_not_safely_mappable`, ordinary-source privacy, exact logical
locator and a canonical envelope hash. The internal CURRENT verifier validates
the manifest, Book identity, field and exact name/number envelope.

## 12. Nameless evidence

The three base Books without a usable Series name produce no fake Series. Each
creates one preservation plan with type
`current_v1_series_membership_without_name` and reason
`series_name_missing`, binding the exact flag/name/number slot.

## 13. Contained-work preservation

All 19 contained-work Series observations remain outside the base Work lane.
Each exact one-based child slot becomes one atomic preservation plan with type
`current_v1_contained_work_series` and reason
`contained_work_series_deferred`. No contained Work or substitute base
membership is created.

## 14. CAT aliases and conflicts

The representative-only and alias-only CURRENT cases both resolve through
their exact CAT mappings. The alias occurrence remains independently
traceable. The reviewed population has one alias-to-representative convergence
and zero conflict. Synthetic equal-edge convergence reuses the relation;
different Series or incompatible known positions fail closed.

## 15. Preservation integration

Production composition admits exactly the three docs/132
type/reason/ordinary-privacy triples. The generic source-neutral preservation
participant commits them through MIG-FND with no product mapping. Findings
link to their typed plan and use the evidence-envelope hash; they do not create
duplicate observations.

## 16. Prepared-stream integration

Series, membership and preservation records enter the existing
`MigrationPlanPreparer`. The Series mapper contract contributes to the plan-set
digest. Dry-run, apply preflight and reconciliation therefore consume the same
typed universe. No CURRENT apply/import command was run.

## 17. CURRENT mapping totals

The designated immutable extraction was re-enumerated; counts are computed
from source data, not runtime constants:

| Result | Count |
|---|---:|
| Base Series-related occurrences | 164 |
| Named occurrences / exact-byte groups | 161 / 58 |
| Series plans | 58 |
| Work-Series membership plans | 161 |
| Known / unknown positions | 102 / 59 |
| Unsafe-position preservation | 5 |
| Nameless preservation | 3 |
| Contained-work preservation | 19 |
| Total preservation plans | 27 |
| Total typed Series-lane plans | 246 |
| Quarantine / conflicts / unmatched CAT dependencies | 0 / 0 / 0 |

## 18. Reconciliation

The mapping registry classifies `series` as an entity and
`work_series_membership` as a relation. `CoreMigrationTargetInspector` verifies
the exact display name, mapped Work and Series dependencies, deterministic edge
identity and exact nullable position. Existing reconciliation/restart tests
remain green.

## 19. Zero-write proof

The exact manifest-bound planning pass reproduced all counts above without a
write path. The final guarded CURRENT dry-run is executed only after the single
implementation commit is loaded into the detached isolated trial. Its ignored
artifact, checksum and before/after 57-table fingerprints carry the exact
final-SHA proof; no apply/import is performed.

## 20. Trial build provenance

The final trial must be detached and clean at the implementation commit, use
project `biblio-v2-migration-trial`, database `biblio_migration_trial`, schema
1026, Core 2.45.0, UI 0.20.0, the explicit subscriber target User/Library,
adapter `current-v1-json-29` and manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
The exact commit SHA is necessarily recorded in the post-commit completion
report and ignored trial evidence rather than self-referenced by this commit.

## 21. Tests and quality gates

Focused evidence covers exact-byte grouping, near variants, stable replay,
name/position divergence, safe/unknown/unsafe positions, `0`, gaps, all three
preservation shapes, CAT alias convergence/conflict, target collision, missing
dependencies, nullable persistence, target inspection and manifest/hash drift.

The final pre-commit Core gate passed in 653 seconds: Composer metadata and
platform requirements passed; all PHP syntax passed; PHPStan reported zero
errors; 784 unit tests passed with 3,296 assertions and the two existing
PHPUnit notices; 593 integration tests passed with 6,733 assertions; WordPress
smoke returned HTTP 200; and manifest plus whitespace checks passed. The
post-commit isolated trial and privacy scan are recorded in the completion
report. Browser/E2E is excluded because no UI or REST contract changed.

## 22. Current V1 data rule

Only the designated ZIP and read-only extraction are CURRENT. ZIP SHA-256 is
`835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
manifest SHA-256 is
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
No source file was changed, re-extracted or substituted.

## 23. Versions

Product remains v2.001, schema remains 1026, Biblio Core is 2.45.0 and Biblio
UI remains 0.20.0. No schema, REST, UI or provider change exists.

## 24. Git and boundary

This slice ends with exactly one local implementation commit and no push.
Wishlist, Archive, rich Series Intelligence, contained-work product migration,
loan backfill and production apply/import remain outside scope and are not
started automatically.
