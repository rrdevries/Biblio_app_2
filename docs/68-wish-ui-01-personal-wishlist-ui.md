# WISH-UI-01 — Personal Wishlist UI

Status: **TECHNICAL GO / HUMAN VISUAL ACCEPTANCE PENDING**

Scope: the normally reachable V2.001 UI over WISH-CORE-01 and WISH-API-01.
No Core/schema/migration change and no grouping, reorder, priority, note,
retailer, price or automatic fulfilment behavior.

## 1. Reachability and composition

`/verlanglijst/` is an ordinary published WordPress Page with shortcode
`[biblio_wishlist_app]`. The shared Ink Light App Shell exposes Mijn
Bibliotheek, Verlanglijst and Hierna lezen as server-generated destinations;
Verlanglijst is active. The block-theme title is hidden on App Shell pages so
the application owns one H1.

The open list is the primary surface: title, ordered Authors when present,
human target meaning, added date and a direct remove action. Loading, empty,
list, request failure and local mutation failure remain distinct states.

## 2. Authoritative flows

- the list uses only `GET /me/wishlist` and an exact decoder;
- Work-only add reuses strict `GET /me/works` discovery;
- Book Detail is the only current source of a canonical Edition ID and offers
  one quiet secondary `Op verlanglijst` action;
- Work-only refinement requires confirmation and PATCH preserves entry ID;
- exact duplicate add is presented as already present;
- reverse Edition-to-Work 409 keeps every Edition and performs no merge/delete;
- remove is direct, has no fake Undo, and failure keeps the entry visible.

Library switching does not scope or replace this personal list. Core/REST
remain the only ownership and authorization authority.

## 3. Bounded discovery gap

There is no approved platform-wide Edition discovery endpoint. General add is
therefore Work-only; Edition-specific add is available from an authorized Book
Detail holding both canonical IDs. A future broader picker is `WISH-DISC-01`;
no provider candidate, ISBN guess or client-side identity matching was added.

## 4. Accessibility and responsive evidence

Native dialog, button, link, list and form semantics provide visible focus,
polite status, deliberate focus restoration and pending guards. Browser
acceptance covers 1440px, 1024px, 768px, 390px and the repository's 720 CSS px
200%-reflow equivalent without horizontal overflow. Screenshots are retained
under `.local/wish-ui-01-screenshots/after/` for the empty page, seeded mixed
list plus shell navigation, Work search, reverse conflict, Book Detail
entrypoint, 390px mobile layout and the 720 CSS px reflow equivalent.

## 5. Verification boundary

Synthetic owner-separated fixtures cover one Work-only entry, two Editions
for the same Work, a foreign entry, empty, duplicate, conflict, refinement,
removal and transport failures. Cleanup includes Wishlist state, entries and
history; fingerprinting covers the complete schema-1022 table set.

Human visual/device acceptance remains separate and must use a normal account
without fixture data. Schema remains `1022`; Biblio Core remains `2.4.0`;
Biblio UI is `0.14.0`.

## 6. Recorded gates

The final UI smoke passed PHP syntax, isolated WordPress registration/enqueue
checks and all `244` frontend tests. The focused Wishlist browser suite passed
`6/6`; the complete Playwright suite passed `62/62`. All five fixture-guard
scenarios passed, cleanup was idempotent and the non-fixture fingerprint was
unchanged.

The complete Core gate also passed PHPStan, Composer metadata/platform,
WordPress smoke, `433` unit tests with `1,857` assertions and `395` integration
tests with `4,749` assertions; the two existing negative-path notices remained
non-failing. Manifest JSON and whitespace checks passed. A separate final
architecture, ownership/privacy, accessibility and regression review found no
blocker.
