# Metadata Hub MH-B1 — ISBN identity exit evidence

Date: 2026-09-05

Task severity: High

Target: schema 1014 and local-first Edition identity foundation

## Scope and verdict

MH-B1 implements ISBN normalization/conversion, a read-only integrity audit,
schema 1014, canonical Edition claims, typed local resolution, database-backed
race handling and AddLibraryItemService integration. It deliberately does not
implement providers, HTTP, provider caching, provider provenance writes, REST
lookup routes, UI, covers, OCR or Series Intelligence.

Verdict at implementation review: **GO — FOUNDATION READY FOR PROVIDER
ADAPTERS**. MH-B2 remains a separate task.

## Pre-migration evidence

The current local database was queried read-only before any source or schema
change:

| Measure | Count |
|---|---:|
| Editions | 1 |
| with ISBN-13 | 0 |
| ISBN-10 only | 0 |
| without stored ISBN | 1 |
| invalid ISBN | 0 |
| exact collisions | 0 |
| canonical ISBN-10/13 equivalence collisions | 0 |
| internal pair conflicts | 0 |
| separator/casing deviations | 0 |

There were no conflicting internal record IDs and no migration blocker.

Repeatable maintenance command:

```sh
ddev exec wp --path=web eval-file /var/www/html/web/wp-content/plugins/biblio-core/scripts/isbn-integrity-audit.php
```

Exit `0` and status `OK` mean blocker-free. Exit `1` and status `BLOCKED` list
only affected Edition IDs and collision keys. The command performs no writes.

## Schema 1014

`biblio_edition_identifier_claims` has a non-null ASCII
`canonical_isbn_13 CHAR(13)` primary key, a non-null binary-collated and unique
`edition_id`, a restrictive Edition foreign key and a 978/979 shape check.
Absence of a claim represents unknown/no-ISBN. Checksum and equivalence remain
domain/audit responsibilities.

`biblio_edition_metadata_provenance` has a non-null `provenance_id` primary key,
restrictive `edition_id`, bounded non-null provider key and record ID, UTC
retrieval time, exact match method, normalized query identifier type/value and
accepted confirmation state. Edition/provider/record/query is unique and an
Edition/time index supports later reads. This slice writes no provenance and
stores no payload, URL, credential, cover or Series evidence.

## Runtime integrity

All ISBN primitives delegate to one rule implementation. ISBN-10 creates or
reuses the same canonical claim as its 978 ISBN-13 alias; 979 never fabricates
ISBN-10. Paired identifiers must be equivalent.

New ISBN-bearing Edition flows resolve locally first. If none exists, Edition
and claim are inserted in the compound transaction. A duplicate claim rolls the
entire losing transaction back; the service then resolves and uses the committed
winner. The existing Edition's Work always wins. No title, contributor or
provider heuristic participates. Existing Edition, manual and no-ISBN paths
remain supported.

## Verification matrix

- Unit: normalization, checksums, X, conversion, 979, typed invalid, no-ISBN,
  local exact/none/legacy ambiguity and Work preservation.
- Migration/DB: health, uniqueness, provenance structure, valid preservation,
  ISBN-10 claim backfill, no-ISBN, conflict failure and retry safety.
- Application: all three AddLibraryItemService paths, ISBN alias reuse, manual
  same-title separation and unchanged authorization/context behavior.
- Concurrency: two independent PHP processes race with equivalent ISBN-10 and
  ISBN-13 input; both return controlled Item success with one Work, one Edition
  and one claim.
- Full gate: PHPStan clean; 345 unit tests / 1,348 assertions (two existing
  PHPUnit notices); 314 integration tests / 3,866 assertions; WordPress smoke,
  manifest JSON and whitespace checks green in 136 seconds.

## Independent review record

1. Product Guardian: manual/no-ISBN and local reuse remain first-class.
2. Domain Architecture: ISBN identifies Edition only; Work is not inferred.
3. Database/Migrations: audit precedes DDL, restrictive FKs and post-health
   guard the version bump, and retry is idempotent.
4. Concurrency/Data Integrity: database uniqueness is final authority and the
   losing transaction leaves no Work/Edition side effect.
5. Security: authorization still precedes catalog lookup; no network, secret,
   payload or personal audit data exists.
6. Testability: parser, resolver, migration, persistence and real-process race
   are independently covered.
7. Scope/versioning: schema is exactly 1014; provider/runtime work remains
   deferred to MH-B2 and later slices.
