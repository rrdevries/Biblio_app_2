# 54 — UI-FOUND-01 App Shell and Mijn Bibliotheek visual baseline

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING**

Date: 2026-09-07

Task severity: **High**

## 1. Audit verdict and visual root cause

The existing Biblio UI already had the correct architectural foundation:
semantic tokens, an Ink/Soft Ivory shell, a 224px desktop sidebar with 72px
rail, responsive off-canvas navigation, working Grid and List presentations,
disabled Search/Sort controls, a Filters disclosure and a native Quick View.

It nevertheless read as a functional WordPress application with Biblio colors
rather than the canonical Deep Library product. Four concrete causes were
confirmed in the real DDEV runtime:

- the WordPress block theme rendered a public header/footer and 150px of inline
  page spacing around the shortcode-owned application canvas;
- the shell used letter placeholders for brand, navigation and context marks;
- catalog records without a cover had no stable cover object, which broke the
  visual rhythm and weakened the title-first hierarchy; and
- the page header, toolbar, status lines and List view remained too generic and
  control-led for Editorial Library × Serious Utility.

## 2. D-UI-GAP-01 closure

UI-FOUND-01 closes only the shared visual-foundation gap:

- `/mijn-bibliotheek/` receives a page-scoped body class. On that one canonical
  Page Shell, the public theme header/footer and their inline outer spacing are
  suppressed; the WordPress admin bar remains intact. No theme template or
  Elementor document is mutated.
- The Classic Sidebar, remembered rail and mobile off-canvas behavior retain
  their existing information architecture and storage contract. Shared
  dependency-free mask icons replace the temporary initials.
- Page titles lead with a small contextual eyebrow and an editorial serif
  title; actions, metadata and controls retain their serious sans-serif role.
- Grid uses one reusable 148px, 2:3 cover token on desktop. A deterministic
  no-cover object communicates absence without fabricating book metadata.
- List is a real compact view of the same authoritative Items. Grid and List
  remain functional; Bookshelf is visibly disabled and cannot be selected.
- Search and Sort remain visibly disabled, while Filters keeps its honest
  contract explanation. No client-only catalog behavior is simulated.
- Quick View keeps the existing authorized detail read and focus behavior, but
  uses a stable icon control with an accessible Item-specific name.
- The same surface, type, spacing, border, status and action hierarchy is shared
  by the existing Add Book Wizard. No parallel page-specific component system
  is introduced.

The exact palette, production font delivery, icon library, optional original
cover ratios and future Bookshelf/Atmosphere behavior remain open as recorded
in the Design System. The values in this slice are implementation work values,
not new product canon.

## 3. Responsive and accessibility contract

The final authenticated Chromium scenario covers 1440px desktop, 900px tablet
and 390px mobile, plus the repository's existing 640px 200%-reflow equivalent.
It verifies:

- the application begins immediately below the 32px desktop or 46px mobile
  WordPress admin bar, without theme whitespace;
- 224px sidebar and 72px remembered rail geometry;
- no horizontal overflow before or after rail collapse and across responsive
  recomposition;
- mobile menu Escape close, complete off-canvas hiding and focus return;
- eight real fixture covers plus one explicitly labelled no-cover object;
- working Grid/List switching, disabled Bookshelf, disabled Search/Sort and
  honest Filters disclosure; and
- Quick View overlay geometry, full-detail link, Escape close and focus return.

Controls retain the shared 44px minimum target, visible focus, semantic native
states and reduced-motion behavior. Visual treatment never substitutes for
Core authorization or Library Context.

## 4. Before/after evidence

Ignored local evidence is stored under:

- `.local/ui-found-01-screenshots/before/`
- `.local/ui-found-01-screenshots/after/`

The baseline set records the previous desktop Grid, collapsed rail, 375px
mobile Grid and representative Add Book start/manual states. The final set
records desktop Grid, List, rail, toolbar, Quick View, tablet, 390px mobile Grid
and representative Add Book states from the real authenticated WordPress/DDEV
runtime.

These captures are acceptance artifacts, not production assets and not part of
the Git commit.

## 5. Unchanged boundaries

There is no change to Biblio Core, REST routes or payloads, schema/migrations,
Library Context, capabilities, Item/Edition/Work semantics, Add Book lookup or
commit behavior, provider configuration, classification, ReadingRound, Notes,
Elementor data or the public-site design. Biblio Home remains outside Library
Context and distinct from Mijn Bibliotheek. Theme/Aubergine/Petrol,
Light/Dark/System, Atmosphere Packs and immersive room behavior remain later
work.

Schema remains `1017`.

## 6. Verification

Verified in the final implementation tree:

- complete Biblio UI JavaScript suite: 203 tests, all passed;
- Biblio UI isolated PHP smoke, registration, scoped body-class and version
  checks: passed;
- PHP syntax and focused Biblio UI PHPStan: passed;
- focused authenticated shell/overview Chromium scenario: passed;
- complete guarded Chromium suite: 51 tests, all passed;
- all fixture guards, double cleanup, zero residue and before/after non-fixture
  fingerprint: passed unchanged;
- complete Core gate: PHPStan, syntax, unit, MariaDB integration and WordPress
  smoke: passed;
- manifest JSON and Git whitespace: passed; and
- explicit independent second review against scope, canon, architecture,
  authorization/privacy, accessibility and regression risk: no blocker.

## 7. Version and remaining human QA

Biblio UI is versioned `0.6.0` so browsers request the changed shared assets.

Renée retains final visual acceptance on intended production browsers/devices,
including actual 200% zoom, keyboard/focus inspection and comparison with the
production WordPress environment. A **GO** from that review authorizes the
already prepared local commit for push; no push is part of UI-FOUND-01 itself.
