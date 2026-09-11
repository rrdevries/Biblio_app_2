# 77 — MH-AUTHOR-01 Pageable Works by selected Author

Status: **GO / CLOSED** after the recorded gates and independent review.

Date: 2026-09-11

Task severity: **High**. The implementation audit preceded every change and an
explicit second review pass was performed as an independent reviewer after
implementation. No current or historical V1 source, snapshot or count was
needed.

## 1. Implementation audit

`BibliographicAuthorReference` already represented exactly one canonical
Author ID or one typed provider-scoped Author identity. Canonical Work reads
could traverse `work_contributors`, but no exact pageable Author-to-Works read
existed. The canonical contributor ontology contains only `author` and
`co_author`; no translator, illustrator, editor or Edition-specific role can be
persisted as a Work Author relation.

Open Library officially documents
`/authors/{author-id}/works.json?limit={n}&offset={n}`. A live read-only contract
check confirmed `size`, `links`, `entries`, stable Work keys, titles and Author
keys. The endpoint does not provide contributor display names, so external
results keep the existing Work `authors` list empty rather than inventing a
name or issuing an extra request.

## 2. Shared Works-by-Author contract

The additive `Application\Metadata\Search` boundary contains a typed selected
Author, the existing provider-neutral `BibliographicWorkSearchResult` items,
typed provider attempts and nullable continuation. It is exposed as
`CoreApplication::bibliographicAuthorWorkSearch()` and requires an authenticated
actor. It accepts no target user, Library, provider selector or free query.

The serialized Work shape is unchanged: result identity/kind, optional
canonical Work ID, title, Authors and Series. It contains no ISBN, publisher,
publication date, binding, language, page count or other Edition data.

## 3. Local Author to Works

The local provider joins the exact canonical Author ID to canonical Works using
only `author|co_author` rows. It orders by Work title then Work ID, reads
`limit + 1`, and exposes an explicit next offset without a hard top-ten.
Co-authored Works remain present and project every ordered canonical Work
Author, not only the selected Author.

Each page uses one Author-existence check, one bounded Work query, one Authors
batchquery and one Series batchquery. The test fixture records exactly four SQL
reads for ten Works; no per-Work query exists. An unknown canonical Author
fails closed instead of becoming a false normal miss.

## 4. Open Library Author to Works

The adapter accepts only an Open Library Author identity and performs one exact
Author-to-Works GET per requested external page. Top-level Author context and
each accepted Work's Author relation must contain the selected Author key.
Every Work requires a stable `/works/OL…W` key and a valid bounded title.

Invalid individual records are rejected while valid siblings remain. A page
whose provider records are all unusable becomes typed malformed. Work/Edition
search, Author search, detail enrichment and Google Books are absent.

## 5. Contributor semantics

Canonical `author` and `co_author` roles both establish membership. The schema
rejects unsupported contributor roles, and the read also allowlists the two
canonical roles. It does not create or reinterpret any contributor ontology.

Open Library Author keys prove endpoint membership but cannot populate the
existing name-based `BibliographicWorkAuthor` presentation. Missing names stay
missing as an empty list; no provider key is rendered as a name.

## 6. Pagination and cursors

The signed version-1 opaque cursor binds:

- the complete selected Author reference, including provider evidence;
- the `local|external` lane; and
- the next provider-neutral offset.

Malformed, tampered, wrong-Author, wrong-provider/reference and impossible
local cursor shapes fail closed. `null` means end. Local results exhaust before
external results; a full final local page hands off to external offset zero
without an early provider request.

## 7. Ordering and deduplication

Ordering remains `(local/external tier, presentation_order, strong result_id)`.
Local SQL order and Open Library response order are presentation only.

Deduplication uses canonical Work ID, same provider-scoped Work ID or an
existing Open Library Work mapping to a canonical Work actually linked to the
selected Author. That mapping check is batched for the page. Equal titles and
title/Author lookalikes remain separate without strong evidence.

## 8. Provider miss and failure

Provider attempts reuse `ProviderLookupStatus` and `ProviderFailureReason`.
Valid empty pages are `miss`; configuration, malformed response, timeout,
network, HTTP and rate-limit outcomes remain distinct. External failure returns
typed evidence beside any local Works and never removes those Works.

Production configuration reuses the single durable resolver:

```text
WordPress constant -> environment variable -> configuration_error
```

## 9. No-fan-out and request-structure proof

One external page makes exactly:

```text
GET /authors/{exact-open-library-author-id}/works.json?limit={page-space}&offset={continuation}
```

The queue-backed transport test fails on an unexpected second call and asserts
that neither `/search`, `editions` nor Work-detail paths occur. Local tests hold
SQL reads constant as the Work count rises.

## 10. Compatibility

MH-SEARCH-01A/01B, MH-EDITION-01, MH-DISC-01, Wishlist/WISH-DISC, Add Book,
`/me/works` and Hierna lezen remain unchanged. The new service is parallel and
has no REST/frontend consumer, so existing strict decoders do not widen.

## 11. Deferred

- REST/UI consumer reachability and the full-page shared search screen;
- Google Books Edition leads; and
- Author detail, biography, portrait and cross-library bibliography UI.

## 12. Tests and quality gates

Final recorded evidence:

- focused Author-to-Works unit suite: `22 tests`, `75 assertions`;
- focused persistence integration suite: `5 tests`, `58 assertions`;
- final full Core unit suite: `535 tests`, `2,241 assertions`; the two
  existing PHPUnit notices remain visible;
- final full Core integration suite: `420 tests`, `4,989 assertions`;
- full Core gate: Composer strict metadata/platform, all PHP syntax, PHPStan,
  WordPress smoke, manifest JSON and whitespace passed in `460 seconds`;
- full Biblio UI smoke/contract suite: `251 passed`, including the unchanged
  strict MH-DISC, Wishlist, Add Book and shared Work contracts; and
- deterministic fixtures cover canonical and typed Author references, exact
  contributor roles, local and external continuation, cursor isolation and
  tamper resistance, strong-identity deduplication, equal-title non-merges,
  unknown canonical Authors, malformed provider records, miss, timeout,
  network, rate-limit, HTTP and configuration failure, one-request behavior
  and the absence of Edition fields.

The second review pass found two fail-closed gaps before closure: an
unknown canonical Author could be mistaken for an empty local page, and a
misbehaving provider implementation could return an identity from the wrong
lane. The final implementation adds an exact Author-existence check and
orchestrator-level local/external provider assertions, with regression tests.

One attempted repeat gate collided with a concurrent DDEV restart and stopped
before tests when its database container exited. DDEV was verified healthy and
the serial clean run reported above then passed completely; the collision is
not counted as a product-test result.

## 13. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Synthetic canonical
records and deterministic Open Library fixtures were the only test data.

## 14. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.9.0`;
- Biblio UI: `0.15.1`, unchanged.

## 15. Git

The final local commit, divergence, clean working tree and no-push status are
reported in the completion report after every gate and review passes.
