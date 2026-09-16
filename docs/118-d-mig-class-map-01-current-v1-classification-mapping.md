# D-MIG-CLASS-MAP-01 — Current V1 classification mapping contract

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-16

Task severity: **High**

Scope: product, taxonomy and migration-mapping design for the explicitly
designated CURRENT V1 classification evidence. This document adds no schema,
Core, UI, source-adapter, CAT participant or migration write. It does not
authorize a production classification mapper or an apply/import command.

## 1. Decision inheritance

V1 is source evidence, not product authority. Accepted V2 canon, current Git
and closed V2 decisions retain precedence. CURRENT labels, V1 migration flags,
alias rules and provider review queues cannot create or redefine V2 taxonomy.

| Classification concept | Existing V2 decision | Layer / owner | Current implementation | CURRENT representation | Mapping status | Open question? |
|---|---|---|---|---|---|---|
| Book Type | Broad practical kind; exactly one per existing context; no fallback | Library-owned `LibraryCatalogContext` at Library + Work | Nine seeded Book Types plus possible Library-local terms | One required `bookType` string per Book; seven active definitions | Five term mappings are exact; `Jeugdboek` and `Kinderboek` have explicit reviewed mappings to `Leesboek`; source review flags remain binding | No |
| Genre | Optional unordered set; literary/narrative genre, not type, audience or topic | Library-owned Library + Work context | Twelve seeded Genres plus possible Library-local terms | `genres[]`; 44 definitions mixing genre, form, topic and audience | Three exact target mappings; all other facts preserved deferred | No new V2 rule |
| Subject / topic | Optional unordered content topics; no default seedset | Library-owned Library + Work context | Typed Subject IDs and repository exist; default seedset empty | No Subject collection; several raw “genres” are topic-like | No source label is promoted to Subject | No |
| Generic category | V2 has no generic `Categorie` dimension | None | No generic category target | `categories[]`; 14 definitions; Genre definitions point to one category bucket | Preserve deferred; never bulk-map to Genre or Subject | No |
| Library-local classification | One context per Library + Work; never Edition or Item | Exact target Library | Composite Library FKs and typed IDs enforce isolation | Source has no Library ID | Bind only through explicit migration target Library | No |
| Definition identity | Target identity is a Library-local typed ID; seed key is durable lookup evidence | Exact target Library | Seed evolution may adopt a local term while retaining its ID | Definitions have no IDs; exact raw names only | Source identity is taxonomy kind + exact raw value; no normalization identity | No |
| Assignment ownership | All Editions/Items of a Work in one Library share one context | Library + Work | `LibraryCatalogSelection` is exact Book Type ID plus Genre/Subject ID sets | Embedded on top-level V1 Book; no assignment ID | Join only through CAT stable Book→Work identity | No |
| Review state | Unknown proposals are not active automatically | Separate reviewed mapping/governance boundary | No CURRENT taxonomy-review migration target | Per-Book migration flags and 446 provider queue entries | Review flags block automatic activation; queue is preserved deferred | No |
| Unknown / unmapped term | No `Anders`, `Overig` or `Onbekend` fallback | Mapping boundary | CAT rejects missing/foreign typed targets | Zero undefined current assignments | Preserve valid unsupported semantics; quarantine malformed/contradictory evidence | No |
| Replay / order | Exact replay only; changed selection is divergent replay; Genre/Subject are sets | MIG-FND + CAT | Canonical plan payload contains exact target IDs | Arrays are ordered but have no proven assignment order | Sort deterministic artifact rows/IDs; never choose a conflict by source order | No |

Relevant authority is ADR-006, the classification sections of documents 00, 01,
02 and 06, and the source-neutral CAT contract in documents 106, 115 and 117.

## 2. CURRENT source evidence

Only this already validated read-only extraction was inspected:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

| Provenance | Verified value |
|---|---|
| Authoritative ZIP | `/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip` |
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Books / Copies | 1,139 / 1,106 |
| Category / Genre assignments | 1,264 / 1,041 = 2,305 |
| Taxonomy review queue | 446 |

The request's “68 category definitions” does not match the source structure.
The reproducible split is seven Book Type definitions, 14 Category definitions
and 44 Genre definitions: **65 classification definitions**. The number 68 is
reached only by adding the three Carrier definitions. Carrier is a separate
source vocabulary and not a V2 Library classification dimension.

Every Book has one non-empty defined Book Type. There are zero undefined
Category or Genre assignments and zero duplicate values within a Book's
Category or Genre array. All 2,305 assignments resolve to active CURRENT
definitions. A Book may have zero Categories (108 Books) or zero Genres (694
Books); all 108 Books without a Category also have no Genre.

CURRENT does not contain a general taxonomy hierarchy. Each Genre definition
does contain one explicit Category bucket and an `allowedBookTypes` list. Every
used Genre occurs with its declared Category on the Book and all 1,041 Genre
assignments satisfy the CURRENT allowed-Book-Type constraint. This proves a
source-local two-layer grouping/validation rule, not a V2 parent/child taxonomy;
V2 Genre and Subject remain flat.

Definitions have unique `sortOrder` values, but assignments have no rank or
position field. Array order also disagrees with definition order for 81 Books'
Categories and 73 Books' Genres. Assignment order therefore has no proven
semantic meaning.

### Source review provenance

All 1,139 Books have a current Book Type value, but only 726 contain
`taxonomyMeta.bookType`. Its nested V401 migration status is:

| Source status | Books | Meaning for this design |
|---|---:|---|
| `migrate` | 371 | Current term may continue through the semantic target mapping |
| `review` | 38 | Explicit human review is still required |
| `no_signal` | 317 | No active target assignment may be inferred from the fallback value |
| no Book-Type migration metadata | 413 | Use the persisted current value, subject to the term mapping |

The 355 `review`/`no_signal` records remain review-required even when their raw
label has an exact target term. They must not be silently activated as V2
classification. The metadata is source provenance, not permission to choose a
target.

## 3. V2 classification canon

Actual accepted Git contains **nine**, not ten, standard Book Types:

| V2 Book Type | Stable seed key |
|---|---|
| Leesboek | `book_type.reading_book` |
| Kookboek | `book_type.cookbook` |
| Studieboek | `book_type.study_book` |
| Kennisboek | `book_type.knowledge_book` |
| Stripboek | `book_type.comic_book` |
| Prentenboek | `book_type.picture_book` |
| Reisgids | `book_type.travel_guide` |
| Woordenboek | `book_type.dictionary` |
| Fotoboek | `book_type.photo_book` |

`Naslagwerk` is not an accepted standard seed. Its appearance in integration
fixtures demonstrates that a Library can own an additional local term; it does
not make that term V2 canon. This design neither adds nor renames a Book Type.

The standard Genre canon is Avontuur, Fantasy, Sciencefiction, Thriller,
Detective / Mystery, Horror, Romance, Historisch, Literatuur, Humor / Satire,
Dystopie and Magisch realisme. Subject has no standard seedset.

Seed keys are durable semantic lookup evidence, but the target identity remains
the exact Library-local term ID. Seed evolution may adopt an existing local
term and preserve its ID. A future mapper must therefore resolve the approved
seed key inside the explicit target Library during reviewed planning and freeze
the resolved exact ID into the plan. Apply must never resolve by display name.

## 4. Book Type mapping

The first five rows have both exact dimension/name evidence and compatible
source/V2 semantic contracts. Exact spelling alone is not the decision.

| CURRENT value | Books | Copies | Disposition | Approved target locator | Reason |
|---|---:|---:|---|---|---|
| Leesboek | 713 | 687 | `EXACT_TARGET` at term level | `book_type.reading_book` | Both mean the broad general reading/recreational type |
| Kennisboek | 249 | 245 | `EXACT_TARGET` at term level | `book_type.knowledge_book` | Both mean informative/explanatory knowledge books |
| Kookboek | 63 | 62 | `EXACT_TARGET` at term level | `book_type.cookbook` | Both mean recipe/cooking books |
| Stripboek | 25 | 24 | `EXACT_TARGET` at term level | `book_type.comic_book` | Both mean comics, graphic novels, manga and related image narratives |
| Studieboek | 15 | 15 | `EXACT_TARGET` at term level | `book_type.study_book` | Both mean study/education/structured-learning books |
| Jeugdboek | 50 | 49 | `REVIEW_MAPPING` — approved | `book_type.reading_book` | Renée explicitly approved `Jeugdboek → Leesboek` for this complete CURRENT population on 2026-09-16; this is not inferred semantic identity |
| Kinderboek | 24 | 24 | `REVIEW_MAPPING` — approved | `book_type.reading_book` | Renée explicitly approved `Kinderboek → Leesboek` for this complete CURRENT population on 2026-09-16 because V1 used the value broadly for children's books, not specifically picture books |

No row maps to `Anders`, `Overig`, `Onbekend`, a Genre or an inferred target.
The raw `Jeugdboek` and `Kinderboek` values remain migration evidence, no new V2
term is created for either value, and none of these 74 records needs a per-Book
classification review. `Kinderboek` is explicitly not mapped to `Prentenboek`.
Both mappings are confined to this reviewed CURRENT mapping matrix.
At assignment level, term mapping and source review state are separate:

| Assignment state | Books | Copies |
|---|---:|---:|
| Exact term and no source `review`/`no_signal` flag | 710 | 684 |
| Explicit approved `Jeugdboek → Leesboek` and `Kinderboek → Leesboek` reviewed mappings; no per-Book review | 74 | 73 |
| Exact term but source `review`/`no_signal` flag | 355 | 349 |

Two upstream invalid-only ISBN Books and their Copies never reach CAT Item
planning: one `Leesboek` with source status `migrate`, and one `Jeugdboek`
without Book-Type migration metadata. The CAT-eligible assignment populations
are consequently 709 exact, 73 approved-reviewed and 355 source-review Books;
their Copy populations are 683, 72 and 349.

## 5. Category semantics

CURRENT Category is a mixed, broad grouping layer: narrative nature, content,
function, audience, form and a catch-all occur side by side. V2 has no generic
Category dimension. None may be turned into a V2 Genre or Subject merely to
complete import.

| CURRENT Category definition | Source status | Disposition |
|---|---|---|
| Fictie | active | `PRESERVE_DEFERRED` |
| Non-fictie | active | `PRESERVE_DEFERRED` |
| Educatie | active | `PRESERVE_DEFERRED` |
| Kind & Jeugd | active | `PRESERVE_DEFERRED` |
| Beeldverhaal | active | `PRESERVE_DEFERRED` |
| Koken & Voeding | active | `PRESERVE_DEFERRED` |
| Naslag & Kennis | active | `PRESERVE_DEFERRED` |
| Hobby, Sport & Creatief | active | `PRESERVE_DEFERRED` |
| Reizen & Cultuur | active | `PRESERVE_DEFERRED` |
| Overig | active | `PRESERVE_DEFERRED`; never a V2 fallback |
| E-books | deprecated | `PRESERVE_DEFERRED`; carrier/category legacy evidence |
| Studieboeken | deprecated | `PRESERVE_DEFERRED` |
| Jeugd & Jongeren | deprecated | `PRESERVE_DEFERRED` |
| Non-Fictie | alias | `PRESERVE_DEFERRED`; alias metadata retained, not identity |

All 1,264 current Category assignments are valid source facts and remain
`PRESERVE_DEFERRED`. They are not silently dropped.

## 6. Genre / Subject boundary

The source's Genre vocabulary is semantically mixed. It contains literary
genres, form, biography/memoir, disciplines/topics, practical subjects,
audiences and image traditions. The filename and field name do not prove that
every value is V2 Genre. No value is promoted to V2 Subject without a reviewed
Subject target; none exists in this snapshot.

Only `Fantasy`, `Sciencefiction` and `Thriller` have exact labels and compatible
literary-genre meaning in the accepted V2 seedset. No singular/plural,
punctuation, capitalization, translation or synonym rule establishes another
identity.

## 7. Genre definitions

| CURRENT Genre definition | Source status | Disposition / target |
|---|---|---|
| Fantasy | active | `EXACT_TARGET` → `genre.fantasy` |
| Sciencefiction | active | `EXACT_TARGET` → `genre.science_fiction` |
| Historische fictie | active | `PRESERVE_DEFERRED` |
| Literaire fictie | active | `PRESERVE_DEFERRED` |
| Poëzie | active | `PRESERVE_DEFERRED` |
| Romantiek | active | `PRESERVE_DEFERRED`; no automatic translation to Romance |
| Thriller | active | `EXACT_TARGET` → `genre.thriller` |
| Misdaad | active | `PRESERVE_DEFERRED`; not silently Detective / Mystery |
| Biografie | active | `PRESERVE_DEFERRED` |
| Memoires | active | `PRESERVE_DEFERRED` |
| Geschiedenis | active | `PRESERVE_DEFERRED` |
| Politiek | active | `PRESERVE_DEFERRED` |
| Psychologie | active | `PRESERVE_DEFERRED` |
| Filosofie | active | `PRESERVE_DEFERRED` |
| Mens & maatschappij | active | `PRESERVE_DEFERRED` |
| Recepten | active | `PRESERVE_DEFERRED` |
| Bakken | active | `PRESERVE_DEFERRED` |
| Kunst & cultuur | active | `PRESERVE_DEFERRED` |
| Reizen | active | `PRESERVE_DEFERRED` |
| Hobby & creatief | active | `PRESERVE_DEFERRED` |
| Sport | active | `PRESERVE_DEFERRED` |
| Baby & peuter | active | `PRESERVE_DEFERRED` |
| Prentenboek | active | `PRESERVE_DEFERRED`; V2 value is a Book Type, not Genre |
| AVI / eerste lezers | active | `PRESERVE_DEFERRED` |
| Jeugdfictie | active | `PRESERVE_DEFERRED` |
| Young adult | active | `PRESERVE_DEFERRED` |
| Graphic novel | active | `PRESERVE_DEFERRED` |
| Manga | active | `PRESERVE_DEFERRED` |
| Strips | active | `PRESERVE_DEFERRED` |
| Fantasy & Science Fiction | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Graphic Novels / Anime | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Literatuur / Literaire fictie | alias | `PRESERVE_DEFERRED` |
| Romantiek / Romans | alias | `PRESERVE_DEFERRED` |
| Thrillers & Misdaad | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Biografieën & Memoires | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Geschiedenis & Politiek | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Kookboeken & Eten | deprecated | `PRESERVE_DEFERRED` |
| Kunst & Cultuur | alias | `PRESERVE_DEFERRED`; capitalization is not identity |
| Mens & Maatschappij | alias | `PRESERVE_DEFERRED`; capitalization is not identity |
| Reizen & Avontuur | alias | `PRESERVE_DEFERRED` |
| Sport & Hobby | deprecated | `PRESERVE_DEFERRED`; no compound split |
| Kinderboeken | deprecated | `PRESERVE_DEFERRED` |
| Jeugdboeken | alias | `PRESERVE_DEFERRED` |
| Young Adult | alias | `PRESERVE_DEFERRED`; capitalization is not identity |

The three exact definitions account for 280 assignments: Fantasy 119,
Sciencefiction 115 and Thriller 46. The remaining 761 Genre assignments are
`PRESERVE_DEFERRED`. There are no classification quarantine candidates caused
by a malformed or undefined Category/Genre value.

### Carrier vocabulary excluded from classification totals

`Fysiek boek`, `E-book` and `Luisterboek` are the three definitions that raise
the source-vocabulary total from 65 to 68. All 1,139 CURRENT Books say `Fysiek
boek`, consistent with v2.001 physical-book scope. That is CAT eligibility
evidence, not a `LibraryCatalogSelection` value. The unused digital definitions
remain source evidence outside this mapping.

## 8. Assignments

Assignments remain attached to the exact mapped Work in the explicit target
Library:

```text
v1.book/<book_id>
  → catalog_work:v1.book/<book_id>/work
  → committed exact Work target
  + explicit target_library_id
  → one LibraryCatalogContext(target Library, target Work)
```

There is no title lookup and no attachment to Edition or Item. The 22 contained
Work occurrences inherit nothing from the parent Book because the source gives
them no classification fields.

Genre and Subject target IDs form duplicate-free unordered sets. Source array
order remains in immutable source evidence but is not promoted to V2 meaning.
Preserved Category/Genre facts retain their source Book identity, dimension,
exact raw label, source status and payload/hash provenance.

### Converging CAT identities

Seventeen repeated-ISBN pairs converge to one target Work/Edition. One V2
Library + Work can have only one selection, so both source Books must resolve
to the same exact selection.

- 10 pairs produce the same proposed active selection: one is exact and nine
  remain review-required because at least one Book is `no_signal`;
- four pairs disagree on exact Book Type (`Leesboek` versus `Kennisboek`);
- three additional pairs agree on Book Type but disagree on the exact mapped
  Genre set.

All seven conflicting pairs consist of a `no_signal` representative and a
`migrate` alias and cover 14 Copies. Neither representative selection, alias
selection, union nor source order gets precedence. They remain explicit
`QUARANTINE_CANDIDATE` group findings with reason
`converged_classification_conflict` unless an explicit pre-plan review first
produces one identical selection for both source Books. Apply must fail closed
if a differing context already exists, and changed reviewed IDs are divergent
replay.

## 9. Review queue

The 446 queue entries are provider-generated term-review evidence, not current
Book assignments:

| Property | CURRENT fact |
|---|---:|
| Pending | 443 |
| Ignored | 3 |
| Open Library / Google Books | 431 / 15 |
| Unique raw / normalized terms | 445 / 442 |
| Stored example contexts | 645 (1–5 per entry) |
| Exact overlap with current definitions or assignments | 0 |

The queue mixes marketing labels, forms, subjects, audiences and provider
editorial labels. It has no stable queue ID, Book/Work link or Library ID;
free-text contexts are capped examples rather than full occurrence identity.
`pending` does not mean accepted or invalid. `ignored` proves only a V1 workflow
outcome and is not V2 quarantine.

There is no active V2 target for this workflow. All 446 entries are
`PRESERVE_DEFERRED`. Ordinary artifacts may expose only source/hash identity,
provider, status and aggregate counts; private context strings, titles,
descriptions and timestamps stay out of artifacts, logs, CLI output and errors.

The entries have no stable source IDs and therefore must not be turned into 446
invented classification observations or assignments. They remain accountable
through the adapter-owned ID-less/non-observation strategy: deterministic
package/category hash, reason and aggregate population, with the immutable
source package retaining the detailed evidence.

The seven `taxonomy_aliases.json` rules are V1 external-label automation rules.
They are not reviewed V2 mappings and are also preserved deferred; runtime
mapping must not execute them.

## 10. Ownership

CURRENT carries no Library or user identity for classification. The explicit
validated migration `target_library_id` is the only Library authority. Target
term IDs must belong to that exact Library, and CAT must validate them before a
write. There is no current actor, admin, first-Library, name or cross-Library
fallback.

Equal raw terms in two Libraries are not one platform taxonomy. Seed keys help
resolve a reviewed semantic target inside one Library; they do not replace the
Library-owned term ID or create global identity.

## 11. Missing / unknown behavior

- Missing or blank CURRENT Book Type: **0**.
- Undefined current Category/Genre assignment: **0**.
- Books without Category: **108**; this is allowed because V2 Genre/Subject are
  optional.
- Books without Genre: **694**; this is likewise allowed.
- A future missing Book Type cannot produce a new context or Item plan and gets
  no fallback.
- A future valid but unsupported term becomes `PRESERVE_DEFERRED` or
  `PRODUCT_DECISION_REQUIRED`.
- A malformed or contradictory source value becomes `QUARANTINE_CANDIDATE`;
  unsupported semantics alone are not quarantine.
- Existing pre-F2.5 represented Works may remain context-free, but a new
  migrated Item cannot be created without exactly one valid Book Type.

## 12. Preservation

Every non-active fact remains accountable:

- 14 Category definitions and all 1,264 Category assignments;
- 41 non-exact Genre definitions and 761 assignments;
- source alias/deprecated status and Category/allowed-Book-Type relationships;
- all 446 taxonomy review entries and seven external alias rules;
- source assignment order as evidence only, without target meaning;
- Book-Type `review`/`no_signal` provenance;
- both sides of every converged-Work classification conflict; and
- the approved `Jeugdboek → Leesboek` and `Kinderboek → Leesboek` decisions with
  both raw source values retained as provenance.

Preservation uses stable source identity and safe allowlisted taxonomy metadata.
Quarantine is reserved for malformed or contradictory evidence. No fact is
administratively closed, translated, merged or intentionally dropped.

## 13. Typed mapping contract

The later deterministic reviewed artifact should be versioned and sorted by
`source_dimension` plus the exact UTF-8 raw value. Each decision row contains:

```text
source_family                 = biblio-v1
source_manifest_sha256        = 35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67
adapter_id                    = current-v1-json-29
source_dimension              = book_type | category | genre
source_raw_value              = exact source string
source_definition_status      = active | alias | deprecated
decision_status               = EXACT_TARGET | REVIEW_MAPPING |
                                PRESERVE_DEFERRED | QUARANTINE_CANDIDATE |
                                PRODUCT_DECISION_REQUIRED
target_dimension              = book_type | genre | subject | null
target_seed_key               = reviewed seed locator or null
target_library_id             = explicit migration target
resolved_target_term_id       = exact typed Library-local ID or null
required_target_status        = active_for_new_link | retained_existing_only
rationale_code                = stable allowlisted reason
decision_provenance           = decision/document identifier
```

Term rows alone cannot approve the 355 source `review`/`no_signal` assignments.
The artifact therefore also needs an immutable per-Book review row whenever a
source assignment is not automatically activation-ready:

```text
source_record_type            = v1.book
source_record_id              = exact stable Book ID
source_payload_hash           = exact profiled observation hash
source_book_type_value        = exact raw value
source_book_type_review_state = review | no_signal | product_decision
assignment_decision_status    = REVIEW_MAPPING | EXACT_TARGET |
                                PRESERVE_DEFERRED | QUARANTINE_CANDIDATE
approved_term_decision_key    = exact term-row reference or null
reviewed_target_term_id       = exact typed Library-local ID or null
decision_provenance           = immutable review/decision identifier
```

Absence of the required per-Book approval row keeps that Book unresolved. The
artifact must also bind a converged-Work group decision to every member source
ID and its payload hash; one reviewed member cannot silently approve its peer.
No reviewer name or private source content is required.

Per-Book planning consumes these rows, the stable CAT Work source dependency,
the exact raw assignments and their source review provenance. Resolution is:

```text
CURRENT exact raw term
  → immutable reviewed decision row
  → resolve approved seed key or explicit local ID in target Library
  → freeze exact LibraryBookTypeId / LibraryGenreId / LibrarySubjectId
  → LibraryCatalogSelection
  → CatalogItemPlan
```

There is no fuzzy, translated, normalized-name or runtime synonym lookup. A
missing, duplicate or foreign target fails closed. An inactive target fails for
a new context/link or changed selection, but an already identical existing
LibraryCatalogContext may be reused unchanged with its retained inactive terms.
Mapping files are not automatically edited during dry-run or apply. Apply sees
typed IDs only.

For a Work with an existing context, the complete reviewed selection must be
identical. For multiple source Books converging on one Work, selection
reconciliation happens before any Item plan and never by record order.

## 14. CURRENT mapping counts

### Definition and assignment disposition

| Population | Exact target | Review mapping | Preserve deferred | Product decision | Quarantine |
|---|---:|---:|---:|---:|---:|
| 7 Book Type definitions | 5 | 2 approved | 0 | 0 | 0 |
| 14 Category definitions | 0 | 0 | 14 | 0 | 0 |
| 44 Genre definitions | 3 | 0 | 41 | 0 | 0 |
| 1,139 Book Type assignments, term mapping | 1,065 | 74 approved | 0 | 0 | 0 |
| 1,139 Book Type assignments, activation gate | 710 | 429 total: 74 approved + 355 pending per-Book review | 0 | 0 | 0 |
| 1,264 Category assignments | 0 | 0 | 1,264 | 0 | 0 |
| 1,041 Genre assignments | 280 | 0 | 761 | 0 | 0 |
| 446 review-queue entries | 0 | 0 | 446 | 0 | 0 |
| 17 converged-Work classification reconciliations | 1 compatible group | 9 compatible review groups | 0 | 0 | 7 conflict group candidates |

The three Carrier definitions are outside classification disposition counts.
`Fysiek boek` is exact CAT scope evidence; unused E-book/Luisterboek definitions
remain source evidence outside v2.001 media scope.

### Item-planning effect

Of 1,106 Copies, two depend on upstream invalid-only ISBN Books and are already
quarantined by CAT. For the remaining 1,104 Copy candidates:

| Classification result | Source Books with Copies | Copy Item candidates |
|---|---:|---:|
| Exact or explicitly approved activation-ready selection after converged-Work reconciliation | 745 | 748 |
| Blocked by source `review`/`no_signal` or converged selection conflict | 353 | 356 |
| Total CAT-eligible | 1,098 | 1,104 |

The 356 blocked candidates comprise 349 source-review Copies and seven otherwise
exact alias Copies whose converged Work has a conflicting selection. These sets
are reconciled without double-counting the seven source-review counterpart
Copies. All 24 `Kinderboek` Copies and 48 CAT-eligible `Jeugdboek` Copies are
activation-ready. The 49th `Jeugdboek` Copy belongs to the upstream invalid-only
ISBN Book and remains outside CAT Item planning.

Even the 748 classification-ready candidates do not yet produce Item plans:
the separately required Item-local review dependency remains unresolved for all
1,104 CAT-eligible Copies. No count is a future business-logic constant.

## 15. Product questions

Overall verdict is **DESIGN GO**. Open product questions: **zero**.

Renée explicitly approved both CURRENT-only reviewed mappings on 2026-09-16:

- `Jeugdboek → Leesboek` for all 50 source Books;
- `Kinderboek → Leesboek` for all 24 source Books.

The raw values remain evidence, neither mapping creates a new Book Type, neither
uses `Prentenboek` or a fallback, and neither requires per-Book review.

## 16. Required implementation slice

With this document at DESIGN GO, the separately authorized bounded
implementation slice should be:

`MIG-02-CLASS-MAP-01 — Current V1 classification mapper`

It should:

- read active assignment inputs only from the exact CURRENT Book classification
  fields already retained in `CurrentV1SourceAdapter::records()`;
- add a bounded read-only adapter/inspection auxiliary-evidence projection for
  data that `records()` does not enumerate: definitions by taxonomy kind +
  exact raw label + status, alias rules by exact source rule ID, and the
  ID-less review queue as one file/category population bound to its exact
  package path, SHA-256 and count;
- keep that auxiliary projection out of participant routing and product plans:
  it exists only to validate the reviewed mapping artifact and to emit safe
  preservation/non-observation findings;
- consume an immutable reviewed mapping artifact;
- resolve approved seed keys or explicit IDs only within the validated target
  Library and emit exact typed IDs;
- retain source review flags and fail closed on unresolved assignments;
- reconcile all source Books that CAT converges to one Work before planning;
- attach one `LibraryCatalogSelection` to each eligible CAT Item plan;
- emit per-Book preservation/quarantine findings for embedded assignments,
  per-definition findings for the 65 name-keyed definitions, and deterministic
  aggregate preservation findings for ID-less/auxiliary categories;
- remain deterministic, privacy-safe and zero-write in dry-run; and
- add no taxonomy creation, fuzzy mapping, provider call, schema, REST or UI.

It must not absorb Item-local mapping. CAT Work/Edition planning remains usable
without classification; only Item planning waits.

The auxiliary boundary must not synthesize 446 queue record identities or
re-read an unvalidated source tree. It consumes the same validated package and
profile, verifies exact file identity from the package manifest, and records
the queue population through the existing ID-less non-observation semantics.

## 17. Acceptance criteria

DESIGN GO and later mapper acceptance require all of the following:

- all seven CURRENT Book Type terms have one explicit approved disposition;
- the reviewed `Jeugdboek → Leesboek` and `Kinderboek → Leesboek` mappings remain
  bound to this CURRENT source, retain both raw values as evidence and require no
  per-Book review;
- every source `review`/`no_signal` assignment remains blocked until reviewed;
- no `Anders`, `Overig`, `Onbekend` or other fallback exists;
- no name-only, translation, singular/plural, punctuation, capitalization,
  similarity or synonym identity exists;
- the actual nine-term V2 Book Type canon is used; `Naslagwerk` is not invented;
- Category, Genre and Subject remain distinct, and the CURRENT mixed vocabulary
  is not bulk-promoted;
- all 2,305 Category/Genre assignments and 446 queue entries reconcile to an
  exact target or preservation outcome;
- all target IDs are typed, active for new links and owned by the exact target
  Library;
- assignments attach only to CAT stable Work identity plus target Library;
- converged source Books yield one identical selection or an explicit conflict;
- existing different LibraryCatalogContext selection fails closed;
- exact replay converges, while changed decision rows/target IDs are divergent;
- ordinary artifacts contain no private queue contexts, titles, descriptions,
  Notes, personal values or timestamps;
- dry-run changes no product or MIG-FND rows;
- downstream Book Detail/search see only active mapped V2 classification;
  preserved facts do not masquerade as filter values or statistics;
- Authors and Series remain structurally unaffected; and
- focused tests, privacy/scope scan, reference validation, `git diff --check`
  and independent review pass.

### Team review

- **Product:** mixed or target-audience semantics must not be relabelled merely
  to complete import.
- **Metadata:** Book Type, Genre, Subject, source Category and Carrier remain
  distinct; review provenance is data.
- **UX:** only exact active V2 terms may drive Book Detail and filtering, so
  migrated navigation is not misleading.
- **Engineering:** mapping is immutable, target-Library-bound and typed, with no
  runtime fuzzy logic or representative precedence.
- **Migration:** every assignment and queue entry is accounted for; an Item plan
  exists only after one complete, non-conflicting selection is approved.

### Downstream effect

- CAT Work/Edition planning is unchanged; Item planning consumes the typed
  selection only after classification and Item-local review both close.
- Book Detail shows the target Library + Work assignment, including later
  inactive linked terms, never raw V1 labels.
- Search/filtering exposes only active target options and exact assignments;
  preserved values are not implicit filter hits.
- Future statistics may count only active V2 dimensions, not preservation rows.
- Series and Authors keep their independent source identities and mappings.

### Current V1 data rule

Only the authoritative ZIP identity and the read-only SOURCE-01 extraction named
above may be used. No re-extraction, alternate `/data/`, source mutation or
artifact-in-source write is allowed. DATA-01, MIG-01 and old exports are not
CURRENT truth. Counts and raw taxonomy terms are safe design evidence; private
source content is not committed.

### Schema / Core / UI / Git

Product remains `v2.001`; schema remains `1026`; Biblio Core remains `2.37.0`;
Biblio UI remains `0.20.0`. Baseline HEAD was
`ed36f38dd43bde886cccce82fb6f3cf294a79e9d` on `main`, 51 commits ahead of
`origin/main`, with a clean worktree before this document. This DESIGN GO changes
no code, schema, runtime data or UI and authorizes only the bounded docs/design
commit requested here. It does not start `MIG-02-CLASS-MAP-01`.
