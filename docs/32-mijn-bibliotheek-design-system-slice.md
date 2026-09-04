# 32 — Mijn Bibliotheek against the Design System baseline

Status: **implemented — GO WITH EXPLICIT DEFERRED CAPABILITIES**.

This record covers the presentation-only implementation slice that applies
[`docs/31-biblio-design-system.md`](31-biblio-design-system.md) and
[`ADR-009`](decisions/ADR-009-biblio-ui-theming-and-atmosphere-architecture.md)
to the existing `Mijn Bibliotheek` page. It does not change Core domain rules,
authorization, persistence, REST routes or the Elementor Page artifact.

In the current information architecture this page is explicitly the complete
active catalog inside one active Library Context. It is not Biblio Home and not
the Library's Home / Action Center. References below to the overview, shell or
sidebar describe this catalog slice; any older mockup association between the
title `Mijn Bibliotheek` and Home modules is historical design context only.

## 1. Baseline and implementation order

The slice started from `main` at
`1c0869ce349d2626aa0af5b3f3b3b3aa8bfe9d4a` with a clean worktree and no
commits ahead of or behind `origin/main`.

The implementation order was:

1. preserve the existing Core-backed Library and Item contracts;
2. introduce semantic design tokens and the theme/appearance attributes;
3. compose the Classic Sidebar and responsive workspace around the existing
   application views;
4. rebuild the overview as Grid/List/Bookshelf presentation states;
5. add Quick View through the existing scoped Item-detail read route;
6. add focused unit, contract and authenticated browser acceptance;
7. run the complete repository gates and commit only this slice.

## 2. Implemented contract

### Shell and responsive composition

- The Biblio UI application now owns an Ink/Light shell with semantic
  `data-biblio-theme` and `data-biblio-appearance` attributes.
- Desktop uses a 224px Classic Sidebar that can collapse to a 72px rail. Only
  `biblio.ui.sidebar.collapsed` is persisted, and storage failure degrades to
  the expanded default without breaking the app.
- Tablet uses the rail composition; mobile uses an off-canvas sidebar with a
  scrim, labelled menu control, idle Escape close and deliberate focus return.
- The shell wraps every existing overview/detail/mutation state. Library
  resolution, routing, authorization and mutations remain unchanged.
- The existing ordinary Elementor Page continues to provide only the outer
  container and shortcode mount. The current WordPress theme header/footer may
  still surround it, as explicitly allowed by the Slice 1A Page contract; a
  site-wide navigation/template redesign is not part of this slice.

### Tokens and component styling

- Semantic page, surface, navigation, text, interaction, border, focus, brass,
  status and book-atmosphere roles are centralized at the Biblio root.
- Ink Light uses temporary, accessibility-tested work values. Components use
  the roles rather than local palette values. This creates the implementation
  seam for Aubergine/Petrol and Light/Dark/System without pretending that the
  still-open palettes already exist.
- The canonical 4/8/12/16/24/32/48/64 spacing scale, refined radii, hairlines,
  restrained cover/elevated shadows and `prefers-reduced-motion` behavior are
  represented in the CSS contract.
- Cormorant Garamond and Source Sans 3 are preferred in the stacks, with safe
  local fallbacks. This slice does not bundle or fetch fonts and does not close
  the canonical production-font validation question.

### Mijn Bibliotheek overview

- Grid is the default and retains the existing paginated, active-Item server
  result. The desktop work value is 148px per cover, with 24px horizontal and
  36px vertical rhythm. Missing covers remain absent instead of being invented.
- List is a working alternate presentation of the same authoritative result.
- Bookshelf is an explicit, accessible placeholder. It does not simulate book
  spines while cover-ratio and shelf rendering contracts remain open.
- Every Item retains the hierarchy cover → title → authors when known →
  contextual/status line. Links and actions continue to depend on the server
  capabilities; visual visibility is not authorization.
- Quick View is a native modal right overlay. It rereads the existing scoped
  Item-detail endpoint, never changes the URL, never shrinks the workspace,
  provides a full-detail link, handles loading/unavailable/error states and
  restores focus on close. On mobile it recomposes as a near-full-height sheet.

## 3. Explicit contract collisions and deferrals

### Search, filter and sort

The Design System says the toolbar contains Search, Filters, Sort and a view
switch, with removable chips for active filters. The still-authoritative Slice
1A application contract, however, explicitly excludes Search, Filters and
Archive. The current overview REST route accepts only `page_size` and an opaque
cursor and therefore cannot search, filter or resort the complete catalog.

A client-only implementation over the first fetched page would present a false
catalog result and break server ordering/pagination truth. This slice therefore
renders the complete toolbar architecture but keeps Search and Sort visibly
disabled. Filters opens a detail area that states why values are not available.
The active-filter chip component contract is styled, but production chips are
not shown until a real filter can be active. This is a deliberate deferral, not
a hidden local product decision.

Closing this deferral requires a separately approved Core/read-model/REST
contract before the controls may become functional.

### Still-open Design System values

This slice does not settle:

- the exact Ink/Aubergine/Petrol Light/Dark/System palettes;
- production font delivery, weights, scale or line heights;
- the icon library;
- uniform versus original cover ratio;
- Atmosphere Pack assets, selection or persistence;
- a site-wide WordPress/Elementor navigation template.

The code provides semantic extension seams for these decisions. It does not
expose fake settings or store domain data in browser storage.

## 4. Acceptance matrix

| Criterion | Result | Evidence / boundary |
|---|---|---|
| Deep Library open composition | Proven | Root-scoped semantic-token CSS, few elevated surfaces; Quick View is the intentional overlay. |
| Ink Light shell | Proven with work values | Theme/appearance attributes and contrast contract; exact palette remains non-canonical. |
| 224px sidebar / 72px remembered rail | Proven | Unit and authenticated desktop-browser measurements plus reload persistence. |
| Tablet/mobile recomposition | Proven | Authenticated Chromium at 900×1000 and 375×812, overflow checks, off-canvas Escape/focus. |
| Grid default and 148px desktop covers | Proven | Authenticated Chromium count/geometry and visual artifact. |
| List view | Proven | Unit and authenticated browser view switch. |
| Bookshelf | Placeholder only | Explicit placeholder; no invented spine/ratio contract. |
| Search/filter/sort | Deferred by contract | Toolbar and disclosure proven; no false client-only catalog operation. |
| Quick View overlay | Proven | Existing detail REST read, native dialog, stable workspace width, full-detail route and focus return. |
| Existing Library/Item/Reading/Notes behavior | Proven compatible | Full frontend/Core/REST and guarded Playwright gates; no contract or schema delta. |
| Accessibility | Slice-level proven | Native semantics, named controls/dialog, keyboard, focus, reduced motion, contrast work values and narrow overflow. No full WCAG claim. |
| Elementor boundary | Proven unchanged | Existing shortcode Page is reused; no Elementor data, theme or Crocoblock mutation. |

## 5. Non-scope

No Core source, schema/migration, Library/Item DTO, authorization rule,
ReadingRound behavior, Private Notes behavior, Next Reading behavior,
Elementor Page data, theme template, licensed package, credentials or permanent
test data is changed. No plugin upgrade or push is part of this slice.

## 6. Verification record

Final verification passes:

- Biblio UI PHP/JavaScript syntax, isolated smoke and 191/191 frontend tests;
- focused Biblio UI PHPStan with no errors;
- complete Core unit 252/252 with 977 assertions and real-MariaDB integration
  253/253 with 2,966 assertions;
- complete Core PHP syntax/PHPStan, Composer metadata/platform requirements,
  WordPress smoke, manifest JSON and Git whitespace;
- guarded authenticated Chromium 50/50, including the new shell/view/Quick
  View scenario and all existing C7/1A/1B/1C/1D regressions;
- a final focused Chromium pass after close-control polish;
- five fixture fail-closed guards, idempotent double cleanup, zero fixture
  counts and unchanged non-fixture fingerprint (`core_rows=39`, SHA-256
  `314a9fc54ef83367cb7cfa4dc4030e9c0d49fcc665a58a1fb00562abca039cfa`).

Visual acceptance found and closed three implementation defects: the boxed
Elementor container initially constrained the desktop shell, native Quick View
was initially opened before attachment to the document, and Notes formatting
relied on browser-implicit editor focus return. The first now uses a root-owned
full-viewport breakout with no Elementor selector, the second opens only after
mount, and the third restores editor focus explicitly.

The summarized gate is also recorded in section 56 of
[`docs/06-testing-and-acceptance.md`](06-testing-and-acceptance.md). Browser
screenshots are local, ignored acceptance artifacts under `.local/e2e-results`
and are not production assets.
