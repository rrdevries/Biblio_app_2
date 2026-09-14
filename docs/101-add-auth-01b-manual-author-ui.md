# ADD-AUTH-01B — Manual Author Add Book UI

Date: 2026-09-14

Status: **TECHNICAL GO / HUMAN VISUAL-INTERACTION ACCEPTANCE PENDING**

Task severity: **High**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.25.0` unchanged

Biblio UI: `0.19.0`

## 1. Pre-coding audit

The Add Book wizard owned one in-memory state object and rerendered its current
step. Manual new Work was already distinguishable as manual mode without a
selected Work; existing Work, existing Edition and provider candidate paths
were separate. Final JSON was composed only in `buildAddBookCommitBody()`.
There was no repeatable-row component, so the smallest reuse was stable local
row keys over the existing field, button, focus and polite live-region
patterns. The audit also found that validation and a dynamic row rerender had
to retain the complete Edition draft rather than reconstruct it from defaults.

## 2. Delivered behavior

Manual new Work starts with one blank optional Author row under `Over het werk`
and supports zero through 32 rows. Add, remove, move up and move down are native
keyboard-operable buttons. Visible order is request order. Add focuses the new
row; remove focuses the next/previous row or add action; reorder retains focus
on the moved row and announces the new position.

Rows use local keys only for UI continuity. Unicode whitespace is normalized
for immediate feedback, blanks are omitted and names longer than 512 Unicode
characters receive row-specific copy, `aria-invalid`, `aria-describedby` and
focus. Equal normalized names remain separate and receive only the exact
non-blocking warning. No Author lookup, merge, provider request, role, position,
ID or identity decision exists in the browser.

Back/Forward, dynamic row actions, existing-Work selection/unlinking and a
validation retry retain Author values/order and all other Edition fields.
Nothing is persisted before the existing final commit.

## 3. Path boundary

| Path | Author presentation | Request behavior |
|---|---|---|
| Manual new Work | Editable repeatable rows | Ordered exact `authors: [{display_name}]`, including `[]` for zero Authors |
| Manual existing Work | Canonical Authors read-only plus non-edit note | `authors` omitted; hidden draft can be restored after unlink |
| Existing Edition | Existing recognition context only | `authors` omitted |
| Provider candidate | Existing provider review only | `authors` omitted; AUTHOR-MAT-01E remains authoritative |

`contributors` remains Edition-level evidence and is presented separately as
`Overige bijdragers (optioneel)` with the approved translator/illustrator/editor/
compiler helper. The public success response is unchanged.

## 4. Request and Core proof

The frontend contract tests prove the exact one/two/zero Author shapes,
ordering, blank removal, Unicode bounds, contributor separation and complete
omission on all non-new-Work paths. Core still repeats normalization and
validation and remains the only materialization/identity authority.

The guarded browser proof performs a real authenticated manual Add Book commit,
without intercepting the commit route, using two ordered Authors. It then uses
the ordinary Search UI to find the newly materialized local Work through its
Author graph. Cleanup resolves only the exact reserved linked
Work/Edition/Item, exact ordered names and two credits; partial or ambiguous
state fails closed. Cleanup and the subsequent independent verify-clean action
both reported `add_auth_ui_residue: 0` and zero for every fixture count.

## 5. Accessibility and responsive evidence

Deterministic browser assertions cover the named fieldset, visible row labels,
row-specific accessible action names, disabled movement boundaries, keyboard
operation, warning/error association, validation focus, live announcements and
focus restoration. Geometry assertions cover desktop `1440`, tablet `900`,
mobile `390` and `720`-wide 200% reflow equivalence. Controls stay at least
44px tall, wrap without horizontal overflow and remain reachable.

Visual captures are retained locally in the ignored QA directory:

- `.local/qa-add-v1-screenshots/H-add-auth-manual-two-authors-desktop-1440.png`
- `.local/qa-add-v1-screenshots/I-add-auth-manual-two-authors-mobile-390.png`
- `.local/qa-add-v1-screenshots/J-add-auth-manual-two-authors-reflow-200.png`
- `.local/qa-add-v1-screenshots/K-add-auth-existing-work-read-only-desktop-1440.png`

## 6. Verification

- focused frontend contract test: **10/10 passed**;
- guarded Add Book Playwright file: **4/4 passed**, including the real UI-to-Core
  proof and the existing canonical path matrix;
- E2E cleanup plus independent verify-clean: **all counts zero**;
- negative E2E fixture safety guards: **5/5 passed**;
- complete Biblio UI gate: PHP lint green, isolated smoke green, all production
  JS syntax green and **274/274 JS tests passed**;
- `manifest.json` parse, `git diff --check` and the final staged whitespace gate
  are required green for the closure commit.

## 7. Independent second review

The final diff was reread against D-ADD-AUTH-01, the twelve delivery points,
Core authority, identity conservatism, accessibility and fixture deletion
safety. The review tightened the proof cleanup to require the Item's actual
Edition link, exact ordered Author names and exact credit count before deleting
anything. No schema, Core, provider, Search contract, runtime data, V1 data or
unrelated capability changed. No blocker remains.

## 8. Verdict

**TECHNICAL GO — awaiting Renée human visual/interaction acceptance**

Technical acceptance does not claim human approval. Renée's desktop, tablet,
mobile and 200% reflow visual/interaction pass remains the only open gate.
