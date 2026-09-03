# 35 — Remaining Search metadata foundation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-03.

## 1. Scope and outcome

This High-severity slice closes Slice 2 from doc 33. It adds only the source
models and persistence required for canonical Search metadata: alternative
Work titles, Edition ISBN state, Library Item inventory number and ordered
omnibus/bundle Work containment. Search, filters, sort, REST and UI remain
unchanged.

## 2. Domain and ownership contract

- Alternative titles preserve entered text and use a case-folded,
  whitespace-collapsed key only for deterministic duplicate identity.
- ISBN-10 and ISBN-13 are normalized and checksum validated independently.
  Edition distinguishes identified ISBN, explicitly no ISBN and unknown.
- Inventory number is optional, Library-owned and unique per Library when
  present; identical numbers in different Libraries are valid.
- Containment relates a parent Work to ordered contained Works. Self-links,
  duplicate links, duplicate positions and cycles are invalid. Containment
  creates no virtual Edition or Item.
- Central bibliographic metadata has no Library/User ownership path. Inventory
  reads require authorized Library Context and a Library predicate.

No unsupported language/type taxonomy for alternative titles, ISBN-10/ISBN-13
equivalence rule, global ISBN uniqueness or graph completeness rule was
invented.

## 3. Persistence and migration

Schema 1009 advances through one forward migration to schema 1010. It adds
`biblio_work_alternate_titles` and `biblio_work_containments`, extends Edition
with nullable indexed ISBN columns and an explicit-no-ISBN flag, and extends
Item with a nullable binary inventory number and a unique
Library/inventory-number key.

Foreign keys are restrictive. Checks protect non-empty values, ISBN shape,
ISBN-state consistency, positive positions and non-self containment. The
migration accepts only fully absent or fully healthy component state, supports
known completed retries, fails unknown partial state before version bump and
preserves existing Work, Edition and Item rows.

## 4. Read and performance contract

Typed read ports accept batches for Work→alternative title, Work→Edition,
ISBN→Edition, parent→contained Work and contained→parent Work. Each adapter
path uses one query per batch and deterministic ordering. Inventory numbers use
one Library-scoped batch query after Library Context authorization. Supporting
indexes cover alternative-title, ISBN, reverse-containment, containment-order
and Library/inventory access paths.

## 5. Verification and review

Focused domain, application, persistence and schema tests prove normalization,
validation, tri-state ISBN semantics, tenant uniqueness/scope, relationship
integrity, cycle rejection, restrictive foreign keys, batch query counts,
migration health/retry/partial-state handling and data preservation.

Four explicit post-implementation passes cover requirements, architecture,
security and regressions because no separate reviewer agent is available.
The final Core gate passed in 108 seconds: 274/274 unit tests with 1,050
assertions and 270/270 MariaDB integration tests with 3,079 assertions, plus
PHP syntax, PHPStan, Composer metadata/platform, WordPress smoke, manifest and
whitespace validation.

## 6. Residual scope

Mijn Bibliotheek technical implementation readiness remains blocked on the
separately planned Location/archive, Collection and query-integration slices.
Representative 1,000/10,000-Item query plans belong to the later concrete
catalog-query shape; this foundation proves bounded batch access and required
indexes only.
