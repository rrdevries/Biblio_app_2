# UI-MYLIB-01 — Mijn Bibliotheek visual reconciliation

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE NOT REVIEWED**
Date: 2026-09-28
Branch: `wip/shared-search-rebuild`
Start HEAD: `c020924062839d86e2120ae4da63a800e171f832`
Core: `2.51.2` unchanged; Biblio UI: `0.20.0` → `0.21.0`; schema unchanged.

## Approved target and implementation

The implementation follows the product-approved UI-REENTRY-03 target in
`docs/31-biblio-design-system.md` §9 and the UI-REENTRY-02 Ink foundation in
§§4, 5 and 11. The four design/index files carrying those decisions were
already modified in the working tree at preflight. Their `SETTLED` decisions
were not edited by this slice.

- The existing page header, Library Context, single primary Add Book action,
  Grid, compact List, server-backed Search/Sort and `Meer laden` remain in place.
  The page, grid title, interface and supplementary metadata use the approved
  desktop/mobile Ink role scale. Cormorant Garamond and Source Sans 3 variable
  WOFF2 files are self-hosted and scoped to this view. The files come from
  Fontsource `5.3.0` with the bundled OFL licenses.
- `Filters` opens a right rail within the desktop page layout, leaving the
  catalog visible and reflowed. At the existing mobile breakpoint it opens a
  native modal sheet with browser-managed focus containment, Escape, explicit
  close and focus return. Changing viewport while open recomposes the panel.
  Active chips and `Alle filters wissen` remain beside the results, outside the
  rail or sheet.
- Groups with more than five options reveal the rest with `Meer lezen` and
  collapse with `Minder tonen`. A selected option beyond the first five stays
  visible while collapsed. Query, checkbox and chip values stay authoritative.
- Real covers use the default 2:3 catalog frame with `object-fit: contain`.
  The no-cover object remains explicit. Search, no-cover and Quick View icons
  use locally hosted Tabler Icons Outline `3.48.0` SVG geometry at 1.6px stroke;
  its MIT license is included. The mobile search field reserves room for its
  44px clear action.

The approved UI-REENTRY-03 target explicitly places any original-cover-ratio
preference outside this page. No cover ratio control or storage was added.
The composed catalog response still provides no real cover reference, so this
fixture could verify no-cover presentation and computed containment but not
the visual rendering of a real cover. New cover data/source work remains a
separate contract dependency.

## Preserved contracts and boundaries

The existing Library scope, Core authorization, active/archive semantics,
Grid/List view state, catalog Search/Filter/Sort, URL and session query state,
opaque cursor pagination, Item route and Quick View read remain unchanged.
There is no Core, REST, schema, migration, production data, Collections,
Book Detail, Add Book, global Search, Wishlist or Atmosphere change.

The UI asset version changed to `0.21.0` to invalidate cached CSS/JavaScript.
The font-face names are specific to this catalog view so loading them does not
alter the typography of other screens. The Tabler mappings are likewise scoped
to Mijn Bibliotheek. A platformwide font/icon rollout remains outside this
slice.

## Technical evidence

- `NODE_OPTIONS=--no-experimental-webstorage ./scripts/test-biblio-ui-smoke.sh`:
  passed; all UI PHP files linted, isolated PHP registration/smoke passed,
  JavaScript syntax passed, and 285 frontend tests passed. The Node 26 host
  exposes global `sessionStorage` by default, unlike the browserless historical
  runtime test environment; the first unadjusted gate failed from persistent
  test storage. The compatibility flag restored that environment without any
  product-code or test-expectation change.
- Guarded authenticated Chromium: 5/5 focused catalog and shared-shell tests
  passed. They cover search/filter/sort, URL and cursor behavior, Grid/List,
  active/archive, zero/error/loading recovery, 1440/1024/768/390px and 200%
  reflow, rail geometry, modal sheet and Escape/focus return, active chips,
  `Meer lezen`, search-clear spacing, actual loaded fonts and Tabler icon mask.
- The DDEV fixture was clean before setup, then double-cleaned and verified
  clean after testing. All synthetic fixture counts are zero. The non-fixture
  fingerprint was unchanged: 28,758 Core rows, SHA-256
  `08f4f7ee917e2fc910973f238185e1221b04f7f962f17899622147de7ecda82a`;
  non-E2E user count and hash were also unchanged.
- `git diff --check`: passed. Final review found no scope, authorization or
  query-semantic regression in the implementation diff.

Local ignored screenshots are under `.local/ui-mylib-01-screenshots/` and
`.local/cat-ui-01-screenshots/`. They are technical evidence, not a human
visual GO. Renée still needs to inspect the live desktop and mobile page,
especially actual covers/authors when an authoritative source supplies them.

Human visual acceptance for this new rendering remains **NOT REVIEWED**.
No push or merge is part of this slice.
