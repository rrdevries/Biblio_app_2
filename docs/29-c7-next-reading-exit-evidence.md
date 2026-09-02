# 29 — C7 Hierna lezen build and exit evidence

Status: **GO**

Date: 2026-09-02
Capability: C7 — Hierna lezen
Begin-HEAD: `47f69e6b408e3d905308a68e459162303cb87209`

## 1. Scope and contract reuse

C7 is a thin private adapter and standalone browser screen above the corrected
Next Reading contract in ADR-008 and doc 28. It does not redesign entry
identity, duplicate behavior, preference semantics, Undo, ordering or
ReadingRound consumption. Every entry remains an independently identified
future reading intention for one Work; equal Works and equal preferred sources
remain valid duplicates.

There is no domain or schema delta. The schema version remains 1008. C7 adds an
actor-authenticated bounded Work/source discovery application read boundary,
WordPress persistence queries for that boundary, guarded `biblio/v1` transport,
an allowlisted standalone Biblio UI adapter and capability tests.

## 2. Private REST contract

All routes use cookie authentication plus `X-WP-Nonce`, resolve the actor only
inside production composition and call named Core application services:

- `GET /biblio/v1/me/next-reading` — authoritative list/version;
- `POST /biblio/v1/me/next-reading` — Work-first add with nullable typed source;
- `DELETE /biblio/v1/me/next-reading/{entry_id}` — expected version, list plus
  opaque server Undo token/expiry;
- `POST /biblio/v1/me/next-reading/undo` — server token Undo;
- `POST /biblio/v1/me/next-reading/reorder` — complete ordered entry-ID list
  plus expected version;
- `PATCH /biblio/v1/me/next-reading/{entry_id}/preferred-source` — set/change;
- `DELETE /biblio/v1/me/next-reading/{entry_id}/preferred-source` — clear;
- `GET /biblio/v1/me/works?q=...&limit=...&cursor=...` — bounded stable Work
  title discovery;
- `GET /biblio/v1/me/works/{work_id}/preferred-source-options` — current
  actor-visible same-Work Library Items and actor-owned ExternalLoans.

`PATCH` is used for preferred-source replacement because it matches the
existing versioned partial-mutation convention in the REST controller. Add has
no expected-version field because the corrected Core append service deliberately
serializes on owner state and does not expose optimistic input; remove, reorder
and preference mutations retain the Core list-version contract.

## 3. Serialization, privacy and failures

List serialization is frontend-specific: list version; entry ID and position;
minimal Work ID/title; preferred-source presentation. Available sources expose
only type and a safe human label. Unavailable saved preferences expose only
`state=unavailable` and `Voorkeursbron niet beschikbaar`, never a protected ID
or foreign label. Work discovery exposes only Work ID/title. Source discovery
never enumerates inaccessible Items or another user's loans.

Unknown and foreign Work, entry or source failures share non-enumerating 404
mapping. Stale list and unavailable/expired/used Undo map to 409. Central request
parsing rejects unknown fields, malformed typed IDs, invalid source shapes,
unbounded limits and invalid/mismatched opaque cursors. Controllers contain no
repository access or authorization logic.

## 4. UI and interaction

`[biblio_next_reading_app]` supplies a separate technical mount for the planned
`/hierna-lezen/` Page. Its module is enqueued only on that slug and has no
selected-Library dependency. Existing Mijn Bibliotheek overview/detail routing
is unchanged.

The screen implements loading, safe retry, exact empty state, populated list,
submitting locks and 409 reconciliation. Rows are semantic list items and never
links/clickable containers. Actions are named buttons: choose/change/clear
source, Omhoog, Omlaag and direct Verwijderen. First/last movement controls are
disabled. Mutations apply only authoritative server responses.

Add always starts with bounded Work title search. The selected Work then shows
optional current sources and a clear `Geen voorkeursbron` option. Duplicate
guards do not exist. Search uses abort plus revision control. Remove opens no
confirmation and presents the server-issued `Ongedaan maken` action. Native
dialogs, deliberate focus restoration, polite live status, minimum 44px
controls, wrapping actions and narrow-layout rules preserve keyboard and reflow
use.

## 5. Guarded acceptance evidence

The fixture remains local/DDEV/opt-in guarded, uses only marked E2E users and a
temporary marked WordPress Page, and creates deterministic duplicates, Item and
ExternalLoan preferences, an unavailable preference, cross-Library visibility,
a foreign loan and reorder/Undo state. Cleanup now covers schema 1008 including
Next Reading lists, entries and Undo records, the marked Page and all C7 source
fixtures. The non-fixture fingerprint uses the complete schema-1008 table set.

Focused proof includes:

- REST integration: 7 tests / 215 assertions;
- C7 frontend contract: 11 tests;
- C7 Playwright: 9/9 scenarios, combining the required empty/add/source/
  duplicate/reorder/remove/Undo/preference/unavailable/stale/foreign/keyboard/
  narrow-reflow cases;
- 200% acceptance strategy: the established equivalent 640 CSS-pixel reflow
  viewport, representing a 1280px layout at 200%, with document overflow,
  reachability and 44px target assertions. This is not represented as a manual
  browser-toolbar zoom measurement.
- complete REST regression: 46 tests / 978 assertions;
- complete frontend regression: 185/185;
- Start Reading/automatic consumption: 3 tests / 23 assertions;
- Core unit: 252 tests / 970 assertions;
- Core MariaDB integration: 246 tests / 2,690 assertions;
- complete guarded Playwright: 49/49 (existing 1A–1D 40/40 plus C7 9/9);
- PHP syntax, PHPStan level 6, Composer metadata/platform, WordPress smoke,
  manifest JSON and Git whitespace: PASS;
- fixture guards: PASS; first cleanup, second cleanup and final verify-clean all
  report zero C7/E2E rows/users/pages; the before/after schema-1008 non-fixture
  fingerprint is identical.

## 6. Explicit non-scope

C7 adds no schema 1009, new Next Reading domain rule, ReadingRound lifecycle
change, Home module, start-from-entry UI, Work detail link, drag-and-drop,
reminder, public sharing, ActivityEvent, Crocoblock logic, permanent WordPress
Page or Elementor design.

## 7. Manual WordPress/Elementor shell

No permanent Page was created. To expose C7, the user may create one ordinary
WordPress Page with slug `hierna-lezen`, hide the theme/Page title so the app's
single H1 remains, place `[biblio_next_reading_app]` in a shortcode block/widget
and optionally add a navigation link labelled `Hierna lezen`. Elementor remains
shell-only; it must not implement list, auth, ordering, Undo or source logic.

## 8. Verdict

**GO. C7 Hierna lezen is implemented and closed.** There are no remaining C7
product or technical blockers. Later Home top-three, start-from-entry UI and
other separately approved capabilities remain outside this closure.
