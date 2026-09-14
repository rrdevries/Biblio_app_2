# ADD-AUTH-01A — Typed manual Author Core integration

Date: 2026-09-14

Status: **GO / CLOSED**

Task severity: **High**

Product: `v2.001`

Schema: `1024` unchanged

Biblio Core: `2.25.0`

Biblio UI: `0.18.1` unchanged

## 1. Pre-coding audit

The strict Add Book body contained `identifier`, `selection`,
`observed_fields`, `classification` and `item`. Manual selection either created
a new Work and Edition or attached a new Edition to an explicit existing Work.
Existing-Edition and local-first exact paths added only an Item; a late ISBN
claim race rolled back and reused the winning existing Edition.

`AddBookCommitService` already preallocated Item, Work and Edition IDs once and
retried the complete Item-add operation once for the four typed Author races.
The existing transaction participant received the definitive Work, Edition and
Item after identity resolution and before commit. Schema 1024 already provided
non-unique Authors, user-observation source/evidence, source-scoped credits and
ordered WorkContributor edges. No schema change was needed.

## 2. Request contract

The public request additively accepts optional top-level `authors`:

```json
{
  "authors": [
    {"display_name": "George C. Clark Jr."},
    {"display_name": "J. Bibb Cain"}
  ]
}
```

Omission and an empty list both mean no Authors. Rows accept no role, position,
Author/provider ID, identity status or selector. The success response remains
the exact existing six-field shape and exposes no Author graph identifiers.

## 3. Validation and normalization

REST requires a JSON list with at most 32 exact row objects and one string
`display_name` per row. Core collapses Unicode whitespace/separators, trims the
ends and drops empty rows. Every remaining name is valid UTF-8 with 1 through
512 Unicode characters. Case, punctuation, diacritics, initials and suffixes
are preserved. There is no person-name grammar, sorting or transliteration.

## 4. Attempt plan

After normalization and before the first transaction attempt,
`AddBookCommitService` creates one immutable `ManualAuthorAttemptPlan`. Every
row receives one opaque server-issued observation ID plus its normalized name,
role and source position. The same plan object is retained by the existing
transaction participant and reused unchanged by the one full-operation retry;
observation IDs are never regenerated inside an attempt.

## 5. New Work Author materialization

`ManualAuthorCredit` implements the shared name-only materialization contract
with schema-1024 user-observation evidence. The existing
`CanonicalAuthorMaterializer` creates one provisional/observed Author, linked
credit/evidence and WorkContributor edge. It performs no provider lookup,
external HTTP, Author-name query, global deduplication or manual-specific
identity algorithm.

## 6. Role and position semantics

Every manual row uses `ContributorRole::Author`. Core derives contiguous
one-based positions after blank rows are removed and preserves final input
order. It never infers `co_author` from position.

## 7. Existing identity behavior

The participant receives the intended new Work and Edition IDs and compares
both with its definitive transaction values. Manual materialization runs only
when both are equal and `existingEdition` is false. Explicit existing Work,
explicit existing Edition, local-first exact reuse and an ISBN racewinner
therefore never receive manual Author mutation, even when a syntactically valid
forward-compatible request contains `authors`. Existing Author edges remain
unchanged, including on an existing Work that starts with zero Authors.

Provider candidate requests do not build a manual plan. Their closed 01E
strong/name-only behavior remains unchanged.

## 8. Transaction, rollback, retry and idempotency

Manual Author, credit/evidence and WorkContributor writes run inside the same
Add Library Item transaction as Work, Edition, ISBN claim, Item,
classification, Edition evidence and activity. A hard Author failure rolls
back the complete operation. A valid manual credit that cannot materialize is
also hard and fails closed.

The existing four race signals retry only the complete operation, at most
once, with the same Item/Work/Edition IDs, candidate and manual attempt plan.
Exact replay of one plan reuses the same Author, credit, evidence identity and
edge; evidence observation history follows the existing canonical rules.

## 9. Same-name and contributor separation

The manual source identity is the opaque observation ID rather than the name.
Equal visible names within one Work and across independent Works remain
independent provisional Authors. `observed_fields.contributors` stays separate
untyped Edition evidence and is never parsed or promoted.

## 10. Search read proof

Integration coverage creates a manual Work and Authors exclusively through Add
Book, then proves that existing local Author Search returns the Author and the
existing selected Author-to-Works read returns that Work. Counts before and
after those reads prove Search performs no mutation.

## 11. Backward compatibility and authorization

Existing requests without `authors` decode and commit unchanged. Empty and
blank-only lists are soft absence. There is no new route or authorization
rule: the input inherits the enclosing private actor-and-Library-bound Add Book
authorization. The public response is unchanged.

## 12. Explicitly deferred

No repeatable Author UI, Author picker/autocomplete, provider person lookup,
manual resolution, merge, correction workflow, structured Edition
contributors, Search change, backfill or migration is included. Those remain
separate slices, with the UI specifically deferred to ADD-AUTH-01B.

## 13. Verification evidence

Targeted implementation evidence:

- manual input and Add Book service unit: 19 tests, 77 assertions;
- Add Book Author integration: 10 tests, 88 assertions;
- manual REST contract selection: 6 tests, 39 assertions;
- old Add Book request without `authors`: 1 test, 22 assertions;
- manual exact attempt replay: 1 test, 8 assertions;
- existing provider-materialization concurrency: 1 test, 12 assertions;
- production PHPStan: green.

The definitive post-fix complete Core gate passed: Composer metadata and
platform checks, all PHP syntax, PHPStan, 659 unit tests with 2,657 assertions,
507 integration tests with 5,877 assertions, WordPress smoke, manifest JSON and
Git whitespace. The first complete run exposed one order-dependent assertion
in the new same-name test; the test was corrected to assert per-Work positions
without sorting by opaque random Work IDs, then passed targeted and in the
complete rerun. The independent second review is recorded in the final local
commit report.

## 14. Actual V1 data rule

No `/data/`, MIG-01 fixture source, DATA-01 or historical/current V1 export was
read or used. Tests use isolated synthetic fixtures. No runtime Author was
backfilled or mutated outside the test database.

## 15. Git

Start HEAD was `9099b9a` on local `main`, 33 commits ahead of `origin/main`,
with a clean working tree. This slice uses exactly one local implementation
commit with message `feat: support typed manual Authors in Add Book`. No push
is authorized or performed.
