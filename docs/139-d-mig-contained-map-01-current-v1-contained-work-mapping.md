# D-MIG-CONTAINED-MAP-01 — Current V1 contained-work mapping

Status: **DESIGN GO / CLOSED**

Date: 2026-09-18

Task severity: **High**

Scope: product, bibliographic-relationship and migration-mapping design only.
This document adds no production mapper, migration participant, schema or
runtime behavior and authorizes no apply/import. V1 is source evidence, not
product authority. `MIG-02-CONTAINED-MAP-01` is not started automatically.

## 1. Decision inheritance

Authority for this decision, in order:

1. accepted V2 Work, containment, Author and Series canon in current Git;
2. implemented schema-1026 domain and persistence contracts;
3. the closed CURRENT CAT Work/Edition mapping;
4. the closed CURRENT Author and Series mapping contracts;
5. MIG-02-PRESERVE-01; and
6. the designated immutable CURRENT package as source evidence.

The audited checkout is clean `main` at
`7e085d61207ce259a3cd3b6815c002228454f36d`. Product is `v2.001`, schema is
`1026`, Biblio Core is `2.48.0` and Biblio UI is `0.20.0`.

The earlier CAT decision already selected occurrence-scoped provisional child
Works and ordered Work containment for this source shape. Renée additionally
decided on 2026-09-18 that the manifest-bound CURRENT exact-byte Series
identity may be reused for `containedWorks[].series` only when the decoded,
non-empty bytes equal an already existing Series identity from the approved
base-Series mapping. This is not a product-wide name-identity rule.

## 2. CURRENT source evidence

Only this designated package was used:

| Provenance | Audited value |
|---|---|
| Authoritative ZIP | `/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip` |
| Validated read-only extraction | `.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/` |
| Local intake ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Source family/version | `biblio-v1` / `books-29.authors-2.reading-goals-2` |

The local intake ZIP was re-hashed. All 3,587 extracted files, totaling
19,801,196 bytes, were rechecked against the retained per-file sizes and
SHA-256 values; zero differed. The extraction was not changed or recreated.
No DATA-01, historical MIG-01 source, fixture or network source was used.

## 3. Existing V2 containment canon

Current V2 models containment as an ownership-neutral ordered relation between
central Works. `WorkContainment` has exactly:

- parent Work;
- contained Work; and
- positive integer position.

The domain, repository and schema reject self-links, duplicate parent→child
edges, duplicate positions per parent and cycles. Foreign keys require both
Works to exist. The relation has no type, role, lifecycle, Library, User,
Edition or Item dimension. One contained Work may in principle occur under
more than one parent; a parent may have multiple ordered children.

Containment never creates a virtual Edition or Item. Existing authorized
catalog queries can use contained Work title, Author and Series metadata to
find the parent Item without redefining ownership.

Schema 1026 already contains every required product table, constraint and
relationship repository. The future mapper needs application/migration
composition only; this design requires no schema change.

## 4. Source shape

`books.json` schema 29 stores `containedWorks` as an ordered JSON array under a
stable parent Book. Every one of the 22 objects has exactly five string fields:

| Field | CURRENT population | Proven meaning |
|---|---:|---|
| `title` | 22 non-empty | contained bibliographic title evidence |
| `author` | 17 non-empty, 5 empty | singular contained Author credit evidence |
| `isbn` | 5 non-empty, 17 empty | publication identifier evidence, not child ownership |
| `series` | 17 non-empty, 5 empty | contained Series label evidence |
| `seriesIndex` | 19 non-empty, 3 empty | Series position evidence, not containment order |

There is no contained-work ID, contained Author ID, standalone Work/Book ID,
relationship type, contributor role or independent containment-order field.
The array slot is the only stable structural occurrence locator.

The ten parent Book IDs are:

```text
1770563314670  1770564106459  1770564560056  1771330987186
1771501681196  1771502445320  1773310755956  1773311581977
1773311668774  1780571512078
```

All ten state `hasMultipleWorks=true`. Their Edition formats are one
`omnibus`, four `special edition` and five `standaard`. One other Book has
`hasMultipleWorks=true` but no contained entries; it is not part of the 22
occurrences and this contract does not fabricate children for it.

## 5. Work-versus-Edition containment semantics

CURRENT Book records combine Work, Edition and physical-copy evidence, and the
raw formats include both omnibus- and set-like publications. The accepted CAT
contract nevertheless already classified this exact `containedWorks[]` shape
as ordered Work-containment evidence. Current V2 Work containment is therefore
the active target for this manifest.

The mapping keeps the parent Book's existing main Work, concrete Edition and
physical Item. It creates one child Work per contained occurrence and one
parent→child Work relation. Publication/set facts remain on or with the parent
Edition evidence. The source does not prove a separately owned child Edition,
so no child Edition or Item is created.

The five valid child ISBNs do not change that conclusion. They remain durable
bibliographic evidence for possible later reviewed Edition materialization.
They do not become an ISBN claim, Edition identity, containment identity or
top-level Work match in this slice.

## 6. Child Work identity

The selected identity strategy is one occurrence-scoped provisional Work per
contained array occurrence:

```text
source type: catalog_work
source ID:   v1.book/<parent-book-id>/contained-work/<one-based-slot>
```

This inherits the namespace already reserved by D-MIG-CAT-MAP-01. It binds
identity to the stable parent Book and exact structural slot, not to title,
Author, Series, ISBN, parent title, payload hash or a target database ID.

Changing the payload in the same slot under the same source identity is plan
divergence and fails closed. Reordering changes which evidence occupies the
slot and likewise fails closed; it does not silently create a new identity.

Occurrence-scoped identity may yield provisional duplicates. That is accepted:
a false global merge is harder to repair than traceable temporary duplicates.

## 7. Repeated title analysis

The 22 occurrences contain 22 distinct exact raw titles:

- repeated exact-title groups across parents: 0;
- repeated exact title+Author groups: 0;
- repeated titles inside one parent: 0;
- empty, non-string, invalid-UTF-8 or overlong titles: 0; and
- exact duplicate child payloads: 0.

The absence of duplicates does not weaken the identity rule. A future equal
title under another parent or slot remains a separate occurrence-scoped Work
unless a separately accepted strong identity contract proves sameness.

## 8. Relation to top-level Works

There are zero exact raw-title matches between contained occurrences and
top-level Books. One case-insensitive title+Author resemblance exists, but
casefolded equality is not identity. All five non-empty child ISBNs validate as
canonical ISBN-13 values and match zero top-level Book ISBN claims.

Therefore CURRENT supplies zero strong reusable top-level Work identities.
Every contained occurrence receives its own child Work. No title, normalized
title, title+Author, Series, parent ISBN or provider lookup may select an
existing Work.

## 9. Child Work title and state

The exact source `title` becomes the child Work title. All 22 values satisfy
the current Work validation; the longest is 27 characters. No capitalization,
subtitle, punctuation or wording is rewritten.

Each child Work uses the existing default
`WorkTitleStatus::Provisional`. This is observed migration evidence, not a
librarian-confirmed canonical claim. The Work receives:

- no Edition by default;
- no Item;
- no provider claim;
- no inherited classification;
- no inherited parent Author or Series; and
- only the child-specific Author/Series mappings defined below.

Current Work validity requires a valid title but does not require an Author,
classification, Series membership or Edition. No missing metadata is invented.

## 10. Parent Work dependency

Each active containment resolves its parent only through:

```text
catalog_work:v1.book/<parent-book-id>/work
```

The mapper performs no title, ISBN, Edition, Item, Author, Series or provider
lookup. All ten CURRENT parent dependencies are valid. A missing, foreign,
divergent or CAT-quarantined dependency blocks the child relation and must not
produce a dangling containment edge.

## 11. Containment relationship

Each valid occurrence produces one source-neutral relation plan:

```text
source type: catalog_work_containment
source ID:   v1.book/<parent-book-id>/contained-work/<slot>/containment
parent:      v1.book/<parent-book-id>/work
child:       v1.book/<parent-book-id>/contained-work/<slot>
position:    original one-based containedWorks[] slot
```

The plan has no relation type because the domain has none. A future participant
must resolve both exact committed Work mappings and write or reuse the exact
edge. Exact replay reuses the mapping. A different child or position, occupied
position, missing target, self-link, cycle or divergent prior mapping fails
closed.

## 12. Containment order

The accepted CAT contract treats the array order as the ordered content
relation for this manifest. All 22 positions are known: each parent contributes
two or three one-based slots. There are zero unknown positions.

The mapper must not alphabetize, compact, renumber or infer order from
`seriesIndex`. If an earlier child becomes invalid, each later valid child
keeps its original slot, including any resulting gap.

## 13. Contained Author evidence

Seventeen occurrences contain one non-empty singular `author` string; five are
empty. There are three distinct raw names. The field has no stable Author ID,
array, role or explicit contributor order.

Name equality provides no person identity. One contained name happens to equal
an existing stable Author display name; that does not permit reuse. The other
names likewise receive no fuzzy, provider or top-level Author resolution.

The five empty values create neither an `Unknown Author` nor preservation of a
fabricated absence claim. They simply yield child Works without active Author
edges.

## 14. Contained Author identity and role

The existing ID-less Author principle extends to this separate contained-work
namespace. For each of the 17 valid values, let `normalized_name` be the exact
output of current V2 observed-name whitespace validation and:

```text
name_hash = SHA-256(
  "current-v1-contained-author-occurrence-name-v1" NUL normalized_name
)
```

The identities are:

| Meaning | Source type | Source ID |
|---|---|---|
| contained Author occurrence/contributor | `catalog_work_contributor` | `v1.book/<book-id>/contained-work/<slot>/author` |
| occurrence-scoped provisional Author | `catalog_author` | `v1.book/<book-id>/contained-work/<slot>/author/name/<name-hash>/author` |

The contributor depends on that exact Author and child Work, uses
`ContributorRole::Author` and position `1`, and carries the validated observed
name. The singular source field is explicitly `author`; no `co_author`, editor,
translator or illustrator role is inferred.

If the child Work cannot materialize, the mapper creates no orphan Author or
contributor. The complete contained occurrence evidence follows the blocking
or quarantine outcome.

## 15. Contained Series evidence

CURRENT contains:

| Series evidence | Count |
|---|---:|
| observations with name or position | 19 |
| non-empty names | 17 |
| non-empty positions | 19 |
| distinct non-empty exact names | 6 |
| safe ordinary integer positions | 19 |
| blank name with position | 2 |

The six decoded non-empty names are byte-identical to six existing Series
identities from the approved base-Series mapping. No contained-only name exists.
The 17 named occurrences therefore create active Work↔Series memberships and
zero new Series identities.

The two name-empty observations cannot select a Series. They remain separate
typed `preserved_deferred` observations with their exact position evidence.

## 16. Series identity and reuse boundary

The approved extension is exactly:

```text
same designated manifest
+ non-empty containedWorks[].series decoded bytes
+ byte-identical existing D-MIG-SERIES-MAP-01 Series identity
→ reuse that existing Series identity
```

The reused source identity remains:

```text
v1.series/exact-raw-name-v1/
<unpadded-RFC-4648-base64url-of-exact-UTF8-bytes>
```

The mapper may neither discover by display name nor create a new Series from a
contained-only label. There is no Unicode normalization, casefolding,
punctuation/whitespace normalization, fuzzy matching, translation, provider
claim or extension beyond this exact manifest.

The membership identity is the existing structural Series slot:

```text
source type: catalog_work_series
source ID:   v1.book/<book-id>/contained-work/<slot>/series
```

It depends on the occurrence-scoped child Work and the exact existing Series
source mapping.

## 17. Series position

`seriesIndex` is assessed independently from containment order. All 19
non-empty values are strings representing safe ordinary positive integers
`1` through `4`.

- the 17 active memberships map their exact integer as `SeriesPosition`;
- the two name-empty observations preserve the raw safe position but cannot
  create a membership without a Series identity;
- no value is rounded, compacted, treated as a year or inferred from array
  position; and
- a changed unsafe value must be preserved or rejected under a reviewed reason,
  never coerced.

## 18. Edition and Expression boundary

No child Edition is created. The five valid child ISBNs are insufficient to
prove an independently represented child publication or ownership context, and
none matches a top-level canonical ISBN claim.

V2.001 has no Expression entity. The mapper must not create an Expression
surrogate, pseudo-Edition, variant relation or overloaded containment field.
Any future translation/version evidence remains preserved until a separately
approved target exists.

## 19. CAT aliases

One contained parent, Book `1770563314670`, is the deterministic representative
of a CAT repeated-ISBN pair and owns two contained occurrences. Its alias Book
`1770934920693` maps to the same parent Work but has no contained list. Absence
on the alias is lack of positive evidence, not evidence that the representative
list is false. The two representative occurrences remain active.

There are zero contained occurrences whose own parent Book is a CAT alias.

For a changed manifest, contained lists must be grouped by converged parent
Work before planning. More than one positive list for the same converged parent
is an explicit alias-group conflict unless a later reviewed contract proves
occurrence equivalence. The mapper must not union, choose the bytewise CAT
representative as content authority, apply last-write-wins or merge children by
title/Author/Series.

## 20. CAT-quarantined parents

Neither of the two CAT-quarantined Books contains `containedWorks`. All ten
CURRENT parents are CAT-ready, so all 22 parent Work dependencies resolve.

A future occurrence below a quarantined or unavailable parent produces no
active containment or orphan child metadata. Valid recoverable evidence is
preserved; malformed or contradictory evidence is quarantined according to
the boundaries below.

## 21. Erroneous-Copy interaction

All ten parent Books have one normal non-excluded Copy. There is zero overlap
with the 23 manifest-bound erroneous Copy exclusions.

Containment is bibliographic and depends on the parent Work, not Item
existence. A future excluded or absent parent Copy therefore cannot by itself
remove valid parent Work or contained-work evidence. Conversely, Copy
existence does not prove child Work identity.

## 22. Source identities

The complete deterministic namespace is:

| Meaning | Source type | Source ID |
|---|---|---|
| child Work | `catalog_work` | `v1.book/<book-id>/contained-work/<slot>` |
| containment relation | `catalog_work_containment` | `v1.book/<book-id>/contained-work/<slot>/containment` |
| contained Author | `catalog_author` | `v1.book/<book-id>/contained-work/<slot>/author/name/<name-hash>/author` |
| contributor occurrence | `catalog_work_contributor` | `v1.book/<book-id>/contained-work/<slot>/author` |
| Series membership | `catalog_work_series` | `v1.book/<book-id>/contained-work/<slot>/series` |
| nameless Series preservation | `preserved_source_evidence` | `v1.book/<book-id>/contained-work/<slot>/series` |
| child ISBN preservation | `preserved_source_evidence` | `v1.book/<book-id>/contained-work/<slot>/isbn` |

Source type remains part of logical identity. No namespace contains a target
database ID, random UUID, title, ISBN, Author name or Series name alone. Exact
names are carried in typed payloads and hashes, not used as global identity.

## 23. Typed migration contract

The future mapper composes existing source-neutral plans:

- `CatalogWorkPlan` for 22 provisional child Works;
- `CatalogAuthorPlan` for 17 occurrence-scoped Authors;
- `CatalogWorkContributorPlan` for 17 child credits;
- `CatalogWorkSeriesPlan` for 17 memberships; and
- `PreservedSourceEvidencePlan` for two nameless Series observations and five
  unprojected child ISBN observations.

One bounded source-neutral plan is missing and required:

```text
CatalogWorkContainmentPlan
```

The CURRENT mapper is guarded by a reviewed contract with identity:

```text
d-mig-contained-map-01.2026-09-18:35a18156490f103d4b6b610f
```

It binds adapter, source family/version, exact manifest and all active versus
preserved decisions in this document. The contract identity enters the
prepared mapper-contract bundle, every containment canonical payload, all 17
`CatalogWorkSeriesPlan.mappingContract` values and all seven preservation
plans. A changed manifest or contract therefore changes the plan-set digest
and fails replay/preflight closed.

It contains parent Work source ID, child Work source ID, positive position and
the bounded mapping-contract identity. Its participant declares both exact
`catalog_work` dependencies. Its writer creates or reuses one
`WorkContainment`, produces target type `work_containment` with deterministic
target ID:

```text
work-containment-<SHA-256(
  "work-containment-v1" NUL parent-work-id NUL child-work-id
)>
```

Position remains canonical plan payload and must match on reuse; it is not part
of edge identity. The writer joins the existing caller-owned MIG-FND
transaction. Reconciliation must inspect the exact parent, child and position.

No monolithic source-specific `ContainedWorkPlan` is needed. CURRENT-specific
interpretation remains in `CurrentV1ContainedWorkMapper`; source-neutral plans
own product writes and replay validation.

## 24. Existing preservation inventory

The current prepared stream contains 98 `preserved_source_evidence` plans.
Exactly 19 are atomic contained-Series plans with:

```text
evidence type: current_v1_contained_work_series
reason:        contained_work_series_deferred
source ID:     v1.book/<book-id>/contained-work/<slot>/series
```

The existing 22 contained-work and 17 contained-Author results are findings,
not executable durable preservation plans. Their occurrence counts overlap and
must not be added to the 19 Series observations as though they were distinct
source objects.

The five child ISBN values are currently covered only by generic contained-work
evidence/findings. The future mapper must admit five atomic plans with:

```text
evidence type: current_v1_contained_work_isbn
reason:        contained_work_isbn_deferred
privacy:       ordinary_source
source field:  containedWorks
```

Their evidence envelope binds parent Book, one-based slot, exact ISBN value,
adapter, source version, manifest and mapping contract without inventing a
child Edition.

## 25. Preservation and promotion contract

In a fresh prepared stream, the 19 old contained-Series treatments become:

- 17 active `catalog_work_series` records; and
- 2 remaining `preserved_source_evidence` records.

The mapper must never emit active mapping and preservation for the same atomic
Series slot in one prepared stream. Findings point to the one selected typed
identity and are not extra durable observations.

No current dry-run committed the 19 preservation records. If a compatible
committed preservation nevertheless exists in a future target context,
MIG-02-PRESERVE-01 remains authoritative: a bounded promotion/backfill must
lock and verify the original observation, retained package, locator, evidence
hash and upgraded contract; leave the old observation and reason unchanged;
create the approved mapping traceably; mark the preservation processed; and
reconcile the target. Historical evidence is never deleted or rewritten.

The five ISBN observations remain preservation-only until a separately
approved child-Edition contract exists.

## 26. Quarantine

Valid but currently unprojectable evidence is `preserved_deferred`. Malformed,
contradictory or unsafe evidence is quarantined or fails planning closed.

For this lane, quarantine/fail-closed conditions include:

- missing, non-string, empty, invalid-UTF-8 or overlong child title;
- non-list `containedWorks` or an invalid child object shape;
- duplicate/ambiguous structural slot;
- unavailable or divergent parent Work dependency;
- self-containment, cycle, duplicate edge or occupied parent position;
- competing positive CAT-alias contained lists;
- divergent replay under the same source identity; and
- a structurally contradictory Series slot or divergent active Series replay.

Occurrence-scoped identity, absent Author/classification/Edition, blank Series
name, a valid nonmatching Series name or incomplete metadata is not by itself
quarantine. Valid Series evidence outside the exact approved reuse boundary is
`preserved_deferred`; it never creates a new Series implicitly.

The reviewed CURRENT population has zero quarantine candidates.

## 27. CURRENT mapping counts

### Source

| Measure | Count |
|---|---:|
| contained-work occurrences / parent Books | 22 / 10 |
| unique raw titles / repeated-title groups | 22 / 0 |
| missing or invalid titles | 0 |
| occurrences with Author evidence | 17 |
| Series observations / non-empty names / positions | 19 / 17 / 19 |
| occurrences with other child metadata: ISBN | 5 |
| known / unknown containment order | 22 / 0 |

### CAT and identity

| Measure | Count |
|---|---:|
| valid parent Work dependencies | 10 |
| contained occurrences under CAT alias parents | 0 |
| contained occurrences under one CAT representative parent | 2 |
| contained occurrences under CAT-quarantined parents | 0 |
| excluded-Copy parent overlap | 0 |
| proposed occurrence-scoped child identities | 22 |
| strong reusable top-level Work identities | 0 |

### Planned result

| Result | Count |
|---|---:|
| child Work plans | 22 |
| containment plans | 22 |
| mapped / unknown containment positions | 22 / 0 |
| contained Author plans | 17 |
| contained contributor plans | 17 |
| new Series identities | 0 |
| reused existing Series identities | 6 |
| child Series membership plans | 17 |
| mapped active Series positions | 17 |
| contained-Series preservation plans | 2 |
| contained ISBN preservation plans | 5 |
| quarantined observations / conflicts / unmatched dependencies | 0 / 0 / 0 |

Every one of the 22 occurrences is accounted. There are no intentionally
dropped values.

## 28. Prepared-stream effects

Starting from the accepted CURRENT stream at HEAD:

| Executable type | Before | After design | Delta |
|---|---:|---:|---:|
| `catalog_work` | 1,137 | 1,159 | +22 |
| `catalog_work_containment` | 0 | 22 | +22 |
| `catalog_author` | 928 | 945 | +17 |
| `catalog_work_contributor` | 1,139 | 1,156 | +17 |
| `catalog_series` | 58 | 58 | 0 |
| `catalog_work_series` | 161 | 178 | +17 |
| `preserved_source_evidence` | 98 | 86 | -12 |
| **total executable records** | **6,592** | **6,675** | **+83** |

The preservation delta is `-17` promoted Series slots, `+0` for the two Series
slots that already remain preserved, and `+5` new ISBN preservation plans.
The total Series treatment remains 19 atomic slots: 17 active plus two
preserved.

The prepared plan-set digest and mapper-contract bundle necessarily change.
Dry-run, apply preflight and reconciliation must consume the same rebuilt
typed stream. No old accepted digest authorizes the new stream.

The current global dry-run baseline has zero unsupported source types, zero
planning errors and zero unmatched references. Its one known circulation
quarantine is unrelated and remains unchanged. The contained-work lane itself
adds zero quarantine, conflict or unmatched result for this snapshot; that does
not convert the global plan into production-apply authorization.

## 29. Downstream effects

Occurrence-scoped child Works can appear as separate provisional Work entities.
If later snapshots contain equal titles or Authors, temporary duplicates remain
possible by design. A future Librarian reconciliation may explicitly merge a
child with a proven canonical Work while retaining source occurrence,
containment, Author and Series traceability.

Existing containment-aware Search can match child title, Author or Series and
return the parent Item. No Search, Author page, Series page, Book Detail, merge
UI or governance workflow is implemented by this design.

No child automatically receives Wishlist, ReadingRound, Personal Reading
Truth, Rating, Review, Note, Book Type, Genre, Category, Subject or any parent
metadata. Those domains remain attached to their exact original evidence.

## 30. Product questions

None. Renée's manifest-bound contained-Series decision closes the final product
question. The verdict is **DESIGN GO / CLOSED**.

## 31. Required implementation slice

The next separately authorized slice is:

```text
MIG-02-CONTAINED-MAP-01 — Current V1 contained works mapper
```

Its bounded scope is:

1. one CURRENT-specific contained-work mapper;
2. 22 child Work plans;
3. a source-neutral containment plan, participant, writer and reconciliation;
4. 17 occurrence-scoped Author and contributor plans;
5. 17 contained Series memberships reusing six existing identities;
6. two remaining contained-Series and five child-ISBN preservation plans;
7. exact alias/conflict, replay and prior-preservation promotion guards; and
8. focused tests plus a manifest-bound zero-write CURRENT dry-run.

This document does not authorize implementation, apply/import, schema change,
UI work or production migration.

This docs-only slice leaves product `v2.001`, schema `1026`, Biblio Core
`2.48.0` and Biblio UI `0.20.0` unchanged.

## 32. Acceptance criteria

`MIG-02-CONTAINED-MAP-01` is complete only when evidence proves:

- exact manifest/adapter/source-version binding;
- 22 and only 22 occurrence-scoped provisional child Work plans;
- 22 and only 22 ordered containment plans using original slots;
- no title/Author/Series/ISBN/fuzzy/provider Work merge;
- 17 and only 17 occurrence-scoped Author plus contributor plans;
- `role=author`, contributor position `1`, and no parent Author inheritance;
- zero new Series identities, six exact existing identities reused, 17 active
  memberships and 17 exact mapped Series positions;
- exactly two name-empty contained-Series preservation plans;
- exactly five child-ISBN preservation plans and zero child Editions/Items;
- no simultaneous active+preserved treatment of one atomic Series slot;
- compatible prior preservation is promoted without rewriting history;
- exact parent Work dependencies and fail-closed alias/conflict handling;
- zero quarantine, conflicts and unmatched dependencies for CURRENT;
- prepared counts and plan-set digest recomputed from source, not hardcoded;
- profile and dry-run remain zero-write;
- private/unrelated source data is absent from artifacts, docs, output and
  errors;
- schema 1026 remains sufficient and no schema change is introduced;
- independent review and focused quality gates pass; and
- no apply/import or push occurs without separate authorization.
