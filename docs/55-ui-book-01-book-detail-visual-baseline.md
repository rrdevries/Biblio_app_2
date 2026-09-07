# 55 — UI-BOOK-01 Book Detail D-BOOK-01 composition baseline

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING**

Date: 2026-09-07

Task severity: **High**

## 1. Pre-implementation audit

The current authorized Book Detail contract directly supplies:

- Edition identity: title, ordered Authors, cover reference, ISBN, language,
  publisher, publication date, Series and form;
- Library Item context: Library, Location, condition, acquisition and
  availability;
- personal reading context: derived reading status, active round, counts,
  Work-wide Reading History and start/end capabilities; and
- owner-scoped Private Notes with existing create, edit, delete and pagination
  behavior.

Classification, Collections and public Assessments exist in separate
Library-scoped read/application layers, but the authorized Item detail response
does not compose them. Book description/synopsis, subtitle, binding, page
count, edition/printing statement, Edition contributors and richer acquisition
fields have no current Book Detail source. This was sufficient to implement a
truthful D-BOOK-01 composition, so the stop condition did not apply.

## 2. Implemented composition

- The Identity Zone is a compact token-driven hero with stable cover geometry,
  an accessible no-cover object, Edition title, available Authors, Library
  context, reading-state/form chips and the existing start or end action.
- There is never more than one primary hero action. Ending an active round
  remains secondary and retains the existing dialog and mutation contract.
- The subnavigation links only to rendered canonical sections: Overview,
  Reading History, Private Notes and the present Book/Edition/Item groups.
- Desktop uses an open main column and visibly separated context column.
  Tablet and mobile stack without changing semantic order.
- Overview uses a calm, honest no-description treatment because no description
  field exists. Active-round start date and the existing reading summary remain
  authoritative.
- Reading History is an open timeline-like native list. Its loading, empty and
  failure states retain a stable section heading.
- Private Notes remains owner-scoped and functionally unchanged, with open
  divider composition and a secondary create action.
- Metadata groups omit unknown values. Edition title and Library remain useful
  minimum context even when optional fields are unavailable.

All atmosphere is neutral CSS from existing semantic tokens. No image asset,
remote cover, inferred palette, persistence or Atmosphere engine was added.

## 3. Deliberately absent or deferred

Reviews/ratings, Collections, Library classification, description, subtitle,
binding, page count, printing statement, Edition contributors and richer
acquisition information are not rendered. Adding any of them requires an
explicitly approved Book Detail composition contract and, where necessary, a
separately authorized backend slice. D-ADD-02 Book Detail edit mode and
collector-local fields remain unimplemented.

## 4. Responsive, accessibility and privacy contract

Guarded Chromium verifies 1440px, 1024px, 768px and 390px layouts plus the
repository's 720px 200%-reflow-equivalent. It asserts no horizontal overflow,
desktop column separation, tablet/mobile stacking, a compact mobile cover,
full-width mobile action, keyboard focus and stable semantic headings. The
no-cover object has an Item-specific accessible name and section navigation
uses a named native navigation landmark.

The UI never treats visibility as authorization. Item detail, Reading History
and Private Notes keep their existing server-side Library Context/ownership
checks and non-enumerating failure behavior. The screenshot scenario enriches
only already allowlisted response fields to make the complete supported
composition visible; it does not introduce unsupported production data.

## 5. Screenshot evidence

Ignored local evidence is stored under:

- `.local/ui-book-01-screenshots/before/`
- `.local/ui-book-01-screenshots/after/`

The final set includes the full 1440px detail, hero, Overview/History, Private
Notes, Edition/Item context, 1024px, 390px, 720px reflow-equivalent and an
unchanged Mijn Bibliotheek shell regression. These files are acceptance
artifacts and are not committed.

## 6. Unchanged boundaries

No Biblio Core class, REST route/payload, schema/migration, Library Context,
authorization rule, Item/Edition/Work meaning, ReadingRound mutation, Private
Notes mutation, classification, Collection, assessment, provider, Elementor or
public-site behavior changed. Schema remains `1017`.

## 7. Verification

Verified in the final implementation tree:

- Biblio UI PHP syntax and isolated smoke: passed;
- Biblio UI asset syntax and complete JavaScript suite: 204 tests, all passed;
- focused D-BOOK-01 unit/design contract suite: 21 tests, all passed;
- focused authenticated responsive Book Detail scenario: passed;
- complete guarded Chromium suite: 52 tests, all passed;
- fixture refusal guards, double cleanup, zero residue and unchanged
  non-fixture fingerprint: passed;
- complete Core gate: PHPStan, syntax, 410 unit tests, 339 MariaDB integration
  tests and WordPress smoke: passed;
- manifest JSON and Git whitespace: passed; and
- explicit independent second review against scope, canon, architecture,
  authorization/privacy, accessibility and regression risk: no blocker.

## 8. Version and remaining human QA

Biblio UI is `0.7.0` so browsers request the changed shared assets.

Renée retains final visual acceptance on intended production browsers/devices,
including actual 200% zoom, keyboard/focus inspection and comparison with the
approved D-BOOK-01 reference. A **GO** from that review authorizes the prepared
local commit for push; no push is part of UI-BOOK-01.
