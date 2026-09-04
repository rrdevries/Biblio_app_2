# 36 — Library Item Location foundation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-04.

## 1. Scope and product contract

This Medium-severity slice implements only the technical Location foundation
for Library Items. Canonical sources establish Location as Library-owned and
an Item as having zero or one current Location. Archive lifecycle,
Collections, Search/filter execution, REST, frontend and management UI remain
outside scope.

Repository inspection classified existing Library/Item identity and Library
Context as reusable canonical sources (A), found no legacy migration source
(B), classified card/detail Location labels as presentation-only (C), found no
canonical Item free-text Location source (D), and excluded unrelated browser
location references (E). Therefore no data conversion or fuzzy merge occurs.

## 2. Domain and relation

`LocationId` is stable and validated. `LibraryLocation` owns its `LibraryId`
and preserves a non-empty valid UTF-8 display name up to 512 characters.
Equal names remain distinct IDs. `Item` carries an optional `LocationId`.
Assignment has replacement semantics and may be cleared; no hierarchy,
normalization, aliases, address or geodata were introduced.

## 3. Persistence and migration

Schema 1010 advances through one forward migration to schema 1011. The new
`biblio_locations` table uses `(library_id, location_id)` identity, a
Library/name/ID listing index, restrictive Library FK and non-empty-name
check. `biblio_items.location_id` is nullable. Its composite
`(library_id, location_id)` FK makes dangling and cross-Library relations
technically impossible; `(library_id, location_id, item_id)` supports future
filtering and reverse reads.

Existing Items are preserved with `NULL` Location. The migration supports a
known completed retry, rejects unknown partial Item state before version bump,
and participates in fresh-install and schema-health validation.

## 4. Application, security and performance

Typed repository ports provide one-Library Location listing, batched
Items→Location and batched Locations→Items. Production composition wires the
wpdb adapter to `LibraryItemLocationQueryService`; each public read resolves
server-side Library Context before repository access. All SQL paths include a
Library predicate. Foreign Item and Location IDs return empty/null-shaped
results and do not enumerate another Library. Central bibliographic metadata
has no path around this scope.

The contracts are batch-first and introduce no inherent N+1. The two composite
indexes cover Library listing and Location-to-Item access; final combined
catalog-query plans remain part of the later query implementation.

## 5. Verification and review

Domain, application, persistence and migration tests cover name/identity/
ownership validation, optional/replaced/cleared relations, equal-name
non-merging, round trips, batch reads, foreign-ID non-enumeration,
cross-Library and dangling rejection, migration preservation, health, retry
and partial-state failure.

Four explicit second-pass reviews cover architecture, security, tests and
product scope because no separate reviewer agent is available. Final gate
passed in 117 seconds: 282/282 unit tests with 1,070 assertions and 277/277
MariaDB integration tests with 3,105 assertions, plus PHP syntax, PHPStan,
Composer metadata/platform, WordPress smoke, manifest and whitespace checks.

## 6. Residual scope

Mijn Bibliotheek technical readiness remains blocked on Archive lifecycle,
Collections and later existing-source/query integration. The expected next
slice is `Archive Lifecycle Foundation`.
