# 39 — Existing-source filter/read foundation exit evidence

Status: **GO / CLOSED — READY FOR TYPED CATALOG QUERY COMPOSITION**

Date: 2026-09-04.

## 1. Scope and source inventory

This Medium-severity Slice 5 closes only the remaining existing-source read
gaps for the first `Mijn Bibliotheek` query wave. It adds no combined catalog
query, filter orchestrator, Search, Sort, cursor, REST or UI behavior.

| Source required by Slice 6 | Existing authority | Slice-5 result |
|---|---|---|
| active/archive Item, Work/Edition identity and title | catalog projection plus Item/archive repositories | already typed, scoped and batch-capable; unchanged |
| Authors/Co-authors and Series | central relationship query service | already bidirectionally batch-capable; unchanged |
| alternative titles, ISBN and containment | bibliographic metadata query service | already batch-capable; unchanged |
| inventory number | authorized Library Item metadata query service | already Library-scoped and batch-capable; unchanged |
| Location | authorized Location query service | already Library-scoped and batch-capable; unchanged |
| Collections and membership | authorized Collection query service | already Library-scoped, active-state-aware and batch-capable; unchanged |
| personal Work reading status | owned ReadingRounds | single-Work gap closed with one actor-scoped batch source |
| Boeksoort, Genre and Onderwerp | LibraryCatalogContext and separate term tables | option/batch gap closed with one authorized classification read boundary |
| personal Rating, Notes and Next Reading | existing owner-scoped services | not a first-wave filter or required result source; inspected and unchanged |

No new source or duplicated state is introduced. The current tables contain all
truth needed by this slice, so schema remains 1013.

## 2. Final read contracts

`LibraryClassificationQueryService` validates the current authenticated
Library Context before exposing separately typed active Boeksoort, Genre and
Onderwerp options or a batch of `LibraryCatalogSelection|null` values keyed by
requested Work ID. Options contain active terms only and are ordered by
normalized name plus stable ID. A missing or foreign-Library context returns
the same existing non-enumerating authorization failure before persistence.

The classification repository resolves any batch in three queries independent
of batch size: base contexts, Genre links and Onderwerp links. It retains linked
inactive terms in an existing context because ADR-006 preserves those links;
inactive terms are absent from new active option lists and therefore cannot
become normal selected filter values. Missing and foreign Work contexts remain
explicit `null`, and one-to-many links remain duplicate-free typed sets under
one Work key.

`GetPersonalWorkReadingStatusService::getMany()` resolves the actor server-side
and derives the existing `reading`, `read` or `not_read` Work status from all
owned ReadingRounds loaded in one query. Active takes precedence over completed;
stopped-only, unknown and actor-foreign history resolves to `not_read`. The
single-Work method now delegates to the same batch contract.

Both Work batches accept at most 100 typed Work IDs, matching the existing
maximum catalog page size. Empty input has an explicit empty result and performs
no source query. Result-map order follows first requested Work-key occurrence.

## 3. Filter, lifecycle and ownership semantics

At Slice 5 exit, Slice 6 remained responsible for OR within a filter group,
AND between groups, query composition, sorting and pagination. Slice 5 fixed
the source semantics it could consume:

- classification is always restricted by `library_id` and active options never
  include inactive terms from any Library;
- absent classification is `null`, not an accidental inner-join exclusion;
- Genre and Onderwerp stay independent unordered sets and never multiply Item
  result identity;
- personal reading status is Work-level, actor-owned and may be projected onto
  multiple physical Items without transferring ownership to a Library;
- archived Items remain outside normal active catalog source reads; Work-level
  personal history and Library classification are retained but cannot by
  themselves reintroduce an archived Item;
- active Collection filters continue to require active Collection, membership
  and Item state; historical membership is not an active match;
- Item archive/restore changes no ReadingRound, Rating, Review, Note, Goal or
  classification source data.

## 4. Query count, indexes and schema

The new classification batch uses three bounded `IN` queries and the personal
status batch uses one. No repository call occurs per Work or Item. Existing
schema-1013 indexes cover `(library_id,work_id)`, reverse Boeksoort/Genre/
Onderwerp lookups and `(user_id,work_id,round_outcome,...)`. Existing Author,
Series, Location and Collection batches remain unchanged. Query-specific
multi-source plans and 1k/10k optimization were assigned to Slice 6 after its
SQL shape existed.

Schema/migration result: **1013 → 1013; no migration and no data rewrite**.

## 5. Verification and review

Targeted unit verification covers authorization-before-read, actor isolation,
status precedence, empty/unknown results, bounded batches and production
surface. Targeted MariaDB verification covers tenant separation, active option
lifecycle, inactive-link retention, mixed known/missing/foreign batches,
constant query counts and the existing Archive/Collection/catalog/migration
regressions. The complete quality gate passed with 311 unit tests / 1,238
assertions and 292 MariaDB integration tests / 3,209 assertions, plus PHP
syntax, PHPStan, Composer metadata/platform requirements, WordPress smoke,
manifest JSON and `git diff --check`.

An explicit independent second pass covers architecture, security/privacy,
read correctness, N+1/cardinality, tests/regression and Product Guardian scope.
No Rating/Review, Note, Next Reading, Archive or Collection product semantics
are changed.

## 6. Downstream boundary

Slice 6 has now composed these sources into the immutable typed catalog query,
Search/ranking, first-wave filters, three sorts, active/archive result,
query-bound cursor and representative query-plan proof. Its evidence is in
`docs/41-typed-catalog-query-composition-exit-evidence.md`.

REST and UI remain Slice 7 work. Final downstream status:
**READY FOR CATALOG TRANSPORT / REST INTEGRATION**.
