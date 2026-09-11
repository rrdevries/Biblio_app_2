# WISH-DISC-01 — Wishlist discovery integration

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING** after the recorded
gates and independent review.

## Scope and source rule

WISH-DISC-01 connects the existing personal Wishlist UI to the completed
MH-DISC-01 discovery/materialization foundation. It adds no provider, ranking,
candidate storage, merge heuristic, Wishlist endpoint, Item or Library
possession behavior. Only current V2 code/runtime and deterministic synthetic
fixtures were used. No MIG-01 snapshot, DATA-01 set, old `/data/`, historical
Wishlist count or current V1 record was used or required.

## Consumer architecture

The add dialog sends exactly one query to
`POST /biblio/v1/me/bibliographic-discoveries`. Core alone classifies ISBN or
text, performs local-first title/author/ISBN discovery and conditional provider
fallback. An exact local ISBN Edition may end discovery, while text discovery
always combines local results first with remaining external candidates. It is
independent of Wishlist membership and removes no title-only lookalikes. The
strict UI decoder accepts only the current envelope, query type,
typed status, four result discriminators, explicit capabilities, canonical
local identity, temporary external identity, presentation fields, provider
attempt/evidence allowlists and presentation order.

Local results use their canonical Work/Edition IDs directly. An external
selection first calls the generic materialization endpoint with the selected
capability's `work_only|work_and_edition` intent; only returned canonical IDs
are then sent to the existing Wishlist POST/PATCH. A `discovery_id` or
`candidate_id` is never a Wishlist target. Provider evidence is validated but
provider identity, logo, ranking and confidence are not rendered.

Materialization and Wishlist mutation deliberately remain two authorized
transactions. If the second write fails, the UI retains the returned canonical
target and retries only the Wishlist write. If a materialization response is
lost, exact candidate replay remains the MH-DISC idempotency boundary while
the snapshot is available. This is not a correctness gap requiring a new
orchestration endpoint: durable platform bibliography is allowed to outlive a
consumer failure.

## Search and result behavior

The dialog has one labelled field: `Zoek op titel, auteur of ISBN`. There is no
provider selector or title/author mode. Results keep server order and remain
separate for `local_work`, `local_edition`, `external_work_candidate` and
`external_edition_candidate`; multiple Editions of one Work are never
collapsed or auto-selected.

Each result renders only supplied title, subtitle, contributors, publishers,
publication date/year, languages, ISBN, format and page count. Book identity
uses the shared serif language and controls use the shared sans/control tokens.
The bounded local discovery repository correction now projects existing
ordered Work contributors into local text Work and Edition candidates; it
changes no matching, identity or persistence rule.

Actions come only from `can_add_work_only` and
`can_add_edition_specific`. User-facing choices are `Uitgave maakt niet uit`
and `Deze specifieke uitgave`; the UI never derives capability from field
presence and never uses Work, Edition, candidate or materialization as action
language.

## Wishlist semantics

- local Work-only and Edition-specific selections call WISH-API-01 directly;
- external selections materialize first and then call that same API;
- exact local/external duplicate outcomes are treated as success and render no
  duplicate row;
- selecting a concrete Edition for an existing Work-only entry opens the
  explicit refinement confirmation and PATCH preserves the entry ID;
- reverse Work-only selection retains the stable 409 choice state, leaves all
  Edition wishes intact and performs no delete/create workaround;
- the existing Book Detail Edition action continues to use the same WISH-API
  POST/PATCH semantics.

## Failure, expiry and request state

Normal no-result, temporary provider failure, configuration failure and invalid
provider response are distinct successful discovery states. Provider failure
never becomes false zero-result copy: local text results remain usable and the
UI calmly reports when external expansion was not fully available. Retry is an
explicit fresh query. A 409
`biblio_metadata_lookup_snapshot_unavailable` is privacy-collapsed and therefore
shown truthfully as `verlopen of niet meer beschikbaar`; the UI never claims
expiry as the only cause and never silently refetches/materializes.

Abort plus monotonically increasing search revision prevents late responses
from winning. A new query invalidates old actions. One shared pending lock
disables dialog/list controls across materialization and Wishlist mutation, so
double materialization, double add, refinement races and stale conflict actions
cannot run concurrently. If an Edition POST encounters a concurrent Work-only
409, the client first reloads the authoritative Wishlist and only then offers
the explicit stable-ID refinement; it never presents that race as the reverse
collapse conflict. Canonical materialization output survives the separate
Wishlist retry state.

## No Item or possession effects

The integrated REST regression materializes an external Work, uses its returned
canonical ID in Wishlist POST and asserts one Wishlist entry while Item,
LibraryCatalogContext and Library activity counts stay zero. The existing
materialization suite independently covers Work+Edition evidence, replay and
concurrency with zero Item, inventory, Location, Condition, Acquisition,
Collection, ReadingRound, circulation, context, activity and Wishlist state.
Guarded UI fixtures additionally compare Item, Collection membership,
ReadingRound, context, classification and activity counts before/after the
Wishlist consumer flow.

## Accessibility, responsive and visual evidence

The native dialog has one named search field, keyboard-operable result/action
buttons, a polite live result status, explicit errors and pending disabled
controls. Async provider results do not move focus. Conflict/refinement/error
headings receive deliberate focus; closing restores the opener; successful
mutation announces the outcome and focuses the stable Wishlist row.

Responsive guards cover 1440px, 1024px, 768px, 390px and the established 720
CSS-pixel 200%-reflow equivalent. A concrete width guard prevents a visually
collapsed one-character Wishlist column in addition to horizontal-overflow
checks. Result metadata collapses to a mobile scan order and all controls retain
the shared 44px minimum target.

Guarded screenshots are under `.local/wish-disc-01-screenshots/after/` for the
default/list, local results, external title/author results, multiple Editions,
ISBN, Work/Edition choice, reverse conflict, provider miss/failure, 390px,
200% equivalent and Book Detail action. All screenshots use synthetic data.

## Manual gap and acceptance boundary

MH-DISC-01 exposes no generic manual materialization contract, so a complete
manual Wishlist bibliographic editor remains a possible deferred `WISH-MAN-01`
gap. Normal miss stays honest and Add Book manual commit is not reused. This is
not a WISH-DISC-01 exit blocker.

Technical closure does not claim Renée's normal-account visual/product
acceptance. That remains the documented human pass for title, author, ISBN,
local/external, Work-only, Edition-specific, multiple Editions, refinement,
reverse conflict, removal and product feel. Library switching stays later
release QA because Renée currently has one personal Library.

## Versions and verification

Schema remains `1023`; Biblio Core remains `2.5.0`; Biblio UI advances to
`0.15.0`. WISH-UI-01 and MH-DISC-01 commits remain unchanged below this slice.

Recorded gates are green: 249 frontend tests; 443 Core unit tests with 1,913
assertions (the two previously documented PHPUnit notices remain non-failing);
409 Core integration tests with 4,858 assertions; the focused schema-1023
discovery run with 9 tests and 51 assertions; PHP syntax; PHPStan over 741
files; strict Composer validation and platform requirements; Core and UI
WordPress smoke; manifest and whitespace checks. The final guarded browser run
passes 73 of 73 scenarios, including the 17-scenario Wishlist suite, all five
fixture refusal guards, double cleanup to zero rows and an unchanged
non-fixture fingerprint. These cover strict decoder/malformed response, local
and external title/author/ISBN, multiple Editions, both intents, duplicate add,
explicit refinement, reverse conflict, miss/failure/expiry, stale response,
concurrent reverse/refinement reconciliation, partial-failure retry, no
Item/possession, Book Detail including its conflict races, Hierna lezen/Work
discovery, Add Book, Metadata Hub replay/evidence/concurrency and
accessibility/reflow. The final independent re-review found no remaining
blocker.
