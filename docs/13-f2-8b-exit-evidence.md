# F2.8b — Ratings & Reviews exit evidence

Date: 2026-08-23
Baseline: `716b0d1adee7b9fdf10281dc1d0c4375b2a77e95`
Verdict: **GO**

## Delivered contract

F2.8b implements the definitive F2.8a model without reopening product choices:

- separate `Rating`, `WrittenReview` and `ContributionPublication` aggregates;
- exact Rating values 1.0–5.0 in half-star steps, stored as integers 2–10;
- normalized plain-text Reviews of zero through 5,000 Unicode code points;
- immutable source owner and Work, with optional correctable own same-Work
  ReadingRound context;
- independent owner-scoped Rating/Review services and readmodels;
- Library-scoped publish, withdraw, republish, move and moderation lifecycles;
- exact personal and public Rating aggregates without stored averages;
- optimistic versions and server-side opaque IDs on all three aggregates;
- no F2.8 Library ActivityEvent and no Timeline implementation.

`ReviewContent` rejects invalid UTF-8 and NUL, normalizes CRLF/CR to LF and
counts with `mb_strlen(..., 'UTF-8')`. HTML remains literal; public output uses
safe escaping. Moderation reasons are non-empty plain text, remain private to
author/authorized moderator projections and respect the TEXT byte boundary.

All source access derives the actor from `AuthenticatedUser`; owner predicates
and same-owner/same-Work Round checks exist at application and persistence
boundaries. Library roles never grant access to private source data.

## ReadingRound integrity

Generated-key uniqueness enforces one unlinked Rating and Review per User +
Work. Separate keys enforce one of each per User + ReadingRound; the two types
may coexist and distinct rereads permit multiple Work contributions.

Historical Round hard-delete requires explicit delete or preserve-unlinked
choice for every linked type. Unlink is versioned. A one-unlinked conflict
rolls the whole transaction back. Round FKs are RESTRICT, and contribution
actions never delete Work, Round or unrelated reading data.

## Publication, moderation and projections

Publish locks the source, takes the shared Library mutation lock, verifies
active membership and active same-Library Work representation, then persists
one current target. Withdrawal retains history; republish refreshes publication
time; move withdraws old and activates eligible target atomically.

Membership loss leaves valid output visible. Public reads re-evaluate active
Work presence: loss suppresses output/average and renewed presence restores it
without rewriting Publication state.

Author and moderation states are independent. Library Owner or active Manager
with `contribution.moderate` may hide/remove with a reason; hidden is restorable
and removed is terminal for the source+Library history. Moderation has no
private source writer dependency.

Public Rating DTOs contain current display name, Rating and publication date.
Public Review DTOs add escaped text and only an independently visible associated
Rating with identical owner/Work/Round context. Round, reading-source, Item,
Notes, email and membership data are excluded.

Personal averages count every current own Rating. Public Library + Work
averages filter visible Publications and active Work presence, select
`rating.updated_at DESC, rating_id DESC` per User, sum original half-units and
round only final presentation half-up to one decimal.

## Schema 1005

The formal chain is now 1000→1001→1002→1003→1004→1005. Migration 1004→1005
creates, in dependency order:

1. `wp_biblio_ratings`;
2. `wp_biblio_reviews`;
3. `wp_biblio_contribution_publications`.

Health covers target tables, columns, generated expressions, indexes, FKs and
CHECKs. Fully absent targets install normally; a known healthy DDL prefix
resumes; unexpected order/shape fails closed. Version advances only after
healthy 1005. Existing 1004 data is preserved and no backfill is required.

## Acceptance matrix mapping

| F2.8a cases | Primary evidence |
| --- | --- |
| 1–10 domain/content | `AssessmentsTest` |
| 11–21 creation/Round/cardinality | application and schema 1005 tests |
| 22–30 ownership/source CAS/delete | application and repository tests |
| 31–46 publication/visibility | application and projection tests |
| 47–56 moderation/audit | authorization/application/boundary tests |
| 57–64 reads/averages | projection and query-service tests |
| 65–72 persistence/CAS/IDs | schema/persistence, independent-process race and ID-retry tests |
| 73–78 migration/regression/composition | migration/lifecycle/boundary suites |
| 79 canonical gate | exact results below |

Real MariaDB exercises relational invariants, Unicode/time/version roundtrips,
conditional writes, conflict translation, visibility, aggregates, schema
upgrade/retry/drift and cascade/restrict behavior. Dedicated independent-
process races prove one-winner unlinked/Round creation, divergent/equal source
CAS, cross-Library publish, publish/delete, withdraw/moderate and eligibility-
loss serialization. They also prove the source→Library→publication lock order;
all earlier independent-process Core concurrency regressions remain green.

## Canonical gate

`./scripts/test-biblio-core-all.sh` completed successfully:

- Composer metadata valid and locked platform requirements satisfied;
- all PHP syntax valid;
- PHPStan level 6: no errors;
- unit: **213 tests, 792 assertions**;
- MariaDB integration: **153 tests, 1,131 assertions**;
- WordPress smoke: plugin active, class loaded, init hook 1, HTTP 200;
- manifest valid and Git whitespace checks clean;
- duration: 53 seconds.

Expected negative lifecycle tests log intentional schema-health/legacy-version
failures while the suite remains green.

## Scope and closure

No UI/Elementor, REST endpoint, comments, likes, feed, notification,
recommendation, FULLTEXT/search, Timeline, statistics, goals, import/export or
external publication was added. Existing source data was not rewritten.

All definitive F2.8a contracts are implemented and the canonical gate is green.
F2.8 can therefore be closed with **GO**.
