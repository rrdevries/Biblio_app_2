# 33 — Mijn Bibliotheek server-side catalog query readiness

Status: **PRODUCT BLOCKED / TECHNICAL BLOCKED — final product-contract pass**.

Date: 2026-09-03.

## 1. Context, scope and verdict

This document reconciles the final approved product decisions for server-side
Search, Filters, Sort and query-bound keyset pagination in `Mijn Bibliotheek`.
It changes documentation only. The current disabled controls and active-only
title-ordered runtime remain unchanged until separately approved implementation.

**Product readiness: BLOCKED.** All supplied decisions are canonicalized, but
one product-scope question remains unanswered: which non-media filter groups
ship in the first v2.001 implementation. Operator behavior is complete once
that list is chosen.

**Technical implementation readiness: BLOCKED.** The current schema and read
model still lack most required Author, Series, alternative-title, ISBN,
inventory, Location, Condition, acquisition, loan, archive and Collection
sources. This is a technical prerequisite, not a second product question.

The Search/Sort/query foundation may be prepared after its own approved scope,
but the complete feature cannot receive implementation GO until the one
remaining product choice and required data-source slices are closed.

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
7. current Core, REST, UI, tests and schema-1008 implementation.

`manifest.json` already indexes this document. Its status records require an
update because the readiness classification changes, but the index does not.

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
| First-release non-media filter groups | OPEN | No definitive list supplied or present in repository | OPEN |
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

### Remaining filter-scope decision

The repository contains canonical meanings for reading status, Auteur, Serie,
Location, language, publisher, lending status, Condition, acquisition date,
Boeksoort, Genre and Onderwerp. Neither the repository nor the final decisions
selects which of those become filters in the first v2.001 implementation.

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
- schema 1008 lacks most required source models.

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

## 11. Technical prerequisites and performance

Schema 1008 currently provides Work title, Item/Edition/Work identity,
LibraryCatalogContext classifications and actor ReadingRound data. It lacks
reliable production sources for alternative/contained titles, Author, Series,
ISBN, inventory, Location, Condition, acquisition, InternalLoan, Archive and
Collections.

Consequences:

- the complete feature requires forward migrations and source-specific domain
  contracts before those fields can be queried;
- Collection membership needs Library-scoped persistence and indexes;
- Author/Series/omnibus relations need central identity and representation;
- archive and lending filters need lifecycle sources, never UI simulation;
- actor-private reading status remains derived, not stored as catalog truth.

Performance acceptance requires deterministic 1,000- and 10,000-Item Library
fixtures, selective and broad searches/filters, first/middle/last keyset pages,
query-count proof and representative `EXPLAIN`/`EXPLAIN ANALYZE`. No full
catalog materialization in PHP or browser is allowed.

## 12. REAL OPEN PRODUCT DECISIONS

1. Which non-media filter groups ship in the first v2.001 implementation:
   reading status, Auteur, Serie, Location, language, publisher, lending status,
   Condition, acquisition date, Boeksoort, Genre, Onderwerp and/or Collections?

No other product question blocks the documented Search, operator, Collection,
Sort, URL/session or Archive behavior. Set results remain outside this scope.

## 13. Implementation plan

### Slice 1 — prerequisite domain and persistence sources

- **Severity:** High.
- **Scope:** approved Author/Series/alternative-title/omnibus, Item metadata and
  lifecycle, Collection and other selected filter sources; forward migrations.
- **Dependency:** final filter-group selection and existing migration/security
  architecture.
- **Exit condition:** canonical sources, Library/ownership constraints,
  migrations, representative indexes and Core tests are green.

### Slice 2 — typed query model, Search, Sort and cursor

- **Severity:** Medium.
- **Scope:** immutable query model; live partial Search; ranking; omnibus result
  context; title/author/conditional-series sorts; cursor v2 and compatibility.
- **Dependency:** relevant Slice-1 source models; existing F2.10–F2.12 boundary.
- **Exit condition:** deterministic multi-page results, query-context-safe
  cursor, strict typed semantics and 1k/10k Search/Sort proof.

### Slice 3 — filters, option reads and query optimization

- **Severity:** High.
- **Scope:** only selected filters; OR/AND operators; Collection semantics;
  direct apply; chips/reset; scoped option reads and optimized SQL.
- **Dependency:** final filter list plus Slice 1 and typed query model.
- **Exit condition:** all filter combinations are tenant-safe, duplicate-free,
  cursor-stable and performant at representative scale.

### Slice 4 — REST, URL/session UI and E2E

- **Severity:** High.
- **Scope:** strict REST query allowlist; stable URL/session state; live UI;
  Back/Forward/copy; archive mixed list; accessibility and guarded browser tests.
- **Dependency:** Slices 1–3 and existing Biblio UI/route conventions.
- **Exit condition:** complete-catalog behavior, stale-response safety,
  keyboard/focus/reflow, non-enumeration, regressions and cleanup are green.

The four-slice shape remains appropriate because missing source models and
high-cardinality query behavior carry different migration, security and
performance risks from adapter/UI activation.

## 14. Review

No separate reviewer agent was available. Two explicit independent passes were
performed.

### Biblio Product Guardian

- all supplied final decisions are represented without adding filters or Set
  behavior not approved by the source;
- old canonical Library scope, fields, ranking, default order, preferences,
  archive distinction and physical-only scope remain intact;
- one genuine filter-scope question remains explicit;
- debounce, encoding, SQL and normalization mechanics remain technical.

### Biblio Architect

- the contract is implementable through typed Core query boundaries;
- Library Context, actor ownership, private reading status and
  non-enumeration remain server-side;
- URL, session, cursor and UI state never authorize;
- four slices separate migrations/source truth, query semantics, complex
  filters/performance and adapter/UI/E2E risk;
- no hidden conflict requires a new ADR in this documentation round.

## 15. Final readiness assessment

| Gate | Result |
|---|---|
| Search product contract | **GO** |
| Collection/operator product contract | **GO** |
| Sort product contract | **GO** |
| URL/session product contract | **GO** |
| Archive interaction product contract | **GO** |
| First-release filter-group scope | **BLOCKED — one explicit product answer** |
| Product readiness | **BLOCKED** |
| Technical implementation readiness | **BLOCKED — prerequisite source models and migrations** |
| Architecture/security readiness | **READY WITH CONDITIONS** |

Implementation may start only through a separately approved slice. The full
feature receives product GO after the section-12 filter list is answered and
technical GO only after the relevant source-model prerequisites pass their own
exit conditions.
