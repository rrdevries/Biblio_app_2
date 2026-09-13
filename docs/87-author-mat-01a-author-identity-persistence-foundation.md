# AUTHOR-MAT-01A — Author identity and persistence foundation

Date: 2026-09-13

Status: **GO / CLOSED**

Product: `v2.001`

Schema: `1024`

Biblio Core: `2.15.0`

Biblio UI: `0.17.0`

## 1. Scope and authority

This slice implements only the persistence and identity foundation approved in
`docs/86-d-author-mat-01-canonical-author-materialization.md`. The repository
and that closed design remain authoritative. No product rule was added.

AUTHOR-MAT-01A includes Author state/version persistence, generic provider
Author claims, source-scoped contributor-credit identity, evidence storage,
schema migration/health and concurrency guarantees. It does not integrate a
materializer or consumer.

## 2. Pre-implementation audit

Before schema 1024, `biblio_authors` contained only opaque `author_id` and
non-unique `display_name`. `Author` exposed the same pair and its writable
repository used an unconditional replace-style save. The generic provider
identity table stored nine current Work/Edition mappings and had one immutable
provider/source key but no Author target. Existing WorkContributor persistence
already enforced unique `(work_id, author_id)` and
`(work_id, contributor_position)` edges for `author|co_author`.

The reusable foundation was MariaDB uniqueness/FKs/checks, restrictive central
relations, insert-plus-reread race handling, compare-and-swap versioning,
caller-owned `WpdbTransactionManager` transactions, strict migration
pre/postconditions and separate-process integration tests. The runtime started
with 0 Authors, 9 Works, 7 Editions, 4 Items, 9 provider identities and 0
WorkContributor edges.

## 3. Schema 1024

`biblio_authors` adds:

- `identity_status`: `provisional|resolved`, default `provisional`;
- `display_name_status`: `observed|librarian_confirmed`, default `observed`;
- positive `author_version`, default `1`.

There is deliberately no display-name or normalized-name uniqueness.

The provider identity table adds nullable `author_id`, a restrictive Author FK
and lookup index. Exactly one target must be present and the checked matrix is
closed to `author → author`, `work → work`, and `edition → work|edition`.

New `biblio_author_contributor_credits` and
`biblio_author_credit_evidence` tables carry restrictive central FKs, closed
status/source shapes, stable keys and lookup indexes. Evidence deletion or
credit deletion cannot cascade into an Author or Work.

## 4. Author domain and persistence

Typed identity state, display-name state and version values extend the Author
entity without materialization behavior. Inserts preserve the requested
provisional/resolved state. Replacement is explicit compare-and-swap: it
requires the stored version, increments by exactly one and reports a failed
match without overwriting. Ordinary writes cannot demote resolved identity or
a Librarian-confirmed display name.

## 5. Provider Author claims

The existing generic provider key remains the database uniqueness boundary.
The Author-specific port looks up and inserts only an `author → author` claim.
Exact replay returns the stored claim. A pre-existing different Author raises a
semantic conflict; an insert lost to concurrency raises a distinct retryable
race. Neither path silently reassigns or merges an Author.

All previous Work/Edition mapping shapes remain supported and unchanged.

## 6. Contributor-credit identity and evidence

The credit key follows the exact design formula: SHA-256 over the canonical
NUL-delimited Work ID, role, decimal positive position, whitespace-trimmed and
collapsed observed name, and Core-derived source-credit identity. Normalizing
does not lowercase, strip accents/punctuation, transliterate, expand initials
or perform fuzzy comparison.

Exact replay returns the existing immutable credit. Another stored shape under
that key is a semantic credit conflict; a concurrent unique-key loss is a
retryable race. Same names from another source, Work or position remain
independent. A credit can be linked to one provisional or resolved Author, or
be unresolved with an actual review reason; ordinary creation cannot reassign
it.

Evidence has a deterministic identity per exact provider, user-observation or
migration shape. Provider identity is re-derived from its stored tuple;
user/migration rows retain their opaque source observation ID and re-derive the
digest from it, so provenance stays directly traceable and internally
consistent. First observation creates one row. Exact replay atomically retains
first-seen time, advances last-seen time and increments the positive count.
Changed source values become separate evidence and never overwrite the credit
or Author.

## 7. Migration and runtime preservation

The linear 1023→1024 migration validates both old and new table shapes in full,
including types, collations, indexes, FKs and checks, and rejects an unknown
partial or drifted schema before any DDL. Before mutation it audits every
legacy provider mapping against the new closed matrix; invalid data halts
instead of being repaired or dropped. Known step-local shapes are retryable,
and the stored version advances only after the complete schema-health
postcondition.

Every existing Author keeps its ID/name and becomes `provisional`, `observed`,
version `1`. WorkContributor edges and Work/Edition claims are preserved. The
migration performs no name merge, provider inference, external lookup, Work
backfill or invented timestamp.

The normal runtime moved from 1023 to 1024 through the normal plugin lifecycle.
Before and after counts are identical: 0 Authors, 9 Works, 7 Editions, 4 Items,
9 provider identities and 0 WorkContributor edges. The two new tables contain
0 rows; no fixture Author was created. Two subsequent normal plugin boots both
reported 1024, all Author defaults are present and the provider-matrix audit
reports 0 invalid rows.

## 8. Concurrency and transaction boundary

Provider-claim and credit repositories rely on the unique database keys rather
than application-only check-then-insert correctness. Integration tests use
separate PHP processes and independent database connections coordinated at an
open caller-owned transaction boundary. They distinguish the losing retryable
insert race from the semantic conflict seen after reread.

Repositories do not open hidden transactions. Later Work + Author + claim +
credit + contributor-edge orchestration can therefore own one complete
transaction and retry it as specified by the canonical design.

## 9. Compatibility and explicit exclusions

The existing WorkContributor schema, source position and ordering are
unchanged. Add Book, `BibliographicMaterializationService`, bibliographic
Search, selected Author/Work reads, selectors, REST and UI are unchanged and do
not write Authors. No provider network request exists in this slice.

AUTHOR-MAT-01B strong Open Library materialization, 01C name-only provisional
materialization and subsequent consumer/promotion/governance slices remain
deferred. No current or historical V1 `/data/`, MIG-01 fixture, DATA-01 or
export was read or used.

## 10. Verification ledger

Targeted green evidence:

- consolidated Author/Add Book/Search unit contracts: 31 tests,
  117 assertions;
- schema/Author/provider/credit/evidence/concurrency post-review consolidation:
  12 tests, 57 assertions;
- existing Author/Series persistence: 4 tests, 44 assertions;
- schema-1023 discovery regression after current migration: 10 tests,
  59 assertions;
- production lifecycle: 9 tests, 108 assertions;
- Core schema migrator: 11 tests, 61 assertions;
- generic materialization and Search persistence regression: 13 tests,
  148 assertions.

The definitive canonical full Core gate passed in 347 seconds. It was repeated
only after a genuine independent-review blocker was fixed and the earlier
post-fix attempt exposed an already-applied local pre-commit CHECK shape plus
its fail-closed lifecycle cache; both were corrected before this final run:

- unit: 620 tests, 2444 assertions, two existing PHPUnit notices;
- integration: 443 tests, 5359 assertions;
- PHP syntax, PHPStan, Composer metadata/platform requirements, WordPress
  smoke, manifest JSON, working-tree fingerprint and Git whitespace: green.

The independent reviewer initially found old-shape preflight and non-provider
evidence-traceability blockers. After the fixes, the reviewer explicitly
rechecked all ten identity/security focus points and returned **NO BLOCKER**.
No browser/E2E or human QA was required because no frontend changed.
