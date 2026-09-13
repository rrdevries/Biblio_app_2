# SEARCH-RUNTIME-01-F1 — Case-insensitive local bibliographic matching

**Status:** GO / CLOSED

**Date:** 2026-09-13

**Product:** v2.001

**Schema:** 1024

**Biblio Core:** 2.19.0

**Biblio UI:** 0.17.0

## 1. Root cause

`BibliographicTextQuery` normalizes only surrounding/repeated whitespace and
preserves case. `wp_biblio_authors.display_name` uses `utf8mb4_bin`, while the
local provider compared each escaped token through direct `LIKE`. Top-level
Author search and the linked-Author `EXISTS` arm of Work search were therefore
case-sensitive before REST serialization.

Runtime inspection also proved that `wp_biblio_works.work_title` already uses
`utf8mb4_unicode_520_ci`. Work-title matching did not have this bug and its SQL
predicate remains unchanged.

## 2. Matching implementation

One private local-provider helper emits `LOWER(<Author field>) LIKE LOWER(%s)`.
It serves only `a.display_name` and `a_search.display_name`. Stored canonical
names are not rewritten. The existing prepared statements, `%s` parameters,
`wpdb::esc_like()`, `%token%` substring form, per-token `AND`, Work-title-or-
linked-Author `OR`, result ordering and offset pagination remain unchanged.

## 3. Behavior and intentionally unchanged semantics

Integration coverage proves that one stored `Stephen King` returns the same
canonical Author for original, lowercase, uppercase and mixed case. Lowercase
`stephen king` returns linked Work `It`; genuinely different text does not.
The result remains `local_canonical` with unchanged IDs and presentation.

Literal `%` and `_` remain escaped. `José Saramago` does not match `JOSE
SARAMAGO`, and `Flannery O'Connor` does not match `FLANNERY OCONNOR`: no accent
folding, punctuation folding, fuzzy matching or broader normalization was
introduced. Ranking, ordering, pagination, identity-only deduplication and
local-first service order remain unchanged.

## 4. Boundaries

There is no schema/collation migration, persistence rewrite, Author
materialization, provider mapping, REST, frontend, Open Library, Google Books,
provider-attempt, external-pagination, ranking or deduplication change.

## 5. Verification

Targeted integration during implementation:

- first red run: 4 expected failures against the case-sensitive predicates;
- final targeted run: 4 tests, 64 assertions, green;
- coverage includes case variants, Work-title and linked-Author matching,
  negative text, wildcard escaping, accent/punctuation boundaries, paging,
  deterministic identity/order, no duplicate Author and unchanged row counts
  for canonical, relation, provider-identity and discovery-snapshot tables.

The one final Core gate passed in 378 seconds:

- Composer/platform, PHP syntax and PHPStan: green;
- unit: 621 tests, 2464 assertions, with 2 existing PHPUnit notices;
- integration: 487 tests, 5650 assertions;
- WordPress smoke: plugin active, class loaded, init hook 1, HTTP 200;
- manifest JSON and `git diff --check`: green.

Current development runtime verification was read-only. `Stephen King`,
`stephen king` and `STEPHEN KING` each returned exactly
`author-770605965f9c7985a2e26c35c684a3ef` as `local_canonical`; lowercase
`stephen king` returned Work `work-18f8240d721ce67fae39a87e5a8a96e1`
(`It`). Row counts across Authors, Works, WorkContributors, bibliographic
provider identities and discovery snapshots/candidates were unchanged.

An independent final review found no scope, query-safety, semantics,
regression or documentation blocker.

## 6. Versions

Product remains v2.001, schema remains 1024 and Biblio UI remains 0.17.0.
Biblio Core is bumped from 2.18.0 to 2.19.0. No schema migration was added.
