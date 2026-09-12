# SEARCH-UI-01A — Full-page bibliographic search

Date: 2026-09-13
Status: **GO / CLOSED**

## 1. Frontend audit

The existing Biblio UI already had the required single App Shell, Page-scoped
shortcode modules, Ink/Light design tokens, central `createBiblioApi` client,
strict response decoders, immutable local view state, request revision/abort,
live regions and guarded Playwright fixture. SEARCH-UI-01A extends those
contracts; it introduces no second shell, router, global store or design system.

The only top-level production transport used is authenticated
`POST /biblio/v1/me/bibliographic-searches`. The current text endpoint rejects
a checksum-valid ISBN. The existing generic discovery paths cannot be wired to
this Page as a small read-only Edition flow without introducing new selection,
snapshot or materialization decisions, so ISBN is not promised by this slice.

## 2. Page mounting / shell

`SearchAppShortcode` owns `[biblio_search_app]` on the ordinary WordPress Page
slug `/zoeken/`. The shortcode emits only the REST root, nonce, authenticated
state and shared navigation destinations. `Plugin` registers the Search module
and enqueues it only when that shortcode/Page is active. The shared navigation
now includes `Zoeken` for Library, Search, Wishlist and Next Reading mounts.

The module renders inside the existing Biblio Page Shell. Elementor needs no
business logic or hand-built composition.

The closure runtime contains that published ordinary Page as local WordPress
post `38`, with exact slug `zoeken` and exact shortcode content. It is not an
E2E-marked fixture and is not part of Git.

## 3. Search field

The semantic `<form>` uses explicit submit and the truthful label/placeholder
`Zoek op titel of auteur`. Input is trimmed and whitespace-normalized to the
existing text contract; empty or overlong input is rejected locally. Submission
uses `query`, `author_cursor: null` and `work_cursor: null`, replaces both result
groups and resets both continuations. No request occurs on individual
keystrokes.

ISBN routing is explicitly deferred. The supporting copy says that ISBN search
will be connected in a later step. Query and submitted query stay in local Page
state; URL/history state is a separate follow-up because no reusable router
contract exists.

## 4. `Alles` result composition

`Alles` is the sole functional search view and is rendered as a non-clickable
current-view marker, not as a dead tab. It contains separate semantic regions:

- `Auteurs`: compact editorial Author entities using only display name;
- `Boeken`: Work results with title, ordered Authors and reliable Series
  context, plus the existing portrait no-cover treatment.

The two entity types are never interleaved. Empty groups are omitted. No total
count, Edition/ISBN/publication field, cover guess, active drill-down or future
tab is fabricated.

## 5. Pagination

Authors and Works keep independent nullable cursors. `Meer auteurs` posts the
submitted query with only `author_cursor`; the returned Authors append while
Works and their cursor remain untouched. `Meer boeken` does the mirror operation
with only `work_cursor`. There is no global next page or infinite scroll.

After append, focus moves to the first newly added result in that group and a
polite live region announces the addition. The other group and surrounding
scroll context remain stable.

## 6. Selector preservation

The strict Author decoder requires and retains `author_selector`; the Work
decoder requires and retains `work_selector`. Both remain opaque values in the
in-memory frontend model for SEARCH-UI-01B. The browser never parses,
reconstructs or displays them, never copies them into DOM or accessible text,
and never treats `result_id` as authority.

## 7. Loading / empty / failure states

The search form remains available throughout. Initial loading uses a quiet
in-content status rather than a blocking overlay. Before search the Page offers
an invitation; a complete miss has one central empty state; one empty group does
not create an empty heading when the other has results.

Transport/service and session failures use safe user-facing copy and a retry
that repeats only the submitted top-level query. Typed provider failure is a
non-blocking notice: usable local results remain rendered. Raw providers,
HTTP/status details, stack traces, cursors and cryptographic details are never
shown. Revision plus `AbortController` prevents stale responses from replacing
newer state.

## 8. Visual LAB compliance

The full Page follows `Editorial Library × Serious Utility`: Soft Ivory content,
Ink shell, restrained brass accents, editorial serif identity text, sans-serif
controls, hairlines, small radii, little shadow and generous whitespace. The
prominent search field remains proportional rather than dashboard-like.

Authors and Books have distinct grouped patterns. Book placeholders are
portrait, never square. `Alles` has no statistics cards, modal, drawer, heavy
filter rail or generic admin styling.

The approved later specialised `Zoeken → Boeken` view remains canonical but
unimplemented: a separate right filter plane starts at result height with a
clear gutter; `Zoeken in` is a compact dropdown, not radios; `Boeksoort` is a
multi-option group; `Taal` belongs under `Over de uitgave`; rating and `In mijn
bibliotheek` filters are excluded.

## 9. Responsive behavior

Desktop at 1440 px uses the spacious grouped composition. At 900 px the Work
layout reduces cleanly while the search remains primary. At 390 px the form,
sections and actions stack full-width with touch-safe controls and no horizontal
overflow. No desktop filter plane is rendered at any width.

## 10. Accessibility

The Page has one ordered heading hierarchy, a real labelled search form,
keyboard submit, native buttons, visible token-based focus, `aria-busy`, polite
status announcements and an alert associated with the result context. Appended
results receive predictable programmatic focus. Reduced-motion and existing
contrast behavior remain inherited. Selectors and cursors never enter labels.

## 11. Compatibility

Search is an additive Page module. The existing App Shell, Mijn Bibliotheek,
Book Detail, Wishlist, Hierna lezen and Add Book remain consumers of their
existing contracts. Shared mount configuration tests cover the added Search
destination. Core search, Author selector/Works, Work selector/Editions and all
materialization contracts are unchanged. No browser provider call or write is
introduced.

## 12. Deferred to SEARCH-UI-01B+

- Author-to-Works interaction;
- Work-to-Editions interaction;
- specialised Books and Authors tabs;
- the separate Books view/filter plane and functional filters;
- ISBN routing and Edition language filtering;
- advanced search, Series results, sort and URL/history state;
- Wishlist, Add Book, materialization and other consumer actions.

## 13. Tests / browser QA / quality gates

Targeted evidence before the final closure gate:

- Search decoder/state plus affected shell/runtime unit tests: `108/108` pass;
- isolated Biblio UI PHP smoke: pass;
- changed PHP syntax in DDEV: pass;
- JavaScript and Playwright spec syntax: pass;
- guarded authenticated SEARCH-UI-01A Playwright spec: `9/9` pass, including a
  genuinely pending request and zero-item continuation focus;
- visual review at 1440, 900 and 390 px: clean, portrait covers and no overflow;
- guarded cleanup and zero-residue verification: pass;
- whitespace: pass.

The first independent review found nonce recovery, temporarily dead pagination
controls, zero-item focus, partial-failure announcement and closure-registration
gaps. Those findings are corrected and their focused tests pass.

Final closure evidence:

- complete Biblio UI JavaScript: `262/262` pass;
- complete guarded Chromium: `83/83` pass, including existing Add Book,
  Wishlist, App Shell, catalog, Book Detail and personal-flow regressions;
- guarded double cleanup, zero residue and identical non-fixture before/after
  fingerprint: pass;
- complete Core unit: `618/618`, `2427` assertions, two existing PHPUnit
  notices and no failures;
- complete real-MariaDB integration: `431/431`, `5297` assertions;
- complete Core gate: Composer metadata/platform, PHP syntax, PHPStan,
  WordPress smoke, manifest and whitespace pass in `291` seconds;
- Biblio UI PHP syntax, focused PHPStan and isolated WordPress smoke: pass;
- manifest JSON, JavaScript/Playwright syntax and final whitespace: pass;
- independent re-review after all findings were corrected: **NO BLOCKERS**.

## 14. Actual V1 data rule

No current, historical or copied V1 data, old `/data/`, MIG-01 snapshot, DATA-01
fixture or earlier export/count was used. Browser responses are deterministic,
source-neutral test fixtures and create no bibliographic domain records.

## 15. Schema/Core/UI versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.14.0`, unchanged;
- Biblio UI: `0.16.0`, bumped for the production PHP/JavaScript/CSS module.

## 16. Git

The slice is closed by one local commit named
`feat: add full-page bibliographic search`, with no push to `origin/main` and a
clean working tree. Branch/ahead state, exact commit and push status are reported
from Git after the commit.
