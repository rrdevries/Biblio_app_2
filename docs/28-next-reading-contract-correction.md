# 28 — Next Reading Contract Correction

Date: 2026-09-02

Scope: formal pre-C7 Core/domain correction; no C7 REST or UI

Status: **GO**

## 1. Why this correction exists

F2.9a/F2.9b correctly recorded and proved the product contract accepted on
2026-08-23. A later explicit product decision replaced that closed targetmodel.
Docs 14 and 15 remain historical evidence and are not deleted, but are marked
`SUPERSEDED FOR CURRENT NEXT READING CONTRACT`. This document is the current
canonical Next Reading truth and prevents both contracts from competing.

The correction is deliberately pre-C7. It changes Core domain, application,
persistence, ReadingRound orchestration and their tests only. It does not add a
Next Reading REST route, discovery endpoint, serializer, frontend, Elementor,
Crocoblock, Home UI or C7 Playwright scenario.

## 2. Current domain truth

Hierna lezen is one private, platform-wide, user-owned, manually ordered list
of separate planned future reading moments. Each entry owns:

- one stable server-issued `NextReadingEntryId`, the sole planning identity;
- its authenticated owner;
- exactly one mandatory Work;
- one contiguous position and immutable created-at;
- zero or one typed mutable `PreferredReadingSource`.

Preferred source is closed to `library_item` and `external_loan`. Work, Edition
and InternalLoan are not preference types. A preference is context only: never
a reservation, claim, availability guarantee, authorization proof or
ReadingRound provenance. It may be set, changed or cleared without replacing
the entry.

There is no content uniqueness. Same Work, different preferences and multiple
fully identical entries are all valid. Only entry ID and owner+position remain
technically unique.

## 3. Preference validation and source loss

Setting an Item preference resolves Item→Edition→Work inside the supplied
Library, requires current `canViewCollection` access and same Work, but does not
require `canUseAsDirectSource`. Starting a ReadingRound revalidates actual use.
Setting an ExternalLoan preference resolves an existing actor-owned loan and
requires the same Work.

The stored preference holds its discriminator, immutable source-ID snapshot,
Item Library-ID snapshot where applicable and a nullable live FK. Later source
deletion, inaccessibility or unusability does not remove or retarget the entry,
change Work/order, select a replacement or increment list version. The live FK
may become null through `ON DELETE SET NULL`; a safe read exposes a generic
`Voorkeursbron niet beschikbaar` state without protected foreign identifiers.

## 4. Ordering, mutation and Undo

New entries append. Complete reorder, add, remove, Undo, preference mutation
and automatic consumption all lock the same persistent owner list-state. A real
mutation increments version exactly once; a semantic no-op does not.

Manual removal deletes the active entry and atomically stores one restoration
record. Core returns an opaque token and expiry; persistence stores only its
SHA-256 hash with owner, full removed-entry snapshot, previous/next anchors,
original ordinal and expiry. Token lookup is owner-scoped and consume-on-use.
Unknown, foreign, malformed, used and expired tokens all resolve generically as
unavailable. Undo restores the same entry ID, Work, created-at and preference
snapshot, first between still-valid neighbors and otherwise at a bounded
original ordinal. The temporary centrally configurable default TTL is 30
seconds; this is a technical default, not a product setting.

Automatic ReadingRound consumption creates no Undo record.

## 5. ReadingRound transaction and matching

`CreateActiveReadingRoundService` owns one transaction and the owner list lock
for both active source paths. `StartReadingFromLibraryItemService` and
`StartReadingFromExternalLoanService` retain their source-specific validation,
then use the shared creation/consume boundary. Future callers can use
`StartReadingFromNextReadingEntryService` with an entry ID plus a concrete
chosen Item or ExternalLoan; Core resolves owner, validates Work/source and
consumes exactly that entry after successful round creation.

For a start elsewhere, the locked current list selects at most one entry:

1. lowest current position with the same Work and exact live actual source;
2. otherwise lowest current position with the same Work and no preference;
3. otherwise no mutation.

Snapshot-only IDs never count as live matches. Duplicate siblings remain.
No-match is a successful ReadingRound start. Failed source validation, round
creation, required consume write or commit rolls back the complete operation.
Historical/source-free registration stays outside consumption.

## 6. Schema 1008 and data-safe migration

The formal chain advances from 1007 to 1008 without reset. Migration recognizes
the supported old 1007 entry shape and maps:

| Old target | Current entry |
| --- | --- |
| Work | same Work, no preferred source |
| Library Item | same Work, preferred `library_item`, snapshots and live Item FK |
| ExternalLoan | same Work, preferred `external_loan`, snapshot and live loan FK |

Entry ID, owner, Work, position, created-at and owner list version are
preserved. Multiple old target forms for the same Work remain separate entries.
Old generated target fields and content-unique indexes are removed. The entry
table retains entry PK, unique owner+position, Work `RESTRICT` and nullable live
source `SET NULL` FKs. New non-unique indexes support owner ordering,
owner+Work+position and exact live-source matching. Named checks plus insert/
update triggers enforce the three valid preference shapes.

The new Undo table stores hashed token, owner, complete entry/preference
snapshot, anchors, ordinal, creation/expiry and supporting owner/expiry indexes.
Health checks cover all columns, indexes, FKs, delete rules, checks, triggers
and stored-data shape. Tests prove fresh empty install, real populated 1007
transition for all target types and duplicates, live source FKs, later SET NULL,
preservation, known retry/idempotence, health and rejection of invalid drift.

## 7. Privacy and activity

All commands and reads derive the actor server-side. Entry, preference and Undo
lookups are owner-scoped and non-enumerating. An Eigenaar or Beheerder of a
Library has no override over another user's list. Minimal projection returns
entry ID, safe Work presentation, order/list version and only safe human
preference context or generic unavailable state.

Add, remove, Undo, reorder, preference changes and automatic consumption write
no Library `ActivityEvent` and expose no public or Library-wide list query.

## 8. Evidence

The replacement tests cover domain identity and shape, unrestricted duplicates,
Item/ExternalLoan preference validation, change/clear, source loss, projection
privacy, manual removal and Undo lifecycle, deterministic matching, explicit
entry start, at-most-one consumption, transaction rollback and list versions.

Real independent-process race coverage includes:

- concurrent duplicate adds;
- remove versus start-from-entry;
- add versus start;
- preferred-source change versus start;
- external start versus reorder;
- Undo versus reorder;
- Undo versus ReadingRound consumption;
- stale manual mutation after automatic consumption.

Final verified results:

- Core unit: 252 tests, 963 assertions, PASS;
- Core real-MariaDB integration: 239 tests, 2,458 assertions, PASS;
- schema-1008 focused migration: 4 tests, 39 assertions, PASS.
- PHP syntax and PHPStan level 6: PASS, zero errors;
- WordPress/Core smoke: plugin active, Core loaded, init hook 1, HTTP 200;
- complete Biblio UI frontend: 174 tests, PASS;
- existing guarded Playwright 1A–1D: 40 tests, PASS, double cleanup and
  identical non-fixture fingerprint;
- isolated Elementor 1A clean import: PASS;
- manifest JSON and Git whitespace: PASS;
- complete canonical Core gate: PASS in 91 seconds.

The integration total includes migration, lifecycle, persistence, existing REST
regression, concurrency, privacy and rollback tests. Intentional negative-test
diagnostics remain expected while PHPUnit exits successfully.

## 9. C7 readiness and scope audit

Current Next Reading Core contract: **GO**. Historical F2.9 implementation:
**superseded by later contract correction**. C7 implemented: **NO**.

C7 is **READY FOR BUILD SCOPE**. Remaining technical work is capability-specific
Work/source discovery, guarded private REST routes, allowlisted serializers and
error mapping, frontend interaction and C7 E2E/accessibility evidence. No Next
Reading domain product blocker remains. The temporary Undo TTL need not become
a user-facing setting before C7.

This correction contains no Next Reading REST route, C7 frontend, Elementor,
Crocoblock, C7 Playwright or Home-module implementation and makes no roadmap
numbering or sequencing decision.
