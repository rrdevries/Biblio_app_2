# 08 — F2.5 exit evidence

Status: **GO**

Scope: Biblio V2 v2.001, F2.5 LibraryCatalogContext and local
classification.

## Git trace

- implementation baseline and exit-check HEAD:
  `f16e57fc80e33a085441751cf4b6b0bf07a75ae1`;
- exit-closure baseline: the same clean `main` HEAD, equal to the local
  `origin/main` tracking ref with ahead/behind `0/0`;
- exit-closure commits: the ADR harmonization and canonical documentation
  commits listed in the final exit-closure report;
- final repository HEAD: the commit containing this evidence and manifest
  registration; its exact immutable hash is recorded in that same report.

The final commit cannot embed its own hash without changing that hash. The
final report therefore supplies the exact immutable final HEAD while this file
durably identifies its role and baseline.

## Scope realized

F2.5 implements:

- LibraryCatalogContext as one Library-owned context per Library + Work;
- exactly one Boeksoort and duplicate-free unordered Genre and Onderwerp sets;
- typed persistent IDs, names, conservative normalized names, immutable seed
  keys, term status, selection and positive contextversion values;
- separate Boeksoort, Genre and Onderwerp lifecycle services;
- ActivityEvent foundations and append-only wpdb persistence;
- schema 1001 classification/audit DDL and schema 1002 data evolution;
- seed bootstrap/evolution for existing and new Libraries;
- independent Item-add and classification-management authorization;
- explicit represented-Work context creation, optimistic context save and
  term management;
- transactional context/term audit and compound Item-add initialization;
- real-MariaDB tenant-integrity, rollback and concurrency protection.

No Library-local Work or Edition identity, generic taxonomy engine or new
Item aggregate was introduced.

## Schema evolution 1000→1001→1002

Formal baseline remains 1000 and current version is 1002. The production
migration registry contains exactly the contiguous 1000→1001 and 1001→1002
steps relevant to F2.5.

Migration 1000→1001 introduces the three local term tables,
LibraryCatalogContext and its Genre/Subject junctions, and append-only
ActivityEvent persistence. Composite foreign keys enforce same-Library term
selection. It backfills `catalog.item_add` for every existing Manager
membership, including inactive memberships, without making an inactive
membership usable. Existing permissions are preserved and
`catalog.classification_manage` is not granted automatically.

Migration 1001→1002 is data-only and leaves schema-1001 DDL unchanged. It
processes every existing Library with the shared seed-evolution service and
records version 1002 only after the complete migration and health postcondition
succeed. Failure leaves version 1001; retry is idempotent and already converged
Libraries are safe no-ops.

## Seeds, adoption and health

New and migrated Libraries receive exactly 9 Boeksoort seeds, 12 Genre seeds
and no Onderwerp seeds. Existing seed keys are no-ops. One safe normalized
local candidate may adopt the immutable seed key by conditional write while
retaining ID, display name and status. Missing terms are created active.

Ambiguous or otherwise unsafe adoption performs no content mutation. Schema
health reports `classification_seed_adoption_ambiguous` with Library ID,
taxonomy type, seed key and candidate IDs as a non-blocking warning. Automatic
migration, bootstrap, adoption and retry write no Library ActivityEvent. New-
Library seed bootstrap participates in the existing Library + Owner-membership
transaction, so a seed failure rolls back the complete creation operation.

## Authorization and application boundaries

Owner has both management capabilities. An active Manager requires the
independent stored permission for each operation:

- `catalog.item_add` permits Item-add and only its inseparable missing-context
  initialization;
- `catalog.classification_manage` permits represented-Work context creation,
  existing-context changes and classification-term management.

Neither permission grants the other. Member, inactive and foreign memberships
are denied, and `UseAccess` does not influence management authorization.
Authorization and server-side actor resolution precede sensitive lookup or
mutation. `ProductionComposition` owns construction and `CoreApplication`
exposes only named application services, never repositories, the internal
initializer or caller-controlled actor/context input.

## Context, lifecycle, audit and concurrency

Context save uses compare-and-swap optimistic locking. A real mutation raises
version exactly once and appends exactly one structured context ActivityEvent
with related Work, technical IDs and historical labels in the same transaction.
Semantic no-op and stale-no-op return current state without write, increment or
event; divergent stale state produces the stable context conflict and no merge.

The final ADR-006 retained-inactive contract is implemented and tested:

- only newly introduced classification-term IDs must be active;
- retained inactive Boeksoort, Genre and Onderwerp links may remain while
  another part of the context changes;
- changing the Boeksoort ID requires explicit confirmation and an active new
  Boeksoort;
- there is no automatic replacement or reactivation;
- Item-add reuses an existing context unchanged, including an inactive retained
  Boeksoort.

Term create, rename, deactivate and reactivate remain separate per type. There
is no hard delete or merge. Normalized uniqueness covers active and inactive
terms. Each real term mutation and its ActivityEvent commit atomically; no-op,
conflict and audit failure leave no committed mutation/event. Library-level
serialization protects the confirmed last-active Boeksoort decision, while
term-row locks serialize deactivation against new context links.

Independent-process integration tests prove:

- seed bootstrap converges without duplicates;
- two context saves from one version produce one winner and one stale conflict;
- equal concurrent missing-context creation reuses one context, while different
  desired selections produce one winner and one stable conflict;
- equal concurrent Item-add initialization produces one context event and two
  valid Items, while different selections roll back the loser;
- duplicate term create and rename/create collision produce one audited winner
  and one conflict;
- deactivation versus new linking and concurrent last-Boeksoort decisions are
  serialized.

## Regression evidence

The complete suites retain F2.3 catalog creation, explicit Library isolation,
transaction rollback, ProductionComposition/CoreApplication boundaries and
ReadingRound Item→Edition→Work derivation. Work and Edition remain platform-
wide; Item and LibraryCatalogContext remain Library-scoped.

## Canonical verification

Final exit-closure verification on 2026-08-22:

- `./scripts/test-biblio-core-all.sh`: PASS in 50 seconds;
- PHP syntax: PASS;
- PHPStan level 6 over production `src`: PASS, no errors;
- unit: 183 tests, 576 assertions;
- isolated real-MariaDB integration: 119 tests, 813 assertions;
- total: 302 tests, 1,389 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- Composer metadata/platform, manifest JSON and Git whitespace: PASS;
- separate requested PHPStan run: PASS, no errors;
- final `git diff --check`: PASS;
- visible worktree state was not unexpectedly changed by the gate.

## Exit criteria

The 52 explicitly numbered ADR-006 acceptance criteria in §§26, 35 and 44 are
all proven. The exit-check also grouped the remaining relevant normative
contracts into 21 auditable contract areas. After harmonizing §§14 and 38, all
21 are proven.

| Classification | Explicit criteria | Normative contract areas |
| --- | ---: | ---: |
| Proven | 52 | 21 |
| Partially proven | 0 | 0 |
| Not proven | 0 | 0 |

No binding ADR-006 contradiction or F2.5 exit blocker remains.

## Deferred and non-scope

F2.5 GO does not implement or imply:

- REST, WordPress Abilities, Elementor or another UI adapter;
- metadata providers, proposals, mappings or automatic classification;
- the Boeksoort request workflow;
- server-side ItemId generation or richer conflict presentation;
- term-level optimistic locking;
- aliases, merge or taxonomy hierarchy;
- Author/contributors, Series or broader bibliographic metadata;
- Archive, Collections, lending, Location, Condition, Acquisition or search;
- an operational remediation UI for seed-adoption warnings.

These require separate product and implementation slices.

## Final verdict

**GO** — ADR-006, current state, architecture, acceptance documentation and the
implemented behavior are consistent; all F2.5 exit criteria are proven and the
complete canonical quality gate is green.
