# AUTHOR-MAT-01D — Generic materialization Author integration

**Status:** GO / CLOSED

**Date:** 2026-09-13

**Product:** v2.001

**Schema:** 1024

**Biblio Core:** 2.18.0

**Biblio UI:** 0.17.0

## 1. Implementation audit

`BibliographicMaterializationService` resolves authentication, authorization,
the actor-scoped snapshot and the requested intent before entering its
caller-owned `TransactionManager`. Work/Edition materialization, provider
claims and evidence already ran inside that transaction. Provider adapters
retained contributor names but did not carry typed Author identity, role or
position into materialization. Open Library payloads already exposed Author
keys, while Google Books exposed ordered name strings only.

The smallest safe delta is an internal typed `BibliographicAuthorCredit`,
carried by candidates and snapshot persistence, plus one call from the existing
generic materialization service to the shared `CanonicalAuthorMaterializer`.
No public REST/materialization result contract changes.

## 2. Generic materialization integration point

Every successful generic Work path invokes the one shared Author materializer
after the final canonical Work is known and before evidence is recorded or a
result is returned. This includes a new Work, a reused Work, Work-only
materialization and an existing/new Edition path. There is no consumer-specific
Author implementation.

## 3. Strong contributor routing

A validated Open Library `/authors/OL...A` identity routes to the existing 01B
`StrongOpenLibraryAuthorCredit` path. It may create or reuse a resolved Author,
immutable provider Author claim, source-scoped contributor credit/evidence and
WorkContributor edge. A provider Work or Edition identity is never treated as
an Author identity.

## 4. Name-only contributor routing

A valid contributor without a strong Author identity routes to the existing
01C `NameOnlyAuthorCredit` path. Google Books author strings always use this
path. The exact source-scoped credit is the retry identity: there is no provider
Author claim and no global name lookup or name-based deduplication.

## 5. Contributor roles and order

Provider arrays explicitly defined as authors retain their original one-based
array position and use canonical role `author`; no synthetic primary/co-author
distinction is introduced. `co_author` remains available only for a source that
explicitly proves that role. Invalid optional entries are skipped without
renumbering later entries. No alphabetizing, retry renumbering or wider
contributor ontology is introduced.

The stable source-credit identity uses the provider candidate's validated Work
or Edition record identity together with Work, role, position and observed
name. Array position alone is not used as the source identity.

## 6. Transaction, conflicts and rollback

Work, Edition where applicable, Work/Edition provider claims, Authors, Author
claims, credits, evidence and WorkContributor edges now share the existing
outer materialization transaction. An unknown persistence failure rolls back
the complete operation.

Canonical semantic conflicts remain typed, evidence-preserving outcomes:
`identity_conflict` never reassigns a claim or credit; `position_conflict`
retains unresolved structural evidence without shifting/overwriting an edge or
allocating an orphan Author. The surrounding Work/Edition operation may commit
that safe unresolved evidence, as defined by the closed 01B/01C policy; a state
whose integrity cannot be guaranteed is a hard failure and rolls back.

## 7. Retry and concurrency behavior

The existing single full-operation retry now also recognizes the four typed
Author races: provider claim, contributor credit, contributor position and
identity promotion. It reruns the same complete candidate materialization in a
fresh transaction; it never retries only the Author substep against stale Work
assumptions. Unknown database errors are not retried.

Two-process integration evidence proves that two Works sharing one strong Open
Library Author converge to two Works, one Author, one provider Author claim,
two credits and two edges.

## 8. Cross-path behavior

An exact name-only credit can be promoted by later strong Open Library identity
using the existing 01B promotion rules. A later name-only replay of the same
strong credit reuses the resolved Author and cannot downgrade it. Both orders
remain idempotent and do not duplicate WorkContributor edges.

## 9. Provider request structure and soft incompleteness

Open Library Work Search adds `author_key` to the fields of its existing Search
request; it performs no second request. ISBN details reuse the existing
`authors[]` objects. Google Books reuses ordered strings from the existing
volume response. No Author Search, Author details, alternate-name lookup,
Google person lookup or other provider fan-out was added.

Missing, empty or malformed optional contributor entries create no Author
state, while otherwise valid Work/Edition materialization remains available.
A valid Open Library name paired with an invalid optional Author key safely
degrades to name-only evidence; no source evidence is fabricated.

## 10. Search consumption proof

Integration coverage materializes a Work and Authors exclusively through the
generic materialization service, then proves that the existing top-level local
Author search returns those Authors and selected Author-to-Works returns the
Work. Same-name independent Authors remain separate results. Search response
contracts remain unchanged and Search performs no write or materialization.

## 11. Add Book remains deferred

Production composition wires the Author materializer only into
`BibliographicMaterializationService`. Add Book constructors, services,
requests and result contracts are unchanged; its targeted regression suite is
green. Add Book may therefore still create a Work without canonical Authors
until AUTHOR-MAT-01E.

## 12. Compatibility and inherited consumers

- Existing Work/Edition identity resolution, reuse and result shapes remain
  unchanged.
- Wishlist already calls the shared generic materialization service and
  therefore inherits Author materialization without Wishlist-specific code or
  UI changes.
- Existing Search reads canonical Author/Work state naturally, without new
  Search wiring or write behavior.
- Snapshots written before 01D remain readable; an absent internal
  `author_credits` field decodes as no credits, never as inferred identity.
- Add Book remains deliberately unwired.

## 13. Explicitly not implemented

No Add Book integration, Search-triggered write, Wishlist/Search UI change,
REST expansion, Author lookup by name, Google Author authority, provider
enrichment, merge/reconciliation, Librarian governance, library-owned Author,
V1 migration, runtime backfill or Edition-contributor model was added.

## 14. Verification evidence

Targeted evidence during implementation:

- provider/discovery unit: 36 tests, 208 assertions;
- final provider unit subset: 28 tests, 169 assertions;
- post-review affected Open Library subset: 17 tests, 103 assertions;
- Add Book unit regression: 14 tests, 98 assertions;
- generic materialization integration: 16 tests, 104 assertions;
- materialization concurrency integration: 4 tests, 31 assertions;
- PHP syntax and PHPStan: green.

The one canonical full Core gate completed once and passed in 350 seconds:

- Composer/platform, PHP syntax and PHPStan: green;
- unit: 621 tests, 2464 assertions, 2 existing PHPUnit notices;
- integration: 485 tests, 5617 assertions;
- WordPress smoke: plugin active, class loaded, init hook 1, HTTP 200;
- manifest JSON and `git diff --check`: green.

No browser/E2E run was required because frontend behavior is unchanged. An
independent second review found no blocker against scope, architecture,
authorization, identity, transactions or regression risk.

## 15. Actual V1 and runtime data rule

No `/data/`, MIG-01 fixture source, DATA-01 or historical export was used.
Read-only runtime verification after implementation still reports 9 Works,
7 Editions, 4 Items, 0 Authors, 0 WorkContributor edges and 0 Author credits.
No current record was backfilled or otherwise mutated for 01D.

## 16. Versions and schema

Product remains v2.001, schema remains 1024 and Biblio UI remains 0.17.0.
Biblio Core is bumped from 2.17.0 to 2.18.0. The complete schema-1024 Author
persistence foundation was sufficient; no schema migration was added.

## 17. Handoff

AUTHOR-MAT-01D is closed. AUTHOR-MAT-01E may separately decide and implement
Add Book Author integration; it must not infer that integration from this
slice. Historical/current Work backfill also remains a separate, explicitly
authorized migration concern.
