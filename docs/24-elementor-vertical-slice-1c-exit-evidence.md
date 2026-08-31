# Elementor Vertical Slice 1C — Exit Evidence

Date: 2026-08-31
Final verdict: **GO**

## 1. Authority, baseline and scope

This record closes Vertical Slice 1C.7. The current checkout, Git history,
canonical contracts and fresh checks below are the implementation evidence.

- branch at start: `main`;
- start HEAD: `62db4c77749388c9ceb48d419406bae5542a3990`;
- `origin/main` at start: `62db4c77749388c9ceb48d419406bae5542a3990`;
- worktree at start: clean;
- pre-exit-commit HEAD: `62db4c77749388c9ceb48d419406bae5542a3990`.

The closed product flow is:

`Mijn Bibliotheek → Itemdetail → persoonlijke Work-brede beëindigde
Leesgeschiedenis`.

It is private, actor-owned, Work-wide, newest-first and paginated. It includes
completed and stopped ReadingRounds across Items, Editions, ExternalLoan,
historical manual and legacy history while excluding active and foreign rows.
Date precision remains exact, month or year as stored and a legacy UTC start
never becomes a fabricated local content date.

This exit step adds no product behavior, business rule, route, UI feature,
browser scenario, Elementor/Crocoblock behavior, schema or migration.

## 2. Implementation sequence and scope audit

Git confirms one direct parent chain with the exact subjects:

1. `014eccb89a1b2e31af8e52f0fc3f8987a140a28d` — Analyze Reading history vertical slice;
2. `9ecab0382ccf3aac8c276db0d7be29a602542d66` — Add Reading history read model;
3. `778847c2cc05800cb93f434bd4f7898ce84bae72` — Add Reading history REST endpoint;
4. `367dd4859b234837db1c8e49ffb4b69f291dbfa2` — Add Reading history UI;
5. `d18955bf7a9af603618358c06e8b392b0b7f35a5` — Polish Reading history accessibility;
6. `62db4c77749388c9ceb48d419406bae5542a3990` — Add Reading history E2E coverage.

The diff scope follows the approved layers: 1C.1 documentation, 1C.2 Core
readmodel, 1C.3 REST adapter, 1C.4 UI, 1C.5 UI accessibility/responsive polish
and 1C.6 guarded fixture/browser evidence. No later commit after 1C.2 changed
the Reading History SQL, ReadingRound schema or index definition. No 1C commit
changed the canonical Elementor artifact or added Crocoblock behavior.

## 3. Final architecture and product contract

- **Core is authoritative:** `GetMyReadingHistoryForWorkService` resolves the
  authenticated actor and delegates to one owner+Work bounded projection query.
  Core owns included lifecycle outcomes, date precision, source classification,
  ordering, page size and keyset cursor semantics.
- **REST is a thin adapter:** the existing WordPress cookie/nonce boundary,
  typed request parsing, dedicated opaque cursor codec, exact serializer
  allowlist and safe error mapper wrap the named Core service.
- **Biblio UI is orchestration and presentation:** validated authoritative
  detail state supplies `work_id`; history has separate loading, error,
  pagination and refresh state; stale navigation responses are aborted or
  ignored; End Reading reconciles detail and history through GET truth.
- **Elementor remains only the Page shell:** the existing ordinary
  `/mijn-bibliotheek/` Page still contains one Shortcode mount.
- **Crocoblock has no role:** no private Core history is queried or mutated by
  JetEngine or another Crocoblock component.

The response entry contains exactly outcome, nullable precision-aware start,
required precision-aware finish, coarse source type and the historical
registration flag. It contains no actor, Library, Work, Item, Edition,
ExternalLoan or ReadingRound identity, version, raw provenance or technical
timestamp. There are intentionally no reread or ordinal labels in 1C.

## 4. Evidence map

- **C:** Core application/DTO/repository code plus Reading History unit and
  real-MariaDB integration tests;
- **R:** REST controller/parser/cursor/serializer/error mapper and the complete
  `RestApiTest`;
- **U:** Biblio UI runtime/view/CSS plus complete and targeted frontend tests;
- **E:** guarded 24-case Playwright run, including the 11-case 1C spec;
- **F:** exact fixture allowlists, four refusal guards, setup, double cleanup,
  zero-residue check and before/after fingerprint;
- **P:** committed 1C.2 high-cardinality `EXPLAIN` evidence and unchanged query,
  schema and index code after 1C.2;
- **S:** complete schema, migration, integrity and production lifecycle tests;
- **L:** unchanged Elementor artifact/hash and fresh clean import verifier;
- **G:** Git commit/diff/status, manifest and whitespace checks.

## 5. Acceptance matrix

| # | Criterion | Result | Evidence |
|---:|---|---|---|
| 1 | History is personal/user-owned | **BEWEZEN** | C, R, E |
| 2 | History is Work-wide | **BEWEZEN** | C, R, E |
| 3 | Same Work/other Item included | **BEWEZEN** | C, E |
| 4 | Same Work/other Edition included | **BEWEZEN** | C, E |
| 5 | ExternalLoan included | **BEWEZEN** | C, R, E |
| 6 | `historical_manual` included | **BEWEZEN** | C, R, E |
| 7 | Legacy ended history included | **BEWEZEN** | C, R, E |
| 8 | Active round excluded | **BEWEZEN** | C, E |
| 9 | Completed included | **BEWEZEN** | C, R, E |
| 10 | Stopped included | **BEWEZEN** | C, R, E |
| 11 | No reread/ordinal labels | **BEWEZEN** | R, U, E |
| 12 | Zero history renders no empty section | **BEWEZEN** | U, E |
| 13 | Exact date precision retained | **BEWEZEN** | C, R, U, E |
| 14 | Month precision retained | **BEWEZEN** | C, R, U, E |
| 15 | Year precision retained | **BEWEZEN** | C, R, U, E |
| 16 | Missing day/month is not invented | **BEWEZEN** | C, R, U, E |
| 17 | Technical UTC start is not used as local content date | **BEWEZEN** | C, R, E |
| 18 | Legacy `started_on` is null where required | **BEWEZEN** | C, R, E |
| 19 | Technical timestamps are not visible | **BEWEZEN** | C, R, U, E |
| 20 | Newest-first ordering | **BEWEZEN** | C, E |
| 21 | Deterministic tie-break | **BEWEZEN** | C, R |
| 22 | Default page size 10 | **BEWEZEN** | C, R, E |
| 23 | Maximum page size 50 | **BEWEZEN** | C, R |
| 24 | Keyset pagination | **BEWEZEN** | C, R, E |
| 25 | Opaque REST cursor | **BEWEZEN** | R, E |
| 26 | `limit + 1` semantics | **BEWEZEN** | C |
| 27 | No duplicates | **BEWEZEN** | C, R, E |
| 28 | No skips | **BEWEZEN** | C, R, E |
| 29 | Ties across page boundary are correct | **BEWEZEN** | C, R |
| 30 | Browser load-more flow is correct | **BEWEZEN** | U, E |
| 31 | One projection query per page | **BEWEZEN** | C, P |
| 32 | No N+1 | **BEWEZEN** | C, P |
| 33 | No aggregate hydration per entry | **BEWEZEN** | C, P |
| 34 | Actor+Work index bound is proven | **BEWEZEN** | C, P |
| 35 | High-cardinality evidence is green | **BEWEZEN** | P |
| 36 | No schema/index migration is needed | **BEWEZEN** | P, S, G |
| 37 | No unbounded history array | **BEWEZEN** | C, R |
| 38 | Actor is resolved server-side | **BEWEZEN** | C, R |
| 39 | No user override | **BEWEZEN** | C, R |
| 40 | No Library Context as private authorization | **BEWEZEN** | C, R |
| 41 | Library Manager/Owner has no override | **BEWEZEN** | C, R, E |
| 42 | Foreign history excluded | **BEWEZEN** | C, R, E |
| 43 | Foreign-only Work causes no existence leak | **BEWEZEN** | R, E |
| 44 | No private IDs visible | **BEWEZEN** | R, U, E |
| 45 | No raw provenance visible | **BEWEZEN** | R, U |
| 46 | No ExternalLoan identity visible | **BEWEZEN** | R, U |
| 47 | REST entry is exactly allowlisted | **BEWEZEN** | R, U |
| 48 | Cursor is not an authorization token | **BEWEZEN** | C, R |
| 49 | `GET /me/works/{work_id}/reading-history` registered | **BEWEZEN** | R |
| 50 | Route is GET-only | **BEWEZEN** | R |
| 51 | Auth/nonce follows existing Biblio convention | **BEWEZEN** | R, U, E |
| 52 | Invalid input maps to safe 400 | **BEWEZEN** | R |
| 53 | Unauthenticated maps to 401 | **BEWEZEN** | R, U |
| 54 | Invalid nonce uses existing 403 | **BEWEZEN** | R, U, E |
| 55 | Core unavailable maps to 503 | **BEWEZEN** | R, U |
| 56 | Unexpected failure maps to privacy-safe 500 | **BEWEZEN** | R, U |
| 57 | Empty/no-own-history is 200 empty page | **BEWEZEN** | C, R |
| 58 | Itemdetail contract is unchanged | **BEWEZEN** | R, U, G |
| 59 | History is not embedded in Itemdetail | **BEWEZEN** | R, U |
| 60 | Itemdetail renders independently from history | **BEWEZEN** | U, E |
| 61 | H2 appears only with results | **BEWEZEN** | U, E |
| 62 | History uses a semantic list | **BEWEZEN** | U, E |
| 63 | Completed copy is correct | **BEWEZEN** | U, E |
| 64 | Stopped copy is correct | **BEWEZEN** | U, E |
| 65 | External loan copy is correct | **BEWEZEN** | U, E |
| 66 | Historical registration copy is correct | **BEWEZEN** | U, E |
| 67 | No speculative source copy | **BEWEZEN** | U, E |
| 68 | Presentation is precision-aware `nl-NL` | **BEWEZEN** | U, E |
| 69 | Local history error does not replace detail | **BEWEZEN** | U, E |
| 70 | Retry is explicit | **BEWEZEN** | U, E |
| 71 | There is no automatic retry | **BEWEZEN** | U, E |
| 72 | Pagination retains existing entries | **BEWEZEN** | U, E |
| 73 | Pagination retry uses the same cursor | **BEWEZEN** | U, E |
| 74 | Pending state blocks duplicate request | **BEWEZEN** | U |
| 75 | History state is separate from `currentDetail` | **BEWEZEN** | U |
| 76 | `work_id` comes only from authoritative detail state | **BEWEZEN** | U |
| 77 | Old request is aborted/ignored | **BEWEZEN** | U, E |
| 78 | Late Work A response cannot overwrite Work B | **BEWEZEN** | U, E |
| 79 | Deep link works | **BEWEZEN** | U, E |
| 80 | Reload works | **BEWEZEN** | U, E |
| 81 | Browser back/forward works | **BEWEZEN** | U, E |
| 82 | Start Reading does not corrupt ended history | **BEWEZEN** | U |
| 83 | Start Reading is regression-free | **BEWEZEN** | U, E |
| 84 | End Reading sends exactly one mutation POST | **BEWEZEN** | U, E |
| 85 | Authoritative detail GET follows End | **BEWEZEN** | U, E |
| 86 | History page-1 GET follows detail GET | **BEWEZEN** | U, E |
| 87 | New entry comes only from GET | **BEWEZEN** | U, E |
| 88 | No local POST-derived append | **BEWEZEN** | U, E |
| 89 | No duplicate ended entry | **BEWEZEN** | U, E |
| 90 | Reload preserves persisted new history | **BEWEZEN** | E |
| 91 | History-refresh failure preserves ended detail | **BEWEZEN** | U, E |
| 92 | History retry does not repeat mutation | **BEWEZEN** | U, E |
| 93 | Itemdetail has exactly one H1 | **BEWEZEN** | U, E |
| 94 | Reading History H2 is logically placed | **BEWEZEN** | U, E |
| 95 | Native `ul`/`li` | **BEWEZEN** | U, E |
| 96 | Native buttons | **BEWEZEN** | U, E |
| 97 | Controls are at least 44 px | **BEWEZEN** | U, E |
| 98 | Loading is subordinate and polite | **BEWEZEN** | U, E |
| 99 | Complete list is not `aria-live` | **BEWEZEN** | U |
| 100 | Initial history load does not steal focus | **BEWEZEN** | U, E |
| 101 | Initial error does not steal focus | **BEWEZEN** | U |
| 102 | Load-more focus is correct | **BEWEZEN** | U, E |
| 103 | Final page focuses deliberate target | **BEWEZEN** | U, E |
| 104 | H2 target has `tabindex=-1` and is outside tab order | **BEWEZEN** | U, E |
| 105 | Pagination error focus is logical | **BEWEZEN** | U |
| 106 | End refresh does not steal focus | **BEWEZEN** | U, E |
| 107 | Status is not color-only | **BEWEZEN** | U, E |
| 108 | Error is not color-only | **BEWEZEN** | U |
| 109 | Accessible names use visible copy | **BEWEZEN** | U, E |
| 110 | No keyboard trap | **BEWEZEN** | U, E |
| 111 | Mobile below 768 px is one column | **BEWEZEN** | U, E |
| 112 | 390×844 browser acceptance | **BEWEZEN** | E |
| 113 | 900×900 browser acceptance | **BEWEZEN** | E |
| 114 | 1280×900 browser acceptance | **BEWEZEN** | E |
| 115 | No horizontal overflow | **BEWEZEN** | U, E |
| 116 | 320 px structural reflow | **BEWEZEN** | U |
| 117 | 200% zoom structural contract | **BEWEZEN** | U |
| 118 | Long date/error copy wraps | **BEWEZEN** | U |
| 119 | No fixed-height truncation | **BEWEZEN** | U |
| 120 | No global Elementor/WP CSS leakage | **BEWEZEN** | U, L |
| 121 | Reading History Playwright 11/11 | **BEWEZEN** | E |
| 122 | Existing 1A/1B Playwright 13/13 | **BEWEZEN** | E |
| 123 | Combined Playwright 24/24 | **BEWEZEN** | E |
| 124 | Fixture is deterministic | **BEWEZEN** | F, E |
| 125 | First cleanup reports zero | **BEWEZEN** | F |
| 126 | Second cleanup reports zero | **BEWEZEN** | F |
| 127 | Cleanup is idempotent | **BEWEZEN** | F |
| 128 | Non-fixture fingerprint is equal | **BEWEZEN** | F |
| 129 | `biblio_dev` is retained | **BEWEZEN** | F |
| 130 | Missing opt-in guard refuses | **BEWEZEN** | F |
| 131 | Non-local WordPress guard refuses | **BEWEZEN** | F |
| 132 | Wrong DDEV project guard refuses | **BEWEZEN** | F |
| 133 | Wrong host guard refuses | **BEWEZEN** | F |
| 134 | No ActivityEvent | **BEWEZEN** | C, R, F |
| 135 | No private audit mutation | **BEWEZEN** | C, R, G |
| 136 | No Elementor delta | **BEWEZEN** | L, G |
| 137 | No Crocoblock delta | **BEWEZEN** | G |
| 138 | No schema/migration in 1C | **BEWEZEN** | S, G |
| 139 | No new ADR is required | **BEWEZEN** | Architecture, G |
| 140 | Itemdetail remains existing shell/readmodel responsibility | **BEWEZEN** | R, U, L |
| 141 | Core remains source of truth | **BEWEZEN** | C, R, U |
| 142 | Browser validates the exact page and five-field entry shape | **BEWEZEN** | U |
| 143 | Invalid stored projection data fails closed | **BEWEZEN** | C |
| 144 | Six routes register exactly once with explicit permission callbacks | **BEWEZEN** | R |
| 145 | History reads have no write, ActivityEvent or audit side effect | **BEWEZEN** | C, R |
| 146 | Tested text/control contrast is at least 4.5:1 and focus at least 3:1 | **BEWEZEN** | U |
| 147 | History CSS is root-scoped and uses existing Biblio tokens | **BEWEZEN** | U |
| 148 | History adds no animation/transition requiring reduced-motion handling | **BEWEZEN** | U |
| 149 | Valid unknown Work returns the same empty 200 page | **BEWEZEN** | C, R |

Totals: **149 BEWEZEN, 0 GEDEELTELIJK BEWEZEN, 0 NIET BEWEZEN**.

## 6. Pagination and performance

The query uses the existing
`reading_rounds_by_user_work_finish(user_id, work_id, round_outcome,
reading_finished_year, reading_finished_month, reading_finished_day,
reading_round_id)` index. The committed reproducible 1C.2 benchmark inserted
50,000 temporary ReadingRounds with 600 relevant actor+Work rows. First and
cursor pages selected the named index with `range` access, read the 600-row
partition and reported `Using index condition; Using filesort`. The filesort
is bounded inside that exact actor+Work+ended set and the limit sort used a
priority queue.

Each page performs one projection query with `LIMIT page_size + 1`; there are
no joins, aggregate hydration or per-entry reads. The 2,075-row automated
integration dataset, including 75 relevant actor+Work rows, proves one query
per page and no duplicate/skip behavior. The fresh targeted suite remains
7 tests/157 assertions green. Because the SQL, schema and index code are
unchanged after 1C.2, the 50,000-row benchmark did not need to be rerun.

**PERFORMANCE/INDEX: GO.** No table scan over other users/Works, N+1,
unbounded array or schema/index migration is required.

## 7. Security and privacy exit

The actor comes only from `AuthenticatedUser`; every page starts with exact
actor + Work predicates. No request value, Library Context, owner/manager role,
capability or cursor can override that scope. Unknown, no-own and foreign-only
history all return 200 with an empty page. This remains true when the actor
manages and directly accesses the Library containing the foreign source.

The exact serializer and strict browser validator exclude private resource
IDs, actor identity, Library data, ExternalLoan identity, version, technical
timestamps and raw provenance. The cursor hides an internal pagination
tie-break but is re-scoped by the current actor and URL Work on every request;
it is not an authorization token. Reads create no Library ActivityEvent,
private audit record or other mutation.

**SECURITY/PRIVACY: GO.** No open privacy, enumeration, IDOR or serializer
leakage issue was found.

## 8. REST and UI reconciliation

Exactly one GET-only history route is registered. Invalid typed input is safe
400; unauthenticated is 401; invalid nonce is the existing WordPress 403; Core
unavailable is 503; unexpected exceptions use the privacy-safe generic 500.
The Itemdetail response is unchanged and history is not embedded.

Itemdetail renders before history and survives local loading or errors. Zero
results leave no empty section. Pagination appends only server results, locks
duplicate requests and retries the identical cursor explicitly. Navigation
uses the current detail's validated Work ID plus abort and generation checks.

Start Reading keeps ended history unchanged and remains regression-free. End
Reading sends one POST, rereads authoritative detail, then reloads history page
1. A held browser response proves no entry is derived from the POST. Failed
history refresh preserves ended detail and retry sends only another GET.

**REST: GO. UI/NAVIGATION/START-END RECONCILIATION: GO.**

## 9. Responsive and accessibility exit

Frontend structure proves one-column reflow below 768 px and the 320 CSS-pixel
/ 200% zoom contract without fixed dimensions, truncation, nowrap, hidden
overflow or absolute positioning. The live browser is green at 390×844,
900×900 and 1280×900 with no horizontal overflow.

The slice has one detail H1, a conditional H2, native list and buttons, 44 px
controls, visible-text names, text outcomes/errors, scoped polite status copy
and deliberate pagination focus. Initial load/error and automatic End refresh
do not steal focus; the full list is not live. Static contrast tests meet the
stated thresholds and history adds no motion.

**RESPONSIVE: GO. ACCESSIBILITY: GO WITH DOCUMENTED LIMITATION.** The complete
Vertical Slice acceptance contract is proven; a full WCAG audit is explicitly
outside this exit and remains a non-blocking future assurance activity.

## 10. Fresh gate results

| Gate | Fresh result |
|---|---|
| Core Composer metadata/platform | PASS |
| Core PHP syntax | PASS |
| PHPStan level 6 | 0 errors |
| Core unit | 242 tests, 919 assertions |
| Core integration | 219 tests, 2005 assertions |
| Core total | 461 tests, 2924 assertions |
| Reading History unit | 12 tests, 31 assertions |
| Reading History integration | 7 tests, 157 assertions |
| REST integration and route smoke | 28 tests, 425 assertions |
| Schema/migrations/integrity | PASS inside complete integration suite |
| WordPress/Core smoke | plugin active, class loaded, init hook 1, HTTP 200 |
| Biblio UI PHP/JS syntax and smoke | PASS |
| Complete Biblio UI frontend | 135/135 PASS |
| Targeted history/detail/runtime/navigation/design | 85/85 PASS |
| Start Reading frontend | 6/6 PASS |
| End Reading frontend | 19/19 PASS |
| Reading History Playwright | 11/11 PASS |
| Existing 1A/1B Playwright | 13/13 PASS |
| Combined Playwright | 24/24 PASS in 30.1 s, one worker |
| Fixture guards | 4/4 refusals PASS |
| Cleanup/zero residue/fingerprint | PASS |
| Clean Elementor import/Page/assets | PASS |
| Manifest JSON and Git whitespace | PASS |

The fresh complete integration run reported 2005 assertions; the previously
committed 1C.6 run recorded 2006. The test count and all results are unchanged,
and this exit record reports the actual current runner output rather than
copying the earlier assertion counter.

The E2E fixture set up exactly 2 Libraries, 18 Items, 16 Works, 17 Editions,
1 ExternalLoan, 29 ReadingRounds, 3 memberships, 2 designations, 16 catalog
contexts, 42 classification terms, 16 activity events and 2 marked users.
Both cleanup passes then reported zero for every family.

The identical before/after fingerprint is:

- Core rows: `0`;
- Core SHA-256:
  `0ce27bbce14013bfb222a2eb124a0bd8647df81294cbf8b874dd395580b6eb9b`;
- non-E2E users: `2`;
- non-E2E users SHA-256:
  `b6d2e8b80642d96611fc23d42ea98a0105b6634ee81e34721850d3ad8ae0395d`;
- `biblio_dev`: present.

## 11. Elementor and Crocoblock

The 1C range contains no change to
`config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip`. Its current
SHA-256 remains
`4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a`.
A fresh clean import with the pinned Elementor 4.2.3 and Elementor Pro 4.2.2
proved one published `mijn-bibliotheek` Page, one outer container, one
Shortcode widget, one frontend mount, regenerated title CSS and Page-only
assets. The non-fatal upstream Elementor CLI warnings remain unrelated to the
verified structure.

**Elementor: existing 1A shell/artifact remains canonical; no 1C export delta
is required. Crocoblock: not used; no action required.**

## 12. Known non-blocking limitations

| Limitation | Classification | Exit effect |
|---|---|---|
| No reread/ordinal labels | Intentional 1C non-scope | None |
| No edit/delete from history | Intentional 1C non-scope | None |
| No rich source identity or Library/Item/Edition display | Intentional privacy/product non-scope | None |
| No full Reading History management page, filters or export | Future enhancement | None |
| No full WCAG audit | Future assurance activity | None within slice acceptance |

There are no blocking known limitations.

## 13. Final exit verdict

All 149 criteria are proven. Core, REST, frontend, browser, privacy,
performance, responsive, accessibility-within-slice, cleanup and regression
contracts are green. No product code or schema change is needed for closure.

**VERTICAL SLICE 1C — LEESGESCHIEDENIS OP BOEKDETAIL: GO / FORMALLY CLOSED.**
