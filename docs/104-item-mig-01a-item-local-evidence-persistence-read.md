# ITEM-MIG-01A — Item-local evidence persistence and read

Status: **GO / CLOSED**

Date: 2026-09-14

Task severity: **High**

Canonical design authority:
`docs/103-d-item-mig-01-minimum-item-local-migration-target.md`.

## 1. Decision inheritance and scope

ITEM-MIG-01A implements the accepted V2.001 Item-local target without
reinterpreting it. Work and Edition remain shared bibliography. Inventory
number, Location, Condition, Acquisition and collector/copy facts belong to
the exact physical Item and Library. The implementation does not inspect,
parse or map V1 and introduces no generic EAV/JSON escape hatch.

Product remains `v2.001`. Schema advances from `1024` to `1025`, Biblio Core
from `2.26.0` to `2.27.0`, and Biblio UI from `0.19.0` to `0.20.0`.

## 2. Domain and active fields

`ItemLocalDetailsState` is the typed all-null-capable state for the approved
Item-local facts:

- Condition: `nieuwstaat`, `zeer_goed`, `goed`, `redelijk`, `matig`, `slecht`;
- `in_library_since`: exact year, year-month or full valid date;
- `signed` and the separately bounded `signed_by` text;
- bounded lossless `copy_limitation`;
- `dust_jacket`: `present`, `missing` or `not_applicable`;
- tri-state `inscription`;
- bounded exact `provenance` and `completeness` text;
- `acquisition_method`: `zelf_aangeschaft`, `gekregen` or `anders`;
- bounded exact `acquired_via` text; and
- exact `DECIMAL(19,4)` paid amount paired with one versioned current or
  historical ISO 4217 currency code.

Null always means unknown/not recorded. Explicit false and
`not_applicable` remain distinct. Date components are never invented. Text is
validated for UTF-8, code-point bounds, non-whitespace content and absence of
Unicode control characters, then stored unchanged. Amounts remain decimal
strings and never become floats, inferred currencies, current values or
method evidence. `signed_by` is valid only when `signed` is true.

## 3. Schema and persistence

Schema 1025 adds one sparse Core-owned `biblio_item_local_details` table. Its
composite primary key and restrictive composite foreign key
`(library_id, item_id)` bind every row to the exact same-Library Item. Typed
nullable columns and database checks mirror the approved enums, partial-date
calendar rules, boolean states, signer invariant, text bounds, paired
amount/currency and positive `details_version`. An index beginning with
`(library_id, acquisition_method)` retains future Library-scoped filtering and
statistics without adding an endpoint now.

`WpdbItemLocalDetailsRepository` performs exact scoped reads, bounded batch
reads, inserts and compare-and-swap replacement. Mutations require an existing
caller-owned transaction. A never-written all-unknown state has no row. Once
created, clearing all values retains an all-null tombstone and increments the
version; it never deletes/recreates the aggregate.

## 4. Version, concurrency and transactions

Create inserts version 1. Update requires the expected version and increments
exactly once. Concurrent absent-row inserts have one physical winner; a loser
converges only when the stored state is exactly equal. Divergent create or
update returns `ItemLocalDetailsStale` and never overwrites. The recorder does
not own or retry the surrounding transaction.

`ItemLocalDetailsRecorder` is source-neutral and receives only an exact target
Library, Item and typed state. MIG-FND's `CommitMigrationRecordService` remains
transaction owner, so the Item/detail product write, observation outcome and
target mapping commit or roll back together. A committed observation is
skipped before product mutation, exact replay converges, and a divergent
existing state becomes a preservation-aware conflict for quarantine/review.
The details row has no separate migration identity or target edge.

## 5. Ownership, privacy and reads

`LibraryItemLocalDetailsQueryService` resolves the actor and explicit
`LibraryContext`, requires `canViewCollection`, and only then performs an
exact tenant-scoped Item/details read. It supports active and archived Items
and a bounded batch of at most 100 unique Item IDs. Missing, foreign and
unauthorized Items use the same non-enumerating not-available boundary. There
is no Edition, Work or sibling-Item fallback. An absent row returns a typed
all-null view for backward compatibility.

Provenance and every other normal field use the same authorized collection
read boundary; raw source observations, evidence references and source IDs are
never serialized.

## 6. Book Detail and lifecycle behavior

The existing Book Detail Core read now composes real inventory number,
Location and Item-local details. REST emits the exact allowlisted
`item_local_details` object, including typed partial date and paid amount.
The strict UI decoder rejects missing, extra or invalid nested members. The
existing `Exemplaar` panel renders only known facts with Dutch presentation
labels and performs no inference from display text. It adds no edit controls.

Archive and restore do not change or version Item-local details. The Core read
can retrieve retained details for archived Items, while a reachable archived
Book Detail presentation remains deferred. Collection add/remove/archive
behavior remains independently owned and never creates, mutates or deletes the
details row.

## 7. Compatibility and deferred work

Existing Items remain valid without a details row. Existing Book Detail keys
remain and their former `unknown` Item placeholders are now backed by actual
Item facts; the new exact object is additive at the current REST route. There
is no current Item backfill.

Explicitly deferred are V1 parsing/mapping/import, Item edit UI, Add Book
acquisition redesign, vendor/person entities, accounting/current valuation,
price or full metadata history, specialist rare-book modelling, provenance
graphs, inscription/enclosure assets, generic Item EAV, Library-local
presentation persistence, technical `Geregistreerd op`, and archived Book
Detail UI reachability.

## 8. Verification evidence

Focused unit and integration coverage proves domain bounds, schema/retry and
database constraints, exact persistence, CAS/tombstones, equal/divergent
concurrency, archive retention, authorization and cross-Library
non-enumeration, Book Detail serialization, strict frontend decoding and
display, and MIG-FND commit/replay/rollback/conflict behavior. The definitive
Core gate passed 681 unit tests with 2708 assertions and 517 integration tests
with 5971 assertions; its Composer/platform, PHP syntax, PHPStan, WordPress
smoke, manifest and whitespace checks are green. The relevant UI gate passed
its isolated WordPress smoke and all 282 JavaScript tests. Independent review
found no remaining blocker after its schema-current, stale-replay,
Unicode-whitespace and clear-versus-update findings were corrected and
regression-tested.

The old `Biblio\Core\Plugin::VERSION` constant remains `2.14.0`. Current code
search confirms it is only asserted by its isolated unit test and is not the
WordPress/runtime/build version source. Repository convention uses the Core
plugin header and manifest for this release bump, so ITEM-MIG-01A leaves that
pre-existing inconsistency untouched and reports it explicitly.

## 9. Actual V1 data rule

No current V1 export was needed or consulted. Exact source fields, values,
prevalence and mappings remain **REQUIRES CURRENT V1 EXPORT FOR MAPPING**. A
future source-dependent run must use a newly designated export; historical
MIG-01/DATA-01 snapshots are evidence only.
