# MIG-02-CONTAINED-MAP-01 — CURRENT V1 contained works mapper

Status: **IMPLEMENTED — FINAL ACCEPTANCE REQUIRES THE CLEAN EXACT-SHA
ZERO-WRITE TRIAL; PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-18

Task severity: **High**

## 1. Decision inheritance

This slice implements the manifest-bound decision in `docs/139` without
reinterpretation. V1 remains source evidence rather than product authority.
Only the designated CURRENT package, current V2 containment/Author/Series
canon and MIG-FND prepared-stream contracts are authoritative. No apply/import
is authorized.

## 2. Pre-coding audit

The audit confirmed that schema 1026 already owns central provisional Works,
ordered Work containment, occurrence-scoped Authors/contributors and
Work-Series memberships. Existing repositories reject self-containment,
cycles, duplicate parent-child edges and duplicate positions. MIG-FND already
owned deterministic preparation, transaction boundaries, replay, preservation
and reconciliation. The missing bounded pieces were CURRENT contained-work
interpretation, a source-neutral containment migration participant and a safe
promotion transition for compatible earlier contained-Series preservation.

## 3. Mapper architecture

`CurrentV1ContainedWorkMapper` runs after CAT representative selection. It is
bound to adapter `current-v1-json-29`, source version
`books-29.authors-2.reading-goals-2`, manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`
and contract
`d-mig-contained-map-01.2026-09-18:35a18156490f103d4b6b610f`.
It emits only source-neutral typed records and privacy-safe findings and does
not write, search a provider or perform target name/title lookup.

## 4. Child Work identity

Each original one-based `containedWorks[]` slot owns one source identity:

```text
v1.book/<parent-book-id>/contained-work/<slot>
```

The mapper creates 22 `CatalogWorkPlan` records. Equal titles never merge and
ISBN, Author or Series evidence never selects an existing Work.

## 5. Child title and provisional state

Each child plan carries the exact validated source title. The normal catalog
migration writer creates a provisional Work. Reconciliation now verifies exact
title plus provisional status for newly materialized non-alias Works.

## 6. Containment plan and participant

The new source-neutral `CatalogWorkContainmentPlan` carries exact parent Work
source ID, child Work source ID, positive position and mapper contract. Its
participant declares both Work dependencies. The writer resolves only exact
committed Work mappings and creates or reuses `WorkContainment` inside the
caller-owned MIG-FND transaction. Replay, wrong mapping type, missing targets,
occupied position and divergent payload fail closed.

## 7. Containment order

All 22 relations retain the original one-based source slot. The mapper never
sorts, compacts or derives containment order from `seriesIndex`. Domain and
persistence guards continue to reject self-links, cycles and position/edge
conflicts.

## 8. Contained Authors

The 17 non-empty singular Author strings create 17 occurrence-scoped
`CatalogAuthorPlan` records and 17 contributor records. Identity contains the
parent/slot occurrence and a hash of the validated observed name; matching
names never merge. Every contributor uses role `author`, position `1`, and the
exact child Work dependency. Five blank Author fields create no fake Author,
preservation or inherited parent credit.

## 9. Contained Series

Seventeen named observations resolve only through byte-identical approved base
Series source identities. They create 17 memberships to six existing Series,
with exact positive positions, and create zero Series. Repository display-name
lookup, normalization and fuzzy matching are absent. A non-resolving identity
remains ordinary typed preservation and cannot create a Series. Two
position-bearing observations without a Series name likewise remain preserved
with reason `contained_work_series_deferred`.

## 10. Prior Series-preservation transition

The fresh prepared stream suppresses the former generic 19 contained-Series
preservation records before adding 17 active memberships and the two remaining
preservations. An active membership embeds the exact prior preservation
descriptor expected for its atomic source slot. During a later apply, the
Series writer selects and locks every compatible committed prior preservation,
then verifies source identity, admitted lane, manifest, source version,
canonical payload/hash, reason, evidence and locator before marking it
`processed` in the same transaction as the membership. Historical
observation/evidence/reason rows are not deleted or rewritten. Divergence fails
closed and rolls back the product relation.

## 11. ISBN preservation

The five valid child ISBN values produce five admitted ordinary
`PreservedSourceEvidencePlan` records with reason
`contained_work_isbn_deferred`. The durable descriptor contains provenance,
locator and deterministic evidence hash; the exact value remains in the
immutable source package.

## 12. Edition and Expression absence

The lane creates zero child Editions, Items or Expression surrogates. Child
ISBN evidence cannot cause Work reuse or publication/ownership materialization.

## 13. Classification and downstream non-inheritance

No parent classification, Wishlist, ReadingRound, Personal Reading Truth,
Rating, Review, Note, Item-local state, circulation state or ownership is
copied to a child Work. The lane is bibliographic migration infrastructure
only.

## 14. CAT aliases

The designated population has one positive representative list and no positive
alias list. Mapping groups positive lists by converged parent representative;
more than one positive list quarantines the group instead of unioning or
choosing one. A missing/quarantined parent produces no orphan child metadata.

## 15. Erroneous-Copy independence

Containment depends on the CAT parent Work, never on a Copy or Item. The ten
CURRENT parents have no overlap with the 23 terminal erroneous Copy records,
and the existing exclusion contract is unchanged.

## 16. Prepared-stream delta

The recomputed target shape is:

| Executable type | Before | After | Delta |
|---|---:|---:|---:|
| `catalog_work` | 1,137 | 1,159 | +22 |
| `catalog_work_containment` | 0 | 22 | +22 |
| `catalog_author` | 928 | 945 | +17 |
| `catalog_work_contributor` | 1,139 | 1,156 | +17 |
| `catalog_series` | 58 | 58 | 0 |
| `catalog_work_series` | 161 | 178 | +17 |
| `preserved_source_evidence` | 98 | 86 | -12 |
| **total** | **6,592** | **6,675** | **+83** |

The Series portion replaces 19 old preservations with 17 active memberships
and two preservations, net zero. The added 22 Works, 22 containments, 17
Authors, 17 contributors and five ISBN preservations produce the +83. Runtime
code does not hardcode these totals.

## 17. CURRENT counts

The designated package contains 22 occurrences under ten parent Books, 22
unique titles, 17 non-empty Authors, 19 Series signals, 17 named Series, two
nameless Series positions and five ISBN values. The implementation target is
22 Works, 22 containments, 17 Authors, 17 contributors, 17 memberships to six
existing Series, two Series preservations and five ISBN preservations, with
zero new Series, lane quarantine, conflict or unmatched dependency.

## 18. Reconciliation

`work_containment` is a required relation mapping. Target inspection resolves
the exact parent/child Work mappings, verifies the deterministic edge ID and
checks exact position through the bibliographic repository. Processed
preservation remains reconcilable only when its historical typed evidence and
stored status still match.

## 19. Global dry-run status

Final global evidence must recompute 6,675 executable records, zero unsupported
source types, planning errors and unmatched references, zero contained-lane
quarantine/conflict, and the unchanged one known circulation quarantine. This
closure draft does not substitute expected values for the final exact-SHA
artifact.

## 20. Zero-write proof

Profile and dry-run execute no product or MIG-FND writer. Final acceptance
requires all 57 Biblio table fingerprints and MIG-FND counts to be identical
before/after, plus an unchanged normal-database fingerprint.

## 21. Exact-SHA trial provenance

The authoritative trial occurs only after the single implementation commit in
a clean detached isolated worktree. Its ignored artifact must identify that
exact Git SHA with `working_tree_dirty=false`, target User/Library, manifest,
plan-set digest and `zero_write=true`. The post-commit completion report carries
the artifact checksum and fingerprints; a self-referential SHA is deliberately
not embedded in this commit.

## 22. Tests and quality gates

Focused coverage includes mapper identity/count boundaries, title divergence,
alias conflict, containment create/reuse/replay/self/cycle/position conflicts,
Author/Series/preservation composition, prior-preservation promotion and
rollback, target inspection and reconciliation. Final acceptance additionally
requires the full Core unit/integration suites, PHP syntax, PHPStan,
Composer/platform, WordPress smoke, manifest JSON, whitespace/privacy checks
and independent review.

## 23. Schema/Core/UI versions

Product remains `v2.001`, schema remains `1026`, Biblio Core becomes `2.49.0`
and Biblio UI remains `0.20.0`. No schema or UI asset changes are included.

## 24. Production apply gate

No apply/import was run or authorized. Technical acceptance of this mapper
does not authorize production migration. The known circulation quarantine,
fresh final export, cutover rehearsal and explicit Renée authorization remain
separate gates.

## 25. Git

This slice is delivered as one local implementation commit and is not pushed.
The exact commit and final post-commit trial evidence are reported outside this
self-containing closure draft.
