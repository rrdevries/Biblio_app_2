# 47 — Metadata Hub MH-B5B Add Book commit exit evidence

Status: **GO / CLOSED**

Date: 2026-09-06

Task severity: **High**

## 1. Outcome

MH-B5B implements the authorized, transactional server-side commit behind Add
Book. MH-B5A remains the lookup/review layer. Commit reauthenticates, resolves
the current Library Context and `catalog.item_add` capability, validates its
own request and reruns canonical ISBN local-first immediately before writing.

An exact current Edition is reused. A local miss creates a new provisional Work
without a new matching heuristic, a concrete CAT-T1 Edition and a Library-owned
Item. Neither path confirms Work/Edition metadata or grants Biblio Librarian
authority.

## 2. REST and application contract

`POST /biblio/v1/libraries/{library_id}/items` accepts exactly:

- `identifier`: an ISBN input or `null`;
- `selection`: `{ "type": "manual" }` or `{ "type": "candidate",
  "lookup_id": "...", "candidate_id": "..." }`;
- `observed_fields`: an allowlisted physical-book observation object;
- `classification`: the existing required `book_type_id`, `genre_ids` and
  `subject_ids` catalog-context initialization;
- `item`: only the already-supported optional `inventory_number` and
  `location_id`.

The allowlisted `201` result returns `item_id`, `edition_id`, `work_id`,
`edition_title`, `work_title_status` and `existing_edition`. The client receives
opaque snapshot/candidate IDs only; provider record IDs, provider Work keys,
payloads and authorization state do not enter the commit request.

## 3. Snapshot and evidence persistence

Schema moves from 1016 to 1017 because the approved semantics cannot be made
durable and atomic without server-owned snapshot and user-observation storage.
The additive migration creates:

- `biblio_metadata_lookup_snapshots`;
- `biblio_metadata_lookup_candidates`;
- `biblio_metadata_user_observations`.

Snapshots are bound to actor, Library and canonical ISBN, expire after 30
minutes, retain the exact reviewed candidate as bounded deterministic JSON and
are content-hash protected. Commit rejects absent, expired, cross-actor,
cross-Library or ISBN-mismatched handoffs and never silently refetches a
provider.

User-observed evidence records actor, Library, Item, Edition, field, exact JSON
value/hash, observation time and `physical_copy_add_book` source. It remains
distinct from provider evidence and confirmation state. Existing central
metadata is not overwritten; differences are retained and fields already
supported by MH-B4 receive a non-confirming correction proposal. Provider
candidate evidence/provenance is retained separately.

## 4. Transaction and concurrency

An explicit transaction participant extends the existing
`AddLibraryItemService` boundary. Work, Edition, canonical ISBN claim,
classification context, Item, audit and required evidence therefore form one
commit. An injected evidence-write failure proves that no partial catalog,
claim, context, audit or evidence row remains.

The existing MH-B1 unique canonical-ISBN claim stays authoritative. On a race,
the losing provisional transaction rolls back, local resolution finds the
winning Edition and the second valid physical addition creates its own Item and
evidence against that Edition. The concurrency proof leaves one Work, one
Edition, two Items and both observations.

## 5. Verification

Verified on 2026-09-06:

- focused lifecycle: 9 tests, 93 assertions;
- focused REST/API: 59 tests, 1,458 assertions;
- focused same-ISBN concurrency: 2 tests, 22 assertions;
- schema 1017 migration/retry safety: 2 tests, 7 assertions;
- complete unit suite: 403 tests, 1,684 assertions, with only the existing
  non-failing PHPUnit mock notices;
- complete integration suite: 333 tests, 4,010 assertions;
- PHP syntax for Core source and tests: passed;
- PHPStan: no errors;
- Composer/platform, WordPress smoke, manifest JSON, Git whitespace and the
  complete repository quality gate: passed in 160 seconds.

## 6. Explicit exclusions and review verdict

No Add Book/Elementor UI, Biblio Librarian UI or queue, new correction workflow,
provider merge/fusion, Work-match heuristic, Expression layer, D-COL-01
collector fields, rare-books behavior or new role/capability is included.

The independent second review pass checked the final implementation against
ADR-014, MH-B4/MH-B5A, CAT-T1, Biblio Librarian governance, authorization,
ownership, transactionality and regressions. Final verdict: **GO / CLOSED**,
with no remaining P0/P1/P2/P3 finding.
