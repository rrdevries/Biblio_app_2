# 20 — Elementor Vertical Slice 1a UI integration and build plan

Status: **GO WITH CONDITIONS**

Date: 2026-08-24

Scope: implementation-ready plan for:

`Mijn Bibliotheek → active catalog overview → Item detail → Start Reading`.

This document plans UI integration only. It adds no Elementor page, template,
PHP/JavaScript/CSS, migration, endpoint, fixture or test.

## 1. Baseline and inspected runtime

Repository baseline:

- branch `main`;
- HEAD `65383ee233f95ed421b1c67b397eee3b799f621a`;
- F2.10, F2.11 and F2.12: GO;
- schema: 1007;
- A1, A2 and A3: closed;
- canonical Core gate: 409 tests / 2,301 assertions.

Current local WordPress evidence:

- WordPress 7.0.2;
- pretty permalinks: `/%postname%/`;
- active plugin: Biblio Core 2.1.0;
- active theme: Twenty Twenty-Five 1.5;
- Elementor, Elementor Pro, JetEngine and other Crocoblock plugins are not
  installed in the local runtime;
- there is no `Mijn Bibliotheek` page or Item detail page;
- there is no checked-in Elementor/JetEngine configuration or UI plugin;
- root `package.json` has no frontend build or Playwright dependencies.

Licensed plugin packages are deliberately not committed. Reproducible project
configuration may be committed. The architecture below therefore keeps the UI
component independently testable and makes Elementor a thin page shell.

## 2. Goal, authority and scope

Vertical Slice 1a must prove one complete browser path:

1. resolve available Libraries for the authenticated actor;
2. resolve one active Library target;
3. render its active Items;
4. navigate to one stable Item deep link;
5. render the scoped Item detail;
6. submit one exact Reading start date;
7. reconcile the UI from an authoritative detail reread.

Authority stays unchanged:

- Core decides actor, Library Context, access, status, capability, source,
  Work, lifecycle and conflict;
- REST parses and serializes;
- Biblio UI owns browser routing, request state and presentation;
- Elementor owns the surrounding page layout;
- capability output may hide or show an action but never authorizes it.

Out of scope are search, filters, Archive, Collections, Wishlist, Notes,
Ratings/Reviews, Next Reading, Authors/Series pages, Timeline, statistics,
goals, navigation redesign, broad metadata work and new REST routes.

## 3. Page and route architecture

### Options

| Option | Advantages | Costs/risks | Decision |
|---|---|---|---|
| ordinary WordPress page with query state | no rewrite lifecycle; Elementor-friendly; refresh/deep links work; smallest PHP surface | query-string URLs; component owns history state | **selected** |
| custom rewrite endpoint | clean path segments; future route family possible | rewrite flush/versioning, conflicts and Elementor main-query handling before it adds user value | reject for Slice 1a |
| memory-only app shell detail state | smallest initial navigation code | no reliable refresh/deep link; browser back/share fail | reject |

### Exact route contract

Use one ordinary published WordPress Page with slug `mijn-bibliotheek` and one
Biblio UI mount:

- entry/selection: `/mijn-bibliotheek/`;
- overview: `/mijn-bibliotheek/?library_id={library_id}`;
- detail: `/mijn-bibliotheek/?library_id={library_id}&item_id={item_id}`.

`library_id` and `item_id` are opaque URL-encoded identifiers, not secrets and
not trusted context. The client never derives one from a name. The REST call
validates every target again.

The Page routes translate only to the existing F2.12 calls:

| Browser trigger | REST request |
|---|---|
| every mount/refresh | `GET /biblio/v1/me/libraries` |
| resolved overview | `GET /biblio/v1/libraries/{library_id}/items` |
| `Meer laden` | the same GET plus returned `cursor`; no client-built offset |
| resolved detail | `GET /biblio/v1/libraries/{library_id}/items/{item_id}` |
| confirmed start form | `POST /biblio/v1/libraries/{library_id}/items/{item_id}/reading-rounds` with only `started_on` |
| successful start reconciliation | repeat the detail GET |

No UI-specific endpoint, nonce-refresh route or general-purpose Core API is
required.

The component uses `history.pushState()` for overview/detail navigation,
`replaceState()` for canonical default-Library selection and `popstate` for
back/forward. It stores no active Library in a cookie, PHP session,
`localStorage` or hidden Elementor field.

On every initial load or browser refresh the component:

1. reads URL state;
2. fetches `/me/libraries`;
3. resolves the URL Library only from that fresh result;
4. fetches overview or detail from REST;
5. renders the result or the operation-specific safe error state.

An invalid/inaccessible Item shows one non-enumerating detail state with a
link that clears `item_id`. An inaccessible Library clears neither ID
silently; it shows a full-page Library-unavailable state with an explicit
return action to `/mijn-bibliotheek/`.

## 4. Library Context UI contract

`GET /biblio/v1/me/libraries` is the only source for available Libraries,
names, designated-personal state and presentation capabilities.

Resolution order:

1. when the URL has `library_id` and exactly that ID exists in the fresh list,
   use it;
2. with no URL target and exactly one available Library, select it and
   canonicalize the URL with `replaceState()`;
3. with multiple Libraries and exactly one `designated_personal: true`, select
   that Library for this personal first slice and canonicalize the URL;
4. otherwise show a minimal Library chooser before loading an overview;
5. with zero Libraries show a neutral empty state; Slice 1a exposes no Library
   creation flow.

The minimal chooser contains name and the available read/use context needed to
identify a Library. It is not the later full switcher. A later switcher can
reuse the same response and must navigate by replacing `library_id` and
removing `item_id`.

Navigation preserves Library Context in the URL. Refresh therefore preserves
the requested target while revalidating access. If membership disappears
between the Library-list and resource request, the overview/detail REST call
remains final and can still return the safe 404 state.

## 5. Overview mapping and default layout

### Header

The component, not Elementor, owns the only page `<h1>`:

- primary heading: `Mijn Bibliotheek`;
- context line: current `library.name`;
- no raw ID, role or broad capability list is displayed.

Elementor supplies only the outer container/site chrome and must not add a
second visible page-title heading.

### Item mapping

| REST field | Slice 1a presentation |
|---|---|
| `item_id` | stable detail link state; not displayed |
| `work_id`, `edition_id` | retained only as response identity; not displayed or used for decisions |
| `title` | required card title/link |
| `authors` | render only when state is `known`; omit the line otherwise |
| `cover_reference` | render an image only when state is `known`; omit the cover region otherwise |
| `form` | map known `physical_book` to presentation label `Boek` |
| `location_or_source` | include only when state is `known` |
| `reading_status` | map `not_read`, `reading`, `read` to Dutch status text |
| `item_status` | no extra badge; endpoint is active-only |
| `capabilities.view_item` | presentation control for the detail link; REST remains authoritative |
| `capabilities.start_reading` | not used as an overview mutation; detail owns the action |

The context rule is exactly:

`Vorm · Locatie/bron · Leesstatus`.

Only known segments render; separators are generated between rendered
segments, never left dangling. Unknown data produces no `Onbekend`, empty
label, fake author, fake cover or fabricated location.

### Layout and pagination

Use one semantic catalog list, one Item per row at every breakpoint. This fits
the currently reliable title/context data better than a sparse cover grid and
can later receive a filter bar without changing the Item contract.

The complete card is one block link with a visible keyboard focus state. If a
known cover arrives later it occupies an optional leading column; the text
layout collapses when no cover exists.

Use a `Meer laden` button for cursor pagination:

- initial page size: the REST default 24;
- one click fetches the returned `next_cursor` and appends results in order;
- disable the button while loading;
- remove it when `next_cursor` is `null`;
- keep the cursor in component memory, not in the page URL;
- refresh restarts from page one;
- do not use page numbers or infinite scroll in Slice 1a.

## 6. Overview loading, empty and error states

| State | Functional heading | Meaning | Action |
|---|---|---|---|
| Library bootstrap loading | `Bibliotheek laden` | available contexts are being resolved | none; announce politely |
| overview loading | `Boeken laden` | first active Item page is loading | none; keep shell stable |
| empty Library | `Nog geen actieve boeken` | REST returned an empty Item list | none in Slice 1a |
| zero Libraries | `Geen bibliotheek beschikbaar` | actor has no available Library Context | return to Mijn Biblio/Home when that link exists |
| Library inaccessible | `Bibliotheek niet beschikbaar` | target is unknown, inactive or no longer accessible | clear target through explicit return action |
| request/server failure | `Bibliotheek kon niet worden geladen` | safe technical/network failure | `Opnieuw proberen` |
| invalid cursor after existing results | `Meer boeken konden niet worden geladen` | current appended list remains valid | retry once or restart from first page |

Partially unknown metadata is not an error state. The card simply omits those
elements. Technical messages, SQL, HTTP bodies and exception text never render.

## 7. Item detail mapping

The detail view keeps the same WordPress Page/mount. It renders a back link to
the current Library overview, an eyebrow `Mijn Bibliotheek`, and the Item title
as the page `<h1>`.

### Always-present first-slice content

- title;
- Library name from `detail.library`;
- known form;
- personal reading status;
- Reading summary;
- Start Reading action only when presentation capability permits it.

### Conditional content

| Field/section | Rule |
|---|---|
| authors | render below title only for `known` non-empty values |
| cover | render only for `known` value; otherwise collapse the cover column |
| ISBN, language, publisher, publication date, series | render one `Uitgave` section only when at least one field is known |
| location, condition, acquisition, availability | render one `Exemplaar` section only when at least one is known |
| form | show in the compact summary even when the Exemplaar section is absent |
| reading status | always show as text, never color alone |
| Reading counts | show non-zero counts; `historical_completed_rounds` is labelled as a subset, never added again to completed |

`missing`, `not_applicable` and `unknown` all omit the value in Slice 1a. Their
semantic difference remains in state and tests for future UI, but the page
does not become a list of unavailable labels. If an entire conditional section
has no known values, the section and heading are absent.

## 8. Start Reading interaction

The primary action sits after the reading summary near the top of detail.

- Render an enabled `Lezen starten` button only when
  `capabilities.start_reading === true`.
- When false, render no disabled explanation because the response does not
  distinguish view-only access from an exact-source active Round.
- Button visibility is only presentation; POST remains authoritative.

The button opens one accessible native-dialog interaction, styled as a centred
modal on tablet/desktop and a bottom sheet on mobile.

Form contract:

- one labelled `<input type="date">` for `started_on`;
- propose the browser's current local calendar day, not a UTC-derived day;
- allow the user to change it;
- require a complete valid `YYYY-MM-DD` before submit;
- do not invent a maximum-today rule that Core does not currently specify;
- send exactly `{ "started_on": "YYYY-MM-DD" }`;
- disable submit and close controls that would resubmit while the request is
  pending;
- use one in-flight request/abort controller and never double-submit.

Outcome handling:

- 201: announce success, close the dialog and run the authoritative detail
  refresh described below;
- 400/422: keep dialog open and attach safe validation feedback to the date;
- 409 active-source conflict: close or disable the form, announce that state
  changed and refresh detail;
- 404: show safe inline unavailability, then refresh detail/context;
- invalid nonce: do not retry POST; offer full page refresh;
- network/500/503: keep entered date, re-enable one explicit retry, and never
  claim success.

## 9. Mutation reconciliation strategy

Selected strategy: **use the mutation response as acknowledgement, then fetch
detail again**.

Do not optimistically calculate `reading.status`, counts or
`start_reading`. The 201 response confirms the new Round ID/source/lifecycle
but does not contain the complete F2.11 detail projection.

After 201:

1. retain the returned `reading_round_id` only for diagnostics/testing during
   the current transition;
2. show `Leesstatus bijwerken` with `aria-live="polite"`;
3. GET the same detail route;
4. replace the complete detail view from that response;
5. move focus to the updated reading-status heading/notice.

If POST succeeded but the detail refresh fails, show `Lezen is gestart, maar
de actuele pagina kon niet worden vernieuwd` with `Pagina vernieuwen`. Never
repeat the mutation automatically.

## 10. Elementor, Crocoblock and Biblio UI matrix

| Concern | Elementor | JetEngine/Crocoblock | Biblio UI plugin |
|---|---|---|---|
| ordinary Page and outer containers | yes | no | mount only |
| site header/footer and static surrounding layout | yes | no | no |
| dynamic page heading/content | no | no | yes |
| Library/application state | no | no | yes |
| REST reads/mutation and nonce header | no | no | yes |
| overview cards and cursor append | no | no | yes |
| detail rendering and URL history | no | no | yes |
| modal/sheet and form state | no | no | yes |
| responsive global shell | yes | no | component internals only |
| business rules/authorization | no | no | no; Core only |

Crocoblock decision for Slice 1a:

- Dynamic Listing: no; the source is a custom paginated REST read model, not
  posts/CPT/CCT;
- Query Builder: no; it would create a second query path around F2.11;
- Dynamic Visibility: no authorization or mutation gating; simple Elementor
  shell visibility adds no value here;
- JetFormBuilder: no; POST, nonce, conflict and refresh semantics need the
  existing REST client;
- JetSmartFilters: no filters exist in this slice.

JetEngine is neither required nor currently installed.

## 11. Frontend technology choice

### Comparison

| Strategy | Advantages | Disadvantages | Verdict |
|---|---|---|---|
| vanilla JavaScript ES modules | no framework/runtime; direct REST fit; independently testable; WordPress 7 has Script Modules API | component conventions must be disciplined | **selected** |
| small Web Components | reusable custom tags and lifecycle | unnecessary custom-element lifecycle; Shadow DOM complicates Elementor/global styles and dialog semantics | not yet |
| React island | mature state/render model | build/runtime/dependency overhead for two views and one form; WordPress React coupling adds little | no |
| JetEngine-native listing | visual builder convenience for WordPress records | mismatched custom REST/cursor/error model; risks duplicate query/visibility logic | no |

Use light-DOM, browser-native ES modules with small single-purpose modules:

- `api.js`: REST transport and normalized transport errors;
- `route-state.js`: query parsing, URL building and history;
- `library-state.js`: target selection;
- `overview-view.js` and `detail-view.js`: allowlisted rendering;
- `start-reading-view.js`: dialog/form/mutation lifecycle;
- `app.js`: orchestration only.

No SPA framework and no bundling/transpilation step are needed for Slice 1a.

## 12. Nonce and configuration bootstrap

Selected bootstrap: **escaped data attributes on the single mount element**.

The Biblio UI PHP adapter renders the mount through a shortcode such as
`[biblio_library_app]` and supplies only:

- REST root from `rest_url("biblio/v1/")`;
- a current `wp_create_nonce("wp_rest")` value;
- canonical overview Page URL;
- a server-generated login URL;
- UI plugin version/locale if needed.

The mount may conceptually look like:

```html
<div
  data-biblio-ui-root
  data-rest-root="…/wp-json/biblio/v1/"
  data-rest-nonce="…"
  data-overview-url="…/mijn-bibliotheek/"
></div>
```

PHP escapes every attribute. The nonce is a CSRF token, not an authorization
claim or secret, and is never hardcoded in Elementor Custom Code.

Every REST request uses same-origin credentials, `Accept: application/json`
and `X-WP-Nonce`. POST additionally sends `Content-Type: application/json`.

Nonce/session expiry for Slice 1a:

- never retry a mutation automatically after `rest_cookie_invalid_nonce`;
- show `Sessie vernieuwen` and perform a full page reload;
- reload obtains a fresh server-rendered nonce when the WordPress session is
  still valid;
- if the session ended, subsequent 401 shows the login action;
- no nonce-refresh endpoint is added.

## 13. Asset architecture and reproducibility

Create a separate, versioned `biblio-ui` WordPress plugin in the implementation
phase. Do not put UI assets in Biblio Core, Twenty Twenty-Five, Elementor Custom
Code or Code Snippets.

Rationale:

- Core remains independently testable and frontend-agnostic;
- changing the active/child theme cannot remove application behavior;
- the component can render in a normal Page without Elementor for tests;
- permanent PHP/JS/CSS and versioning live in Git;
- Elementor remains replaceable presentation around one mount.

Planned location:

```text
web/wp-content/plugins/biblio-ui/
  biblio-ui.php
  src/Plugin.php
  src/LibraryAppShortcode.php
  assets/js/app.js
  assets/js/api.js
  assets/js/route-state.js
  assets/js/library-state.js
  assets/js/overview-view.js
  assets/js/detail-view.js
  assets/js/start-reading-view.js
  assets/css/app.css
```

Implementation must update the repository plugin allowlist in `.gitignore`.
Use WordPress 7's `wp_register_script_module()`/
`wp_enqueue_script_module()` and `wp_enqueue_style()`. Register assets in the
plugin and enqueue them on `wp_enqueue_scripts` only when
`is_page("mijn-bibliotheek")`; do not wait until shortcode rendering, because
the stylesheet belongs in the document head. Use one Biblio UI plugin version
for JS/CSS cache busting. No npm production dependency or build artefact is
needed.

Elementor exports belong later under
`config/elementor/vertical-slice-1a/`; licensed packages, local IDs/secrets and
`.local.*` overrides remain outside Git.

## 14. Elementor Page and template plan

Use one ordinary WordPress Page, not a Theme Builder single template and not a
second detail Page.

Page contract:

- title/slug: `Mijn Bibliotheek` / `mijn-bibliotheek`;
- editable with Elementor after Elementor is installed;
- one outer content container using Global Styles;
- one Shortcode widget containing `[biblio_library_app]`;
- no Elementor Loop Grid, dynamic query, form or visibility rule;
- no duplicate static `<h1>`; the component owns view-specific heading;
- existing site header/footer may surround it, but full navigation redesign is
  outside Slice 1a.

The same mount renders selection, overview and detail from URL state. This
keeps refresh and direct links stable while avoiding duplicate Elementor page
configuration.

The implementation exit must export the Page and relevant Site Settings/Kit
configuration into the chosen checked-in `config/elementor` directory and
document how to import it into a clean local runtime.

## 15. Minimum design-system contract

No approved Biblio visual token source is present in the repository. Slice 1a
therefore defines functional component tokens, aliases them to Elementor
Global Styles when available and uses sober fallbacks. This is not a visual
identity redesign.

Minimum tokens:

- spacing: 4, 8, 12, 16, 24, 32, 48 and 64 px;
- content maximum: 72 rem; detail reading column maximum: 48 rem;
- typography: inherited body; one page-title, section-heading, body and small
  metadata level;
- controls: primary, secondary and quiet link; minimum height 44 px;
- cards: 1 px boundary, 12 px radius, 16–24 px internal spacing;
- status: text label plus optional shape/icon, never color only;
- loading: stable reserved region, visible text and `aria-live`;
- error/empty: one heading, one short explanation and at most one primary
  recovery action;
- dialog: maximum 32 rem desktop/tablet, mobile bottom-sheet treatment;
- focus ring: visible 2 px minimum contrast-aware outline.

CSS custom properties use `--biblio-*` names. Color and font aliases may read
Elementor global CSS variables with neutral accessible fallbacks; component
layout must not depend on undocumented Elementor DOM selectors.

## 16. Responsive rules

Align with the functional Elementor breakpoint family:

- mobile: below 768 px;
- tablet: 768–1023 px;
- desktop: 1024 px and above.

### Overview

- one list Item per row on all widths;
- known cover: approximately 96×144 px desktop, 72×108 px tablet and 64×96 px
  mobile; absent cover removes the column;
- context line wraps normally without horizontal scrolling;
- the whole card link remains at least 44 px high;
- `Meer laden` becomes full-width on mobile.

### Detail

- desktop: optional cover column up to 16 rem plus content; without cover use
  one 48 rem content column;
- tablet: optional cover up to 12 rem plus content where space permits;
- mobile: cover above content, sections stacked, primary action full-width;
- metadata uses semantic definition lists and never a horizontally scrolling
  table.

### Start Reading

- desktop/tablet: centred dialog;
- mobile: bottom sheet with labelled date input and vertically stacked actions;
- no sticky action that obscures content or browser controls in Slice 1a.

## 17. Accessibility minimum

- exactly one view-specific `<h1>` and ordered section headings;
- overview is a semantic list; Item navigation is an anchor, mutation is a
  button;
- all interactive elements are keyboard reachable with visible focus;
- the entire Item target is usable without nesting buttons inside links;
- date input has a visible label, description and associated inline error;
- dialog moves focus to its heading/first field, traps focus through native
  dialog behavior and returns focus to the opening button on close;
- Escape cancels only when no mutation is in flight;
- root/regions use `aria-busy`; loading and mutation outcomes use restrained
  `aria-live="polite"` announcements;
- blocking errors use an alert heading without repeatedly announcing full
  content;
- reading status and errors are never conveyed only through color/icon;
- minimum pointer target is 44×44 px;
- decorative visuals are hidden from assistive technology; known covers get
  useful alternative text based on title;
- browser back/forward changes focus to the new view heading.

Slice 1a exit includes keyboard and screen-reader smoke checks, not a claim of
a complete accessibility audit.

## 18. REST error to UX mapping

The UI branches on machine code plus operation context, never on exception
message text.

| Machine/status | Initial overview/detail | Load more | Start Reading | Recovery |
|---|---|---|---|---|
| `biblio_authentication_required` / 401 | full-page session state | retain list, blocking notice | dialog/session notice | log in or reload |
| `rest_cookie_invalid_nonce` / 403 | full-page session-refresh state | retain list | inline blocking dialog state | full reload; never auto-retry POST |
| `biblio_resource_not_available` / 404 | operation-specific Library or Item unavailable page | retain current list | safe inline unavailable, then reread | return to overview/context |
| `biblio_reading_round_already_active_for_source` / 409 | n/a | n/a | state-changed notice | authoritative detail refresh |
| other mapped conflict / 409 | safe changed-state view | retain list | inline conflict | refresh current resource |
| missing/type/syntax/unknown fields / 400 | safe invalid-route/request state | restart pagination for invalid cursor | associated form error | correct/restart |
| `biblio_validation_failed` / 422 | safe request state | retain list | associated form error | correct date/input |
| `biblio_core_unavailable` / 503 | full-page temporary-unavailable state | retain list | inline temporary failure | explicit retry |
| `biblio_internal_error` / 500 | full-page generic failure | retain list | inline generic failure | explicit retry |
| network/timeout | full-page offline/request failure | retain list | keep input, unknown-not-success | explicit retry; never claim mutation success |

For a 404, the operation tells the UI whether the unavailable target was the
Library or Item; the shared response deliberately reveals no further reason.
Toast-only errors are not used for blocking states. Toasts may supplement a
successful state announcement but never contain the only recovery control.

## 19. E2E and fixture plan

Use Playwright in the implementation phase. Add the exact development
dependency and lockfile to the root package; do not add a frontend framework.

### Guarded deterministic fixture

Provide a test-only WP-CLI fixture setup/cleanup script that refuses to run
unless WordPress environment type is `local` and the site host matches the
project's DDEV host. It may create and remove only exact `e2e-` identities.

Required data:

- authenticated actor `biblio_e2e_actor`;
- foreign actor `biblio_e2e_other`;
- designated personal active Library with Owner · Direct access;
- at least two sorted active Items with unknown optional metadata;
- one startable Item;
- one same-actor Item that already has an active Round for conflict coverage;
- one foreign-Library Item that cannot be resolved in the actor's context;
- optional zero/empty Library fixture for component-state coverage.

Credentials come from environment variables and are never committed. Fixture
cleanup targets only those known IDs/users and runs before and after the suite.

### Primary Playwright flow

1. log in through WordPress and save authenticated storage state;
2. open `/mijn-bibliotheek/`;
3. verify selected Library heading and first Item page;
4. open the startable Item through its accessible link;
5. verify direct deep-link URL and detail title/status;
6. open Start Reading, keep or change the proposed exact date and submit once;
7. verify success announcement and refreshed `reading` status;
8. reload the browser page;
9. verify the same Library/Item route and authoritative reading state persist.

### Negative/security flows

Minimum required:

- direct URL for the foreign Item under the actor Library returns the generic
  Item-unavailable UX and exposes no foreign title/details;
- active-source conflict produces the state-changed UX and detail reread;
- invalid nonce is simulated by replacing the mount nonce before POST, proves
  no automatic mutation retry and offers page reload.

Use semantic role/name selectors first. Stable `data-biblio-view` and
`data-biblio-item-id` hooks may support route/state assertions but may never be
authorization inputs.

## 20. Twelve-step implementation sequence

Each step is separately reviewable and testable.

1. **Runtime prerequisite and config convention** — install/activate agreed
   Elementor/Pro versions locally, record versions, create the
   `config/elementor/vertical-slice-1a` convention; prove plugin/theme/page
   inventory without creating the page yet.
2. **Biblio UI plugin foundation** — add the Git allowlist, plugin lifecycle,
   shortcode mount and isolated PHP smoke test; prove it runs without
   Elementor and does not change Core.
3. **Config and asset bootstrap** — add data attributes, Script Modules API,
   stylesheet/versioning and page-only enqueue; test escaped REST URL/nonce.
4. **REST client and transport states** — implement same-origin headers,
   envelope parsing, abort behavior and machine-error normalization; unit-test
   representative success/error envelopes.
5. **URL router and Library resolution** — implement query state,
   push/replace/popstate and the exact one/multiple/designated/zero rules;
   unit-test URLs and selection decisions.
6. **Overview component** — loading/empty/error states, allowlisted Item list,
   context line and `Meer laden`; component-test cursor append and invalid
   cursor recovery with fixtures.
7. **Detail component** — deep link, back navigation, conditional metadata and
   Reading summary; component-test unknown-field omission and 404 behavior.
8. **Start Reading interaction** — accessible dialog, local-day proposal,
   validation, one in-flight POST, conflict/nonce/network handling and detail
   reread; component/integration-test no optimistic status truth.
9. **Ordinary Elementor Page shell** — create `Mijn Bibliotheek`, add one
   shortcode mount, remove duplicate page heading and export Page/Kit config;
   import into a clean local runtime as proof.
10. **Design tokens, responsive and accessibility pass** — apply the minimum
    CSS contract and verify keyboard, focus, live regions and three breakpoint
    families.
11. **Playwright fixture and E2E** — add guarded fixture setup/cleanup, primary
    refresh flow and the three negative/security flows.
12. **Vertical-slice exit** — run JS/PHP/UI/E2E checks plus existing relevant
    Core smoke, inspect export reproducibility and scope, document verdict and
    commit cleanly.

Do not combine steps 6–8 into one unreviewable component commit. Do not create
new REST endpoints to simplify frontend state.

## 21. Risks, conditions and blockers

| Risk/condition | Impact | Control |
|---|---|---|
| Elementor/Pro absent locally | Page/export step cannot currently execute | prerequisite step 1; user supplies licensed package/access |
| no existing Global Styles or approved visual source | visual polish cannot be claimed final | use only minimum functional aliases/fallbacks; treat later brand work separately |
| no current UI plugin/page/export | clean-start work is required | explicit plugin/page/config sequence above |
| no Playwright dependency/fixture | E2E is not presently runnable | locked dev dependency and guarded fixture in step 11 |
| nonce expires after long-open page | mutation can receive 403 | full reload; never silently repeat POST |
| POST succeeds but detail reread fails | UI cannot safely derive final state | acknowledge success, require refresh, never repeat mutation |
| cursor becomes invalid/stale | more-results request fails | retain loaded Items and restart from page one |
| optional catalog metadata remains unknown | detail/overview are visually sparse | conditional sections and list-first layout; no placeholders |
| URL contains opaque IDs | malformed/stale targets are possible | encode strictly and revalidate through `/me/libraries` plus resource endpoint |
| Elementor CSS/DOM changes | component could inherit unintended style | `--biblio-*` tokens, light DOM contract, no undocumented Elementor selectors |

There is no missing REST/Core contract and no product-rule blocker for the
chosen direct-access personal-Library flow.

Two conditions remain before the entire Elementor slice can be declared built:

1. install and activate the licensed Elementor runtime/version selected for
   the project;
2. prove a clean import/export path for the Page and Global Styles/Kit config.

Steps 2–8 can technically be developed and tested without Elementor, but the
Elementor integration and final exit cannot close until both conditions are
met. Installing licensed plugins or choosing credentials is external to this
analysis commit.

## 22. Exit verdict

**GO WITH CONDITIONS.**

The UI architecture, URL state, REST mapping, component ownership, nonce
bootstrap, mutation reconciliation, responsive/accessibility minimum,
fixtures and twelve-step build route are concrete. Implementation needs no new
Core rule or REST endpoint and need not guess how Library, Item or Reading
authority works.

It is not yet unconditional `GO FOR UI BUILD` because the inspected local
runtime has no Elementor/Pro installation and no reproducible Elementor
configuration path to verify. Biblio UI plugin work may begin from this plan;
the Elementor Page/export step must wait for the two explicit conditions in
section 21. No UI was built in this analysis.
