# SEARCH-AUTH-01C — External Author disambiguation context

**Status:** GO / CLOSED

**Date:** 2026-09-14

**Product:** v2.001

**Schema:** 1024

**Biblio Core:** 2.22.0

**Biblio UI:** 0.17.0

## 1. Implementation audit

The existing Open Library Author request used only `q`, bounded `limit` and
`offset`; it had no explicit response-field selection. The response parser
accepted `numFound|num_found`, `start` and bounded `docs`, and required only a
strong Author `key` plus valid `name` per row. Extra document properties were
decoded but ignored. Consequently `top_work` and `birth_date`, when returned,
were dropped and were not represented in the existing all-null external
`BibliographicAuthorDisambiguation`.

The smallest provider-neutral delta was one external factory on that existing
value object, two field-local normalization helpers in the Open Library
adapter, and passing the resulting object into the existing Author result.
There is no second DTO, service orchestration change or public contract delta.

## 2. Open Library request fields

The existing `/search/authors.json` call now selects exactly:

- `key`;
- `name`;
- `top_work`; and
- `birth_date`.

Its existing `q`, `limit` and `offset` parameters remain unchanged. Provider
`work_count`, alternate names, biography, remote IDs, personal name and death
date are not requested.

## 3. External context model

Each valid external Author result receives the existing internal
`BibliographicAuthorDisambiguation` with:

- nullable provider-neutral `representative_work_title`;
- `linked_work_count = null`; and
- nullable integer `birth_year`.

These values are presentation context only. They are not identity, ranking,
deduplication, canonical linkage or selector evidence.

## 4. Representative Work parsing

Only a string `top_work` is considered. Ordinary surrounding whitespace is
trimmed. A non-empty, valid UTF-8 value of at most 512 characters is retained
exactly; missing, null, empty, whitespace-only, non-string, malformed UTF-8 or
overlong input becomes null. The adapter does not title-case, rewrite
punctuation, validate the Work against Biblio, materialize it or fetch details.

## 5. Birth-year parsing

`birth_date` is free-form provider text, so 01C deliberately does not add a
general date parser. It accepts a string only when it contains one unique,
standalone four-digit year from 1000 through the current UTC calendar year.
This covers a plain year and clear full-date text such as `September 21, 1947`
or `21 September 1947`.

Missing, empty, non-string, malformed or overlong values return null. Multiple
conflicting years, an open year range, explicit uncertainty markers such as
`circa`, `ca`, `c.`, `approximately`, `about`, `before`, `after`, `between`,
`possibly`, `probably`, `?` or `~`, and years outside the fixed lower/current
year bounds also return null. No death year or life-date range exists.

## 6. Same-name external behavior

Separate strong provider Author identities with the same display name remain
separate results. Synthetic same-name `Stephen King` candidates retain their
own Work-title/year pairs (`Work A`/1947 and `Work B`/1962); context equality or
difference never collapses them.

## 7. Ranking, deduplication and pagination compatibility

Match quality still derives only from display name and query normalization.
Disambiguation is absent from `sortKey()`, `sourceOrderKey()`, name-group IDs,
strong references and cursors. The four 01A tiers, provider order, bounded
capacity, consumed-source offset and continuation semantics are unchanged.

Mapped external duplicates remain suppressed by exact current provider mapping
only. Their external context is discarded with the suppressed row and is never
merged onto the retained canonical Author.

## 8. Request structure and no fan-out

One Author provider page still executes exactly one Author Search request. The
field selection is part of that request and optional context is parsed inside
the existing document loop. There is no Author detail, Work detail,
Works-by-Author, lookup-by-ID or enrichment request. Limit and offset behavior
are unchanged.

## 9. Local 01B compatibility

Local zero/one/two-or-more Work semantics are untouched. Canonical Authors keep
their authoritative local count and sole canonical title only at count one;
their birth year remains null. Provider miss/failure cannot erase that local
projection, and mappings do not copy provider context into it.

## 10. REST and UI compatibility

The public Author item remains exactly `result_id`, `result_kind`, nullable
`author_id`, `display_name` and opaque `author_selector`. Match quality,
name-group and all three disambiguation values remain internal. No Biblio UI,
JavaScript, CSS, copy, source label, browser test or visual acceptance surface
changed.

## 11. Explicitly deferred

- SEARCH-AUTH-UI-01 atomic strict REST/UI cutover;
- visible `Auteur van ...` and birth-year context;
- source-label removal;
- visible limiting, clustering and progressive disclosure; and
- Author governance, detail, enrichment, merge/split and materialization.

Provider Work count, alternate-name matching/display, death year, life-date
strings, provider detail requests and local enrichment from mappings are
explicitly not implemented.

## 12. Tests and quality gates

Focused Open Library and application tests cover valid/missing/malformed Work
context; plain and clear full-date years; lower/current boundaries; missing,
malformed, conflicting, impossible, uncertain and non-string dates; external
null count; same-name isolation; mapped suppression; unchanged ordering; exact
request fields; bounded limit/offset; and one-request/no-Works-path behavior.

Recorded evidence:

- focused provider/application regression: 44 tests, 209 assertions;
- final `./scripts/test-biblio-core-all.sh`: green in 383 seconds;
- unit: 648 tests, 2606 assertions, with the two established PHPUnit notices;
- integration: 492 tests, 5733 assertions;
- Composer metadata/platform, complete PHP syntax, PHPStan, WordPress smoke,
  manifest JSON and `git diff --check`: green; and
- independent second review: no blockers across all twelve requested focus
  points.

## 13. Actual V1 data rule

No current or historical `/data/`, MIG-01 fixture source, DATA-01 snapshot,
record or count was read, copied, interpreted or mutated. Tests use only small,
deterministic provider/application fixtures. No runtime data changed.

## 14. Versions and schema

Product remains v2.001, schema remains 1024, Biblio Core advances from 2.21.0
to 2.22.0 and Biblio UI remains 0.17.0. There is no migration.
