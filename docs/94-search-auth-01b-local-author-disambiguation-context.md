# SEARCH-AUTH-01B — Local Author disambiguation context

**Status:** GO / CLOSED

**Date:** 2026-09-13

**Product:** v2.001

**Schema:** 1024

**Biblio Core:** 2.21.0

**Biblio UI:** 0.17.0

## 1. Implementation audit

The 01A Author result already carried strong reference, display name, source
order, `exact|broader` match quality and name-group metadata. Canonical rows
originate in `WpdbBibliographicSearchProvider` and receive batch provider-claim
evidence in `BibliographicTextSearchService`. Existing WorkContributor reads
were batchable by Author, but the general Work repository could hydrate titles
only one at a time. The smallest safe addition was therefore a dedicated
batch disambiguation port with one aggregate WordPress implementation. No
schema or product decision was missing.

## 2. Local context model

Every Author result now owns one immutable internal
`BibliographicAuthorDisambiguation` with:

- nullable `representative_work_title`;
- nullable `linked_work_count`; and
- nullable `birth_year`.

Canonical Authors receive authoritative local count/title values and null birth
year. External candidates retain null for all three fields in 01B. The object
is application-internal and is not attached to mutable repository entities.

## 3. Zero, one and multiple Works

- zero linked Works: count `0`, representative title `null`;
- exactly one linked Work: count `1`, representative title is that Work's
  exact canonical title; and
- two or more linked Works: exact count, representative title `null`.

The typed local factory rejects a representative title unless the count is
exactly one and requires it when the count is one. No alphabetical, temporal,
positional or popularity-based representative is possible.

## 4. WorkContributor roles and scope

The persistence projection explicitly includes only `author` and `co_author`
edges and counts distinct canonical `work_id` values. The current table also
enforces one Author edge per Work and allows only those two Work-level roles.
Edition contributors, provider evidence, Wishlist state, Editions, Items,
holdings and Library membership do not enter the query.

The projection is platform-wide bibliographic context. It accepts Author IDs
only and has no Library Context or ownership parameter.

## 5. Batch projection and no N+1

`BibliographicAuthorDisambiguationLookup` accepts the canonical Author IDs on
the current application page. The WordPress adapter rejects more than ten IDs,
initializes every requested Author to count zero and skips SQL for an empty
batch.

For a non-empty batch it executes exactly one derived aggregate query. The
inner query filters `author|co_author`, counts distinct Work IDs and emits a
single Work ID only when the count is one. The outer join hydrates a canonical
title only for that single Work ID. Measured query cost is therefore one for
1–10 Authors, not proportional to the number of Authors. Together with the
existing page reads, a local Author page uses the Author search query, this one
context query and the existing one provider-claim batch query.

## 6. Same-name and governance-state behavior

Same-name canonical Authors retain separate strong references and independently
projected contexts. Synthetic `Peter King` Authors correctly receive `Work
Alpha` and `Work Beta`. Provisional and resolved Authors use the same query and
context rules; identity status is neither loaded into Search nor ranked.

## 7. Ranking, deduplication and pagination compatibility

Disambiguation is replaced after existing canonical claim evidence and is not
part of `sortKey()`, `sourceOrderKey()`, strong identity keys or selectors.
Canonical exact/broader and external exact/broader order, current mapped
suppression, source-progress cursor v2, page boundaries and zero-visible
continuation remain the 01A contracts.

## 8. Provider failure independence

Local context is projected before external composition. Open Library miss,
configuration failure, malformed response or technical failure cannot erase
it. The change adds no provider field normalization, HTTP request or provider
dependency; Open Library code is untouched.

## 9. Stephen King verification

The isolated integration scenario retains one canonical `Stephen King`, one
linked canonical Work `It`, one mapped Open Library duplicate and unmapped
external candidates. The retained canonical result projects exact match,
count `1`, representative title `It` and null birth year while the mapped row
stays suppressed and external context stays unknown.

The current development runtime was also checked read-only through the local
projection path. It returned exactly one canonical `Stephen King`
(`author-770605965f9c7985a2e26c35c684a3ef`) with exact match, linked Work count
`1`, representative title `It` and null birth year. No provider request or
runtime write was made for that check.

## 10. REST and UI compatibility

The public Author item remains exactly `result_id`, `result_kind`, nullable
`author_id`, `display_name` and `author_selector`. REST intentionally ignores
the new internal object. Biblio UI and its source labels remain unchanged;
there is no CSS, JavaScript, browser/E2E or visible `Auteur van ...` change.

## 11. Explicitly deferred

- SEARCH-AUTH-01C: external representative Work and conservative birth year;
- SEARCH-AUTH-UI-01: coordinated strict REST/UI cutover, copy, clustering,
  progressive presentation and source-label removal.

No Author detail, provider enrichment, external Work count, alternate names,
materialization, backfill or migration was added.

## 12. Tests and quality gates

Focused unit and persistence tests cover 0/1/2/many, canonical title selection,
both WorkContributor roles, same-name isolation, provisional/resolved parity,
mapped suppression, external null semantics, provider failure survival, stable
ordering and the measured one-query/empty-query boundary. Existing cursor,
pagination, mapping, REST and provider-request regressions remain in the
canonical Core suite.

Recorded evidence:

- unit suite during iteration: 634 tests, 2524 assertions, with the two
  established PHPUnit notices;
- focused `BibliographicSearchPersistenceTest`: 9 tests, 148 assertions;
- focused PHPStan: no errors across 845 files;
- current-runtime local Stephen King projection: exact/count `1`/`It`/null;
- final `./scripts/test-biblio-core-all.sh`: green in 361 seconds;
- final unit suite: 634 tests, 2524 assertions, with the same two notices;
- final integration suite: 492 tests, 5733 assertions;
- Composer metadata/platform, complete PHP syntax, PHPStan, WordPress smoke,
  manifest JSON and `git diff --check`: green; and
- independent read-only review of the final code/test/docs diff: `NO BLOCKERS`
  across all twelve requested focus points.

The attempted regex-filtered integration invocation did not reach PHPUnit
because DDEV interpreted regex parentheses as shell syntax; the safe class
filter above then passed. Likewise, the first inline WP-CLI runtime command did
not execute its query because shell `set -u` expanded `$wpdb`; the temporary
eval-file path above then completed read-only and was removed. Neither tooling
quoting failure changed code or runtime data.

## 13. Actual V1 data rule

No current or historical `/data/`, MIG-01 fixture source, DATA-01 snapshot,
record or count was read, copied, interpreted or mutated. Tests use isolated
synthetic V2 fixtures; runtime verification is read-only.

## 14. Versions and schema

Product remains v2.001, schema remains 1024, Biblio Core advances from 2.20.0
to 2.21.0 and Biblio UI remains 0.17.0. There is no migration.

## 15. Git

The slice is committed exactly once locally after final gates and independent
review. Exact start/final HEAD, divergence, worktree and no-push status are
reported in the completion report.
