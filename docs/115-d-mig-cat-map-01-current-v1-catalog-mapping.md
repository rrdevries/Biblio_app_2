# D-MIG-CAT-MAP-01 — Current V1 catalog mapping

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-16

Task severity: **High**

Scope: product, metadata and migration-mapping design only. This document adds
no schema, Core, UI, adapter, CAT participant or migration write. It uses only
the explicitly designated SOURCE-01 snapshot and its existing read-only
extraction.

## 1. Decision inheritance audit

V1 is source evidence, not product authority. Accepted V2 canon and current
Git remain authoritative.

| Topic | Existing accepted V2 decision | Evidence/source | Current implementation status | CURRENT source evidence | Mapping status | Open state |
|---|---|---|---|---|---|---|
| Work identity | Work is platform-wide; title, Author name, Series and fuzzy similarity never prove identity | Functional design; ADR-010; docs 35, 46 and 106 | Stable Work plus provisional title state exist; CAT creates/reuses through exact mapping or approved ID | 1,139 stable Book IDs; no separate Work IDs | REVIEW_MAPPING | No new product rule |
| Work title | A first Edition title may seed a provisional Work title but never confirms it | ADR-012; ADR-014; CAT-T1 | `WorkTitleStatus::Provisional` is the creation default | All 1,139 Book titles are non-empty | EXACT_TARGET as provisional seed | Closed |
| Edition identity | Edition is one concrete publication under exactly one Work | Product Compass; ADR-010; docs 35 and 106 | Stable Edition, exact Work dependency and source-neutral CAT plan exist | V1 has no separate Edition entity or ID | REVIEW_MAPPING | Closed for one Book occurrence; convergence needs technical work |
| Edition title | Concrete catalog display uses required Edition title | CAT-T1; ADR-014 | Required `edition_title` is persisted and read | All 1,139 Book titles are non-empty; subtitles are empty | EXACT_TARGET | Closed |
| ISBN-10/13 | Both normalize and checksum-validate through current canonical rules | ADR-010; MH-B1; docs 44 and 106 | `IsbnRules`, `IsbnCanonicalizer`, canonical claim and resolver exist | 325 ISBN-10 and 904 ISBN-13 field occurrences | EXACT_TARGET when valid | Closed |
| Canonical ISBN | ISBN is Edition evidence, never Work or Item identity | ADR-010; docs 44 and 106 | Unique canonical ISBN claim and fail-closed Work conflict exist | 970 canonical identities; 17 repeated groups | REVIEW_MAPPING | Technical convergence contract needed |
| Unknown versus no ISBN | Unknown/not entered, explicit no-ISBN and identified ISBN are distinct | Docs 35, 44 and 106; Renée decision 2026-09-16 | Domain, schema and `CatalogEditionPlan` support all three through MIG-02-CAT-F1 | 150 blank Books; zero explicit no-ISBN markers | EXACT_TARGET; CAT contract ready | Closed |
| Edition format/type | No generic format mapping; only an approved allowlist may become binding | ADR-014; `AddBookMetadataReviewPolicy` | Format currently falls back to evidence-only | `standaard` 1,129; `special edition` 9; `omnibus` 1 | PRESERVE_DEFERRED except structural containment | Closed |
| Binding | Publication form is Edition-level; no unapproved value mapping | ADR-014 | Metadata binding target exists, but current allowlist is empty | blank 1,113; `hardcover` 9; `softcover` 17 | PRESERVE_DEFERRED/evidence-only | Closed |
| Publication metadata | Subtitle, language, publisher, publication date and page count are Edition-bound evidence | ADR-014 | Field-review/evidence model exists; CAT plan does not write it | Publisher 724; date 940; language 761; pages 659 | REVIEW_MAPPING with implementation gap | Closed |
| Item/Copy identity | One physical owned copy is one Library-owned Item | Product Compass; ADR-013; doc 106 | Item has Library, Edition, active/archive state, inventory and Location | 1,106 stable Copies; all Book references valid | EXACT_TARGET | Closed |
| Inventory/source number | Inventory is optional and unique only within Library; legacy number is not automatically inventory | Functional design; docs 35 and 103 | Optional `InventoryNumber` exists in `CatalogItemPlan` | `copyNumber` is unique per Copy; legacy/source numbers equal parent `bookNumber` | PRESERVE_DEFERRED pending exact reviewed mapping | Closed |
| Variant relationship | No generic V2 variant relation has been approved | Current Work/Edition/containment model | No variant participant or target relation exists | Five directed, valid, acyclic relations | PRESERVE_DEFERRED | Closed without guessing |
| Omnibus/contained Works | Parent Work may contain ordered Works; containment creates no virtual Edition or Item | Product Compass; docs 35 and 06 | Ordered acyclic `WorkContainment` exists; CAT has no containment participant | 22 ordered ID-less occurrences under 10 Books | REVIEW_MAPPING to existing containment | No product redesign |
| Classification | Library+Work context requires an exact active Book Type; no raw-string guessing or implicit `Anders` | ADR-006; doc 106 | `CatalogItemPlan` requires typed `LibraryCatalogSelection` | 2,305 raw assignments | Known external dependency | Separate mapping slice |
| Item-local details | Copy facts remain Item/Library-owned and unknown stays null | ADR-013; docs 103–104 | Typed state and CAT attachment exist | Copy acquisition is nonzero; condition is empty | Known external dependency | Separate mapping slice |
| Archive | Archive is later Item lifecycle on the same Item identity | Functional design; docs 37, 64 and 106 | Archive periods and preserved reasons exist | 23 archived Copies | Known downstream dependency | Separate wiring |
| Authors | Names never establish Work identity; contributor mapping uses stable exact dependencies | Author canon; doc 107 | AUTH participant exists | Stable Author IDs plus ID-less occurrences | Known downstream dependency | Separate AUTH mapping |
| Series | Names are not identity and cannot merge Works | Author/Series foundation; SOURCE-01 | Series target exists; no CURRENT mapper/participant | 161 named occurrences | Known downstream dependency | MIG-02-SER-01 |

The external Current Phase snapshot contains older interim version statements.
Current Git is the technical authority: product `v2.001`, schema `1026`, Biblio
Core `2.36.0` and Biblio UI `0.20.0`.

## 2. CURRENT source provenance

The sole authoritative snapshot is:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

SOURCE-01 recorded ZIP SHA-256
`835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`.
The sole working source for this audit was the already extracted read-only root:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

The existing SOURCE-01 profile artifact binds that extraction to manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`,
3,587 files and 19,801,196 bytes. Its adapter is `current-v1-json-29`, source
family `biblio-v1`, version `books-29.authors-2.reading-goals-2`. The artifact
checksum sidecar matches the artifact bytes.

No ZIP extraction, source mutation, substitute dataset, DATA-01, MIG-01 fixture
or historical export was used.

## 3. CURRENT source catalog evidence

| Fact | CURRENT count/result |
|---|---:|
| Books | 1,139 |
| Copies | 1,106 |
| Books with no Copy | 39; all 39 are Wishlist-referenced |
| Books with one Copy | 1,094 |
| Books with two Copies | 6 |
| Missing Copy→Book references | 0 |
| Non-empty primary `isbn` | 989 |
| Blank ISBN Books | 150 |
| Invalid-only ISBN Books | 2 |
| Canonical ISBN duplicate groups | 17 groups of two Books, 34 Books total |
| Variant relationships | 5 valid, directed and acyclic |
| Contained-work occurrences | 22 under 10 parent Books |
| Edition format | 1,129 standard; 9 special edition; 1 omnibus |
| Binding | 1,113 blank; 9 hardcover; 17 softcover |
| Carrier | 1,139 `Fysiek boek` |

The 39 Book records without Copies are not owned physical Items. They still
need stable Work/Edition mappings for their Wishlist dependencies.

## 4. V1 Book semantic analysis

A CURRENT V1 `Book` is an aggregate with mixed legacy responsibilities. It is
not truthfully equivalent to one V2 entity.

| Source facts | Truthful V2 layer | Rule |
|---|---|---|
| Book title | Concrete Edition title; provisional Work-title seed | The two target fields do not gain equal authority |
| ISBN, publisher, publication date, language, pages, binding/format | Edition/publication | Only ISBN and title have active CAT fields today; other values remain reviewed evidence |
| Stable Copy rows | Item | One Item per stable Copy ID |
| Copy acquisition, condition, local facts | Item-local | Supplied later as typed state; Book values never overwrite Copy values |
| Book Type, categories and genres | Library+Work classification | Dedicated reviewed mapping supplies target IDs |
| Author IDs/names | Work-contributor dependency/evidence | Never a Work merge key |
| Series | Separate Series dependency/evidence | Never a Work merge key |
| `variantOfBookId` | Ambiguous legacy relation | Preserve without inventing Work or Edition semantics |
| `containedWorks[]` plus `hasMultipleWorks=true` | Ordered Work containment evidence | No virtual Item or child Edition is implied |
| `bookNumber`, `titleGroupKey`, platform/manual/provider fields | Source identity/provenance evidence | Not a canonical Work or Edition key |
| Book-level ownership/acquisition/archive-like fields | Mixed legacy representation | Copy-local evidence has precedence only after its own reviewed mapping; no silent overwrite |

The default safe interpretation is therefore one Book occurrence seeding one
provisional Work and one concrete Edition, except where strong exact Edition
identity requires convergence or the record is blocked/quarantined.

## 5. Work mapping

For a Book that has no exact previously committed mapping and no explicitly
approved canonical target:

1. create one provisional Work using the Book title as seed;
2. do not search or merge by title, Author, Series, `titleGroupKey` or fuzzy
   similarity;
3. retain the stable Book-derived Work source identity for downstream domains.

Separate provisional Works are safer than a false merge. A later Librarian
cleanup may resolve possible duplicates without rewriting migration evidence.

Repeated canonical ISBN requires different treatment because one canonical
ISBN is exact Edition identity in V2. The mapper must not create two Editions
with one claim or pre-map the two Books to separate Works. The 17 groups need a
deterministic convergence/alias contract that produces one Edition and one
Work target while keeping both source Book identities traceable. Current CAT
plans cannot express the alias to a newly created target during planning. This
is a technical mapping-contract gap, not permission to use ISBN as a general
Work merge heuristic.

## 6. Edition mapping

Each non-converged V1 Book produces one Edition plan under its exact Work
dependency. The concrete Book title becomes Edition title. Valid ISBN data uses
the canonical identity described in §9.

The mapper must never invent a second Edition distinction from `standaard`, a
blank binding or absent metadata. Conversely, it must not collapse two Book
records by title. Exact canonical ISBN convergence is the only observed strong
Edition-identity grouping candidate in this population.

The 39 Wishlist-only Books produce Work and Edition mappings but no Item plan.

## 7. Copy/Item mapping

The exact boundary is:

```text
one stable V1 Copy ID -> one V2 Item in the explicit target Library
```

All 1,106 Copy→Book references resolve. Six Books have two Copies, so their
two Items share the mapped Edition while retaining separate Item identities.
Every Item plan depends on the mapped Edition, carries the explicit validated
target Library and requires an already-reviewed classification selection.

The Item is initially created as the active canonical physical target. A later
archive participant applies the 23 archived states/history to those same Item
IDs. CAT must not create a different identity for an archived Copy.

## 8. Source identity namespaces

The future mapper should retain raw `v1.book` and `v1.copy` observations while
deriving stable typed CAT identities. The exact source-type/source-ID pairs are:

| Target plan | Source type | Source ID |
|---|---|---|
| Main Work | `catalog_work` | `v1.book/<book-id>/work` |
| Main Edition | `catalog_edition` | `v1.book/<book-id>/edition` |
| Item | `catalog_item` | `v1.copy/<copy-id>/item` |
| Contained occurrence Work | `catalog_work` | `v1.book/<parent-book-id>/contained-work/<one-based-position>` |
| Future containment relation | `catalog_work_containment` | `v1.book/<parent-book-id>/contained-work/<one-based-position>/containment` |

The embedded IDs are the unchanged stable source IDs. Position is permitted
only for genuinely ID-less contained occurrences and means an occurrence in
that exact ordered container, not a globally resolved Work. No source identity
contains a target ID, title, ISBN, random UUID or regenerated value.

Repeated-ISBN aliases need a dedicated deterministic contract before these
namespaces can be used for those 34 Books; choosing an ISBN-derived source ID
would incorrectly turn publication text into source entity identity.

## 9. ISBN analysis

The analysis applies the exact normalization and checksum rules currently in
`IsbnRules`: remove hyphens/whitespace, uppercase, validate ISBN-10/13 checksum,
convert ISBN-10 through the 978 ISBN-13 form, and retain 979 as ISBN-13 only.

| ISBN structure | Books/occurrences |
|---|---:|
| `isbn` + `isbn13` | 664 Books |
| `isbn` + `isbn10` | 85 Books |
| `isbn` + `isbn10` + `isbn13` | 240 Books |
| No ISBN field value | 150 Books |
| Non-empty raw field occurrences | 2,218 |
| Valid raw occurrences | 2,214 |
| Checksum-invalid raw occurrences | 4 occurrences on 2 Books |
| Formatting variants normalized safely | 3 occurrences |
| Books with exactly one valid canonical identity | 987 |
| Books with conflicting valid identities | 0 |

All 240 Books carrying both ISBN-10 and ISBN-13 canonicalize to one publication
identity. No source value is corrected. The two invalid-only Books are
quarantine candidates; their four repeated invalid field occurrences must be
retained as evidence and must not become explicit no-ISBN.

## 10. Blank/unknown ISBN

The 150 blank Books have no explicit marker proving that the publication has
no ISBN. Their correct source meaning is **unknown/not supplied**, not explicit
no-ISBN. They account for 145 Copies and five Wishlist-only Books.

V2 domain and persistence model this truth through
`EditionIsbnMetadata::unknown()`: both ISBN columns are null and
`explicitly_no_isbn=0`. MIG-02-CAT-F1 extended `CatalogEditionPlan` to accept
that same value-object state without adding a parallel flag.

Renée approved on 2026-09-16 that MIG-02 must use this existing unknown state
for the 150 Books, create no canonical ISBN claim, retain
`explicitly_no_isbn=0` and preserve the stable Book→Edition source identity in
MIG-FND. Substituting `withoutIsbn()` remains prohibited.

The product meaning is closed and the truthful typed CAT plan can now be
constructed. The CURRENT source mapper remains separate and is not implemented
by MIG-02-CAT-F1.

## 11. Duplicate ISBN/convergence

There are 970 distinct canonical identities among 987 valid Books. Seventeen
identities each occur on exactly two Books:

- all 17 pairs have exact equal titles and both records have Copies;
- 15 have equal Author-ID/name arrays;
- the other two differ only by subset/missing Author evidence, not competing
  members;
- 16 have equal observed publication fields;
- one differs in binding and edition-format evidence;
- two pairs are also connected by `variantOfBookId`.

Canonical ISBN proves one Edition identity under current V2 rules. Differing
supplemental metadata remains separate evidence and cannot create a second
Edition. The one binding/format difference is reviewable evidence, not a reason
to violate the ISBN claim.

The current participant can converge only when the exact Work dependency is
already the same. Independent per-Book Work plans would instead trigger
`WorkEditionConflict`. The future mapper therefore needs an explicit
representative-and-alias planning contract with exact target mappings for both
Book identities. Until that exists, the 17 groups are CAT-specific blocked
groups, not silently split, merged by title or discarded.

## 12. Variants

All five `variantOfBookId` references resolve; there are no cycles. Source and
target each have a Copy, exact title, exact Author-ID array and exact Author
name array. Two relations share a canonical ISBN, two have different valid
ISBNs and one side of one relation has unknown ISBN. All five source and target
formats are `standaard`.

Those facts establish a deliberate source relation but not its exact V2
meaning. The field name alone cannot prove same Work, another Edition or a
generic Work relation. The two same-ISBN pairs follow ISBN convergence, not a
variant rule. The five raw relations are otherwise `PRESERVED_DEFERRED`; their
main Book records retain separate provisional Work seeds unless another exact
identity rule applies. No new variant model is invented.

## 13. Edition format/binding

| Raw value | Classification | Treatment |
|---|---|---|
| `standaard` | No special active type | Do not create a value; retain source evidence |
| `special edition` | Existing evidence-only format, no approved active enum | PRESERVE_DEFERRED |
| `omnibus` | Evidence consistent with existing Work containment | Use actual contained-work structure for containment; retain raw format evidence |
| `hardcover` / `softcover` | Edition binding evidence | PRESERVE_DEFERRED under the current empty format allowlist |
| blank binding | Unknown | Keep unknown |

One source concept is never duplicated into both binding and Edition type.

## 14. Titles

For each main Book, its source title is concrete Edition-title evidence and is
used as the required Edition title. The same string may seed the new Work title
only with `provisional` status. This is an allowed provisional bootstrap, not a
claim that the Edition title is the original/canonical Work title.

Contained occurrence titles seed only their occurrence-local provisional
Works. No title equality merges contained occurrences or main Books.

## 15. Publication metadata

| Source field | Population | Existing target | Mapping |
|---|---:|---|---|
| Title | 1,139 | Active Edition title; provisional Work seed | Exact as §14 |
| Subtitle | 0 | Edition field-review/evidence | No populated mapping |
| Publisher | 724 | Edition field-review/evidence | Reviewed evidence; no CAT writer yet |
| Publication date | 940 | Edition field-review/evidence | Preserve exact string/known precision; no invented components |
| Language | 761 | Edition field-review/evidence | Reviewed exact allowlist; no normalization by similarity |
| Page count | 659 | Edition field-review/evidence | Reviewed typed value; invalid values preserve raw evidence |
| Binding | 26 | Format evidence; possible future explicit binding allowlist | Evidence-only now |
| Edition format | 1,139 | Format evidence plus existing containment where applicable | §13 |
| Description | 607 | No settled Work-versus-Edition active migration target | PRESERVE_DEFERRED |
| Cover reference | 883 | Separate cover migration inventory | PRESERVE_DEFERRED; no URL/provider fetch |
| Provider/link/source fields | nonzero | Source provenance/evidence | Never canonical identity; preserve |
| Special features/provenance fields | nonzero bounded population | Ambiguous Edition versus Item-local meaning | Preserve for later reviewed mapping |

Observed publication-date shapes are 630 year, 24 year-month, 99 ISO day, 165
named month-day-year, 11 named month-year, four uncertain-year and seven other
strings. The mapping must retain the source value and must not replace unknown
components with January 1 or migration time.

The metadata field-review/evidence model can represent publisher, date,
language and page count, but the CAT plan/writer currently contains only title
and ISBN. A future participant extension must retain this evidence in the same
outer transaction or classify it preserved; active schema expansion is not
required by this design.

## 16. Contained works/omnibus

The exact source shape is 22 ordered child objects under 10 parents. All 22
have title, 17 have Author text, five have valid ISBN, 17 have Series text and
19 have a Series index. All 10 parents state `hasMultipleWorks=true`; one is
raw `omnibus`, four are `special edition` and five are `standaard`. There are no
exact duplicate child payloads, including across parents.

Current V2 already has the fitting concept: ordered acyclic Work containment.
The truthful mapping is:

1. retain the parent Book's main provisional Work, Edition and physical Item;
2. create one provisional Work per contained occurrence using the occurrence
   identity in §8;
3. add one ordered `WorkContainment` relation from parent Work to child Work;
4. create no child Edition and no virtual child Item;
5. leave child Author/Series data to their dedicated mappings;
6. retain the five child ISBN values as evidence only because the source does
   not prove a separately owned child Edition.

CAT has no containment participant, so this existing product rule needs a
small separate implementation lane. No anthology, Expression or container
subsystem is introduced.

## 17. Item source numbers

Every Copy has a unique 12-character `copyNumber`. It begins with its parent's
9-character `bookNumber`. `legacyBookNumber` and `sourceBookNumber` are equal to
that parent number on all 1,106 Copies and have only 1,100 distinct values
because six Books have two Copies.

`Copy.id` remains the migration source identity. The structure proves that
`copyNumber` distinguishes Copies, but does not prove that the user intended it
as the current Library inventory number. Therefore all three number fields are
retained as source evidence and `CatalogItemPlan.inventoryNumber` remains null
until a reviewed source-number mapping explicitly approves `copyNumber`.

The legacy/source Book number is never used as Item inventory because it is not
unique for the six multi-Copy Books.

## 18. Classification/Item-local boundaries

Every Item plan requires a real, active, already-reviewed
`LibraryCatalogSelection` with one Book Type and optional Genre/Subject IDs.
The catalog mapper accepts those typed dependencies only. It creates no term,
guesses no raw string and never falls back to `Anders`.

Copy acquisition, condition and collector facts belong only in optional typed
`ItemLocalDetailsState`. The dedicated Item-local mapper determines them.
Absent state means no details row and no default. Book-level values never
overwrite Copy-local facts.

## 19. Preservation rules

The mapper must apply these dispositions explicitly:

- `MAPPED` or `TRANSFORMED`: active Work/Edition/Item facts represented exactly;
- `PRESERVED_DEFERRED`: valid format/binding, variant, cover, ambiguous
  publication or other unsupported evidence retained without an active lie;
- `QUARANTINED`: invalid ISBN, contradictory dependency, ownership conflict or
  an unresolved identity group that cannot safely enter CAT;
- `PRODUCT_DECISION_REQUIRED`: none in this catalog mapping design.

Raw payload remains bounded MIG-FND evidence. It is not copied into product JSON
or generic notes. A Copy may retain legitimate Work/Edition/Item target edges
while a separate important fact is preserved deferred, consistent with doc 103.

## 20. Typed CAT mapping contract

For an ordinary mapped Book/Copy, the future layer produces:

### `CatalogWorkPlan`

- source type/ID from §8;
- exact source title as provisional seed;
- `approvedExistingWorkId` only from an exact committed mapping or explicit
  reviewed target, never from similarity.

### `CatalogEditionPlan`

- source type/ID from §8;
- exact `workSourceId` dependency;
- exact Book title as Edition title;
- `EditionIsbnMetadata::identified()` for one valid canonical identity;
- `EditionIsbnMetadata::unknown()` for blank source under the implemented §24
  CAT contract change;
- never `withoutIsbn()` for this CURRENT population because it has zero explicit
  no-ISBN evidence;
- `approvedExistingEditionId` only from exact prior mapping or reviewed strong
  target identity.

### `CatalogItemPlan`

- source type/ID from §8;
- exact Edition source dependency;
- explicit validated target Library;
- required reviewed classification dependency;
- null inventory/Location unless separately reviewed;
- optional already-reviewed `ItemLocalDetailsState` later;
- one plan for each of the 1,106 stable Copy IDs.

The mapper also needs deterministic handling for repeated ISBN aliases and a
separate containment relation lane. Neither is smuggled into a title or ID.

## 21. CURRENT mapping counts

| Population class | Books | Copies | Wishlist-only Books | Current status |
|---|---:|---:|---:|---|
| Valid unique canonical ISBN | 953 | 925 | 34 | Base Work+Edition plannable; Item awaits classification |
| Valid ISBN in repeated group | 34 | 34 | 0 | 17 convergence groups; blocked on alias contract |
| Blank/unknown ISBN | 150 | 145 | 5 | Exact approved mapping; CAT plan contract ready |
| Invalid-only ISBN | 2 | 2 | 0 | Quarantine candidate |
| Conservative uncomplicated subset | 939 | 912 | 33 | Unique valid ISBN, standard format, no variant or containment |

Additional overlapping populations are five variants, nine special-edition
Books, one omnibus Book, 10 parent Books with 22 contained occurrences and 39
Books with no Item. The conservative subset is not a discard rule; it is the
population with none of those CAT-specific structural complications.

No CURRENT Book or Copy is silently unaccounted for.

## 22. Downstream referential consequences

- AUTH needs the stable Work source IDs; it must not infer Work from names.
- ReadingRounds, Personal Reading Truth and Notes resolve each parent Book to
  its exact Work mapping.
- Wishlist resolves 41 stable entries through exact Work/Edition mappings,
  including the 39 Books without Copies.
- Series uses the stable Work mapping but maintains separate identity rules.
- Archive and circulation preservation resolve stable Copy IDs to the same Item
  mappings created here.
- Contained occurrence Works receive no Item mapping, preventing false
  possession and virtual copies.

Repeated-ISBN aliases must remain resolvable from both original Book IDs before
these downstream participants can be considered ready.

## 23. Dry-run blockers

### Truthfully plannable at the base CAT layer

- 953 Books with one unique valid canonical ISBN can produce a base Work and
  Edition plan;
- their 925 Copies have a truthful Item identity/dependency shape;
- 939 Books and 912 Copies form the conservative uncomplicated subset.

### Blocked only by reviewed external dependencies

- every Item plan waits for exact classification target IDs;
- Item-local details wait for their dedicated mapping but may truthfully remain
  absent when unknown;
- publication metadata evidence waits for an evidence-writer contract;
- containment waits for a small relation participant.

### CAT-specific implementation blockers

- 150 unknown-ISBN Books are representable by the closed MIG-02-CAT-F1 plan
  contract; the CURRENT mapper itself remains separate;
- 17 repeated-ISBN groups need a deterministic representative/alias contract;
- five variant relations are preservation-only and do not block their main
  records unless they overlap another blocker.

### Preservation/quarantine

- two invalid-only ISBN Books are quarantine candidates;
- unsupported valid metadata stays preserved deferred, never discarded.

No dry-run or apply is run in this design slice.

## 24. Product decisions

Open product questions: **zero**.

Renée approved on 2026-09-16 that `CatalogEditionPlan` may be extended to accept
the already existing `EditionIsbnMetadata::unknown()` state for CURRENT Books
with blank ISBN and no explicit no-ISBN evidence. The plan creates no canonical
ISBN claim, leaves `explicitly_no_isbn=0`, retains the stable Book→Edition
identity through MIG-FND and never derives explicit no-ISBN from blank input.

All other catalog questions are closed by existing V2 canon or an explicit
mapping disposition: repeated ISBN uses exact Edition convergence, invalid
ISBN quarantines, variants preserve deferred, contained occurrences use the
existing ordered Work-containment model, and classification/Item-local facts
remain separate reviewed dependencies.

## 25. Required implementation slices

Implementation remains separately authorized and should stay bounded:

1. `MIG-02-CAT-F1` — **GO / CLOSED** in doc 116: unknown ISBN is accepted and
   proven in the existing CAT Edition plan/writer without weakening
   identified/no-ISBN invariants.
2. `MIG-02-CAT-MAP-01` — translate exact CURRENT Book/Copy records into reviewed
   CAT plans, including deterministic repeated-ISBN representative/alias
   mappings and preservation dispositions.
3. `MIG-02-CAT-CONT-01` — map occurrence-local contained Works and ordered
   containment without virtual Editions/Items.
4. Dedicated classification and Item-local mapping slices supply typed
   dependencies; they are not folded into the base mapper.
5. A bounded Edition-evidence slice retains publisher/date/language/page/format
   evidence transactionally if active migration of that evidence is required.

No implementation is authorized by this document.

## 26. Acceptance criteria

Future implementation must prove at minimum:

- one normal Book creates one provisional Work and concrete Edition;
- each stable Copy creates exactly one Item in the explicit Library;
- two Copies of one Book share the Edition and remain distinct Items;
- ISBN-10/13 equivalents produce one canonical identity;
- repeated canonical ISBN converges safely without a Work conflict and keeps
  every Book traceable;
- invalid ISBN is not corrected or converted to no-ISBN;
- blank ISBN remains unknown and creates no ISBN claim;
- explicit no-ISBN is used only when source evidence explicitly proves it;
- no title, Author, Series or fuzzy Work merge occurs;
- variant facts are preserved without invented semantics;
- contained Works receive occurrence-local provisional Work identities and
  ordered containment, never virtual Items;
- Edition title and provisional Work seeding retain distinct authority;
- publication metadata is mapped to an approved target or preserved;
- classification and Item-local state use only reviewed typed dependencies;
- exact replay is idempotent and changed payloads fail closed;
- Author, Reading, Note, Wishlist, Series, Archive and circulation dependencies
  resolve through stable CAT identities;
- simulation/dry-run is deterministic, privacy-safe and zero-write.

### Current V1 data rule

Only the authoritative ZIP identity and the existing SOURCE-01 read-only
extraction named in §2 may be used. Source data, titles and personal content are
not committed. This document contains counts, structural facts, hashes and
allowlisted raw enums only.

### Schema/Core/UI impact

This design slice itself changed no schema, Core, UI, adapter, participant or
runtime data. The separately authorized MIG-02-CAT-F1 follow-up keeps schema
`1026` and Biblio UI `0.20.0`, and bumps Biblio Core to `2.36.0`.

### Git

Audit baseline: local `main` at `0d8b186`, 48 commits ahead of `origin/main`,
clean at audit start. DESIGN GO permits one local docs-only commit after final
review. Nothing is pushed.
