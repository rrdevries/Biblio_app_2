# SEARCH-AUTH-01A — Author ranking, mapped dedup and pagination

**Status:** GO / CLOSED

**Date:** 2026-09-13

**Product:** v2.001
**Schema:** 1024
**Biblio Core:** 2.20.0
**Biblio UI:** 0.17.0

## 1. Implementation audit

Before coding, the current result DTO, local/provider ordering, lane
composition, cursor v1, page size, Open Library limit/offset, mapping
persistence and selector evidence were audited. The decisive gaps were the
last-visible Author cursor, fixed provider limit, discarded early provider
pages and absence of Author batch mapping reads. No schema or product decision
was missing.

Canonical hydration is deliberately absent. Canonical traversal is complete
before external traversal, so a mapped canonical Author satisfying the local
predicate was already offered. If its canonical display name does not satisfy
that predicate, docs/92 requires omission rather than alias expansion.

## 2. Match-quality semantics

`BibliographicAuthorMatchQuality` is internal `exact|broader` presentation
metadata. Exact comparison uses `mb_convert_case(..., MB_CASE_FOLD, 'UTF-8')`
after trimming and collapsing Unicode separators/whitespace. It does not fold
punctuation, diacritics, initials, aliases or transliteration and never changes
stored names.

The internal `name_group_id` is `author-name-` plus the lowercase SHA-256 digest
of that same normalized display name. It is grouping metadata, not identity.

## 3. Ranking tiers

Application order is authoritative:

1. canonical exact;
2. canonical broader;
3. external exact;
4. external broader.

Canonical source order is exact class followed by the existing stable local
display-name and Author-ID order; application composition carries its source
position rather than applying a second Unicode collation. External provider
order remains intact inside each match tier. The
bounded fetched provider page is partitioned; Search never crawls later pages
to discover every possible exact match.

## 4. Strong mapped dedup

Only the current exact `(provider, author, provider record, canonical Author)`
claim suppresses an external row. Equal/case-folded names do not suppress or
merge anything. Two canonical namesakes and multiple provider Authors with the
same display name therefore remain separate strong identities.

A canonical result carries composite provider evidence only when its current
page batch returns exactly one supported claim. Existing selector encoding is
unchanged. Author-to-Works now re-reads that exact mapping at use time and
fails closed when it is missing, targets another Author or is no longer the
sole supported claim for that canonical Author.

## 5. Mapping reads

`BibliographicAuthorProviderIdentityLookup` provides two bounded reads:

- current provider Author IDs to canonical Author IDs for one external page;
- current canonical Author IDs to supported provider claims for one local page.

The WordPress implementation performs one `IN (...)` query for each required
page projection and rejects a batch above ten. There is no N+1, cache or
canonical hydration query.

## 6. Provider request capacity

The Author-only provider port accepts offset plus limit `1..10`. A mixed final
canonical page requests exactly `10 - canonical_count`; a full canonical page
skips the provider and hands off an external offset-zero cursor. Local pages
with continuation make no external Author request.

## 7. Source-progress cursor

Author cursors use signed payload version 2 with exact keys for normalized
query, `authors`, traversal phase, next source offset and fixed order contract.
The old Author v1 payload is rejected rather than reinterpreted. Work cursor v1
is unchanged.

The offset advances by source rows returned, not visible rows retained after
mapped suppression. REST and UI continue to receive only an opaque token.

## 8. Pagination edge cases

A provider page containing only mapped duplicates can return zero visible
items with a non-null continuation and a `candidates` provider attempt. The
next request starts after all consumed rows; it neither loops nor refetches
them. Replaying the same cursor against unchanged fixtures returns the same
page and continuation. Fetched valid unmapped rows always fit because provider
capacity never exceeds remaining application capacity.

## 9. Provider failure behavior

Canonical results survive Open Library miss, configuration, malformed or
technical failure. A normal miss remains successful and typed. Each external
Author page performs at most one Author Search call and no Author detail,
Works, Edition or other enrichment request.

## 10. REST compatibility

`POST /biblio/v1/me/bibliographic-searches` keeps the current Author item keys:
`result_id`, `result_kind`, nullable `author_id`, `display_name` and opaque
`author_selector`. `match_quality` and `name_group_id` remain internal. The
group envelopes, provider attempts and independent Author/Work cursors remain
transport-compatible; only newly issued Author cursor tokens use v2 semantics.

## 11. Explicitly deferred

- SEARCH-AUTH-01B: linked Work count and sole representative title;
- SEARCH-AUTH-01C: Open Library top Work and conservative birth year;
- SEARCH-AUTH-UI-01: coordinated REST fields, strict decoder, clustering,
  visibility limits, source-label removal and UI.

No frontend, CSS, source label, materialization or detail flow changed.

## 12. Tests and quality gates

Focused tests cover normalization boundaries, four tiers, same-name survival,
current/removed/different mappings, batching, composite revalidation, remaining
capacity, zero-visible continuation, source advance, replay, miss/failure,
cursor strictness and Open Library request structure. The isolated Stephen
King integration uses canonical `Stephen King -> It`, mapped OL19981A, one
unmapped exact namesake and broader external candidates without runtime writes.

Recorded evidence:

- focused Author-to-Works unit: 14 tests, 48 assertions;
- focused Search persistence: 8 tests, 106 assertions;
- focused REST fixture recheck: 1 test, 42 assertions;
- final `test-biblio-core-all.sh`: green in 391 seconds;
- unit: 632 tests, 2510 assertions, with the two established PHPUnit notices;
- integration: 491 tests, 5693 assertions;
- Composer metadata/platform, PHP syntax, PHPStan, WordPress smoke, manifest JSON
  and `git diff --check`: green.

The first broad run exposed one obsolete REST test fixture that constructed a
composite Author selector without persisting its required claim. The fixture
now uses the production claim repository; its focused recheck and the complete
final rerun are green. This was not a runtime contract relaxation.

## 13. Actual V1 data rule

No current or historical `/data/`, MIG-01 fixture source, DATA-01 snapshot,
record or count was read, copied, interpreted or mutated. Tests use synthetic,
deterministic fixtures only.

## 14. Versions

Product remains v2.001, schema remains 1024, Biblio Core advances from 2.19.0
to 2.20.0 and Biblio UI remains 0.17.0. No migration exists.

## 15. Git

The slice is committed exactly once locally after final gates and independent
review. The exact commit, HEADs, divergence, clean tree and no-push status are
reported in the completion report.
