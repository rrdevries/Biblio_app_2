# D-MIG-ITEMLOCAL-MAP-01 — Current V1 Item-local mapping contract

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-16

Task severity: **High**

Scope: product, Item and migration-mapping design only. This document adds no
production mapper, schema, Core behavior, REST, UI, import/apply path or source
mutation. V1 is source evidence, not product authority.

## 1. Decision inheritance

The authority order for this decision is:

1. accepted V2 Item, ExternalLoan and ownership canon;
2. D-ITEM-MIG-01 (`docs/103`) and D-MIG-LOAN-01 (`docs/113`);
3. the closed ITEM-MIG-01A, CAT-MAP and CLASS-MAP implementations;
4. current Git, schema and domain contracts; and
5. the immutable CURRENT V1 source as reviewed mapping evidence.

The following decisions are inherited without reopening them.

| Topic | Binding V2 rule | Current implementation/evidence | Mapping consequence |
|---|---|---|---|
| Work/Edition/Item | Work and Edition are shared bibliography; an Item is one owned physical copy in one exact Library | Functional design; ADR-013; CAT participant | Copy-local facts never alter Work/Edition or a sibling Item |
| External borrowed source | A physical source borrowed from outside Biblio is user-owned, Work-linked ExternalLoan truth and does not become a Library Item | Functional design §§external source; architecture; D-MIG-LOAN-01 | A CURRENT external-borrowed Copy must not produce `CatalogItemPlan` merely because V1 stores it as a Copy |
| Item-local owner | Item-local facts belong to exact Item + exact target Library | Docs 103–104; schema 1025 | No user-global or cross-Library fallback |
| Unknown | Unknown is null, never a false, zero, default, current date or `anders` | Docs 103–104 | Only explicit values activate a field |
| Acquisition | `In bibliotheek sinds`, method, `Verkregen via` and exact paid amount/currency are independent | D-ITEM-MIG-01 | No field infers another |
| Classification | New Item needs one reviewed typed Library classification | ADR-006; docs 118–119 | Item planning requires classification-ready **and** Item-local-ready |
| Circulation | CURRENT circulation is independently preserved/quarantined by MIG-02-CIRC-01 | Docs 113–114 | The Item-local mapper neither creates nor duplicates circulation records |
| Preservation | Unsupported valid source meaning is `preserved_deferred`; malformed/contradictory meaning is quarantined | MIG-FND | Active state and deferred evidence may coexist for one source observation |

### Verified current baseline

- Git branch: `main`.
- HEAD: `6fd0ae167486325b50321957a04af91630b35ae3`.
- Product: `v2.001`.
- Schema: `1026`.
- Biblio Core: `2.38.0`.
- Biblio UI: `0.20.0`.
- CAT-MAP and CLASS-MAP are closed; 1,104 Copies are CAT-eligible and 748 are
  classification activation-ready before this Item-local decision.

## 2. CURRENT source evidence

The only authoritative snapshot is:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

The only source root inspected for this design was the existing read-only
extraction:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

The source contract is:

| Provenance fact | Exact value |
|---|---|
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Version | `books-29.authors-2.reading-goals-2` |
| Source family | `biblio-v1` |

Direct access to the designated archive remains denied by the macOS archive
permission boundary recorded by SOURCE-01. The approved untouched Finder-copy
anchor at `source-intake-20260915/data.zip` was re-hashed read-only and matched
the exact ZIP SHA-256 above. The extraction was not recreated or modified.

### Exact Copy shape

`books.json` has schema version 29, 1,139 Books and 1,106 Copies. Every Copy
contains exactly the following top-level field set:

```text
acquisition, archiveReason, archived, archivedAt, bookId,
circulationRounds, condition, copyNumber, createdAt, disposal,
exemplarPhotos, id, legacyBookNumber, location, notes, ownershipStatus,
platformSource, sourceBookNumber, status, updatedAt
```

Relevant shapes are:

- `acquisition`: null on 1,029 Copies and an object on 77;
- acquisition object keys: `type`, optional `date`, optional `source`; the one
  missing-type object contains only `date`;
- acquisition date: `{value, precision}` with precision exactly `year`,
  `month` or `day`;
- `condition`: present as an empty string on all 1,106 Copies;
- `notes`: present as a string on all Copies and non-empty three times;
- `disposal`: one `{date, reason}` object;
- `exemplarPhotos`: an object, empty 1,105 times and with one `front` slot once;
- `circulationRounds`: a separate circulation structure;
- no Copy field exists for price, currency, signed, signed-by, limitation,
  dust jacket, inscription, provenance or completeness/enclosures.

### Book/Copy duplication

Acquisition is present on 68 Books and 77 Copies. For all 68 Book occurrences,
the linked Copy acquisition object is exactly equal. The remaining nine
acquisition objects occur only on the Copy. There are:

- zero Book-only acquisition objects for a linked Copy;
- zero differing Book/Copy acquisition objects; and
- no reason to read Book acquisition as fallback or precedence.

The Item-local mapper therefore reads only the concrete Copy acquisition
object. Equal Book-level data remains source evidence and is not written a
second time.

CURRENT Books also carry legacy acquisition projections outside the nested
object:

| Book-level projection | Population | Exact relationship | Treatment |
|---|---:|---|---|
| `acquiredAt` | 27 | Equals nested `acquisition.date.value` 27/27 | Preserve as duplicate Book evidence; never Copy fallback |
| `acqYear` | 27 | Equals the nested date year 27/27 | Preserve as duplicate Book evidence |
| `acqMonth` | 24 | Equals the nested month 24/24; absent on the three year-precision dates | Preserve as duplicate Book evidence |
| `acquiredVia` | 68; 18 distinct private values | Non-empty for all Book acquisitions and unequal to nested `acquisition.source` 68/68 | Preserve separately; never map to Copy `acquired_via` |
| `giftFrom` | 42; 14 distinct private values | Equals nested source on all 42 `received` Book acquisitions | Preserve as duplicate Book evidence; no giver identity |

These fields neither override the 77 Copy objects nor fill a missing Copy
field. Their private values stay out of docs and ordinary artifacts. The
future mapper must emit privacy-safe preservation accounting for these Book
projections through the Book observation/finding boundary; it must not create
a second Item-local write or silently drop the non-identical `acquiredVia`
evidence.

## 3. V2 Item-local canon

`ItemLocalDetailsState` is the complete active target. It has nullable typed
members for:

- `condition`;
- `in_library_since` with exact year/month/day precision;
- `acquisition_method` (`zelf_aangeschaft`, `gekregen`, `anders`);
- `acquired_via`;
- exact `paid_amount` plus ISO 4217 currency;
- `signed` and `signed_by`;
- `copy_limitation`;
- `dust_jacket`;
- `inscription`;
- `provenance`; and
- `completeness`.

Null is a first-class truthful value for every unknown field. A never-created
all-null state has no persistence row. An explicit later clear of an existing
row is a versioned all-null tombstone; migration must not create such a
tombstone for a source with no known Item-local value.

Inventory number and Location remain existing Item concerns outside
`ItemLocalDetailsState`. Archive remains later lifecycle on the same owned Item.

## 4. Acquisition object semantics

The object name `acquisition`, its `type`, nested `date` and nested `source`
form one explicit acquisition shape. The source uses four observed type states:

| Raw type state | Copies | Reviewed meaning |
|---|---:|---|
| `bought` | 26 | acquired by buying |
| `received` | 45 | acquired by receiving/getting |
| `borrowed` | 5 | temporarily borrowed external physical source; not an owned Library Item |
| missing key | 1 | method unknown; other independently valid facts remain usable |

The 68 duplicated Book objects and nine Copy-only objects contain no value
conflict. The Copy occurrence is the sole Item-local input because the target
fact belongs to the concrete Copy.

## 5. `bought`

Decision: **EXACT_TARGET**.

The explicit source enum `bought` maps exactly to:

```text
acquisition_method = zelf_aangeschaft
```

This decision is based only on the acquisition type. Price, source text,
ownership, circulation and any neighbouring field do not contribute.

Population: 26 Copies.

## 6. `received`

Decision: **EXACT_TARGET**.

The explicit source enum `received` maps to the approved broad V2 distinction:

```text
acquisition_method = gekregen
```

V2.001 deliberately has no gift/inheritance/donation subtype. No giver, Person,
User or Vendor identity is inferred. Explicit source context, when present,
maps independently to `acquired_via`.

Population: 45 Copies.

## 7. `borrowed`

Decision: **PRESERVE_DEFERRED; NO LIBRARY ITEM**.

No product question remains. Existing V2 canon is explicit: an externally
borrowed physical source is user-owned ExternalLoan truth linked to Work and
does not become a Library Item. D-MIG-LOAN-01 also says the CURRENT borrowed
source Copy ID must remain evidence rather than being converted into an Item.

CURRENT evidence independently confirms that all five acquisition objects with
`type=borrowed` have one linked `borrowed` circulation round. Four are also
represented at Book level as `ownershipStatus=borrowed`,
`collectionStatus=borrowed`, `lending.mode=borrowed_in`; the fifth is Copy-only
at Book-current-state level and its Copy itself says `ownershipStatus=borrowed`.

The exact CURRENT state nuance is:

- one Copy is currently marked borrowed and has an open borrowed round;
- three Copies are currently marked owned while Book and both circulation
  representations remain borrowed/open; and
- one Copy is marked owned with a closed borrowed round while its Book remains
  borrowed/open. That last circulation ID is the already-known CIRC-01
  quarantine.

This corrects an over-broad evidence sentence in doc 113 that described all
five Copy current ownership values as borrowed. It does not change doc 113's
accepted product conclusion or circulation dispositions.

The Item-local mapper must therefore:

1. emit no `ItemLocalDetailsState` and no `CatalogItemPlan` for these five
   source Copies;
2. preserve the complete Copy/acquisition evidence with an explicit
   external-borrowed/non-Item reason;
3. leave the four unambiguous circulation observations to CIRC-01
   `preserved_deferred` and the one end-state conflict to CIRC-01 quarantine;
4. never map `borrowed` to `anders`, `gekregen` or `zelf_aangeschaft`; and
5. never duplicate circulation payload or private counterparty/note content in
   Item-local product data or ordinary artifacts.

Acquisition evidence and circulation evidence remain separately accounted even
though they describe the same external-borrowing context.

## 8. Missing acquisition type

Decision: **REVIEW_MAPPING resolved to partial active state**.

The one object without `type` has:

- one structurally valid day-precision acquisition date;
- no acquisition source text;
- an owned Copy and owned parent Book; and
- no parent Book acquisition object that could supply a method.

The object is therefore otherwise semantically usable. It maps as:

```text
acquisition_method = NULL
in_library_since   = exact source day
acquired_via       = NULL
```

The absent type remains explicit missing-type source evidence. It is not
repaired, defaulted to `anders` or quarantined, because the independent valid
date can be represented without ambiguity and null is the approved method
meaning for unknown.

## 9. Acquisition date

Decision: **EXACT_TARGET** for non-external-borrowed Item candidates.

The date is nested in the explicit acquisition object and carries its own
precision. It maps to `in_library_since` without using technical timestamps.

| Precision | All source Copies | Active Item-candidate mapping | Borrowed/non-Item preservation |
|---|---:|---:|---:|
| Day | 20 | 16 | 4 |
| Month | 13 | 13 | 0 |
| Year | 3 | 3 | 0 |
| **Total** | **36** | **32** | **4** |

All 36 date objects have exactly `value,precision`, a string value, a format
matching the declared precision and a valid V2 calendar/domain value. The
implementation must construct
`ItemAcquisitionDate` from exact components, never fill month/day, and never
use `createdAt`, `updatedAt`, registration, export, migration or current time.
Any future value that fails shape, precision or calendar/domain validation must
fail closed as `invalid_item_local_acquisition_date`, preserve its raw source
evidence and produce no Item plan; it must not escape as an unaccounted generic
planning error.

## 10. Acquired via

Decision: **EXACT_TARGET** for non-external-borrowed Item candidates.

`acquisition.source` is explicit acquisition-source context and maps unchanged
to `acquired_via`. It creates no Person, Vendor, User or authority record.

- 53 Copies have non-empty source text;
- those values contain 16 distinct private strings;
- 49 belong to `bought`/`received` Item candidates and map actively;
- four belong to borrowed/non-Item Copies and remain preservation-only; and
- the missing-type acquisition has no source text.

All 53 values fit the active text contract: maximum 27 Unicode code points,
no blank-only value, no leading/trailing whitespace, no control character and
no over-512 value. The private values are deliberately absent from this
document and ordinary artifacts.

## 11. Amount and currency

Decision: **NO_DATA**.

No Copy acquisition object contains amount, price or currency fields.

```text
paid_amount = NULL
currency    = NULL
```

Zero is not invented, price is not parsed from source/notes and no currency is
inferred from locale or target user.

## 12. Condition

Decision: **NO_DATA**.

All 1,106 Copy records contain `condition` as the empty string. No condition
value maps actively.

```text
condition = NULL
```

The mapper adds no `Goed`, `Nieuwstaat`, age inference, archive inference or
empty-string enum. No Condition value mapping table is needed for this
snapshot.

## 13. Signed and signed-by

Decision: **NO_DATA**.

The CURRENT Copy shape has no structured signed or signer field.

```text
signed    = NULL
signed_by = NULL
```

No Author, title, note or other free text is inspected for a signature.

## 14. Copy and limitation

Decision: **NO_DATA** for the typed field.

No source field has the approved semantic “number/limitation of this physical
copy”, such as `17/250`. `copyNumber`, `legacyBookNumber` and
`sourceBookNumber` are operational source numbers, not limitation evidence.

```text
copy_limitation = NULL
```

## 15. Dust jacket

Decision: **NO_DATA**.

No explicit dust-jacket field exists.

```text
dust_jacket = NULL
```

Binding never implies jacket presence, absence or non-applicability.

## 16. Inscription

Decision: **NO_DATA**.

No explicit copy-level inscription state exists.

```text
inscription = NULL
```

Copy notes and printed Edition dedications are not parsed into this boolean.

## 17. Provenance

Decision: **NO_DATA** for active Copy provenance.

No Copy has a dedicated provenance field. Book-level publication/provenance or
`specialFeatures` evidence is not Copy truth. Acquisition source, circulation
counterparty and Copy notes remain distinct.

```text
provenance = NULL
```

## 18. Completeness

Decision: **NO_DATA**.

No explicit completeness/enclosure field exists.

```text
completeness = NULL
```

No value is inferred from binding, a photo slot, notes or other evidence.

## 19. Copy notes

Decision: **PRESERVE_DEFERRED**.

Three Copies have a non-empty unstructured note string. They have no stable
nested note ID and the source shape does not type them as provenance,
condition, inscription or completeness. Their content is not inspected or
parsed for active fields.

The later implementation must account for them as private deferred Copy-note
evidence, or route them through a separately approved copy-note decision. Note
content and timestamps must not enter docs, dry-run artifacts, CLI output,
logs or errors.

## 20. Source numbers

Decision: **PRESERVE_DEFERRED**.

| Field | Populated | Distinct | Exact structural evidence | Active target |
|---|---:|---:|---|---|
| `copyNumber` | 1,106 | 1,106 | 12-character Copy discriminator; begins with parent 9-character `bookNumber` | None |
| `legacyBookNumber` | 1,106 | 1,100 | Equals parent `bookNumber` on all Copies; six two-Copy duplicate groups | None |
| `sourceBookNumber` | 1,106 | 1,100 | Equals `legacyBookNumber` and parent `bookNumber` on all Copies | None |

`Copy.id` remains migration identity. `copyNumber` distinguishes source Copies,
but source structure does not prove that the user intended it as V2 inventory
number. Legacy/source Book numbers are not even unique across Items for the six
multi-Copy Books.

Therefore `CatalogItemPlan.inventoryNumber` remains null and all three fields
remain migration/source evidence. No number maps to `copy_limitation`.

## 21. Disposal and exemplar photo

Decision: **PRESERVE_DEFERRED**.

- one Copy has a `{date, reason}` disposal object;
- one Copy has one non-empty `exemplarPhotos.front` slot; and
- these are two different unsupported meanings; they do not authorize archive,
  asset, provenance, condition or completeness writes.

Disposal requires a later archive/disposal review. The photo slot requires a
later asset decision. Neither is parsed, copied into product text nor silently
dropped. Four unique Copies contain at least one non-empty note/disposal/photo
fact; one of those Copies contains two such auxiliary kinds.

## 22. Preservation

One Copy may legitimately yield both active Item-local state and deferred
evidence, but one logical MIG-FND observation still has exactly one final
disposition. The mandatory product/evidence split is:

| Source evidence | Active product handling | MIG-FND handling |
|---|---|---|
| reviewed non-external-borrowed acquisition values | typed `ItemLocalDetailsState` | original observation remains traceable |
| no known active Item-local value | no details state/row | reviewed absence plus any auxiliary facts |
| three source-number fields | none | `preserved_deferred` |
| Copy notes | none | private `preserved_deferred` |
| disposal | none | `preserved_deferred` pending separate review |
| exemplar photo slot | none | `preserved_deferred` pending asset model |
| borrowed Copy/acquisition | no Item | `preserved_deferred` as external-borrowed Copy evidence |
| linked unambiguous circulation | none here | CIRC-01 `preserved_deferred` only |
| linked contradictory circulation | none here | CIRC-01 quarantine only |

Raw V1 JSON is never stored in `biblio_item_local_details`. Preservation stays
under the migration run/target evidence boundary and ordinary outputs remain
privacy-safe.

### One final disposition per observation

Every CURRENT Copy has at least the three deferred source-number fields.
Therefore a derived `catalog_item` observation that creates/reuses an Item must
finish as:

```text
disposition        = preserved_deferred
preservation_reason = copy_auxiliary_evidence_preserved
mappings            = item + library_catalog_context
                      + optional item_local_details
```

`MigrationRecordOutcome::preserved(...)` already permits target mappings. The
implementation must adjust the current CAT Item writer/participant, which now
returns `mapped`, to return this exact preserved outcome with the ordinary
created/reused target mappings. It must include only privacy-safe evidence
flags/counts/hashes or a bounded reference to restricted source evidence—never
private values.

This is not two dispositions and does not mean the Item write is deferred. The
Item, classification context and optional details commit normally and
atomically; `preserved_deferred` records that additional valid Copy facts still
have no active target.

The five external-borrowed Copies produce no `catalog_item` observation and
use the exact source-finding reason `external_borrowed_copy_preserved`, without
product mappings. The two Copies blocked by upstream invalid ISBN likewise
produce no Item observation; their Copy/source-number and Item-local evidence
remain explicit source-mapping findings under the upstream quarantine rather
than disappearing.

Replay of a committed preserved Item observation must verify the identical
source payload, preservation reason and item/context/details mappings. Changed
payload, changed active state, missing/broken target or a disposition/reason
change fails closed; it does not silently become `mapped` or create a second
Item. Reconciliation must count each observation once while separately proving
all created/reused mappings and every preservation finding.

## 23. ItemLocalDetailsState contract

The future mapper must produce one of three explicit outcomes per Copy.

### A. Reviewed non-empty state

For 72 non-external-borrowed Item candidates with at least one exact active
value, construct a typed state with only known members and null everywhere
else:

```text
condition             = NULL
in_library_since       = exact Copy acquisition date or NULL
acquisition_method     = zelf_aangeschaft | gekregen | NULL
acquired_via           = exact Copy acquisition source or NULL
paid_amount            = NULL
signed                 = NULL
signed_by              = NULL
copy_limitation        = NULL
dust_jacket             = NULL
inscription            = NULL
provenance             = NULL
completeness           = NULL
```

The 72 are 26 `bought`, 45 `received` and one missing-type/date-only Copy.
Pass the non-empty `ItemLocalDetailsState` to `CatalogItemPlan`. Of these, 71
are currently active and one is archived; the later archive participant owns
lifecycle and does not alter the details state.

### B. Reviewed absence

For 1,029 non-external-borrowed Item candidates with no acquisition object and
no other active Item-local value:

- mark the Item-local dependency reviewed;
- pass `localDetails = null`, not `ItemLocalDetailsState::unknown()`; and
- create no details row.

This follows CAT and `ItemLocalDetailsRecorder`: absence is valid and invents
neither a row nor a versioned all-null tombstone.

Of these, 1,007 are currently active and 22 are archived. One currently active
Copy also has the separate disposal object. Archive and disposal handling stay
outside Item-local state; later lifecycle processing neither creates nor clears
a details row.

### C. Not a Library Item

For the five external-borrowed Copies:

- return the exact terminal eligibility `NOT_LIBRARY_ITEM_EXTERNAL_BORROWED`;
- emit no `CatalogItemPlan` and no Item-local state; and
- preserve the Copy/acquisition evidence with reason
  `external_borrowed_copy_preserved`.

The current binary `itemLocalReviewed + ?ItemLocalDetailsState` dependency
shape cannot express “reviewed, but this source Copy is not an Item”: reviewed
null would incorrectly allow CAT to create an Item. The implementation slice
must add the exact typed terminal Item-eligibility outcome above and CAT must
suppress the Item plan only for that outcome. It must not overload null state,
classification, inventory, archive or circulation to carry that meaning.

Every active state is bound to the exact Item and explicit target Library.
There is no actor/admin/first-user/display-name or cross-Library fallback.

## 24. Classification intersection

The closed CAT/CLASS baseline has:

- 1,106 total Copies;
- two Copies excluded upstream by invalid-only ISBN Books;
- 1,104 CAT-eligible Copies;
- 748 classification-ready Copies; and
- 356 classification-blocked Copies.

All five external-borrowed Copies are CAT-eligible under the pre-existing CAT
shape. Four are classification-ready and one is classification-blocked. Once
the accepted no-Item rule is applied:

| Dependency intersection | Copies |
|---|---:|
| CAT-eligible and Item-local-ready | 1,099 |
| Classification-ready ∩ Item-local-ready | **744** |
| Classification-blocked but Item-local-ready | 355 |
| External-borrowed/non-Item | 5 |
| Upstream invalid-ISBN, outside CAT eligibility | 2 |

Therefore this design makes **744** CURRENT Copies semantically ready for a
complete Item plan after the separately authorized mapper implementation. It
does not make all 1,104 CAT-eligible Copies ready.

## 25. CURRENT counts and mapping matrix

### Population counts

| Fact | CURRENT count |
|---|---:|
| Copies | 1,106 |
| Copies with acquisition object | 77 |
| `bought` | 26 |
| `received` | 45 |
| `borrowed` | 5 |
| missing acquisition type | 1 |
| acquisition dates | 36: day 20, month 13, year 3 |
| populated acquired-via candidates | 53 source-total; 49 active; 4 borrowed-preserved |
| Book `acquiredAt` / `acqYear` / `acqMonth` | 27 / 27 / 24 legacy projections |
| Book `acquiredVia` / `giftFrom` | 68 / 42 private legacy projections |
| price/currency | 0 / 0 |
| populated Condition | 0 |
| signed/signed-by | 0 / 0 |
| limitation | 0 |
| dust jacket | 0 |
| inscription | 0 |
| Copy provenance | 0 |
| completeness | 0 |
| non-empty Copy notes | 3 |
| `copyNumber` | 1,106 populated; 1,106 distinct |
| `legacyBookNumber` | 1,106 populated; 1,100 distinct |
| `sourceBookNumber` | 1,106 populated; 1,100 distinct |
| disposal objects | 1 |
| non-empty exemplar-photo slots | 1 |

### Primary Item-local classification

These primary classes are mutually exclusive; auxiliary preservation may also
apply to an Item-local-ready Copy.

| Class | Copies | Meaning |
|---|---:|---|
| A. Item-local-ready | **1,101** | 72 typed non-empty states + 1,029 reviewed absences |
| B. Blocked by one product decision | **0** | borrowed is already settled by V2 canon |
| C. Preservation-only auxiliary/non-Item | **5** | external-borrowed source Copies; no Item plan |
| D. Item-local malformed/quarantine candidate | **0** | missing type remains valid partial unknown; circulation conflict stays in CIRC scope |

The two invalid-ISBN Copies are included in A for Item-local semantics but stay
upstream CAT-quarantined. One circulation observation linked to C remains
quarantined by CIRC-01; it is not double-counted as an Item-local quarantine.

### Field/value matrix

| Source field/concept | Population / source values | V2 Item-local target | Status | Active? | Preserved? | Product decision? | Notes |
|---|---|---|---|---|---|---|---|
| `acquisition.type=bought` | 26 | `zelf_aangeschaft` | EXACT_TARGET | Yes | Source trace | No | Exact explicit type |
| `acquisition.type=received` | 45 | `gekregen` | EXACT_TARGET | Yes | Source trace | No | No giver identity |
| `acquisition.type=borrowed` | 5 | none; no Item | PRESERVE_DEFERRED | No | Yes | No | ExternalLoan canon; CIRC separate |
| missing acquisition type | 1 | method null | REVIEW_MAPPING | Partial | Yes | No | Exact day still active |
| acquisition date | 36 | `in_library_since` | EXACT_TARGET | 32 | 4 borrowed | No | Precision exact |
| acquisition source | 53 | `acquired_via` | EXACT_TARGET | 49 | 4 borrowed | No | Private text omitted |
| price/currency | 0 | paid amount/currency | NO_DATA | No/null | No | No | Never invent zero |
| condition empty string | 1,106; 0 populated | condition | NO_DATA | No/null | No | No | No default |
| signed/signed-by | absent | signed/signed_by | NO_DATA | No/null | No | No | No free-text inference |
| source numbers | 1,106 each | none | PRESERVE_DEFERRED | No | Yes | No | Not inventory/limitation |
| dust jacket | absent | dust_jacket | NO_DATA | No/null | No | No | Binding irrelevant |
| inscription | absent | inscription | NO_DATA | No/null | No | No | Notes not parsed |
| Copy provenance | absent | provenance | NO_DATA | No/null | No | No | Book evidence not copied |
| completeness | absent | completeness | NO_DATA | No/null | No | No | Photo/binding not inference |
| Copy notes | 3 non-empty | none | PRESERVE_DEFERRED | No | Yes/private | No | No typed parsing |
| disposal | 1 object | none here | PRESERVE_DEFERRED | No | Yes | Later slice | Not archive by assumption |
| exemplar photo | 1 slot | none | PRESERVE_DEFERRED | No | Yes | Later asset slice | No asset implementation |
| Book nested acquisition duplicate | 68 exact | none independently | PRESERVE_DEFERRED | Via Copy only | Yes | No | Exact active mapping only from Copy; no double write |
| Book `acquiredAt`/year/month | 27/27/24 | none independently | PRESERVE_DEFERRED | No | Yes | No | Exact nested-date projections |
| Book `acquiredVia` | 68; 18 distinct private values | none | PRESERVE_DEFERRED | No | Yes/private | No | Not equal to nested source; never Copy fallback |
| Book `giftFrom` | 42; 14 distinct private values | none | PRESERVE_DEFERRED | No | Yes/private | No | Duplicates received nested source; no identity |
| Book special features | 11 values on 9 Books | none here | PRESERVE_DEFERRED | No | Existing Book evidence | No | Not Copy-local |

## 26. Product questions

Open product questions: **zero**.

The likely question—whether a temporary externally borrowed physical source may
be a Library Item—is already answered by accepted V2 canon: it may not. The
source evidence is sufficient to bind the five `borrowed` acquisition Copies
to that already-settled category without inventing ownership.

No question is manufactured for optional inventory number, missing acquisition
type, empty condition, unsupported notes, disposal or photo evidence. Their
truthful null/preservation treatments are already available.

## 27. Required implementation slice

After this DESIGN GO, the separately authorized bounded implementation should
be:

**MIG-02-ITEMLOCAL-MAP-01 — Current V1 Item-local mapper**

It should:

1. consume only adapter-validated CURRENT Copy records under the exact
   snapshot/manifest contract;
2. map `bought`, `received`, date and source through the exact reviewed rules;
3. represent missing type as null while retaining its exact date;
4. emit typed non-empty `ItemLocalDetailsState` for 72
   non-external-borrowed Item candidates;
5. mark 1,029 non-external-borrowed all-null Item candidates reviewed while
   passing `localDetails=null`;
6. add an explicit no-Item external-borrowed outcome for five Copies;
7. integrate that outcome with CAT so no false Library Item is planned;
8. preserve source numbers, Copy notes, disposal, photo, Book legacy
   acquisition projections and borrowed acquisition evidence with the exact
   allowlisted reasons from this contract;
9. keep circulation preservation/quarantine separate and non-duplicated;
10. combine only exact target Library + typed classification + reviewed
    Item-local eligibility/state;
11. remain deterministic, privacy-safe and zero-write in profile/dry-run; and
12. add no schema, UI, REST, source cleanup, free-text parser, inventory
    inference, asset handling, archive handling or apply/import behavior.

For an Item observation with auxiliary evidence, apply must call
`MigrationRecordOutcome::preserved("copy_auxiliary_evidence_preserved", ...,
mappings: $mappings)` rather than return a second or competing disposition.
The dry-run plan, apply result and reconciliation must all agree on that
single final disposition.

The implementation should recompute counts from the source and mapping results;
none becomes a runtime constant. Production mapping code is not implemented by
this decision.

## 28. Acceptance criteria

### Mapping

- `bought → zelf_aangeschaft` and `received → gekregen` are exact and tested.
- `borrowed` never becomes an acquisition method or Library Item.
- Missing type remains null while its valid day-precision date survives.
- All 36 CURRENT acquisition dates are shape-, precision-, calendar- and
  domain-valid and retain exact year/month/day precision.
- A future invalid acquisition date is accounted as
  `invalid_item_local_acquisition_date`, preserves evidence and creates no Item
  plan.
- Only explicit `acquisition.source` becomes `acquired_via`.
- Amount/currency, Condition and collector fields remain null without source
  evidence.
- Source numbers never become inventory or limitation without a later approved
  decision.
- Book-level nested and legacy projections never overwrite or double-write
  Copy state and are explicitly preserved/accounted.

### State and CAT integration

- Exactly 72 non-external-borrowed Item candidates produce a non-empty typed
  state: 71 currently active and one archived.
- Exactly 1,029 non-external-borrowed Item candidates provide reviewed absence
  and no details row: 1,007 currently active and 22 archived.
- Exactly five external-borrowed Copies produce no Item plan.
- CAT planning distinguishes reviewed absence from terminal non-Item.
- The exact Library and Item bind every active state.
- `classification-ready ∩ Item-local-ready` recomputes to 744 for this snapshot.

### Preservation and privacy

- Every populated source number, Copy note, disposal object, photo slot and
  borrowed acquisition object has an explicit preserved path.
- Book `acquiredAt`, `acqYear`, `acqMonth`, `acquiredVia` and `giftFrom`
  projections are explicitly preserved without Copy fallback, duplicate active
  writes or private-value exposure.
- The one linked circulation contradiction remains CIRC quarantine and is not
  hidden by an Item-local disposition.
- Raw V1 JSON never enters the product details table.
- Private Copy acquisition source, Book `acquiredVia`/`giftFrom`, Copy-note,
  counterparty and circulation-note text never appears in committed docs or
  ordinary artifacts/output/errors.
- Active mapping plus auxiliary preservation reconciles without silent loss.
- Every Item observation with auxiliary Copy evidence has exactly one
  `preserved_deferred` final outcome with reason
  `copy_auxiliary_evidence_preserved` and retains its target mappings.
- Exact replay verifies the same payload, reason and mappings; divergent replay
  or broken targets fail closed.

### Boundaries

- No schema/Core/UI production change is part of this document.
- No source file is modified or re-extracted.
- No apply/import command is run.
- No production mapper is implemented automatically.
- The required implementation remains a separately authorized slice.

## Team review

### Product

The bought/received distinction survives as active Item truth. Externally
borrowed physical sources remain truthful user-owned source evidence rather
than becoming falsely owned Library Items.

### Metadata

Acquisition stays distinct from circulation. Book-level duplication does not
gain precedence. Source identifiers are not promoted to limitation or
inventory semantics.

### UX

Only known fields can appear in migrated Item details. Empty Condition and
absent collector facts do not render invented defaults. Deferred notes,
disposal, photos and external-loan evidence are not presented as active Item
facts.

### Engineering

The active target remains the existing typed `ItemLocalDetailsState`. Reviewed
all-null input omits the details row. The only required CAT contract extension
is an explicit terminal non-Item outcome; no schema or generic escape hatch is
needed.

### Migration

Every CURRENT Copy-local field is accounted through active mapping, reviewed
null, preservation or the existing CIRC quarantine. The complete immediately
implementable intersection is 744, not all 1,104 CAT-eligible Copies.

## Current V1 data rule

Only the immutable SOURCE-01 snapshot, approved hash anchor, read-only
extraction and `current-v1-json-29` adapter are authority for this mapping.
Historical exports, fixtures, DATA-01 and other ZIPs are not current truth.

## Schema/Core/UI impact

This design changes none. Product remains `v2.001`; schema remains `1026`;
Biblio Core remains `2.38.0`; Biblio UI remains `0.20.0`.

## Verdict

**DESIGN GO.** No product decision is required from Renée. The next authorized
slice is `MIG-02-ITEMLOCAL-MAP-01`; it is not started by this document.
