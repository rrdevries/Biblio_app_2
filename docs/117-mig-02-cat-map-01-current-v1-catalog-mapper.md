# MIG-02-CAT-MAP-01 — Current V1 catalog mapper

Status: **GO / CLOSED**

Date: 2026-09-16

Task severity: **High**

Scope: exact CURRENT `v1.book`/`v1.copy` semantics to typed, source-neutral CAT
planning records. No migration apply/import, classification mapping,
Item-local source mapping, containment implementation, schema or UI change.

## 1. Pre-coding audit

`CurrentV1SourceAdapter` validates the reviewed schema-29 aggregate before
enumeration. A `v1.book` record retains the exact allowlisted Book object,
including stable `id`, title, three ISBN fields, Authors, Series, publication
evidence, classification strings/arrays, variant/contained-work structures and
historical/user-owned nested facts. A `v1.copy` retains stable `id`, exact
`bookId`, acquisition/condition/archive/location/source-number fields and
circulation occurrences. The adapter emits one raw observation per stable ID;
it does not assign V2 meaning.

The source-neutral contracts were already:

- `CatalogWorkPlan`: provisional title plus only an exact approved target;
- `CatalogEditionPlan`: exact Work source dependency, concrete title, one
  `EditionIsbnMetadata` tri-state and only an exact approved target;
- `CatalogItemPlan`: exact Edition dependency, target Library, reviewed typed
  classification, optional inventory/Location, optional reviewed typed
  Item-local state and only an exact approved target.

RUN-01 first inventories and enumerates adapter records, then independently
routes typed records to one participant by source type. The new mapper is a
registered batch layer between those phases. Batch scope is required because
one Book produces separate Work and Edition identities and repeated ISBN needs
population-level grouping. It leaves both the raw adapter and source-neutral
participants free of CURRENT field interpretation.

Generated dependencies remain typed `source_type`+`source_id` edges in
`PlannedMigrationRecord`. Non-active CURRENT facts remain privacy-safe mapping
findings with a stable disposition/reason and original raw source identity.
MIG-FND remains the sole future preservation/quarantine/apply boundary; this
slice writes no observation, mapping, preservation or quarantine row.

One Book truthfully emits `catalog_work:v1.book/<id>/work` and
`catalog_edition:v1.book/<id>/edition`. One Copy would emit
`catalog_item:v1.copy/<id>/item` only after both classification and Item-local
review dependencies are explicit. Schema 1026 already represents every active
target and remains sufficient.

## 2. Decision inheritance

The mapper implements doc 115 without redesign. Work remains platform-wide;
title, Author, Series, `titleGroupKey` and similarity never identify it.
Concrete Book title becomes Edition title and only a provisional Work-title
seed. Canonical ISBN is exact Edition evidence, never general Work identity.
Unknown ISBN remains unknown, invalid evidence is never repaired, and no
source number becomes inventory. Library and user ownership rules are
unchanged.

## 3. Mapper architecture

`MigrationSourceMapper` and its adapter-keyed registry extend RUN-01 with an
optional deterministic mapping stage. Adapters without a mapper retain exact
passthrough behavior. `CurrentV1CatalogMapper` consumes the complete reviewed
CURRENT enumeration, removes raw Book/Copy records from participant routing,
adds typed CAT records, passes unrelated source types through unchanged and
emits safe `MigrationSourceMappingFinding` records.

Production CURRENT dry-run registers this mapper. Profile mode remains raw
source inspection. Dry-run artifacts add safe mapping findings/counts and the
number of mapped planning records; typed payloads and raw source content remain
absent.

## 4. Work mapping

Every non-quarantined Book has its own stable Work source identity. The mapper
creates a provisional `CatalogWorkPlan` without any title/name lookup. Two
same-title unknown-ISBN Books remain two independent Work plans. A repeated-
ISBN alias instead carries an exact representative Work source dependency.

## 5. Edition mapping

Every non-quarantined Book has its own stable Edition source identity and exact
Book-derived Work dependency. The source title is the concrete Edition title.
Repeated-ISBN aliases retain their own Edition source identities while
depending on the representative Edition source mapping. There is no random,
title-derived or ISBN-derived logical source ID.

## 6. Known ISBN

All non-empty `isbn`, `isbn10` and `isbn13` evidence is parsed through the
existing `IsbnCanonicalizer`. Equivalent ISBN-10/13 values collapse only to
one canonical Edition identity and produce identified metadata. Multiple valid
canonical identities on one Book fail closed. No provider/network lookup or
repair occurs.

## 7. Unknown ISBN

A Book with all three ISBN fields blank produces
`EditionIsbnMetadata::unknown()`. It plans no canonical claim and never calls
`withoutIsbn()`. Equal title does not converge unknown Editions.

## 8. Invalid ISBN

An invalid-only Book is quarantined as `invalid_isbn`; it produces neither a
Work nor Edition plan and therefore fabricates no Edition identity. Raw source
evidence remains represented by the original raw record/hash boundary. If a
future reviewed source contains a valid canonical identity plus separate
invalid ISBN evidence, the valid identity may plan while the invalid evidence
is explicitly `invalid_isbn_evidence_preserved`.

## 9. Duplicate ISBN aliases

The exact canonical ISBN first establishes a repeated-Edition group. The
representative is the bytewise-smallest stable Book source ID; title, Author or
other presentation data never influences selection. A group with conflicting
Book titles fails closed as `duplicate_isbn_work_conflict` instead of silently
merging Works.

The representative creates the ordinary Work/Edition intent. Every other
member keeps its own Work and Edition source identities with exact alias
dependencies. The source-neutral CAT writer resolves those dependencies only
through already committed representative mappings, verifies Work/ISBN
compatibility, and commits reused Work/Edition mappings for the alias. No
product alias entity, Work rewrite or Edition move is introduced.

## 10. Copy / Item mapping

Stable Copy ID remains the sole Item source anchor. Multiple Copies of one Book
retain different Item source identities and share the exact mapped Edition.
`copyNumber`, `legacyBookNumber` and `sourceBookNumber` remain deferred evidence;
inventory and Location stay null. Books without Copies create no Item.

The default CURRENT run supplies no reviewed classification or Item-local
mapping, so it emits no Item plan and records both unresolved dependencies for
each otherwise valid Copy. Synthetic tests prove that an explicitly supplied
classification plus reviewed Item-local result (including reviewed absence)
produces exactly one typed Item plan.

## 11. Variant / deferred evidence

All variant relations are `deferred_variant_relation` findings and do not
change catalog identity. Edition format/binding/publication/cover/provider and
other unsupported catalog facts remain `deferred_edition_evidence`. Source
records remain explicitly accounted; source-number evidence is separately
deferred. No unsupported value is promoted into product state.

## 12. Contained-work boundary

The mapper counts every contained-work occurrence as
`deferred_contained_work`. It creates no contained Work, Edition, Item or
relation. MIG-02-CAT-CONT-01 remains the separately authorized containment
lane from doc 115.

## 13. Classification dependency

Raw Book Type/category/genre strings are never mapped or defaulted. Item
planning requires a supplied `LibraryCatalogSelection`; without it, the Copy
is explicitly `unresolved_classification_dependency`.

## 14. Item-local dependency

Acquisition, condition, dates, source, numbers and provenance are not mapped.
The dependency provider must explicitly state that Item-local review completed;
the reviewed result may truthfully be null. Until then the Copy is
`unresolved_item_local_dependency`.

## 15. Downstream resolution

Author, ReadingRound, Note, Wishlist and Series mappers can derive the exact
Book-specific Work/Edition source IDs without presentation matching. Both
members of a repeated-ISBN pair have committed-source identities that converge
on the same actual targets through CAT alias mappings. Archive and circulation
continue to anchor on stable Copy IDs. No downstream product migration occurs
in this slice.

## 16. Reconciliation integration

Alias observations remain ordinary `catalog_work`/`catalog_edition`
observations, so the existing RECON-01 entity contracts account for both
source identities and verify the shared target exists. Mapping findings account
for invalid ISBN, variants, containment and unresolved Item dependencies before
apply. No Book or Copy silently disappears.

## 17. CURRENT mapping totals

Read-only mapping of manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`
recomputed:

| Mapping class | CURRENT result |
|---|---:|
| Books / Copies | 1,139 / 1,106 |
| Unique valid canonical ISBN Books | 953 |
| Repeated canonical ISBN representatives / aliases | 17 / 17 |
| Unknown-ISBN Books | 150 |
| Invalid-only quarantined Books / dependent Copies | 2 / 2 |
| Work plans / Edition plans | 1,137 / 1,137 |
| Copies blocked on classification | 1,104 |
| Copies blocked on Item-local review | 1,104 |
| Item plans without reviewed dependencies | 0 |
| Variant relations | 5 |
| Contained-work occurrences | 22 |
| Conservative uncomplicated Books / Copies | 939 / 912 |

No total is hardcoded in mapping behavior. The dry-run also reproduced 987
canonical ISBN operations, 150 unknown-ISBN Editions and zero planning errors
or unmatched references.

## 18. Zero-write proof

The exact CURRENT profile and dry-run wrote only ignored artifacts/checksums.
Exact row counts for every `wp_biblio_*` product and MIG-FND table were captured
immediately before and after the final dry-run and were identical. In
particular, migration runs, locks, source observations, target mappings,
preservations and quarantine all remained zero. The artifact declares
`zero_write_confirmed=true`; manifest identity remained unchanged.

The final dry-run artifact SHA-256 was
`c027b4132b8bbe512bf13d059f24a70999f9c89fb828283f381a8fe0de2de38c`.
A privacy scan compared CURRENT titles, Author strings and Note bodies to the
artifact. Two short substring candidates were hash/path-reviewed as collisions
inside structural locations/source IDs/hashes, not emitted content; no private
source value was serialized as catalog payload or explanation.

## 19. Explicitly deferred

Classification source mapping, Item-local source mapping, Authors, Series,
contained-work implementation, Wishlist, Archive, assessments, circulation
promotion, production apply/import and final cutover remain outside this slice.

## 20. Tests / quality gates

- synthetic mapper coverage: known, unknown, invalid, repeated ISBN,
  deterministic aliases, conflict quarantine, Copies, deferred evidence and
  unresolved/reviewed dependencies;
- CAT integration proves representative and alias Book source identities map
  to one Work and one Edition/claim;
- CURRENT production dry-run proves exact totals, deterministic artifact and
  zero writes;
- final unit suite: 725 tests, 2,899 assertions and two pre-existing PHPUnit
  notices; final integration suite: 568 tests and 6,471 assertions;
- final full Core gate: green in 535 seconds, including PHP syntax, PHPStan,
  Composer/platform, WordPress smoke, manifest and whitespace;
- focused alias integration after the independent-review finding: five tests,
  25 assertions; the re-review closed the finding with GO; and
- CURRENT artifact privacy and exact before/after row-count checks: green.

No browser/E2E test is applicable because REST and UI are unchanged.

## 21. Current V1 data rule

Only the unchanged designated SOURCE-01 extraction was inspected. The ZIP was
not re-extracted, source files were not modified, no artifact was written below
the source root and no DATA-01/MIG-01/historical export or provider was used.

## 22. Schema/Core/UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.36.0 -> 2.37.0`.
- Biblio UI: `0.20.0` unchanged.

## 23. Git

Implementation started from clean local `main` at `b08d24e`. Exactly one local
implementation commit is created after final gates and independent review.
Nothing is pushed.
