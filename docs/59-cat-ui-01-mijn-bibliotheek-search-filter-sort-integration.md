# CAT-UI-01 — Mijn Bibliotheek Search, Filter & Sort integration

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING**
Date: 2026-09-08
Schema: `1017` unchanged
Biblio UI: `0.11.0`

## Contract audit

Before implementation the current repository and DDEV runtime confirmed:

- Mijn Bibliotheek still read Grid/List from
  `GET /biblio/v1/libraries/{library_id}/items`; Search and Sort were disabled
  and Filters contained only explanatory UI;
- the approved composed read is
  `GET /biblio/v1/libraries/{library_id}/catalog`;
- Search accepts 2–191 trimmed Unicode characters and covers Edition title,
  Work title/alternate title, contained-Work title, Author, Series, ISBN and
  inventory number using Core-owned partial, case- and accent-insensitive
  semantics and relevance ordering;
- Core supports Reading status, Author, Series, Location, Book Type, Genre,
  Subject, Collection, Without Collection and active/archive query groups;
  values use OR within a group and groups combine with AND;
- `title`, `author` and conditional `series` are the only sort keys; Series
  order requires an active Series filter;
- the REST cursor is opaque and bound to actor, Library and the complete query,
  with a default page size of 24 and stable Core ordering;
- Grid and List shared one presentation model, but query state was not yet
  represented in URL, History or session storage; and
- Core resolves the actor and explicit Library Context, enforces
  `canViewCollection` and retains non-enumerating transport errors.

## Implemented integration

`biblio-ui/catalog-query` now owns strict query normalization, REST and URL
serialization, session scoping and exact response decoding. The app has one
query, request, result and cursor lifecycle for Grid and List. A query change
clears the old page, aborts/supersedes the previous request and ignores late
responses. `Meer laden` submits the same query with only the opaque server
cursor added.

The existing toolbar now provides:

- live Search with a 250 ms technical debounce, immediate Enter, explicit
  clear, the canonical two-character minimum and distinct loading, zero-result
  and query-error states;
- direct filters for the fixed Reading status enum and the Library-scoped
  active Book Type, Genre and Subject options from the existing
  `classification-options` route;
- the directly supported `Zonder collectie` boolean filter, without requiring
  a Collection option list;
- removable active chips, `Alle filters wissen` and temporary
  `Ook in archief zoeken`; and
- `Titel A–Z`, `Auteur A–Z` and `Serievolgorde` only when a Series filter is
  already present in valid query state.

Search/filter/sort state is canonicalized into URL History and restored by
Back/Forward, copied URLs and a session fallback scoped with the current REST
nonce, Library and module. Archive inclusion is deliberately excluded from
URL/session persistence and therefore resets on refresh/navigation. Grid/List
preference remains separate.

Active Items retain normal detail and Quick View navigation. Archived results
are marked `Archief` and expose no Item-detail or Quick View action. The
catalog transport has no cover-reference field; the UI uses the existing
labelled no-cover presentation rather than Item-level reads or invented data.

## Deliberately absent controls

Core and REST can evaluate Author, Series, Location and Collection filters, but
the browser contract exposes no safe Library-scoped option/read route for
those categories. CAT-UI-01 therefore renders no such controls. The boolean
`Zonder collectie` filter is rendered because it needs no option source. No
provider lookup, hardcoded option list, cross-Library enumeration
or Add Book data reuse was introduced. Valid values already present in a
canonical URL remain strictly serialized, including conditional Series sort,
but the UI does not invent their option source.

Bookshelf, fuzzy search, typo tolerance, client-side filtering/sorting, new
sort keys, a new search engine and permanent preference mutation remain out of
scope.

## Verification

- strict frontend contract/runtime/view/routing suites: 234 tests;
- isolated Biblio UI PHP syntax and registration smoke;
- guarded Chromium coverage for real Search, filters, sort, URL/session state,
  Back/refresh, archive, zero/error states and a 25-row cursor result;
- authenticated screenshots at 1440, 1024, 768 and 390 px plus actual Chromium
  page scale 200%, with no horizontal overflow;
- full guarded fixture checks, double cleanup and unchanged non-fixture
  fingerprint;
- complete Core unit/integration/smoke, PHP syntax, PHPStan, Composer,
  WordPress/schema, manifest and Git whitespace gates.

Human visual acceptance and any push remain Renée's separate decisions.
