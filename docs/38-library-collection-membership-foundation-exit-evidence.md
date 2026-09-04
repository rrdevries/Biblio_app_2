# 38 — Library Collection and membership foundation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-04.

## 1. Scope and canonical contract

This High-severity Slice 4 implements only the Biblio Core technical foundation
for Library-owned Collections and Collection↔Item membership. Active Collections
contain only active Items from the same Library. One Item may occur in several
Collections, Collections may be empty, and Collection order plus Item order are
manual. Collection deletion, smart Collections, Wishlist/loan integration,
goals, query composition, REST/UI and deferred metadata remain outside scope.

## 2. Aggregate, membership and lifecycle

`LibraryCollection` owns stable identity, Library identity, display and simple
casefold/whitespace-normalized name, optional description, active/archived
status, manual position, optimistic version and microsecond UTC timestamps.
Active names are unique within a Library. Detail, order and content changes
advance version and `updated_at`; Item-owned metadata, Location, cover, reading
status and archive changes do not.

`CollectionMembership` is a separately identified period with Library,
Collection and Item identity, active/inactive state, manual Item position,
`added_at`, and paired `ended_at`/end reason. Ordinary removal uses `removed`;
Item archive uses `item_archived`. A later explicit re-add creates a new active
period at the current bottom. Collection archive is read-only and excluded from
active context while preserving memberships, Item state and internal order;
restore exposes only memberships whose Item and membership remain active.

## 3. Application, authorization and concurrency

`ManageLibraryCollectionsService` is the named application boundary for create,
update, archive/restore, Collection reorder and atomic ordered membership save.
It resolves the current WordPress actor server-side, builds Library Context and
requires active Owner authority or the canonical Manager permission
`collections` before accessing a Collection. Missing, foreign and unauthorized
resources use the same non-enumerating `CollectionNotAvailable`; unavailable
Items use one scoped `CollectionItemNotAvailable`.

Library mutation locking serializes active-name and Collection-order changes.
Collection rows and active membership drafts are locked inside one transaction;
`CollectionVersion` provides compare-and-swap stale detection. Repeating an
already achieved Collection target or unchanged draft is a no-op; divergent
transitions and duplicate/invalid drafts use typed conflicts.

## 4. Persistence, reads and Archive interaction

Schema 1013 adds `biblio_collections` and
`biblio_collection_memberships`. Composite Library identities and foreign keys
reject dangling/cross-Library relationships. Generated nullable unique keys
enforce one active normalized name and one active Collection+Item membership
without deleting historical periods. Checks cover supported states/reasons,
positive positions/version, paired lifecycle fields and timestamp ordering.

`LibraryCollectionQueryService` authorizes Library Context before batch reads
for active ordered Collections, Collection context, active Collections per Item,
active Items per Collection, active counts and previous context for currently
archived Items. Active membership reads join active Collection, membership and
Item state, and therefore cannot leak archived Items or archived Collections.

The existing Item archive service now invokes a narrow membership archive port
after the Item/history CAS write and before audit append in the same transaction.
All active memberships become inactive history at the exact archive instant.
Item restore performs no membership mutation. Collection archive/restore never
changes Item lifecycle. ReadingRounds, ratings, reviews, notes, goals and other
user-owned data are untouched.

## 5. Migration and verification

The 1012→1013 migration is forward-only and leaves all existing Items and their
archive history untouched; new Collection tables start empty. Completed runs and
the known one-table-created partial state converge safely. Any malformed partial
definition fails before version bump. Postcondition health checks all columns,
generated expressions, indexes, foreign keys and checks.

Targeted and complete verification covers domain invariants, authorization and
non-enumeration, manual order, duplicate/cross-Library rejection, persistence,
batch reads, Collection and Item lifecycle interaction, production composition,
migration retry/partial state and prior catalog/personal regressions. Exact final
results are: targeted unit 45 tests/162 assertions; targeted Collection,
schema-1013 and Archive MariaDB integration 10/56; complete migration-chain
integration 11/61; complete unit 308/1,216; complete MariaDB integration
290/3,192. PHP syntax, PHPStan, Composer metadata/platform, WordPress smoke,
manifest and Git whitespace passed. The complete quality gate took 126 seconds.

## 6. Review and residual scope

An explicit independent second pass reviews architecture, security/privacy,
data integrity/schema, tests/regression and Product Guardian scope. No visible
Collection ActivityEvents were added because they are not canonical v2.001
behavior.

The complete Mijn Bibliotheek query remains blocked on Slice 5 existing-source
filter/read integration and Slice 6 typed query composition. The next approved
technical prerequisite should therefore be the Medium-severity existing-source
filter/read foundation. No push is part of this slice.
