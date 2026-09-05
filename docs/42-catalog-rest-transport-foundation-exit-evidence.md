# 42 — Catalog REST transport foundation exit evidence

## 1. Verdict and scope

Status: **GO / CLOSED — READY FOR MIJN BIBLIOTHEEK UI QUERY INTEGRATION**.

Slice 7A exposes the existing typed `CatalogQueryService` through one new,
read-only transport route. It adds no query semantics, frontend, browser state,
Elementor change, mutation, preference, suggestion engine, persistence or
schema change. Schema remains **1013 → 1013**.

The newer canonical documentation correction in commit `606a43e` is retained.
In particular, this slice does not implement or reinterpret `Wat zal ik lezen?`.

## 2. Transport map

| Transport concern | Existing Core source | REST responsibility |
|---|---|---|
| Library | `LibraryId` plus `LibraryContextQueryService` | route identity; Core authorizes server-side |
| Actor | `AuthenticatedUser` | WordPress cookie/nonce authentication; no actor input |
| Search | `CatalogSearchTerm` | trim outer whitespace, validate UTF-8 length contract |
| Filters | `CatalogFilters` | strict allowlist and typed list conversion |
| Sort | `CatalogQuerySort` | allowlist stable machine values |
| Page size | `CatalogOverviewPageSize` | integer syntax; Core limits 1–100/default 24 |
| Cursor | `CatalogQueryCursor` | opaque string pass-through |
| Archive | `CatalogArchiveScope` | allowlist active-only or canonical mixed scope |
| Result | `CatalogQueryPage` | explicit allowlisted serialization |
| Failures | request and Core failures | existing Biblio REST error envelope/mapping |

## 3. Route and authentication

The route is:

    GET /biblio/v1/libraries/{library_id}/catalog

The route uses the existing authenticated Biblio REST boundary. Browser clients
use WordPress cookie authentication plus `X-WP-Nonce` for `wp_rest`. The route
parameter selects a Library identity but does not prove access.
`CatalogQueryService` resolves the current actor and authorizes Library Context
server-side before catalog persistence access. There is no `user_id`, role or
capability request parameter.

Missing, inactive, foreign and unauthorized Libraries retain the same
non-enumerating `404 biblio_resource_not_available` response. Invalid client
syntax is independent of resource existence and does not trigger foreign
resource lookups.

## 4. Request contract

The complete query-parameter allowlist is:

| Parameter | Shape | Contract |
|---|---|---|
| `search` | single string | outer whitespace trimmed; 2–191 Unicode characters |
| `reading_statuses[]` | string list | `reading`, `read`, `not_read` |
| `author_ids[]` | ID list | central Author IDs |
| `series_ids[]` | ID list | central Series IDs |
| `location_ids[]` | ID list | Library-local Location IDs |
| `book_type_ids[]` | ID list | Library-local Boeksoort IDs |
| `genre_ids[]` | ID list | Library-local Genre IDs |
| `subject_ids[]` | ID list | Library-local Onderwerp IDs |
| `collection_ids[]` | ID list | Library-local active Collection IDs |
| `without_collection` | single boolean | lowercase `true` or `false`; exclusive with Collection IDs |
| `sort` | single string | `title`, `author`, `series` |
| `page_size` | single integer | default 24; minimum 1; maximum 100 |
| `archive_scope` | single string | `active_only` or `active_and_archived` |
| `cursor` | single string | opaque Core cursor, maximum 2048 bytes |

List parameters use WordPress query-array encoding, are duplicate-free, contain
one to 100 non-empty trimmed values and preserve OR-within-group semantics.
Different active groups retain Core AND semantics. `series` sort remains valid
only with an active Series filter. Missing parameters use typed Core defaults;
an explicitly empty parameter is invalid. Unknown parameters fail closed.

REST does not resolve Library-local filter IDs. Unknown, inactive or foreign
values therefore retain the Core non-match contract without disclosing whether
a resource exists elsewhere.

## 5. Cursor and archive behavior

REST passes `cursor` unchanged into `CatalogQueryCursor` and emits the returned
opaque value as `next_cursor`. Signing, decoding, actor/Library/query
fingerprinting, anchor resolution and stale detection remain entirely in the
Slice-6 Core codec/service. Tampering, another actor or Library, and changed
Search, filters, sort, page size or archive scope fail closed through the
generic validation response.

`active_only` is the default. `active_and_archived` exposes the already
canonical mixed active/archive list; no archive-only view or lifecycle mutation
is added.

## 6. Response contract

Success keeps the existing Biblio envelope:

    { "data": { "library": {...}, "items": [...], "next_cursor": null|string } }

Each Item is explicitly allowlisted to:

- `item_id`, `work_id`, `edition_id`;
- `title`, `item_status`, `inventory_number`;
- `authors[]` with `author_id` and `display_name`;
- `series[]` with `series_id`, `display_name` and nullable `position`;
- nullable `location` with `location_id` and `display_name`;
- nullable `classification` with `book_type_id`, `genre_ids` and `subject_ids`;
- `collection_ids`;
- the current actor's `reading_status`;
- nullable `contained_match_title`.

Serialization reads only the already loaded `CatalogQueryPage`. It exposes no
database-only keys, versions, SQL/debug values, cursor internals, actor IDs,
Notes, private Rating/Review content, historical Collection membership or
other hidden data. A valid empty result is HTTP 200 with `items: []` and
`next_cursor: null`.

## 7. Error mapping

The existing REST error conventions remain authoritative:

| Category | HTTP/code |
|---|---|
| malformed, unknown or wrong-type transport input | 400 / specific `biblio_*request*` or field code |
| typed Core validation, including incompatible cursor or Series-sort context | 422 / `biblio_validation_failed` |
| unauthenticated | 401 / `biblio_authentication_required` |
| inaccessible Library Context | 404 / `biblio_resource_not_available` |
| unexpected failure | 500 / `biblio_internal_error` |

Raw exception text, stack traces, SQL and protected identifiers are never
returned. WordPress rejects an invalid cookie nonce with its existing 403
`rest_cookie_invalid_nonce` response before the controller executes.

## 8. Performance and compatibility

A fully enriched first catalog page executes the existing **15 Core database
calls**. Continuation executes **16**, including the existing anchor-resolution
call. The controller and serializer add **0** calls, perform no per-Item lookup,
do not execute a total-count query and invoke `CatalogQueryService` once.
A cold actor switch in the integration harness executes two WordPress user
resolution calls before route dispatch; this is framework authentication
overhead rather than catalog or serializer work.

The existing `GET /biblio/v1/libraries/{library_id}/items` overview and all
existing detail, reading, Notes, Next Reading and assessment routes remain
unchanged. The new `/catalog` resource is the authoritative typed Search/filter/
sort transport for Slice 7B; the existing `/items` route remains the backward-
compatible active overview until the UI is deliberately migrated.

## 9. Verification and review

Targeted parser, serializer and REST integration tests cover defaults, all
parameters and filter types, malformed/unknown/duplicate/excessive input,
Unicode Search, three sorts, Series-sort context, page-size boundaries, mixed
archive scope, first/next/empty pages, allowlisted serialization, actor-private
reading status, nonce/authentication, Library non-enumeration and query-bound
cursors across actor, Library, filter and sort changes.

Final verification evidence:

- targeted parser/serializer unit: **20 tests, 49 assertions**;
- targeted WordPress/MariaDB `RestApiTest`: **55 tests, 1368 assertions**;
- targeted Slice-6 `CatalogQueryCompositionTest`: **9 tests, 68 assertions**;
- complete unit suite: **339 tests, 1316 assertions**;
- complete integration suite, including REST, schema/migration, Archive,
  Collections, reading status, Authors/Series, Classification, Location and
  production lifecycle regressions: **306 tests, 3826 assertions**;
- Composer metadata and locked platform requirements, all PHP syntax, PHPStan,
  WordPress smoke, manifest JSON and unstaged/staged whitespace checks: green;
- local development schema remained **1013** and the isolated
  `biblio_core_test` database was removed after verification.

No Playwright suite was run: Slice 7A changes no frontend or existing UI
endpoint, and the repository has no relevant browser flow for the newly added,
as-yet unconsumed `/catalog` route.

The independent review found no remaining blocker or major issue. Architecture,
REST contract, authentication, privacy, validation, serialization, cursor
transport, query counts, compatibility and Product Guardian boundaries all
retain the existing Core truth. No frontend, URL/session state or What Shall I
Read behavior is implemented.

## 10. Remaining boundary

Slice 7B may connect the existing disabled Mijn Bibliotheek controls to this
route, source filter-option presentation from existing authorized read
boundaries, manage URL/session/browser state, suppress stale browser responses
and add UI/E2E/accessibility evidence. It must not duplicate Core query logic.
