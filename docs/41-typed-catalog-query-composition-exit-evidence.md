# 41 — Typed Mijn Bibliotheek catalog query composition exit evidence

## 1. Verdict and scope

Status: **GO / CLOSED — READY FOR CATALOG TRANSPORT / REST INTEGRATION**.

Slice 6 adds one authoritative Core application boundary for the composed
`Mijn Bibliotheek` catalog. It implements no REST parser, serializer, endpoint,
frontend, Elementor, URL/session state or recommendation/suggestion engine.
Schema remains **1013 → 1013** with no migration, index or data rewrite.

## 2. Source map and query architecture

| Concern | Authoritative source | Composition |
|---|---|---|
| Primary identity/scope | Library Item joined to Edition and Work | tenant-scoped active or mixed active/archive preselection; one row per Item |
| Search | Work/alternate/contained title, Author/Co-author, Series, Edition ISBN and Item inventory | existence tests and deterministic relevance; contained match returns the omnibus Item |
| Author/Series filters and sorts | central Work relationships | semijoins for filters; ordered scalar projection only for the selected sort |
| Location | Library Item relation | direct tenant-safe Item predicate; batch result enrichment |
| Boeksoort/Genre/Onderwerp | LibraryCatalogContext and Library terms | tenant-scoped active-term semijoins; three-query batch enrichment |
| Collections | Library Collection memberships | active Collection plus active membership semijoin; historical rows never match |
| Leesstatus | actor-owned ReadingRounds | actor-scoped existence predicates; one-query batch projection after selection |

`CatalogQueryService` authorizes through `LibraryContextQueryService`, resolves
the authenticated actor server-side, delegates primary selection to
`WpdbCatalogQueryRepository`, and enriches only selected page IDs through the
existing bounded source services. Library ownership and actor-private history
are jointly presented but never merged into one ownership model.

## 3. Typed contracts and product semantics

`CatalogQuery` is immutable and combines `CatalogFilters`, `CatalogQuerySort`,
`CatalogOverviewPageSize`, optional `CatalogSearchTerm`,
`CatalogArchiveScope` and optional `CatalogQueryCursor`. Filters accept only
typed unique value objects. `CatalogQueryPage` returns ordered typed
`CatalogQueryItem` values with stable Item, Work and Edition identity,
canonical relationship context, personal reading status and an optional next
cursor; an empty result is an empty list with no cursor.

The exact first wave is Leesstatus, Auteur, Serie, Locatie, Boeksoort, Genre,
Onderwerp, Collecties and exclusive `Zonder collectie`. Values inside one
group use OR; different active groups use AND. Missing metadata and unknown,
inactive or foreign Library-local IDs do not match. Relation filters use
semijoins, so multiple Authors, Series, Genres, Subjects or Collections cannot
multiply the primary Item row.

Browse order is exactly title A–Z (default), Author A–Z and Series/volume order.
Author and Series null buckets sort last. Series order requires an active
Series filter and unknown volume sorts last. Title and stable Item ID complete
every browse key. Search overrides browse sort with relevance ranks: exact
title/ISBN, title, Author/Co-author, Series, then inventory/other canonical
context; title and Item ID break ties.

Active-only is the default. `ActiveAndArchived` produces the already canonical
single mixed list; it neither creates an Archive group nor changes lifecycle
truth. Active Collection filters require active Collection and membership.
Archived Collections and historical membership cannot match.

## 4. Cursor, authorization and privacy

`CatalogQueryCursorCodec` HMAC-authenticates a versioned payload containing a
canonical query fingerprint and the last Item ID. The fingerprint binds actor,
Library, Search, every filter, sort, page size and archive scope. It leaks no
sort tuple or personal data. Continuation resolves that Item again inside the
same effective query before applying keyset predicates; tampered, cross-actor,
cross-Library, changed-query and stale-anchor cursors fail closed.

Every primary query starts with `library_id` and Item lifecycle scope. Dynamic
SQL fragments come only from typed enums and fixed templates; all values are
prepared. Central Work metadata never grants Item visibility. Classification,
Location and Collection reads remain tenant-scoped, and Reading status uses
only the current server-resolved actor. Unauthorized/foreign Library Context
is rejected through the existing non-enumerating authorization boundary before
catalog persistence access.

## 5. Query count and representative plans

A normal first page containing Author, Series, classification, Location,
Collection and reading-status context uses exactly **15 database calls**.
Continuation uses **16**, adding one query to resolve the cursor anchor.
Selection, relationship maps, Library-local source maps and personal status are
bounded batch calls; Author/Series entity fetches are skipped when no IDs are
present. Empty result enrichment performs no source calls. No call scales with
the number of Items in the selected page and no full Library is materialized in
PHP.

Controlled MariaDB fixtures measured the same eight representative scenarios at
1,000 and 10,000 Items. The selected Item plan used existing index
`items_by_library_status_location`; estimated rows were 1,000/5,000 for broad
queries and 500/5,000 for Location. Supporting local timings were:

| Scenario | 1,000 Items | 10,000 Items | Plan note |
|---|---:|---:|---|
| default title | 3.172 ms | 28.532 ms | temporary/filesort for canonical title order |
| Location | 1.446 ms | 12.350 ms | index condition; temporary/filesort |
| Author filter | 1.813 ms | 17.653 ms | semijoin; temporary/filesort |
| Author sort | 5.548 ms | 58.464 ms | derived primary Author; temporary/filesort |
| Genre + Collection | 2.957 ms | 101.413 ms | materialized tenant semijoins; no outer filesort |
| Series order | 2.599 ms | 21.585 ms | temporary/filesort for derived volume order |
| Search | 8.475 ms | 60.772 ms | canonical multi-source relevance; temporary/filesort |
| next title page | 2.635 ms | 25.919 ms | keyset predicate; temporary/filesort |

The 10k combined relational case was initially about 1.2 seconds with
correlated predicates. Independent review classified that as major; replacing
those predicates with tenant-scoped materializable semijoins reduced it to
101.413 ms in the final quality-gate run without schema change. Filesorts/temp
tables are expected for derived canonical orders and bounded by Item keyset
selection. No blocking full-table plan or need for schema 1014 was found.

## 6. Verification and independent review

Targeted proof covers typed defaults/validation, query-bound HMAC cursor,
tampering and context changes, every first-wave filter, OR/AND algebra,
cardinality, all three sorts, equal keys, first/next/last/empty pages, stale
anchor rejection, Search fields/ranking/accent handling/omnibus identity,
active/archive and Collection lifecycle, tenant and actor isolation, constant
query count, production composition and 1k/10k plans.

The complete unit, MariaDB integration, migration, PHP syntax, PHPStan,
Composer metadata/platform, WordPress smoke, manifest and whitespace gates are
the final commit gate. Exact final counts are recorded in the completion
report.

Independent review dimensions:

- architecture: one application boundary and two-phase query/enrichment; no
  parallel source truth;
- security/privacy: server actor and Library authorization, prepared values,
  tenant predicates and actor-private status isolation;
- query correctness: canonical Search/filter/sort/archive behavior preserved;
- filter algebra/cardinality: OR/AND explicit and Item identity never deduped
  after a cross-product;
- cursor/pagination: deterministic keysets, authenticated query binding and
  fail-closed stale anchors;
- performance/queryplan: initial major resolved by semijoins; no open major;
- test/regression: focused contract tests plus complete existing gates;
- Product Guardian: no new filter, sort, Archive/Collection/reading-status,
  permission, UI or suggestion behavior.

## 7. Remaining boundary

Slice 7 may add strict REST request parsing/serialization, safe error mapping,
URL/session UI state, live controls, archive labels and E2E/accessibility proof
on top of this Core contract. Deferred filters and What Shall I Read remain
separate future scopes.
