# 34 — Central Author and Series Relationship Foundation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-03.

## 1. Scope and boundary

This High-severity slice closes Slice 1 from doc 33. It adds central Author and
Series identities, typed Work relationships, schema 1009 persistence, batch
repository reads and production composition wiring.

It adds no Search, filter, sort, cursor, REST, frontend, Elementor, management
UI, aliases, automatic merge, external bibliography or Series-completeness
behavior.

## 2. Domain contract

- `AuthorId` and `SeriesId` use the existing stable UTF-8 identifier contract.
- Author and Series display names are non-empty valid UTF-8 with a maximum of
  512 characters. Labels can change behind stable IDs; name equality never
  merges records.
- `ContributorRole` is closed to `author` and `co_author`.
- Each Work contributor has one positive explicit position. A Work aggregate
  rejects duplicate Author IDs and duplicate positions and sorts by position.
- No exactly-one-primary-Author rule is introduced: canonical sources establish
  role and ordering but not that cardinality.
- Work-Series membership is unique per Work + Series.
- `SeriesPosition` is either unknown (`NULL`) or a normalized non-negative
  decimal with at most 14 integer and 6 fractional digits. This preserves
  fractional numbering and sortability without inventing a value for unknown.

## 3. Persistence and migration

Schema 1008 advances through one forward migration to schema 1009. Four
central tables are added:

| Table | Purpose | Primary/unique integrity |
|---|---|---|
| `biblio_authors` | central Author identity/name | Author ID primary key |
| `biblio_work_contributors` | typed ordered Work↔Author relation | Work+Author primary key; Work+position unique |
| `biblio_series` | central Series identity/name | Series ID primary key |
| `biblio_work_series` | Work↔Series position | Work+Series primary key |

Both relation tables use `RESTRICT` foreign keys to central parents. Check
constraints reject empty names, unknown contributor roles and non-positive
contributor positions. Reverse Author lookup uses `(author_id, work_id)`;
Series order uses `(series_id, series_position, work_id)`. Primary keys support
Work-first lookup.

The migration validates healthy schema 1008 before change, recognizes only
healthy retryable schema-1009 additions, creates absent tables idempotently and
advances the version only after full schema-1009 health succeeds. Unknown
partial state fails before version bump and requires explicit recovery.

Current schema-1008 Work and Edition tables contain no Author/Series source
columns. No parallel canonical entities, import fields, seeds or fixtures were
found that required data migration. Upgrade evidence preserves an existing
Work row exactly; no fuzzy matching, speculative deduplication or destructive
reset occurs.

The pre-migration source classification was:

- **A — canonical/reusable:** central Work identity and Library-owned Item →
  Edition → Work links;
- **A — canonical but separate concern:** Library-local classifications, which
  are not Author or Series identity;
- **B — legacy/migration-relevant:** none found;
- **C — presentation-only:** existing empty Author/Series card/detail
  placeholders, which are not persistence sources;
- **D — not relevant:** imports, seeds and fixtures contained no authoritative
  Author/Series payload to transform.

## 4. Application and query paths

Typed Author and Series repository ports provide identity lookup and batched
relationship reads for Work→Authors, Author→Works, Work→Series and
Series→Works. Concrete wpdb adapters preserve contributor and Series ordering.
Each non-empty batch read executes one repository query, so the contract has no
inherent N+1 behavior.

`BibliographicRelationshipQueryService` is wired into the production
`CoreApplication`. Writable adapters remain composition details; this slice
adds no unrestricted management flow or transport endpoint.

## 5. Security boundary

Author and Series are platform-wide bibliographic metadata, not Library
authorization. The four new tables contain no Library, Item or User foreign
key. Relationship reads return typed central Work relationships only; they
cannot return Library Items. Existing Item reads still require server-derived
actor identity, explicit Library Context, active membership/authorization and
an Item reachable in that Library.

No endpoint, route registration or frontend call is added. Therefore a global
Author or Series ID cannot become a new public or cross-Library Item
enumeration path through this slice.

## 6. Test and review evidence

Focused evidence covers domain validation, role closure, deterministic order,
Core/database duplicates, known/unknown fractional Series positions, stable-ID
name correction, relationship round trips, one-query batch reads, restrictive
foreign keys, schema health, 1008 upgrade, retry and unknown partial failure.

Final evidence:

- focused Author/Series persistence, security and schema migration: 8 tests,
  59 assertions;
- migration-regression follow-up across schema 1004, 1007 and 1008: 11 tests,
  66 assertions;
- complete Core unit suite: 267 tests, 1,014 assertions;
- complete MariaDB integration suite: 261 tests, 3,030 assertions;
- PHP syntax, PHPStan, Composer metadata/platform requirements, WordPress
  smoke, manifest JSON and `git diff --check`: passed;
- complete Core quality gate: passed in 104 seconds.

Four explicit second-pass reviews were performed because no independent agent
tool was available: Biblio Architect, Biblio Security Reviewer, Biblio Test
Engineer and Biblio Product Guardian. Blockers and majors were resolved before
closure.

## 7. Deferred scope and next prerequisite

Overall catalog-query technical readiness remains blocked. The expected next
slice is **Remaining Search Metadata Foundation**: alternative titles, ISBN,
inventory identity and omnibus containment under a separately approved task.
Location/archive, Collections, existing-source predicates, complete query
execution and REST/UI activation remain later slices from doc 33.
