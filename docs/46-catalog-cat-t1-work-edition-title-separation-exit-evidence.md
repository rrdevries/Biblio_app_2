# 46 — Catalog CAT-T1 Work/Edition title separation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-06

Task severity: **High**

## 1. Outcome

CAT-T1 separates the concrete Edition title from the Work title. Edition owns
one required title. Work title state is explicitly `provisional` or
`librarian_confirmed`; new and migrated Works are provisional unless a
librarian explicitly confirms them through a future authorized command.

## 2. Schema and migration

Schema moves from 1015 to 1016. The migration adds:

- `editions.edition_title VARCHAR(512) NOT NULL` with a non-empty check;
- `works.work_title_status VARCHAR(32) NOT NULL DEFAULT 'provisional'` with a
  closed-vocabulary check.

For existing data, every Work title remains unchanged, every related Edition
receives an exact copy of that title and every Work is marked provisional. The
migration stages nullable columns before backfill, is retry-safe for absent and
known derived partial state, and fails before the version bump when partial
title/status data could not have been produced by its own backfill.

## 3. Runtime behavior

- every new Edition path requires and persists a concrete Edition title;
- new Work+Edition creation uses that title only as a provisional Work seed;
- existing Edition reuse mutates no title;
- Work repository hydration preserves both title states;
- overview/detail and composed catalog records display Edition title;
- catalog sort and cursor tie-breakers use Edition title;
- search matches both Edition and Work title;
- provider evidence cannot confirm or overwrite Work title.

## 4. Verification

Verified on 2026-09-06:

- PHP syntax: passed for Core source and tests;
- unit suite: 393 tests, 1,604 assertions; passed with the two existing
  non-failing PHPUnit notices;
- complete integration suite: 327 tests, 3,917 assertions; passed, including
  schema migration/retry/fail-closed cases and catalog performance plans;
- maximum-length non-ASCII identifiers and independent titles round-trip;
- Composer metadata/platform requirements, PHP syntax and PHPStan: passed;
- WordPress smoke: plugin active, class loaded, init hook `1`, HTTP `200`;
- manifest JSON and `git diff --check`: passed;
- full repository quality gate: passed in 158 seconds.

## 5. Explicit exclusions

No REST response/input change, UI, Elementor behavior, Add Book integration,
automatic Work lookup, provider selection/fusion, MH-B4 review binding, MH-B5
bypass, DATA-01 change or V1 migration is included.

## 6. Review status

The High task used a separate analysis pass and an independent final diff
review. Review findings strengthened the independent migration divergence and
default-drift checks, Edition title boundary tests, and Edition-based
sort/cursor pagination tests. The final review verdict is **GO**, with no
remaining P0/P1/P2/P3 findings.
