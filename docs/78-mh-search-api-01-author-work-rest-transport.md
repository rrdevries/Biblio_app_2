# 78 — MH-SEARCH-API-01 Author/Work REST transport

Status: **GO / CLOSED**

Post-closure note: D-WORK-REF-01 now adds `work_selector` to each Work item
without changing this route's request, grouping, cursors or Author projection;
see `docs/81-d-work-ref-01-signed-work-selector-handoff.md`.

Date: 2026-09-11

Task severity: **High**. The REST audit preceded all changes. Primary
implementation and independent review are separated. No V1 source, snapshot or
count is required.

## 1. REST audit

Authenticated personal resources use `/biblio/v1/me/...`, with a coarse
permission callback plus authoritative application-layer actor resolution.
Typed lookup/search resources use POST with strict JSON objects, while the
older `/me/works` page remains its separate GET query contract. Central
parsing, `data` response envelopes and safe `WP_Error` mapping already exist.

MH-SEARCH-01A supplied the strict typed request/serializer and signed cursors;
MH-SEARCH-01B exposed the authenticated service through
`CoreApplication::bibliographicTextSearch()`. The REST adapter therefore needs
no new search, ranking, identity, provider or authorization policy.

## 2. Endpoint

```text
POST /biblio/v1/me/bibliographic-searches
```

The route is registered once through the existing `RestController`. It is an
additive top-level search resource, not an action route.

## 3. Request contract

The strict JSON body is:

```json
{
  "query": "Ursula Le Guin",
  "author_cursor": null,
  "work_cursor": null
}
```

`query` is required. Both cursors are independently optional. Unknown body or
query fields, non-object/malformed JSON, invalid UTF-8, wrong types,
empty/over-limit queries, checksum-valid ISBN and invalid, tampered,
wrong-query or wrong-group cursors fail closed. There is no coercion and no
provider, result type, user or Library selector.

## 4. Response contract

The existing `data` envelope contains exactly `query`, `authors` and `works`.
Each result group contains `items`, nullable `next_cursor` and typed
`provider_attempts`.

Author items expose opaque `result_id`, provider-neutral `result_kind`,
nullable canonical `author_id`, `display_name` and, after D-AUTHOR-REF-01, an
opaque signed `author_selector`. The selector is the only authority-bearing
handoff to the future Works-by-Author REST boundary; `result_id`, visible IDs
and display data are not. Work items expose the unchanged result identity/kind
pattern, nullable canonical `work_id`, Work title, ordered Authors and reliable
Series context. Raw provider identity/payload, ISBN, publisher, publication
date, language, binding, page count and every Library/private field stay
absent.

## 5. Authentication and privacy

The existing `/me` permission callback returns 401 for an anonymous request.
`BibliographicTextSearchService` independently requires the authenticated
WordPress/Biblio actor before any provider call. The request contains no actor
identity. Discovery reads no private Library data and needs no Library Context.

## 6. Pagination behavior

The REST adapter passes the two existing cursor types unchanged into the typed
request. Each signed cursor binds the normalized query, exact `authors|works`
group, local/external lane, presentation order and stable result identity.
Signatures derive from a domain-separated hash of WordPress `AUTH_SALT`.

An Author continuation can be supplied without a Work continuation and vice
versa. The returned groups remain separate, so the consumer can append only
the requested group without resetting the other UI collection. REST never
decodes, combines, rewrites or converts the cursors into offsets.

## 7. Failure and miss semantics

Normal external miss remains `miss`. Configuration, malformed response,
timeout, network, rate-limit and HTTP failures remain the existing typed
provider-attempt states per group. A normal miss or partial external failure is
HTTP 200 and never removes usable local results.

Malformed transport and cursor-contract violations return safe 400 responses.
WordPress keeps its standard `rest_invalid_json` response for invalid JSON or
UTF-8 before the controller. Missing Core returns 503. Unexpected exceptions
use the existing privacy-safe 500 envelope.

## 8. Strict transport boundary

`RestBibliographicTextSearchContract` performs only transport structure and
error adaptation around `BibliographicTextSearchContract`. `RestRequestParser`
owns WordPress JSON/query parsing, `RestResponseSerializer` accepts the typed
`BibliographicTextSearchResult`, and the controller calls only the existing
application service. No provider or repository array is serialized directly.

## 9. Compatibility

The route is parallel and additive. It changes no MH-DISC-01 route/shape,
Wishlist REST, Add Book REST, `/me/works`, provider configuration or current
consumer decoder. No materialization, Work/Author/Edition creation, Item,
Wishlist or Library mutation occurs.

## 10. Deferred transport

MH-AUTHOR-01 Works-by-Author and MH-EDITION-01 Editions-by-Work stay
application-only. D-AUTHOR-REF-01 supplies only the signed Author-selector
handoff; the route that accepts it still belongs to MH-AUTHOR-API-01.
MH-EDITION-API-01, SEARCH-UI-01 and every Wishlist/Add Book consumer cutover
also remain separate.

## 11. Tests and quality gates

Final recorded evidence:

- independent review: no blocker; reviewer-focused unit set `38` tests / `115`
  assertions and `git diff --check` pass;
- focused bibliographic REST integration: `5` tests / `102` assertions;
- route-registration regression: `1` test / `82` assertions;
- unchanged MH-DISC REST regression: `1` test / `11` assertions;
- full unit suite: `545` tests / `2265` assertions, with the same two existing
  PHPUnit notices;
- full integration suite: `423` tests / `5062` assertions;
- Composer metadata/platform, PHP syntax, PHPStan, WordPress smoke, manifest
  JSON and Git whitespace: pass;
- final clean serial `test-biblio-core-all.sh`: pass in `381` seconds.

An earlier complete gate also passed, but overlapped a reviewer-triggered DDEV
restart. It was therefore not used as the final closure run; the recorded
`381`-second serial rerun above is the decisive evidence.

## 12. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Tests use synthetic
canonical records and the existing deterministic provider fixtures.

## 13. Versions and schema

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core at this slice's closure: `2.10.0`; D-AUTHOR-REF-01 later adds
  the selector field in Core `2.11.0`;
- Biblio UI: `0.15.1`, unchanged.

## 14. Git

The final local commit, divergence, clean working tree and no-push status are
recorded in the completion report after every gate and review passes.
