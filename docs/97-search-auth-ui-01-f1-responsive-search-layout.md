# SEARCH-AUTH-UI-01-F1 — Responsive Search results layout

Date: 2026-09-14
Status: **TECHNICAL GO / HUMAN RESPONSIVE RECHECK PENDING**

Task severity: **Medium**. A read-only layout audit preceded the CSS change. A
separate final review pass rechecked the resulting diff against scope,
responsive behavior, accessibility and regression risk.

## 1. Root cause

The outer Search layout was already a two-column grid with a flexible
`minmax(0, 1fr)` main plane, a bounded `15rem`–`17rem` rail, a `1.5rem` gutter
and `min-inline-size: 0` on the relevant grid descendants. The rail stacks at
the existing `1199px` breakpoint.

The Book preview nevertheless forced five `minmax(0, 1fr)` tracks at every
larger viewport. At 1440px the available main plane was narrower than five
portrait covers plus four gaps. The tracks could shrink, but each card still
contained a `10.75rem` cover and was start-aligned, so card content painted
beyond its assigned track into the gutter and rail. Page-level overflow alone
did not detect that internal overlap.

## 2. Canonical fix

The preview now uses an `auto-fit` grid whose minimum track is the existing
portrait-cover width, bounded by `100%`. Column count therefore follows the
actual main-plane width: five columns appear only where five usable tracks fit;
at 1440px the grid selects four and wraps the fifth card. Existing tablet and
mobile breakpoint rules remain authoritative. No rail dimension, gutter,
card, cover, button or App Shell design was changed, and no clipping or
horizontal scrolling was added.

The responsive browser assertion now measures preview, card and immediate
child rectangles against the main plane. It also verifies the gutter/rail
separation, existing rail-stack breakpoint, page overflow and actual first-row
column count at 1800, 1440, 1200, 900, 390 and 200% reflow.

## 3. Preserved scope

Books remain first and the `Alles` preview remains capped at five. Author
presentation, actions, tabs, rail copy, Search state, drill-down/Back,
accessibility and focus behavior are unchanged. No Core, REST, ranking,
identity, provider, schema, runtime-data or V1-data behavior changed.

## 4. Verification

- focused Search CSS/JavaScript contract: `18/18` passed;
- complete targeted Search Chromium suite: `18/18` passed;
- viewport geometry and captures: 1800, 1440, 1200, 900, 390 and 200% reflow
  passed with no page overflow, child overflow or rail overlap;
- deterministic E2E fixture cleanup was idempotent and `verify-clean` reported
  zero residue;
- complete Biblio UI gate: PHP syntax, isolated WordPress smoke and `269/269`
  JavaScript tests passed;
- the four requested captures were visually reviewed without a technical
  blocker; and
- manifest JSON, whitespace and the independent-style final review passed.

## 5. Versions and acceptance

- product: `v2.001`, unchanged;
- schema: `1024`, unchanged;
- Biblio Core: `2.23.0`, unchanged;
- Biblio UI: `0.18.1` (stylesheet cache-bust).

Technical evidence cannot grant Renée's responsive product acceptance. The
new 1440, 900, 390 and 200% captures remain the human recheck set.

**TECHNICAL GO — awaiting Renée human responsive recheck**
