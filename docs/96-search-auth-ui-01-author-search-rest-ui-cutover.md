# SEARCH-AUTH-UI-01 — Author search REST/UI cutover

Date: 2026-09-14
Status: **TECHNICAL GO / HUMAN VISUAL-INTERACTION ACCEPTANCE PENDING**

Task severity: **High**. A read-only A–J audit preceded code changes. REST and
its strict frontend consumer were changed atomically by one implementation
owner and then reviewed in a separate requirements/security/regression pass.

## 1. Implementation audit

SEARCH-AUTH-01A–01C already supplied Core-ranked Author results with typed
`match_quality`, opaque `name_group_id`, local linked-Work context and external
same-response Work/birth context. The public serializer and browser still used
the old five-key Author shape, while both `Alles` and `Auteurs` repeated source
labels and every `Meer auteurs` action immediately requested the next cursor.

The smallest complete change was therefore REST projection, strict decoding,
Page-local visible-subset/disclosure state, Author row/focused presentation,
targeted CSS/tests, versions and canonical evidence. No missing backend or
product decision required reopening 01A–01C.

## 2. REST contract and strict decoder

The existing fields remain:

- `result_id`;
- `result_kind`;
- nullable `author_id`;
- `display_name`;
- opaque `author_selector`.

The Author item now also always contains:

- `match_quality: exact|broader`;
- opaque `name_group_id`;
- `disambiguation` with exactly nullable `representative_work_title`, nullable
  non-negative JSON-safe `linked_work_count` and nullable bounded `birth_year`.

The serializer reads only the typed application result. Provider keys/IDs,
Open Library `top_work`, `work_count`, governance/mapping evidence, ranking
integers and selector payloads remain absent. The browser requires the complete
exact allowlist, validates every new type/bound and freezes the context. It
neither decodes nor renders the selector.

## 3. `Alles` and `Auteurs`

Books stay first. The `Alles` Author preview is reduced from four to three and
uses the existing Core order. A canonical exact result prevents external
broader candidates from consuming the preview; external exact same-name noise
is bounded without altering retained state.

`Auteurs` renders all returned canonical Authors and initially at most five
external candidates. A fourth exact external member of the same name group
waits for disclosure, so broader candidates behind that identity group wait as
well. External-only exact results remain normal primary results. Optional
headings use only `Mogelijke auteurs met deze naam`, `Meer mogelijke auteurs
met deze naam` and `Andere auteurs`, never technical provenance.

## 4. Human-readable and same-name disambiguation

Presentation follows docs/92 exactly:

- local zero Works: no invented context;
- local one Work: `Auteur van {title}`;
- local multiple Works: `{n} werken in de catalogus`;
- external birth/title: `Geboren {year}` and/or `Auteur van {title}`;
- no external Work count.

Visible rows in one server-issued name group remain separate strong identities
with separate actions. Distinct reliable context is preserved. Rows whose
available context is still identical receive a deterministic visible
`Mogelijkheid n van m` fragment over the current visible Core order; the same
fragment enters the accessible action name. No name normalization, identity
merge, selector change or client deduplication occurs.

## 5. Source labels and focused Author

Ordinary Author rows and the focused Author-to-Works header no longer contain
`Biblio-catalogus`, `Externe bron`, `Open Library` or another per-result source
label. The right rail deliberately retains:

```text
Zoeken in
Biblio-catalogus
Inclusief aangesloten bibliografische bronnen.
```

The selected Author state carries the already rendered reliable human context
beside display name and opaque selector. It makes no details/enrichment request.

## 6. Progressive disclosure and compatibility

All server results remain retained. `Meer auteurs` first advances the visible
external prefix by at most five. Only when no loaded hidden Author remains does
one click send one request with the unchanged opaque cursor. The returned page
is appended and at most the next five become visible. A zero-visible page with
another cursor keeps the button focused and announces that more results remain;
there is no automatic loop.

Author-to-Works still sends only `author_selector`. Internal Back preserves
top-level/Author-Works/Edition state and selected context; a new query resets
drill-down and disclosure. Work search, Work-to-Editions, Wishlist, Add Book,
mapped suppression, Author cursor v2 and provider failure behavior remain
compatible.

## 7. Responsive, accessibility and failure behavior

Rows remain compact semantic list items: name, at most one two-fragment context
line and native action. Same-name group copy is associated with its rows;
action labels are distinguishable without technical IDs. Focus moves to the
first newly revealed row, or stays on `Meer auteurs` after zero-visible
continuation. Existing keyboard tabs, visible focus, alerts, polite live region
and loading states remain.

At mobile width rows stack name/context/action without horizontal overflow and
the action becomes full width. The existing 1440/900/390 responsive composition
and 200% reflow boundary remain the acceptance target. Provider failure retains
usable canonical Authors; without an Author result, a failed Author attempt is
an incomplete-search state with retry rather than a definitive miss.

## 8. Explicitly unchanged/deferred

No schema, provider request/field, local or external projection, ranking,
mapping, cursor, materialization, runtime record or V1 source changed. Author
detail, biography, portraits, merge/governance UI, alternate-name matching,
death year, external Work count, provider detail calls, Series/Collections and
V1 migration remain deferred.

## 9. Verification

Recorded verification evidence:

- strict Search decoder/presentation/state JavaScript passed inside the full UI
  run: `269` tests, `269` passed, plus UI PHP syntax and isolated WordPress
  smoke;
- focused REST integration: `86` tests, `2,130` assertions, passed;
- combined Search Chromium scenarios: `18` tests, `18` passed, including
  disclosure, same-name context, source-label negatives, drill-down/back,
  1440/900/390 and 200% reflow;
- guarded full Chromium: `92` tests, `92` passed in `2.5m`; guards passed,
  cleanup was idempotent and the non-fixture Core hash remained
  `07e6b917788f0a6ff18b1fdcc4a5865c1cd24953f3ff2699b87804b6417e449c`;
- full Core gate passed in `415s`: strict Composer metadata and platform,
  all PHP syntax, PHPStan, `649` unit tests / `2,622` assertions with the two
  existing PHPUnit notices, `492` integration tests / `5,741` assertions,
  WordPress smoke, manifest JSON and staged/unstaged whitespace;
- read-only current V2 data inspection confirmed canonical Stephen King has
  one linked Work (`It`) and the strong `/authors/OL19981A` mapping targets
  that same canonical Author; no runtime row was mutated;
- generated desktop, tablet, mobile, 200% reflow and Author/Work/Edition
  drill-down captures were visually reviewed without a technical blocker; and
- an explicit independent-style requirements, architecture, security and
  regression pass found no blocker across the fifteen required focus points.

## 10. Actual V1 data rule and versions

No current or historical V1 `/data/`, MIG-01 fixture source, DATA-01 snapshot,
count or export was used. Tests use deterministic source-neutral fixtures and a
read-only current V2 spot check where practical.

- product: `v2.001`;
- schema: `1024`, unchanged;
- Biblio Core: `2.23.0`;
- Biblio UI: `0.18.0`.

## 11. Human acceptance

Technical completion does not evidence Renée's visual/interaction decision.
Renée should inspect `Alles`, `Auteurs`, a same-name group and the selected
Author-to-Works header on desktop/tablet/mobile.

**TECHNICAL GO — awaiting Renée human visual/interaction acceptance**
