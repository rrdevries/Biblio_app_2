# 74 — MH-SEARCH-01A Shared pageable Author/Work contract

Status: **GO / CLOSED** after the recorded gates and independent review.

Date: 2026-09-11

Task severity: **High**. Analysis, primary implementation and independent
review were separated. No V1 source, snapshot or count was needed.

## 1. Current contract audit

The production MH-DISC-01 route returns one fixed flat list of local/external
Work and Edition candidates. Wishlist consumes that exact strict shape. Add
Book retains its separate Library/ISBN boundary, while Add Book and Hierna
lezen can also use the separate pageable `/me/works` contract. These exact
decoders mean that an apparently additive field can be breaking.

The existing Work discovery establishes the useful pattern: normalized UTF-8
text, deterministic keyset order, `items + next_cursor`, query binding and an
explicit nullable end state. Its concrete cursor cannot be reused because it
is Work-only and has no result-group discriminator. Local canonical Author ID
plus display name and Work ID plus title/Authors/Series are reliable. Current
MH-DISC has no external Author entity; external Work identity is strong only
when it is a real provider Work key.

## 2. New typed contract

The additive `Application\Metadata\Search` boundary defines:

- `BibliographicTextSearchRequest`: one normalized general text query and
  independent optional Author/Work continuations;
- `BibliographicAuthorSearchResult`: stable typed Author reference,
  provider-neutral result kind, optional canonical Author ID and display name;
- `BibliographicWorkSearchResult`: stable typed Work reference, Work title,
  ordered Author presentations and reliable optional Series context;
- `BibliographicAuthorSearchPage` and `BibliographicWorkSearchPage`: separate
  `items` and nullable continuation groups; and
- `BibliographicTextSearchResult`: the two independent groups for one query.

The Work serializer contains no ISBN, publisher, publication date, binding,
Edition language or other concrete-publication field.

## 3. Pagination and cursors

`BibliographicSearchCursorCodec` produces a signed, versioned opaque envelope
bound to normalized query, exact `authors|works` group, result lane,
presentation order and stable result ID. The HMAC secret must contain at least
32 bytes. Malformed, tampered, wrong-query and wrong-group cursors fail closed.

Each page validates that its continuation equals the final returned keyset.
`next_cursor = null` explicitly means end of results. No total and no hidden
hard top-ten is represented by this contract.

## 4. Ordering and deduplication

The stable order tuple is:

```text
(local/external tier, presentation_order, strong result_id)
```

Local canonical results therefore precede external candidates. Provider
relevance may supply `presentation_order` inside the external lane, but it is
not confidence, selection, canonical status or a merge rule. Strong result ID
is the deterministic tie-breaker.

Canonical identity or the same provider-scoped entity identity may prevent a
duplicate. A proven provider-to-canonical mapping uses the canonical ID as the
stable key. Identical or similar title/name presentation alone remains two
results.

## 5. Provider capability model

Capability asymmetry is represented without a speculative universal adapter:

- `BibliographicAuthorSearchProvider` supports Author search;
- `BibliographicWorkSearchProvider` supports Work search.

A provider may implement one, both or neither. A Google Volume/publication key
does not satisfy the typed provider Work-reference contract. No Open Library,
Google Books or other adapter is implemented or changed.

## 6. Author to Works handoff

`BibliographicAuthorReference` retains either canonical `AuthorId` or a typed
provider-scoped Author identity. This is sufficient stable input for the later
Works-by-Author slice. No Works-by-Author query, endpoint or materialization is
part of MH-SEARCH-01A.

## 7. Work to Editions handoff

`BibliographicWorkReference` retains either canonical `WorkId` or a typed
provider-scoped Work identity. This is sufficient stable input for
MH-EDITION-01. No Edition retrieval, count, fan-out or language filter is part
of this slice.

## 8. Compatibility strategy

The old MH-DISC-01 response, snapshot/materialization flow and strict Wishlist
decoder remain unchanged. `/me/works`, its cursor and strict decoder remain
unchanged for Add Book, Hierna lezen and other current consumers. Add Book's
Library-authorized ISBN/manual/Item flow remains unchanged.

MH-SEARCH-01B will populate the parallel contract. Wishlist and Add Book move
only in their later explicit consumer cutover slices. The old contract can be
considered for retirement only after every current consumer has moved and its
own regression shield has been updated.

## 9. REST and application boundary

This slice ends at a typed application contract, strict request decoder,
serializer, cursor codec and provider-neutral capability ports. No REST route
or production composition is useful before a provider/orchestration
implementation exists, so none was added. The future reachable personal route
must use authenticated `/me` semantics and accept no target user or Library
identity.

## 10. Tests and quality gates

Synthetic contract tests cover Author and Work pages with continuation, every
empty-group combination, independent cursors, query/group binding, tamper
resistance, malformed/unknown/coerced input, deterministic local-first order,
strong-identity duplicates, text lookalikes, typed provider Work identity,
provider capability asymmetry and absence of Edition fields.

Recorded evidence:

- focused final contract suite: `20 tests`, `57 assertions`;
- full Core unit suite after the final test additions: `471 tests`, `1,995
  assertions`; two pre-existing PHPUnit notices remain visible;
- full Core integration suite: `410 tests`, `4,865 assertions`;
- full Core gate: Composer strict metadata/platform, PHP syntax, PHPStan,
  WordPress smoke, manifest JSON and whitespace all passed; the recorded full
  run completed in `544 seconds`;
- full Biblio UI smoke/contract suite: `251 passed`, including the unchanged
  strict MH-DISC, Wishlist, shared Work and Add Book contracts; and
- independent review first found and then verified fixes for direct typed-ISBN
  acceptance and mapped-provider duplicate overlap. Its final verdict was no
  blocker. A final targeted regression additionally proves that a validly
  signed cursor with an unknown result discriminator or field fails closed.

The first attempted parallel UI/Core invocation caused a DDEV Mutagen/start
collision and one concurrent review command interrupted an integration run
with exit `143`; neither produced a code-test failure. All reported evidence
above comes from later serial successful runs.

## 11. Outside scope

No provider HTTP implementation, Editions-by-Work, Works-by-Author, ISBN
contract replacement, REST endpoint, provider ranking engine, full-page UI,
Wishlist/Add Book consumer change, advanced/language search, performance work,
schema/persistence or V1/migration work was added.

## 12. Versions

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.6.0`;
- Biblio UI: `0.15.1`, unchanged.

## 13. Git

The slice is committed once locally after all gates and independent review.
The exact commit, final divergence, clean working tree and no-push status are
reported in the completion report.
