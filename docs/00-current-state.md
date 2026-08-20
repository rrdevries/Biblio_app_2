# 00 — Current state

Status: canonical working state for Biblio V2 / v2.001.

## Product model

Biblio is one application. Every platform user has one private, platform-wide environment: `Mijn Biblio`.

A `Bibliotheek` is an internal tenant/domain entity inside the same WordPress site. It is not a WordPress Multisite site.

A user can initially belong to zero Libraries.

For v2.001 we assume every user has at most one designated own/personal `Privébibliotheek`. If the user performs their first relevant reading or borrowing action and does not yet have that personal Library, Biblio creates it automatically. The user becomes `Eigenaar` with `Directe toegang`.

This personal Privébibliotheek is an ownership/authorization anchor for the user's own collection context; it does not own private ReadingRounds, external loans or other Mijn Biblio data.

The same user can additionally belong to, manage or own other Privébibliotheken.

v2.001 supports only physical books.

## Scope layers

### Platform

Owns:
- Biblio user accounts;
- central bibliographic identity;
- platform administration and recovery.

### Bibliotheek

Owns:
- physical collection and Items;
- local catalog context;
- locations;
- collections;
- desired acquisitions;
- archive;
- internal lending;
- library settings;
- library audit.

### Mijn Biblio / user

Owns private:
- ReadingRounds;
- Reading inventory view (`Leesvoorraad`);
- external borrowing;
- reading goals;
- wishlist (`Verlanglijst`);
- `Hierna lezen`;
- ratings/reviews;
- notes;
- personal statistics/year overview/timeline;
- Home configuration and personal preferences.

## Library types

Conceptually:
- `Privébibliotheek`
- `Uitleenbibliotheek`

Only `Privébibliotheek` can be selected in v2.001. `Uitleenbibliotheek` is shown disabled as future functionality.

A v2.001 Library cannot change type.

## Membership

Membership has two independent dimensions.

### Beheerrol
- Eigenaar
- Beheerder
- Lid

### Gebruikstoegang
- Directe toegang
- Lenen
- Alleen bekijken

For a v2.001 Privébibliotheek the Eigenaar always has `Directe toegang`.

A non-owner membership defaults to:
- `Lid`
- `Alleen bekijken`
- `Actief`

A `Beheerder` has baseline rights to manage the shared catalog/books and physical Exemplaren in the current Bibliotheek. Other management domains require explicit additional permissions.

Additional management permissions are explicit and only active while the user is a `Beheerder`.

## Accounts

Only Platform `Super admin`, or an `Admin` with explicit platform `Gebruikersbeheer`, creates platform-wide Mijn Biblio accounts in v2.001.

A newly created account may temporarily have no Library.

When that user first performs a relevant reading or borrowing action, Biblio automatically creates the user's one designated personal Privébibliotheek if it does not yet exist. The user becomes:
- Eigenaar
- Directe toegang

A user may still create or own additional Privébibliotheken for other shared collections where the product flow permits this.

Adding that user to another existing Library initially happens through Platformbeheer. The existing platform account is reused.

## Bibliographic model

Platform-wide identity:
- Work
- Edition
- Auteur
- Serie

Library-local:
- LibraryCatalogContext
- Item/Exemplaar
- local Boeksoort / Genre / Onderwerp
- acquisition, location, condition, archive and lending state

User-owned:
- ReadingRound and all private reading activity.

Central identity does not mean unrestricted editing. When a central record is used by multiple Libraries, ordinary Library administrators propose corrections instead of directly changing the shared record.

## Reading

A new active ReadingRound always has:
- one user;
- one Work;
- exactly one concrete physical source.

There is at most one active ReadingRound per user + concrete source.

Multiple simultaneous ReadingRounds for the same Work are allowed when they use different physical sources.

Valid active v2.001 sources:
- Library Item available through `Directe toegang`;
- Item currently internally lent to the user;
- active external loan.

Historical closed ReadingRounds may have an unknown source if it is genuinely no longer known.

`Mijn leesstatus` is user × Work:
- at least one active round → Aan het lezen;
- else at least one completed successful round → Uitgelezen;
- else → Niet gelezen.

## Leesvoorraad

`Leesvoorraad` is a user-specific view of concrete physical sources the user may use now and that do not have an active ReadingRound on that exact source.

Past reading history does not remove a source from inventory.

An administratively available Item may simultaneously appear in multiple direct-access users' inventories because direct physical use does not create a checkout automatically.

## Hierna lezen

A fully manual private list in v2.001.

Entries can be:
- a Work;
- a specific concrete Item/source.

No automatic removal, availability rule, lending rule or ReadingRound rule modifies the list.

## Lending

`Directe toegang` allows direct use without a loan, but an explicit internal loan may still be recorded.

`Lenen` requires an internal loan before the Library Item becomes the user's physical reading source.

`Alleen bekijken` allows collection viewing/searching but neither direct use nor receiving an internal loan.

No loan request/reservation workflow in v2.001.

## Home

Home is the modular start page inside Mijn Biblio.

Fixed:
- Zoeken
- Mijn bibliotheken
- Home aanpassen

Default modules on:
1. Nu aan het lezen
2. Openstaande acties
3. Hierna lezen
4. Leesdoelen
5. Leesvoorraad
6. Geleend

Default off:
- Verlanglijst
- Recente activiteit
- Statistieken
- Snelle acties

## Audit

`Bibliotheek → Activiteitenlog` is a Library audit function for Eigenaar and authorized Beheerders, not a general member feature.

Personal meaningful history belongs in `Mijn Biblio → Tijdlijn`.

## Technical architecture

One WordPress site with:
- WordPress infrastructure;
- Biblio Core;
- Crocoblock/JetEngine where suitable;
- Elementor Pro for presentation;
- custom PHP/JS where integrity or interaction requires it.

Biblio-owned custom tables are the proven baseline for relational and transactional Core-data where hard integrity, tenant isolation, user ownership, transactions or concurrency are central. Persistence remains selectable per domain. CPT, CCT and JetEngine remain available as supporting options where they do not undermine Core boundaries or integrity rules.

Work and Edition remain platform-wide and use Biblio-owned custom tables for v2.001. Item is Library-owned. ExternalLoan and ReadingRound are user-owned.

The current ReadingRound source representation uses nullable `item_id` and `external_loan_id` foreign keys with an exactly-one check. This is the accepted v2.001 baseline for the two currently implemented source types, not a permanent decision for every future source type.

See `docs/decisions/ADR-004-fase-0-persistence-and-reading-sources.md`.

## Fase 0

Status: **Afgerond — GO**

Fase 0 has proven:
- single-site WordPress as application runtime;
- Biblio Core without an Elementor dependency for domain flows;
- explicit Library Context;
- LibraryMembership and server-side authorization;
- Biblio-owned custom-table persistence against real MariaDB;
- a valid zero-Library account state;
- explicit personal-Library designation;
- transactional, idempotent and concurrency-safe personal Privébibliotheek provisioning;
- platform-wide Work and Edition;
- Library-owned Item with cross-Library isolation;
- user-owned ExternalLoan without Library ownership;
- user-owned ReadingRound with exactly one concrete source;
- simultaneous active ReadingRounds for the same Work on different sources;
- cross-user privacy and absence of Library-role bypasses;
- transactions and rollback for compound mutations;
- database-enforced concurrency invariants;
- isolated WordPress/MariaDB integration tests with automatic setup and cleanup.

Last verified Fase-0 test baseline:
- PHP 8.3.31;
- PHPUnit 12.5.33;
- unit: 55 tests, 114 assertions;
- integration: 57 tests, 276 assertions;
- total: 112 tests, 390 assertions;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200.

InternalLoan has not been implemented in Fase 0.

## Fase 1

**Fase 1 — Biblio Core fundament stabiliseren**

Status: **Afgerond — GO**

De uitgevoerde stabilisatie omvat:
- distinguish spike-only code from production-worthy code;
- stabilize module boundaries and naming;
- define the migrations/install/upgrade lifecycle;
- stabilize application-service contracts;
- establish a consistent exception model;
- review transaction boundaries;
- extend the authorization matrix where the next implemented behavior requires it;
- establish lifecycle diagnostics; product-level audit behavior remains
  deferred until a concrete use-case requires it.

### F1.1 — Versioned Core Migration Infrastructure

Status: **Implemented**

- the formal supported schema history starts at baseline version `1000`;
- internal Fase-0 spike versions 1–5 are not production upgrade paths;
- product `v2.001`, plugin/package `2.1.0` and schema version are independent;
- baseline installation requires an empty Core schema;
- future schema changes use ordered forward migration steps;
- version bump occurs only after the step postcondition succeeds;
- schema health checks essential tables, columns, constraints, indexes,
  generated columns and foreign-key lifecycle rules;
- unexpected drift fails closed without automatic repair;
- real MariaDB tests prove fresh install, current no-op/data preservation,
  failure without version bump, controlled retry and drift detection.

Last verified F1.1 test baseline:
- unit: 55 tests, 114 assertions;
- integration: 60 tests, 274 assertions;
- total: 115 tests, 388 assertions;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200.

See `docs/decisions/ADR-005-formal-core-schema-migration-baseline.md`.

### F1.2 — Consistent exception and transaction model

Status: **Implemented**

- expected Core failures use validation, authorization, conflict, persistence
  and transaction categories with stable reason codes;
- valid read absence remains `null`, authorization predicates may remain
  boolean, and mutation failures use exceptions;
- wpdb duplicate-key recognition is centralized and repositories translate
  known constraints into semantic ReadingRound or personal-Library conflicts;
- raw database diagnostics remain diagnostic context rather than application
  reason codes or public persistence messages;
- nested transactions are rejected on both the manager and connection level;
- begin, operation, commit and rollback failures have explicit semantics;
- operation failures remain primary after successful rollback, while rollback
  failure retains both failures and reports an uncertain outcome;
- deterministic transaction-level failures are covered by test doubles and
  real MariaDB continues to prove compound rollback and concurrency behavior.

Last verified F1.2 test baseline:
- unit: 66 tests, 149 assertions;
- integration: 61 tests, 295 assertions;
- total: 127 tests, 444 assertions;
- personal-Library concurrency: 1 test, 10 assertions;
- ReadingRound concurrency: 1 test, 3 assertions;
- F1.1 migration regression: 9 tests, 52 assertions;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200.

HTTP/REST/Abilities mapping, UI copy and plugin composition remain outside
F1.2.

### F1.3 — Production plugin lifecycle and composition root

Status: **Implemented**

- the plugin registers a real WordPress activation hook before activation;
- activation runs the F1.1 migrator, validates schema health and lets failures
  escape so WordPress does not record a false successful activation;
- normal runtime checks the installed schema version at early `init` priority
  1 and runs only missing explicit migrations;
- a successful full health inspection is cached for 300 seconds, while a
  matching lifecycle failure defers retries for 60 seconds;
- the caches are operational throttles, never schema truth: migration and
  health remain authoritative and activation always clears both caches;
- unhealthy Core boot keeps WordPress available but publishes no Core
  application boundary, logs a technical reason and shows a capability-gated
  administrator notice without raw SQL;
- `ProductionComposition` creates one shared dependency graph for migration,
  transactions, repositories, authorization and the existing application
  services;
- `CoreApplication` exposes only named application services; concrete wpdb
  repositories and lower-level write primitives are not adapter entrypoints;
- successful initialization still fires `biblio_core_initialized`, now with
  the `CoreApplication` boundary as its first argument;
- plugin hook registration and lifecycle execution are idempotent per request.

Last verified F1.3 test baseline:

- unit: 66 tests, 149 assertions;
- integration: 69 tests, 345 assertions;
- total: 135 tests, 494 assertions;
- lifecycle/activation: 8 tests, 50 assertions;
- F1.1 migration regression: 9 tests, 52 assertions;
- concurrency regression: 2 tests, 13 assertions;
- F1.2 transaction regression: 8 tests, 26 assertions;
- WordPress smoke: plugin active, Core loaded, init hook executed once, HTTP
  200.

F1.3 deliberately adds no REST/Abilities/UI/WP-CLI adapter.

### F1.4 — Authenticated identity and scoped boundaries

Status: **Implemented**

- application services resolve the current actor through the small
  `AuthenticatedUser` port; the WordPress adapter accepts no actor input and
  maps only the current valid WordPress user to the domain `UserId`;
- unauthenticated use fails explicitly with `authentication_required`;
- production-facing self-service signatures no longer accept `UserId` as an
  actor or accept a caller-constructed `LibraryContext`;
- a caller may select a Library ID as target, after which Core constructs the
  context from that ID plus the trusted actor and separately verifies both
  membership permission and Item record scope;
- personal-Library provisioning, owned ExternalLoan/ReadingRound reads and
  both existing Reading startflows use the same authenticated actor dependency;
- user-owned flows retain the valid zero-Library state and never gain access
  through Library roles;
- read and write repository ports are separated for Library, Work, Edition,
  Item, ExternalLoan and ReadingRound where existing contracts previously
  exposed unused writes;
- ExternalLoan read and write persistence adapters are separate; the
  authorization-neutral writer is not constructed by the production
  composition because no ExternalLoan creation use-case is exposed;
- Library, membership and catalog persistence primitives remain internal
  composition/test facilities until an explicitly authorized mutation service
  is implemented;
- foreign or unknown owned/scoped reads remain non-enumerating `null`; start
  flows report `reading_source_unavailable`; authentication itself has the
  distinct stable reason `authentication_required`.

Last verified F1.4 test baseline:

- unit: 68 tests, 161 assertions;
- integration: 73 tests, 372 assertions;
- total: 141 tests, 533 assertions.

F1.4 adds no REST/Abilities/UI/WP-CLI adapter and no new ExternalLoan,
membership or catalog product lifecycle.

### F1.5 — ReadingRound source and Work invariants

Status: **Implemented**

- supported Reading-start operations accept a concrete source target, never a
  caller-selected `WorkId` plus `ReadingSource` combination;
- Library Item start validates the Item/Edition relation and derives Work via
  Item → Edition → Work;
- ExternalLoan start resolves the actor server-side, requires the active loan
  to be owned by that actor and derives Work from the loan;
- the shared Reading creation service exposes only source-specific public
  entrypoints; its generic aggregate construction step is private;
- the ReadingRound write repository re-resolves the persisted source relation
  and rejects a source/Work mismatch before insert;
- the database representation remains the ADR-004 v2.001 baseline: exactly one
  source through XOR/FKs and active uniqueness per User + concrete source;
- independent-process MariaDB tests prove exactly one winner for concurrent
  starts from the same Item and from the same ExternalLoan.

The repository check is defense in depth for privileged internal/test callers;
production adapters receive no ReadingRound writer and cannot bypass the
source-specific application services. No trigger, denormalized source table or
schema migration is introduced. A future InternalLoan source remains outside
F1.5 and still requires the renewed representation choice recorded in ADR-004.

Last verified F1.5 test baseline:

- unit: 71 tests, 169 assertions;
- integration: 76 tests, 379 assertions;
- total: 147 tests, 548 assertions.

F1.5 adds no REST/Abilities/UI/WP-CLI adapter and no ExternalLoan, InternalLoan,
membership or catalog creation lifecycle.

### F1.6 — Domain/persistence contracts and naming

Status: **Implemented**

- every currently persisted Core identifier is non-empty valid UTF-8 with a
  maximum of 191 characters, matching the actual `VARCHAR(191)` columns;
- Work title is valid UTF-8 and at most 512 characters, matching
  `work_title VARCHAR(512)`;
- ExternalLoan and ReadingRound dates must fall inside MariaDB `DATETIME(6)`'s
  supported UTC year range 1000–9999 before persistence;
- the non-persistable `ExternalLoanStatus::Inactive` state is removed; the
  current ExternalLoan model is explicitly active-only;
- AdditionalPermissions is an ordered list of unique, non-empty valid UTF-8
  strings. Values and whitespace are preserved exactly; no normalization or
  permission catalogue is introduced;
- membership hydration accepts only a JSON array of strings and then applies
  all domain invariants. Objects, non-string elements, empty identifiers,
  duplicates and invalid JSON fail as `persistence_read_failed` rather than
  being normalized or partially hydrated;
- Library, Item and ReadingRound likewise remain active-only technical models
  in the current Core implementation. Membership already supports both active
  and inactive states in domain and persistence;
- the Core-wide integration reset helper no longer carries the misleading old
  Library-only name. No `LibrarySchema*` or `LibraryTableNames` production
  infrastructure remains;
- product `v2.001`, plugin/package `2.1.0` and formal schema baseline `1000`
  remain separate and no version number or schema is changed by F1.6.

The database schema already expressed the intended current state, so F1.6
narrows domain contracts and hardens hydration without a migration. Completed
ExternalLoan/ReadingRound, Item archive and membership mutation lifecycles stay
deferred until their actual application behavior and historical data are
implemented.

Last verified F1.6 test baseline:

- unit: 105 tests, 206 assertions;
- integration: 79 tests, 396 assertions;
- total: 184 tests, 602 assertions.

F1.6 adds no lifecycle transitions, new permission functionality, REST/UI,
schema migration or new failure reason.

### F1.7 — Complete quality gate and proven documentation

Status: **Implemented**

- `./scripts/test-biblio-core-all.sh` is the canonical root quality command;
- it validates Composer metadata and runtime platform requirements, lints all
  plugin PHP, runs PHPStan against production `src`, executes the complete unit
  and isolated MariaDB integration suites once, performs the WordPress smoke,
  validates `manifest.json` and checks staged plus unstaged Git diffs;
- PHPStan and the WordPress stubs are reproducible `require-dev` dependencies
  in `composer.lock`; analysis uses explicit level 6 without baseline or
  ignored errors;
- the gate is fail-fast and compares the complete visible Git status before
  and after execution, so an otherwise green run still fails on a repository
  mutation;
- migration, lifecycle/activation, identity/authorization, transaction and
  both concurrency categories remain covered by the one full integration/unit
  pass rather than duplicate targeted reruns;
- `docs/07-fase-1-exit-evidence.md` records the complete Fase-1 exit decision,
  evidence and deferred boundaries.

Last verified F1.7 quality baseline:

- PHPStan 2.2.8, level 6, production `src`: no errors;
- unit: 105 tests, 206 assertions;
- integration: 79 tests, 396 assertions;
- total: 184 tests, 602 assertions;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200;
- Composer metadata/platform, all plugin PHP syntax, manifest JSON, staged and
  unstaged diff checks: passed;
- visible repository status before and after the gate: unchanged.

Fase 1 changes no product version, plugin/package version or formal schema
baseline: these remain `v2.001`, `2.1.0` and `1000`. Legacy spike versions 1–5
remain unsupported migration sources under ADR-005, not unfinished Fase-1
work. No REST/Abilities/UI adapter, new product behavior, new schema migration,
CI pipeline or Fase-2 implementation is introduced.

## Fase 2

### F2.3 — First catalog vertical slice

Status: **Implemented**

The first production-supported catalog mutation reuses the existing canonical
Work → Edition → `Catalog\Item` model and schema:

- `AddLibraryItemService` exposes separate operations for an existing Edition,
  a new Edition under an existing Work, and a new Work plus Edition;
- the current actor is resolved server-side for every operation and the caller
  selects only the target Library and catalog identifiers/data;
- catalog Item-add requires an active Owner or an active Manager with
  `catalog.item_add` and is independent of physical `UseAccess`; Member,
  inactive and foreign-Library actors are denied before central catalog
  existence is inspected;
- `catalog.classification_manage` is an independent Manager permission for
  existing LibraryCatalogContext changes and classification-term management;
  it grants no Item-add right, while `catalog.item_add` grants no general
  classification-management right;
- the service creates the Item with the authorized Library identity and never
  creates a Library-local Work or Edition identity;
- all paths are transaction-managed; Edition+Item and Work+Edition+Item are
  atomic and leave no partial rows after failure;
- duplicate Work, Edition or Item primary identifiers map to the stable
  `catalog_record_already_exists` conflict while retaining MariaDB diagnostics;
- multiple Items may reference one Edition within a Library and the same
  platform-wide Edition may be reused by multiple Libraries;
- Item reads stay explicitly Library-scoped, and a newly created Item remains
  a valid ReadingRound source whose Work is derived through Edition;
- `CoreApplication::libraryItemCreation()` is the single new production
  accessor; repositories and generic service-location remain unpublished.

No schema migration, catalog table, aggregate or version change is part of
F2.3. Product `v2.001`, plugin/package `2.1.0` and schema baseline `1000` remain
unchanged. REST/Abilities/UI, Author/contributors, Series, extended metadata,
search/entity resolution, taxonomy, Collections, Wishlist, Archive, lending,
ratings/notes and reading goals remain outside this slice.

Last verified F2.3 quality baseline:

- PHPStan level 6 over production `src`: no errors;
- unit: 132 tests, 298 assertions;
- integration: 87 tests, 467 assertions;
- total: 219 tests, 765 assertions;
- independent-process catalog creation: one winner, one stable conflict;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200;
- Composer, PHP syntax, manifest and Git diff checks: passed.
