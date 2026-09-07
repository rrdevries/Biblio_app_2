# 52 — ADD-UI-01 Add Book Wizard server-contract integration

Status: **TECHNICAL GO / HUMAN QA PENDING**

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

## 6. Human QA still required

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

## 7. Scope and schema

Schema remains `1017`. No migration, Core production code, Elementor page,
Location API, Book Detail edit mode, collector-field persistence/UI, Librarian
queue, provider rule, Work-match heuristic, classification taxonomy or push is
part of ADD-UI-01.
