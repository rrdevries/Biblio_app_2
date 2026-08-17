# 07 — Fase-1 exit evidence

Status: **GO**

Scope: Biblio V2 v2.001, F1.1–F1.7.

## Canonical verification

From a prepared local checkout with DDEV, the locked Composer dependencies and
an active healthy Biblio Core plugin, run exactly:

```bash
./scripts/test-biblio-core-all.sh
```

The command is fail-fast. It validates Composer metadata and platform
requirements, all plugin PHP syntax, level-6 PHPStan analysis of `src`,
the complete unit suite, the complete isolated `biblio_core_test` integration
suite, the existing local WordPress smoke, `manifest.json`, and staged plus
unstaged Git whitespace. It snapshots visible `git status` before execution and
fails if that state changes. The integration database is recreated and removed
per run; the normal WordPress database is read only by the smoke.

## Verification matrix

| Check | Current command | In canonical gate | Dependencies | Missing problem before F1.7 |
| --- | --- | --- | --- | --- |
| Complete unit suite | `./scripts/test-biblio-core-unit.sh` | Yes, once | DDEV, locked Composer vendor | Already separate and included. |
| Complete integration suite | `./scripts/test-biblio-core-integration.sh` | Yes, once | DDEV, WP-CLI, MariaDB, disposable `biblio_core_test` | Already separate and included. |
| Migration and schema health | Complete integration suite (`CoreSchemaMigrationTest`) | Yes | Integration dependencies | Covered, but not named by the old gate. |
| Lifecycle and activation | Complete integration suite (`ProductionLifecycleTest`) | Yes | Integration dependencies | Covered, but not named by the old gate. |
| Identity and authorization | Complete unit/integration suites, including `AuthenticatedIdentityBoundaryTest` | Yes | PHPUnit suites | Covered, but not named by the old gate. |
| Transaction semantics | Complete unit/integration suites, including `WpdbTransactionManagerTest` | Yes | PHPUnit suites | Covered, but not named by the old gate. |
| Both concurrency paths | Complete integration suite (`PersonalLibraryProvisioningConcurrencyTest`, `ReadingRoundConcurrencyTest`) | Yes | Independent PHP processes and MariaDB | Covered, but not named by the old gate. |
| WordPress smoke | `./scripts/test-biblio-core-smoke.sh` | Yes, once | Prepared active local plugin, curl, DDEV | Separate check existed but was absent from the old gate. |
| Composer metadata | `ddev composer --working-dir=web/wp-content/plugins/biblio-core validate --strict --no-check-publish` | Yes | DDEV, Composer, lockfile | Missing. |
| Composer platform | `ddev composer --working-dir=web/wp-content/plugins/biblio-core check-platform-reqs --lock` | Yes | DDEV, Composer, lockfile | Missing. |
| PHP syntax | DDEV `find` plus `php -l` over plugin PHP excluding vendor | Yes | DDEV, PHP | Missing. |
| Static analysis | plugin `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress` | Yes | PHPStan 2.2.8, WordPress 6.9.4 stubs | Missing dependency and configuration. |
| Manifest JSON | `jq empty manifest.json` | Yes | jq | Missing. |
| Git whitespace | `git diff --check` and `git diff --cached --check` | Yes | Git | Missing. |
| No repository mutation | Before/after `git status --porcelain=v1 --untracked-files=all` comparison | Yes | Git, cmp, temporary files | Missing. |

The named category rows deliberately reuse the complete suites. They are
coverage evidence, not additional executions.

## Exit checklist

| Criterion | Result | Evidence |
| --- | --- | --- |
| Fresh activation | PASS | Real activation in `ProductionLifecycleTest` installs formal baseline `1000`, verifies health and publishes Core only after success. |
| Current and forward upgrade/migration | PASS | F1.1 migration tests prove current no-op/data preservation and ordered forward steps with postconditions. |
| Migration failure and controlled retry | PASS | Integration cases prove no premature version bump, recognized partial-state retry and rejection of unsafe drift. |
| Schema health and fail-closed drift | PASS | `CoreSchemaHealthChecker` plus real-MariaDB drift cases cover tables, columns, indexes, constraints, generated expressions and FK rules. |
| Legacy spike versions 1–5 as upgrade sources | N/A | ADR-005 replaces these internal versions with formal baseline `1000`; they are diagnosed and rejected, not supported production migrations. |
| Production mutation boundary | PASS | `ProductionComposition`, `CoreApplication` and `ProductionApplicationBoundaryTest` expose named services, no repository writer or service locator. |
| Authenticated Library scope | PASS | Trusted actor resolution plus Core-built `LibraryContext` and Item Library checks are covered by unit and integration identity tests. |
| Cross-user isolation | PASS | Owned ExternalLoan/ReadingRound reads return `null`; source starts collapse inaccessible/unknown records to `reading_source_unavailable`. |
| Personal-Library concurrency | PASS | `PersonalLibraryProvisioningConcurrencyTest` uses independent processes/connections and proves one designation. |
| Item ReadingRound concurrency | PASS | `ReadingRoundConcurrencyTest` proves exactly one active round winner for the same user and Item source. |
| ExternalLoan ReadingRound concurrency | PASS | `ReadingRoundConcurrencyTest` plus its ExternalLoan worker prove exactly one winner for the same owned loan source. |
| Source/Work invariant | PASS | F1.5 source-specific services derive Work and repository defense in depth rejects an inconsistent aggregate. |
| Compound mutation rollback | PASS | Real-MariaDB provisioning/Reading tests prove failed compound writes leave no half-valid state. |
| Nested, commit and rollback semantics | PASS | F1.2 unit tests structurally retain failures, reject nesting and distinguish begin/operation/commit/rollback outcomes. |
| Persistable domain state | PASS | F1.6 identifier/title/date/active-state/permissions contracts and `PersistenceContractRoundTripTest` align hydration with columns. |
| History and FK `RESTRICT` | PASS | Schema health and database-integrity tests verify source/ownership foreign keys do not cascade-delete historical ReadingRound data. |
| Complete local quality gate | PASS | The canonical command runs Composer, lint, PHPStan, both complete suites, smoke, JSON and Git checks exactly once where applicable. |
| Documentation | PASS | Current state, architecture, acceptance, manifest and this exit record distinguish proven Core behavior from deferred scope. |
| Clean visible worktree state through verification | PASS | Gate before/after status snapshot is unchanged; integration uses only disposable `biblio_core_test`; smoke is read-only. |
| Static analysis | PASS | Locked PHPStan 2.2.8 plus WordPress 6.9.4 stubs analyse `src` at level 6 without baseline or ignored errors. |
| Independent version dimensions | PASS | Product `v2.001`, plugin/package `2.1.0`, formal schema baseline `1000`. |

## Implemented and proven

Fase 1 proves the Core foundation only: formal migration/health infrastructure,
failure and transaction semantics, production lifecycle/composition,
authenticated/scoped service boundaries, ReadingRound source/Work integrity,
domain/persistence contract alignment and the complete local quality gate.

## Explicitly deferred

Fase-1 GO does not claim REST, WordPress Abilities, Elementor/JetEngine or other
UI adapters; ExternalLoan or InternalLoan creation/completion; ReadingRound
completion; Item archive; membership/catalog mutation use-cases; new permission
functionality; product-level audit behavior; CI; or any Fase-2 functionality.
Those require their own application behavior, authorization, persistence and
acceptance work.
