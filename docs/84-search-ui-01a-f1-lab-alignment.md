# SEARCH-UI-01A-F1 — LAB alignment

Date: 2026-09-13
Status: **TECHNICAL GO — awaiting Renée human visual acceptance**

## 1. Human QA blocker recap

SEARCH-UI-01A was technically closed, but human comparison with the approved
LAB screenshot was visual NO-GO. The former Page read as a broad technical
Authors/Works MVP: the search panel dominated, Authors appeared before Books in
large dashboard cards and there was no distinct right rail. F1 corrects only
that composition.

## 2. LAB alignment audit

The supplied LAB screenshot is the hard visual baseline for the search content
area. The current Ink/Light App Shell remains canonical. No reusable approved
search hero asset was present, so F1 uses the existing Soft Ivory layers and
tokens and introduces no stock or generated image.

## 3. Page header and search composition

Idle state retains the inviting `Zoeken` introduction. After a completed query
the Page switches to one compact hierarchy: `Zoekresultaten` followed by
`Resultaten voor “{query}”`. The labelled semantic search form remains
prominent but no longer occupies a large standalone panel. It submits only on
explicit form action and still promises only title or Author text.

## 4. Functional tabs

`Alles`, `Boeken` and `Auteurs` are accessible tabs over the same retained
Page-local state. Switching a tab, including with Arrow, Home or End keys,
sends no new search request. `activeTab` is presentation state only; result
lanes, selectors and cursors are neither copied nor reconstructed.

## 5. `Alles` composition

`Alles` is a bounded category overview. Books come first and receive the
primary visual weight; at most five loaded Works appear on wide desktop.
Compact Authors follow, bounded to four loaded results. `Bekijk alle boeken`
and `Bekijk alle auteurs` are real buttons that select the corresponding tab.

## 6. Books presentation

Works use a calm editorial grid with a portrait `Geen omslag` treatment, title,
Authors and reliable existing Series context. The current typed Work search
contract exposes no trustworthy cover asset, so no provider or decorative
cover is guessed. Full loaded Work results and `Meer boeken` are available in
the specialised Books tab.

## 7. Authors presentation

Authors use compact hairline-separated editorial rows rather than large white
dashboard cards. The specialised Authors tab keeps the same density and exposes
the existing `Meer auteurs` continuation.

## 8. Right rail

Desktop uses a separate results/rail grid with a clear gutter. The rail contains
only truthful search guidance and, when applicable, a compact external-status
card. It contains no filter, sort, Library-scope or advanced-search control.
Below 1200 px the rail moves after the result content instead of becoming a
permanent split view.

## 9. Best Match decision

The backend provides no explicit cross-entity Best Match designation. F1 does
not infer one from position, text similarity, provider order or result kind.
The LAB Best Match block is therefore intentionally absent.

## 10. Duplicate Author-name behavior

Strong-identity-only deduplication remains authoritative, so equal display
names are never merged in the browser. The existing typed `result_kind` is
rendered only as provider-neutral secondary context (`In Biblio` or `Uit
bibliografische bron`). Opaque selectors, provider IDs and technical identities
remain hidden.

## 11. Partial failure presentation

Typed partial-provider failure no longer competes visually with the main
results. A compact right-rail status says that external results were incomplete
and that local results remain available. The message stays accessible through
the existing live-status behavior and exposes no raw provider or error detail.

## 12. Responsive behavior

At 1440 px the composition has a five-item Book preview and separate right
rail. At 900 px the Book grid reduces to three columns and the rail follows the
results. At 390 px the Page is one column, Books retain portrait proportion,
Authors remain compact, tabs stay horizontally safe, touch targets remain
usable and no horizontal overflow occurs.

Visual evidence:

- `.local/search-ui-01a-f1-visuals/desktop-1440.png`;
- `.local/search-ui-01a-f1-visuals/tablet-900.png`;
- `.local/search-ui-01a-f1-visuals/mobile-390.png`.

## 13. Accessibility

The Page keeps its real labelled form, ordered headings, visible focus,
`aria-busy`, polite announcements and native buttons. Tabs use tablist/tab/
tabpanel semantics with selected/controlled relationships and keyboard
navigation. Category pagination remains focus-predictable. No selector or
cursor appears in the DOM, accessible names or visible copy.

## 14. Compatibility and backend boundary

F1 changes only Biblio UI JavaScript/CSS, its tests, UI version wiring and
canonical documentation. Biblio Core, REST payloads, signed selector codecs,
pagination contracts, App Shell navigation and WordPress Page mounting are
unchanged. No provider call, search-as-you-type behavior, materialization,
Wishlist/Add Book action, drill-down or write was introduced.

## 15. Explicitly deferred

- backend totals and numeric tab badges;
- cross-entity Best Match;
- Series and Collections top-level search/results;
- filters, sort, `Zoeken in`, Library scope and advanced search;
- ISBN routing and URL/history state;
- Author-to-Works and Work-to-Editions drill-down;
- Wishlist, Add Book, materialization and other consumer actions.

## 16. Tests, browser QA and quality gates

- JavaScript and Playwright syntax: pass;
- focused Search JavaScript tests: `11/11` pass;
- Biblio UI PHP syntax and isolated WordPress smoke: pass;
- complete Biblio UI JavaScript suite: `262/262` pass;
- authenticated SEARCH-UI Chromium suite: `11/11` pass;
- final complete guarded Chromium gate: `85/85` pass;
- guarded cleanup: idempotent, zero E2E residue and unchanged non-fixture
  fingerprint;
- direct visual review at 1440, 900 and 390 px: no technical visual blocker;
- Core Composer/platform, PHP syntax, PHPStan and WordPress smoke: pass;
- complete Core unit suite: `618/618`, `2427` assertions and two existing
  PHPUnit notices with no failures;
- complete MariaDB integration suite: `431/431`, `5297` assertions;
- manifest JSON and Git whitespace: pass.

During acceptance, one Search reset test exposed a test-order race and was made
deterministic by waiting for the old result state it is meant to reset. One
unchanged Notes keyboard test produced one isolated focus timeout in an earlier
broad run; it then passed `3/3` in focused reproduction and passed in the final
`85/85` guarded run.

## 17. Actual V1 data rule

No current, historical or copied V1 data, `/data/`, MIG-01 snapshot, DATA-01
fixture or historical export/count was used. Browser responses are deterministic
source-neutral test fixtures.

## 18. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.14.0`, unchanged;
- Biblio UI: `0.16.1`.

## 19. Git

The slice is closed on `main` by one local commit named
`fix: align search results with LAB design`. No safety branch was needed and
nothing is pushed to `origin/main`. Exact commit/ahead status is reported from
Git after closure.

## 20. Human visual acceptance

Automated and technical visual checks cannot grant the final product decision.
The desktop, tablet and mobile evidence remains for Renée to compare directly
with the supplied LAB baseline.

**TECHNICAL GO — awaiting Renée human visual acceptance**
