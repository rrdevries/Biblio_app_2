# 33 — Mijn Bibliotheek server-side catalog query readiness

Status: **BLOCKED — readiness analysis only**.

Date: 2026-09-03.

## 1. Context, scope and verdict

This reconciled analysis defines what is known and what is still missing before
server-side Search, Filters, Sort and context-bound keyset pagination may be
implemented for the existing `Mijn Bibliotheek` overview.

No production code, schema, REST route, UI behavior, Elementor configuration or
runtime data is changed by this slice. The current route and UI remain limited to
the existing active overview ordered by Work title and Item ID.

**Verdict: BLOCKED.** Reconciliation closes several false or over-broad open
questions: the personal/default view contract, archive preference and temporary
archive-search behavior, title-ascending default, empty-query browse behavior,
and a set of implementation-only normalization/cursor/transport choices. The
repository still does not contain a definitive filter set, Collection-filter
operators, alternate Author/Series/date sort contract or complete search and
state UX. Most canonical search fields and almost every candidate rich filter
also lack a reliable persisted source in schema 1008. The complete feature
therefore still cannot enter implementation without the short product-decision
list in section 16 and prerequisite data capabilities.

The existing title-only data permits a later, separately approved technical
subslice, but that would not satisfy the feature requested here.

## 2. Sources and authority

The analysis used, in authority order:

1. `AGENTS.md` and the source-order rule in `docs/05-source-register.md`;
2. `docs/00-current-state.md` and `docs/01-functional-design.md`;
3. `docs/26-future-roadmap-decisions.md` and the accepted ADRs, especially
   ADR-001, ADR-002, ADR-003, ADR-004, ADR-005 and ADR-006;
4. `docs/02-architecture.md`, `docs/03-scope-and-deferred.md` and
   `docs/06-testing-and-acceptance.md`;
5. F2.11/F2.12 and Vertical Slice 1A evidence in docs 18–21;
6. the living Design System and its implemented Mijn Bibliotheek slice in docs
   31–32;
7. current Core, REST, UI, tests, schema registry and the local schema-1008
   MariaDB runtime.

`manifest.json` already indexes document 33. No document-index change is needed
for this reconciliation.

## 3. Reconciliation matrix

Statuses mean: `CANONICAL` is a complete existing product decision; `PARTIAL`
has a fixed basis but unresolved product semantics; `OPEN` has no definitive
repository decision; `TECHNICAL` needs no user product decision;
`DOCUMENTATION GAP` existed in authoritative implementation/current-state
evidence but was insufficiently centralized in the functional design before
this reconciliation.

| Onderwerp | Status vóór reconciliation | Bron | Definitieve status |
|---|---|---|---|
| Library scope, authorization, active default and visible copies | Definitief | Functional design §§5 and 15; testing §12; ADR-001/003 | CANONICAL |
| Search fields: Work title/alternative title, Auteur/Co-auteur, Serie, relevant ISBN, inventory number | Definitief | Functional design §§5 and 15 | CANONICAL |
| Search ranking and two-character minimum | Definitief met implementatiedetails open | Functional design §15 | CANONICAL |
| Scoring within a relevance class and exact-identifier recognition | Open productbesluit | Canonical ranking/length behavior supplies the constraint | TECHNICAL |
| Empty/absent query returns the normal overview | Open | Existing backward-compatible F2.11/F2.12 overview; functional design §5 | CANONICAL |
| Case, Unicode/whitespace, identifier punctuation, maximum length and query transport | Open productbesluit | ADR-003; functional design §§15 and 18; implementation must preserve approved matching behavior | TECHNICAL |
| Prefix/token/substring and accent-equivalence behavior | Open productbesluit | No definitive source | OPEN |
| Contained Work title for an omnibus | Open | Functional design §4 defines the relation but not Library-search matching | OPEN |
| Set result | Open for this Item overview | Functional design §§4 and 15 fixes direct Set matching and excludes child-only duplicate Set results | PARTIAL |
| Quick filters `Alles`, `Ongelezen`, `Aan het lezen`, `Uitgelezen` | Open | Functional design §§5–6 fixes the default active overview and derived statuses, not quick-filter exposure/operators | PARTIAL |
| Quick filters `Fysiek`, `E-book`, `Luisterboek` | Open/later media | Functional design §§1 and 4; scope doc §Media excludes a varying v2.001 media filter | CANONICAL |
| Quick filters `Uitgeleend`, `Serie` | Open | Functional design §§4–5, 8 and 11 fixes the underlying concepts, not filter exposure | PARTIAL |
| Expanded Auteur, Serie, Locatie, Taal, Uitgever, Uitleenstatus, Conditie | Open | Functional design §§4–5, 8 and 11 define values, not filter semantics; most sources unavailable | PARTIAL |
| Expanded Vorm/drager | Open | Physical-only v2.001 scope excludes a varying form filter | CANONICAL |
| Expanded Leesstatus | Open | Functional design §§5–6 defines three derived values, not filter mechanics | PARTIAL |
| Expanded Aankoop-/toevoegdatum | Open | Functional design §5 defines business acquisition date versus registration timestamp, not filter field/range | PARTIAL |
| Boeksoort/Genre/Onderwerp filters | Open | Functional design §5; ADR-006; data exists but overview exposure/operators are open | PARTIAL |
| Collection member boundaries and multiple Collection membership | Open als filtercontract | Functional design §10; testing §10; roadmap A-02; domain prerequisites only | CANONICAL |
| Collection multi-select, OR, AND with other groups, `Zonder collectie`, exclusivity and option lifecycle | Open | No definitive repository source for these filter semantics | OPEN |
| One result row per matching Item identity | Mixed into Collection product semantics | Existing Item result identity; duplicate-safe query requirement | TECHNICAL |
| Default Library-overview order: title ascending | Definitief alleen in technical/current-state evidence | Docs 00/18; current Core; now centralized in functional design §5 | DOCUMENTATION GAP |
| Explicit Title sort options beyond the default | Open | No definitive source | OPEN |
| Author sort | Open | No primary-author or missing-value order is fixed | OPEN |
| Series sort and unknown volume numbers last | Open | Functional design §11; roadmap B-12/C-01 fixes Series identity but not order/volume behavior | PARTIAL |
| Date sorts | Open | No definitive source | OPEN |
| Personal `Standaardweergave` and fallback | Asked again under view persistence | Functional design §17; testing §17; source register defaults/preferences | CANONICAL |
| Temporary filter/sort versus permanent defaults | Open together with session state | Functional design §§17–18; settings save behavior; temporary controls do not silently change defaults | DOCUMENTATION GAP |
| Session survival, user+Library+module memory, URL and Back/forward | Open | Archive search has a specific session/reset rule; no generic catalog rule exists | OPEN |
| Personal `Archief tonen`, default off | Partly open | Functional design §17; testing §17; terminology | CANONICAL |
| Temporary `Ook in archief zoeken` | Partly open | Functional design §§5 and 15; testing §12 fixes session-only, no preference mutation and reset | CANONICAL |
| Archive interaction with ordinary browse, filters and sort | Open | No complete source beyond the two preceding contracts | PARTIAL |
| Cursor tuple/fingerprint, list encoding, SQL shape, indexes and deterministic tie-breakers | Mixed into product questions | ADR-002/003/004; architecture and current adapter contracts | TECHNICAL |
| Toolbar, detail disclosure and active chips | Definitief | Design System §12; doc 32 | CANONICAL |

No repository file or Git-history revision contains the proposed complete
quick/expanded filter list or the multi-Collection OR/`Zonder collectie`
contract. The source register confirms that older handovers once carried
detailed Collection and preference history, but those source files are not in
this checkout and its summaries do not preserve these exact filter rules. They
cannot be promoted to current truth without source evidence. The two actual
documentation gaps closed here are the title-ascending product default and the
boundary between temporary controls and explicit saved defaults.

### Documentation gaps closed

- The title-ascending Library-overview default existed in F2.11/current-state
  evidence but was not stated in the central functional-design search contract.
- `Standaardweergave`, explicit settings save behavior and the generic
  temporary-filter rule existed separately; their no-silent-default-mutation
  consequence was not stated together for Mijn Bibliotheek filter/sort/view
  controls.

No complete historical quick/expanded filter or Collection-filter decision was
recoverable from the repository, so no such gap is claimed as closed.

## 4. Current implementation and request flow

Current flow:

`browser → GET /biblio/v1/libraries/{library_id}/items → RestController`
`→ RestRequestParser → CatalogUiReadService → CatalogUiReadRepository`
`→ WpdbCatalogUiReadRepository → CatalogItemReadRecordPage`
`→ RestResponseSerializer → overview-view.js`.

### 4.1 Core

- `CatalogUiReadService::activeOverview()` accepts Library ID, optional
  `CatalogOverviewCursor` and optional `CatalogOverviewPageSize` only.
- The service resolves the authenticated actor and F2.10 Library Context before
  the repository call.
- `CatalogUiReadRepository::activeOverview()` has no typed query/filter/sort
  argument.
- `CatalogOverviewCursor` contains Work title and Item ID.
- The record/page/view types expose reliable Item/Edition/Work IDs, Work title,
  active Item state, actor-derived Work reading status and capabilities.
- Authors and all rich metadata remain typed `unknown`; form is the fixed
  `physical_book`; overview location/source is currently only the Library name.

Missing: a typed catalog query, search/filter/sort value objects, query-context
validation, option-list read boundaries and richer source projections.

### 4.2 Repository and SQL

The adapter starts from `items_by_library`, joins Edition and Work, left-joins
one actor-owned ReadingRound aggregate and exact-Item active Round, orders by
`w.work_title ASC, i.item_id ASC`, requests `limit + 1`, then emits the last
title/Item tuple as cursor. It executes one overview projection query; the
application adds the Library Context read. There is no per-Item loop.

Missing: search predicates/ranking, filter predicates, alternate order/keyset
predicates, scoped option queries and duplicate-safe many-to-many handling.

### 4.3 REST

- The route supports `cursor` and `page_size` in practice.
- `page_size` defaults to 24 and is bounded to 1–100.
- `CatalogCursorCodec` emits an unsigned opaque base64url version-1 payload with
  `title` and `item_id`.
- The overview callback currently does not run a query-field allowlist check;
  unknown query fields can therefore be ignored instead of rejected.
- `RestResponseSerializer` exposes the allowlisted current page and next cursor.

Missing: strict overview query allowlist, typed parsing/normalization, query
object construction, context-bound cursor validation and stable filter-option
responses if the UI needs server-provided choices.

### 4.4 Frontend

- `app.js` requests only the first page or `?cursor=...`, appends load-more
  results and protects navigation with `AbortController` and run generations.
- `route-state.js` intentionally keeps only `library_id` and `item_id`; building
  a route clears all other query parameters.
- `overview-view.js` renders disabled Search and Sort, a filter disclosure with
  explanatory copy, Grid/List/Bookshelf view state and Quick View.
- Only sidebar collapse is persisted. Catalog query and selected view are not
  stored as a preference.

Missing: query state/reducer, request serialization, first-page replacement,
cursor reset, active chips/reset, query-specific loading/empty/error states,
stale query-response protection and any approved URL/session persistence.

## 5. Search contract analysis

| Candidate field | Canonical status | Current source | Consequence |
|---|---|---|---|
| Work title | Yes | `wp_biblio_works.work_title` | Technically possible now. |
| Alternative title | Yes | None | Requires a central alias/title model and persistence. |
| Auteur/Co-auteur | Yes | None | Requires central identities, role relations and Library-visible joins/projection. |
| Serie | Yes | None | Requires Series identity/relations and an explicit representation model. |
| ISBN | Yes | None | Requires Edition ISBN persistence including `Geen ISBN` semantics. |
| Inventory number | Yes | None | Requires Item inventory persistence and same-Library uniqueness. |
| Contained Work title in omnibus | Insufficient source proof | Omnibus relation absent | **OPEN PRODUCT DECISION:** whether it matches the container Item. Labelling within an approved result model is later UX detail. |
| Set title/ISBN | Direct Set matching is canonical, but inclusion in this Item overview is not | Set model absent | **OPEN PRODUCT DECISION:** whether this implementation scope includes Set results or leaves them to a separate result surface. |
| Publisher/language/classification/free text | Not canonical Library-search fields | Partial/no sources | Do not add without a product decision. |

Canonical behavior already known:

- strict Library scope and current collection-view authorization;
- active Items by default;
- no copy deduplication;
- relevance class ordering and alphabetical title within equal relevance;
- general minimum two characters, exact identifiers exempt;
- no hidden external metadata search;
- no Notes/review/Collection-description free-text search.

**OPEN PRODUCT DECISIONS:** prefix/token/substring behavior;
accent/diacritic-equivalence where it materially changes expected results;
contained-title semantics; Set inclusion in this result surface; and whether an
explicit alternate sort may override relevance while search is active.

Empty or absent `q` retains the normal title-ordered overview. Case-folding,
Unicode and whitespace normalization, safe maximum length, exact-identifier
recognition, ISBN/inventory punctuation handling and transport encoding are
technical choices. They must be deterministic, tested and may not narrow or
expand an approved matching contract accidentally.

The current `work_title` collation is `utf8mb4_unicode_520_ci`, so current SQL
comparison is case-insensitive and generally accent-insensitive. That database
fact is not a product decision and must not become one accidentally.

## 6. Filter contract analysis

The underlying meaning of many candidate values is canonical, but no complete
Mijn Bibliotheek filter set or operator contract exists. For every filter that
is approved for exposure, the remaining product questions are:

1. quick filter or expanded filter;
2. single or multi-select;
3. OR or AND within the filter group;
4. AND relationship to other groups;
5. null/unknown inclusion and label;
6. active-only versus archive interaction;
7. source and option-list visibility.

Stable-ID transport, strict parsing and duplicate-safe SQL are technical.

Technical availability today:

- **Reading status:** derivable server-side from actor-owned ReadingRounds as
  `reading`, `read` or `not_read`; it must remain private and never be stored as
  a second catalog truth.
- **Boeksoort/Genre/Onderwerp:** Library-scoped IDs and Work-context junctions
  exist with useful Library-leading indexes. The product has not approved these
  as Mijn Bibliotheek filters.
- **Form:** all v2.001 Items are physical books; `Fysiek` would not narrow the
  result and `E-book`/`Luisterboek` are outside v2.001. Do not expose these as a
  varying current filter.
- **Location, Author, Series, language, publisher, loan status, Condition,
  acquisition, Collections and archive:** no reliable current source.

### Collections

Canonical Collection membership boundaries are usable prerequisites: active
Items in the same Library only; one Item may belong to multiple Collections;
archived Items are not current members; archived Collections are read-only.

The domain already fixes active same-Library Item membership, permits one Item
in multiple Collections and excludes archived Items from current Collection
membership. It does **not** fix one/multiple selection, OR within the group,
AND with other groups, `Zonder collectie`, its exclusivity, or whether archived
Collections are omitted from or separately available in filter options. Those
remain one explicit Collection-filter product decision. Whatever is approved,
one Item identity must not become duplicate rows merely because multiple
membership relations match; the duplicate-safe SQL shape is technical.

## 7. Sort and keyset contract

### 7.1 Proven order

The only complete current overview order is:

`Work title ASC, Item ID ASC`.

Its keyset predicate is:

`title > :title OR (title = :title AND item_id > :item_id)`.

For canonical relevance search, the likely tuple is:

`relevance class ASC, Work title ASC, Item ID ASC`.

The ranking classes come from the functional design; exact SQL scoring within a
class is technical, provided it is deterministic and does not invent a new
semantic rank. Item ID remains the final unique tie-breaker.

### 7.2 Alternate orders

| Sort | Required tuple if later approved | Current blocker |
|---|---|---|
| Title descending | `title DESC, item_id DESC` | Not canonically offered; product decision required. |
| Author | normalized/display Author key, title, Item ID | No primary-author/null rule, no data. |
| Series | Series key, canonical volume/order key, title, Item ID | Series identity is canonical, but no order/volume model, unknown-volume placement or data exists. |
| Acquisition/registration/publication date | precision/null-aware date tuple plus Item ID | Field choice, precision/null order and data are open. |

Locale/collation behavior must be fixed and tested. Keyset comparison and
`ORDER BY` must use the same explicit collation/normalization. Nulls must be
represented by an explicit sort bucket before the nullable value and Item ID;
database-default null order must not define product behavior implicitly.

## 8. Cursor and query-context direction

Recommended strategy: **B — a fingerprint of the canonical query context in a
versioned cursor, validated by the service against the separately supplied
typed query object**.

A version-2 catalog cursor should contain only:

- cursor version;
- authorized Library ID;
- sort discriminator;
- normalized keyset tuple for that sort;
- final Item-ID tie-breaker;
- SHA-256 (or equivalent fixed-length) fingerprint of canonical normalized
  search/filter/sort context, excluding page size and cursor.

The application service recomputes the fingerprint from the authorized Library
ID and immutable query and rejects a mismatch before repository access. This
prevents replay under another Library or query while avoiding a large cursor
containing every filter value. The cursor does not authorize anything; Library
Context and every scoped ID are validated again.

Backward compatibility recommendation:

- requests without new parameters retain title ASC and current page-size
  defaults;
- decode version-1 catalog cursors only for that exact empty/default query;
- generate version-2 cursors after the implementation ships;
- reject malformed, unknown-version, impossible-tuple and context-mismatched
  cursors with the existing safe invalid-field 400 behavior.

Signing is not required for authorization because the server revalidates the
typed tuple and context, but an HMAC may be added as defense against arbitrary
cursor fabrication if a managed key/lifecycle convention is approved. A hash
of public query context alone is a consistency fingerprint, not an authenticity
signature.

## 9. Typed application contract

Recommended Core direction, without implementation:

- `CatalogOverviewQuery`: immutable aggregate containing search, filters, sort,
  page size and optional cursor;
- `CatalogSearchTerm`: UTF-8 and canonical product normalization/length rules;
- `CatalogOverviewFilters`: typed sets/ranges and invariant validation;
- `CatalogReadingStatusFilter` and other filter-specific enums/value objects
  only after their product contracts are approved;
- typed scoped IDs for Location/Collection/classification options;
- `CatalogOverviewSort`: allowlisted enum, initially only proven values;
- `CatalogOverviewCursor`: versioned sort-specific keyset tuple plus context
  fingerprint;
- `CatalogQueryFingerprint`: deterministic canonical serialization/hash.

`CatalogUiReadService::activeOverview(LibraryId, CatalogOverviewQuery)` remains
the named application boundary. `CatalogUiReadRepository` receives the typed
query, never `WP_REST_Request` or an associative transport bag. REST performs
syntax parsing; Core validates meaning, scope and authorization.

## 10. Database, indexes and performance

### 10.1 Current schema and live evidence

Schema 1008 currently has:

- Works: ID and `work_title`; no title index;
- Editions: ID and Work ID, indexed by Work;
- Items: ID, Library ID, Edition ID and active-only status, indexed separately
  by Library and Edition;
- LibraryCatalogContext: Library+Work primary key and Library+Boeksoort index;
- Genre/Subject junctions: Library+Work+term primary keys and Library+term
  reverse indexes;
- ReadingRounds: actor, Work, outcome and finish-date index usable for bounded
  actor+Work aggregation;
- no Author, Series, ISBN, inventory, Location, Condition, acquisition,
  InternalLoan, Archive or Collection tables/columns.

The local runtime held 1 Item, 4 Works and 0 ReadingRounds. Both the current
title-order plan and an illustrative `%boek%` title predicate used
`items_by_library`, PK lookups for Edition/Work, `Using temporary` and
`Using filesort`. This confirms current index choice but is not representative
performance proof.

### 10.2 Impact by capability

| Capability | Query/index assessment |
|---|---|
| Current title order | Existing query is bounded but sorts the Library result. A composite index on Items cannot directly cover a Work-table title; 10k proof is required. |
| Substring title search | Leading-wildcard `LIKE` cannot use a normal title B-tree for search. It can remain acceptable at 10k only after measured proof. |
| Ranked multi-field search | Requires missing source tables and likely UNION/EXISTS or a scoped search projection; schema change required, exact index after representative EXPLAIN. |
| Reading-status filter | Use one actor-scoped aggregate/EXISTS plan, never per-Item queries. Existing actor+Work+outcome index is promising; representative status-selectivity proof required. |
| Classification filters | Existing Library-leading indexes support scoped `EXISTS`; multi-value combinations need duplicate/skip paging tests. |
| Collection filters | Requires Collection/membership schema. Prefer `EXISTS`/deduplicated Item IDs and Library-leading membership indexes. |
| Location/Condition/acquisition/loan/archive | Requires source schema first, then Library-leading indexes matched to approved filters/order. |
| Author/Series/language/publisher | Requires bibliographic schema/relations first; index after cardinality and relation design are known. |

At 1,000+ and 10,000 Items per Library, acceptance requires seeded deterministic
data, first/middle/last keyset pages, selective and non-selective predicates,
query-count proof, wall-time observations and `EXPLAIN`/`EXPLAIN ANALYZE` where
supported. No full in-memory catalog filtering is acceptable.

Conclusion: **schema changes are required for the complete requested feature;
additional indexes are expected but cannot be selected responsibly before the
missing source models and approved query shapes exist.**

## 11. Reading-status filter

Reading status is derived, not stored:

1. any actor-owned active Round for the Work → `reading`;
2. otherwise any actor-owned completed Round → `read`;
3. otherwise → `not_read`.

Stopped-only remains not read; historical completion counts as read. The
current overview already derives this with one actor-filtered grouped subquery,
so the filter can remain server-side without N+1. The implementation should
filter on the same derived expression or an equivalent scoped `EXISTS`
form. A persisted status column in catalog data would be a forbidden second
truth. A new projection is only justified by measured 10k evidence and would
need explicit freshness/ownership design.

## 12. Frontend query state

Required implementation state:

- immutable current query: search, filters and sort;
- current first-page result, next cursor and append state;
- draft versus applied expanded-filter state if the UX chooses explicit apply;
- active filter chips and reset action;
- query loading, load-more loading, query empty and operation-specific error;
- independent Grid/List/Bookshelf presentation state;
- request generation plus `AbortController` for stale-response protection.

Correctness rules:

- any applied search/filter/sort change aborts the old request, clears loaded
  Items and cursor, and starts at page one;
- load-more always sends the exact same normalized query plus returned cursor;
- Grid/List switches presentation only and preserves query/results/cursor;
- Quick View/detail continues to use returned scoped Item IDs and reauthorizes;
- stale responses can never overwrite a newer query.

The effective personal/Library/platform `Standaardweergave` contract determines
the initial view. An ordinary view switch is temporary and does not silently
save a new preference. The same no-silent-default rule applies to filter and
sort controls.

**OPEN PRODUCT DECISIONS:** whether ordinary filter/sort choices survive
in-module navigation during the current session and, if so, their exact
user+Library+module scope; URL persistence and Back/forward semantics; search
debounce versus explicit submit; immediate versus Apply filter changes; and
reset scope. Existing `route-state.js` supports only Library/Item identity and
cannot be extended silently because URL persistence changes UX behavior. The
existing explicit-submit Next Reading search is a reusable technical pattern,
not canonical proof for Mijn Bibliotheek.

## 13. Proposed REST contract

Keep the existing endpoint:

`GET /biblio/v1/libraries/{library_id}/items`.

The final allowlist depends on product decisions. The following is the proposed
technical vocabulary; `blocked` parameters must not ship before their source and
semantics are approved.

| Parameter | Type | Requirement | Normalization / allowed values | Cursor interaction | Status |
|---|---|---|---|---|---|
| `q` | string | optional | Approved matching contract; min 2 except recognized exact identifier; bounded technical maximum | Included in fingerprint | Search fields ready; matching UX still open; normalization technical |
| `reading_status` | list of enum | optional | Proposed values `reading`, `read`, `not_read`; multiplicity/OR open | Included | Blocked product contract |
| `form` | list of enum | optional | No useful v2.001 values beyond `physical_book` | Included | Do not expose now |
| `location_id` | list of ID | optional | Typed same-Library IDs | Included | Blocked product/data |
| `author_id` | list of ID | optional | Typed central IDs; only results represented in Library | Included | Blocked product/data |
| `series_id` | list of ID | optional | Typed central IDs; only results represented in Library | Included | Blocked product/data |
| `language` | list of code | optional | Standardized language codes | Included | Blocked product/data |
| `publisher_id` | list of ID | optional | Stable central ID rather than label | Included | Blocked product/data/model |
| `loan_status` | list of enum | optional | Values must follow approved availability/loan model | Included | Blocked product/data |
| `condition` | list of enum | optional | Canonical Condition values, including explicit unknown rule | Included | Blocked product/data |
| `acquired_from`, `acquired_to` | precision-aware date/range | optional | Must use approved acquisition business date, not registration time | Included | Blocked product/data |
| `collection_id` | list of ID | optional | Typed active same-Library IDs | Included | Blocked product/data |
| `without_collection` | boolean | optional | Proposed exclusive with `collection_id`; exact contract open | Included | Blocked product/data |
| `include_archived` | boolean | optional | Canonical temporary search-session behavior; never mutates preference | Included | Semantic basis ready; archive data and wider filter interaction blocked |
| `sort` | enum | optional | `title_asc` is the only proven overview sort; relevance behavior with `q` must be decided | Included | Partial |
| `page_size` | integer | optional | Existing 1–100, default 24 | Excluded from fingerprint | Ready |
| `cursor` | opaque string | optional | Versioned, sort-specific and context-bound | Must match all context | Ready direction |

List encoding (repeated parameters, brackets or comma-separated values) is a
transport choice, but one convention must be selected and strictly parsed. No
labels, actor ID, capability, Library override or arbitrary field names are
accepted. Unknown parameters/values, malformed IDs, incompatible combinations,
oversized lists and mismatched cursors return safe 400 errors. Foreign scoped
IDs must not reveal existence; use the established non-enumerating mapping.

## 14. Security and tenant review

No separate reviewer agent was available. An explicit independent second pass
was performed in two roles after the documentation changes.

### Biblio Product Guardian pass

- Current canonical scope supersedes any old digital-media quick-filter idea:
  `Fysiek` is invariant and `E-book`/`Luisterboek` are outside v2.001.
- Underlying status, metadata and Collection concepts were not mistaken for
  approval of a filter UI or operator.
- The absent historical handover files were not reconstructed from the task
  wording or source-register summaries.
- Existing view/default and archive decisions were retained and separated from
  genuinely open interaction details.

### Biblio Architect pass

- Core remains owner of Library scope, authorization, private reading-status
  derivation and query meaning; REST and UI remain adapters.
- Cursor, transport, normalization, duplicate-safe query shape, collation,
  tie-breakers, limits and indexes are technical implementation decisions.
- Missing Author/Series/ISBN/Item lifecycle/Collection sources remain explicit
  prerequisites; no UI or REST simulation is authorized.
- No production, schema, runtime or authorization contract changed.

### Blockers

- Filter option and result queries cannot ship until every Library-owned ID is
  constrained by the already-authorized explicit Library Context.
- Archive, Collection, Location and other absent sources cannot be simulated in
  REST/UI or inferred from visibility.

### Major

- A cursor reusable under another query can skip or duplicate rows and may
  probe result boundaries; context fingerprint validation is mandatory.
- Central Author/Series IDs must only yield Library-visible Items and must not
  become cross-Library existence oracles.
- Actor-private reading status must be derived only for the authenticated actor;
  no user ID parameter is accepted.
- Many-to-many joins can duplicate Items and corrupt keyset pages; use `EXISTS`
  or a unique Item-ID relation.

### Minor

- Bound search length, list cardinality and page size to control expensive
  predicates.
- Normalize invalid/unknown filter behavior consistently and do not leak SQL,
  schema, foreign labels or membership details through errors or timing-oriented
  option endpoints.
- Continue cookie authentication plus `X-WP-Nonce` for private reads.

### Observations

- `canViewCollection` is the current read boundary; UI capabilities remain
  presentation only.
- Unknown and inaccessible resource handling already has a non-enumerating
  pattern that should be reused.
- The current overview parser should add a strict query allowlist as part of the
  implementation rather than continue ignoring unsupported query fields.

## 15. Implementation acceptance matrix

### Unit

- every value object accepts valid and rejects malformed/oversized input;
- canonical normalization and fingerprint determinism;
- default empty query and title order;
- every approved enum/set/range and incompatible combination;
- sort-specific cursor tuple validation, versioning and v1 default-query bridge;
- cursor mismatch for changed search, any filter or sort;
- derived reading-status truth table;
- REST parsing rejects unknown parameters and values.

### Integration / MariaDB

- every approved search field, exact-identifier exemption and ranking class;
- active-only default and approved archive behavior;
- every approved filter alone and in approved combinations;
- OR within approved multi-value groups and AND between groups;
- Collection OR/no-duplicate/`Zonder collectie` only if explicitly approved;
- null/unknown behavior and inactive option behavior;
- tied and null sort keys over at least three pages;
- no duplicates, skips or order drift between pages;
- cursor replay under a changed query fails before results;
- actor reading status and tenant isolation;
- foreign Collection/Location IDs reveal no data;
- query-count budget and no N+1.

### REST

- old request without new parameters remains compatible;
- exact allowlisted query serialization and defaults;
- invalid types, values, cardinalities, combinations and unknown fields;
- malformed/tampered/unknown-version cursor;
- cursor plus changed query context;
- cookie/nonce, unauthenticated and foreign-Library behavior;
- safe non-enumerating errors and no internal leakage.

### Frontend

- search submit/debounce behavior after decision;
- filter disclosure, apply behavior, active chips and individual/all reset;
- sort selection and relevance interaction after decision;
- every query change resets cursor/results and aborts stale work;
- load-more preserves the exact query;
- loading, query-empty, error and retry states;
- Grid/List preserves the same query and results;
- URL/back-forward/session behavior after decision;
- Quick View and detail navigation regressions.

### E2E and accessibility

- find an Item beyond the old first cursor page;
- filter the complete catalog, not the fetched subset;
- combined filters and alternate sort after approval;
- load more within an active query and reset to default;
- foreign IDs/non-enumeration;
- Quick View regression;
- keyboard, focus, announcements, chips, mobile reflow and 200% strategy.

### Performance

- deterministic 1,000+ Item case and a 10,000 Item-per-Library case;
- selective and broad title/multi-field searches;
- each approved high-cardinality filter and combination;
- first/middle/last keyset page;
- query count and representative `EXPLAIN`/`EXPLAIN ANALYZE`;
- no complete catalog materialization in PHP or browser.

## 16. Reconciled decisions and remaining prerequisites

### Canonical Search contract

- strict authorized current-Library scope and active Items by default;
- Work title/alternative title, Auteur/Co-auteur, Serie, relevant ISBN and
  inventory number;
- exact title/ISBN, title, Auteur/Co-auteur, Serie and then other valid context,
  with alphabetical title inside equal relevance;
- minimum two characters for general queries, exact identifiers exempt;
- empty/absent query returns the normal title-ascending overview;
- every matching physical Item remains reachable; no hidden external search.

### Canonical Filter contract

- the current v2.001 overview does not expose a varying media-form filter:
  physical books are the only supported medium;
- the three reading statuses and all named metadata/domain values retain their
  definitions, but that does not itself approve them as filters;
- Collection membership is active same-Library physical Items only and an Item
  may belong to multiple Collections; filter operators remain open;
- filters change view only and never mutate underlying data or a saved default.

### Canonical Sort contract

- default/no-search order is Work title ascending;
- search uses the canonical relevance classes and then alphabetical title;
- stable unique tie-breakers and collation implementation are technical;
- no alternate Title, Author, Series, volume or date order is yet approved.

### Session/persistence contract

- effective initial view follows personal `Standaardweergave`, then Library
  default, then Biblio fallback;
- ordinary view/filter/sort controls do not silently rewrite permanent
  preferences or defaults; those change only through explicit settings;
- only temporary archive search has a complete current-session/reset contract;
  generic filter/sort session survival and URL behavior remain open.

### Archive interaction contract

- `Archief tonen` is personal per Library and defaults off;
- `Ook in archief zoeken` is temporary, does not change that preference and
  resets on refresh/navigation;
- archived hits are marked and archived Items are not active Collection
  members or available reading sources;
- the ordinary overview/filter/sort interaction with the personal preference
  remains incomplete.

### REAL OPEN PRODUCT DECISIONS

1. Which non-media filters ship in the first Mijn Bibliotheek release, and
   which are quick filters versus expanded filters?
2. For each approved filter, is selection single or multiple, is matching OR
   within a group and AND between groups, and how are missing/unknown values
   represented?
3. Does the Collection filter use multi-select OR, combine with other groups
   through AND, offer exclusive `Zonder collectie`, and omit archived
   Collections from normal options?
4. Should text matching use prefix, token or substring behavior, should accent
   differences be equivalent, and should contained Work titles in an omnibus
   match its Item?
5. Is Set search part of this Item-overview implementation scope, and may a
   chosen sort override relevance while a search is active?
6. Which alternate orders are offered: Title descending, Author, Series and/or
   date; for Series, what is the ordering source and where do unknown volume
   numbers appear?
7. Does search run live or after confirmation, do expanded filters apply
   immediately or via Apply, and what does reset clear?
8. Should ordinary filter/sort state survive in-module navigation during the
   session, and what are the URL and Back/forward semantics?
9. Does personal `Archief tonen` affect the initial ordinary overview and all
   filters, and how is that distinguished from temporary `Ook in archief
   zoeken`?

### Technical-only decisions

- case folding, Unicode/whitespace normalization and safe maximum query length;
- exact-identifier detection and ISBN/inventory punctuation normalization;
- stable ID/list transport, strict parsing and option-query DTO shape;
- sort-specific cursor tuple, version, fingerprint/HMAC choice and v1 bridge;
- duplicate-safe `EXISTS`/subquery SQL, collation, tie-breakers and indexes;
- query limits, cardinality caps, performance budgets and safe error mapping.

### Technical prerequisites

- central alternative titles, Author relations, Series relations/order and ISBN;
- Item inventory, Location, Condition, acquisition, loan and archive models;
- Collection aggregate/membership persistence and read options;
- explicit scoped option-query application boundaries;
- representative query/index proof after those schemas exist.

No assumption in this document closes those decisions.

## 17. Recommended implementation slicing

The complete feature is not responsible as one implementation commit.

Reconciliation removes separate product-decision work for empty-query behavior,
personal/default view resolution, the temporary archive-search lifecycle and
cursor/transport/SQL mechanics. It also removes media-form filters from the
v2.001 candidate set. It does not remove the metadata, Item-lifecycle and
Collection source slices, and it does not authorize filter or alternate-sort
implementation before the section-16 product answers exist.

1. **Prerequisite domain/persistence capabilities — High.** Implement only
   separately approved metadata, Author/Series, Item lifecycle/metadata and
   Collection sources with their own migrations, authorization and tests.
2. **Catalog query foundation — Medium.** Add the typed query model, strict REST
   parsing, canonical title/default/relevance behavior, the approved text-match
   contract, context-bound cursor v2 and repository query framework; retain
   backward compatibility.
3. **Approved filters and query optimization — High.** Add only decided filters,
   scoped option reads, duplicate-safe SQL, migrations/indexes justified by
   1k/10k evidence and security tests.
4. **REST/UI integration and E2E — High.** Activate toolbar controls, state,
   chips/reset, stale-request handling, chosen URL behavior, complete-catalog
   load-more, accessibility and browser acceptance.

If product scope is deliberately reduced to title-only search on current data,
that may be replanned as one Medium slice, but it is not the requested complete
Search/Filter/Sort feature and needs explicit approval.

## 18. Final readiness assessment

| Acceptance condition | Result |
|---|---|
| Every planned Search semantic sourced | **Partially proven** — fields/ranking/minimum/default browse are known; matching and result-surface interactions are open; implementation normalization is technical. |
| Every planned Filter semantic sourced | **Not proven** — underlying domain values are often canonical, but the exposed set and operators are not. |
| Every planned Sort semantic sourced | **Partially proven** — title-ascending default and search ranking are canonical; alternates are open. |
| Existing decisions reconciled | **Proven** in sections 3 and 16, including view/default and archive contracts. |
| Missing decisions isolated | **Proven** as nine answerable product questions in section 16. |
| Cursor/query-context direction clear | **Proven** as technical recommendation. |
| Core/REST/UI changes known | **Proven at layer/class level**. |
| Schema/index impact known | **Partially proven** — schema prerequisites are known; exact indexes await approved models and representative plans. |
| Security assessed | **Proven for readiness**, with implementation blockers/major risks explicit. |
| Test matrix complete | **Proven as an implementation plan**. |
| Implementation slices concretely bounded | **Proven**, but prerequisites prevent immediate start of the complete feature. |

**BLOCKED.** The reconciliation materially reduces the decision surface but the
first-release filter set/operators, alternate sorting, search-result behavior
and archive/state interaction are still fundamental parts of the requested
contract. Do not issue an implementation GO for the complete feature until the
section-16 product decisions are approved and the required source models are
either implemented or explicitly removed from the implementation scope.
