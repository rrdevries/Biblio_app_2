# 17 — F2.10 Library Identity & Context exit evidence

Status: **GO**

Date: 2026-08-23

Scope: A1 / `Library Identity & Context Readiness` from
`docs/16-phase-2-rest-gap-elementor-readiness-analysis.md`.

## Closed readiness gaps

F2.10 closes the four A1 gaps without entering F2.11, F2.12 or UI work:

1. Library has a required persisted display identity in addition to its stable
   opaque ID.
2. Automatic personal Privébibliotheek provisioning persists the canonical
   default `Mijn Bibliotheek`.
3. The authenticated actor can obtain an active-membership Library list with
   identity, personal designation and server-calculated capabilities.
4. One explicit target Library ID is resolved and validated inside Core;
   missing, foreign and inactive context is rejected without client trust.

## Final contracts

`LibraryName` is valid UTF-8, trimmed, internal whitespace-normalized,
non-empty and at most 191 characters. Names are not unique and never replace
`LibraryId` as the reference identity. General Library create/rename remains
deferred.

`LibraryContextQueryService` accepts no caller-supplied actor or trusted
`LibraryContext`. It resolves the authenticated user, obtains only that
actor's membership records, builds `LibraryContext` in Core and evaluates the
existing authorization policy. Its immutable projection contains Library ID,
name, type, status, designated-personal marker and capabilities for collection
view, catalog add/context/classification, contribution publication/moderation,
direct Item use and InternalLoan receipt. Capability output is informative;
each mutation must still re-authorize current state.

## Migration and persistence

Schema 1007 adds binary-collated `VARCHAR(191) library_name` to
`wp_biblio_libraries`. Migration 1006→1007 recognizes only the absent-column
state or its own expected partial column state, adds the column nullable,
backfills null/blank rows with `Mijn Bibliotheek`, makes the column `NOT NULL`
and adds `libraries_name_non_empty`. The version is bumped only after the
1007 health postcondition succeeds. The same migration adds non-unique
`memberships_by_user (user_id, library_id)` for the actor list.

Real MariaDB tests prove the 1006 upgrade, backfill, retry/idempotence,
NOT-NULL/CHECK defenses and health. Fresh install still starts at the unchanged
formal baseline 1000 and runs the complete ordered chain through 1007.

## Test evidence

Targeted contract coverage proves:

- LibraryName normalization and personal default;
- provisioning persistence and concurrent one-winner reuse with the default
  name;
- Library identity round-trip;
- actor-scoped list ordering, designated marker and capability differences;
- rejection of foreign, missing and inactive context;
- schema backfill, data constraints and idempotence;
- production composition and adapter-facing boundary constraints.

Canonical full-gate result:

- PHP syntax: passed;
- PHPStan level 6: passed, no errors;
- unit: 222 tests / 857 assertions;
- real-MariaDB integration: 169 tests / 1,267 assertions;
- total: 391 tests / 2,124 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- Composer metadata, manifest JSON and Git diff checks: passed;
- visible worktree status before/after the gate: unchanged.

## Exit verdict

**GO** — the Elementor blocker `Library Identity & Context Readiness` is
closed. F2.10 is complete. F2.11 — Catalog UI Read Models is the next required
Elementor blocker, followed by F2.12 — WordPress UI Adapter Foundation. There
is no architectural reason to build Elementor UI before those two slices and
the readiness gate are complete.

No push was performed.
