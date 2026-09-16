# MIG-02-CAT-F1 — Unknown ISBN plan support

Status: **GO / CLOSED**

Date: 2026-09-16

Task severity: **Medium**

Scope: small source-neutral CAT migration-contract extension only. No CURRENT
V1 catalog mapper, source-field interpretation, apply command, schema or UI is
included.

## 1. Decision inheritance and pre-coding audit

D-MIG-CAT-MAP-01 already closed the product meaning: an ISBN that is unknown or
not supplied is not evidence that a publication explicitly has no ISBN. The
typed migration plan therefore uses `EditionIsbnMetadata::unknown()` and must
not substitute `withoutIsbn()`.

Before production code changed, the audit confirmed:

- `CatalogEditionPlan` already carried `EditionIsbnMetadata`, but rejected the
  unknown state after normal `Edition` validation and serialized every
  claimless state as `without_isbn`;
- normal `Edition` and `WpdbEditionRepository` already supported unknown by
  storing both ISBN columns as `NULL` and `explicitly_no_isbn=0`;
- `CatalogEditionMigrationParticipant` planned a claim only for a canonical
  identity;
- `CatalogMigrationWriter::applyEdition()` was the sole CAT claim path and
  also skipped that path when no canonical identity existed; and
- schema 1026 already contained the complete tri-state persistence contract,
  so no schema 1027 was necessary.

## 2. ISBN-state contract

The CAT plan now distinguishes exactly:

| State | Typed value | Canonical payload | ISBN claim | `explicitly_no_isbn` |
|---|---|---|---:|---:|
| Known ISBN | `identified(...)` | `canonical` | one create/reuse path | `0` |
| Explicit no ISBN | `withoutIsbn()` | `without_isbn` | none | `1` |
| Unknown ISBN | `unknown()` | `unknown` | none | `0` |

The existing value object remains the single source of truth. No `has_isbn`,
`isbn_unknown` or other parallel plan flags were added. Invalid/mismatched ISBN
metadata remains rejected by the existing domain constructor.

## 3. CatalogEditionPlan and planning behavior

The CAT-only guard that required either a canonical identity or explicit
no-ISBN was removed. Normal Edition title and metadata validation remains.
`canonicalPayload()` now records the truthful tri-state value, which keeps
source hashing and divergent replay sensitive to a state transition.

Unknown planning retains the exact `catalog_work` dependency and the ordinary
`create_or_reuse_edition` operation. It emits no
`claim_or_reuse_canonical_isbn` operation and no no-ISBN operation or flag.
Repeated synthetic dry-runs produce identical artifacts and write no Core or
MIG-FND row.

## 4. Apply, claims and persistence

The writer needed no new branch. With unknown metadata:

- no canonical identity is resolved or claimed;
- a new Edition receives the planned Work, title and unknown metadata;
- ordinary persistence writes `isbn_10=NULL`, `isbn_13=NULL` and
  `explicitly_no_isbn=0`;
- exact mapped or explicitly approved target reuse still requires compatible
  Work and ISBN state; and
- a later Item resolves and uses the committed Edition mapping normally.

## 5. Replay and identity

Unknown ISBN is not an identity or lookup key. Two separate source Edition IDs
with equal title and Work create separate Editions. No title, Work-title,
metadata-similarity or fuzzy target lookup was added.

Exact source replay reuses the prior Edition mapping. Because the canonical
payload includes `unknown`, changing the same source Edition to explicit
no-ISBN or a canonical ISBN is divergent replay and fails closed. No automatic
upgrade, downgrade or claim mutation occurs.

## 6. Regression and explicit exclusions

Known ISBN still uses the existing resolver and unique claim boundary.
Explicit no-ISBN remains claimless and persists `explicitly_no_isbn=1`. CAT
transaction ownership, Work dependency, Item dependency, mapping dispositions
and reconciliation inputs are unchanged.

This slice does not implement blank CURRENT source ISBN to unknown, repeated-
ISBN representative/alias planning, containment, variants, classification,
Item-local mapping, Authors, Series, source apply/import or a new target reuse
heuristic. Those remain separate authorizations.

## 7. Verification

Final validation evidence:

- focused CAT plan unit tests: 2 tests / 8 assertions;
- focused CAT apply/replay integration: 8 tests / 61 assertions;
- focused CAT dry-run integration: 1 test / 14 assertions;
- complete unit suite: 715 tests / 2,853 assertions, with the two existing
  PHPUnit notices and no failure;
- complete integration suite: 564 tests / 6,446 assertions;
- Composer metadata/platform, PHP syntax, PHPStan, WordPress smoke, manifest
  JSON and Git whitespace: green;
- full Biblio Core quality gate: green in 450 seconds;
- independent second review: no blocking finding after rechecking the complete
  diff against the decision, architecture, identity/replay boundary, security,
  scope exclusions and regression risks.

No browser/E2E test is applicable because REST and UI are unchanged. Tests use
synthetic plans only; no CURRENT source mapping or real source record is used.

## 8. Versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.35.0 -> 2.36.0`.
- Biblio UI: `0.20.0` unchanged.

## 9. Git

Exactly one local implementation commit is created after all gates and the
independent second review. Nothing is pushed to `origin/main`.
