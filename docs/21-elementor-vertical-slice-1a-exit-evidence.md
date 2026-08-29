# Elementor Vertical Slice 1A — exit evidence

Date: 2026-08-29
Final verdict: **GO**

## 1. Authority and baseline

This record closes step 12 of the contract in
`docs/20-elementor-vertical-slice-1a-build-plan.md`. The plan remains the
historical intent; the repository, Git history and the fresh checks recorded
here are the implementation evidence.

- branch at start: `main`;
- start HEAD: `b29484f557f97273dff509d1d46449b2fd3f434a`;
- `origin/main` at start: `b29484f557f97273dff509d1d46449b2fd3f434a`;
- worktree at start: clean;
- pre-exit-commit HEAD: `b29484f557f97273dff509d1d46449b2fd3f434a`.

The implementation sequence is represented by these commits:

1. `1a33c9f` — Plan first Elementor vertical slice;
2. `379ba99` — Establish Elementor runtime prerequisite;
3. `03d97e4` — Add Biblio UI plugin foundation;
4. `da7f345` — Add Biblio UI asset bootstrap;
5. `54c5fd9` — Add Biblio UI REST transport;
6. `acbcce5` — Add Biblio UI library routing;
7. `08719f6` — Add Biblio UI overview component;
8. `f828da9` — Add Biblio UI detail component;
9. `8de3f6a` — Add Biblio UI start reading interaction;
10. `526a9e0` — Add Elementor library page shell;
11. `82b1f13` — Complete vertical slice 1a design and accessibility pass;
12. `b29484f` — Add vertical slice 1a Playwright fixtures and E2E.

## 2. Closed scope

The delivered slice is limited to:

`Mijn Bibliotheek → active Items → Item detail → Start Reading → authoritative detail reread`.

The production UI contains no search, filters, Archive, Collections,
Wishlist, Notes, Ratings/Reviews, Next Reading, Author/Series pages, Timeline,
statistics, goals, broad navigation redesign, JetEngine, JetSmartFilters or
JetFormBuilder application behavior. No new REST route was introduced for the
UI. The current cover/author/edition fields remain explicit `unknown` Core
projection values and the UI omits them rather than inventing placeholders.

## 3. Runtime

The accepted local runtime was:

| Component | Accepted value |
|---|---|
| WordPress | 7.0.2 |
| PHP | 8.3.31 |
| Composer | 2.10.2 |
| MariaDB | 10.11 |
| Node.js / npm | 24.13.0 / 11.6.2 |
| Biblio Core | 2.1.0, active |
| Biblio UI | 0.2.0, active |
| Elementor | 4.2.3, active |
| Elementor Pro | 4.2.2, active |
| Theme | Twenty Twenty-Five 1.5, active |
| Playwright | `@playwright/test` 1.62.1, exact lock |
| WordPress environment | `local` |
| DDEV project / primary URL | `biblio-v2` / `https://biblio-v2.ddev.site` |

## 4. Boundary and route contract

- Core owns authenticated actor, Library Context, membership/use access,
  Item visibility, presentation capabilities, reading source and Work
  derivation, lifecycle and conflict rules.
- The existing WordPress REST adapter parses and serializes the allowlisted
  transport contract and delegates every decision to named Core boundaries.
- Biblio UI owns only URL/browser state and presentation. It is vanilla ES
  modules with no framework, Web Components, bundle or production build step.
- Elementor owns only the ordinary Page layout containing the shortcode.
- UI visibility and client capabilities are never authorization.

The browser route is `/mijn-bibliotheek/`. Overview uses `library_id`; detail
uses `library_id` plus `item_id`. Back, forward, reload and direct detail links
retain those URL parameters. The UI calls only the existing routes:

- `GET /biblio/v1/me/libraries`;
- `GET /biblio/v1/libraries/{library_id}/items`;
- `GET /biblio/v1/libraries/{library_id}/items/{item_id}`;
- `POST /biblio/v1/libraries/{library_id}/items/{item_id}/reading-rounds`.

Cookie authentication uses the mount-provided WordPress `wp_rest` nonce in
`X-WP-Nonce`. The UI never retries an invalid-nonce POST.

## 5. Elementor Page and reproducibility

The published ordinary Page is exactly `/mijn-bibliotheek/`. Verification
proved one outer Elementor container, one Shortcode widget, exactly one
`[biblio_library_app]`, one rendered Biblio mount and Page-only Biblio assets.
The Page hides the theme Page title, leaving one visible view H1. No Loop Grid,
query, form or dynamic-visibility configuration implements the application.

The tracked Page/Kit artifact is
`config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip`, SHA-256
`4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a`.
It uses `https://biblio-export.invalid` and contains no DDEV URL, credential,
license key or licensed plugin package.

`./scripts/test-elementor-vertical-slice-1a.sh` installed a fresh WordPress
site in the allowlisted temporary database, imported `content,settings`,
regenerated Elementor CSS and re-proved the Page/Kit, mount, title CSS and
asset isolation. The exit audit corrected its cleanup order so the
container-side Mutagen copy is removed before the host-side temporary uploads
directory. A fresh rerun left neither the test database nor a
`step9-import-uploads.*` directory.

## 6. Gate results

| Gate | Fresh result |
|---|---|
| Biblio UI PHP syntax and isolated smoke | PASS |
| Biblio UI production JS syntax | PASS |
| Biblio UI JS tests | 63/63 PASS |
| Biblio UI targeted PHPStan | 0 errors |
| Core Composer metadata/platform requirements | PASS |
| Core PHP syntax | PASS |
| Core PHPStan | 0 errors |
| Core unit | 229 tests, 879 assertions, PASS |
| Core integration, including schema/migrations and REST | 180 tests, 1421 assertions, PASS |
| Core WordPress smoke | plugin active, class loaded, init hook 1, HTTP 200 |
| Working Page verifier and asset isolation | PASS |
| Clean Elementor import | PASS |
| Playwright | 5/5 PASS |
| Manifest JSON and Git whitespace | PASS |

The earlier step-11 report stated 1423 integration assertions. The fresh
unchanged pre-exit checkout reports 1421. No integration test was skipped and
step 12 changed no Core code or test; the current 180/1421 result is the
authoritative exit count.

## 7. Playwright, browser and accessibility evidence

The five Chromium cases prove:

1. overview → detail → one Start Reading POST → authoritative reread → reload;
2. a foreign Item returns the same non-enumerating 404 state and leaks no
   foreign title or Library identity;
3. a stale active-source submit returns 409, rereads and leaves one Round;
4. an invalid nonce returns 403, is not retried and creates no Round;
5. real-content responsive, keyboard, target-size and unknown-metadata
   acceptance at 1440×900, 768×1024 and 375×812.

An additional semantic browser pass at 1440×900 and 375×812 proved one visible
view H1, logical H1/H2/H3 hierarchy, an accessible active-books list, no
button-in-link nesting, no horizontal overflow, ≥44 px card/primary targets,
deep-link/reload/back/forward behavior, no fabricated cover or edition
sections, and non-enumerating foreign-Item error announcement. The native
dialog has labelled/described relationships, a labelled date field initialized
to the Amsterdam local day, initial field focus, live regions, cancel/return
focus and a full-width mobile bottom sheet. The Playwright keyboard case proves
Enter and Escape behavior.

## 8. Fixture and data safety

The formal fixture uses only `biblio_e2e_actor` and `biblio_e2e_other`, two
allowlisted Library IDs, four exact Work/Edition/Item sets and one conflict
Round. The users are subscribers and carry a fixture marker. An unmarked
username collision refuses deletion.

Negative checks proved refusal without `BIBLIO_E2E_ALLOW_FIXTURES=1`, with a
non-`local` WordPress environment, with another DDEV project and with another
primary host. Cleanup uses transactions and allowlisted `IN` values; it uses
no truncate, schema reset, prefix wildcard or broad user cleanup.

`setup → cleanup → setup → cleanup` passed, repeated cleanup was idempotent and
the final verify-clean count was zero for all fixture users and domain rows.
`biblio_dev` remained present. The count and SHA-256 fingerprint of non-E2E
users were identical before and after (`2` and
`8bc9e526a290ca813cea59be2a2ac8a3f48875fd4dbf3d09a8194c7a55a8fd4c`).

Credentials remain only in ignored `.local/e2e.env` with mode `0600`; no
password was printed or tracked. The ignored
`.local/fixture-source/data.zip` is not tracked and is not read by setup,
Playwright or runtime code. Only four non-private book titles were copied into
the self-contained fixture.

## 9. DDEV environment assessment

`.ddev/config.yaml` sets `WP_ENVIRONMENT_TYPE=local` only in the repository's
local DDEV web container. It is not a production or staging deployment
configuration. More importantly, `local` alone never authorizes fixture work:
the fixture also requires explicit opt-in, `IS_DDEV_PROJECT=true`, exact
project `biblio-v2`, and HTTPS host equality for WordPress home, site and
`DDEV_PRIMARY_URL`. Negative runtime tests proved each independent refusal.
The tracked setting is therefore correct and safe for this reproducible local
runtime.

## 10. Reproducibility and closed conditions

Biblio Core, Biblio UI, npm lockfile, exact Playwright version, Elementor
Page/Kit artifact, import verifier, E2E setup/cleanup scripts and commands are
tracked. `.local/`, credentials, the licensed Elementor Pro package and local
Biblio1 archive are ignored. A second developer needs the documented exact
Elementor packages, but no undocumented application state or production data.

Conditions:

- Elementor 4.2.3 and Elementor Pro 4.2.2 installed/active: **CLOSED**;
- clean Page/Kit export and isolated import: **CLOSED**;
- live overview/detail/Start Reading content: **CLOSED** by guarded fixtures
  and Playwright/browser acceptance.

Known non-blocking limitations are the current Core projection's intentionally
unknown author, cover and richer edition metadata, and known non-fatal
Elementor 4.2.3 CLI deprecation/warning output. Neither changes the slice
contract or requires a Core expansion.

## 11. Final decision

**GO.** All relevant code, security, tenant-isolation, mutation,
reconciliation, cleanup, responsive/accessibility, Page/Kit import and
repository-reproducibility gates are green. No security, integrity, hidden
state, Core or REST condition remains open. Step 12 adds this evidence and the
strict import-test cleanup correction only; it adds no product feature,
endpoint or business rule.
