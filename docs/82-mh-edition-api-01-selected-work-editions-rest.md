# 82 — MH-EDITION-API-01 Selected Work Editions REST

Status: **GO / CLOSED** after the recorded gates and independent second review.

Date: 2026-09-12

Task severity: **High**. The read-only REST/identity audit preceded every
change. Primary implementation and an explicit independent second review pass
were separated. No current or historical V1 source was required.

## 1. Work selector handoff

The server-issued opaque `work_selector` is the only selected-Work authority.
The REST adapter delegates decode to the existing
`BibliographicWorkSelectorCodec`, which verifies signature, version, type,
strict form and—only for composite selectors—the exact current provider-Work-
to-canonical-Work mapping. It returns a trusted
`BibliographicWorkReference`; REST never reconstructs one from visible fields.

## 2. Endpoint

The one additive resource is:

```text
POST /biblio/v1/me/bibliographic-work-editions
```

It uses the existing `/me` authentication callback, central request parser,
safe response envelope and exception mapping. No action or Edition-detail
route was added.

## 3. Request

The strict JSON object contains required string `work_selector` and optional
nullable string `cursor`. Missing/wrong types, malformed JSON/UTF-8, unknown
body or query fields and malformed/tampered/cross-bound selectors or cursors
fail closed. Raw Work/provider/result identity, title, ISBN, Author, Library or
user identity is rejected rather than treated as authority.

## 4. Response

The established `data` envelope contains exactly `items`, `next_cursor` and
typed `provider_attempts`. The existing Edition serializer emits typed result
and canonical/provider identity, title/subtitle, contributors, languages,
publishers, precision-preserving publication date, ISBN-10/13, format, page
count and existing materialization handoff fields where supported. Nullable
data stays nullable and valid ISBN-less Editions remain visible.

## 5. Authentication and privacy

Anonymous access returns the established 401. The actor is server-resolved and
the application service independently requires authentication. This shared
bibliographic read needs no Library Context or membership check and accepts no
client user identity. Item, Library, user and private data are absent.

## 6. Pagination

The adapter passes the optional opaque MH-EDITION-01 cursor unchanged. Its
signed application contract remains bound to the exact trusted Work reference,
provider/reference context, local/external lane and continuation. Valid
continuation works; Work A with Work B's cursor, malformed/tampered cursors and
provider/reference mismatches are rejected. `null` remains the end state; no
raw offset is exposed.

## 7. Canonical, provider and composite behavior

Canonical-only selection discovers local Editions and may use the existing
exact-one current provider mapping according to MH-EDITION-01. Provider-only
selection requests Editions for that exact Open Library Work. A composite
selector reaches existing local-then-external behavior only after current
mapping proof. REST owns none of the lane, ordering or deduplication policy.

## 8. Stale mapping behavior

A correctly signed composite whose exact mapping has been removed or now
targets another canonical Work is rejected during selector verification,
before Edition discovery or any provider call. There is no downgrade to either
identity, alternate mapping, rematerialization or automatic repair.

## 9. Failure semantics

Provider candidates, miss, configuration error, malformed response, timeout,
network/HTTP and rate-limit states preserve the existing typed attempt
contract. A normal miss or recoverable external failure remains a usable 200
response. When local Editions exist, external failure never removes them and
does not become a generic 500.

## 10. Security and cross-binding

The REST layer accepts no client-composed identity components. The selector's
HMAC protects its canonical ID, provider identity, form, version and type;
composite decode additionally proves current server mapping truth. The Edition
cursor independently binds the exact decoded Work reference. Safe 400 errors
do not reveal signatures, secrets, mappings or provider internals.

## 11. No-fan-out and request structure

One external Edition page performs at most one exact request:

```text
GET /works/{exact-open-library-work-id}/editions.json?limit={space}&offset={continuation}
```

There is no Work/Author search, Google request, Work-detail enrichment,
per-Edition enrichment or N+1 behavior. The controller adds no provider call.

## 12. Compatibility

The route is additive. Existing MH-SEARCH-API-01, D-AUTHOR-REF-01,
MH-AUTHOR-API-01, D-WORK-REF-01, MH-EDITION-01, MH-DISC, Wishlist, Add Book,
Hierna lezen and `/me/works` contracts remain unchanged. No Work, Edition,
Item, discovery snapshot, materialization, Wishlist or Library write occurs.

## 13. Deferred

SEARCH-UI-01, Wishlist/WISH-DISC and Add Book consumer cutovers remain separate
bounded slices. This backend transport adds no frontend behavior, design,
language filter, candidate materialization or Edition-detail resource.

## 14. Tests and quality gates

Final deterministic evidence:

- focused selected-Work Editions REST integration: `5` tests, `132`
  assertions;
- full Core unit suite: `618` tests, `2,427` assertions, with the same two
  pre-existing non-failing PHPUnit notices;
- full Core integration suite: `431` tests, `5,298` assertions;
- PHP syntax, PHPStan, Composer metadata/platform, WordPress smoke, manifest
  JSON and Git whitespace: pass;
- clean serial `test-biblio-core-all.sh`: pass in `279` seconds; and
- explicit independent second code/security/docs review: GO, with no identity,
  authorization, cursor, request-fan-out, side-effect, compatibility or scope
  blocker.

These runs cover strict parser/serializer behavior, all three selector forms,
stale mapping before discovery, authentication, cursor binding, local/provider/
composite paging, ISBN-less Editions, miss/malformed/partial provider failure,
exact one-request pages, no side effects, route registration and the required
Core compatibility suites.

## 15. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Tests use synthetic
canonical records and deterministic provider fixtures.

## 16. Versions and schema

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.14.0`;
- Biblio UI: `0.15.1`, unchanged.

No migration, selector table, session or token registry was added.

## 17. Git

MH-EDITION-API-01 is committed once locally only after every gate and the
independent second review pass. The final commit, branch divergence, clean
working tree, safety-branch state and no-push status are recorded in the
completion report.
