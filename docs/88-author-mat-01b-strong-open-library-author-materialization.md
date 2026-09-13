# AUTHOR-MAT-01B — Strong Open Library Author materialization

Date: 2026-09-13

Status: **GO / CLOSED**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.16.0`

Biblio UI: `0.17.0` unchanged

## 1. Implementation audit

The pre-coding audit found that Author Search already normalizes a bare
`OL…A` to `/authors/OL…A` and carries it through typed provider identity, but
ISBN details discard Author keys and Work Search requests names only. The 01A
Author, provider-claim, contributor-credit/evidence and transaction ports were
ready. WorkContributor persistence had the correct two uniqueness boundaries,
while application orchestration still had to distinguish exact replay,
structural conflict and a concurrent insert race.

The implementation adds only the shared Core materialization boundary and its
typed input/result/ID support. It extends the existing credit repository with a
version-checked review-reason write needed to retain semantic conflict state.

## 2. Strong Open Library identity input

`StrongOpenLibraryAuthorCredit` requires an exact `WorkId`, typed
`author|co_author`, positive position, bounded observed display name,
`OpenLibraryAuthorId`, typed Open Library Work/Edition source record and
observation time. `OpenLibraryAuthorId` accepts `OL123A` or
`/authors/OL123A`, stores the canonical slash form and rejects Work keys,
Edition keys, malformed values, arbitrary strings and URLs.

There is no free-form provider choice, Author/name/title lookup or implicit
role inference.

## 3. Canonical Author materialization

With no claim or structural conflict, Core creates one opaque Author with
`identity_status=resolved` and `display_name_status=observed`. With an existing
claim it loads and reuses that Author. Display-name equality never participates
in identity selection. A later observed spelling is preserved as credit
evidence and does not replace the canonical name.

The exact already-linked credit is the only safe provisional-promotion path in
01B: the same Author is promoted monotonically with versioned replacement,
without changing its ID, display name or edge. No name/Work/position
similarity promotion exists.

## 4. Provider Author claim

The exact `(open_library, author, /authors/OL…A)` claim is immutable. Exact
replay reuses it. An exact credit that names another Author returns typed
`identity_conflict`, flags the credit when safe and retains the new strong
evidence; no claim or credit is reassigned.

Unique claim loss remains `AuthorProviderClaimRace`, so the complete owning
transaction rolls back before retry.

## 5. Contributor credit/evidence

The service derives the existing 01A source-scoped credit key from Work, role,
position, whitespace-only normalized observed name and Core-derived Open
Library source identity. Strong person identity does not replace this
source-occurrence identity. Exact evidence replay updates first/last/count;
different observed names are independent evidence/credit records. No raw
provider payload is stored.

## 6. WorkContributor

The edge remains exact Work + Author + `author|co_author` + positive source
position. Exact replay is reused. Source order is never alphabetized.
An occupied position, the same Author at another position or an incompatible
role is a typed `position_conflict`: an unresolved credit with
`structural_ambiguity` and evidence is retained, while no edge is overwritten
or shifted and no unnecessary new Author/claim is allocated.

## 7. Transactions

`CanonicalAuthorMaterializer` deliberately opens no hidden transaction. It
must execute in the enclosing caller-owned transaction from docs/86. All hard
failures therefore roll back Author, claim, credit, evidence and edge together.
The standalone integration harness uses `WpdbTransactionManager` around the
complete call.

## 8. Concurrency evidence

Separate PHP processes and independent database connections coordinate at the
claim or credit read boundary. The losing complete transaction retries once
from fresh state. Targeted evidence proves:

- concurrent same provider Author on one Work: one Author/claim/credit/edge;
- concurrent same exact credit: one credit/edge;
- concurrent same provider Author on two Works: one Author, two credits and
  two edges.

The injected hard-failure test proves no orphan Author, claim, credit, evidence
or edge survives rollback.

## 9. Failure taxonomy

Malformed or missing strong identity cannot construct the typed input.
Semantic identity and contributor-position conflicts are typed result statuses
so retained evidence can commit. Claim, credit, edge-position and promotion
races are distinct retryable exceptions for one complete outer retry. Unknown
persistence or transaction failures remain hard failures.

## 10. Explicitly not implemented

- name-only provisional Author materialization (01C);
- Google Books Author materialization;
- provider network lookup or enrichment;
- Open Library adapter/normalizer cutover;
- Add Book integration (01E);
- generic bibliographic materialization or Search consumption (01D);
- Search-triggered writes, REST, UI, Librarian governance, merge or V1
  migration.

## 11. Compatibility

Provider Work/Edition claim shapes remain unchanged. Add Book,
`BibliographicMaterializationService`, Search contracts and production
composition contain no reference to the new materializer. The existing 01A
credit/evidence behavior remains compatible; only an additive version-checked
review-reason repository operation was introduced.

## 12. Tests and quality gates

Targeted green evidence:

- strong materialization: 14 tests, 77 assertions;
- isolated materialization concurrency: 3 tests, 18 assertions;
- 01A Author persistence: 3 tests, 23 assertions;
- 01A Author concurrency: 3 tests, 12 assertions;
- generic materialization concurrency: 3 tests, 23 assertions;
- Search persistence: 2 tests, 32 assertions;
- complete unit suite: 620 tests, 2444 assertions, with the same two existing
  PHPUnit notices;
- PHP syntax and PHPStan: green;
- normal runtime: schema `1024`, with zero Authors, WorkContributor edges,
  Author provider claims, contributor credits and credit evidence before any
  consumer integration;
- one definitive full Core gate: green in 359 seconds, including Composer
  metadata/platform checks, PHP syntax, PHPStan, 620 unit tests with 2444
  assertions and the same two existing PHPUnit notices, 460 integration tests
  with 5453 assertions, WordPress smoke, manifest JSON and whitespace;
- independent re-review after the stale-read race correction: **NO BLOCKER**.

## 13. Actual V1 data rule

No `/data/`, MIG-01 fixture source, DATA-01 case, historical export or current
V1 snapshot was read or used. All writes occurred only in isolated deterministic
test fixtures. No runtime Author backfill was performed.

## 14. Versions

Product stays `v2.001`. Schema stays `1024`. Biblio Core advances from
`2.15.0` to `2.16.0`. Biblio UI stays `0.17.0`.

## 15. Git

Start HEAD: `2fc89f7c82cce6fb61f534c7766a01c2c1fc9de5` on local `main`, initially
21 commits ahead of `origin/main`. Closure uses the required single local
commit; its exact final HEAD and clean-tree status are recorded in the final
completion report. No push is authorized or performed.
