# WISH-API-01 — Personal Wishlist REST transport

Status: **GO / CLOSED**

Scope: WordPress REST transport for the existing WISH-CORE-01 personal
Wishlist. No UI, schema change, V1 parser/import, migration endpoint or new
Wishlist product semantics.

## 1. Transport audit

The existing transport uses `/biblio/v1/me/...` for authenticated personal
resources, `RestController` for route composition, `RestRequestParser` for
strict request decoding, `RestResponseSerializer` for explicit allowlists and
`RestErrorMapper` for stable WordPress error envelopes. Successful payloads are
wrapped as `{ "data": ... }`.

The Wishlist is user-owned and platform-wide. It therefore uses no Library
Context, Library route, role-derived access, client-provided actor or owner ID.
The existing `GetMyWishlistService` supplies the complete frontend projection
in `created_at DESC, wishlist_entry_id DESC` order. It has no pagination
contract, so WISH-API-01 adds no speculative cursor or total-count model.

## 2. REST contract

### Read the owner Wishlist

`GET /biblio/v1/me/wishlist`

Request: no query or body fields.

Response 200:

```json
{
  "data": {
    "entries": []
  }
}
```

Every entry has exactly:

- `wishlist_entry_id`;
- `target_type`: `work_only|edition_specific`;
- `work_id`;
- `edition_id`: string or `null`;
- `display_title`;
- ordered `authors` with `author_id` and `display_name`;
- `created_at` and `updated_at` as UTC microsecond instants.

### Add a target

`POST /biblio/v1/me/wishlist`

Work-only request:

```json
{
  "target": {
    "type": "work_only",
    "work_id": "work-id"
  }
}
```

Edition-specific request:

```json
{
  "target": {
    "type": "edition_specific",
    "work_id": "work-id",
    "edition_id": "edition-id"
  }
}
```

Response: the current allowlisted entry projection. A new entry is 201. An
exact idempotent duplicate is 200 with the existing stable entry. Core alone
validates Work existence, Edition existence/relation and exclusive target
state.

### Refine Work-only to Edition-specific

`PATCH /biblio/v1/me/wishlist/{wishlist_entry_id}`

```json
{
  "target": {
    "type": "edition_specific",
    "edition_id": "edition-id"
  }
}
```

Response 200: the refined current entry projection. The entry ID and
`created_at` are preserved. The path ID is resolved only within the
authenticated owner and revalidated under the existing Core transaction and
Work-state lock. A repeated identical refinement reuses the same entry.

### Remove an entry

`DELETE /biblio/v1/me/wishlist/{wishlist_entry_id}`

Request: no query or body fields. Response: 204 with no body. The normal
self-service removal uses the existing Core default `removed`; the REST layer
does not hard-delete and exposes no alternate lifecycle reason.

## 3. Errors, ownership and non-enumeration

- unauthenticated: 401 `biblio_authentication_required`;
- malformed/extra/wrong-type transport input: 400 request error;
- missing, foreign or unavailable Work/Edition/entry: 404
  `biblio_resource_not_available`;
- reverse or mixed Work/Edition intent: 409
  `biblio_wishlist_intent_conflict`;
- exhausted entry-ID allocation: 409
  `biblio_wishlist_entry_id_collision_exhausted`;
- controlled domain validation: 422 `biblio_validation_failed`;
- unavailable Core: 503 `biblio_core_unavailable`.

No response includes owner ID, Library ID, internal lock state, removal history,
migration mapping, source/run/quarantine data, provider payload or schema
version. A Library Owner/Manager role gives no access to another user's entries.

## 4. Concurrency and regression boundary

Parallel REST-worker tests cover identical Work-only adds, identical Edition
adds and concurrent Work-only add/refinement. The database-backed Work-state
lock and existing uniqueness rules remain authoritative: one valid target state
survives, exact duplicates reuse an entry and refinement preserves identity.

Tests also cover own/foreign list and mutation behavior, strict target shapes,
Work/Edition mismatch, two different Editions for one Work, reverse conflict,
normal and repeated removal, stable ordering, response allowlists and the
absence of Hierna lezen, Collection, publication and migration side effects.

## 5. WISH-UI-01 handoff

The future UI can consume:

- list: `GET /me/wishlist`;
- Work-only add: `POST /me/wishlist` with `work_only` target;
- Edition-specific add: `POST /me/wishlist` with `edition_specific` target;
- refinement: `PATCH /me/wishlist/{wishlist_entry_id}`;
- remove: `DELETE /me/wishlist/{wishlist_entry_id}`;
- explicit choice-required state: HTTP 409 code
  `biblio_wishlist_intent_conflict`.

UI placement, labels, confirmation presentation and the later explicit reverse
choice flow remain WISH-UI-01/product work. Grouping/reorder, priority, note,
Goal/context, retailer/price and automatic fulfilment remain outside this API.

## 6. Data, schema and versions

Only synthetic guarded fixtures were used. No current V1 `/data/` was needed,
and no MIG-01 snapshot, `.local/fixture-source`, DATA-01, historical Wishlist
record or count was used as current V1 truth.

Schema remains `1022`. Biblio Core is `2.4.0`; Biblio UI remains `0.13.0`;
product scope remains `v2.001`.

## 7. Verification

Recorded validation includes Wishlist REST/Core integration and real parallel
REST concurrency plus route registration. The final complete gate passed 433
unit tests with 1,857 assertions (and the two existing PHPUnit notices) and 395
integration tests with 4,750 assertions. PHP syntax, PHPStan, Composer metadata
and platform requirements, WordPress/Core smoke, manifest JSON and whitespace
also passed. Runtime schema remained `1022`.

The final implementation diff received a separate
architecture/security/privacy/concurrency/regression review pass with no
blocker.
