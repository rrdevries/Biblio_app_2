# D-ITEM-MIG-01 — Minimum Item-local migration target

Status: **DESIGN GO — Acquisition B+ approved; implementation not started**

Date: 2026-09-14

Task severity: **High**

Scope: product/domain/migration design only. This document adds no production
code, schema, migration, REST, UI or V1 data mapping. V1 is source evidence,
not product authority.

## 1. Decision-inheritance audit

The authority order used here is: current accepted V2 decisions; current
Git/schema/implemented contracts; current closed design/closure documents; and
only then V1 source facts after Renée designates a current export. Historical
fixtures and MIG-01 profiles are not current V1 truth.

| Topic | Existing accepted V2 decision | Evidence/source | Current implementation status | Still open? |
|---|---|---|---|---|
| Work/Edition/Item ownership | Work and Edition are shared; one physical Item belongs to one Library; local copy facts cannot overwrite central bibliography | `docs/01-functional-design.md` §4; ADR-013 §Decision | Item retains Library and Edition identity | No |
| Verkrijgingswijze (Acquisition method) | Item-local structured distinction `Zelf aangeschaft`, `Gekregen`, `Anders`; unknown is null | Renée product decision, 2026-09-14; `docs/01-functional-design.md` §Item | No domain or persistence target | No |
| Verkregen via (Acquired via) | Optional bounded Item-local context text, without vendor/person/authority entity | Renée product decision, 2026-09-14 | No domain or persistence target | No |
| Acquisition date | `In bibliotheek sinds` is the content/business date; statistics use the content date | `docs/01-functional-design.md` §Acquisition | Detail DTO has only an `unknown` placeholder | No; precision-preserving representation is technical |
| Registration time | `Geregistreerd op` is the technical time the V2 Item was registered, never its acquisition date | `docs/01-functional-design.md` §Acquisition | Current Item/domain/schema has no registration timestamp | No product question; this existing implementation gap is outside the details target |
| Paid price/value | Optional exact historical paid amount plus ISO 4217 currency; current valuation remains a separate excluded concept | Renée product decision, 2026-09-14 | Not implemented | No |
| Condition | Optional Item value with exactly `Nieuwstaat`, `Zeer goed`, `Goed`, `Redelijk`, `Matig`, `Slecht`; blank means not recorded | `docs/01-functional-design.md` §Condition | Not in Item/schema; Book Detail always receives `unknown` | No |
| Location | Library-owned; an Item has zero or one current Location; used Locations are not destructively removed | `docs/01-functional-design.md` §Locations; doc 36 | Implemented in Core/schema and Library-scoped reads; Book Detail still receives `unknown` | No product question; read integration is missing |
| Inventory number | Optional Item field, unique only within its Library | `docs/01-functional-design.md` §Inventory number | Implemented in Item/schema/Add Book and batch read; absent from Book Detail | No |
| Signed | Factual state of this concrete copy | ADR-013 §Item/Copy collector layer; this design brief | Not implemented | No |
| Signed by | Separate copy-local signer text; never inferred from canonical Authors and does not require an Author entity | This design brief strengthens ADR-013's “room for who signed it” | Not implemented | No |
| Copy/limitation number | Item-local and distinct from Edition-wide print-run information | ADR-013 §Item/Copy collector layer | Not implemented | No |
| Dust jacket | Copy-local factual state: present, missing or not applicable | ADR-013 §Item/Copy collector layer | Not implemented | No; null retains unknown |
| Inscription/dedication | Whether this copy contains a personal inscription/dedication; a supplementary note is later scope | ADR-013 §Item/Copy collector layer | Not implemented | No for factual state; free text remains deferred |
| Origin/provenance | Modest known origin of this copy; no provenance graph or owner authorities | ADR-013 §Item/Copy collector layer and deferred specialist data | Not implemented | No |
| Completeness/enclosures | Copy-local presence/absence facts about maps, inserts, loose plates, slipcases or supplements | ADR-013 §Item/Copy collector layer | Not implemented | No |
| Library-local display/sort/label | Exactly own display name, own sort title and short local explanation/label; a separate layer from Item facts | ADR-013 §Two collector layers | Approved but not implemented | Target identity is not settled, but this layer is outside the Item-local target and does not block this design |
| Archive state/history | Archive is Item lifecycle, not delete; identity, bibliography, acquisition and copy facts remain | `docs/01-functional-design.md` §Archive; docs 37 and 64 | State/version, periods and native/preserved reasons implemented | No |
| Collection membership | Separate Library-owned organization of active Items; Item archive ends active memberships and restore does not silently re-add them | `docs/01-functional-design.md` §Collections; doc 38 | Implemented independently of Item metadata | No |
| Generic local observations | No generic active Item field is approved; exactly six extra collector fields are approved | ADR-013 §§Item/Copy collector layer and Deferred specialist data | MIG-FND evidence exists, but is not active product data | No: reject an active arbitrary observation/EAV escape hatch |

### Verified current baseline

- Git: `main` at `4ee5f070d0aaece21d95ce82ffc789112f085608`,
  36 commits ahead of `origin/main`, clean at audit start.
- Product: `v2.001`.
- Live DDEV and schema constant: schema `1024`.
- WordPress plugin/runtime version: Biblio Core `2.26.0`.
- Biblio UI plugin/runtime version: `0.19.0`.
- `Biblio\Core\Plugin::VERSION` is still `2.14.0`, while the plugin header,
  manifest and live WordPress runtime report `2.26.0`. That is an existing
  version-source inconsistency. It is out of scope and is not silently
  reconciled by this design.

## 2. Problem statement

Current V2 can identify an Item, bind it to one Library and Edition, give it an
inventory number and Location, and archive/restore it with retained history.
It cannot actively persist or normally read Condition, Acquisition or the six
approved collector facts. The current Item detail DTO contains generic
Location/Condition/Acquisition slots, but `CatalogUiReadService` fills all of
them with `unknown`. Persistence-only migration into MIG-FND would therefore
retain evidence but not create usable Item truth.

The minimum target must make approved physical-copy facts normal source-neutral
V2 product data, while retaining raw migration evidence separately and avoiding
a rare-book catalogue, arbitrary EAV store or V1-shaped schema.

## 3. Existing V2 canon

The following decisions are inherited without reopening them:

1. Work and Edition remain central bibliographic entities. Publisher,
   publication date, ISBN and canonical titles never become Item-local because
   migration finds a copy-specific value.
2. Item is the concrete physical-copy layer and is Library-owned.
3. Condition is optional and uses the existing six-value vocabulary. There is
   no migration-only `Anders` value.
4. Acquisition method is optional and uses exactly `Zelf aangeschaft`,
   `Gekregen` and `Anders`; `Verkregen via` and amount cannot infer it.
5. `In bibliotheek sinds` is a business/content date, distinct from the
   technical record timestamp. Known precision is retained.
6. The six D-COL-01 collector fields are Signed, Copy number/limitation, Dust
   jacket, Inscription/dedication, Origin/provenance and
   Completeness/enclosures.
7. Specialist antiquarian fields remain deferred.
8. Archive changes lifecycle, not the historical facts of the copy.
9. Collections are a separate organization layer and contain active physical
   Items only.
10. Unknown stays unknown. No false negative, zero, current date or quality
   default is introduced.
11. Current V1 mappings and prevalence wait for an explicitly designated
    current export.

## 4. Layer and ownership principles

| Fact | Layer/owner | Rule |
|---|---|---|
| Canonical Work identity/title | Work, platform-wide | Never changed by this target |
| Concrete publication title, publisher, publication date, ISBN | Edition, platform-wide | Never copied into Item-local fields |
| Inventory number, Location, Condition, Acquisition, collector facts | Item, exact Library | Authorized and read under explicit Library Context |
| Own display/sort title and local label | Library-local presentation | Separate from the Item-local target; never smuggled into Condition/notes |
| Migration payload/provenance | MIG-FND, exact run/target context | Audit evidence, not ordinary product metadata |
| Unapproved or future copy facts | Preservation-only | Never placed in an arbitrary active JSON/notes field |

An Item-local row must carry the same `library_id` as its Item and use a
composite restrictive relation. A shared Edition, sibling Item or another
Library can never provide fallback values.

## 5. Minimum V2.001 field set

The minimum active set is:

| Field | Minimum semantics | Unknown semantics |
|---|---|---|
| Condition | One existing controlled value | Null/not recorded |
| In-library-since | Precision-preserving year, year-month or full date | All date components absent |
| Verkrijgingswijze (Acquisition method) | `Zelf aangeschaft`, `Gekregen` or `Anders` | Null; never inferred from amount or context text |
| Verkregen via (Acquired via) | Bounded context text such as a shop, market, gift source or inheritance | Null |
| Paid amount | Exact historical amount paired with ISO 4217 currency | Both amount and currency null |
| Signed | Explicit signed or not signed factual state | Null |
| Signed by | Optional bounded plain-text description of signer(s), only when Signed is true; null when Signed is false or unknown | Null; signer unknown does not negate signed state |
| Copy/limitation | Bounded lossless text such as `17`, `17/250` or `Presentation copy` | Null |
| Dust jacket | Present, missing or not applicable | Null |
| Inscription/dedication | Explicit present or absent for a personal copy inscription/dedication | Null |
| Provenance | Bounded plain-text origin evidence | Null |
| Completeness/enclosures | Bounded plain-text statement of present/missing components | Null |

Existing active Item fields remain Item identity, Edition, Library, status,
version, inventory number and Location. Archive periods and Collection
memberships stay in their existing independent models.

The field value contract is exact:

- Condition machine values are `nieuwstaat`, `zeer_goed`, `goed`, `redelijk`,
  `matig` and `slecht`, with the identical Dutch labels in §7. Labels remain the
  product vocabulary; keys are only stable transport/storage codes.
- A partial date is null, or has a year from 1000 through 9999, an optional
  month 1 through 12 and an optional valid calendar day. Month requires year;
  day requires month. Unknown components are null, never zero or an invented
  `1`.
- `signed_by` and `acquired_via` contain 1–512
  Unicode code points; `copy_limitation` contains 1–191; `provenance` and
  `completeness` contain 1–1024. Values must be valid UTF-8, contain at least one
  non-whitespace code point and contain no Unicode control characters.
- Text is stored exactly as accepted: no trimming, Unicode normalization,
  whitespace collapsing or case canonicalization. MIG-02 must preserve a
  rejected/out-of-contract source value in raw evidence rather than truncate or
  rewrite it.
- Acquisition-method machine values are `zelf_aangeschaft`, `gekregen` and
  `anders`. Null is unknown. `anders` is an explicit value, never the fallback
  for absent, ambiguous or richer unsupported source data.
- Paid amount uses `DECIMAL(19,4)`: at most 15 integer
  digits and four fractional digits. Its transport string matches
  `(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?`, has no sign, grouping or exponent,
  and is serialized canonically without leading or insignificant trailing
  zeros. It is paired with an uppercase three-letter ISO 4217 current or
  historical code from a versioned Core allowlist; both are null or both are
  present. No float, conversion, inferred currency or current-value meaning is
  permitted. Out-of-range, over-precision and unrecognized-currency source
  values are rejected from the active field and preserved raw, never rounded or
  truncated.

The minimum does not add a generic copy note. Raw or not-yet-approved source
facts remain losslessly traceable through MIG-FND as described in §9.

## 6. Acquisition

### Settled

- Acquisition belongs to the physical Item and its Library.
- `In bibliotheek sinds` is historical business content; it is not the database
  creation time, export time or migration time.
- The date retains only known precision: year, year-month or full date. Missing
  month/day are not filled with `1`.
- Acquisition remains readable after archive.
- Current monetary valuation is not acquisition price and is outside this
  bounded target unless Renée later approves a separate value domain.

### Approved B+ contract

- `acquisition_method` is optional and structured as `zelf_aangeschaft`,
  `gekregen` or `anders`; null is unknown.
- `acquired_via` is independent optional bounded context text. It may name a
  shop, antiquarian, market, gift source or inheritance, but creates no vendor,
  person or authority entity and never substitutes for the method.
- `paid_amount` is an optional exact historical amount/currency pair and is not
  current value or appraisal.
- `Zelf aangeschaft` versus `Gekregen` remains stable, filterable Item truth.
  V2.001 adds no gift/inheritance/donation/trade subtype taxonomy. A future
  refinement must retain the approved core distinction.
- No method is inferred from paid amount, acquired-via text, provenance or any
  other indirect signal.

Provenance remains separate: it describes earlier history of the physical copy,
not merely how it entered the current Library.

## 7. Condition

The active Condition value is Item-local current state with exactly these
accepted product values:

- Nieuwstaat;
- Zeer goed;
- Goed;
- Redelijk;
- Matig;
- Slecht.

The implementation uses the stable machine keys fixed in §5 with an exact
one-to-one mapping to these labels and may not add or merge meanings. Null means
not recorded/unknown and is distinct from every condition. There is no `other`
value and no mapping by similarity.

A current export may later prove an exact source-to-V2 allowlist. A source term
outside that allowlist leaves structured Condition unknown and keeps the raw
wording in its MIG-FND source observation. The containing source-copy outcome
is then preservation-aware under the single-disposition rule in §14; quarantine
is reserved for invalid, contradictory or ownership-conflicting input.

This minimum stores current Condition, not a newly invented full Condition
timeline. Archive retains the value. Any future normal edit/history workflow
must preserve the already stated archive-history principle and receive its own
explicit mutation/audit contract; this design does not silently create it.

## 8. Collector/local physical facts

### Signed and signed by

`signed` is tri-state: signed, not signed, or unknown. `signed_by` is nullable,
bounded plain text and is allowed only when signed is true. Signed with an
unknown signer is valid. Signer text is not an Author ID, is not resolved by
name and never creates or mutates a canonical Author/contributor.

### Copy/limitation number

The value is bounded lossless text. It is not split into numerator,
denominator, copy sequence or print-run semantics. Formatting is preserved
apart from rejecting an empty value.

### Dust jacket

The structured states are present, missing and not applicable; null is unknown.
No jacket-condition grading is introduced.

### Inscription/dedication

The active minimum is only present, absent or unknown for a personal
inscription/dedication in this physical copy. Printed publisher/editorial
dedication is Edition content, not this field. ADR-013's possible supplementary
free-text note remains later scope.

### Provenance

One bounded plain-text origin statement is the active minimum. It may describe
a prior owner, estate, auction or other known origin. It is not a provenance
graph, authority record, auction-history model or chain of ownership entities.

### Completeness/enclosures

One bounded plain-text statement records copy-specific completeness, including
maps, inserts, loose plates, CDs, slipcases or supplements. This is not asset
upload/file management and makes no Work/Series completeness claim.

## 9. Preservation and raw evidence

Structured product truth and migration evidence remain separate:

1. The normal Item-local detail row contains only approved typed product fields.
2. The MIG-FND `SourceObservation` may retain the canonical bounded raw payload
   or a durable reference and its payload hash.
3. An exact mapping writes structured product state and the existing Item target
   edge. Item-local details are part of that Item target, not a second migration
   identity or target type. The raw observation remains traceable without being
   displayed as product metadata.
4. MIG-FND gives one source observation exactly one disposition. A source copy
   whose important facts are all represented can be `mapped` or `transformed`.
   If one legitimate important fact remains deferred, the source-copy outcome
   is `preserved_deferred` with the already committed Work/Edition/Item target
   edges and raw evidence. An invalid, contradictory or ownership-conflicting
   record is quarantined/fails closed. MIG-02 may use finer source-observation
   granularity only if it defines that deterministically before profiling.
5. No Item-local JSON blob, generic active observation table or migrated “copy
   note” becomes an escape hatch.

A valid but unsupported source fact is `preserved_deferred`, not quarantined.
Quarantine is reserved for invalid, contradictory, ownership-conflicting or
ambiguous multiple-Item target input. Legitimate Work/Edition/Item edges for one
source copy remain allowed. A technical/persistence failure is `failed` with
explicit retryability. `intentionally_dropped` requires a reason and is
forbidden for an important legitimate Item-local source fact.

The existing metadata-user-observation tables are Edition/provider/Add Book
evidence with a fixed field allowlist. They are not reused as generic Item or
V1 migration storage.

## 10. History versus current-state rules

| Concept | Classification in this minimum |
|---|---|
| Acquisition date and any later-approved acquisition details | Historical Item fact; never replaced by technical registration/migration time |
| Condition | Editable current metadata; archive retains it; no new timeline in this slice |
| Signed/not signed | Persistent physical fact, correctable only by an explicit future edit |
| Signed by | Editable copy description attached to signed state |
| Copy/limitation | Persistent copy fact represented losslessly |
| Dust jacket | Editable current factual state |
| Inscription/dedication | Persistent physical fact represented as factual state |
| Provenance | Editable cumulative local statement, not a graph/history engine |
| Completeness/enclosures | Editable current local statement |
| MIG-FND raw source observation | Immutable snapshot evidence |
| Archive period/reason | Existing immutable lifecycle history, outside this detail row |

No future edit is authorized to clear or rewrite facts merely to make another
operation succeed. The details row is its own optimistic-lock aggregate,
separate from Item lifecycle `item_version`; §13 defines its concurrency rules.

## 11. Read contract

An active target requires an authorized normal Core read, not only migration
storage.

Required application contract for ITEM-MIG-01A:

- `LibraryItemLocalDetailsQueryService` resolves the actor and explicit Library
  Context before a tenant-scoped Item/details read;
- it can retrieve active or archived Item details by exact Item ID, with a
  bounded batch form for later list composition;
- missing, foreign and unauthorized Items share non-enumerating behavior;
- an absent detail row hydrates every optional field as unknown, preserving
  backward compatibility for all existing Items;
- no Work/Edition/sibling-Item fallback exists.

Normal details visibility, including provenance, inherits the existing exact
`canViewCollection` capability for an active membership in the requested
Library Context. This does not grant write access. Add Book uses
`canAddCatalogItem`; a later detail edit uses `canManageCatalogItems`. Missing,
foreign and unauthorized records remain non-enumerating. MIG-FND raw payload,
evidence references and source IDs never enter this normal response.

ITEM-MIG-01A shall compose this result into Book Detail's existing `Exemplaar`
panel. The current Location, Condition and Acquisition placeholders become real reads;
inventory number and the approved collector facts join that panel. Typed state
must cross REST boundaries, so the UI never infers “not signed”, “no jacket” or
date precision from display text.

The serializer emits one exact allowlisted `item_local_details` object. Its
required keys are `details_version` (positive integer, or null when no row),
`condition` (one §5 machine value or null), `in_library_since` (null or exactly
`{year, month, day}`), `signed` (boolean or null), `signed_by` (string or null),
`copy_limitation` (string or null), `dust_jacket` (`present`, `missing`,
`not_applicable` or null), `inscription` (boolean or null), `provenance` (string
or null), `completeness` (string or null), `acquisition_method`
(`zelf_aangeschaft`, `gekregen`, `anders` or null), `acquired_via` (string or
null) and `paid_amount` (null or exactly `{decimal, currency}`). Server and
client reject missing, extra, wrongly typed or invalid nested keys/values;
display labels remain presentation only. A never-created persistence row
serializes the same object with version and every field null. A previously
created row that was explicitly cleared has a positive version while every
value is null.

The current Book Detail endpoint is active-Item-only. The Core query must retain
details for archived Items and make them available to the existing authorized
archive/read boundary. A reachable archived Book Detail/REST presentation is a
separate focused follow-up, not permission to hide or delete archived facts.

## 12. Write ownership

All writers share typed value objects, aggregate invariants and one
non-transaction-owning recorder/repository contract, but not one actor-aware
application command or transaction owner:

- normal Add Book may later provide optional Item-local details;
- a future Item edit surface may modify them after server-side authorization;
- a source-neutral migration participant may write them for the exact validated
  target Library;
- Library management roles may write only when an existing or separately
  approved capability authorizes Item management.

Normal Add Book and future edit application services resolve their actor,
capability and transaction before invoking the shared recorder. MIG-FND remains
the transaction owner for migration. Its participant is non-transaction-owning,
receives the exact target Library and Item, validates same-Library ownership and uses the same
value objects/repository as normal product code. It has no actor/admin/name
fallback and performs no direct ad-hoc wpdb write.

No Add Book UI expansion is required by this design. The existing success
control for `Exemplaar verder beschrijven` remains disabled until an Item edit
surface is separately implemented.

## 13. Persistence strategy

The smallest sensible shape is one Core-owned sparse 1:1 table, conceptually
`biblio_item_local_details`, rather than many new columns on `biblio_items` or
an EAV/JSON value store.

Recommended structural contract:

- composite primary/foreign identity `(library_id, item_id)` referencing the
  same pair on Item with `RESTRICT`;
- typed nullable columns for the approved fields;
- precision-preserving acquisition-date components with database checks mirroring
  §5 component dependency, ranges and calendar validity;
- closed checks for Condition, signed, dust-jacket and inscription states;
- bounded columns plus domain validation for the full UTF-8, whitespace and
  control-character text policy in §5;
- cross-field check: signer text is allowed only when signed is true;
- closed check for acquisition method and paired amount/currency;
- positive `details_version` for optimistic locking;
- no source family/ID, raw V1 payload, current valuation or arbitrary JSON.

The 1:1 row leaves Item identity/lifecycle compact, avoids EAV ambiguity,
supports one join/batch read and can be extended through explicit migrations.
An absent row is valid. Archive/restore and Collection membership changes do
not modify or delete it.

This is a standalone details aggregate, not an independent bibliographic or
migration identity. A non-empty create is an atomic insert at
`details_version=1`. An update uses compare-and-swap on
`(library_id, item_id, expected_details_version)` and increments exactly once.
Concurrent absent-row inserts allow one winner; the loser re-reads and succeeds
only for exact equal state, otherwise it returns a conflict. A never-written
all-unknown state has no row. Once a row exists, clearing its last known value
retains an all-null tombstone and increments `details_version` through the same
compare-and-swap; it never deletes/recreates the row or resets its version. Item
archive/restore does not increment `details_version`.

An index beginning with `(library_id, acquisition_method)` preserves the
approved future filtering/statistics capability without adding a filter endpoint
to ITEM-MIG-01A.

The migration mapping remains `target_entity_type=item` with the existing Item
ID. The details row never receives its own MIG-FND target edge.

This structural and value contract is implementation authority for the bounded
ITEM-MIG-01A slice; no Acquisition subsystem or subtype table is authorized.

## 14. Migration semantics

The future `MIG-02-CAT-01` Item participant must:

1. begin from the explicitly validated target user+Library and pinned current
   export;
2. use stable source-copy identity and the existing source observation hash;
3. create/reuse the exact Item before writing its same-Library detail row;
4. commit Item, details, target mapping and outcome in the existing
   `CommitMigrationRecordService` transaction;
5. treat an absent optional field as unknown without blocking Item creation;
6. let the non-transaction-owning details recorder converge only while the
   locked observation is `observed` or `retryable_failure`, treating exact
   already-present state as success/no-op;
7. reject a divergent existing detail row as conflict for quarantine/review,
   never overwrite it silently;
8. preserve raw input and date precision in the source observation;
9. map Condition only through an explicit exact allowlist based on the current
   export;
10. map Acquisition method only through an explicit exact current-export
    allowlist; never infer it from amount, acquired-via text or provenance;
11. apply the single-disposition rule: use `preserved_deferred` with committed
    Item target edges when one important valid fact remains deferred, rather
    than claiming simultaneous mapped and preserved outcomes; and
12. create no duplicate detail rows or metadata records on replay.

The same source copy maps to the same Item. Within one run, source identity is
unique. A committed observation is returned/skipped by orchestration and is not
passed to `CommitMigrationRecordService::commit()` again; a changed payload for
that identity is a conflict, not a second observation. Product writing is
allowed only for the service's `observed` and `retryable_failure` states.

Across runs, exact prior-result reuse uses the same target user+Library, source
family/type/ID and payload hash. For a later snapshot whose same logical source
copy has a changed payload, source-scoped traces (not hash-bound
`priorTargets()`) must resolve exactly one prior Item in that same target
context. That Item is reused and the new-run observation receives its own
outcome; zero prior targets follows normal materialization and multiple prior
Items fail closed for review. It never mutates/deletes prior evidence or silently
chooses a target. Exact detail-row equality and the insert-race re-read are
additional product safeguards, not substitutes for MIG-FND replay rules.

## 15. Field decision matrix

| Concept | Existing V2 decision | Layer | V2.001 active target? | Structured or preserved? | Current/readable? | Editable later? | Migration importance | Requires current V1 export for mapping? | Notes |
|---|---|---|---|---|---|---|---|---|---|
| Acquisition date | Business date distinct from registration time | Item/Library | Yes | Structured partial date + raw observation | No; proposed by this design | Yes | High | Yes | Do not invent missing precision |
| Registration time | Technical V2 Item registration time, never acquisition | Item/system | Existing canon, but outside this details target | Separate future structured Item field; source date preserved unless separately mapped | No current Item field | System-owned, not user-editable | Not a copy-description migration target | Yes, before any legacy timestamp mapping | Details-row creation time is not a substitute |
| Verkrijgingswijze (Acquisition method) | Exact `Zelf aangeschaft`, `Gekregen`, `Anders`; null unknown | Item/Library | Yes | Structured enum + raw observation | No; proposed by this design | Yes | High | Yes | Filterable; no inference or subtype taxonomy |
| Verkregen via (Acquired via) | Optional bounded context text; no vendor/person authority | Item/Library | Yes | Structured bounded text + raw observation | No; proposed | Yes | High | Yes | Does not replace method; richer source facts may remain preserved |
| Paid acquisition amount/currency | Exact historical amount, distinct from current value | Item/Library | Yes | Structured decimal/currency pair + raw observation | No; proposed | Yes | Potentially high | Yes | Null pair allowed; never infer method or currency |
| Current monetary value | No approved V2.001 target | Out of scope | No | Preserved only if encountered | No | Separate future decision | Unknown | Yes | Never use paid price as current value |
| Condition | Exact six optional values | Item/Library | Yes | Structured enum + raw observation | No; proposed by this design | Yes | High | Yes | Unknown remains null; no `Anders` |
| Location | 0..1 current Location | Item/Library | Already active | Structured FK | Core/batch yes; Book Detail placeholder no | Yes | High | Yes | Existing target; no redesign |
| Inventory number | Optional, unique within Library | Item/Library | Already active | Structured text | Core/batch yes; Book Detail no | Yes | High | Yes | Existing target; no redesign |
| Signed | Approved copy fact | Item/Library | Yes | Structured factual state | No; proposed | Yes | High | Yes | Unknown distinct from not signed |
| Signed by | Separate signer description; no Author inference | Item/Library | Yes | Structured bounded text | No; proposed | Yes | High | Yes | Signed with unknown signer is valid |
| Copy/limitation | Approved copy fact | Item/Library | Yes | Lossless bounded text | No; proposed | Yes | High | Yes | Do not force numeric parts |
| Dust jacket | Present/missing/not applicable | Item/Library | Yes | Structured enum | No; proposed | Yes | Medium/high | Yes | Null means unknown |
| Inscription/dedication | Personal copy fact; note later | Item/Library | Yes | Structured factual state | No; proposed | Yes | Medium/high | Yes | Printed dedication is not this field |
| Provenance | Modest known origin; no chain/entities | Item/Library | Yes | Bounded text | No; proposed | Yes | High | Yes | Rich provenance deferred |
| Completeness/enclosures | Copy component presence/absence | Item/Library | Yes | Bounded text | No; proposed | Yes | High | Yes | No file/asset subsystem |
| Own display name | Exactly one approved local presentation field | Library-local contextual | Yes in product canon, but not this Item target | Separate future structured target; preserve source if encountered | No | Yes later | Unknown | Yes | Attachment identity Work vs Edition is not settled |
| Own sort title | Exactly one approved local presentation field | Library-local contextual | Yes in product canon, but not this Item target | Same as above | No | Yes later | Unknown | Yes | Never place on Item by convenience |
| Short local label | Exactly one approved local presentation field | Library-local contextual | Yes in product canon, but not this Item target | Same as above | No | Yes later | Unknown | Yes | Not generic migration notes |
| Archive state/reason/history | Existing Item/period model | Item/Library | Already active | Existing structured/preserved-reason model | Authorized Core history yes | Existing commands | High | Yes | Details survive archive |
| Collection membership | Separate active Item organization | Library/Collection | Already active | Existing structured periods | Authorized Core reads yes | Existing commands | High | Yes | Metadata never depends on membership |
| Other legitimate Item source fact | No active generic field approved | Preservation-only | No | MIG-FND `preserved_deferred`; quarantine only if invalid/conflicting | Audit only | Only after explicit decision | High for no-loss | Yes | No arbitrary JSON product field |

## 16. Explicitly deferred

- specialist rare-book cataloguing: collation formulas, gatherings/signatures,
  detailed binding/paper/watermark state, issue/state, auction history,
  conservation logs and specialist grading;
- signature authentication and canonical signer entities;
- provenance graphs/historical-owner entities;
- inscription text/asset capture;
- enclosure asset/file management;
- current valuation/accounting;
- a generic Item EAV/JSON observations feature;
- full Condition/Location history editing;
- the existing `Geregistreerd op` Item implementation gap; any later field must
  record technical V2 Item registration and must not absorb a legacy acquisition
  or details-row creation date;
- Item edit UI and a larger Add Book Item form;
- Library-local presentation persistence until its Work-versus-Edition identity
  receives separate implementation authority;
- archived Book Detail presentation beyond the existing Core archive read;
- exact V1 mapping, field prevalence and source taxonomies.

## 17. Current V1 export dependencies

The target principle does not require current V1 data. These later questions
are explicitly **REQUIRES CURRENT V1 EXPORT FOR MAPPING**:

- which source field is the acquisition business date and what precision it
  actually carries;
- which source values map exactly to `Zelf aangeschaft`, `Gekregen` or
  `Anders`, which supply `Verkregen via`, and which are an exact amount/currency;
- which Condition values exist and which are exact approved matches;
- which collector fields exist, their null/unknown conventions and formats;
- whether local presentation facts exist and whether they are Work-, Edition-
  or copy-oriented;
- which legitimate Item-local facts remain preservation-only;
- all record counts and migration prevalence.

No historical ZIP, DATA-01 case or earlier `/data/` copy answers these current
mapping questions.

## 18. Open product questions

There are **zero unresolved product questions** for this bounded target.

Renée approved the B+ contract on 2026-09-14: precision-preserving acquisition
date, structured acquisition method, bounded acquired-via context and optional
exact historical amount/currency. The current-value and subsystem boundaries in
§6 remain explicit.

The unresolved Work-versus-Edition identity of the separate Library-local
presentation layer is deliberately deferred from ITEM-MIG-01A and must not be
guessed during implementation; it is not a blocker for this Item-local target.

## 19. Implementation slicing

The next separately started implementation should remain one bounded slice:

**ITEM-MIG-01A — Item-local evidence persistence/read**

It should include the typed Item-local value model, one 1:1 Core table and
repository, authorized Core active/archive-capable read, source-neutral
non-transaction-owning migration participant, shared recorder usable from an
actor-aware Add Book service, mandatory Book Detail REST/read composition and
strict frontend display of known values. It does not include an Item edit UI,
Add Book redesign, V1 parser/import,
current-source mapping, archive UI redesign or Library-local presentation
layer.

Only split `ITEM-MIG-01B — product read integration` if implementation evidence
shows the REST/UI contract cannot safely ship atomically with Core persistence.
Persistence must not be declared complete as an active migration target while
normal product code still cannot read it.

## 20. Acceptance criteria

Future implementation is accepted only when tests prove:

1. every detail row belongs to the exact Item and Library;
2. composite constraints and services reject dangling/cross-Library writes;
3. every structured enum and exact text bound/policy at its stated domain and
   database boundaries, including DB/domain parity for enums, dates and
   signed=true/signer pairing;
4. unknown/null remains distinct from explicit negative/not-applicable state;
5. acquisition date enforces component dependency, year range and valid calendar
   dates, retains known precision and never uses migration/registration time;
6. exact write/read-back through the source-neutral repository and authorized
   Core query;
7. existing Items without a detail row remain valid and read all fields as
   unknown;
8. archive/restore preserves details unchanged and archive history remains
   independent;
9. Collection add/remove/archive behavior never creates, changes or deletes
   Item-local details;
10. Work/Edition records and sibling Items receive no local contamination;
11. `canViewCollection` can read every normal detail including provenance,
    `canAddCatalogItem` and `canManageCatalogItems` gate their respective normal
    writes, and other Libraries/unauthorized actors receive no data or
    enumerating distinction;
12. Book Detail returns and renders only known allowlisted Item facts, rejects
    missing/extra/wrongly typed nested keys and uses no client inference;
13. source observation, Item/detail write and mapping share one MIG-FND
    transaction;
14. rollback leaves no detail row or false mapping;
15. orchestration skips a committed observation without invoking product write,
    while retryable failure can converge without a duplicate row;
16. a changed payload conflicts within one run; in a new run the same logical
    copy reuses its sole prior Item, and zero/multiple prior-target cases follow
    the explicit normal/fail-closed paths without overwrite;
17. raw source evidence remains traceable and absent from ordinary product
    output/logs;
18. exact approved Condition mappings succeed and unknown terms are never
    coerced to a nearby value;
19. Acquisition method round-trips as exactly one approved value or null,
    remains deterministically queryable by Library, and `anders` is never used
    as an unknown/fallback value;
20. amount, acquired-via text and provenance never infer Acquisition method,
    and acquired-via context never mutates or replaces provenance;
21. concurrent details-row create has one winner with exact-equality convergence,
    divergent create/update and stale CAS fail closed, clear-versus-update keeps
    a versioned all-null tombstone without ABA, and archive never changes
    `details_version`;
22. technical Item registration time, acquisition content date and details-row
    creation time are never substituted for one another;
23. amount/currency min/max, overflow, over-precision,
    canonical round-trip, invalid currency and no-rounding cases pass;
24. schema fresh install, ordered upgrade, exact retry, partial-state failure
    and health checks pass; and
25. existing catalog, Add Book, archive, Collection, Book Detail and migration
    regressions remain green, followed by an independent review pass.

### Team-review outcome

| Perspective | Review conclusion |
|---|---|
| Product | The approved B+ minimum retains acquisition and ordinary copy truth without turning Biblio into a specialist rare-book system. |
| Metadata | Work/Edition/Item boundaries, explicit unknowns and raw-evidence separation are source-faithful. |
| UX | One typed Book Detail group is understandable; edit-form expansion remains separately bounded. |
| Engineering | The sparse typed aggregate, exact REST shape, capability matrix, CAS and shared non-owning recorder give an implementable boundary. |
| Migration | One-disposition outcomes, target-scoped replay and preservation of unsupported valid facts prevent silent loss or duplicate Item/detail identity. |

No perspective produced another unresolved product decision. Engineering and
migration review corrections and the approved Acquisition B+ contract are
incorporated above.

## Design exit

All inheritance, ownership, field, preservation, unknown, migration, read and
rare-book boundaries are closed. The field matrix is implementation authority
for the bounded next slice. Verdict is therefore **DESIGN GO**.

ITEM-MIG-01A is not started by this design closure and still requires its own
explicit implementation task.

No schema, Core version, UI version, PHP, JavaScript or CSS changes are part of
this design slice. No current V1 export was requested or used.
