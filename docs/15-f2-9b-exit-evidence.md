# 15 — F2.9b Hierna lezen exit evidence

> **SUPERSEDED FOR CURRENT NEXT READING CONTRACT.** Dit document blijft het
> historische, destijds geldige exitbewijs voor de toenmalige F2.9b-scope. Het
> oude immutable targetmodel, duplicateverbod en ontbreken van automatische
> ReadingRound-consumptie zijn niet langer actueel. De huidige canonieke
> waarheid en het correctiebewijs staan in
> `docs/28-next-reading-contract-correction.md` en
> `docs/decisions/ADR-008-next-reading-intent-model-and-transactional-consumption.md`.

Date: 2026-08-23
Baseline: `ef08f88fd835df47bf4cdf771908801d32964dc8`
Verdict: **GO**

## Delivered contract

F2.9b implements the definitive F2.9a contract as one private, platform-wide,
fully manual ordered list per authenticated User. Core supports exactly three
closed targets:

1. Work;
2. Library Item;
3. owner-scoped ExternalLoan.

Each entry has a server-issued opaque ID, immutable owner, Work, target type,
source snapshot and creation time. Only position changes through the list
aggregate. There is deliberately no retarget service: replacing a target is
remove plus add.

Item add requires current same-Library collection-view authorization and
derives Work through Item→Edition. It does not require direct use access.
ExternalLoan add uses an owner predicate and derives Work from the loan. No
command accepts UserId, caller-supplied entry ID or caller-supplied source/Work
combination. Library roles never transfer list or loan ownership.

Generated target keys plus application checks enforce one Work target, one
Item target and one ExternalLoan target per User. A Work target and one or more
different concrete targets of the same Work may coexist. There is no count
check, capacity field or functional maximum.

## Snapshot and source lifecycle

Concrete targets store only immutable Work, target type, original source ID
and, for Item, original Library ID. Full source records, availability and
labels are not duplicated. Separate live Item/ExternalLoan FKs are nullable
and use `ON DELETE SET NULL`.

Legitimate source deletion therefore preserves entry, snapshot, position and
list version. It neither converts the target to Work nor relinks a later record
that happens to reuse the technical source ID. Reads expose `live`,
`unavailable`, `inaccessible` or `missing` without filtering or mutating the
list. Current Core source schemas are active-only; later state expansion can
change the informational status resolver without changing target eligibility
or list persistence.

## Ordering and concurrency

`NextReadingList` is the collection aggregate. Positions are contiguous
positive integers `1..N`; append writes `N+1`, remove hard-deletes only the
entry and compacts, and reorder accepts the complete exact owner set.

One persistent owner state row supplies a positive `list_version` and the
empty-list mutex. A user without a row reads virtual version 1. The first real
mutation atomically provisions version 1 and commits version 2. Every later
real collection mutation increments once. Identical current or stale reorder
is a semantic no-op; divergent stale intent returns typed current owner state.

Every mutation uses one MariaDB transaction. `FOR UPDATE` serializes the owner
state; source rows are locked and revalidated immediately before concrete
target insert. Two-phase position staging prevents transient unique-key
collisions. Unique owner+position and owner+generated-target keys are the final
database defense.

Independent PHP processes and connections prove:

- two different adds both survive in one contiguous order;
- duplicate add has one winner and one typed duplicate;
- add/reorder and delete/reorder have only serializable outcomes;
- divergent reorders have one winner and one stale result;
- equal reorders perform one semantic write/version increment;
- first-state creation is safe under concurrent add;
- source-delete versus add yields either unavailable add or a retained entry
  whose live FK becomes null;
- source-delete versus reorder preserves snapshot, entry and order.

## Schema 1006

The formal chain is now 1000→1001→1002→1003→1004→1005→1006. Migration
1005→1006 creates exactly two tables in dependency order:

1. `wp_biblio_next_reading_lists`;
2. `wp_biblio_next_reading_entries`.

The list table owns version and technical timestamps. The entry table owns
identity, owner, Work, closed target discriminator, minimal immutable snapshot,
nullable live source FKs, contiguous position and creation time. Work deletion
is restricted, owner-state deletion cascades entries, and source deletion sets
only its live pointer null.

MariaDB 10.11 rejects a `CHECK` that references a column also governed by an
`ON DELETE SET NULL` FK. Snapshot target shape therefore remains enforced by
named `CHECK`s, while two named BEFORE INSERT/UPDATE triggers enforce the
equivalent live-field type and snapshot-equality invariant. FK cascades do not
fire these triggers, so legitimate source deletion can still null the live
field. Health validates table, engine, columns, collations, generated
expressions, indexes, FKs, rules, CHECKs, trigger definitions and contiguous
stored data.

Fresh install, real 1005→1006 upgrade with preserved sentinel data, known
DDL-before-version retry, unknown partial-state rejection, trigger/data drift
and exact relational defenses run against MariaDB. No backfill exists because
there was no canonical earlier Hierna-lezen storage.

## Read models, Home and events

Named queries expose only the actor's full list or the first at most three
entries in `position ASC, entry_id ASC`. Home uses the same safe historical
fallback and never filters availability, access loss or missing source. There
is no Library-wide, platform-wide or social list query and no Home UI.

Add, remove and reorder have no ActivityEvent dependency and write zero Library
events. F2.9b adds no Timeline event, storage, UI, REST route or generic writer
to `CoreApplication`.

## Acceptance matrix mapping

The 73 cases in `docs/14-f2-9a-next-reading-analysis.md` remain the binding
case-level contract.

| F2.9a cases | Durable primary evidence |
| --- | --- |
| 1–8 IDs, values, targets, immutability | `NextReadingTest`; readonly closed constructors; production-boundary reflection |
| 9–18 source add and authorization | `NextReadingPersistenceTest`; real Item/Edition/membership and owned-loan repositories |
| 19–25 duplicate/cardinality/no maximum | application duplicate tests, generated unique-key tests and absence of every capacity constraint |
| 26–37 order, no-op/stale, delete, privacy | aggregate unit tests and owner-scoped MariaDB application tests |
| 38–44 collection races and initial state | `NextReadingConcurrencyTest` with independent processes/connections |
| 45–54 non-mutating source lifecycle/history | access-loss, Item/ExternalLoan hard-delete and source-delete race tests; immutable snapshot schema |
| 55–58 Home and query scope | named Home/full-list projectors, first-three integration assertions and `CoreApplication` boundary |
| 59–63 server IDs and persistence defense | `NextReadingIdRetryTest`, repository roundtrip, trigger/FK/CHECK/unique tests and source revalidation |
| 64–68 migration/retry/health/drift | `Schema1006NextReadingTest`, migration and production-lifecycle suites |
| 69–71 no events/Timeline and named composition | zero-event assertions, dependency graph and production-boundary test |
| 72 F2.6–F2.8 and infrastructure regressions | complete unit, MariaDB, lifecycle, concurrency and smoke suites |
| 73 canonical gate | exact final results below |

Some negative requirements are proven structurally rather than by a synthetic
mutation: no retarget method/service, no count limit, no global query, no event
dependency and no Timeline/UI storage exist in the production boundary or
schema. Existing active-only Item/ExternalLoan status storage cannot express a
second persisted status; F2.9b nevertheless stores none of that state and has
no automatic mutation path, so future status expansion cannot silently change
entry identity/order.

## Canonical gate

`./scripts/test-biblio-core-all.sh` completed successfully after all production,
test and documentation changes:

- Composer metadata valid and locked platform requirements satisfied;
- all PHP syntax valid;
- PHPStan level 6: no errors;
- unit: **219 tests, 834 assertions**;
- MariaDB integration: **165 tests, 1,240 assertions**;
- WordPress smoke: plugin active, class loaded, init hook 1, HTTP 200;
- manifest valid and Git whitespace checks clean;
- duration: 68 seconds.

Expected negative lifecycle tests log intentional schema-health and legacy-
version failures while the suite remains green.

## Scope and closure

No Elementor/frontend, REST endpoint, recommendation, algorithmic ranking,
priority, deadline, reminder, notification, social/collaborative list,
Timeline, statistics, goal, Notes/Assessment expansion or import/export was
added. Existing user data was not rewritten.

All F2.9a contracts and all 73 acceptance cases have durable direct or
structural evidence. F2.9b and therefore F2.9 can close with **GO**.
