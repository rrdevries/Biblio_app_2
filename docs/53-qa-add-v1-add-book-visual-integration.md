# 53 — QA-ADD-V1 Add Book Wizard visual integration

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING**

Date: 2026-09-07

Task severity: **High**

## 1. Visual root cause

ADD-UI-01 already used the shared Ink/Soft Ivory tokens, but its local
composition still read as a generic form stack. The wizard used nearly equal
action emphasis, treated non-independent form/summary areas like cards, placed
authors inside generic metadata and gave the same width and rhythm to simple
and comparative states. Status and programmatic heading focus also appeared
more technical than editorial.

At the time of QA-ADD-V1, the WordPress page shell additionally placed a large
global site header before the Biblio application and a global footer after it.
That finding was outside this slice and was later closed page-scoped by
UI-FOUND-01; see
[`docs/54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md`](54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md).

## 2. Visual integration

The existing wizard now follows the canonical Deep Library vocabulary:

- a controlled Guided Flow column is used for ordinary steps, while only
  existing-Edition and candidate comparison may use the wider comparison
  measure;
- eyebrow, step title, hairlines and whitespace establish the hierarchy;
- Edition and Work titles plus authors use the editorial serif roles, while
  labels, metadata, forms, controls and status remain sans-serif;
- Edition/candidate cards remain real entity cards with limited radius, one
  brass hairline, no shadow and compact metadata; ISBN is deliberately muted;
- candidate differences use a quiet brass-subtle mark and no ranking or
  provider prominence;
- manual Edition entry is one open bibliographic form, the optional Work link
  is an open hairline section and the summary is no longer a generic card;
- action rows expose at most one primary progression action in their context;
  per-card selection actions in comparison lists and Work results are
  secondary;
- missing-data, provider-failure, pending, extra-copy and error feedback use
  subtle semantic accent lines rather than heavy alert boxes; and
- mobile stacks actions, metadata and cards without changing flow or meaning.

## 3. Reused design-system contracts

The implementation reuses the existing semantic color roles, Cormorant
Garamond/Source Sans 3 font roles, `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64`
spacing scale, 44px control minimum, 1px boundaries, compact/control radii,
focus token and existing mobile breakpoint. Two reusable composition measures
were added: `--biblio-guided-max` and `--biblio-comparison-max`.

No page-specific color palette, inline style, shadow system or parallel
component library was added.

## 4. Unchanged boundaries

There is no change to lookup, REST, provider, Work/Edition/Item,
classification, permission/capability, route, state-machine, Core, schema,
Elementor or Librarian-governance behavior. No unreliable cover, language,
publisher, date or other placeholder data is invented. D-ADD-01/D-ADD-02 and
the explained disabled `Exemplaar verder beschrijven` action remain unchanged.

Schema remains `1017`.

## 5. Responsive and accessibility evidence

The authenticated Chromium matrix exercises 1440px desktop, 1024px tablet,
390px mobile and the repository's 640px 200%-reflow equivalent. It asserts no
horizontal overflow in the Biblio root or wizard, reachable 44px controls,
programmatic step-title focus, visible labels, provider-failure alert semantics
and the existing complete selection/recovery/fallback flow.

Twelve local screenshots cover start, existing Edition, single candidate,
multiple candidates, manual Edition, provider failure and success. Desktop is
captured for every required state; representative start, multiple-candidate,
manual, provider-failure and success states are also captured at 390px. The
ignored local evidence directory is:

`.local/qa-add-v1-screenshots/`

The screenshots were reviewed as one system. They show one consistent
Ink/Soft Ivory shell, editorial entity hierarchy, compact metadata, restrained
cards, open forms/status and coherent mobile action stacking.

## 6. Verification

Verified in the final implementation tree:

- complete Biblio UI JavaScript suite: 203 tests, all passed;
- Biblio UI isolated PHP smoke, script-module/style registration and version
  checks: passed;
- PHP syntax for the changed Biblio UI PHP files: passed;
- focused authenticated Add Book Chromium matrix: 1 test, passed;
- complete guarded Chromium suite: 51 tests, passed;
- all five fixture guards failed closed; double cleanup, zero residue and the
  before/after non-fixture fingerprint passed unchanged;
- complete Core gate: PHPStan level 6 and syntax passed, 410 unit tests with
  1,735 assertions and two existing non-failing notices passed, 339 MariaDB
  integration tests with 4,125 assertions passed, WordPress smoke passed;
- manifest JSON and Git whitespace: passed; and
- explicit independent second review against requirements, architecture,
  authorization/privacy, accessibility and regression risk: no blocker.

## 7. Version and remaining human QA

Biblio UI is versioned `0.5.0` so browsers request the changed stylesheet and
presentation module.

Renée retains final human visual acceptance on intended production browsers
and devices, including actual 200% browser zoom, real-device focus/keyboard
inspection and comparison with the surrounding production WordPress shell.
The global WordPress header/footer spacing was a separate shell finding in this
slice and is now closed by UI-FOUND-01 without retroactively changing this
historical `0.5.0` verification record.
