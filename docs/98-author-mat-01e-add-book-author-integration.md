# AUTHOR-MAT-01E — Add Book Author integration

Date: 2026-09-14

Status: **GO / CLOSED**

Task severity: **High**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.24.0`

Biblio UI: `0.18.1` unchanged

## 1. Implementation audit

The audit found five effective Add Book identities: existing Edition, reviewed
provider candidate creating a Work+Edition, reviewed candidate resolving to an
existing Edition or ISBN race winner, manual new Work, and manual new Edition
under an explicit existing Work. Every write path delegates to
`AddLibraryItemService`; its participant runs after Item persistence but before
commit and receives the definitive Work/Edition/Item.

Open Library ISBN candidates already carried ordered Author names and optional
validated `/authors/OL…A` keys; Google Books already carried ordered Author
names. Their typed credits survived provider normalization but the Add Book
snapshot serialized only contributor display strings, losing identity, role
and position before commit. Manual `contributors` remained intentionally
untyped Edition evidence. No schema or provider request was needed.

## 2. Add Book integration point

The Add Book snapshot now round-trips the private typed credits. Older
unexpired snapshots without `author_credits` decode to an empty list. The
existing `AddBookCommitEvidenceWriter` participant invokes the one shared
`CanonicalAuthorMaterializer` with its definitive Work. No Author is created
during lookup or review and no candidate/temporary identity is materialized.

## 3. Strong contributor path

A valid Open Library Author key routes to
`StrongOpenLibraryAuthorCredit`. The existing 01B behavior creates or reuses a
resolved Author, immutable Author claim, source credit/evidence and ordered
WorkContributor. Strong identity is rejected if attached to another provider;
there is no fallback name reconciliation.

## 4. Name-only contributor path

Valid credits without a strong key route to `NameOnlyAuthorCredit`. Google
Books strings always take this path. The existing 01C behavior creates or
reuses only the exact source-scoped provisional Author and creates no provider
Author claim. Matching names on independent Works remain independent.

## 5. Role/order semantics

The participant passes through the already-normalized `author|co_author` role
and original positive provider-array position. Current Open Library and Google
author arrays assert `author` for every valid entry and do not invent a
primary/co-author distinction. Skipped invalid entries do not renumber later
positions. The retry identity remains provider + typed source entity + durable
provider record ID + original source slot together with the canonical credit
shape.

## 6. Transaction / rollback

Author materialization runs inside the same Add Library Item transaction as
Work/Edition/ISBN identity, Item, classification, Add Book provider/user
evidence and activity. A hard Author persistence failure rolls back the whole
attempt, including Work, Edition and Item. Shared semantic conflict outcomes
retain unresolved evidence without overwriting claims/edges or allocating an
orphan Author.

## 7. Retry / concurrency

`AddBookCommitService` recognizes the four existing typed Author race signals
and retries the complete Item-add operation exactly once. The retry uses the
same already-validated candidate and the same preallocated Work, Edition and
Item IDs. Persistent or unknown failures escape. A separate-process database
test proves two concurrent Add Book operations for different Works with one
strong Open Library Author converge to two complete Items, one Author/claim,
two credits and two edges.

## 8. Existing Work/Edition behavior

A reviewed candidate that resolves locally or loses an ISBN race materializes
against the winning Edition's real Work. Exact replay is idempotent. An
explicit existing-Edition selection without reviewed candidate evidence adds
the Item and leaves the Author graph untouched. Manual existing-Work/new-
Edition also has no typed Author evidence and remains unchanged.

## 9. Cross-consumer reuse

An integration fixture first materializes an Open Library Edition and Author
through generic bibliographic materialization, then adds that exact Edition
through Add Book. The same source credit, resolved Author, claim and edge are
reused; evidence observation history advances and identity is not downgraded.

## 10. Search consumption proof

After a new Add Book commit, the existing local Author search returns the
canonical Author and the existing local Author-to-Works provider returns the
committed Work. The proof performs no Search write.

## 11. Provider request structure

Add Book commit still reads only its server-side reviewed snapshot. No Author
Search, details, enrichment or other Open Library/Google request was added.

## 12. Manual path behavior

Manual Add Book still creates no canonical Author. Its current generic
`contributors` observation has neither a trustworthy Work-Author role nor a
strong/stable typed contributor authority. This is an intentional capability
boundary, not a regression.

## 13. Public API/UI compatibility

The public Add Book request, success response, REST parser/serializer and UI
are unchanged. Author creation remains internal. Existing reviewed-snapshot
REST coverage remains green and no browser/E2E run is required for this
backend-only transactional change.

## 14. Explicitly deferred

No manual Author field/picker, Author detail/profile, merge/reconciliation,
Librarian governance, provider enrichment, Search materialization, runtime
backfill, V1 migration or Edition contributor system is implemented.

## 15. Tests / quality gates

Targeted evidence before the final gate:

- complete unit suite: 654 tests, 2636 assertions, with 2 existing notices;
- Add Book Author integration: 6 tests, 61 assertions;
- Add Book snapshot roundtrip/backward compatibility: 2 tests, 16 assertions;
- existing Add Book reviewed-snapshot REST regression: 1 test, 15 assertions;
- generic materialization regression: 16 tests, 104 assertions;
- generic materialization concurrency regression: 4 tests, 31 assertions;
- targeted PHP syntax and production PHPStan: green.

The one canonical Core gate then passed in 346 seconds: Composer metadata and
platform requirements, syntax, PHPStan, 654 unit tests / 2636 assertions (the
same 2 notices), 498 integration tests / 5811 assertions, WordPress smoke,
manifest JSON and whitespace. The independent second review found no blocker.

## 16. Actual V1 data rule

No `/data/`, MIG-01 fixture source, DATA-01 case, historical export or current
V1 snapshot was read or used. Tests use isolated synthetic fixtures. No
current-runtime Author backfill or acceptance record was created. A final
read-only query found schema version 1024 and zero rows in the current runtime
Author, Author-credit/evidence and WorkContributor tables before this slice is
used by a real Add Book commit.

## 17. Schema/Core/UI versions

Product remains `v2.001`. Schema remains `1024`; the 01A persistence
foundation was sufficient. Biblio Core advances from `2.23.0` to `2.24.0`.
Biblio UI remains `0.18.1`.

## 18. Git

Start HEAD: `abb573b` on local `main`, initially 31 commits ahead of
`origin/main`. Closure uses exactly one local commit with message
`feat: materialize Authors during Add Book`. No push is authorized or
performed. Final HEAD and clean-tree evidence are reported after commit.
