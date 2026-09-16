# D-MIG-AUTH-MAP-01 — Current V1 Author mapping contract

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-16

Task severity: **High**

Scope: product, identity and migration-mapping design only. This document adds
no production mapper, participant, schema, Core behavior, provider claim,
apply/import path, REST or UI. It uses only the designated CURRENT V1 snapshot
and its existing read-only extraction.

## 1. Decision inheritance

V1 is source evidence, not product authority. Accepted V2 Author and
WorkContributor canon, current implementation and the closed MIG-02-AUTH-01
participant contract remain authoritative.

| Topic | Existing V2 decision | Current implementation | CURRENT source evidence | Mapping status | Open question? |
|---|---|---|---|---|---|
| Canonical Author identity | `AuthorId` is platform-wide identity; display-name equality never proves person identity | Schema 1024 stores non-unique names and `provisional|resolved` identity | 257 stable Author IDs; no duplicate IDs | Stable valid IDs map one-to-one to provisional Authors | No |
| Identity state | Migration without accepted strong authority is `provisional` | `CatalogAuthorPlan` can create/reuse only provisional/observed Authors | A V1 ID proves source continuity only | `provisional` | No |
| Display name | Canonical display name is presentation metadata; routine materialization never silently rewrites it | `CatalogAuthorPlan` uses canonical whitespace validation; replacement is governed/versioned | 257 valid `displayName` strings | Entity name seeds `observed`; occurrence variants remain credit evidence | No |
| Display-name status | Observation does not equal Librarian confirmation | Closed values are `observed|librarian_confirmed` | No V1 Librarian-confirmation authority | `observed` | No |
| Contributor credit identity | One source-scoped Work credit is distinct from person identity | Credit key binds Work, role, position, normalized name and source identity | 1,146 ordered `books[].authors[]` occurrences | One deterministic occurrence record per source slot | No |
| Stable contributor identity | Exact source Author mapping, not name, selects the canonical Author | `CatalogWorkContributorPlan` requires an exact `catalog_author` dependency | 469 positional Author-ID references; all valid | Resolve through the exact V1 Author mapping | No |
| ID-less contributor identity | Independent name-only evidence remains independent; false merges are worse than provisional duplicates | AUTHOR-MAT-01C creates evidence-scoped provisional Authors and never searches by name | 677 name occurrences have no positional Author ID | One occurrence-scoped provisional Author per valid occurrence | No |
| Same-name isolation | Equal names never merge, promote or select an Author | Names are deliberately non-unique; no name lookup exists in AUTH migration | 66 repeated ID-less name groups cover 210 occurrences; 93 ID-less occurrences equal a stable entity name | Keep identities separate | No |
| Provider claims | Only validated strong provider Author identity may create a claim | MIG-02-AUTH-01 has no claim/network path | No Open Library/Google Author ID; one Wikipedia link is presentation evidence only | Create zero provider claims | No |
| WorkContributor role | Role must be explicitly supplied; order does not imply `co_author` | Closed roles are `author|co_author`; current author arrays and manual Author input use `author` for every row | Source field is semantically `authors`; no role field exists | Every active CURRENT base occurrence uses `author` | No |
| Position | Preserve trustworthy positive source position; never sort or renumber | `ContributorPosition` is positive; uniqueness is per Work+position | Array order exists for all 1,146 base occurrences | One-based original `authors[]` position | No |
| Work dependency | Contributor mapping requires the exact committed CAT Work mapping | MIG-02-AUTH-01 resolves only `catalog_work` mappings | CAT-MAP provides `v1.book/<id>/work` for 1,137 non-quarantined Books | Exact dependency only | No |
| Duplicate ISBN aliases | Both Book identities map to the same real Work without becoming product aliases | CAT-MAP representative/alias mappings are ordinary `catalog_work` mappings | 17 two-Book groups; no competing positive Author identities | Preserve each occurrence; exact edges may converge | No |
| Unsupported/malformed evidence | Valid unsupported truth is preserved; malformed or contradictory identity evidence is quarantined | MIG-FND has `preserved_deferred` and `quarantined` | Four valid-but-not-active and three malformed/contradictory base occurrences | Explicit terminal accounting | No |
| Non-Work roles | Translator, illustrator, editor and compiler are not inferred as Work Authors | Current WorkContributor boundary admits only typed Work roles; Edition contributor structure is separate | No base role fields and no typed non-author role structure | Invent nothing; preserve separate evidence | No |

Binding authority comes from docs 86–90, 98–100 and 107, the current-state,
functional-design, architecture and acceptance documents, ADR-010, ADR-012
and ADR-014, and the closed CAT-MAP contract in docs 115 and 117. The older
MIG-01 mapping document is historical evidence only and does not authorize
name identity or first=`author`/later=`co_author` inference.

Verified current baseline:

- branch `main`;
- HEAD `d2195375ca15e514680d50e1724565c911d093cc`;
- product `v2.001`;
- schema `1026`;
- Biblio Core `2.39.0`; and
- Biblio UI `0.20.0`.

## 2. CURRENT source evidence

The sole authoritative snapshot is:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

The only source data read for this decision was the existing read-only
extraction:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

| Provenance fact | Exact value |
|---|---|
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Files / bytes | 3,587 / 19,801,196 |
| Adapter | `current-v1-json-29` |
| Source family | `biblio-v1` |
| Source version | `books-29.authors-2.reading-goals-2` |

Direct terminal access to the designated archive remains denied by the macOS
permission boundary recorded by SOURCE-01. The approved untouched Finder-copy
anchor in the ignored intake root was re-hashed read-only and matched the ZIP
hash above. The extracted manifest was independently recomputed from path,
byte size and per-file SHA-256 and matched exactly; the existing profile
artifact checksum also passed. No re-extraction or source mutation occurred.

The Author-relevant source shape is:

- `authors.json` schema 2: `authors[]` contains stable `id`, required
  `displayName` and additional source metadata;
- `books.json` schema 29: every Book has ordered string list `authors[]`;
- `authorIds` is either absent or an array of stable Author IDs;
- `authorsLocked` is missing on 853 Books, false on 285 and true on one; it is
  neither person identity, role nor mapping authority; and
- no base Author occurrence object, occurrence ID or role field exists.

The 22 `containedWorks[]` objects form another lane. Their singular `author`
field is non-empty 17 times and empty five times; it has no stable Author ID or
role field and is not part of the 1,146 base occurrences below.

## 3. Stable Author identities

For every valid person-like `authors.json.authors[]` row:

```text
one stable V1 Author ID -> one canonical provisional/observed Author
```

The mapper must emit one `catalog_author` plan using the source entity's
display name. It supplies no `approvedExistingAuthorId`: current V1 contains no
accepted evidence that selects an already-existing V2 Author. Exact replay may
reuse only the exact committed MIG-FND mapping. A source Author reused by many
Books therefore remains one canonical Author and gains separate source-credit
occurrences and WorkContributor edges.

This is source continuity, not global person resolution. The resulting Author
stays `provisional`; no provider claim, name lookup, Search result, normalized
name, Work context or occurrence order may promote it to `resolved`.

Of 257 source Author entities, 256 satisfy the active V2 person-Author
contract. One stable source entity is explicitly a corporate/publisher form.
That is valid bibliographic source evidence but not a V2 person identity, so
the entity and its one base occurrence are `preserved_deferred`. The mapper
must not coerce that source body into a person or silently discard it.

All 14 valid unreferenced source Authors still receive their own active
`catalog_author` mapping. Lack of a CURRENT Work occurrence is not evidence
that the stable source identity is disposable.

If a future snapshot contains different stable V1 Author IDs with an equal
display name, each remains a distinct Author. Current absence of duplicate
names does not weaken that rule.

## 4. Author display names

All 257 Author entity names are strings, non-empty after current V2
normalization, valid UTF-8, between 3 and 42 Unicode code points and below the
512-character bound. None changes under the current trim/collapse rule. There
are zero exact or whitespace-normalized duplicate entity-name groups.

The active rule is exactly
`AuthorContributorCreditKey::normalizeObservedName()`: reject NUL/invalid
UTF-8/empty/over-512 input, trim leading/trailing whitespace and collapse
Unicode whitespace/separators to one space. Case, accents, punctuation,
initials, particles, suffixes, parentheses, digits and hyphens remain
unchanged. There is no person-name grammar, surname inversion,
transliteration, case-folding or punctuation stripping.

Only source `displayName` seeds the canonical V2 `display_name`. V1
`givenName`, `familyName`, `sortName`, `namePartsLocked`, aliases, biography,
photo, language, timestamps, links and provenance neither rewrite the display
name nor establish identity. They remain linked source evidence. The one
Wikipedia link and its provenance do not constitute a provider Author claim.

All 1,146 base occurrence names are strings, non-empty, 3–47 Unicode code
points and below the V2 bound. Three contain whitespace that the current V2
validator collapses. That canonical validation is the only allowed
normalization; the immutable source remains the exact raw evidence.

For the 469 ID-bearing slots, 467 occurrence names exactly equal the source
Author entity display name and two differ only in case. In those two cases the
entity display name seeds the canonical Author, while the occurrence spelling
is retained as the contributor credit/evidence name. No second Author is
created and neither spelling overwrites the other.

## 5. Contributor occurrence shapes

The CURRENT base shape is two parallel ordered scalar arrays, not an array of
typed contributor objects.

| Book shape | Books | Name occurrences | Author-ID occurrences | Mapping meaning |
|---|---:|---:|---:|---|
| No contributor values | 164 | 0 | 0 | No Author plan or edge |
| Only stable-ID contributors | 427 | 452 | 452 | Exact stable Author dependency per position |
| Only ID-less contributors | 531 | 658 | 0 | Occurrence-scoped provisional Author per valid slot |
| Mixed stable-ID prefix + ID-less tail | 17 | 36 | 17 | Exact IDs for prefix; occurrence-scoped Authors for tail |
| **Total** | **1,139** | **1,146** | **469** | Every occurrence accounted |

The exact count-pair distribution is:

| `authors` count | `authorIds` state/count | Books |
|---:|---:|---:|
| 0 | absent | 109 |
| 0 | 0 | 55 |
| 1 | absent | 378 |
| 2 | absent | 58 |
| 3 | absent | 9 |
| 4 | absent | 4 |
| 5 | absent | 3 |
| 1 | 0 | 68 |
| 2 | 0 | 7 |
| 3 | 0 | 2 |
| 4 | 0 | 2 |
| 1 | 1 | 410 |
| 2 | 2 | 12 |
| 3 | 3 | 2 |
| 4 | 4 | 3 |
| 2 | 1 | 11 |
| 3 | 1 | 3 |
| 4 | 1 | 2 |
| 7 | 1 | 1 |

There are no IDs without a name slot, no more-IDs-than-names case, no null
array member, no duplicate Author ID inside one Book and no missing Author
entity reference.

Five source strings require explicit non-routine treatment rather than a
person-name grammar: one placeholder, one semicolon-concatenated multi-name
scalar, one malformed ampersand-concatenated scalar and two corporate or
collective forms. The first three are quarantined. The two valid collective
forms are preserved deferred because current V2 Author identity is a person
and has no entity-kind discriminator. Exact names are deliberately not copied
into this document.

## 6. ID/name alignment

The 548 count mismatches consist only of:

- 531 Books with names and no IDs; and
- 17 Books with one Author ID followed by one or more additional names.

All 469 ID slots have a name at the same position. Every ID resolves. At the
same array position, 467 names exactly equal the referenced Author entity name
and the remaining two are case-only variants. There are zero shifted IDs,
zero holes and zero cases in which an ID array is longer than the name array.

For this exact manifest, that complete positional agreement establishes the
reviewed rule:

```text
authorIds[i] identifies authors[i]
```

`authorIds` is therefore an aligned prefix of `authors`, never an unordered
set and never a name-search hint. Names after that prefix are ID-less. This
rule is bound to adapter `current-v1-json-29` and the exact manifest above. A
future snapshot with a missing slot, shifted/mismatched entity name, invalid
ID or longer ID array must fail closed for reviewed mapping; it may not reuse
this population result by assumption.

The two case-only variants follow §4. No name is used to discover or verify a
different Author. The alignment decision uses the complete exact positional
structure, not equality as a person-identity algorithm.

## 7. ID-less contributors

The selected contract is one occurrence-scoped provisional Author for each
valid ID-less base occurrence whose exact CAT Work mapping is available. This
inherits the already-accepted evidence-scoped name-only Author model; it does
not introduce global identity from source text.

The identity inputs are:

```text
source Book ID
+ original one-based authors[] position
+ exact V2-whitespace-normalized observed name
```

The normalized name is included through a namespaced SHA-256 component in the
derived source ID. The hash is only a bounded deterministic representation of
the occurrence; it is not a person identifier and cannot be used across
occurrences. The typed plan still carries the validated observed display name.

Consequences:

- exact replay of that occurrence reuses its source mapping and Author;
- equal names on another Book or position create separate provisional Authors;
- an ID-less name equal to a stable Author entity name does not attach to it;
- no provider claim or network lookup is allowed;
- no `possible_duplicate` review reason arises from name equality alone; and
- later Librarian reconciliation may explicitly merge or promote only under a
  separate governed contract.

The CURRENT evidence demonstrates why this isolation is necessary: 66 exact
ID-less name groups cover 210 occurrences, and 93 ID-less occurrences across
40 names equal a stable Author entity display name. Three ID-less occurrences
also repeat one exact name within the same Book. None of those equalities
proves person sameness.

There are 677 ID-less base occurrences. Of these, 672 valid person-like
occurrences have a mapped CAT Work and receive occurrence-scoped Author plus
contributor plans. One valid collective form and one occurrence beneath an
invalid/quarantined CAT Book are preserved deferred. Three malformed or
contradictory person-identity scalars are quarantined.

This creates temporary Search duplicates by design. False person merges are
harder to repair and would corrupt every linked Work; provisional duplicates
remain traceable and reconcilable.

## 8. Roles

CURRENT supplies no explicit role field and therefore supplies no
`co_author`, translator, illustrator, editor or compiler distinction. It does,
however, place these base values in the source field explicitly named
`authors` and relates aligned IDs to source Author entities.

Existing V2 canon already closes this case: when an ordered source array
semantically asserts authorship but no primary/co-author distinction, every
valid entry uses:

```text
ContributorRole::Author
```

Position expresses order only. Position 1 is not “primary author”, and
positions 2+ are not converted to `co_author`. The manual Add Book rule and
provider-backed author-array rule agree, but the authority here is the same
canonical Work Author semantics applied to the exact CURRENT `authors` field.

No non-author role is invented. Any such source evidence found in another
field stays Edition/source evidence and outside this mapper. The two collective
forms remain preserved because the current Author target lacks entity kind,
not because a different role is guessed.

## 9. Ordering

Every active base occurrence uses its original one-based `authors[]` array
position as `ContributorPosition`. The mapper must not sort, compact or
renumber. If an earlier occurrence is preserved or quarantined, a later active
occurrence keeps its original higher position; gaps are truthful and valid.

The `authorIds` prefix uses the same position. Source array order is available
for all 1,146 base occurrences. `authorsLocked` does not change order
authority. WorkContributor uniqueness on Work+position and Work+Author remains
the fail-closed target boundary.

## 10. CAT Work dependency

Every active contributor plan depends only on:

```text
catalog_work : v1.book/<source-book-id>/work
```

The mapper must not look up Work by title, ISBN, Edition, Author, Series,
`titleGroupKey`, provider identity or fuzzy similarity. The dependency must
resolve through the exact committed CAT mapping in the same source family,
target user and target Library context.

The same Book-derived Work source identity is used whether CAT created a new
Work, mapped an unknown-ISBN Book or reused a repeated-ISBN representative.
Contributor mapping neither re-evaluates ISBN nor embeds a target Work ID in
its source identity.

A Work may exist without an active Author edge. Missing or unsafe Author
evidence does not block Work/Edition/Item migration; it is explicit
bibliographic incompleteness with preserved/quarantined evidence, never a cue
to invent `Unknown Author`.

## 11. Alias Book handling

CAT-MAP has 17 repeated-ISBN groups of two source Books. Both Book identities
retain their own `catalog_work` mappings to the same canonical Work.

CURRENT Author evidence across those 34 Books is:

- 24 base contributor occurrences, all with valid stable Author IDs;
- zero ID-less occurrences;
- four pairs with no contributor occurrence on either Book;
- eleven pairs with the exact same single Author ID and occurrence name at
  position 1; and
- two pairs where exactly one member has one stable-ID occurrence and the
  other has no positive Author evidence.

There is no group with competing positive Author identities, roles or
positions. Empty author arrays are absence of evidence, not evidence that the
Work has no Author.

All 24 occurrences therefore remain separately planned and evidenced. In the
eleven exact pairs, each source occurrence gets its own migration credit and
mapping while the second occurrence safely reuses the exact same
WorkContributor edge. In the two one-sided pairs, the one positive occurrence
creates the edge. No source Book becomes contributor authority merely because
CAT selected it as a technical ISBN representative.

The future mapper must nevertheless fail closed on a changed manifest that
contains competing positive alias contributor sets. It may map exact common
stable-ID/role/position edges, but must preserve the conflicting remainder as
one reviewed alias-group conflict. It must not choose the bytewise CAT
representative as metadata winner, union different positive sets, overwrite an
occupied position or merge ID-less names.

## 12. Invalid/quarantined Work handling

CAT quarantines two invalid-only ISBN Books and intentionally creates no Work
or Edition mapping for them. Together they have two base contributor
occurrences: one with a valid stable Author ID and one ID-less.

The stable Author entity still maps independently because its source identity
is valid and may be used elsewhere. Neither contributor occurrence can create
a dangling WorkContributor edge. Both occurrence records are
`preserved_deferred` with their exact Book/position/Author-reference evidence.
The ID-less occurrence creates no orphan canonical Author because the accepted
name-only lifecycle creates an Author only for a valid Work credit.

If CAT later resolves the Books through an explicit reviewed mapping, a later
authorized run may promote those exact preserved occurrence identities. This
design neither repairs the ISBN nor pre-approves that promotion.

Contained-work Author evidence remains a separate lane: 17 non-empty singular
author strings across 22 contained occurrences are preserved deferred and are
not folded into the parent/base WorkContributor graph. Their eventual Work
dependency must come from the separate containment mapping.

## 13. Source identity namespaces

The mapper derives only deterministic source identities. It uses no random ID,
target ID, display name alone, ISBN or canonical Author ID.

Let `normalized_name` be the exact output of current V2 whitespace
normalization and:

```text
name_hash = SHA-256(
  "current-v1-author-occurrence-name-v1" NUL normalized_name
)
```

The exact namespaces are:

| Meaning | Source type | Source ID |
|---|---|---|
| Stable V1 Author entity | `catalog_author` | `v1.author/<author-id>` |
| Base contributor occurrence | `catalog_work_contributor` | `v1.book/<book-id>/author-occurrence/<one-based-position>` |
| ID-less occurrence-scoped Author | `catalog_author` | `v1.book/<book-id>/author-occurrence/<one-based-position>/name/<name-hash>/author` |
| CAT Work dependency | `catalog_work` | `v1.book/<book-id>/work` |

For an ID-bearing occurrence, `author_source_id` is
`v1.author/<author-id>`. For an ID-less occurrence it is the occurrence-scoped
Author ID above. The contributor record's canonical payload binds the exact
Author reference, Work reference, role, position and normalized occurrence
name; changed evidence under the same occurrence slot therefore cannot replay
silently.

Current raw IDs make every derived ID fit the existing 191-character bound.
The implementation must construct and validate these namespaces centrally and
fail closed if a future source ID no longer fits.

## 14. Typed AUTH mapping contract

No participant change is required. The mapper produces the existing types.

### Stable source Author

```text
MigrationSourceRecord
  source_type = catalog_author
  source_id   = v1.author/<author-id>
  typed plan  = CatalogAuthorPlan(
    displayName = validated source entity displayName,
    approvedExistingAuthorId = null
  )
```

### ID-less occurrence-scoped Author

```text
MigrationSourceRecord
  source_type = catalog_author
  source_id   = occurrence-scoped Author identity from §13
  typed plan  = CatalogAuthorPlan(
    displayName = validated occurrence name,
    approvedExistingAuthorId = null
  )
```

### Active contributor occurrence

```text
MigrationSourceRecord
  source_type = catalog_work_contributor
  source_id   = base occurrence identity from §13
  references  = catalog_author:<author_source_id>
                catalog_work:<work_source_id>
  typed plan  = CatalogWorkContributorPlan(
    authorSourceId     = exact source Author reference,
    workSourceId       = v1.book/<book-id>/work,
    role               = ContributorRole::Author,
    position           = original one-based authors[] position,
    observedDisplayName = validated occurrence name
  )
```

`CatalogAuthorPlan` creates only provisional/observed identity.
`CatalogWorkContributorPlan` resolves only committed mappings and the existing
`AuthorMigrationWriter` creates truthful migration credit/evidence plus the
ordered edge inside `CommitMigrationRecordService`. No provider claim, HTTP
client, target lookup heuristic or raw provider payload enters the contract.

Exact replay reuses the mapped Author, credit and edge. Changed payload,
missing or foreign dependency, unexpected target type, occupied position,
same Author at another position, divergent credit/evidence or missing edge
fails closed under the existing AUTH participant.

## 15. Preservation / quarantine

The mapper must emit explicit privacy-safe findings/terminal accounting in
addition to active typed plans.

| Source evidence | Disposition | Required behavior |
|---|---|---|
| Extended Author metadata not represented by active V2 Author | `preserved_deferred` | Keep linked to stable source Author evidence; do not create claims or overwrite display name |
| Valid corporate/collective source Author entity | `preserved_deferred` | Preserve entity and occurrence; create no V2 person Author |
| Valid corporate/collective ID-less occurrence | `preserved_deferred` | Preserve occurrence; create no person identity |
| Contributor under quarantined CAT Book | `preserved_deferred` | Preserve exact Book/position/Author reference; create no edge or orphan Author |
| Contained-work `author` text | `preserved_deferred` | Keep in separate contained-work lane |
| Explicit placeholder instead of an Author | `quarantined` | No Author/edge; reason `invalid_author_placeholder` |
| Concatenated multiple-person scalar | `quarantined` | No automatic split, ordering or identity; reason `compound_author_scalar` |
| Malformed contradictory Author scalar | `quarantined` | No repair or normalization beyond the safe validator; reason `malformed_author_scalar` |
| Future invalid/missing/misaligned Author ID | `quarantined` | No positional guess or name fallback |
| Future valid but unsupported entity kind | `preserved_deferred` | Preserve without coercing to person |

No contributor occurrence is intentionally dropped. Author names are
bibliographic, but committed artifacts and docs use counts, reason codes,
source identities/hashes and structural examples rather than catalog dumps.
Source Author timestamps and auxiliary metadata remain source evidence; they
do not backdate or confirm V2 identity.

## 16. CURRENT counts

### Author entities

| Measure | Count |
|---|---:|
| Stable Author entities | 257 |
| Valid active person-Author plans | 256 |
| Stable IDs referenced by base Books | 243 |
| Valid referenced active IDs | 242 |
| Valid active but unreferenced Author entities | 14 |
| Stable entity preserved for unsupported corporate/collective kind | 1 |
| Duplicate Author IDs | 0 |
| Exact/normalized duplicate entity-name groups | 0 / 0 |
| Stable IDs reused across multiple Books | 65 |
| Maximum occurrence count for one stable ID | 52 |

### Base Books and occurrences

| Measure | Count |
|---|---:|
| Books | 1,139 |
| Books with only stable-ID contributors | 427 |
| Books with only ID-less contributors | 531 |
| Books with mixed stable-ID and ID-less contributors | 17 |
| Books with no contributor occurrence | 164 |
| Base name occurrences | 1,146 |
| Occurrences with valid aligned Author ID | 469 |
| ID-less occurrences | 677 |
| Active contributor occurrence plans | 1,139 |
| `preserved_deferred` base occurrences | 4 |
| Quarantined base occurrences | 3 |
| Active stable-ID occurrences | 467 |
| Active ID-less occurrences | 672 |
| Active stable source Author plans | 256 |
| Active occurrence-scoped Author plans | 672 |
| **Total active `catalog_author` plans** | **928** |
| Explicit source roles | 0 |
| Active role | `author` on all 1,139 active occurrences |
| Ordering availability | 1,146/1,146 base occurrences |

The 1,139 active occurrence plans produce 1,128 distinct current
WorkContributor edges: eleven pairs of repeated-ISBN Book occurrences safely
reuse an exact edge while retaining separate credits/evidence and occurrence
mappings. This is expected convergence, not a dropped occurrence.

### Special dependencies

| Measure | Count |
|---|---:|
| Repeated-ISBN alias groups / Books | 17 / 34 |
| Base occurrences on those Books | 24, all stable-ID |
| Exact equal one-occurrence pairs | 11 |
| One-sided positive-evidence pairs | 2 |
| Empty/empty pairs | 4 |
| Competing positive alias contributor sets | 0 |
| Invalid/quarantined CAT Books | 2 |
| Base occurrences under them | 2: one stable-ID, one ID-less |
| Contained-work occurrences | 22 |
| Non-empty contained-work author strings | 17 |

All counts are recomputed from the designated extraction and exact CAT-MAP
artifact; none is hardcoded from DATA-01, MIG-01 or another export.

## 17. Downstream effects

### Author Search

The 928 active Author mappings become ordinary searchable canonical Authors.
Stable V1 IDs reused across Books produce one Search result with several Works.
ID-less occurrence-scoped Authors may produce equal-name duplicate results;
that is truthful provisional identity and must not be deduplicated by name.
Current Search remains read-only and creates no migration reconciliation.

The dedicated Library Author index/detail module remains deferred. Normal
provisional state alone creates no Librarian-review task.

### Author to Works

Each active edge feeds the existing Author-to-Works read. A stable Author may
return several mapped Works. An occurrence-scoped Author normally returns one
Work. Repeated-ISBN Book occurrences converge on the same canonical Work
without losing separate migration credits.

### Series

No Author name, source Author ID or WorkContributor edge determines Series
identity, membership or completeness. Series remains its own mapping lane.

### Wishlist

Wishlist mappings continue to depend on exact CAT Work/Edition identity, not
Author mapping. Their bibliographic display may later show active Authors, but
Author incompleteness does not block a Wishlist record.

### Reading

Reading truth and ReadingRounds depend on exact Work and optional Item
mappings. They neither select nor merge Authors. Missing/preserved Author
evidence does not block valid reading history.

### Notes

Private Notes remain owner-bound and Work-bound. Author mapping neither reads
nor exposes Note content and is not a Note authorization dependency.

## 18. Product questions

No product decision remains for this exact snapshot.

- Role is inherited from the accepted semantics for arrays explicitly
  asserting authorship: every active occurrence is `author`; order never
  creates `co_author`.
- ID-less identity is inherited from the accepted evidence-scoped name-only
  model: one occurrence-scoped provisional Author, never a global name merge.
- Corporate/collective values do not require coercion or an immediate entity-
  kind redesign; valid unsupported evidence is preserved deferred.
- Malformed/composite values are quarantined without automatic splitting.

A future explicit entity-kind model or reconciliation UI is a separate slice,
not an open requirement for this mapper.

## 19. Required implementation slice

The next bounded slice is:

```text
MIG-02-AUTH-MAP-01 — Current V1 Author mapper
```

It should:

1. add a manifest-bound `CurrentV1AuthorMapper` in the existing post-adapter,
   pre-participant mapping layer;
2. consume only validated `v1.author` and `v1.book` records from adapter
   `current-v1-json-29`;
3. validate the exact positional-prefix rule before producing any AUTH plan;
4. emit the source identities and existing typed plans from §§13–14;
5. use the exact CAT Work source identities, including both alias Books;
6. emit explicit preservation/quarantine findings and reconciliation counts;
7. keep dry-run deterministic, payload-safe and zero-write;
8. add focused mapping, alias, malformed, replay, privacy and reconciliation
   coverage; and
9. make no participant, schema, provider, apply/import, REST or UI change.

No implementation is authorized by this design slice.

## 20. Acceptance criteria

D-MIG-AUTH-MAP-01 is accepted because:

- stable V1 Author identity maps one-to-one without becoming globally
  resolved identity;
- new migration Authors are provisional/observed and zero provider claims are
  invented;
- canonical display names and occurrence spellings remain separate where they
  differ;
- the exact ID/name prefix alignment and all mismatch patterns are quantified;
- all valid ID-less occurrences use deterministic occurrence-scoped identity,
  never name-based global reuse;
- all active roles are truthfully `author`, with no position-derived
  `co_author`;
- original positive positions are preserved without sorting or compaction;
- every active occurrence depends on the exact CAT Work source identity;
- repeated-ISBN alias evidence is retained, exact edges converge and no
  technical representative becomes metadata authority;
- invalid-CAT and contained-work occurrences create no dangling edge;
- source identities are deterministic, replay-safe and contain no target ID;
- existing `CatalogAuthorPlan` and `CatalogWorkContributorPlan` are sufficient;
- all 257 source Author entities and all 1,146 base occurrences are mapped,
  preserved or quarantined with no unexplained drop;
- downstream Search/Author-to-Works duplicates are accepted as safer than
  false person merges;
- the authoritative source/hash/manifest boundary is unchanged; and
- no product question remains.

Verdict: **DESIGN GO**.

## 21. Current V1 data rule

Use only the designated ZIP and validated read-only extraction listed in §2.
Do not re-extract, modify the source, substitute DATA-01/MIG-01/another
`/data/`, write artifacts into the source root or make a network lookup. A
changed adapter, manifest, source shape or byte set requires a fresh reviewed
mapping decision; this document does not float to another export.

## 22. Schema/Core/UI impact

This is docs/design only:

- schema remains `1026`;
- Biblio Core remains `2.39.0`;
- Biblio UI remains `0.20.0`;
- no source, product or MIG-FND row is written;
- no migration apply/import is run; and
- no Search, REST or UI behavior changes.

## 23. Git

The design started from clean local `main` at
`d2195375ca15e514680d50e1724565c911d093cc`, 55 commits ahead of
`origin/main`. Closure requires exactly one local docs commit with message
`docs: define current V1 Author mapping`. No push is authorized.
