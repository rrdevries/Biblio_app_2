# 33 — Mijn Bibliotheek server-side catalog query readiness

Status: **BLOCKED — readiness analysis only**.

Date: 2026-09-03.

## 1. Context, scope and verdict

This analysis defines what is known and what is still missing before server-side
Search, Filters, Sort and context-bound keyset pagination may be implemented for
the existing `Mijn Bibliotheek` active-Item overview.

No production code, schema, REST route, UI behavior, Elementor configuration or
runtime data is changed by this slice. The current route and UI remain limited to
the existing active overview ordered by Work title and Item ID.

**Verdict: BLOCKED.** The repository contains canonical Library-search fields
and ranking direction, but it does not contain a sufficiently complete product
contract for the requested filters, alternate sort options or their interaction.
Most canonical search fields and almost every requested filter also lack a
reliable persisted source in schema 1008. The complete feature therefore cannot
enter implementation without product decisions and prerequisite catalog,
Collection and Item-lifecycle data capabilities.

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

`manifest.json` lists docs through 32 and no later canonical catalog-query
source. Document 33 is therefore the next free documentation number.

## 3. Canonical source matrix

`Definitief` means the cited source fixes the behavior. `Open` means the source
describes a domain value or visual affordance but does not fix the filter/sort
contract. `Niet beschikbaar` means current schema 1008 cannot supply the value.

| Onderwerp | Canonieke bron | Besluit | Status |
|---|---|---|---|
| Search scope | Functional design §§5 and 15; testing §12; ADR-001/003 | Strict current-Library scope; active Items by default; all matching copies remain reachable. | Definitief |
| Search fields | Functional design §§5 and 15 | Work title/alternative title, Auteur/Co-auteur, Serie, relevant Item ISBN and inventory number. | Definitief; only Work title is currently persisted |
| Search ranking | Functional design §15 | Exact title/ISBN, title, Auteur/Co-auteur, Serie, other valid contextual match; equal relevance uses alphabetical title. | Definitief; implementation detail within each class remains open |
| Search length | Functional design §15 | General minimum two characters; exact identifiers are exempt. | Definitief at principle level; identifier recognition and maximum are open |
| Filter leesstatus | Functional design §§5–6 | The three actor-private derived states are canonical. Whether/how they are exposed as a filter is not specified. | Open |
| Filter vorm | Functional design §§1 and 4; AGENTS.md | v2.001 supports physical books only. No varying form source exists in the overview. | Not a meaningful current filter; open for later media scope |
| Filter locatie | Functional design §5 | Location is Library-owned and optional, but no Mijn Bibliotheek filter contract or persistence exists. | Open / not available |
| Filter collectie | Functional design §10; roadmap A-02 | Collection membership is active same-Library physical Items only. Filter selection/cardinality/OR behavior is not canonicalized. | Open / not available |
| Zonder collectie | No current canonical source | No filter behavior or exclusivity contract is recorded. | Open / not available |
| Filter Auteur | Functional design §§4, 11 | Auteur/Co-auteur identity is canonical, but the filter contract and persistence are absent. | Open / not available |
| Filter Serie | Functional design §§4, 11 | Series identity is canonical, but the filter contract and persistence are absent. | Open / not available |
| Filter taal | Functional design §4 | Edition language semantics exist; filter semantics and persistence do not. | Open / not available |
| Filter uitgever | Functional design §4 | Publisher is Edition metadata; filter semantics and persistence do not. | Open / not available |
| Filter uitleenstatus | Functional design §§5 and 8 | Availability/loan meanings exist; filter semantics and internal-loan persistence do not. | Open / not available |
| Filter conditie | Functional design §4 | Item Condition values exist; filter semantics and persistence do not. | Open / not available |
| Filter toevoeg-/aanwinstdatum | Functional design §5 | Business acquisition date and technical registration time are distinct. Which one filters/sorts and its range semantics are open; neither is persisted. | Open / not available |
| Classification filters | Functional design §5; ADR-006 | Boeksoort/Genre/Onderwerp and scoped IDs exist, but no overview filter contract is canonical. | Open; data available |
| Sort titel | F2.11 docs 00/18; current Core and UI placeholder | Current overview order is Work title ASC, Item ID ASC. | Definitief for the current default |
| Search result order | Functional design §15 | Relevance classes, then alphabetical title. | Definitief direction; exact scoring/ties beyond Item ID are technical work |
| Sort auteur | No current canonical source | No alternate Author sort, primary-author rule or null order is fixed. | Open / not available |
| Seriesortering | Functional design §11; roadmap B-12/C-01 | No external series order/completeness truth is fixed. | Open / not available |
| Datum-sorteringen | No current canonical source | No acquisition/registration/publication sort is approved for this overview. | Open / not available |
| Archief-context | Functional design §§5, 9, 15 and 17 | Search defaults active; temporary `Ook in archief zoeken` resets on refresh/navigation and is separate from personal `Archief tonen`, default off. | Definitief semantically; archive persistence/preferences are not available |
| Toolbar/chips | Design System §12; doc 32 | Toolbar has Search, Filters, Sort and view switch; active filters use removable chips. Controls remain deferred until the full-catalog server contract exists. | Definitief presentation direction |

The task description mentions multi-Collection OR semantics, no duplicates and
exclusive `Zonder collectie`. The repository search found no canonical source
for those filter semantics. They are therefore not silently promoted to product
truth by this analysis.

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
| Contained Work title in omnibus | Insufficient source proof | Omnibus relation absent | **OPEN PRODUCT DECISION:** whether it matches the container Item and how the hit is labelled/ranked. |
| Set title/ISBN | Canonical for Set search, but not for this Item route | Set model absent | **OPEN PRODUCT DECISION:** whether Set results belong in this `/items` overview or a separate result model. |
| Publisher/language/classification/free text | Not canonical Library-search fields | Partial/no sources | Do not add without a product decision. |

Canonical behavior already known:

- strict Library scope and current collection-view authorization;
- active Items by default;
- no copy deduplication;
- relevance class ordering and alphabetical title within equal relevance;
- general minimum two characters, exact identifiers exempt;
- no hidden external metadata search;
- no Notes/review/Collection-description free-text search.

**OPEN PRODUCT DECISIONS:** case behavior as product contract; whitespace
normalization; substring versus prefix/token matching; accent/diacritic
equivalence; maximum query length; empty `q` behavior; exact identifier
recognition; punctuation/hyphen normalization for ISBN/inventory; multi-author
match presentation; contained-title semantics; and whether an explicit
alternate sort may override relevance while search is active.

The current `work_title` collation is `utf8mb4_unicode_520_ci`, so current SQL
comparison is case-insensitive and generally accent-insensitive. That database
fact is not a product decision and must not become one accidentally.

## 6. Filter contract analysis

No requested filter has a complete canonical UI/query contract. The following
questions must be decided per exposed filter before implementation:

1. quick filter or expanded filter;
2. single or multi-select;
3. OR or AND within the filter group;
4. AND relationship to other groups;
5. null/unknown inclusion and label;
6. active-only versus archive interaction;
7. stable ID versus normalized value transport;
8. source and option-list visibility.

Technical availability today:

- **Reading status:** derivable server-side from actor-owned ReadingRounds as
  `reading`, `read` or `not_read`; it must remain private and never be stored as
  a second catalog truth.
- **Boeksoort/Genre/Onderwerp:** Library-scoped IDs and Work-context junctions
  exist with useful Library-leading indexes. The product has not approved these
  as Mijn Bibliotheek filters.
- **Form:** all v2.001 Items are physical books; the DTO's known value is not a
  variable persisted classification.
- **Location, Author, Series, language, publisher, loan status, Condition,
  acquisition, Collections and archive:** no reliable current source.

### Collections

Canonical Collection membership boundaries are usable prerequisites: active
Items in the same Library only; one Item may belong to multiple Collections;
archived Items are not current members; archived Collections are read-only.

The proposed target behavior—one or multiple selected Collections, OR within
that group, no duplicate Item rows, exclusive `Zonder collectie`, active
Collections only, active Items only and AND with other groups—needs an explicit
product decision because the current repository does not record it. A future
query should use `EXISTS` predicates or a deduplicated Item-ID subquery rather
than a multiplicative membership join.

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

### 7.2 Open alternate orders

| Sort | Required tuple if later approved | Current blocker |
|---|---|---|
| Title descending | `title DESC, item_id DESC` | Not canonically offered. |
| Author | normalized/display Author key, title, Item ID | No primary-author/null rule, no data. |
| Series | Series key, canonical volume/order key, title, Item ID | No order/completeness model or data. |
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

**OPEN PRODUCT DECISIONS:** URL persistence versus component/session state;
Back/forward semantics; search debounce versus explicit submit; whether filter
changes apply immediately or via Apply; reset scope; and whether view state is
remembered. Existing `route-state.js` supports only Library/Item identity and
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
| `q` | string | optional | Canonical UTF-8 search normalization; min 2 except recognized exact identifier; maximum open | Included in fingerprint | Search fields approved; normalization blocked |
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
| `include_archived` | boolean | optional | Temporary search-session behavior; separate from preference | Included | Blocked archive data/UI interaction |
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
was performed against architecture, security, privacy and regression risk.

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

## 16. Product decisions and technical prerequisites

### Proven

- Library-scoped authorized search and active default;
- canonical search fields and ranking classes;
- two-character general minimum with exact-identifier exemption;
- all physical copies remain reachable;
- temporary archive-search concept;
- current title ASC + Item-ID keyset order;
- derived actor-private reading-status meanings;
- toolbar/chip presentation direction.

### OPEN PRODUCT DECISIONS

1. search case/whitespace/partial/accent/punctuation and maximum-length rules;
2. contained-title/omnibus and Set-result behavior;
3. empty query and relevance-versus-user-sort interaction;
4. which filters actually ship in Mijn Bibliotheek;
5. quick/expanded placement, cardinality, group operators and null behavior for
   every filter;
6. Collection OR/exclusivity/active-option filter semantics;
7. alternate Title, Author, Series and date sorts including null/tie rules;
8. URL/session persistence, Back/forward, debounce/submit, apply/reset and view
   persistence;
9. interaction between temporary archive search, `Archief tonen`, filters and
   ordinary browsing.

### Technical prerequisites

- central alternative titles, Author relations, Series relations/order and ISBN;
- Item inventory, Location, Condition, acquisition, loan and archive models;
- Collection aggregate/membership persistence and read options;
- explicit scoped option-query application boundaries;
- representative query/index proof after those schemas exist.

No assumption in this document closes those decisions.

## 17. Recommended implementation slicing

The complete feature is not responsible as one implementation commit.

1. **Prerequisite domain/persistence capabilities — High.** Implement only
   separately approved metadata, Author/Series, Item lifecycle/metadata and
   Collection sources with their own migrations, authorization and tests.
2. **Catalog query foundation — Medium.** Add the typed query model, strict REST
   parsing, title/default/relevance contract that is then approved, context-bound
   cursor v2 and repository query framework; retain backward compatibility.
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
| Every planned Search semantic sourced | **Partially proven** — fields/ranking/minimum are known; matching/normalization/interactions are open. |
| Every planned Filter semantic sourced | **Not proven**. |
| Every planned Sort semantic sourced | **Partially proven** — current title order and search ranking only. |
| Missing decisions isolated | **Proven** in section 16. |
| Cursor/query-context direction clear | **Proven** as technical recommendation. |
| Core/REST/UI changes known | **Proven at layer/class level**. |
| Schema/index impact known | **Partially proven** — schema prerequisites are known; exact indexes await approved models and representative plans. |
| Security assessed | **Proven for readiness**, with implementation blockers/major risks explicit. |
| Test matrix complete | **Proven as an implementation plan**. |
| Implementation slices concretely bounded | **Proven**, but prerequisites prevent immediate start of the complete feature. |

**BLOCKED.** Do not issue an implementation GO for the complete feature until
the section-16 product decisions are approved and the required source models are
either implemented or explicitly removed from the implementation scope.
