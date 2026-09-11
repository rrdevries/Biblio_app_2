# 76 — MH-EDITION-01 Lazy Editions by selected Work

Status: **GO / CLOSED** after the recorded gates and independent review.

Date: 2026-09-11

Task severity: **High**. The implementation audit preceded every change; the
primary implementation and explicit independent review pass were separated.
No V1 source, snapshot or count was required.

## 1. Implementation audit

`BibliographicWorkReference` already represented exactly one canonical Work ID
or one typed provider-scoped Work identity. Canonical `EditionRepository`
reads existed only by Edition ID; there was no pageable Work-to-Editions read.
Schema 1023 already had `editions_by_work`, concrete Edition title and ISBN
columns. The existing provider-identity table could prove Open Library Work and
Edition mappings but its read port had no reverse Work-identity projection.

Open Library's official REST API documents
`/works/{work-id}/editions.json?limit={n}&offset={n}` with `size`, `links` and
concrete `entries`. The entries can carry title/subtitle, languages,
publishers, publication date, ISBNs, physical format and page count without a
second request.

## 2. Shared Edition contract

The additive `Application\Metadata\Search` contract contains a typed parent
Work reference, stable canonical or provider-scoped Edition reference,
provider-neutral metadata, `presentation_order`, explicit materialization
handoff capabilities, typed provider attempts and nullable continuation. Every
field other than the Edition and parent Work identity plus Edition title is
nullable or an empty bounded list.

The boundary is exposed as `CoreApplication::bibliographicEditionSearch()` and
requires an authenticated actor. It accepts no user, Library, Item, Wishlist
or provider selector. No REST route or UI consumer was added.

## 3. Local Edition discovery

`WpdbBibliographicEditionSearchProvider` reads only canonical Editions for the
exact canonical Work. It orders by concrete Edition title and Edition ID,
pages with `limit + 1`, projects stored ISBN identity and loads the existing
ordered Work contributors once per page. Unsupported canonical publication
metadata remains absent. No Item or possession join exists, so an Edition
without an Item remains discoverable.

## 4. Open Library Edition discovery

`OpenLibraryEditionSearchProvider` performs exactly one bounded HTTP GET for
the selected Work page. It neither searches Works nor requests Edition detail
per result. The top-level Work relation must equal the selected provider Work;
an explicit per-entry Work relation, when present, must contain that same Work.

Stable `/books/OL…M` identity and a valid title are required per record.
Malformed individual records are rejected while valid siblings remain. A page
whose provider records are all invalid is a typed malformed response, not a
normal miss.

## 5. Pagination and cursors

The signed version-1 opaque cursor binds:

- the exact selected Work reference, including provider evidence;
- the `local|external` lane; and
- the next provider-neutral continuation offset.

Malformed, tampered, wrong-Work, wrong-provider/reference and impossible local
cursor shapes fail closed. `null` is the explicit end state. A full final local
page hands off to external offset zero without making an early HTTP request.
There is no hidden total limit.

## 6. Edition metadata

The shared result represents concrete Edition title/subtitle, contributors,
language codes, publisher/imprint values, precision-preserving publication
date text, canonical ISBN-10/13, format/binding and page count when the source
supports them. Local canonical rows currently supply title, ISBN and existing
Work contributors only. Missing data is never fabricated or copied into Work.

## 7. Ordering and deduplication

Ordering is `(local/external tier, presentation_order, strong result_id)`.
Provider order is presentation only. Deduplication uses only canonical Edition
ID, canonical ISBN, same provider-scoped Edition ID or a proven provider-to-
canonical Edition mapping. Equal titles remain separate without that evidence.

## 8. ISBN-less Editions

An Open Library Edition without ISBN remains a valid concrete result when its
stable provider Edition ID, valid title and selected provider Work relation are
present. It retains Edition-specific materialization capability. ISBN is not a
display prerequisite and no materialization policy was redesigned.

## 9. Provider miss and failure

Provider attempts reuse the existing `ProviderLookupStatus` and
`ProviderFailureReason` taxonomy. Normal empty pages remain `miss`;
configuration, malformed response, timeout, network, HTTP and rate-limit
states remain distinct. When external lookup fails after local discovery, the
local page remains in the result with the typed failure beside it.

## 10. No-fan-out and request structure

One selected Work can trigger at most one HTTP request for one requested page:

```text
GET /works/{exact-open-library-work-id}/editions.json?limit={page-space}&offset={continuation}
```

There is no `/search` call, no second Work, no Google Books call and no
`/books/{edition-id}` enrichment. Unit transport queues fail on any unplanned
extra request and assert the exact two URLs across two requested pages.

## 11. Materialization handoff

External results carry provider Edition identity, provider Work-backed parent
context, retrieval time, existing `text_search` evidence classification,
complete allowlisted candidate metadata and explicit Work-only/Edition-
specific capabilities. This is enough for a later bounded adapter into the
existing generic MH-DISC snapshot/materializer flow. MH-EDITION-01 itself does
not snapshot or materialize and creates no canonical record.

## 12. Compatibility

MH-SEARCH-01A/01B, MH-DISC-01, Wishlist/WISH-DISC, Add Book and `/me/works`
remain unchanged. The new service is parallel and has no REST/frontend
consumer. Existing strict decoders therefore need no widening.

## 13. Deferred

Author-to-Works remains MH-AUTHOR-01. Google Volume/Edition leads remain
MH-GBOOK-01. REST/UI reachability, language filters, Wishlist cutover and Add
Book cutover remain separate consumer/transport slices.

## 14. Tests and quality gates

Recorded deterministic evidence:

- focused Edition contract/service: 11 tests, 44 assertions;
- focused Open Library adapter/configuration: 10 tests, 41 assertions;
- focused local persistence: 3 tests, 35 assertions;
- full Core unit suite: 513 tests, 2,163 assertions, with the two existing
  non-failing PHPUnit notices;
- full Core integration suite: 415 tests, 4,931 assertions;
- all Core PHP source/tests syntax and PHPStan pass;
- complete Core quality gate including WordPress smoke, Composer/platform,
  manifest and whitespace: PASS in 414 seconds;
- isolated Biblio UI smoke, PHP/JavaScript syntax and all 251 existing UI
  contract tests: PASS; and
- the independent final review found no remaining identity, pagination,
  failure, request-structure, compatibility or scope blocker.

An earlier overlapping integration invocation collided with the test harness'
shared database cleanup and produced `Unknown database`; it is not code
evidence. The later single serialized full run above passed cleanly.

## 15. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Synthetic canonical
records and deterministic Open Library response fixtures were used.

## 16. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.8.0`;
- Biblio UI: `0.15.1`, unchanged.

## 17. Git

MH-EDITION-01 is committed once locally after all gates and review. The final
commit, divergence, clean working tree and no-push status are reported in the
completion report.
