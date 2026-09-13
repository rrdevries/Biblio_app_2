# AUTHOR-MAT-01C — Name-only provisional Author materialization

Date: 2026-09-13

Status: **GO / CLOSED**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.17.0`

Biblio UI: `0.17.0` unchanged

## 1. Implementation audit

AUTHOR-MAT-01B already supplied one provider-payload-free
`CanonicalAuthorMaterializer`, the exact 01A contributor-credit key,
deterministic evidence, ordered edge checks, typed conflicts and caller-owned
transaction semantics. 01C adds a second typed command to that boundary and
shares those primitives. It does not duplicate orchestration in a separate
service or alter strong Open Library identity semantics.

## 2. Name-only input contract

`NameOnlyAuthorCredit` contains exactly one provider-observed contributor
occurrence:

- canonical `WorkId`;
- typed `author|co_author` role;
- positive `ContributorPosition`;
- valid observed display name;
- validated provider key;
- typed source entity `work|edition`;
- stable source record ID; and
- observation time.

It has no provider Author ID, client-supplied canonical Author ID, fuzzy hint or
provider payload. Provider, source type, record and position derive the 01A
source identity inside Core.

## 3. Provisional Author creation

A missing exact credit and compatible open edge create one Author with
`identity_status=provisional` and `display_name_status=observed`. The canonical
display name uses only the existing UTF-8 whitespace trim/collapse. Case,
accents, punctuation and initials are not rewritten.

## 4. Exact credit reuse

Exact replay uses only the unique source/Work/role/position/whitespace-
normalized-name credit key. It reuses the stored credit, its linked Author and
the exact WorkContributor edge.

- A linked provisional Author remains provisional.
- A linked resolved Author remains resolved; no claim is removed or changed.

Strong-first/name-only replay therefore converges on the resolved Author. The
existing 01B exact-credit proof may still promote a name-only Author when a
later strong Open Library command proves that exact same credit; 01C itself
contains no promotion path.

## 5. Same-name independence

Independent source identities, Works or positions derive independent credit
keys. Name equality never searches for or reuses an Author. Same-name credits
on different Works create separate provisional Authors. Independent credits
that compete for one Work position fail structurally rather than merging.

## 6. Contributor credit and evidence

01C uses the schema-1024 credit/evidence repositories unchanged. Provider
evidence stores the validated source tuple, observed name, role, position and
observation history with `strong_provider_author_id=NULL`. Exact evidence
replay advances its count; a different source observation remains separate.
No raw external payload is stored.

## 7. WorkContributor

The exact edge preserves `WorkId`, `AuthorId`, role and positive source
position. Exact replay is success. No alphabetical order, shift, overwrite or
last-write-wins behavior exists.

An occupied position or the same Author at an incompatible role/position
returns typed `position_conflict`. A new independent losing credit is retained
as `unresolved` with `structural_ambiguity` and evidence, without allocating an
orphan Author.

## 8. Transactions and retries

The materializer opens no transaction and must run inside the caller-owned
transaction. Author, credit, evidence and edge therefore commit or roll back
together. Expected credit/edge uniqueness races escape for one complete
operation retry. Unknown persistence failures remain hard failures.

## 9. Concurrency evidence

Independent PHP processes and database connections prove:

- two identical name-only credits converge on one Author, credit and edge;
- equal names on independent Works create two Authors, credits and edges; and
- independent same-Work credits racing for one position leave one linked edge
  and one unresolved credit/evidence, with no loser Author.

## 10. No provider claim and no network

The name-only method never calls the Author provider-claim repository. A
rejecting test double makes any claim read/write fail, yet name-only
materialization succeeds. The boundary has no HTTP/provider client dependency;
no Open Library or Google Books request is possible.

## 11. Strong-path compatibility

The strong command continues to create/reuse resolved Authors and immutable
Open Library claims. Both commands share only the validated credit/evidence
and edge contract. Name-only neither intercepts strong identity nor supplies a
fallback. Existing safe exact-credit promotion remains a strong-path action.

## 12. Explicitly not implemented

- name-only promotion or reconciliation;
- Author merge, alias, tombstone or redirect;
- Open Library/Google Author lookup;
- Google Books consumer wiring;
- Add Book integration;
- generic bibliographic materialization integration;
- Search writes or reads;
- REST, UI or Librarian governance;
- V1 migration or runtime backfill.

## 13. Tests and quality gates

Targeted green evidence:

- materializer behavior and strong-path regression: 29 tests, 169 assertions;
- isolated materialization concurrency: 6 tests, 39 assertions; and
- PHPStan and targeted PHP syntax: green.

One initial broad run exposed a 15-second timeout in the new same-position test
barrier. The barrier was moved from the post-write edge insert to immediately
after the preflight edge read, ensuring both processes observe the same empty
state without holding unrelated writes while waiting. The targeted concurrency
suite and independent review then passed again.

The one definitive full Core gate is green in 329 seconds: Composer
metadata/platform checks, complete PHP syntax, PHPStan, 620 unit tests with
2444 assertions and the same two existing PHPUnit notices, 478 integration
tests with 5567 assertions, WordPress smoke, manifest JSON and Git whitespace.
Independent final re-review reports **NO BLOCKER** for identity, transaction,
concurrency, regression and scope boundaries.

## 14. Actual V1 data rule

No `/data/`, MIG-01 fixture source, DATA-01 case, historical export or current
V1 snapshot was read or used. Tests use isolated synthetic fixtures only. No
normal-runtime Author record was created. Read-only runtime verification reports
schema `1024` and zero Authors, WorkContributor edges, Author provider claims,
contributor credits and contributor-evidence rows.

## 15. Schema and versions

Product remains `v2.001`. Schema remains `1024`; no migration is required.
Biblio Core advances from `2.16.0` to `2.17.0`. Biblio UI remains `0.17.0`.

## 16. Git

Start HEAD: `d1aea9cefca6056d831937512ea91d79ce9d3166` on local `main`, initially
22 commits ahead of `origin/main`. Closure uses exactly one local commit. No
push is authorized or performed; exact final HEAD and clean-tree state are
reported in the completion response.
