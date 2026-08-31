# Elementor Vertical Slice 1C — Reading History Readiness

Date: 2026-08-30
Last updated: 2026-08-31
Readiness verdict: **READY WITH CONDITIONS**

Current implementation status: **1C.4 GO** — see §§17–19

## 1. Authority, baseline and analysis scope

This is a read-only readiness and delta analysis for showing the authenticated
user's earlier ReadingRounds on Item detail. It adds no product behavior.

- branch: `main`;
- start HEAD and `origin/main`:
  `9236ebd71167cb1d49226503f57be3d4fdca62c9`;
- start worktree: clean;
- Vertical Slice 1A: formal GO;
- Vertical Slice 1B: formal GO.

The current checkout is the technical source of truth. The binding product
and architecture sources are `docs/00-current-state.md`, the Reading sections
of `docs/01-functional-design.md`, `docs/02-architecture.md`,
`docs/03-scope-and-deferred.md`, `docs/06-testing-and-acceptance.md` and
ADR-007.

## 2. Proven current ReadingRound truth

### 2.1 Canonical per-round data

An owned ReadingRound canonically contains:

| Value | Truth and UI suitability |
|---|---|
| ID | Stable private identity; not needed by read-only 1C presentation. |
| User | Immutable owner; must remain server-side. |
| Work | Immutable and therefore the reliable history scope. |
| Source | Current optional Item or ExternalLoan reference; it may have been explicitly corrected and is not necessarily the original start source. |
| Outcome | `completed` or `stopped` for every ended row; safe to present. |
| Provenance | Immutable `legacy_source_started`, `source_started` or `historical_manual`; raw migration detail is not a presentation contract. |
| ReadingPeriod | Optional start plus required finish for ended rows; safe when precision is preserved. |
| Legacy start | UTC content instant without original timezone evidence; it must not be presented as a confirmed local calendar day. |
| Technical timestamps | Concurrency/persistence facts, not reading dates; exclude from 1C. |
| Version | Mutation/CAS data; exclude from read-only 1C. |

`ReadingDate` preserves exactly year, year+month or year+month+day. A finish
date is always present for an ended row, but it is not necessarily exact after
historical registration or an explicit content correction. A start date may
be absent. No missing component may be invented.

### 2.2 Derived values

Personal Work-read-status and reading sequence are derived from current owned
rows. `GetReadingSequenceService` is Work-wide and considers only completed
rounds. It returns `first_read`, `reread` or
`chronology_indeterminate`; it does not determine ordinal labels such as
"second read" or "third read". Stopped and active rounds do not participate.

The service currently obtains every owned round for a Work through the
unbounded aggregate repository method `findAllForUserAndWork()`. That is valid
for the existing domain projection, but it is not a bounded UI-list contract.

## 3. Product-scope decision

### 3.1 Options

| Option | Assessment |
|---|---|
| A. Exact Item only | Reject as primary history. It hides same-Work rereads and cannot reliably mean "started on this Item" after explicit same-Work source correction. |
| B. All personal rounds for the Work | Correct primary semantic scope; Work is immutable and existing status/sequence rules are Work-wide. |
| C. Work-wide plus safe source context | **Recommended.** It preserves the correct Work-wide history while distinguishing only coarse current source/registration context. |

### 3.2 Recommended minimal 1C product contract

Show all **ended, actor-owned ReadingRounds for the detail Work**, newest
recorded finish period first. Keep the current active round in the existing
`Lezen` state and do not duplicate it in history.

Treatment by case:

- same Work through another Item: included;
- same Edition through another Item: included;
- another Edition of the same Work: included;
- ExternalLoan: included with coarse label `Externe lening`;
- `historical_manual`: included and labelled `Historische registratie`;
- `legacy_source_started`: included once ended; its legacy start instant is
  omitted as a local calendar date;
- active plus earlier ended rounds: active stays separate, ended rows appear
  in history;
- source-free corrected normal history: included without speculative source
  copy.

Do not claim `Eigen exemplaar`, a specific Library, the original start source
or an exact current-versus-other Item relation. The stored source is current
correctable provenance information, and no immutable historical Library/Item
display snapshot exists.

### 3.3 Explicit non-scope

- edit, correct or delete controls;
- a separate full reading-history page or Timeline;
- source Item, ExternalLoan or Library names/IDs;
- `first_read`, `reread`, ordinal reread labels or chronology warnings;
- active ReadingRounds inside history;
- filters, search, grouping, export or statistics.

## 4. Ordering and date presentation

Use newest first because Item detail answers the immediate question "when did
I most recently read this Work?". The deterministic continuation order should
reverse ADR-007's content-date tuple:

1. finish `earliest` descending;
2. finish `latest` descending;
3. ReadingRound ID descending as a non-semantic tie-break.

The ID tie-break exists only inside the server result/cursor and never implies
historical certainty. Overlapping partial dates remain potentially unordered
in the semantic first/reread sense.

Presentation examples:

- exact: `12 maart 2025`;
- month: `maart 2025`;
- year: `2025`;
- known start and finish: `12 maart 2025 – 28 maart 2025`;
- missing/unusable start: `Einddatum: maart 2025`.

Technical `ended_at`, `updated_at`, legacy UTC start or a fabricated first day
must never fill a content date.

## 5. Privacy and authorization

Reading history remains private user-owned data without Library ownership.

- The service resolves the current actor through `AuthenticatedUser`.
- Every persistence predicate starts with exact actor + Work scope.
- No request accepts `user_id`, owner, role, Library role or capability as
  authority.
- Library Owner/Manager can see only their own history, never another user's.
- On a shared-Library Item detail every actor receives only their own rows.
- Losing Library access makes that Item detail unavailable, but does not
  delete or transfer personal Work history. A Work-scoped personal boundary
  remains reusable by a future Mijn Biblio history surface.
- The minimal response exposes no ReadingRound, Item, ExternalLoan, Library or
  user IDs and performs no live join to inaccessible source metadata.

Coarse source type is safe because it reveals only the owner's own round
context. It must be described as current source context, not immutable start
history.

## 6. Readmodel architecture

### 6.1 Do not join history into the existing detail SQL

`WpdbCatalogUiReadRepository` currently returns one Item row. Its Work summary
is one grouped row and its exact-source active join is cardinality-bounded.
Joining ended history directly would multiply the Item row and couple
pagination to detail hydration.

A second owner-scoped projection query is required. It must select only the
allowlisted history columns and must not hydrate unbounded aggregates through
`ReadingRoundRepository::findAllForUserAndWork()`.

### 6.2 Dedicated application read boundary

Recommended new boundary:

- `GetMyReadingHistoryForWorkService` resolves the actor;
- a dedicated `ReadingHistoryReadRepository` returns a page of immutable
  presentation DTOs;
- typed `ReadingHistoryPageSize`, `ReadingHistoryCursor`,
  `ReadingHistoryEntryView` and `ReadingHistoryPage` keep pagination and
  output shape inside Core/application contracts;
- `WpdbReadingHistoryReadRepository` performs one owner+Work+ended query with
  `LIMIT page_size + 1` and no per-row lookups.

`GetReadingSequenceService` remains the correct semantic reference for future
first/reread classification, but should not be called by 1C: it excludes
stopped rows, is unbounded and provides no ordinal sequence.

`GetOwnedReadingRoundService` likewise proves the existing owner-scoped
single-round boundary, but it is intentionally an ID lookup and is not a
history-list service. Neither existing service should be stretched into the
new paginated presentation contract.

## 7. Item detail versus a new REST route

### 7.1 Rejected: embed an unbounded `reading_history` array

Adding every row to Item detail is unsafe. Embedding only an initial page
would then require awkward history cursor parameters on the detail endpoint
or a second route for subsequent pages, while every next page would repeat the
complete Item payload.

### 7.2 Recommended route

Add one private read route:

`GET /biblio/v1/me/works/{work_id}/reading-history`

Query parameters:

- `cursor`: optional versioned URL-safe continuation token;
- `limit`: optional integer, default 10, maximum 50.

The Item detail response remains byte-for-byte backwards-compatible. Biblio
UI obtains its already validated `work_id` from current detail state, then
loads history independently. A history failure must not replace the valid Item
detail.

The read route uses the same cookie-authenticated WordPress REST convention.
`X-WP-Nonce` is sent for GET because it authenticates the cookie request in
the current client; it does not create a mutation or a retry concern.

Error contract:

- 200 with an empty page for a valid unknown Work ID or no owned rows; this
  avoids Work/history enumeration;
- 400 for malformed Work ID, cursor or page size;
- 401 for unauthenticated requests;
- WordPress's standard 403 for invalid nonce;
- 503 when Core is unavailable;
- safe generic 500 for unexpected failure.

No history-specific 404 is needed. A caller who knows another user's Work or
cursor still receives only rows matching the current actor; a cursor cannot
change actor scope.

## 8. Pagination and boundedness

Pagination is required in 1C. An unlimited array is not acceptable even if
typical histories are short.

- initial/default page: 10;
- maximum page size: 50;
- keyset cursor, never offset;
- fetch `limit + 1` to derive `next_cursor`;
- cursor carries the finish interval keys and private row ID only as an opaque
  owner-scoped continuation token;
- malformed tokens are 400; a well-formed token with no matching owner rows
  yields an empty page.

The entry does not expose `reading_round_id`; use of the ID inside the cursor
is an implementation tie-break only. Current cursor conventions can be
reused structurally, but history needs its own codec and exact payload shape.

## 9. Minimal allowlisted REST contract

Recommended response:

```json
{
  "data": {
    "items": [
      {
        "outcome": "completed",
        "started_on": {
          "year": 2025,
          "month": 3,
          "day": 12
        },
        "finished_on": {
          "year": 2025,
          "month": 3,
          "day": 28
        },
        "source_type": "library_item",
        "historical_registration": false
      }
    ],
    "next_cursor": null
  }
}
```

Contract decisions:

- `reading_round_id`: no; read-only UI has no identity operation;
- `version`: no; there is no history mutation;
- Work ID: no; already scoped by the URL;
- Item/ExternalLoan/Library IDs: no;
- raw provenance: no; serialize only `historical_registration`;
- `started_on`: nullable and precision-aware; legacy start becomes null;
- `finished_on`: required but precision-aware, not assumed exact;
- `source_type`: `library_item`, `external_loan` or `unknown`;
- first/reread sequence: absent from 1C.

The serializer must emit exactly these entry fields. Source type reflects the
current corrected source reference. `historical_registration` remains true
even if a historical row later receives a concrete source.

## 10. Minimal Biblio UI delta

Item detail gains an H2 section `Leesgeschiedenis` after the existing reading
summary/actions and before bibliographic metadata.

- Use a semantic `<ul>` with one `<li>` per round, not a table.
- Show `Uitgelezen` or `Gestopt` as text, never color alone.
- Show the honest precision-aware date/range.
- Optionally show one secondary context line: `Externe lening`, or
  `Historische registratie` when true; do not add copy for Library/unknown
  sources.
- Do not render an empty section after a successful zero-entry response; the
  existing reading summary already communicates no ended rounds.
- During loading use an independently busy history region.
- On failure keep Item detail intact and show a safe inline retry.
- `Meer laden` appends the next page once, prevents duplicate requests and
  announces additions through a polite live region.
- If the focused load-more control disappears on the final page, move focus
  to the first newly appended entry or the history heading.
- Route change aborts history work and stale responses cannot update a newer
  Item.
- A successful Start authoritative detail reconciliation preserves ended
  history without an unnecessary duplicate GET. A successful End
  reconciliation reloads history page 1 so the newly ended round appears
  without trusting the POST response.

Likely frontend files:

- `web/wp-content/plugins/biblio-ui/assets/js/app.js`;
- `web/wp-content/plugins/biblio-ui/assets/js/detail-view.js`;
- `web/wp-content/plugins/biblio-ui/assets/css/app.css`;
- `web/wp-content/plugins/biblio-ui/tests/js/app-runtime.test.mjs`;
- `web/wp-content/plugins/biblio-ui/tests/js/app.test.mjs`;
- `web/wp-content/plugins/biblio-ui/tests/js/detail-view.test.mjs`;
- `web/wp-content/plugins/biblio-ui/tests/js/design-contract.test.mjs`.

No router or Page URL change is required. A separate JS module is optional,
not required for the minimal delta.

## 11. Responsive and accessibility impact

- one-column cards/rows with wrapping content at every breakpoint;
- no horizontal table or fixed date columns;
- existing width, spacing, focus and 44 px control tokens;
- H2 plus semantic list relationships;
- independently named loading/error/live status;
- visible focus and ≥44 px retry/load-more controls;
- no automatic focus movement on initial passive load;
- controlled focus only when a focused pagination control is removed;
- verify 390×844, 900×900 and 1280×900 with long Dutch labels and year-only
  dates.

## 12. Performance and schema assessment

Expected cost is one additional REST request and one SQL query on initial Item
detail, plus one query per explicit `Meer laden`. There is no N+1 and no join
to Item, Edition, Library or ExternalLoan tables: source type follows from the
two nullable source columns.

The current index
`reading_rounds_by_user_work_finish(user_id, work_id, round_outcome,
reading_finished_year, reading_finished_month, reading_finished_day,
reading_round_id)` provides the exact actor+Work prefix and was designed for
Work finish reads. Mixing completed and stopped may still require a
work-scoped sort because `round_outcome` precedes the finish keys.

No schema migration is proven necessary now. Step 1C.2 must run `EXPLAIN` and
a representative high-cardinality mixed-outcome test for the exact keyset
query. Accept only an actor+Work-bounded scan and stable page latency. If that
proof fails, stop before UI/REST expansion and design an explicit next schema
migration/index; do not silently add or guess one.

## 13. Future test strategy

### Core/readmodel

- current actor only; foreign actor rows excluded;
- exact Work scope across Items, Editions and ExternalLoan;
- ended only; active excluded;
- completed and stopped both included;
- historical/manual label and current source-type mapping;
- legacy start maps to null without timezone invention;
- null, year, month and exact start/finish precision;
- newest-first deterministic cursor ordering, no gaps/duplicates;
- page-size bounds and empty unknown Work;
- malformed stored data fails safely;
- one query/no per-entry lookup and `EXPLAIN` evidence.

### REST

- sixth route registers exactly once as GET with explicit permission callback;
- cookie auth, valid/missing/invalid nonce;
- request `user_id` or role cannot switch actor;
- valid unknown Work and foreign-only Work are equivalent empty 200 pages;
- strict response allowlist contains no owner, IDs, version, timestamps or raw
  provenance;
- cursor first/middle/final pages and invalid cursor/page size;
- Core unavailable 503 and unexpected exception privacy 500.

### UI

- strict response validation;
- empty/single/multiple pages and load-more locking;
- completed/stopped labels;
- exact/month/year/null-start formatting without false precision;
- source/registration labels without raw IDs;
- independent loading/error/retry and route abort;
- Start/End reconciliation refreshes history;
- semantic list, live announcement, focus and responsive CSS.

### E2E

Use the existing guarded actor, foreign actor, Libraries, login, runner,
fingerprint and double-cleanup architecture. Add deterministic allowlisted
records for:

1. Work with no ended rounds;
2. one completed round;
3. one stopped round;
4. multiple rounds proving newest-first order and `Meer laden`;
5. same Work through another Item/Edition;
6. ExternalLoan source;
7. `historical_manual` with year/month precision and missing start;
8. active plus ended combination, proving active exclusion;
9. foreign actor history on a shared/managed Library Item;
10. deep link/reload plus responsive and accessibility acceptance.

Legacy timezone behavior belongs at Core/REST integration level; the browser
only needs to prove that a null start creates no fabricated date.

The current fixture cleanup deletes rounds primarily by allowlisted Item IDs.
Source-free manual and ExternalLoan history require extending cleanup with
exact allowlisted Work/round and ExternalLoan targets, corresponding counts,
second-cleanup proof, zero residue and unchanged non-E2E fingerprint. Never
use a prefix wildcard or broad user/Work deletion.

## 14. Elementor and Crocoblock

No Elementor change is justified. The ordinary `/mijn-bibliotheek/` Page
still contains one shortcode mount, while Biblio UI renders the new section
inside that mount. The canonical 1A artifact remains unchanged and must stay
covered by the existing shell/import regression.

Crocoblock/JetEngine is not needed: it must not query private ReadingRound
rows, implement owner filtering or paginate this Core-owned projection.

## 15. Recommended build plan

### 1C.1 — Readiness and contract approval

- Goal: establish the product/read/transport contract in this document.
- Scope/files: this analysis and `manifest.json` only.
- Tests: baseline, document consistency, `jq empty`, `git diff --check`.
- Stop when: Work-wide scope, coarse source fields, separate route or the
  no-sequence decision is not approved.
- Ownership: **GEMENGD** — **Gemiddeld**; Codex analysis, user product approval.

### 1C.2 — Owner-scoped bounded Core readmodel

- Goal: add typed paginated ended history for actor+Work.
- Expected files/layers: new application History DTO/cursor/page-size/page and
  repository port, `GetMyReadingHistoryForWorkService`,
  `WpdbReadingHistoryReadRepository`, `CoreApplication`,
  `ProductionComposition`, focused unit/integration/boundary tests.
- Tests: owner/Work/source/outcome/date/legacy/order/cursor/query-count and
  high-cardinality `EXPLAIN` matrix.
- Stop when: foreign data can enter, ordering invents precision, query is not
  actor+Work bounded or an index migration proves necessary.
- Ownership: **CODEX** — **Hoog**.

### 1C.3 — Private paginated REST read route

- Goal: expose the exact allowlisted history page.
- Expected files/layers: `RestController`, `RestRequestParser`,
  `RestResponseSerializer`, `RestApi`, a dedicated history cursor codec and
  `RestApiTest`.
- Tests: route/method, auth/nonce, parser/cursor, empty non-enumeration,
  allowlist, pagination, 503/500.
- Stop when: the route needs caller identity/Library authority, emits private
  IDs/raw provenance or cannot keep unknown/foreign-only Work equivalent.
- Ownership: **CODEX** — **Gemiddeld**.

### 1C.4 — UI runtime and semantic history list

- Goal: load, validate, render, retry and paginate history independently on
  Item detail, including authoritative refresh after mutation.
- Expected files/layers: `app.js`, `detail-view.js`, `app.css`, app/runtime,
  detail and design-contract frontend tests.
- Tests: strict contract, empty/single/multiple/load-more, abort/stale response,
  retry, date labels and Start/End reconciliation.
- Stop when: detail failure is coupled to history, POST acknowledgement becomes
  authority, duplicate GET pagination occurs or dates/IDs leak.
- Ownership: **CODEX** — **Hoog**.

### 1C.5 — Responsive and accessibility acceptance

- Goal: close list, pagination, live-status, focus and three-breakpoint UX.
- Expected files/layers: `app.css`, `detail-view.js`, frontend design/detail
  tests; no Elementor metadata.
- Tests: semantic list/headings, status text, busy/live region, focus after
  final load, 44 px controls, visible focus and no overflow at all viewports.
- Stop when: status depends on color, focus is lost, content scrolls
  horizontally or partial dates imply false precision.
- Ownership: **CODEX** — **Gemiddeld**.

### 1C.6 — Guarded fixtures and browser E2E

- Goal: prove real owner-private Work history and non-regression.
- Expected files/layers: `e2e/fixture.php`, new
  `e2e/specs/vertical-slice-1c.spec.mjs`, `e2e/README.md` and runner/cleanup
  files only if exact new targets require them.
- Tests: the ten cases in §13 plus all 1A/1B cases, guard refusals, cleanup
  twice, residue zero and equal fingerprint.
- Stop when: source-free/external rows are not exactly cleanable, foreign rows
  appear, browser results depend on test order or any existing slice regresses.
- Ownership: **CODEX** — **Hoog**.

### 1C.7 — Formal exit

- Goal: run complete Core/UI/E2E/Elementor-shell gates and record criterion
  evidence and final verdict.
- Expected files/layers: next numbered exit evidence plus required
  current-state/testing/manifest status only.
- Tests: full canonical gate, targeted history REST/readmodel, clean fixture
  fingerprint and final documentation/repository checks.
- Stop when: any privacy, boundedness, false-precision, cleanup or canonical
  gate fails; stop before product fixes.
- Ownership: **GEMENGD** — **Gemiddeld**; Codex evidence, user release decision.

## 16. Conditions and verdict

Implementation must not begin until these contract conditions are accepted:

1. approve Work-wide ended-only history with coarse source/registration
   context and no exact Item claim;
2. approve the separate paginated `/me/works/{work_id}/reading-history` route,
   minimal ID-free entry and no first/reread labels in 1C;
3. in 1C.2, prove the exact mixed-outcome keyset query against the existing
   index; if it is not actor+Work bounded, stop and decide a formal migration.

At the 1C.1 readiness point the verdict was **READY WITH CONDITIONS**. The
binding product choices were subsequently approved for 1C.2 and the measured
index condition is closed by §17. The subsequent REST contract is closed by
§18 and the UI runtime contract is closed by §19; later acceptance/E2E slices
remain unimplemented.

## 17. 1C.2 implementation evidence

The binding 1C contracts in this document were approved for the Core-only
1C.2 build. 1C.2 is now **GO** while the overall downstream REST/UI plan remains
unimplemented.

- `GetMyReadingHistoryForWorkService` resolves the actor and delegates to a
  dedicated `ReadingHistoryReadRepository`;
- immutable entry/page/page-size/cursor/source-type contracts contain only the
  future allowlisted projection values;
- `WpdbReadingHistoryReadRepository` executes one actor+Work+ended projection
  query with the §4 order, keyset predicate and limit + 1;
- default page size is 10 and maximum is 50;
- legacy starts remain null, partial finish/start precision is unchanged and
  the internal ID exists only inside the continuation cursor;
- the §12 candidate index was tested with 50,000 temporary rows, including 600
  mixed-outcome/mixed-precision target rows, 10,000 same-actor other-Work rows,
  10,000 other-actor same-Work rows and 29,400 unrelated rows;
- first and cursor page both selected
  `reading_rounds_by_user_work_finish`, access type `range`, estimated/actual
  rows 600, `Using index condition; Using filesort`; MariaDB's measured total
  query time was approximately 0.79 ms for each local run and the limit sort
  used a priority queue;
- the filesort is accepted because it is structurally confined to exact
  actor+Work+ended rows; no table scan or unrelated owner/Work scan occurs;
- both 50,000-row runs were transactionally rolled back and residue checks
  returned zero ReadingRounds and zero Works;
- an automated 2,075-row integration case plus pagination tests prove one
  query per page, owner/Work boundedness and no duplicates or skips;
- schema stays 1007: no migration, table or index change was made.

No REST route, Itemdetail change, Biblio UI, Elementor, Crocoblock or E2E
fixture was part of the 1C.2 implementation.

## 18. 1C.3 implementation evidence

The separately approved thin REST adapter is now **GO**.

- exactly one sixth private route was added:
  `GET /biblio/v1/me/works/{work_id}/reading-history`;
- the controller delegates only to the existing 1C.2 application service and
  accepts no actor, Library role or capability override;
- typed parsing accepts only `work_id`, optional `limit` (10 default, 50
  maximum) and an optional dedicated versioned URL-safe cursor;
- each cursor page is re-scoped through current actor plus URL Work in Core;
- the existing envelope contains only `items` and `next_cursor`; entries emit
  exactly the five approved fields and no identity, version, raw provenance or
  technical timestamp;
- exact/month/year precision, null legacy start, all three source types and the
  historical-registration marker are preserved;
- valid unknown, empty and foreign-only history are equivalent empty 200
  pages, including a foreign source Library managed by the requesting actor;
- malformed input is 400, unauthenticated/missing nonce is 401, invalid nonce
  is WordPress 403 and Core unavailable is 503;
- keyset roundtrip tests cover default/explicit/max bounds and tied finish
  dates without duplicates or skips;
- read-only regression evidence proves unchanged ReadingRound and Library
  ActivityEvent counts; Itemdetail has no history field.

Schema remains 1007. No ReadingRound lifecycle, Itemdetail, Biblio UI,
Elementor, Crocoblock or E2E file changed in 1C.3.

## 19. 1C.4 implementation evidence

The separately approved Biblio UI runtime and semantic list are now **GO**.

- Itemdetail supplies the only Work identity through its existing strict
  validated response; no URL, presentation control or new backend field is
  trusted for history scope;
- `reading-history.js` strictly accepts only the approved page and five-field
  entry shapes, formats Dutch ReadingDate precision and owns the semantic
  subordinate view;
- the detail renders before the fixed `limit=10` GET resolves; loading,
  empty/error, ready, pagination and refresh state are independent from
  `currentDetail`;
- zero results produce no section/H2, while completed/stopped entries render
  as `Uitgelezen`/`Gestopt` in a semantic list;
- only `Externe lening` or the priority `Historische registratie` source line
  is presented; Library/unknown sources and all IDs/provenance stay absent;
- pagination locks duplicate requests, follows and replaces only a validated
  opaque cursor, appends server order, retains entries on error, retries the
  same cursor explicitly and maintains logical focus;
- history requests use the existing same-origin nonce client and current
  route AbortSignal, plus generation/revision checks against late old-Work
  responses;
- direct links and fresh runtime starts load history independently;
- Start Reading preserves existing ended history without another GET; End
  Reading first reconciles authoritative detail and then replaces history and
  pagination from a fresh page-1 GET only;
- a failed post-End refresh marks retained history locally stale, permits an
  explicit history retry and never repeats the mutation;
- one-column responsive CSS, wrapping entries, semantic status/error copy and
  existing 44 px/focus controls provide the 1C.4 accessibility baseline.

Schema stays 1007. No Core, REST, Elementor, Crocoblock, E2E/Playwright,
schema or migration file changed. Formal expanded
responsive/accessibility acceptance remains 1C.5 and guarded E2E remains
1C.6.
