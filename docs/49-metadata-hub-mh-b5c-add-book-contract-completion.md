# 49 — Metadata Hub MH-B5C Add Book contract completion

Status: **GO / CLOSED**

Date: 2026-09-06

Task severity: **High**

## 1. Outcome

MH-B5C completes the three server contracts required by the canonical Add Book
Wizard without adding UI or changing schema `1017`.

## 2. Explicit existing Edition selection

`POST /biblio/v1/libraries/{library_id}/items` additionally accepts selection
`{ "type": "existing_edition", "edition_id": "..." }` with a required ISBN
identifier. The Edition ID is a selector, never authorization evidence. Core
reauthenticates, resolves the current Library Context and `catalog.item_add`
capability, reruns canonical local ISBN resolution and accepts only an Edition
that remains in that current match set. The existing transactional path creates
only the new Library Item. Missing, stale or manipulated choices fail through
the generic validation contract; no automatic winner or duplicate Edition is
created.

## 3. Explicit existing Work selection

The manual selection may additionally be
`{ "type": "manual", "work_id": "..." }`. Candidate and existing-Edition
selections cannot carry a Work ID. Core delegates only this manual/new-Edition
case to the established `addWithNewEditionForExistingWork()` path, which
reauthorizes, requires the shared Work to exist, preserves it unchanged and
creates the concrete CAT-T1 Edition plus Library Item transactionally. Omitting
`work_id` keeps the existing provisional Work+Edition path. No search endpoint,
matching heuristic or automatic Work choice was added.

## 4. Existing Item context

Every B5A local match now returns `existing_item_count` plus `existing_items`.
Each compact entry allowlists only `item_id`, optional `inventory_number` and an
optional Location object containing `location_id` and `display_name`. The query
requires both the explicit current Library ID and Edition ID and orders by Item
ID. One batch query covers all local matches. Items from any other Library are
excluded even when they share the Edition.

## 5. Verification and boundaries

Verified on 2026-09-06:

- focused Add Book unit: 14 tests, 91 assertions;
- focused REST/integration: 62 tests, 1,505 assertions;
- complete unit suite: 407 tests, 1,711 assertions, with the two existing
  non-failing PHPUnit mock notices;
- complete integration suite: 336 tests, 4,058 assertions;
- PHP syntax and PHPStan: passed;
- Composer/platform, WordPress smoke HTTP 200, manifest JSON, Git whitespace
  and the complete repository quality gate: passed in 173 seconds.

No schema or migration, Add Book/Elementor UI, Work-search endpoint, Librarian
queue/UI, metadata correction UI, provider fusion, Expression layer, collector
field persistence, new role/capability or central Work/Edition mutation is part
of MH-B5C.
