# 27 — Elementor Vertical Slice 1D Private Notes exit evidence

Status: **GO — CLOSED**

Exit date: 2026-09-01.

## 1. Authority, baseline and scope

This record formally closes Vertical Slice 1D. It consolidates the accepted
contracts and fresh evidence from 1D.1 through 1D.7 and introduces no new
product behavior.

The controlled baseline was clean `main` with HEAD and `origin/main` both at
`6de017d08345a4eb4d38c5c1e6e9b2637285faa1`. The exit changes documentation
and `manifest.json` only. Core, REST behavior, schema, migrations, Biblio UI,
Elementor, Crocoblock, fixtures and Playwright product tests are unchanged.

## 2. Implementation sequence and scope audit

1. `4985b55065f7fe180965f4f4974243aceee4c497` — 1D.1, Analyze Private Notes vertical slice;
2. `169d51d5ba8cd84b344fd00f29361e10ddbf94de` — 1D.2, Prepare Private Notes read boundary;
3. `43caff52d6f2d0c006bfc77e6de93f9c1e7a0aa9` — 1D.3, Add Private Notes REST API;
4. `6933141e73c65b848ff7b0528a8f1c6f57c6931e` — independent canonical future-roadmap documentation;
5. `738120cd989c4b6c5ffc3ab3f2ea50a7cb3e3eaf` — 1D.4, Add Private Notes UI;
6. `65e6023fd2ad291c3f3b8f233154ef9f2426fdb3` — 1D.5, Polish Private Notes accessibility;
7. `6de017d08345a4eb4d38c5c1e6e9b2637285faa1` — 1D.6, Add Private Notes E2E coverage.

All are present in order and every 1D commit is an ancestor of the baseline.
The roadmap commit changes only `docs/00-current-state.md`,
`docs/26-future-roadmap-decisions.md` and `manifest.json`; it causes no 1D
product-scope mixing.

## 3. Locked product and architecture contract

Private Notes are always private and owned by the authenticated user. They are
Work-wide, support zero, one or multiple separately identified records and do
not become Library-, Item-, Edition- or ReadingRound-owned. A Note has no
title, category or tag, and its public projection exposes no Library, Item,
Edition, ReadingRound, owner or technical timestamp metadata.

Saving is explicit; there is no autosave. Content supports exactly `p`, `br`,
`strong`, `em`, `ul`, `ol`, `li` and `blockquote`, with no attributes. Rich
paste becomes plain text. Core sanitizes on write and validates on hydration
and render; the UI reconstructs only validated safe nodes and never renders
raw input or unsafe saved HTML.

PATCH and DELETE require `expected_version`. A semantic no-op does not create
a new version. Divergent stale state returns 409 and is never silently
overwritten. Delete is confirmed hard delete without undo. GET uses an opaque
server cursor, authoritative server order, keyset pagination and `limit + 1`.
Private Note operations create no `ActivityEvent`.

Core owns actor resolution, ownership, Work scope, validation, concurrency and
mutations. REST is a thin cookie/`X-WP-Nonce` adapter over named
`CoreApplication` services. Biblio UI owns interaction state, never authority.
Elementor remains the Page shell and Crocoblock has no role.

The locked hierarchy is `Lezen` → `Leesgeschiedenis` → `Privénotities` →
`Uitgave` → `Exemplaar`.

## 4. Contract traceability

| Requirement | Core/read model | REST | UI | Tests/browser |
|---|---|---|---|---|
| Ownership/privacy and Work scope | Server actor plus owner+Work query | No owner/Library input; foreign empty/generic 404 | Work only from validated Item detail | Core/REST privacy and browser foreign/manager cases |
| Multiple separate Notes | Page of record views | Collection GET/POST; member PATCH/DELETE | Native list and record actions | frontend CRUD and browser create/edit/delete/pagination |
| Safe constrained content | Strict policy and render validation | Allowlisted `content_html` | serializer, plain-text paste, safe DOM | Core/REST XSS, frontend serializer, browser safe content |
| Concurrency/no-op | Versioned services; semantic comparison | `expected_version`; 409 | intent retained; GET-only recovery | frontend/browser no-op and stale update/delete |
| Pagination | owner+Work keyset, `limit + 1` | opaque cursor | page size 10, server-order append/retry | REST pages, frontend paging, 13-Note browser case |
| Dirty state/navigation | no implicit mutation | no call before Save | canonical dirty dialog and `beforeunload` | frontend/browser Cancel, navigation and Back |
| Responsive/accessibility | presentation-neutral | presentation-neutral | native semantics, wrapping, focus recovery | 320–1280 browser matrix and manual 200% headed PASS |
| Cleanup/data safety | marker-owned fixture graph | authenticated public truth | no fixture authority | five guards, double cleanup, zero residue/fingerprint |

## 5. Security and privacy exit

- Actor identity is server-derived; client actor, Library context or role is
  never Note authority.
- Owner+Work scope is reapplied on every page. Cursors never authorize.
- Unknown and foreign member PATCH/DELETE share generic unavailable behavior;
  a foreign-only collection is an empty successful page.
- Library Eigenaar/Beheerder grants no override and leaks no count, content,
  identifier or private metadata.
- Raw unsafe HTML, attributes, `script`, `style` and event handlers cannot
  reach executable DOM; corrupt saved content fails closed.
- The UI does not authorize and Notes emit no Library `ActivityEvent`.

Security/privacy verdict: **GO**.

## 6. State, concurrency and reconciliation exit

- PATCH/DELETE send the authoritative version exactly once.
- Divergent stale update/delete returns 409; identical semantic update is a
  true no-op without client-side version arithmetic.
- Mutations are never automatically retried. Failed post-mutation refresh
  retains the best confirmed state and recovers with GET only.
- Page 1 is authoritative after mutation. Pagination preserves server order
  and retries only the failed cursor GET.
- Abort/generation guards prevent late Work-A state from contaminating Work B;
  one-in-flight locks prevent duplicate mutations.

State/concurrency verdict: **GO**.

## 7. UX, responsive and accessibility exit

Zero and multiple-record states, Add, Edit, explicit Save, clean/dirty Cancel,
dirty navigation, confirmed hard delete, subordinate errors, retry and
pagination are usable. Dialogs are named/described, provide safe initial and
return focus, support idle Escape and lock while pending. Keyboard access
covers Add, toolbar, editor, Save/Cancel and delete dialog. Focus indicators,
44px project targets, labels, native list semantics, local status and busy
semantics remain intact.

Authenticated Playwright evidence is green at 320×800, 390×844, 640×900
reflow-equivalent, 900×900 and 1280×900 without document/Notes/editor/dialog
overflow. This is not represented as proof of browser zoom.

The user separately performed a local authenticated **headed browser** smoke
with actual page zoom at exactly **200%** and real Private Notes content. The
reported result is PASS: Notes remained usable/readable; no problematic
horizontal overflow occurred; create/edit controls remained reachable; and
dialogs plus keyboard/focus behavior remained usable. This is manual headed
browser acceptance, not Playwright evidence. It closes the sole 1D.6 condition.

Responsive/accessibility verdict: **GO** for slice acceptance; no complete
WCAG audit is claimed.

## 8. Fresh exit gates

| Gate | Fresh result |
|---|---|
| Private Notes frontend | 35/35 PASS |
| Complete Biblio UI frontend | 174/174 PASS |
| Start / End / Reading History frontend | 6/6, 19/19, 8/8 PASS |
| Private Notes REST | 15 tests, 328 assertions, PASS |
| Complete `RestApiTest` | 39 tests, 747 assertions, PASS |
| Playwright 1A–1C / 1D / total | 24/24, 16/16, 40/40 PASS in 58.6 s, Chromium, one worker |
| Fixture guards | 5/5 PASS |
| JavaScript and UI PHP syntax | PASS |
| Focused UI PHPStan | PASS, no errors |
| Isolated UI smoke | PASS; Elementor/Core not loaded |
| Core/WordPress smoke | plugin active, class loaded, init hook 1, HTTP 200 |
| Canonical complete Core gate | 247 unit/939 assertions; 232 integration/2,371 assertions; PASS in 72 s |
| Manifest JSON and Git whitespace/scope | PASS |

The privacy-safe unexpected-failure diagnostic in `RestApiTest` is deliberate
test output; PHPUnit exits successfully.

## 9. Fixture cleanup and fingerprint

The local-only runner proved five fail-closed guards, cleaned twice before
setup, created only the marker-owned graph and cleaned twice after Playwright.
Both final cleanups reported zero for all fixture families, including Private
Notes and E2E users; `verify-clean` passed.

Before and after are identical:

- `core_rows=0`;
- `core_sha256=0ce27bbce14013bfb222a2eb124a0bd8647df81294cbf8b874dd395580b6eb9b`;
- `non_e2e_users=2`;
- `non_e2e_users_sha256=b6d2e8b80642d96611fc23d42ea98a0105b6634ee81e34721850d3ad8ae0395d`;
- `biblio_dev_present=true`.

Cleanup/data-safety verdict: **GO**.

## 10. Final criteria

| # | Criterion | Result |
|---:|---|---|
| 1 | 1D.1 readiness/product contract | **BEWEZEN** |
| 2 | 1D.2 Core/read boundary | **BEWEZEN** |
| 3 | 1D.3 thin REST boundary | **BEWEZEN** |
| 4 | 1D.4 multi-Note CRUD UI | **BEWEZEN** |
| 5 | 1D.5 responsive/accessibility polish | **BEWEZEN** |
| 6 | 1D.6 guarded authenticated browser evidence | **BEWEZEN** |
| 7 | Actual 200% page-zoom acceptance | **BEWEZEN — MANUAL** |
| 8 | Ownership, Work scope, role non-override | **BEWEZEN** |
| 9 | Safe allowlist and XSS fail-closed behavior | **BEWEZEN** |
| 10 | Versioning, semantic no-op, stale conflicts | **BEWEZEN** |
| 11 | Pagination and local recovery | **BEWEZEN** |
| 12 | Dirty state, dialogs, keyboard and focus | **BEWEZEN** |
| 13 | 320px through desktop reflow | **BEWEZEN** |
| 14 | Guards, cleanup, residue and fingerprint | **BEWEZEN** |
| 15 | Regression, syntax, static analysis and smokes | **BEWEZEN** |
| 16 | Documentation/manifest-only 1D.7 scope | **BEWEZEN** |
| 17 | Open exit blockers | **NONE** |

## 11. Known non-blocking future polish

For a later approved UI-polish scope only:

1. increase vertical separation between `Leesgeschiedenis`, `Privénotities`,
   `Uitgave` and `Exemplaar`;
2. preserve `Lezen` → `Leesgeschiedenis` → `Privénotities` → `Uitgave` →
   `Exemplaar`;
3. make focused H2 `Privénotities` look less like an input while retaining a
   clear keyboard-focus indicator.

No CSS or product code changes in 1D.7.

## 12. Next-slice readiness and final verdict

The canonical roadmap does not name or approve a numbered Vertical Slice 1E.
This exit therefore invents none. With 1D closed and no blockers, the
repository is technically ready for the next separately scoped and approved
vertical slice.

**1D.7 verdict: GO.**

**Vertical Slice 1D verdict: GO — CLOSED.**

**1D.6 exact-200%-zoom condition: CLOSED by manual headed browser acceptance.**
