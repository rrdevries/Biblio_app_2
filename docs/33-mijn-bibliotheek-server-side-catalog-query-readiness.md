# 33 — Mijn Bibliotheek server-side catalog query readiness

Status: **PRODUCT GO / TECHNICAL BLOCKED — first-wave prerequisite analysis**.

Date: 2026-09-03.

## 1. Context, scope and verdict

This document reconciles the final approved product decisions for server-side
Search, Filters, Sort and query-bound keyset pagination in `Mijn Bibliotheek`.
The original reconciliation was documentation-only. Schemas 1009 and 1010 now
close the Author/Series and remaining Search-metadata prerequisites, while the
current disabled controls and active-only title-ordered runtime remain
unchanged until separately approved implementation.

**Product readiness: GO.** The first implementation wave is fixed as
Leesstatus, Auteur, Serie, Locatie, Boeksoort, Genre, Onderwerp, Collecties and
`Zonder collectie`. No product decision remains for this wave.

**Technical implementation readiness: BLOCKED.** Schemas 1009 and 1010 provide
the central Author/Series and remaining Search-metadata foundations. The
current schema and read model still lack required first-wave Location, archive
and Collection sources. Existing reading, classification and bibliographic
sources also need query integration. These are technical prerequisites, not
product questions.

The first technical prerequisite, the central Author/Series relationship
foundation, is closed in
`docs/34-author-series-relationship-foundation-exit-evidence.md`. The second,
the remaining Search-metadata foundation, is closed in
`docs/35-remaining-search-metadata-foundation-exit-evidence.md`. The complete
query feature cannot receive implementation GO until all remaining applicable
prerequisite slices are closed.

## 2. Sources and authority

Authority order:

1. the final product decisions supplied on 2026-09-03;
2. `AGENTS.md`, `docs/00-current-state.md` and
   `docs/01-functional-design.md`;
3. accepted ADRs, especially ADR-001 through ADR-006;
4. `docs/02-architecture.md`, `docs/03-scope-and-deferred.md`,
   `docs/05-source-register.md` and `docs/06-testing-and-acceptance.md`;
5. F2.11/F2.12 and Vertical Slice 1A evidence in docs 18–21;
6. docs 26, 31 and 32;
7. current Core, REST, UI, tests and schema-1010 implementation.

`manifest.json` indexes this document and both foundation evidence records.

## 3. Final reconciliation matrix

| Onderwerp | Status vóór finalization | Definitief contract | Status nu |
|---|---|---|---|
| Library scope, authorization and physical Item identity | CANONICAL | Strict current-Library scope; every matching Item remains reachable; no virtual Item | CANONICAL |
| Search fields | CANONICAL | Work/alternative/contained title, Auteur/Co-auteur, Serie, relevant ISBN and inventory number | CANONICAL |
| Live Search | OPEN | Starts automatically from two characters; debounce/submit support technical; newest valid result wins | CANONICAL |
| Text matching | OPEN | Partial, case-insensitive and accent/diacritic-tolerant; exact identifiers may rank higher | CANONICAL |
| Empty query | CANONICAL | Normal browse result | CANONICAL |
| Ranking | CANONICAL/PARTIAL | Exact title/ISBN, title, Auteur/Co-auteur, Serie, other context; title inside equal relevance | CANONICAL |
| Omnibus contained-title/metadata match | OPEN | Omnibus Item is result with `Bevat:` context; no virtual contained Item | CANONICAL |
| Set result inclusion | OPEN | Not decided by the supplied final decisions | OPEN; outside current scope |
| General filter operators | OPEN | OR within one group; AND between groups unless explicitly excepted | CANONICAL |
| Direct filter application | OPEN | Every change automatically queries; no Apply button; technical batching allowed | CANONICAL |
| Active filter chips/reset | CANONICAL/PARTIAL | One removable chip per active value plus `Alle filters wissen` | CANONICAL |
| v2.001 media-form filters | CANONICAL | No varying filter: physical only; no E-book/Luisterboek filter | CANONICAL |
| First-release non-media filter groups | OPEN | Leesstatus, Auteur, Serie, Locatie, Boeksoort, Genre, Onderwerp, Collecties and `Zonder collectie` | CANONICAL |
| Deferred v2.001 filter groups | OPEN | Taal, Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds`; retained in v2.001 but outside the first wave | CANONICAL |
| Collection multi-select and operators | OPEN | Multiple; OR within Collection, AND across groups; at least one membership matches | CANONICAL |
| Collection duplicate behavior | TECHNICAL/PARTIAL | One Item at most once | CANONICAL |
| `Zonder collectie` | OPEN | Exists and is exclusive with ordinary Collection selections | CANONICAL |
| Collection option lifecycle | OPEN | Active Collections only as normal options; archived Collections excluded | CANONICAL |
| Title sort | CANONICAL/PARTIAL | `Titel A–Z`, default | CANONICAL |
| Author sort | OPEN | `Auteur A–Z` | CANONICAL |
| Series sort | PARTIAL | Only with active Serie filter; series/volume order; unknown volumes last | CANONICAL |
| Other sorts | OPEN | Not in v2.001 | CANONICAL |
| Search/sort interaction | OPEN | Search relevance, then title, overrides alternate sort while Search is active | CANONICAL |
| URL query state | OPEN | Search/filter/sort in stable allowlisted reproducible URL | CANONICAL |
| Browser/copy behavior | OPEN | Back/Forward restores query; copied URL opens same temporary query | CANONICAL |
| Session fallback | OPEN | Used only without explicit URL state; scoped user + Library + module | CANONICAL |
| Permanent preference boundary | CANONICAL | URL/session controls never silently save a permanent preference | CANONICAL |
| `Archief tonen` | CANONICAL/PARTIAL | Personal per Library, default off | CANONICAL |
| `Ook in archief zoeken` | CANONICAL | Temporary and never mutates the preference | CANONICAL |
| Archive result composition | OPEN | Active and archived Items share one list; archived Items labelled `Archief` | CANONICAL |
| Query objects, cursor, encoding, debounce, cancellation, SQL and indexes | TECHNICAL | Builder choice within this product contract | TECHNICAL |

Direct Set-result support was not included in the final decisions. Existing
Set rules remain valid, but Set persistence is absent and Set inclusion is not
required for this Item-overview scope. It becomes a product question only if a
later implementation explicitly proposes Set results here.

## 4. Final Search contract

### Fields and scope

- strict current-Library scope and server-authorized Library Context;
- active Items by default, expanded by the archive rules in section 8;
- Work title and alternative title;
- Auteur/Co-auteur;
- Serie;
- ISBN relevant to represented Library Items;
- inventory number;
- contained Work title, Auteur/Co-auteur and Serie for an omnibus.

No Notes, Review text, Collection descriptions or hidden external metadata
search is introduced.

### Matching and live behavior

- ordinary text uses partial matching;
- matching is case-insensitive and accent/diacritic-tolerant;
- Search runs live while typing from the canonical two-character minimum;
- exact identifiers are exempt from that general minimum and may receive an
  exact higher-ranked match;
- only the newest valid query may update results;
- Enter/submit may be supported but is not required to start Search.

Debounce duration, Unicode normalization, exact identifier recognition and
request cancellation versus stale-response rejection are technical choices.

### Ranking and sort interaction

Search result order remains:

1. exact title/ISBN;
2. title;
3. Auteur/Co-auteur;
4. Serie;
5. other valid context-specific match.

Within equal relevance, title is alphabetical. While Search is active this
canonical relevance order is authoritative: `Auteur A–Z` or `Serievolgorde`
does not override it. The selected eligible alternate sort becomes effective
again when Search is no longer active.

### Omnibus behavior

A match through a contained Work returns the physical omnibus Item and context
such as `Bevat: [titel]`. Underlying Work metadata may match only through the
already canonical fields above. A contained Work without a separate Item is
not rendered as a virtual Library Item. If a separate matching Item exists, it
appears normally as its own result.

## 5. Final Filter contract

### General operators

- values inside one filter group combine with OR;
- different filter groups combine with AND;
- a specific filter may deviate only through an explicit canonical exception;
- filter changes apply directly and issue a new full-catalog query;
- no `Toepassen` button exists;
- technical batching/debounce may preserve the functionally direct experience;
- each active value appears as an individually removable chip;
- `Alle filters wissen` clears all active filter values.

v2.001 has no varying form/drager filter. All supported Items are physical;
E-book and Luisterboek remain outside scope.

### Collection filter

- multiple active Collections may be selected;
- an Item matches membership in at least one selected Collection;
- Collection values use OR and combine with other groups through AND;
- one Item identity appears at most once;
- `Zonder collectie` exists and is exclusive with ordinary Collection values;
- only active Collections are normal options;
- archived Collections are not normal active options.

### First implementation wave

The first v2.001 implementation wave contains exactly:

- Leesstatus;
- Auteur;
- Serie;
- Locatie;
- Boeksoort;
- Genre;
- Onderwerp;
- Collecties;
- `Zonder collectie`.

Taal, Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds` remain part
of the broader v2.001 product design as **deferred within v2.001**. They are not
removed, rejected or future-version-only and do not block the first wave.

## 6. Final Sort contract

Available in v2.001:

1. `Titel A–Z` — default browse order;
2. `Auteur A–Z` — normal alternate order;
3. `Serievolgorde` — available only with an active Serie filter, using the
   canonical series name/volume order and placing unknown volume numbers last.

Not available in v2.001: newest-added, publication date, Location, Rating,
Title descending or any other unlisted order.

Stable Item-ID tie-breakers, primary-author projection, collation and keyset
tuples are technical. They must produce deterministic pages without changing
the product order.

## 7. URL and session query state

Search, filter and sort state is temporary query state, not a preference.

Resolution:

1. explicit allowlisted URL query state;
2. otherwise remembered session state for authenticated user + Library +
   module;
3. otherwise canonical/default state and saved preferences where applicable.

Back/Forward restores earlier query state. A copied URL opens the same query.
URL/session changes never update `Standaardweergave`, `Archief tonen` or
another permanent preference. Exact parameter names, list encoding, canonical
ordering and backward-compatible parser details are technical.

Every effective query change clears the old result/cursor and begins at page
one. Load-more uses exactly the same canonical query. Request generations and
abort/stale guards prevent old responses from replacing newer state.

## 8. Archive interaction

- `Archief tonen` is personal per Library and defaults off;
- `Ook in archief zoeken` is temporary and never rewrites that preference;
- if either is active, active and archived Items appear in one mixed list;
- there is no parallel Archive result group;
- every archived Item is visibly labelled `Archief`;
- Search, filters, sorting and keyset pagination apply to that one effective
  result set.

The precise visual styling of the Archive label remains UI implementation.

## 9. Current implementation delta

Current flow remains:

`browser → private REST overview → CatalogUiReadService`
`→ CatalogUiReadRepository → wpdb projection → allowlisted response`.

Current limitations:

- Core accepts only Library ID, page size and title/Item cursor;
- the repository supports only active title-ordered Items;
- REST has no strict full query allowlist or typed filter/sort parser;
- UI controls are disabled and route state contains only Library/Item IDs;
- schema 1010 has the remaining Search-metadata sources but still lacks
  Location, archive lifecycle and Collection sources plus query composition.

Required additions retain existing boundaries: Core owns query meaning,
Library Context, authorization and actor-private reading status; REST parses
transport; UI renders and manages temporary state. UI state and cursor data
never authorize.

## 10. Technical-only decisions

The implementing Builder may choose, within the final product behavior:

- immutable typed query-object composition and filter-specific value objects;
- cursor-v2 payload/encoding, version bridge and canonical query fingerprint;
- HMAC use as defense in depth, never as authorization;
- exact allowlisted URL parameter names and list encoding;
- debounce interval and cancellation versus generation-based stale rejection;
- Unicode/case/diacritic normalization implementation;
- exact identifier detection and punctuation normalization;
- REST DTO and scoped option-query shapes;
- SQL using `EXISTS`, unions or deduplicated subqueries;
- deterministic tie-breakers, explicit null buckets and collation;
- index strategy after representative query-plan measurement;
- bounded query/list limits and safe error mapping.

These choices may not change fields, partial matching, ranking, filter
operators, URL behavior, sort availability or archive composition.

## 11. Technical dependency matrix

Evidence is schema 1010, the current domain types and repositories, and the
active `WpdbCatalogUiReadRepository` projection.

| Feature | Required source/model | Exists now? | Gap/class | Required before query implementation? |
|---|---|---|---|---|
| Search: Work title | central Work title | Yes | **A — ALREADY EXISTS** | Yes; reusable now |
| Search: alternative title | central Work-title aliases | Yes | **A — ALREADY EXISTS**; query integration still to add | Yes; reusable now |
| Search/filter/sort: Auteur/Co-auteur | central Author identity plus ordered typed Work relationship | Yes | **A — ALREADY EXISTS**; query integration still to add | Yes; reusable now |
| Search/filter/sort: Serie | central Series identity plus Work membership and volume sort value | Yes | **A — ALREADY EXISTS**; query integration still to add | Yes; reusable now |
| Search: ISBN | Edition ISBN source including explicit no-ISBN/unknown distinction | Yes | **A — ALREADY EXISTS**; query integration still to add | Yes; reusable now |
| Search: inventory number | Library Item inventory identity | Yes | **A — ALREADY EXISTS**; authorized query integration still to add | Yes; reusable now |
| Search: omnibus metadata | ordered acyclic Work containment relationship | Yes | **A — ALREADY EXISTS**; Item-preserving query integration still to add | Yes; reusable now |
| Filter: Leesstatus | actor-owned ReadingRounds and derived Work status | Yes | **A — ALREADY EXISTS**; query predicate still to add | Yes; no new persistence |
| Filters: Boeksoort/Genre/Onderwerp | LibraryCatalogContext and typed Library terms/links | Yes | **B — EXISTS BUT READ MODEL MISSING** | Yes |
| Filter: Locatie | Library Item Location source | No | **D — DOMAIN FOUNDATION MISSING** | Yes |
| Filters: Collecties/`Zonder collectie` | Library-owned Collection and Item membership | No | **D — DOMAIN FOUNDATION MISSING** | Yes |
| Sort: Titel A–Z | Work title plus Item-ID tie-breaker | Yes | **A — ALREADY EXISTS** | Yes; reusable now |
| Archive/query context | Item active/archive lifecycle state | No; enum/schema allow active only | **D — DOMAIN FOUNDATION MISSING** | Yes |
| Deferred filters | language, publisher, lending, condition and acquisition sources | Mixed/absent | **F — NOT NEEDED FOR FIRST WAVE** | No |
| Scale | query-specific composite indexes and plans | Partly | **E — INDEX/PERFORMANCE ONLY** after query shape exists | Before technical GO, not before foundations |

No evidence supports treating placeholder fields in card/detail view objects as
production sources: the current repository deliberately hydrates most of them
as unavailable. Classification terms and Work-level assignments do exist, so
they need a query/read slice rather than a new domain foundation.

Performance acceptance ultimately requires deterministic 1,000- and
10,000-Item Library fixtures, selective and broad searches/filters,
first/middle/last keyset pages, query-count proof and representative
`EXPLAIN`/`EXPLAIN ANALYZE`. No full catalog materialization in PHP or browser
is allowed.

## 12. Remaining product decisions

**None.** The documented first wave and its operators, Search, Sort,
URL/session and Archive behavior are product-ready. Set results remain outside
this Item-overview scope. Deferred v2.001 filters do not block the first wave.

## 13. Recommended implementation slicing

The former single High prerequisite slice is too broad. It mixed central
bibliographic identity, Library-owned classifications, Item lifecycle and
Collections, each with different ownership and migration invariants.

### Slice 1 — central Author and Series relationship foundation

- **Severity:** High.
- **Status:** **GO / CLOSED** in schema 1009; see doc 34.
- **Dependency:** existing central Work identity, schema migration baseline and
  Core-owned domain rules.
- **Scope:** Author identities; typed Auteur/Co-auteur Work relationships;
  Series identities; Work membership and deterministic volume-order value;
  repositories, composition and migrations.
- **Schema impact:** forward schema-1009 candidate with new central tables,
  constraints and lookup/order indexes; exact version is chosen at build time.
- **Exit condition:** valid identities and relationships persist and reload,
  role/order/cardinality and duplicate invariants hold, migrations are
  idempotent/healthy, and cross-Library identity cannot grant Library access.
- **Outside scope:** Search/query DTOs, REST/UI, aliases, omnibus containment,
  ISBN, inventory, Location, Archive, Collections and deferred filters.

### Slice 2 — remaining Search metadata foundation

- **Severity:** High.
- **Status:** **GO / CLOSED** in schema 1010; see doc 35.
- **Dependency:** Slice 1 and central Work/Edition/Item identity.
- **Scope:** alternative Work titles, ISBN semantics, inventory number and
  omnibus containment needed by canonical Search.
- **Schema impact:** forward schema-1010 migration and search-supporting indexes.
- **Exit condition:** each source is canonical, persistence-tested and can
  expose exact Item-preserving omnibus matches without virtual Items.
- **Outside scope:** query execution, filters, REST/UI and deferred metadata.

### Slice 3 — Item Location and archive lifecycle foundation

- **Severity:** High.
- **Dependency:** current Item/Library ownership model.
- **Scope:** Library-scoped Location and active/archive Item lifecycle required
  by first-wave filtering and mixed archive results.
- **Schema impact:** forward migration(s), lifecycle constraints and
  Library/status/location indexes.
- **Exit condition:** transitions preserve history, authorization and tenant
  isolation; active/archive reads are deterministic.
- **Outside scope:** lending, condition, acquisition and query UI.

### Slice 4 — Collection and membership foundation

- **Severity:** High.
- **Dependency:** Item lifecycle and existing Library authorization.
- **Scope:** active/archived Library-owned Collections and duplicate-free
  same-Library active-Item memberships required for Collecties and
  `Zonder collectie`.
- **Schema impact:** forward migration(s), tenant foreign keys, uniqueness and
  membership-query indexes.
- **Exit condition:** membership cannot cross Libraries, inactive Items are not
  silently accepted, archive rules hold and membership reads are performant.
- **Outside scope:** smart Collections, desired additions, query DTOs and UI.

### Slice 5 — existing-source filter/read foundation

- **Severity:** Medium.
- **Dependency:** current ReadingRound and LibraryCatalogContext persistence.
- **Scope:** reusable Core read predicates/options for Leesstatus, Boeksoort,
  Genre and Onderwerp; no duplicate persistence.
- **Schema impact:** none expected; add indexes only with measured evidence.
- **Exit condition:** actor-private status and Library-scoped classifications
  are queryable with active-option semantics and tenant-safe tests.
- **Outside scope:** complete query object, REST and frontend controls.

### Slice 6 — typed query model, Search, Sort, filters and cursor

- **Severity:** High.
- **Dependency:** Slices 1–5.
- **Scope:** immutable query contract, first-wave predicates/options, ranking,
  three sorts, archive context, cursor v2 and representative optimization.
- **Schema impact:** query-specific indexes only when plans demonstrate need.
- **Exit condition:** complete first-wave combinations are deterministic,
  duplicate-free, tenant-safe, cursor-bound and proven at 1k/10k scale.
- **Outside scope:** deferred v2.001 filters, REST and frontend activation.

### Slice 7 — REST, URL/session UI and E2E

- **Severity:** High.
- **Dependency:** Slice 6 and existing F2.10–F2.12 boundaries.
- **Scope:** strict REST allowlist, stable URL/session state, live controls,
  Back/Forward/copy, mixed archive presentation, accessibility and E2E.
- **Schema impact:** none.
- **Exit condition:** full first-wave behavior, stale-response safety,
  non-enumeration, keyboard/focus/reflow and regression gates are green.
- **Outside scope:** deferred v2.001 filters and unrelated UI work.

## 14. Next technical slice implementation brief

**Title:** Central Author and Series Relationship Foundation

**Task severity:** High.

**Implementation status:** **GO / CLOSED** in schema 1009. The retained brief
below records the approved boundary; implementation evidence is in doc 34.

**Goal:** add the smallest complete central bibliographic foundation for
Auteur/Co-auteur and Serie relationships so later Search, filter and sort work
has authoritative sources.

**Why now:** Auteur and Serie each occur in Search, first-wave filters and
alternate sorting. They share central Work identity semantics and currently
have neither domain types nor persistence, making this the highest-leverage
coherent prerequisite.

**Canonical dependencies:** `AGENTS.md`; functional design sections on Work,
contributors and Authors/Series; architecture central-vs-local boundaries;
ADR-002 through ADR-005; ADR-006 only for the contrast with Library-local
classification; current schema-1008 migration conventions.

**Production scope:** immutable Author and Series IDs/entities; validated
display names; typed Work-contributor relationships limited to Auteur and
Co-auteur for v2.001; deterministic contributor order; Work-Series membership
with an optional validated volume-order value; repository ports, wpdb
adapters, composition wiring and explicit application boundaries needed to
create/read relationships without exposing unrestricted UI mutation.

**Schema/migrations:** add one forward migration after 1008 with separate
central Author, Work-contributor, Series and Work-Series tables; binary stable
IDs, foreign keys to Work, duplicate-preventing keys, role/order/volume
constraints and indexes for Author/Series lookup and series ordering. Preserve
existing data and use health-checked idempotent migration conventions.

**Application/Core impact:** Core owns validation, relationship roles,
ordering, duplicate rules and repository contracts. Do not fold these concepts
into LibraryCatalogContext or generic taxonomies.

**REST impact:** none.

**Frontend impact:** none.

**Security/ownership:** Author and Series identities are central, but their
existence grants no Library visibility. Any Library-facing read must still
start from authorized Library Context and reachable Items. Actor resolution is
server-side; unknown or inaccessible Library-scoped targets remain
non-enumerating. No cross-Library relationship may be inferred as access.

**Performance considerations:** index joins from Work to contributor/Series,
Author alphabetical keys and Series plus volume order. Prove no N+1 repository
loading and inspect representative plans before adding speculative indexes.

**Tests required:** domain validation; Auteur/Co-auteur role and contributor
ordering; duplicate relationships; optional/unknown volume ordering contract;
repository round trips; foreign-key and migration health/idempotence; rollback
on failure; central identity without cross-Library authorization leakage;
composition smoke and full relevant Core gate.

**Acceptance criteria:**

1. Author and Series identities and Work relationships persist and reload
   losslessly through typed Core ports.
2. Only canonical Auteur/Co-auteur roles are accepted and contributor order is
   deterministic.
3. A Work-Series relation supports the canonical known/unknown volume-order
   distinction without claiming external completeness.
4. Duplicate and dangling relations are rejected at domain/database layers.
5. The forward migration is health-checked, idempotent and preserves schema
   1008 data.
6. Central metadata cannot authorize or enumerate Library Items.
7. No REST, frontend or query behavior changes.

**Out of scope:** alternate-title aliases, omnibus containment, ISBN,
inventory, Location, Archive, Collections, Search/filter/sort execution,
deferred v2.001 metadata, automatic merge and external Series completeness.

**Suggested commit message:** `Add Author and Series relationship foundation`

**Completed next slice:** Remaining Search Metadata Foundation; see doc 35.

**Current expected next slice:** Item Location and Archive Lifecycle
Foundation.

## 15. Review

No separate reviewer agent was available. Four explicit independent passes
were performed.

### Biblio Product Guardian

- the exact first filter wave is canonicalized;
- deferred v2.001 filters remain present and are not reclassified;
- no product rule was added for technical storage or query mechanics;
- remaining product decisions: none.

### Biblio Architect

- the former broad prerequisite phase is split by central identity,
  Library-owned Item/Collection state and existing-source projection;
- Author/Series is the smallest high-leverage coherent first slice;
- no generic metadata model or broad catalog refactor is proposed.

### Biblio Security Reviewer

- central bibliographic identity is not Library authorization;
- Library-facing reads remain Item-reachable and Library-scoped;
- actor-owned reading status, tenant isolation and non-enumeration remain
  explicit in later query boundaries.

### Biblio Test Engineer

- every foundation slice has an independent migration/read/domain exit gate;
- the first brief includes domain, persistence, migration, composition,
  security and performance evidence;
- end-to-end query tests wait until all required sources exist.

## 16. Final readiness assessment

| Gate | Result |
|---|---|
| Search product contract | **GO** |
| First-wave filter/operator contract | **GO** |
| Sort product contract | **GO** |
| URL/session product contract | **GO** |
| Archive interaction product contract | **GO** |
| Remaining first-wave product decisions | **NONE** |
| Product readiness | **GO** |
| Technical implementation readiness | **BLOCKED — prerequisite foundations** |
| Architecture/security readiness | **READY WITH CONDITIONS** |

Further implementation may proceed only through separately approved technical
slices. After completed Slices 1 and 2, the expected next prerequisite is Slice
3, Item Location and Archive Lifecycle Foundation. The completed foundations
add no REST, frontend, Search/filter/sort query behavior or runtime fixture
data.
