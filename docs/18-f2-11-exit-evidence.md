# 18 — F2.11 Catalog UI Read Models exit evidence

Status: **GO**

Date: 2026-08-23

Baseline: `bc4d072bee4370660e7c70ef08933737a3c08b67`, schema 1007.

Scope: A2 / `Catalog UI Read Models` from
`docs/16-phase-2-rest-gap-elementor-readiness-analysis.md`.

## Closed readiness gaps

F2.11 closes A2 without entering F2.12 or Elementor:

1. one bounded active-Library overview now projects stable UI-facing Items;
2. one scoped Itemdetail now composes Library, Item, Edition and Work identity;
3. personal reading status and a detail summary are derived from owned
   ReadingRounds rather than copied into catalog persistence;
4. server-derived view/start presentation capabilities accompany both reads;
5. stable title/Item cursor ordering, query-count and index-plan evidence
   prevent a direct or unbounded UI table query.

## Overview contract

`CatalogUiReadService::activeOverview()` takes an explicit Library ID, optional
typed continuation cursor and page size. Page size defaults to 24 and is
limited to 1–100. It returns F2.10 Library identity/context plus active Item,
Work and Edition IDs, reliable title, author/cover availability, physical form,
Library source label, derived reading status, active state and capabilities.

Ordering is Work title ascending and Item ID ascending. Multiple copies remain
separate. The cursor contains the last projected title and Item ID. Archive and
search/filter behavior are not added.

## Detail and metadata contract

`itemDetail()` returns the same stable identities and adds typed fields for
ISBN, language, publisher, publication date, Series, Location, Condition,
Acquisition and availability. These values are `unknown` because schema 1007
contains no reliable source. Author and cover are likewise unknown. They are
not empty, missing, not-applicable or fabricated. Physical-book form is known.

The explicit `known`, `missing`, `not_applicable` and `unknown` states preserve
those meanings for future compatible enrichment. Missing and cross-Library
Item IDs both yield `catalog_item_not_available` without enumeration.

## Context, privacy and capabilities

The service resolves the authenticated actor, validates the target Library
through F2.10 and passes only the validated actor/Library identities to its
private query port. Foreign/inactive context fails before catalog lookup.

View capability comes from current collection access. Start presentation
capability requires current direct Item use and no active ReadingRound on that
exact Item. It is informational only; F2.12/start mutation must re-authorize.

## Reading projection

Status follows the canonical user × Work rule: any active Round means
`reading`; otherwise any completed Round means `read`; otherwise `not_read`.
Stopped-only remains not-read, while stopped after any completion remains read.
Historical completion counts as completion. Detail supplies counts for active,
completed, stopped and historical completed Rounds.

## Query and schema decision

The wpdb adapter issues one joined projection query per overview/detail. It
starts with active Items scoped by `items_by_library`, joins Edition and Work,
and joins one actor-owned ReadingRound aggregate plus exact-Item active state.
The application adds one F2.10 context query. There is no per-Item query loop.

EXPLAIN coverage proves the Library index access; bounded pagination limits the
result. No new persistence fact or missing index was justified, so schema
remains 1007 and no migration 1008 or cache was added.

## Test evidence

Targeted unit and real-MariaDB tests cover:

- metadata-state and page-size invariants;
- active-only Library scope, empty Library, multiple copies and cursor pages;
- title/Item ordering and absence of foreign-Library Items;
- Work/Edition/Item identity and reliable title;
- never-read, active, stopped-only, completed and historical/mixed status;
- detail ReadingRound counts;
- direct versus view-only and exact-active-source capabilities;
- unknown/cross-Library Item non-enumeration and foreign context rejection;
- two-query application budget and `items_by_library` EXPLAIN plan;
- production composition boundary and all earlier regressions.

Canonical full-gate result:

- PHP syntax: passed;
- PHPStan level 6: passed, no errors;
- unit: 229 tests / 879 assertions;
- real-MariaDB integration: 174 tests / 1,317 assertions;
- total: 403 tests / 2,196 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- Composer metadata/platform, manifest JSON and Git diff checks: passed;
- full canonical gate: passed in 70 seconds.

## Remaining for F2.12

F2.12 must add the versioned WordPress REST/security boundary, nonce/auth,
typed request parsing, allowlist serialization, error mapping and route tests.
It may serialize these DTOs but must not replace their Core scope or capability
rules. Rich metadata/search/archive remain later B-slices.

## Exit verdict

**GO** — A2 / F2.11 is closed. F2.12 is the single remaining pre-Elementor
gate slice. No REST route, WordPress Ability, JSON mapping, Elementor, browser
JavaScript or future module was added.
