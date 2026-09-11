# 75 — MH-SEARCH-01B Local + Open Library Author/Work discovery

Status: **GO / CLOSED**

Post-closure note: MH-EDITION-01 now consumes its strong Work reference for
lazy pageable Editions without changing the MH-SEARCH-01B contract; see
`docs/76-mh-edition-01-lazy-editions-by-selected-work.md`.

Date: 2026-09-11

Task severity: **High**. The implementation audit preceded all changes; the
primary implementation and explicit independent review pass are separated. No
V1 source, snapshot or count is required.

## 1. Implementation audit

The existing `WpdbAuthorRepository` had canonical Author persistence and
read-by-ID but no text page. `WpdbWorkDiscoveryRepository` already established
stable Work title/ID pagination and title-or-linked-Author matching, while
MH-DISC-01 established normalized token matching and provider failure types.
MH-SEARCH-01A supplied the exact Author/Work result, page, reference, cursor and
capability-port contract but deliberately had no implementation or production
composition.

Open Library's official Search and Authors API documentation confirms separate
Author search and Work-first search endpoints, stable Author/Work keys and
`offset/limit` pagination. The Work search response itself contains the
allowlisted Work title and Author names, so neither a Work detail call nor an
Edition request is needed.

## 2. Local Author discovery

`WpdbBibliographicSearchProvider::searchAuthors()` searches existing canonical
Author display names. Every normalized whitespace token must match the display
name. Ordering is stable by display name and Author ID; pages contain ten items
plus an explicit nullable continuation and therefore do not imply a top-ten.

## 3. Local Work discovery

Local Work search requires every normalized token to match either canonical
Work title or a linked Author display name. It orders by Work title and Work ID,
then batch-loads ordered canonical Authors and reliable stored Series context.
It exposes no Edition, ISBN, publisher, publication date, binding or language.

## 4. Open Library Author discovery

The adapter calls only `/search/authors.json` with `q`, `limit` and `offset`.
Each accepted result requires a valid Open Library Author key and a bounded
display name. The result reference is provider-scoped and no Author entity,
biography, detail request or Works-by-Author query is created.

## 5. Open Library Work discovery

The adapter calls only `/search.json` with `fields=key,title,author_name`.
Each accepted result requires a valid Open Library Work key. Title and Author
names are the only presentation data; Series stays absent when the search
response does not provide confirmed typed context. No Edition fan-out, best
Edition, ISBN list or publication metadata is requested or inferred.

## 6. Pagination

Author and Work continuations remain independent. Open Library `offset` is
derived inside the adapter from the 01A cursor's presentation position. The
public contract retains only the signed, versioned, query-, group- and
lane-bound application cursor. `null` remains the explicit end state.

Local pages complete before external results continue. The orchestrator still
executes one external Author call and one external Work call when local results
exist, so local hits never become an external-search short circuit.

## 7. Ordering and deduplication

The 01A tuple `(local/external tier, presentation_order, strong result_id)`
remains authoritative. Local canonical results therefore precede Open Library;
Open Library relevance controls only its own presentation order.

Deduplication uses only canonical identity, the same provider-scoped entity
identity, or an existing Open Library Work-to-canonical-Work mapping against a
displayed local result. Same-name Authors and same-title Works without that
evidence remain distinct. No fuzzy or cross-provider merge exists.

## 8. Provider failure and miss

The result now carries typed provider attempts independently for Authors and
Works, using the existing `ProviderLookupStatus` and `ProviderFailureReason`
enums. Empty valid pages are `miss`; configuration, timeout, network, rate
limit, HTTP and malformed-response outcomes remain distinct. An external
failure produces an empty external lane but never removes the local page.

Production configuration uses the existing durable resolver:

```text
WordPress constant -> environment variable -> configuration_error
```

No provider setting is duplicated and no secret is logged or stored.

## 9. No-Edition-fan-out proof

The adapter unit test gives Work search exactly one queued HTTP response and
asserts one `/search.json` request whose URL contains no `editions`. Any second
request would fail the queue test. Production code contains no Edition endpoint
in the new adapter and requests only `key,title,author_name`.

## 10. Compatibility

MH-SEARCH-01B is exposed only through the parallel `CoreApplication` service.
It adds no REST route and no frontend decoder. MH-DISC-01 and its snapshots,
Wishlist/WISH-DISC, Add Book, `/me/works` and Hierna lezen remain unchanged.
Google Books is not composed into the new Author or Work lane.

## 11. Deferred handoffs

- `MH-AUTHOR-01`: pageable Works by the selected strong Author reference;
- `MH-EDITION-01`: lazy pageable Editions for one selected Work; and
- `MH-GBOOK-01`: any explicit Google Volume/Edition-lead path.

REST/UI reachability and Wishlist/Add Book consumer cutover remain separate.

## 12. Tests and quality gates

Recorded evidence:

- final full Core unit suite: `492 tests`, `2,075 assertions`; the two existing
  PHPUnit notices remain visible;
- final full Core integration suite: `412 tests`, `4,896 assertions`, including
  new local Author pagination and Work relationship projection coverage;
- full Core gate: Composer strict metadata, platform requirements, every PHP
  file's syntax, PHPStan, WordPress smoke, manifest JSON and whitespace passed;
  the recorded complete run finished in `468 seconds`;
- full Biblio UI smoke/contract suite: `251 passed`, including unchanged
  MH-DISC, Wishlist, Add Book and shared `/me/works` contracts;
- Open Library unit fixtures cover valid/malformed Author and Work pages,
  independent continuation, miss, timeout, network, rate limit and server
  failure, plus the one-request/no-Edition assertion; and
- the independent review found two evidence gaps—explicit Work provider
  continuation and dual-group normal miss—which were added before its final
  no-blocker verdict.

Deterministic synthetic/local fixtures and dedicated Open Library JSON fixtures
are the only data sources. No browser/E2E run was needed because no frontend
code or reachable route changed.

## 13. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record or count
was read, copied, interpreted or mutated. The slice is source-neutral.

## 14. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.7.0`;
- Biblio UI: `0.15.1`, unchanged.

## 15. Git

The final local commit, divergence, clean working tree and no-push status are
recorded in the completion report after all gates pass.
