# Elementor Vertical Slice 1B — Exit Evidence

Date: 2026-08-30
Final verdict: **GO**

## 1. Authority, baseline and scope

This record closes Vertical Slice 1B step 7. The current checkout, Git history
and the fresh checks below are the implementation evidence.

- branch at start: `main`;
- start HEAD: `9a39b305f3914e358222f847b72b8c31ac2f3396`;
- `origin/main` at start: `9a39b305f3914e358222f847b72b8c31ac2f3396`;
- worktree at start: clean;
- pre-exit-commit HEAD: `9a39b305f3914e358222f847b72b8c31ac2f3396`.

The closed slice is:

`Mijn Bibliotheek → Item detail → exact active ReadingRound → Leesronde
afronden → completed/stopped → authoritative mutation → authoritative detail
reread → persistent history`.

It does not add reading-history UI, explicit reread copy, a stopped-reason,
pause, search/filter/archive, Collections, Notes/Reviews/Goals, new schema,
new routes beyond the end route, Elementor application behavior or
Crocoblock behavior.

## 2. Implementation sequence

Git confirms the exact hashes and subjects:

1. `3c12955941704af379afa314cb3b609b3f1270db` — Fix stale ReadingRound end semantics;
2. `5c1ebca7ee9bdf354f13785563b3a685485e3b25` — Expose active ReadingRound in item detail;
3. `18dcbfd699c6067193d99d5d77189c8eada76e25` — Add ReadingRound end REST endpoint;
4. `87883a2b8e82da90c96851ed3373fa71395ea487` — Add ReadingRound end UI runtime;
5. `6473f704b994a5f284111e98d253e29954832c6b` — Add ReadingRound end dialog;
6. `9a39b305f3914e358222f847b72b8c31ac2f3396` — Add ReadingRound end E2E coverage.

## 3. Architecture verdict

- **Core is authoritative:** authenticated ownership, lifecycle, outcome,
  content-date semantics, version, stale/no-op decisions and historical
  preservation live behind named application boundaries and owner-scoped
  repositories.
- **REST is an adapter:** WordPress authentication/nonce boundary, typed
  request parsing, dispatch to Finish/Stop, named allowlist serialization and
  safe error translation. It has no ReadingRound business rule.
- **Biblio UI is orchestration and presentation:** validated browser state,
  one mutation, authoritative reread, focus, error and recovery behavior. A
  capability controls presentation only and never authorizes.
- **Elementor is the unchanged shell:** one ordinary Page, one outer
  container, one Shortcode widget and one Biblio mount.
- **Crocoblock is not used or required.**

No architecture document or ADR needed amendment: the accepted Core/adapter/UI
boundaries already describe the implemented result.

## 4. Evidence map

The compact matrix uses these evidence groups:

- **C:** `ReadingRound`, `ReadingRoundEnd`, Finish/Stop services,
  `WpdbReadingRoundRepository`, ADR-007 and ReadingRound unit/integration and
  concurrency tests;
- **D:** catalog detail views/service/repository, allowlisted REST detail and
  `CatalogUiReadModelsTest`;
- **R:** route/controller/parser/serializer/error mapper and `RestApiTest`;
- **U:** `app.js`, `detail-view.js`, `end-reading-view.js`, `app.css` and the
  complete 112-test frontend suite;
- **E:** the 13-case Playwright run: five 1A cases and eight 1B cases;
- **F:** guarded fixture, refusal tests, runner, double cleanup, residue check
  and before/after fingerprint;
- **S:** schema 1003 active-source predicates/indexes and the full schema and
  migration suite;
- **P:** clean Elementor 1A Page/Kit import and Page/asset verifier.

## 5. Acceptance criteria matrix

| # | Criterion | Result | Evidence |
|---:|---|---|---|
| 1 | Actor-owned active round on exact Item is recognized | **BEWEZEN** | D, R, E |
| 2 | Same Work on another Item is not projected as active | **BEWEZEN** | D |
| 3 | Foreign/private round is not projected | **BEWEZEN** | D, R, E |
| 4 | End action appears only for current server capability and active round | **BEWEZEN** | D, U, E |
| 5 | Explicit completed or stopped choice is required | **BEWEZEN** | U, E |
| 6 | No default outcome | **BEWEZEN** | U, E |
| 7 | Exact required `YYYY-MM-DD` finish date | **BEWEZEN** | C, R, U |
| 8 | Browser-local today is the UX default | **BEWEZEN** | U, E |
| 9 | UI adds no `max=today` domain rule | **BEWEZEN** | U, R |
| 10 | Completed dispatches to Finish service | **BEWEZEN** | R |
| 11 | Stopped dispatches to Stop service | **BEWEZEN** | R |
| 12 | Core enforces server-side ownership | **BEWEZEN** | C, R |
| 13 | Library Owner/Manager cannot override private ownership | **BEWEZEN** | C, R, E |
| 14 | Unknown and foreign rounds are non-enumerating equivalents | **BEWEZEN** | R, E |
| 15 | Cookie auth and `wp_rest` nonce are applied | **BEWEZEN** | R, U, E |
| 16 | Completed ends the active round | **BEWEZEN** | C, R, E |
| 17 | Stopped ends the active round | **BEWEZEN** | C, R, E |
| 18 | Ended round remains history; no overwrite/delete | **BEWEZEN** | C, R, E |
| 19 | Real mutation increments version exactly once | **BEWEZEN** | C, R, E |
| 20 | Identical stale retry is an idempotent 200 no-op | **BEWEZEN** | C, R, E |
| 21 | Divergent stale intent returns typed 409 | **BEWEZEN** | C, R, E |
| 22 | Same outcome with another stale date returns typed 409 | **BEWEZEN** | C, R |
| 23 | Current-version incompatible ended lifecycle returns 422 | **BEWEZEN** | C, R, E |
| 24 | Finish before start remains Core validation | **BEWEZEN** | C, R |
| 25 | Presentation supplies no user/owner/round ID/version | **BEWEZEN** | U |
| 26 | Round ID/version come from validated current detail | **BEWEZEN** | U |
| 27 | POST acknowledgement is not authoritative detail state | **BEWEZEN** | U |
| 28 | Success always performs authoritative GET reread | **BEWEZEN** | U, E |
| 29 | POST 200 plus reread failure causes no retry/false success | **BEWEZEN** | U |
| 30 | 409 performs no retry and reconciles by reread | **BEWEZEN** | U, E |
| 31 | 404 performs no retry and safely reconciles | **BEWEZEN** | U, R |
| 32 | Invalid nonce never automatically retries mutation | **BEWEZEN** | U, E |
| 33 | Ambiguous abort/outcome unknown never automatically retries | **BEWEZEN** | U |
| 34 | Duplicate pending submit produces exactly one POST | **BEWEZEN** | U, E |
| 35 | Completed UI state is correct after reread | **BEWEZEN** | U, E |
| 36 | Stopped state is independent and not shown as completed | **BEWEZEN** | U, E |
| 37 | State persists after browser reload | **BEWEZEN** | E |
| 38 | Back/forward retains valid route/state | **BEWEZEN** | U, E |
| 39 | Native dialog has an accessible name/description | **BEWEZEN** | U, E |
| 40 | Outcomes are grouped radios and keyboard-operable | **BEWEZEN** | U, E |
| 41 | Date has a visible label and error association | **BEWEZEN** | U |
| 42 | Escape closes an idle dialog | **BEWEZEN** | U, E |
| 43 | Escape/cancel cannot hide a pending mutation | **BEWEZEN** | U, E |
| 44 | Cancel returns focus to the trigger | **BEWEZEN** | U, E |
| 45 | Reconciliation focuses the logical new-state status | **BEWEZEN** | U, E |
| 46 | Controls meet the 44 px minimum target | **BEWEZEN** | U, E |
| 47 | Focus ring is visible | **BEWEZEN** | U, E |
| 48 | Mobile uses a bottom sheet | **BEWEZEN** | U, E |
| 49 | Tablet/desktop use a centered dialog | **BEWEZEN** | U, E |
| 50 | No horizontal overflow at 390×844, 900×900, 1280×900 | **BEWEZEN** | U, E |
| 51 | Completed E2E happy path | **BEWEZEN** | E |
| 52 | Stopped E2E happy path | **BEWEZEN** | E |
| 53 | Stale 409 E2E | **BEWEZEN** | E |
| 54 | Invalid nonce E2E | **BEWEZEN** | E |
| 55 | Foreign/privacy at E2E/API level | **BEWEZEN** | R, E |
| 56 | Idempotent retry over real WP REST stack | **BEWEZEN** | R, E |
| 57 | Current-version 422 over real stack | **BEWEZEN** | R, E |
| 58 | Fixture refuses unsafe local/DDEV/project/host contexts | **BEWEZEN** | F |
| 59 | Cleanup removes only allowlisted E2E data | **BEWEZEN** | F |
| 60 | Cleanup is idempotent | **BEWEZEN** | F |
| 61 | Non-E2E fingerprint is equal before/after | **BEWEZEN** | F |
| 62 | Existing Vertical Slice 1A remains regression-free | **BEWEZEN** | E, P |
| 63 | No schema/migration change is needed | **BEWEZEN** | S, Git diff |
| 64 | No Elementor change is needed | **BEWEZEN** | P, Git diff/hash |
| 65 | No Crocoblock change is needed | **BEWEZEN** | Architecture/Git diff |
| 66 | End mutation creates no ActivityEvent/private audit | **BEWEZEN** | C, R, E |
| 67 | Later start can create a new round while ended history remains | **BEWEZEN** | C, D, S, E |

Totals: **67 BEWEZEN, 0 GEDEELTELIJK BEWEZEN, 0 NIET BEWEZEN**.

Criterion 67 is established by the combined production contracts: after end,
the existing row remains ended with the same identity; the active-only
generated uniqueness value is released; the authoritative detail exposes
`start_reading=true`; and Start Reading always creates a newly generated
ReadingRound rather than reopening the historical row.

## 6. Lifecycle and history integrity

Completed and stopped preserve the same ReadingRound ID, user, Work, source,
provenance and creation instant. A real active-to-ended transition records the
exact content calendar date, sets server-side technical timestamps and raises
the version from 1 to 2. It does not delete the row. Work and provenance remain
immutable and source is not silently changed.

The database's active-source generated columns depend on
`round_outcome IS NULL`, so ending releases active-source uniqueness without
removing history. A later Start Reading uses a new opaque ID and the existing
ended row continues contributing to completed/stopped history. The end use
case has no ActivityEvent dependency and no private audit table/event was
introduced.

## 7. Security and privacy review

Final review found no blocker:

- owner-scoped locked read plus user/version/lifecycle CAS prevents IDOR,
  Library-role escalation and stale-version bypass;
- authenticated WordPress identity is the only user source; the route accepts
  no caller-supplied owner, user, Library or capability authority;
- the UI derives ID/version only from strict current-detail state;
- known-foreign and unknown IDs share safe 404 code, status and message;
- nonce failures are rejected by WordPress and mutations are never retried
  automatically after nonce, abort, network uncertainty, 409 or 404;
- success and error serializers are named allowlists; raw exceptions, SQL,
  stacks, owner IDs and technical timestamps are not returned;
- fixtures require explicit opt-in plus exact local environment, DDEV project
  and host, and cleanup targets only exact marked/allowlisted records.

The intentionally logged fake SQL exception during `RestApiTest` is the
negative exception-leakage test; the HTTP response remains generic.

## 8. Fresh gate results

| Gate | Fresh result |
|---|---|
| Core Composer metadata/platform | PASS |
| Core PHP syntax | PASS |
| Core PHPStan level 6 | 0 errors |
| Core unit | 230 tests, 882 assertions, 0.045 s |
| Core integration | 209 tests, 1693 assertions, 42.261 s |
| Core total | 439 tests, 2575 assertions |
| Targeted end + REST integration | 33 tests, 353 assertions, 3.153 s |
| Core schema/migrations | PASS within integration suite |
| Core WordPress/REST runtime smoke | active, class loaded, init hook 1, HTTP 200 |
| Core complete gate | PASS in 78 s |
| Biblio UI PHP/JS syntax and isolated smoke | PASS |
| Biblio UI frontend tests | 112/112 PASS, 99.057 ms |
| Playwright 1A | 5/5 PASS |
| Playwright 1B | 8/8 PASS |
| Playwright total | 13/13 PASS in 24.5 s |
| Fixture refusal/cleanup/residue/fingerprint | PASS |
| Clean Elementor import/Page/assets | PASS |
| Manifest JSON and Git whitespace | PASS |

The E2E runner first proved refusal without opt-in, outside local WordPress,
for the wrong DDEV project and for the wrong host. It then cleaned twice,
proved zero residue, captured a fingerprint, set up exact records, ran all
tests with one worker, cleaned twice, re-proved zero residue and compared the
fingerprint.

Before and after were identical:

- Core rows: `0`;
- Core SHA-256:
  `0ce27bbce14013bfb222a2eb124a0bd8647df81294cbf8b874dd395580b6eb9b`;
- non-E2E users: `2`;
- non-E2E user SHA-256:
  `b6d2e8b80642d96611fc23d42ea98a0105b6634ee81e34721850d3ad8ae0395d`;
- `biblio_dev`: present.

## 9. Responsive, accessibility and non-regression evidence

The 1B browser cases prove native dialog naming, radio keyboard operation,
required labelled date input, idle Escape, trigger-focus return, pending
dismissal lock, post-reconciliation status focus, ≥44 px controls, visible
focus and no horizontal overflow at all three required viewport sizes. Mobile
uses the bottom sheet; tablet and desktop use the centered dialog.

All five existing 1A browser cases passed unchanged, including Start Reading,
foreign Item non-enumeration, stale conflict, invalid nonce and responsive
acceptance.

## 10. Elementor and Crocoblock scope

The 1B commit range contains no Elementor artifact change. The artifact at
`config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip` has the same
pre-1B and current SHA-256:
`4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a`.

A clean temporary import with Elementor 4.2.3 and Elementor Pro 4.2.2 proved
one published `mijn-bibliotheek` Page, one outer container, one Shortcode
widget, one frontend mount, regenerated title CSS and Page-only assets.

**Bestaande 1A Elementor artifact blijft canoniek; 1B heeft geen
Elementor-delta.** Crocoblock is neither used nor required.

The known Elementor CLI deprecation and undefined-key warnings during import
are third-party, non-fatal output; import and all structural assertions pass.

## 11. Known non-blocking limitations

- No separate reading-history UI: outside 1B, future slice, no exit blocker.
- No reason field for stopped: outside 1B, future product decision, no blocker.
- No explicit `Opnieuw lezen` copy: outside 1B; the authoritative post-end
  state exposes the existing Start Reading action and Core creates a new row.
- Richer author/cover bibliographic metadata remains outside this slice.
- No Crocoblock behavior and no Elementor rebuild were required.

## 12. Final decision

**GO.** All 67 criteria are proven, every canonical gate is green, security
and history reviews found no blocker, fixture residue is zero, the non-E2E
fingerprint is unchanged, 1A remains regression-free and the existing
architecture documentation matches the implementation. Step 7 changes only
exit/status documentation and the manifest; it changes no product code and
adds no schema, route, Elementor or Crocoblock behavior.
