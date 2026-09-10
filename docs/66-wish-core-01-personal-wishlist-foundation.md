# WISH-CORE-01 — Personal Wishlist foundation

Status: **GO / CLOSED**

Scope: source-neutral Core/domain/application/persistence/read-model and
MIG-FND integration for the personal Wishlist. No REST route, UI, V1 parser,
source profiling or production-data migration.

## 1. Canonical contract

An active Wishlist Entry is private, platform-wide and owned by one user. It
references exactly one Work and is either Work-only, where Edition is
irrelevant, or Edition-specific for one exact Edition of that Work.

For one user + Work there is at most one Work-only entry, or zero or more
different Edition-specific entries. Both forms cannot coexist and the same
Edition cannot occur twice. An exact repeated add is idempotent. Explicit
Work-only→Edition refinement preserves the entry identity and creation time;
Edition-specific→Work-only is a conflict. Removal atomically leaves the active
set and preserves the removed snapshot with one canonical history reason:
`fulfilled`, `removed` or `read_and_removed`.

Wishlist is not Library-owned, Desired Acquisitions, Hierna lezen, a
Collection, possession or fulfilment state. No Add Book, reading, Item or list
operation automatically mutates it.

## 2. Schema 1022

The additive 1021→1022 migration creates:

- `biblio_wishlist_work_states`: one exclusive target mode and serialization
  lock per user + Work;
- `biblio_wishlist_entries`: stable entry, owner, Work, target type, optional
  Edition and UTC microsecond creation/update times;
- `biblio_wishlist_entry_history`: immutable removed entry snapshot, removal
  instant and typed reason.

The entry→state composite foreign key enforces the exclusive mode. Unique
indexes enforce one Work-only entry and one user+Edition entry. Check
constraints close target shape, values and timestamp order. Work and Edition
foreign keys are restrictive. Structural/data health detects unknown partial
DDL, empty states and Edition→Work mismatch. Fresh install, exact retry and
ordered upgrade are covered by real MariaDB tests.

## 3. Application and ownership boundaries

`AddWishlistEntryService`, `RefineWishlistEntryService`,
`RemoveWishlistEntryService` and `GetMyWishlistService` resolve the
authenticated actor internally. None accepts caller-controlled user ID or
Library Context. Persistence predicates always include the owner for personal
entry reads/removal.

The list projection returns stable entry ID, Work ID, optional Edition ID,
display title, ordered Authors and creation/update times. It performs one
entry/title query and one batch Author query for the result set. There is no
Library, membership, role or Item join.

## 4. Transactions and concurrency

Every self-service mutation owns one Core transaction. The user+Work state is
created/locked before current locking reads of entries. This matters under
MariaDB `REPEATABLE READ`: using snapshot reads after the lock could turn
concurrent duplicate adds/refinements into false conflicts.

Refinement deletes the Work-only child, changes the locked state mode and
reinserts the same entry ID with the exact original creation instant. All
steps commit together or roll back together. Concurrency tests prove duplicate
add reuse, mixed-intent exclusion and stable one-entry refinement.

## 5. MIG-FND participation

`WishlistRecorder` is source-neutral and deliberately does not start a nested
transaction. `CommitMigrationRecordService` can therefore atomically combine
an explicitly targeted product write with the source observation outcome and
`wishlist_entry` mapping.

Synthetic tests prove created/reused mappings, stable target identity through
refinement, reverse trace, no product or mapping residue on rollback,
committed-observation retry without a second write and quarantine of a
forbidden reverse collapse. Source family, source ID, payload hash and
quarantine remain in MIG-FND, never in Wishlist product rows.

No current V1 source, historical source copy or reported historical count was
used. Any source-dependent run still requires a newly designated and pinned
current export.

## 6. Explicit boundaries

Included: Work-only and Edition-specific add, own list, explicit refinement,
typed removal history, owner isolation, schema health, concurrency and MIG-FND
binding.

Excluded: REST/API, UI, priority, notes, groups, smart logic, automatic
fulfilment, Desired Acquisitions and source execution. Priority and notes remain
in product canon but were explicitly outside this foundation; grouping is
V2.002+. The focused delivery follow-up is `WISH-API-01`.

## 7. Verification evidence

Recorded gates include PHP syntax, PHPUnit unit, real-MariaDB schema,
application/persistence, concurrency and MIG-FND coverage, PHPStan, Composer
platform requirements, WordPress/Core smoke, manifest validation and
`git diff --check`. The final closure report records exact totals and the
independent review verdict.

Biblio Core is `2.3.0`, formal schema is `1022`, Biblio UI remains `0.13.0`,
and product scope remains `v2.001`.
