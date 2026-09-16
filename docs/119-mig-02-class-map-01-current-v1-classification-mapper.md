# MIG-02-CLASS-MAP-01 — Current V1 classification mapper

Status: **GO / CLOSED**

Date: 2026-09-16

Task severity: **High**

Scope: exact reviewed CURRENT Book Type, Category and Genre evidence to typed
Library-local V2 classification dependencies plus complete preservation and
blocking findings. No Item-local mapping, taxonomy creation or apply/import.

## 1. Pre-coding audit

`CurrentV1SourceAdapter` retains `bookType`, `categories[]`, `genres[]` and the
optional `taxonomyMeta.bookType.migration.status` on each stable Book. It also
validates seven Book Type, 14 Category and 44 Genre definitions, seven taxonomy
alias rules and the ID-less 446-entry provider review queue. The queue and
definitions needed a bounded auxiliary projection because they deliberately do
not become fabricated migration observations.

V2 terms are Library-local typed records. Durable seed keys locate approved
semantics, while the actual IDs vary by Library and environment. Existing
Book Type and Genre repositories already support exact Library+seed-key reads.
`LibraryCatalogContext` remains Library×Work, with exactly one Book Type and
unordered Genre/Subject sets. `CatalogItemPlan` already consumes the complete
typed `LibraryCatalogSelection`.

CAT-MAP represented missing classification as a null dependency and
`unresolved_classification_dependency`, independently from Item-local review.
The safest architecture was therefore a CURRENT-only mapper collaborator after
validated adapter inspection and before source-neutral CAT participants.
Schema 1026 already represented every target and remained sufficient.

## 2. Decision inheritance

The implementation follows doc 118 and ADR-006 without redesign. V1 remains
source evidence, not taxonomy authority. The mapping contract is bound to:

- adapter `current-v1-json-29`;
- manifest `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`;
- version `d-mig-class-map-01.2026-09-16`; and
- explicit target Library identity.

There are no approved per-Book override rows for the 355 CURRENT
`review`/`no_signal` records. The bounded contract requires exact stable Book ID
plus observation payload hash, exact raw value/review state, decision status,
reviewed target seeds and decision provenance for any such future reviewed row.
The sorted approval-set digest is part of the contract identity, so changing a
row changes artifact provenance. Targets outside this contract's reviewed
tables require a new contract version. Absence remains blocking.

## 3. Mapper architecture

`CurrentV1ClassificationMapper` receives adapter-validated Books and CAT's
deterministic representative relation. It resolves the fixed mapping matrix to
typed Library-local IDs, builds proposed selections, retains source review
state, reconciles converged Works and returns selections only for ready
Book/Copy candidates.

The production mapper registry is composed with the actual Book Type and Genre
repositories. `CoreApplication` exposes that registry as a named migration
application boundary; the CLI no longer constructs a repository-free mapper.
Profile mode remains raw and dry-run remains zero-write.

The adapter-owned `CurrentV1ClassificationEvidence` projection exposes only
definition dimension/status/raw label and review-queue/alias file hash plus
aggregate counts. Detailed queue contexts, titles, descriptions and timestamps
remain in the immutable source package.

## 4. Book Type mapping

| CURRENT | Books | Copies | V2 seed key |
|---|---:|---:|---|
| Leesboek | 713 | 687 | `book_type.reading_book` |
| Kennisboek | 249 | 245 | `book_type.knowledge_book` |
| Kookboek | 63 | 62 | `book_type.cookbook` |
| Stripboek | 25 | 24 | `book_type.comic_book` |
| Studieboek | 15 | 15 | `book_type.study_book` |
| Jeugdboek | 50 | 49 | `book_type.reading_book` |
| Kinderboek | 24 | 24 | `book_type.reading_book` |

`Jeugdboek` and `Kinderboek` are explicit reviewed mapping rows, not name or
suffix heuristics. `Kinderboek` never infers `Prentenboek`. Unknown values have
no `Anders`, first-term, null-to-proceed or `Leesboek` fallback.

## 5. Genre mapping

Only the following exact CURRENT meanings become active Genres:

| CURRENT | Assignments | V2 seed key |
|---|---:|---|
| Fantasy | 119 | `genre.fantasy` |
| Sciencefiction | 115 | `genre.science_fiction` |
| Thriller | 46 | `genre.thriller` |

All 761 other Genre assignments remain preserved deferred. No translation,
case-folding, punctuation, compound split, synonym or Subject promotion is
performed.

## 6. Preserved classifications

All 14 Category definitions and 1,264 assignments remain preserved deferred.
Of 44 Genre definitions, three have exact targets and 41 remain preserved.
Definition findings retain taxonomy dimension, source definition status and
exact raw value. Assignment findings retain the exact source Book relation.
No raw evidence is copied to product fields.

## 7. Review queue

All 446 ID-less provider-review entries remain one adapter-bound preserved
population: 443 pending and three ignored; 431 Open Library and 15 Google
Books. The artifact carries file/hash identity and aggregate population only.
The seven external taxonomy alias rules are preserved one per exact, unique
rule ID, bound to the alias-file hash, and never executed as V2 mappings.

## 8. Assignment resolution

The only path is stable source Book → CAT Work source identity → explicit
target Library → exact reviewed seed key → exact typed local ID. There is no
title, Author, Edition, Item, display-name or normalized-name lookup. Missing,
inactive or foreign target terms fail closed.

## 9. Convergence conflicts

All 17 repeated-ISBN groups are compared before Copy planning. Compatible
selections remain deterministic; a source `review`/`no_signal` flag blocks only
that source record and does not contaminate an independently ready identical
selection. Seven groups propose different Book Type or Genre sets and remain
blocked as `converged_classification_conflict`. Neither representative, alias,
union, last write nor Book-ID order wins. Each of the seven group outcomes also
has two supporting findings binding representative ID, member Book ID and exact
source payload hash, so all 14 sides remain traceable without source entities.

## 10. CAT dependency integration

A ready Book supplies the exact typed selection to each eligible Copy. A
blocked Book retains `unresolved_classification_dependency`. Item-local review
remains independently unresolved for every CAT-eligible Copy, so the CURRENT
run still creates zero Item plans. Work and Edition planning remain usable.

## 11. CURRENT mapping totals

| Result | Books | Copies |
|---|---:|---:|
| CAT-eligible | 1,137 | 1,104 |
| Classification activation-ready | 745 | 748 |
| Classification-blocked among Books with Copies | 353 | 356 |

The blocked Copy population is the 349 source-review candidates plus seven
otherwise exact counterparts in real converged-selection conflicts. Counts are
recomputed evidence, not runtime constants.

All 65 definitions, 1,139 Book Type assignments, 1,264 Category assignments,
1,041 Genre assignments, 446 queue entries and seven exact-ID alias rules
received an explicit mapped, transformed, preserved or blocked finding. There
were seven convergence group conflicts with 14 supporting member rows, zero
planning errors and zero unmatched references.

## 12. Zero-write proof

The exact CURRENT dry-run ran against the isolated
`biblio-v2-migration-trial` / `biblio_migration_trial` target. All 57
`wp_biblio_*` product and MIG-FND table counts matched before and after. The
artifact states `zero_write_confirmed=true`; no apply/import was invoked.

Artifact SHA-256:
`e34ae22d4dec949fd3204672c55144e60d279c3622fdb241849097ae2ceb960d`.

The trial checkout was restored after the temporary runtime validation. Its
existing validator also reported an unrelated environment-version drift:
WordPress runtime 7.1 versus the older runbook expectation 7.0.2. Project,
database, target identity, schema and isolation guards had already passed; the
classification dry-run itself was read-only and its full table-count equality
is independent evidence.

## 13. Reconciliation

Active term mappings, preserved Category/Genre assignments, review-blocked
Books, conflicts and Copy dependency outcomes are deterministic source-mapping
findings. Definitions, review queue and alias rules stay within the approved
auxiliary/non-observation boundary; alias rules retain their real source IDs.
Conflict-member rows are supporting evidence separate from the seven group
outcomes. No fake entity observation or target mapping is added, and no
assignment disappears or is double-counted.

## 14. Explicitly deferred

Item-local source mapping, Authors, Series, contained works, Wishlist, Archive,
assessments, loan backfill, Search/filter UI and every apply/import path remain
outside this slice.

## 15. Tests / quality gates

Synthetic coverage proves all seven Book Types, the three exact Genres,
unsupported preservation, source-review blocking, exact/failed convergence,
per-member conflict binding, provenance/digest-bound approval rows, exact
unique alias-rule IDs, active Library-local target validation and CAT versus
Item-local dependency separation. The CURRENT run proves exact counts,
privacy-bounded artifacts and zero writes. The final Core gate passed Composer
metadata/platform, complete PHP syntax, PHPStan, 737 unit tests with two
pre-existing PHPUnit notices (2,947 assertions), 569 integration tests (6,472
assertions), WordPress smoke, manifest JSON and `git diff --check` in 534
seconds. The independent review's three traceability findings were corrected;
its second pass reported no remaining blocker or major.

No browser/E2E test applies because REST and UI are unchanged.

## 16. Current V1 data rule

Only the immutable SOURCE-01 extraction and manifest above were used. The ZIP
was not re-extracted, the source was not modified, and artifacts were written
outside the source root. No historical export, provider, network call, fuzzy
classifier or source cleanup was used.

## 17. Schema/Core/UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.37.0 → 2.38.0`.
- Biblio UI: `0.20.0` unchanged.

## 18. Git

The slice started from clean `main` at `5026bbf`. Exactly one local
implementation commit is created after final gates and independent review.
Nothing is pushed.
