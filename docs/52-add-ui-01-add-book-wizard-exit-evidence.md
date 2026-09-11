# 52 — ADD-UI-01 Add Book Wizard server-contract integration

Status: **TECHNICAL FIX GO / PROVIDER CONFIG + HUMAN QA PENDING**

Date: 2026-09-07

Task severity: **High**

## 1. Outcome

The canonical Add Book Wizard now runs inside the existing
`/mijn-bibliotheek/` application mounted by `[biblio_library_app]`. The
overview shows `Boek toevoegen` only when the resolved Library presentation
contains `capabilities.add_catalog_item === true`; every read and commit still
uses the existing Core-backed REST contracts and server-side authorization.
No Elementor logic, second page, frontend framework or parallel mutation path
was added.

## 2. Implemented flow

One stateful guided flow covers:

- native camera ISBN scanning where the browser exposes `BarcodeDetector` and
  `getUserMedia`, with manual ISBN entry always present as the dependency-free
  fallback;
- ISBN-10/ISBN-13 normalization before lookup;
- `existing_edition`, including the explicit correction/evidence route and an
  extra-copy acknowledgement with current-Library Item context;
- `local_ambiguous`, with an explicit unranked choice and no duplicate Edition
  escape route;
- single and multiple reviewed candidates, with subtle difference marking and
  only physical Edition fields available for direct adjustment;
- metadata miss, provider failure/retry and no-ISBN manual entry;
- optional deliberate existing-Work search through the shared `/me/works`
  decoder, without automatic Work matching;
- server-provided Book Type, Genre and Subject choices, optional inventory
  number and no Location field because no safe Location option source exists;
- conditional summary, pending/error states, exactly one commit request and a
  calm success state; and
- expired-candidate recovery through a fresh lookup/review while retaining
  already entered Item/classification values.

The success action `Exemplaar verder beschrijven` is visibly disabled with an
explanation. The required Boekdetail edit/deep-link target does not yet exist,
so the UI does not invent one. `Bekijk boek` opens the existing Item detail and
`Nog een boek toevoegen` resets the same wizard.

## 3. Contract, security and data boundaries

Strict frontend decoders reject extra or malformed lookup, classification,
commit and Work-discovery fields. Commit payload construction allowlists only
the existing selection, physical Edition observations, Library-local
classification and supported Item inventory number.

The client treats Library ID, capability, Edition ID, Work ID, lookup ID and
candidate ID only as selectors. Core remains responsible for authentication,
explicit Library Context, `catalog.item_add`, local-first re-resolution,
snapshot ownership/expiry, Work validation and transactionality. UI visibility
does not grant authorization. No central Work/Edition confirmation or silent
mutation, provider fusion, collector data, Librarian UI, schema change or new
capability was introduced.

## 4. Interaction and accessibility

The implementation stays inside the existing semantic-token shell and provides
visible labels, textual feedback, a polite live region, focus on each new step,
disabled pending controls, keyboard-native forms/actions, minimum 44px controls,
responsive single-column recomposition and reduced-motion behavior. All API
content enters the DOM through `textContent`.

## 5. Verification

Verified in the final implementation tree:

- complete Biblio UI JavaScript suite: 202 tests, all passed;
- Biblio UI PHP syntax, isolated smoke and script-module/version registration:
  passed;
- focused ADD-UI-01 authenticated Chromium contract matrix: 1 test, passed;
- complete guarded Playwright suite: 51 tests, all passed;
- all five fixture guards failed closed as intended; double cleanup,
  `verify-clean` and the before/after non-fixture fingerprint passed with zero
  fixture residue;
- complete Core unit suite: 410 tests, 1,735 assertions, with the two existing
  non-failing PHPUnit notices;
- complete Core MariaDB integration suite: 339 tests, 4,126 assertions;
- complete Core PHP syntax, PHPStan level 6, Composer/platform, WordPress smoke,
  manifest JSON and Git whitespace gates: passed; and
- explicit independent second review against requirements, architecture,
  authorization/privacy and regressions: passed with no open code finding.

The tracked E2E fixture needed one compatibility correction: its existing
CAT-T1 helper call now supplies the already-required concrete Edition title.
This changes no production behavior or fixture intent.

## 6. QA-ADD-F1 correction

A real DDEV/WordPress composition probe with ISBNs `9780140328721`,
`9780306406157` and `9780061120084` returned the same exact attempt chain for
each lookup: Open Library `configuration_error/configuration`, followed by
Google Books `configuration_error/configuration`, with zero candidates and
final `provider_failure`. Neither required runtime value is defined or present
in the DDEV web environment:

- Open Library requires `BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL` so its requests use
  the required identified contact configuration;
- Google Books requires `GOOGLE_BOOKS_API_KEY` for the production adapter.

`ProductionComposition` wires both providers into the existing
first-sufficient orchestration independently. A sufficient Open Library result
returns without Google; when Open Library cannot produce a candidate, a
configured Google adapter may still do so. Both providers were disabled here
only because both values were absent. WordPress and DDEV logs contained no
additional provider error; the controlled attempt results above are the
sanitized failure evidence.

Renée must supply the real values outside Git before a successful live-provider
probe is possible. In this DDEV checkout, environment values belong in ignored
`.ddev/config.local.yaml`; the ignored `web/wp-config.php` custom-values block
must define the two WordPress constants from those environment values before
WordPress loads. No value is committed, logged or invented by this fix.

CONFIG-F1 supersedes only that operational step: current production composition
reads the same environment variables directly when the corresponding WordPress
constant is absent. The constant remains the backwards-compatible higher-
precedence source, but editing generated `web/wp-config.php` is no longer
required. See `docs/71-config-f1-durable-provider-configuration.md`.

The missing manual action had a separate cause. Core already returned HTTP 200
with `provider_failure`, `manual_available: true` and `retry_available: true`,
but the strict frontend decoder treated the role-aware `explicit_mappings`
object as a string list. Every real lookup response includes that contributor
map, so decoding failed and the wizard rendered its generic transport-error
state before `renderProviderFailure()` could run. The decoder now accepts and
freezes the existing map shape, including Core's existing empty-array encoding
for an empty map. No REST response or provider policy changed.

The authenticated browser regression now uses the real field-binding shape and
proves the exact route `provider_failure` → `Handmatig invoeren` → existing
manual Edition state with the normalized ISBN retained. Retry remains covered.
Biblio UI is versioned `0.4.1` so browsers request the corrected module.

Correction-specific final verification:

- the real three-ISBN composition probe and authenticated browser runtime probe
  produced the exact results above; the browser loaded
  `add-book-wizard.js?ver=0.4.1`;
- the complete Biblio UI smoke/JavaScript suite passed: 202 tests;
- focused Core lookup tests passed: 5 tests, 56 assertions;
- focused Add Book REST integration passed: 6 tests, 86 assertions;
- the focused authenticated Chromium matrix passed: 1 test;
- the complete Core quality gate passed, including PHPStan, syntax,
  Composer/platform, 410 unit tests with 1,735 assertions and two existing
  non-failing notices, 339 MariaDB integration tests with 4,125 assertions,
  WordPress smoke, manifest and whitespace checks; and
- the complete guarded Chromium suite passed on its unchanged verification
  rerun: 51 tests, all five fail-closed guards, double cleanup, zero residue and
  an unchanged non-fixture fingerprint. Its first full run had one unrelated
  timing failure in the existing Private Notes keyboard-focus case; no Notes or
  other out-of-scope code was changed, and the immediate full rerun passed.

## 7. Human QA still required

Before deployment, verify on the intended production browsers/devices:

1. sign in as an Owner and confirm `Boek toevoegen` appears only for a Library
   that exposes the add capability;
2. allow camera access on a phone, scan a real EAN-13 ISBN and confirm the
   stream stops after detection, fallback and cancellation;
3. deny camera access and test a browser without `BarcodeDetector`; confirm the
   normal ISBN field remains immediately usable, including a hardware scanner;
4. visually inspect existing, ambiguous, candidate, manual, Work-link,
   extra-copy, failure/retry, summary and success states on phone and desktop;
5. traverse the whole flow by keyboard and verify visible focus, announced
   status/error changes and 200% reflow; and
6. confirm `Bekijk boek` opens the created Item and the disabled
   `Exemplaar verder beschrijven` explanation is clear.

## 8. Scope and schema

Schema remains `1017`. No migration, Core production code, Elementor page,
Location API, Book Detail edit mode, collector-field persistence/UI, Librarian
queue, provider rule, Work-match heuristic, classification taxonomy or push is
part of ADD-UI-01.
