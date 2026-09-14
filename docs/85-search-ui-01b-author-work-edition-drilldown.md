# SEARCH-UI-01B — Author → Works → Editions drill-down

Date: 2026-09-13
Status: **HUMAN GO / CLOSED**

Task severity: **High**. A read-only frontend/REST audit preceded coding. The
implementation stayed with one primary owner and received a separate second
review pass against identity, state, accessibility, visual baseline and scope.

## 1. Frontend audit

The closed SEARCH-UI-01A/F1/F2 Page already retained the top-level query,
active tab, Author/Work results, independent cursors and typed provider
attempts in local immutable state. Its central API client, safe error mapping,
abort/revision guard, live region, focus patterns and responsive results/rail
composition were directly reusable.

Top-level Author and Work decoders already retained `author_selector` and
`work_selector` only in memory. The selected-Author endpoint returns pageable
Works with a new `work_selector` on every item; the selected-Work endpoint
returns concrete nullable Edition metadata. No required backend field was
missing.

## 2. Navigation and state model

The Page now supports only these explicit local views:

```text
results → authorWorks → workEditions
results → workEditions
```

The added local state is limited to selected Author, selected Work, the two
drill-down pages and one contextual return target. There is no global store,
generic history stack, router, URL mutation or browser-history hack.

A new search aborts the active request, increments the shared request revision,
clears both selections and drill-down pages, resets to `results`/`Alles` and
submits the normal top-level query. Internal Back preserves the already loaded
top-level state, active tab, Author Works page and best-effort scroll position.

## 3. Author → Works

Every Author in `Alles` and `Auteurs` has a native `Bekijk werken` button with
an Author-specific accessible name. It posts exactly:

```json
{"author_selector":"<opaque>","cursor":null}
```

Continuation repeats only the same selector plus returned opaque cursor and
appends `items`. Display name, canonical Author ID, result ID and provider data
are never request authority. SEARCH-AUTH-UI-01 now preserves the selected
Author's bounded human disambiguation context in that focused header instead of
the former source label, followed by compact Work rows with title, Authors,
reliable Series context and one real Editions action.

Loading is inline. Normal empty, transport/session failure, typed complete
provider failure, typed partial failure and pagination retry are distinct.
Usable Works remain visible when external expansion fails.

## 4. Work → Editions

Top-level Works and Works reached from an Author share one
`openWorkEditions()` flow and one focused Edition composition. The request is
exactly:

```json
{"work_selector":"<opaque>","cursor":null}
```

Continuation appends by the returned Edition cursor. Raw `work_id`,
`result_id`, title and provider identity are never authority. The decoder also
requires every Edition's parent Work result identity to match the currently
selected Work before state is committed.

Edition rows are deliberately denser than Work cards. They render title and
subtitle first, then available language/publication/publisher/format context,
Edition contributors, ISBN and page count. Null fields create no placeholder.
ISBN-less Editions remain normal visible results. No cover or action is
fabricated.

## 5. Failures and stale protection

Typed provider failure retains usable local items and appears as compact rail
status. A complete zero-item provider failure has an in-context drill-down
retry rather than restarting top-level search. Session/authentication recovery
reuses the existing Page pattern.

A safe 400 response at the selected-entity boundary is presented as an
outdated-result state that asks the user to search again. It exposes no token,
mapping, signature or provider detail and never falls back to visible IDs.

All top-level, Author and Edition requests share abort plus a monotonically
increasing request revision. A response from an earlier Author, Work or query
cannot replace the current view. Focus moves to the focused-view heading after
both loading and the final async render, to the first appended item after
pagination, and back to the originating action when returning.

## 6. Security, mutations and backend boundary

Selectors stay opaque strings held only in the in-memory model and exact POST
body. They are never decoded, reconstructed, logged, displayed, copied to DOM
attributes or used in accessible labels. DOM focus markers contain only local
group/index coordinates.

The frontend calls only the three authenticated bibliographic read routes. It
contains no PATCH/DELETE, materialization, Wishlist, Add Book, Item, Library or
other mutation. Biblio Core, REST, schema, provider adapters and persistence are
unchanged.

## 7. Visual, responsive and accessibility integration

The closed 01A composition remains the baseline: App Shell, results header,
search field, tabs, main/rail ratio, truthful scope card and design tokens are
unchanged. Focused views reuse Soft Ivory/Ink, serif bibliographic identity,
sans controls/metadata, hairlines, restrained brass, small radii and minimal
shadow. The rail remains structural and receives only contextual help.

Native buttons have explicit accessible names. Headings remain ordered under
the Page H1, loading and additions use the polite live region, errors use
alerts, `aria-busy` covers the active result pane and the existing reduced-
motion behavior remains intact. Browser evidence covers desktop 1440 px,
tablet 900 px and mobile 390 px with no horizontal overflow and touch-safe
stacking.

Visual evidence:

- `.local/search-ui-01b-visuals/desktop-1440-author-works.png`;
- `.local/search-ui-01b-visuals/desktop-1440-work-editions.png`;
- matching Author/Edition evidence at 900 px and 390 px.

## 8. Compatibility and deferred behavior

SEARCH-UI-01A/F1/F2 tabs, previews, top-level pagination, rail, scope copy,
duplicates and failure behavior remain covered by their existing browser
suite. App Shell, Wishlist, Add Book, Hierna lezen and every existing Core
consumer keep their contracts.

Still deferred:

- Wishlist and Add Book actions;
- Edition selection or materialization;
- ISBN routing;
- URL/deep-link/browser-Back state;
- filters, sort and advanced search;
- Series/Collections discovery or detail.

## 9. Verification

Recorded focused evidence:

- Search decoder/state/security: `16/16` pass;
- combined SEARCH-UI-01A/01B Chromium: `16/16` pass;
- responsive Author/Edition visual capture at 1440/900/390: pass;
- Biblio UI PHP syntax and isolated WordPress smoke: pass;
- complete Biblio UI JavaScript: `267/267` pass;
- JavaScript/Playwright syntax and Git whitespace: pass.
- complete guarded Chromium suite: `90/90` pass;
- guarded cleanup: two idempotent cleanup passes, all fixture counts zero;
- non-fixture fingerprint: unchanged before/after (`231` Core rows, `3`
  non-E2E users, identical SHA-256 values);
- Biblio Core PHPStan: pass;
- Biblio Core unit suite: `618/618` pass (`2427` assertions, two existing
  PHPUnit notices);
- Biblio Core integration suite: `431/431` pass (`5296` assertions);
- Biblio Core WordPress smoke, manifest JSON and final Git whitespace: pass.

The serialized Core quality gate completed in `337` seconds. No schema or
backend contract was changed.

The independent second review found and corrected final-render focus loss,
typed zero-result failure retry context, Edition capability invariants and the
Core-aligned presentation-order bound. No remaining blocker was found after
the corrected focused suites.

## 10. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 case/count or
earlier export was read, copied, interpreted or mutated. Tests use only
synthetic source-neutral browser responses and existing deterministic Core
fixtures.

## 11. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.14.0`, unchanged;
- Biblio UI: `0.17.0`.

## 12. Human acceptance

Renée completed the final interaction and visual acceptance.

**HUMAN GO / CLOSED**
