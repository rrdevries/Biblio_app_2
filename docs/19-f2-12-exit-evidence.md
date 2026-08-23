# 19 — F2.12 WordPress REST Adapter Foundation exit evidence

Status: **GO**

Date: 2026-08-23

Baseline: `fca01a2fa91741653ecb2d64a8b0d5209b0454e4`, schema 1007.

Scope: A3 / `WordPress REST Adapter Foundation` and the Elementor Readiness
Gate from `docs/16-phase-2-rest-gap-elementor-readiness-analysis.md`.

## Closed readiness blocker

F2.12 closes the last known A-blocker without building Elementor. One thin
WordPress infrastructure adapter exposes only the first vertical slice:

`Mijn Bibliotheek → active catalog overview → Item detail → Start Reading`.

The routes call the existing F2.10 context query, F2.11 catalog read service
and named Library-Item Reading start service through `CoreApplication`. REST
does not query repositories or wpdb and does not decide membership, reading
status, availability, capabilities, Work/source consistency or lifecycle.

## Namespace, naming and routes

The stable namespace is `biblio/v1`, available below `/wp-json/biblio/v1`.
Names describe resources rather than Elementor widgets.

| Method | Route | Input | Success |
|---|---|---|---|
| GET | `/me/libraries` | none | 200, available Library contexts |
| GET | `/libraries/{library_id}/items` | optional `cursor`, optional `page_size` 1–100 | 200, active Item page |
| GET | `/libraries/{library_id}/items/{item_id}` | path IDs | 200, scoped Item detail |
| POST | `/libraries/{library_id}/items/{item_id}/reading-rounds` | JSON `started_on` | 201, started Round identity/state |

Success responses use `{ "data": { ... } }`. Overview places the opaque
continuation token in `data.next_cursor`; `null` means there is no next page.
The cursor is a versioned transport encoding of the F2.11 typed title/Item
cursor and is not a database offset.

## Authentication and actor resolution

All four routes are private and have an explicit permission callback. The
callback admits only a currently logged-in WordPress user and performs no
domain authorization. `WordPressAuthenticatedUser` then resolves that current
server-side user again inside every called Core service.

No route accepts an authoritative actor ID. `user_id`, role or capability
claims in query data are ignored; unsupported mutation body fields are
rejected. Client capability output is presentation information only.

## Cookie authentication and REST nonce

The browser contract uses WordPress cookie authentication and the standard
REST nonce action `wp_rest`. Elementor/custom JavaScript must send the nonce in
`X-WP-Nonce` on cookie-authenticated requests, especially POST. Reads should
also send it so WordPress retains the logged-in cookie identity.

WordPress validates the nonce in `rest_cookie_check_errors` before route
dispatch. There is no custom token or CSRF implementation:

- valid cookie + valid nonce continues to the permission callback and Core;
- missing nonce makes the cookie request anonymous, producing the adapter's
  `biblio_authentication_required` / 401 on these private routes;
- invalid nonce is the WordPress-standard `rest_cookie_invalid_nonce` / 403;
- valid nonce without Library authorization still receives the same safe Core
  not-available response; a nonce never grants authorization.

This follows the WordPress REST cookie-authentication and custom-endpoint
registration contracts:

- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/

## Library Context and non-enumeration

`library_id` is explicit route scope, never trusted context. The adapter builds
the typed ID only from URL parameters and passes it to the existing F2.10
service. That service derives actor, active membership, authorization and
capabilities. REST contains no membership predicate.

Unknown, foreign and inactive Library context all map from Core
`authorization_denied` to `biblio_resource_not_available` / 404. Unknown and
cross-Library Items likewise share that exact external status, code and
message. Query or JSON fields cannot override URL `library_id` or `item_id`.

## Typed request validation

The adapter parses transport into Core value objects before the use-case call:

- `library_id` and `item_id`: non-empty valid UTF-8 Core identifiers, maximum
  191 characters;
- `page_size`: integer 1–100, default 24;
- `cursor`: non-empty opaque URL-safe versioned token whose decoded fields
  must form a valid F2.11 cursor;
- `started_on`: required exact Gregorian date string `YYYY-MM-DD`;
- mutation JSON: exactly the `started_on` allowlist.

Missing required data, wrong types and invalid syntax have separate stable
400 codes. Domain/application validation remains a separate
`biblio_validation_failed` / 422 response and is not reproduced in REST.

## Allowlist serialization

Every response has a named serializer method. There is no reflection,
object-to-array dump or row serialization.

Library output contains only ID, name, type, status, designated-personal state
and the eight F2.10 capabilities. Overview cards contain only stable
Item/Work/Edition IDs, title, typed metadata values, reading status, Item state
and F2.11 capabilities. Detail adds only the documented typed metadata and
Reading summary. Values that F2.11 defines as `unknown` remain `{state:
"unknown", value: null}` or `{state: "unknown", values: []}`; no metadata is
invented.

Start Reading returns only `reading_round_id`, `work_id`, the Library Item
source, lifecycle, exact start date components and optimistic version. It does
not return owner ID, technical timestamps, provenance, SQL fields or the full
domain aggregate.

## Central error contract

`RestErrorMapper` is the sole Biblio transport-error translator. Core exception
messages are never response messages. WordPress owns the nonce error that runs
before the route.

| External code | HTTP | Meaning |
|---|---:|---|
| `biblio_authentication_required` | 401 | no authenticated WordPress actor |
| `biblio_missing_required_field` | 400 | required transport field absent |
| `biblio_invalid_field_type` | 400 | wrong transport type |
| `biblio_invalid_field_syntax` | 400 | malformed ID, cursor, size or date |
| `biblio_unknown_request_fields` | 400 | mutation body outside allowlist |
| `rest_cookie_invalid_nonce` | 403 | WordPress rejected REST nonce |
| `biblio_validation_failed` | 422 | Core semantic validation rejected input |
| `biblio_resource_not_available` | 404 | inaccessible context/resource, non-enumerating |
| `biblio_reading_round_already_active_for_source` | 409 | active source conflict |
| `biblio_reading_round_stale` | 409 | mapped reusable Reading stale conflict |
| `biblio_core_unavailable` | 503 | Core did not pass production boot/health |
| `biblio_internal_error` | 500 | safe unexpected/infrastructure failure |

All errors use the standard WordPress error envelope with machine code, safe
message and `data.status`. Stack traces, SQL and private exception context are
not included.

## Overview, detail and Start Reading authority

Overview and detail call `CatalogUiReadService` directly. Active-only scope,
sort, cursor semantics, metadata states, actor-owned reading derivation and
capabilities therefore remain the F2.11 contract.

POST calls `StartReadingFromLibraryItemService::start()`. It sends only typed
Library ID, Item ID and exact Reading date. Core re-reads accessible source and
Edition, derives Work, checks direct-use access, resolves owner server-side and
enforces duplicate active-source state through the existing transaction and
database invariant. REST does not precompute or copy those decisions.

## Registration and production lifecycle

`Plugin::boot()` creates one `RestApi` and registers one `rest_api_init` hook.
Both plugin boot and REST registration are idempotent. Routes remain registered
when Core boot fails so requests fail closed with 503 instead of disappearing.
The application provider reads the existing plugin lifecycle boundary at
request time; no global application locator was introduced.

The classes live under `Infrastructure/WordPress/Rest`; Core/domain and F2.11
DTOs remain independent of WordPress REST and continue to run in the unit
suite without WordPress.

## Adapter and security evidence

Real WordPress REST-server tests against isolated MariaDB prove:

- all four routes and explicit permission callbacks;
- idempotent registration and fail-closed unavailable Core;
- anonymous denial and server-resolved authenticated actor;
- own, foreign, inactive, view-only and missing Library context;
- Library and Item non-enumeration;
- allowlisted Library, overview, detail and mutation payloads;
- active-only scoped overview, empty Library, cursor pages and no foreign data;
- detail unknown metadata and absence of internal/private fields;
- valid cookie+nonce start, missing nonce and invalid nonce;
- view-only and cross-Library start denial;
- active-Round conflict from Core;
- query actor/capability spoofing has no effect;
- mutation actor/capability/unknown fields are rejected;
- bad page size, cursor, missing date and invalid calendar date;
- raw exception, file path, SQL and membership detail do not leak.

The test dispatches through `WP_REST_Server`; nonce cases execute WordPress's
real `rest_cookie_check_errors` before dispatch.

## Schema and Abilities API decision

F2.12 persists no new truth. Schema stays 1007; there is no DDL, migration
1008, cache or user-data rewrite.

No WordPress Abilities API surface was added. A parallel experimental surface
would duplicate registration and testing without closing an additional first-
slice readiness gap. Future abilities may reuse the same application services
without changing Core.

## Full quality gate

Canonical full-gate result:

- Composer metadata and locked platform requirements: passed;
- all plugin/test PHP syntax: passed;
- PHPStan level 6 over production `src`: passed, no errors;
- unit: 229 tests / 879 assertions;
- real WordPress/MariaDB integration: 180 tests / 1,422 assertions;
- total: 409 tests / 2,301 assertions;
- targeted REST family within integration: 6 tests / 105 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- manifest JSON and Git whitespace checks: passed;
- full canonical gate: passed in 70 seconds.

## Elementor integration contract

The first Elementor/custom-JavaScript slice may:

1. obtain a server-generated `wp_rest` nonce and send cookies plus
   `X-WP-Nonce`;
2. load `/biblio/v1/me/libraries` and retain one explicit `library_id`;
3. load the active overview and follow only returned `next_cursor` tokens;
4. load detail with the returned stable `item_id`;
5. show actions from capability output for UX, never as authorization;
6. POST `{ "started_on": "YYYY-MM-DD" }` to the returned Item route;
7. refresh overview/detail after 201 and branch on stable error codes.

Elementor must not query Core tables, accept actor/capability claims, infer
Library authorization or implement Reading rules.

## Elementor Readiness Gate

| Blocker | Status | Evidence |
|---|---|---|
| A1 Library Identity & Context | CLOSED | F2.10, `docs/17-f2-10-exit-evidence.md` |
| A2 Catalog UI Read Models | CLOSED | F2.11, `docs/18-f2-11-exit-evidence.md` |
| A3 WordPress REST Adapter | CLOSED | F2.12, this document |

Gate assessment:

- Core business rules, actor identity, Library Context and final authorization
  remain server-side;
- stable overview, detail and start contracts exist for the first UI slice;
- cookie authentication and standard nonce/CSRF failure paths are proven;
- serialization and errors are explicit, stable and non-enumerating;
- tenant isolation, spoof resistance and exception privacy are tested;
- REST is a transport adapter without duplicated business decisions;
- the vertical slice is technically end-to-end testable without direct data
  access from Elementor.

## Remaining conditions and scope

There is no remaining A-blocker. The next step may be only the first Elementor
vertical slice and must preserve this contract. Rich catalog metadata,
search/filter, archive and all B/C slices remain later work. Notes,
Ratings/Reviews, Next Reading and generic CRUD endpoints were not added.

## Exit verdict

**GO — ELEMENTOR READY.** F2.12 and A3 are closed. A1/A2/A3 are all closed,
the canonical gate is green and the first Elementor vertical slice may start
after the clean F2.12 commit.
