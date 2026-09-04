# 37 — Library Item Archive lifecycle foundation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-04.

## 1. Scope and contract

This High-severity Slice 3B implements only the Core Archive lifecycle
foundation. Item state is `active` or `archived`; archive and restore retain
one Item identity and every archive period. The six canonical reasons map
losslessly to `sold`, `given_away`, `donated`, `lost`, `damaged_discarded` and
`not_returned`. Catalog-query composition, REST/UI, Collections, Condition,
Acquisition and lending remain outside scope.

## 2. Aggregate and application boundary

`Item` owns status and monotonic `ItemVersion`. Valid transitions are
Active→Archived and Archived→Active. An identical archive retry with the same
open reason and any restore retry whose target is already active are no-op
successes without another version, period or audit event. A different reason
against an already archived Item is invalid; a version mismatch before an
unapplied target is `ItemArchiveStale`.

`ManageLibraryItemArchiveService` resolves the authenticated actor and checks
active Owner/Beheerder baseline Item-management authority before Item access.
It locks by Library+Item, writes state/history and appends an immutable Library
ActivityEvent in one transaction. Missing/foreign Items and unauthorized
Libraries use one non-enumerating availability failure.

## 3. Persistence, reads and migration

Schema 1012 adds Item version, active/archive status integrity, unique
Library+Item relational identity, and `(library_id,item_status,location_id,
item_id)`. `biblio_item_archive_periods` stores archive version/reason/time and
optional restore version/time at microsecond UTC precision. Its composite FK
prevents dangling/cross-Library history; generated-key uniqueness permits one
open period; deterministic history order is Item then archive version.

The 1011→1012 migration is forward-only. Existing Items remain active version
1 with Edition, inventory and Location untouched. Completed retries are
no-ops; a known Item-DDL-complete/history-table-missing partial state completes;
unknown partial definitions fail before version bump. Full postcondition
health covers columns, indexes, FKs, generated expression and checks.

`LibraryItemArchiveQueryService` authorizes Library Context before its two
batch reads: requested Items→current lifecycle and Items→ordered periods.
Foreign IDs retain null/empty result shape without enumeration or query-per-
Item behavior.

## 4. Dependent flows and privacy

Existing catalog overview/detail SQL already selects active Items only.
`GetAccessibleLibraryItemService` now also requires active state, covering
current Leesvoorraad, preferred-source projection and new ReadingRound source
resolution. Archive/restore updates only Item state/version, archive periods
and Library audit. It preserves Location and does not mutate ReadingRounds,
ratings, reviews, notes, goals, Collection history or snapshots.

InternalLoan is not implemented in the current aggregate, schema or
composition. Therefore no active InternalLoan can currently exist and the
ordinary archive guard is vacuously satisfied. The future lending foundation
must add that check and the canonical settle-then-archive routes for
`not_returned`/`given_away`; this slice fabricates no lending port or behavior.

## 5. Verification and review

Tests cover aggregate transitions, identity/Location preservation, all typed
conflict classes, identical no-op retries, Owner/Beheerder authorization,
member/foreign non-enumeration, server-side read scope, multiple periods,
reason/time precision, relational rejection, index/health contracts, existing
Item migration, completed and known-partial retry, and unknown-partial failure.

Final verification on the reconciled `0530a83` base passed: the targeted unit
set ran 26 tests with 63 assertions; targeted Archive persistence/schema ran 7
tests with 40 assertions; the 1011→1012 migration chain ran 19 tests with 84
assertions; the complete unit suite ran 294 tests with 1,117 assertions; and
the complete MariaDB integration suite ran 284 tests with 3,147 assertions.
PHP syntax, PHPStan, Composer metadata/platform requirements, WordPress smoke,
manifest JSON and Git whitespace checks also passed. The complete quality gate
took 154 seconds.

An explicit second review pass covers architecture, security/privacy,
tests/regression and product scope because no separate reviewer agent is
available. Final gate counts are recorded in the completion report.

## 6. Residual scope

The complete Mijn Bibliotheek query remains blocked on Collection/membership
foundation and later existing-source/query integration. The next prerequisite
is Slice 4, Collection Foundation (High).
