# 00 — Current state

Status: canonical working state for Biblio V2 / v2.001.

Explicit decisions, directions and open design questions for versions after
the active v2.001 scope are maintained in
`docs/26-future-roadmap-decisions.md`.

The canonical living visual and UI baseline is maintained in
`docs/31-biblio-design-system.md`. Its structural theming and Atmosphere
architecture is accepted in
`docs/decisions/ADR-009-biblio-ui-theming-and-atmosphere-architecture.md`.

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
- Verlanglijst;
- `Hierna lezen`;
- `Wat zal ik lezen?`, including private suggestion results and preferences;
- ratings/reviews;
- notes;
- personal statistics/year overview/timeline;
- Home configuration and personal preferences.

### Wat zal ik lezen?

Status: **Functionele basis v2.001 — vastgezet; nog niet geïmplementeerd**.

`Wat zal ik lezen?` is een private, gebruiker-eigen en platformbrede keuzehulp
naast het volledig handmatige `Hierna lezen`. Dezelfde drie persoonlijke
engines zijn bereikbaar vanuit Mijn Biblio en binnen iedere Bibliotheek; alleen
de kandidatenpool wordt in Library-context tot die geautoriseerde Library
beperkt. De functie bepaalt eerst concrete beschikbare bronkandidaten en hun
toepasselijke Library-lokale classificatie, dedupliceert daarna per Work en
muteert nooit brondata.

Het volledige contract, `Kies uit…`, persoonlijke suggestion-uitsluitingen,
deferred onderdelen en open ontwerpvragen staan in
`docs/40-what-shall-i-read-functional-design.md`. Er is geen productiecode,
schema, REST- of UI-implementatie aan gekoppeld.

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

The technical central bibliographic baseline is schema `1009`. It persists
stable Author and Series identities plus typed Work relationships. Work
contributors are limited to ordered `author` and `co_author` roles. A
Work-Series membership stores either a validated sortable decimal position or
`NULL` for genuinely unknown position; unknown is never converted to a
fictitious volume number. These central relationships contain no Library,
Item or User ownership and grant no Library access.

Schema `1010` adds the remaining canonical Search metadata foundation:
lossless alternative Work titles with deterministic technical identity,
validated optional ISBN-10/ISBN-13 plus an explicit no-ISBN state on Edition,
optional Library-scoped Item inventory numbers, and ordered acyclic
Work-containment relations for omnibus/bundle identity. Central metadata
remains ownership-neutral. Inventory-number reads require authorized Library
Context and remain scoped to the requested Library. This foundation adds no
Search, filter, sort, REST or UI behavior.

Schema `1011` adds the Library Item Location foundation. A Location has a
stable ID, a lossless validated display name and exactly one owning Library.
An Item has zero or one current Location. The nullable Item relation is
protected by a composite Library+Location foreign key, so an Item cannot use a
Location from another Library. Authorized Library-scoped batch reads cover
Library→Locations, Items→Location and Locations→Items without an inherent
N+1 contract. Equal display names do not merge. No archive lifecycle,
Location management flow, Search/filter query, REST or UI was added.

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

One private, platform-wide, user-owned and manually ordered list of future
reading intentions. Every entry has its own stable planning identity, exactly
one Work and optionally one preferred source (`library_item` or
`external_loan`). The same Work and even the same complete preference may occur
multiple times; there is no content uniqueness.

Preferred source is mutable context, not authorization, reservation or
ReadingRound provenance. Source loss preserves entry, Work, position and list
version and is projected generically when protected live context is no longer
available.

After a successful active ReadingRound start, Core transactionally consumes at
most one matching entry: the explicit entry when starting from an entry,
otherwise the first exact live-source match in current order, then the first
general Work entry. Historical/source-free registration consumes nothing.
Manual remove creates one short-lived owner-scoped one-time Undo token; automatic
consumption does not. The technical default Undo TTL is 30 seconds.

## Lending

`Directe toegang` allows direct use without a loan, but an explicit internal loan may still be recorded.

`Lenen` requires an internal loan before the Library Item becomes the user's physical reading source.

`Alleen bekijken` allows collection viewing/searching but neither direct use nor receiving an internal loan.

No loan request/reservation workflow in v2.001.

## Biblio Home, Bibliotheek Home en Mijn Bibliotheek

Biblio kent drie structureel verschillende navigatieniveaus:

- **Biblio Home** is het platformniveau buiten één specifieke actieve
  Bibliotheekcontext. Het toont de Bibliotheken waartoe de gebruiker toegang
  heeft, laat een Bibliotheek openen of wisselen en kan lichte platformbrede
  persoonlijke context tonen. Het is geen volledige catalogus en doet zonder
  actieve Bibliotheek niet alsof de Library-shell of -sidebar al actief is.
- **Bibliotheek Home** is de bestaande persoonlijke, selectieve en
  actie-/discoverygerichte Home / Action Center binnen precies één door Core
  gevalideerde actieve Library Context. Deze Home kan lokale zoekfunctie,
  primaire acties, Nu aan het lezen, Openstaande acties, Hierna lezen,
  Leesvoorraad en relevante of recente boeken bevatten, maar toont bewust niet
  de volledige catalogus. Home blijft een uitnodigend actiecentrum en geen
  zwaar dashboard.
- **Mijn Bibliotheek** is de volledige actieve catalogus binnen die ene
  Bibliotheek: alle actieve Library Items, met de toepasselijke zoek-, filter-,
  sorteer-, Grid-, Lijst-, Boekenplank-, filterchip-, cursor/load-more-, Quick
  View- en Itembeheercontracten voor zover afzonderlijk ondersteund. Deze
  bestemming is functioneler, rustiger, scanbaarder en informatiedichter en is
  niet de Bibliotheek Home.

Bij het openen van een Bibliotheek vanuit Biblio Home ontstaat de actieve
Library Context. Binnen die context zijn `Home` en `Mijn Bibliotheek` aparte
hoofdnavigatiebestemmingen, conceptueel naast `Collecties`, `Lezen` en andere
Library-functies:

```text
Home                → Bibliotheek Home / Action Center
Mijn Bibliotheek    → volledige actieve catalogus
```

Oudere mockups en formuleringen waarin Home-functionaliteit onder de titel
`Mijn Bibliotheek` stond, zijn uitsluitend historische ontwerpcontext en geen
actuele informatiearchitectuur.

Biblio Home bevat de bestaande toegang tot `Mijn bibliotheken`. Bibliotheek
Home behoudt de reeds ontworpen lokale zoek-/actiecontext en `Home aanpassen`.
De standaard ingeschakelde Action Center-modules zijn Nu aan het lezen,
Openstaande acties, Hierna lezen, Leesdoelen, Leesvoorraad en Geleend;
Verlanglijst, Recente activiteit, Statistieken en Snelle acties staan standaard
uit. Deze verdeling verfijnt de IA en ontwerpt de bestaande Home-functionaliteit
niet opnieuw.

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

The current ReadingRound source representation uses nullable `item_id` and
`external_loan_id` foreign keys with a zero-or-one check. Normal active starts
still require exactly one authorized source; zero sources are reserved for
explicitly corrected unknown provenance and manual history. This is the
accepted v2.001 baseline for the two currently implemented source types, not a
permanent decision for every future source type.

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
- `CoreApplication::libraryItemCreation()` remains the only Item-creation
  accessor; classification management is exposed through separate named
  context-, Boeksoort-, Genre- and Onderwerp-services, while repositories and
  generic service-location remain unpublished.

No schema migration, catalog table, aggregate or version change is part of
F2.3. Product `v2.001`, plugin/package `2.1.0` and schema baseline `1000` remain
unchanged. REST/Abilities/UI, Author/contributors, Series, extended metadata,
search/entity resolution, taxonomy, Collections, Verlanglijst, Archive, lending,
ratings/notes and reading goals remain outside this slice.

Last verified F2.3 quality baseline:

- PHPStan level 6 over production `src`: no errors;
- unit: 132 tests, 298 assertions;
- integration: 87 tests, 467 assertions;
- total: 219 tests, 765 assertions;
- independent-process catalog creation: one winner, one stable conflict;
- WordPress smoke: plugin active, Core loaded, init hook executed, HTTP 200;
- Composer, PHP syntax, manifest and Git diff checks: passed.

### F2.5 — LibraryCatalogContext and local classification

Status: **Implemented**

F2.5 adds the Library-owned classification layer around platform-wide Work
identity. `LibraryCatalogContext` is uniquely scoped by Library + Work and
contains exactly one Boeksoort plus duplicate-free, unordered Genre and
Onderwerp sets. The typed identifiers, names, normalized names, immutable seed
keys, term statuses, selection and contextversion value objects share the
persistable Core contracts.

Schema 1001 introduced the separate Boeksoort, Genre and Onderwerp tables,
LibraryCatalogContext plus its set junctions, and immutable append-only
ActivityEvent persistence. Composite foreign keys enforce that context and
terms belong to the same Library. The 1000→1001 migration also backfills
`catalog.item_add` for existing Manager memberships, preserves all additional
permissions and deliberately does not grant `catalog.classification_manage`.

Formal data-only migration 1001→1002 evolves every existing Library through
the shared seed-evolution service. It adopts only one safe hard-normalized
local candidate, otherwise creates the missing seed or emits the non-blocking
`classification_seed_adoption_ambiguous` health warning without guessing.
IDs, display names, status and existing context links are preserved; migration,
bootstrap and adoption create no Library ActivityEvent. New Libraries use the
same service inside the existing Library + Owner-membership transaction and
receive exactly 9 Boeksoort seeds, 12 Genre seeds and no Onderwerp seeds.

Item-add and classification management use independent permissions.
`catalog.item_add` controls Item creation and its inseparable missing-context
initialization; `catalog.classification_manage` controls explicit represented-
Work context creation, existing-context changes and term management. Owner has
both rights; an active Manager only has permissions explicitly stored for that
membership. Member, inactive and foreign memberships are denied, and
`UseAccess` does not alter either management decision.

#### Context and term management

Production Core now exposes explicit services for represented-Work context
creation, optimistic context save and the separate Boeksoort, Genre and
Onderwerp lifecycles. Every operation resolves the actor server-side and uses
`catalog.classification_manage`; Item-add authorization is not accepted.

Context save supports semantic no-op and stale-no-op before conflict handling.
A real save validates only newly introduced links as active, increments the
contextversion exactly once and appends exactly one immutable ActivityEvent in
the same transaction. Already linked inactive Boeksoort, Genre and Onderwerp
IDs may be retained when another part of the context changes; changing the
Boeksoort ID requires explicit confirmation and the new Boeksoort must be
active. Nothing is automatically reactivated or replaced. Term writes and
their term-level ActivityEvents are likewise atomic; no-op, failure and
conflict outcomes write no event.

Missing-context creation uses a Library-scoped Item → Edition → Work query.
Library-row serialization makes equal concurrent creation idempotent and
different concurrent creation an explicit conflict. Term-row locks serialize
new-link validation against deactivation. Boeksoort lifecycle uses the same
Library-row lock for the last-active confirmation decision.

Separate-process coverage proves context-CAS winner/stale behavior,
equal/different concurrent context creation, normalized term-create and
rename/create conflicts, term-deactivate versus new-link serialization, and
the confirmed last-active Boeksoort decision. No schema change is part of this
management layer; formal schema version remains 1002.

#### LibraryCatalogContext during Item-add

Status: **Implemented**

All three existing `AddLibraryItemService` paths now accept one optional typed
`LibraryCatalogContextInitialization`. It is used only when the authorized
Library + Work has no context. In that case one active Boeksoort is required;
active Genres and Onderwerpen are optional duplicate-free sets. The shared
internal initializer reuses the F2.5.5c Library lock, locked term resolution,
active-link validation and unique context write instead of creating a second
classification path.

An already existing context is reused exactly as stored. Item-add ignores an
initialization intent in that case, including when its retained Boeksoort is
inactive: no classification write, version increment or context-created event
occurs. When the context was initially absent, a concurrent equal initializer
reuses the winner while a different desired classification returns the stable
context conflict.

Work, Edition, new context at version 1, Item and exactly one structured
`library_catalog_context.created` ActivityEvent commit through one existing
transactional boundary. Event failure and every other operational failure roll
back the complete compound write. The context event is appended only after the
Item write and captures the trusted server-side actor, related Work and
historical term IDs plus labels.

Initialization remains reachable only inside `libraryItemCreation()` and uses
`catalog.item_add`; `catalog.classification_manage` is neither required nor
granted. `CoreApplication` exposes no initializer, repository or trusted
context input. Formal schema version remains 1002 and no DDL or migration is
part of F2.5.6.

F2.5 preserves the F2.3 Work/Edition/Item model, explicit Library isolation and
Item→Edition→Work ReadingRound derivation. Metadata mappings,
REST/Abilities/UI, the Boeksoort request workflow, term merge/hierarchy and
term-level optimistic locking remain deferred. The complete criterion and
verification record is maintained in `docs/08-f2-5-exit-evidence.md`.

### F2.6 — ReadingRound lifecycle and historical truth

Status: **Implemented — GO**

ADR-007 fixes the production contract for active and ended ReadingRounds.
Ended outcome is completed or stopped; lifecycle is derived from the nullable
outcome and is not a second mutable truth. Normal active rounds start with
exactly one Item or ExternalLoan source. A manually registered historical
completed round is initially source-free and linked directly to Work; it is
never represented through a pseudo source. Identity, ownership, Work and
provenance remain immutable. Source is explicitly correctable for active and
ended rounds, including same-Work Item↔ExternalLoan, unknown→concrete and a proven
wrong concrete source→unknown when the correct source is no longer known.

New content dates preserve exact day, month+year or year precision as explicit
calendar components. Technical creation/update/end timestamps are separate
and never stand in for reading dates. Existing schema-1002 `started_at` values
remain untouched legacy content instants because their original timezone and
calendar intent cannot be reconstructed safely.

Finish, stop and ended correction use owner-scoped transactional CAS with
semantic no-op, stale-no-op and typed stale conflict behavior. Personal Work
read status and first-read/reread classification remain derived; uncertain
chronology is represented explicitly rather than resolved with technical
timestamps or IDs. ReadingRound IDs are issued server-side through a specific
generator with bounded primary-key collision retry.

Only a fully erroneous manually registered historical round may be hard
deleted, including when it names the wrong Work. A normal Biblio
start→end-round is corrected and never deleted. Deleting wrong manual history
and registering the right Work are separate operations; Work itself never
changes on a ReadingRound.

Production Core implements the complete contract through schema 1003, the
owner-scoped start/finish/stop/history/correction/delete services and derived
Work-status and reading-sequence projections. Both active start paths and
historical registration share the server-side generator and its bounded
primary-key collision retry. Wpdb persistence supplies locked owner reads,
compare-and-swap replacement, conditional historical deletion and User + Work
queries; no ReadingRound mutation is wired to Library ActivityEvent.

Migration 1002→1003 preserves legacy IDs, User, Work, source and `started_at`,
marks existing rows `legacy_source_started`, and introduces nullable outcome,
immutable provenance, explicit content-date components, technical timestamps
and positive optimistic version. Known already-applied migration state retries
idempotently before the version bump, and health validation covers the 1003
columns, generated active-source keys, indexes, foreign keys and checks.

The complete implementation and verification record is maintained in
`docs/09-f2-6b-exit-evidence.md`.

### F2.7 — Private Notes / Mijn notities

Status: **Implemented — GO**

Private Notes are user-owned, always private and linked to one immutable Work.
They may have zero or one correctable ReadingRound context; every linked Round
must be owned by the same user and concern the same Work. Active, ended and
historical Rounds are all eligible, multiple Notes may share a Work or Round,
and Library or platform roles never grant access.

Content uses the fixed safe HTML subset `p`, `br`, `strong`, `em`, `ul`, `ol`,
`li` and `blockquote`, without attributes. Core normalizes and validates this
contract server-side and repeats the same validation at the render boundary.
Links, media, embeds, scripts, styles, classes, arbitrary HTML and complex
blocks are rejected rather than silently retained or stripped.

Named create, content-update, context-correction/removal, conditional hard-
delete and owner-scoped read/list services use server-derived actor identity.
Real updates increment a positive optimistic version exactly once; semantic
no-ops return current state and divergent stale intent returns current safe
state through a typed conflict. Opaque Note IDs are issued server-side with
three bounded collision retries after the first attempt.

Schema 1004 adds `wp_biblio_private_notes` through migration 1003→1004 with
real Work and optional ReadingRound foreign keys, three bounded query indexes,
technical UTC timestamps and CAS version. Work deletion is restricted.
Legitimate F2.6 hard deletion of linked `historical_manual` ReadingRound truth
preserves the Note and nulls only its context through `ON DELETE SET NULL`,
without changing Note content, Work, timestamps or version.

Private Note mutations write no Library ActivityEvent and Notes are not added
to Timeline. The complete implementation and verification record is maintained
in `docs/11-f2-7b-exit-evidence.md`.

### F2.8 — Ratings & Reviews / Beoordelingen

Status: **Implemented — GO**

Rating, WrittenReview and ContributionPublication are separate Core aggregates.
Ratings use exact half-units 2–10; Reviews are normalized plain text of zero
through 5,000 Unicode code points and are escaped at every public boundary.
Both sources are user-owned, retain immutable Work and may have correctable
own same-Work ReadingRound context. Named owner-predicated services use CAS.

Publication is a separate Library-scoped lifecycle. Publish, republish and move
require active membership plus active Item representation of the source Work.
Membership loss preserves visibility; missing active Work presence suppresses
public output until presence returns. Author withdrawal and authorized Library
moderation remain independent and never transfer or expose source ownership.

Public DTOs contain only current display name, visible Rating where applicable,
escaped Review text and publication date. Personal averages count every own
Rating; public averages select the latest visible Rating per User with
`updated_at DESC, rating_id DESC`, sum half-units exactly and round only final
presentation.

Schema 1005 adds `wp_biblio_ratings`, `wp_biblio_reviews` and
`wp_biblio_contribution_publications` through formal migration 1004→1005.
Explicit historical Round deletion choices resolve linked contributions
atomically. No F2.8 mutation writes Library ActivityEvent or adds Timeline.
Independent-process MariaDB races prove one-winner creation/publication,
divergent/equal source CAS, publish-versus-delete, withdraw-versus-moderation
and eligibility-change serialization. Owner publication mutations use the
canonical source→Library→publication lock order.

The complete implementation and verification record is maintained in
`docs/13-f2-8b-exit-evidence.md`.

### B7 — Ratings & Reviews Library-public read boundary

Status: **Implemented — GO / UI FOUNDATION READY**

The personal Rating average now uses a dedicated owner + Work database
aggregate and therefore counts every current own Rating rather than inheriting
the 100-row display-list bound. Private and published Ratings both count;
Ratings belonging to another actor or Work do not.

The authenticated `biblio/v1` adapter exposes one bounded read route:
`GET /libraries/{library_id}/works/{work_id}/assessments`. Before any
contribution lookup, the application boundary resolves the actor server-side
and calls the existing Library-context boundary, which applies Core
`canViewCollection`. Unknown, inaccessible and inactive contexts remain the
same non-enumerating 404. Publication is not an internet-open concept.

The response contains one stable keyset-paged mixed list of currently visible
Rating and Review publications plus the Library + Work public aggregate.
Historical visible publications remain separate list entries; the aggregate
still selects only the latest currently visible Rating per User + Work using
`rating.updated_at DESC, rating_id DESC`. Hidden, removed and withdrawn
publications are excluded, active Work-representation loss suppresses output,
and Library isolation is enforced. Public DTOs expose no source owner ID,
ReadingRound identity, membership, moderation reason or private content.

Schema remains 1008. No owner Rating/Review REST writes, Ratings/Reviews UI,
Elementor/Crocoblock change or moderation-management UI is part of B7. The
complete evidence is in `docs/30-b7-ratings-reviews-public-read-evidence.md`.

### F2.9 — Hierna lezen / Next Reading

Status: **current contract correction and C7 adapter/screen — GO**

The former F2.9 targetmodel and exit remain historical evidence in docs 14–15
but are explicitly superseded. Current Core models stable entry identity,
mandatory Work, optional typed mutable preferred source and unrestricted
duplicates. Schema 1008 migrates every old Work/Item/ExternalLoan target
without resetting data and adds server-side owner-scoped Undo storage.

All list mutations use the same owner-state lock. Active Library Item and
ExternalLoan ReadingRound creation and match/consume share one transaction;
failed creation or required consume rolls back both. Automatic consumption
removes at most one deterministic match and creates no Undo or ActivityEvent.
Read projection exposes safe human context or a generic unavailable state, not
protected foreign source IDs.

The current normative architecture and verification record are maintained in
`docs/decisions/ADR-008-next-reading-intent-model-and-transactional-consumption.md`
and `docs/28-next-reading-contract-correction.md`. C7 now adds bounded
platform-wide Work search, actor-safe same-Work source options, nine private
`biblio/v1` routes, allowlisted serialization and the standalone
`[biblio_next_reading_app]` UI at the planned `hierna-lezen` Page slug. It
supports duplicates, Work-first add, preference set/change/clear, authoritative
Omhoog/Omlaag, direct remove plus server Undo, stale reconciliation, keyboard
focus/status and responsive reflow without a selected Library context.

Schema remains 1008 and the corrected Core/domain contract is unchanged. C7
adds no Home or start-from-entry UI, ActivityEvent, Crocoblock logic, permanent
WordPress Page or Elementor design. Formal build and exit evidence is in
`docs/29-c7-next-reading-exit-evidence.md`.

### F2.10 — Library Identity & Context Readiness

Status: **Implemented — GO**

Library now has a required stable identity consisting of its existing opaque
ID plus a persisted server-side name. `LibraryName` accepts valid UTF-8,
trims and collapses whitespace, is limited to 191 characters and is not
unique. Automatic personal Privébibliotheek provisioning uses the canonical
name `Mijn Bibliotheek`.

Schema 1007 adds `library_name` to `wp_biblio_libraries`. The forward-only
1006→1007 migration adds it through a recognized nullable intermediate state,
backfills missing/empty names with `Mijn Bibliotheek`, then makes it `NOT NULL`
and adds a non-empty CHECK. Retry, postcondition, data-health and real MariaDB
tests prove idempotence and drift detection. The same migration adds
`memberships_by_user (user_id, library_id)` for the actor-scoped switch query.

Production Core exposes one named `LibraryContextQueryService`. It derives the
actor server-side, lists only that actor's active memberships, returns ID,
name, type, status, designated-personal marker and server-calculated
capabilities, and resolves one explicit target Library ID non-enumerating.
Missing, foreign and inactive memberships are rejected; user ownership and
Library membership remain separate concepts.

F2.10 closes A1 from the readiness analysis. F2.11 bounded Catalog UI Read
Models and F2.12 REST adapter/security remain open. No REST route, Elementor
UI, general Library create/rename or membership lifecycle was added.

The implementation and verification record is maintained in
`docs/17-f2-10-exit-evidence.md`.

### F2.11 — Catalog UI Read Models

Status: **Implemented — GO**

Production Core exposes one adapter-independent `CatalogUiReadService` for
the first UI slice. Its overview accepts one explicit Library ID, a maximum
100-item page size and an optional typed cursor. It first resolves F2.10
Library Context for the authenticated actor and then projects active Items
only, ordered by Work title and Item ID. The page includes Library identity,
Item/Work/Edition identity, reliable Work title, active state, personal
Work-reading status and server-derived view/start capabilities.

Itemdetail uses the same scoped projection and fails non-enumerating for an
unknown or cross-Library Item. It adds owned ReadingRound counts for active,
completed, stopped and historical completed rounds. Reading status remains
derived: active wins, otherwise any completion means read, otherwise not read.
An active round blocks only presentation of start for that exact Item source;
the mutation must still re-authorize.

The current schema has no reliable Author, cover, ISBN, language, publisher,
publication-date, Series, Location, Condition, Acquisition or lending-state
source. The DTO therefore reports these as typed `unknown`, never as empty
strings or fabricated placeholders. Physical-book form and Library source name
are known. Missing, unknown and not-applicable remain distinct DTO states for
future compatible enrichment.

One SQL projection per overview/detail request joins active Item → Edition →
Work and actor-owned ReadingRound aggregates; it does not expose rows or
repositories. Cursor pagination is deterministic and the query plan uses
`items_by_library`; no N+1, cache or schema 1008 was introduced. Search,
archive, rich metadata, REST and Elementor remain out of scope.

The implementation and verification record is maintained in
`docs/18-f2-11-exit-evidence.md`.

### F2.12 — WordPress REST Adapter Foundation

Status: **Implemented — GO / ELEMENTOR READY**

WordPress now exposes the first UI slice under the versioned `biblio/v1`
namespace: available Library contexts, active catalog overview, scoped Item
detail and Start Reading from a Library Item. Every route is private, has an
explicit permission callback and resolves the current actor server-side.

Cookie-authenticated browser requests use WordPress's standard `wp_rest` nonce
in `X-WP-Nonce`. Library and Item URL identities remain untrusted targets and
are revalidated by F2.10/F2.11 or the existing Reading start service. Query or
body actor/capability claims never become authority.

Typed parsing covers IDs, bounded page size, opaque cursor and exact
`YYYY-MM-DD` start date. Named allowlist serializers expose only the F2.10,
F2.11 and minimal started-Round contract. One central mapper turns Core
failures into safe stable REST errors; inaccessible and unknown private
resources remain non-enumerating.

The adapter lives at the WordPress infrastructure boundary and contains no
membership, reading-status, availability, capability, source/Work or lifecycle
rules. Schema remains 1007. A1, A2 and A3 are now closed, so the next permitted
step is the first Elementor vertical slice—not broader endpoints or B/C scope.

The complete contract, security evidence and readiness decision are maintained
in `docs/19-f2-12-exit-evidence.md`.

### Elementor Vertical Slice 1A — Mijn Bibliotheek

Status: **Implemented — GO**

The first browser-facing slice is complete on the ordinary published Page
`/mijn-bibliotheek/`: active catalog overview, scoped Item detail, Start
Reading and an authoritative detail reread. URL state is limited to
`library_id` and `item_id`. The standalone Biblio UI plugin owns vanilla
ES-module browser state and presentation; Elementor remains one outer
container with one `[biblio_library_app]` Shortcode widget and no application
business logic.

The slice uses only the four existing private `biblio/v1` routes. Core still
owns authenticated actor, Library Context, authorization, source/lifecycle
rules and conflicts; UI visibility is not authorization. Search, filters,
Archive, Collections, Notes, Ratings/Reviews, Next Reading and other later
features were not added.

The exact Elementor 4.2.3/Pro 4.2.2 Page/Kit artifact clean-imports into an
isolated local database. Frontend, Core, Playwright, tenant-isolation,
fixture-cleanup, responsive and accessibility gates are green. Guarded local
fixtures close the former real-content browser condition and leave formal E2E
data at zero after acceptance.

The complete final verification record is maintained in
`docs/21-elementor-vertical-slice-1a-exit-evidence.md`.

### Vertical Slice 1B — ReadingRound detail and end support

Status: **Implemented — GO**

The private `biblio/v1` surface now contains five routes. The additional
owner-scoped mutation is:

- `POST /me/reading-rounds/{reading_round_id}/end`.

It accepts only `outcome` (`completed` or `stopped`), exact `finished_on` and
integer `expected_version`. The authenticated WordPress actor is the sole
identity source; no Library Context, membership or caller-supplied identity is
accepted. Dispatch goes to the existing `FinishReadingRoundService` or
`StopReadingRoundService`, so lifecycle, ownership, date, version, no-op and
stale behavior remain Core decisions. Unknown and foreign-owned rounds share
the same non-enumerating 404 response.

Itemdetail already exposes the actor-owned exact-Item active-round identity,
version and start date plus the presentation capability `end_reading`. The
end mutation returns only ended identity, lifecycle, outcome, finish date and
version.

The Biblio UI runtime strictly validates that detail projection and exposes an
internal `endReading({ outcome, finishedOn })` action to the visible UI.
The runtime snapshots ReadingRound ID/version only from the current validated
detail, sends one nonce-protected mutation, never retries it automatically and
always reconciles success, stale conflict, resource unavailability or a
malformed success acknowledgement through an authoritative detail reread.
Navigation abort marks the POST outcome as unknown and stale responses cannot
overwrite a newer route.

Itemdetail now renders `Leesronde afronden` only when the validated detail
projection has both `end_reading=true` and an active round. A native modal
requires an explicit completed/stopped choice and proposes the browser-local
calendar day without imposing a today maximum. The view submits only
`{ outcome, finishedOn }`; ReadingRound identity and version stay internal to
the runtime. Pending submission blocks duplicate dismissal and submission.
Success, conflict and non-availability close stale dialog state only after the
runtime's authoritative reread; nonce, authentication and uncertain outcomes
offer safe recovery without an automatic second POST or false success claim.
The compact dialog is centered on tablet/desktop and becomes a mobile bottom
sheet using the existing Biblio tokens and accessibility patterns. Elementor
and Crocoblock remain unchanged.

The existing guarded local Playwright layer now also proves completed and
stopped outcomes through the visible UI, one-POST pending behavior,
authoritative reread and persistence, reload/back/forward behavior, divergent
stale 409 reconciliation, invalid-nonce 403 without retry, non-enumerating
foreign/unknown 404 even for a Library manager, identical stale idempotency
and the current-version incompatible 422 lifecycle boundary. Independent
allowlisted records isolate those scenarios. The same fixture stack performs
the stale competing action through the owner-scoped Core service, exposes
read-only state evidence and verifies exact cleanup twice plus an unchanged
non-E2E Core/user fingerprint. Responsive and native-dialog keyboard evidence
covers 390x844, 900x900 and 1280x900.

The formal exit audit classifies all 67 acceptance criteria as proven. Fresh
Core, UI, REST/end-contract, Playwright, fixture safety, clean Elementor import
and repository gates are green; fixture residue is zero and the non-E2E
fingerprint is unchanged. No product code, schema, Elementor or Crocoblock
change was required for closure. The authoritative final verification record
is `docs/22-elementor-vertical-slice-1b-exit-evidence.md`.

### Vertical Slice 1C.2 — Reading History Core read model

Status: **Implemented — GO**

Core now exposes one named `GetMyReadingHistoryForWorkService` for the
authenticated user's personal, Work-wide Reading history. It returns only
ended `completed` and `stopped` ReadingRounds; active rounds stay outside this
history. The service accepts no owner override or Library Context, resolves the
actor server-side and delegates to a dedicated `ReadingHistoryReadRepository`.
Library ownership or management therefore never grants access to another
user's ReadingRounds.

The immutable read contract contains outcome, nullable precision-aware start,
required precision-aware finish, coarse current source type and the
`historical_manual` registration marker. It contains no User, Library, Work,
Item, Edition, ExternalLoan or ReadingRound identity, version, technical time
or raw provenance. A legacy UTC start is never converted to a local
`ReadingDate` and projects as `null`.

`WpdbReadingHistoryReadRepository` performs exactly one projection query per
page without aggregate hydration, joins or per-entry reads. It scopes every
page by exact actor + Work + ended outcome, orders by finish earliest DESC,
finish latest DESC and internal ReadingRound ID DESC, and uses a typed opaque-
transport-ready cursor with `LIMIT page_size + 1`. Page size defaults to 10
and is bounded at 50.

The unchanged existing index
`reading_rounds_by_user_work_finish(user_id, work_id, round_outcome,
reading_finished_year, reading_finished_month, reading_finished_day,
reading_round_id)` was proven against 50,000 temporary local rows. Both first
and cursor pages used `range` access on that index and read only the 600-row
actor+Work partition. The precision expressions require a filesort, but it is
bounded inside that partition and used a priority queue for `LIMIT 11`; no
table scan or unrelated User/Work scan occurred. All temporary rows and Works
were rolled back with zero residue.

Schema remains 1007. No migration, table, index, REST route, Itemdetail field,
Biblio UI, Elementor, Crocoblock or Playwright fixture changed in 1C.2. That
slice exposes only the Core application boundary; 1C.3 below adds the
separately approved private REST adapter.

### Vertical Slice 1C.3 — Reading History private REST read route

Status: **Implemented — GO**

The private `biblio/v1` surface now contains exactly six routes. The sixth is
the read-only owner-scoped
`GET /biblio/v1/me/works/{work_id}/reading-history` route. It uses the same
cookie plus `X-WP-Nonce` convention and thin F2.12 adapter boundary as the
existing routes, then delegates directly to
`CoreApplication::readingHistory()`. Actor identity is never accepted from the
request and Library ownership or management grants no access to another
user's Reading history.

The request accepts only typed `work_id`, optional `limit` (default 10,
maximum 50) and an optional dedicated versioned URL-safe history cursor. The
response keeps the existing `{ "data": ... }` envelope and contains only
`items` plus `next_cursor`. Each item allowlists exactly outcome, nullable
precision-aware start, required precision-aware finish, coarse source type and
the historical-registration flag. ReadingRound/source identities, actor,
Library, Work, version, raw provenance and technical timestamps remain absent.

Unknown valid Works, no own history and foreign-only history are deliberately
equivalent empty 200 pages. Malformed request values are 400, unauthenticated
requests are 401, invalid cookie nonces use WordPress's standard 403, Core
unavailability is 503 and unexpected failures remain safely generic. Cursor
pages are re-scoped by current actor plus URL Work on every call. Itemdetail is
unchanged and contains no embedded history.

Schema remains 1007. No migration, lifecycle mutation, Biblio UI, Elementor,
Crocoblock or E2E fixture is part of 1C.3. The route performs no writes or
Library ActivityEvent/audit side effect.

### Vertical Slice 1C.4 — Reading History UI

Status: **Implemented — GO**

The existing Itemdetail now renders a subordinate private Reading history
region after the current reading summary/actions and before bibliographic
metadata. Only the strictly validated `work_id` from the authoritative detail
response is used for
`GET /me/works/{work_id}/reading-history?limit=10`; history is separate runtime
state and never becomes part of `currentDetail`.

The valid Itemdetail renders before history loads and remains usable when the
history request fails. Loading, error/recovery, pagination and refresh states
are local to the subordinate region. An empty successful page removes that
region without rendering a `Leesgeschiedenis` heading. Non-empty results use
a semantic list, textual `Uitgelezen`/`Gestopt` outcomes and Dutch date copy
that preserves exact, month or year precision without timezone conversion or
invented date parts. Only `Externe lening` and, with presentation priority,
`Historische registratie` add coarse source context; Library/unknown sources
produce no speculative copy.

`Meer laden` follows only the opaque validated cursor, allows one request at a
time, retains existing entries on failure, appends server order and provides
explicit same-cursor retry plus controlled focus. The detail navigation
generation and shared AbortSignal prevent old Work responses from updating a
new route, including back/forward navigation and direct-link reloads.

A successful Start Reading authoritative reread preserves the existing ended
history without an unnecessary duplicate history GET. After End Reading, the
runtime first completes the authoritative detail reread and then fetches
history page 1 again, replacing the list and cursor only with GET truth. It
never appends from the POST acknowledgement or retries the mutation. If that
history refresh fails, authoritative detail remains visible while the old
history is explicitly marked as not refreshed and can be retried locally.

Schema remains 1007. No Core, REST, Elementor, Crocoblock, E2E/Playwright,
schema or migration change is part of 1C.4.

### Vertical Slice 1C.5 — Reading History responsive and accessibility pass

Status: **Implemented — GO**

The 1C.4 Reading history behavior and product semantics are unchanged. The
formal frontend pass now proves one-column reflow at the existing mobile,
tablet and desktop breakpoints, including a structural 320 CSS-pixel/200%
zoom contract: no fixed history width/height, truncation, nowrap, absolute
positioning, horizontal overflow, animation or transition was added. Long
exact/month/year periods, null-start copy, long local errors and ten entries
remain complete and wrapping. The empty stable region still has no residual
layout.

The history uses native `ul`/`li` semantics with visible list-item behavior,
one H2 only for non-empty history and native buttons/links with visible text
as their accessible names. Existing Biblio tokens supply spacing, surfaces,
44 px controls, focus ring and colors; text, muted-surface, secondary-control
and focus colors meet the tested contrast thresholds. Mobile load-more and
history recovery controls use the existing full-width control pattern, while
tablet/desktop retain the readable detail-column maximum.

The stable history region owns `aria-busy`. Short loading, pagination,
completion and error messages use scoped polite live text without assigning a
second `role=status`, so they do not compete with the existing Itemdetail and
Reading mutation status contract or announce the complete list. Initial load,
initial error/retry and automatic post-End refresh never request focus.
Successful pagination focuses the newly connected replacement control; when
that control disappears, the connected history H2 receives programmatic
focus with `tabindex=-1` and stays outside the normal tab sequence. Pagination
failure focuses its local recovery control. No history item becomes focusable.

The post-End refresh error now states that the reading status is updated while
history could not be refreshed. It contains no technical detail and does not
steal the existing End Reading reconciliation focus. The unchanged guarded
1A/1B Playwright regression passes all 13 cases after proving fixture guards,
double cleanup, zero residue and an unchanged non-fixture fingerprint; no 1C
case or fixture was added.

Schema remains 1007. No Core, REST, Elementor, Crocoblock, E2E/Playwright
file, schema, migration, Reading History semantic or Start/End Reading
semantic changed in 1C.5.

### Vertical Slice 1C.6 — Reading History browser and integration evidence

Status: **Implemented — GO**

The guarded local Playwright layer now contains a separate eleven-case
Reading History spec. Its deterministic fixture reuses the two existing E2E
Libraries and identities, keeps the original 1A actor-Library overview at
nine Items, and places eight history-specific Items in the already accessible
other Library. One shared Work covers the primary Item, a second Item on the
same Edition, another Edition, ExternalLoan, source-free historical, legacy,
active-plus-ended, foreign-owner and thirteen-row pagination cases. Separate
Works isolate zero history, active-only, End refresh, refresh failure and
rapid navigation.

Browser assertions prove the semantic H2/list, completed/stopped and source
copy, exact/month/year/null-start precision, Work-wide entries, actor privacy,
10+3 opaque-cursor pagination without duplicates or skips, final-heading
focus, initial and page-two retry, invalid-nonce recovery, direct links,
reload/back/forward and a deliberately delayed stale response. The End flow
observes `POST end` followed by authoritative `GET detail` and then page-one
`GET history`; while that history response is held, no new entry appears.
Refresh failure preserves ended detail and retained history, and explicit
retry sends only another history GET.

The 390×844, 900×900 and 1280×900 browser matrix proves native controls,
one H1, one history H2, `ul`/`li`, 44 px targets and no document or history
horizontal overflow. Initial history loading uses polite live text and does
not move focus; End refresh retains the existing mutation-status focus.

The complete Playwright run passes 24/24: all original 1A/1B cases pass 13/13
unchanged and Reading History passes 11/11. Four environment/host/project/
opt-in guards refuse correctly. Both cleanup runs report zero for Libraries,
Items, Works, Editions, ExternalLoans, ReadingRounds, memberships,
designations, catalog contexts, classification terms, activity events and
users; the non-fixture fingerprint is identical before and after.

Schema remains 1007. No Core, REST, Biblio UI product code, Elementor,
Crocoblock, schema or migration changed in 1C.6. At that implementation step,
formal exit evidence was deliberately deferred to 1C.7; it is closed below.

### Vertical Slice 1C.7 — Formal Reading History exit

Status: **Complete — GO**

The formal 2026-08-31 exit audit proves all 149 acceptance criteria: 149
BEWEZEN, 0 GEDEELTELIJK BEWEZEN and 0 NIET BEWEZEN. There are no blockers.
The actor-owned Work-wide ended-history, date-precision, keyset pagination,
bounded one-query projection, private REST allowlist, independent UI state,
navigation isolation and Start/End authoritative reconciliation contracts are
all green.

Fresh verification passes 242 Core unit tests/919 assertions, 219 Core
integration tests/2005 assertions, the focused Reading History suite at
7/157, REST at 28/425, all 135 frontend tests and all 24 Playwright cases.
Four fixture guards refuse correctly; cleanup is zero and idempotent; the
non-fixture fingerprint is unchanged. The existing Elementor artifact keeps
SHA-256 `4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a`
and passes a fresh clean import. Crocoblock remains unused.

Responsive acceptance is GO. Accessibility is GO within the Vertical Slice
contract, with a full WCAG audit retained only as a non-blocking future
assurance activity. No product code, route, UI feature, browser scenario,
schema, migration, Elementor or Crocoblock change was required for 1C.7.

The authoritative final record is
`docs/24-elementor-vertical-slice-1c-exit-evidence.md`.

### Vertical Slice 1D.1 — Private Notes readiness and contract

Status: **Analysis complete — READY WITH CONDITIONS**

The F2.7 contract is reconstructed and remains binding: Private Notes are
server-authorized, user-owned, always private, Work-wide aggregates with
optional ReadingRound context, strict safe HTML, conditional hard delete and
optimistic concurrency. Library Context and Library/platform roles grant no
access; Note mutations create no Library ActivityEvent or Timeline entry.

F2.7 explicitly permits multiple Notes per User + Work and per ReadingRound.
Vertical Slice 1D must therefore expose a Work-wide collection of separately
identified Notes; a singular `/me/works/{work_id}/note` upsert or one-Note UI
would conflict with the closed product contract. Schema 1004 persistence is
ready and the current schema remains 1007. No schema or domain delta is needed.

The normal remaining work is an adapter-facing render-validated Note page,
thin owner-scoped REST collection/member routes, exact error/cursor mappings
and Biblio UI list/editor/dialog/reconciliation state. Before implementation,
the recommended multi-Note presentation, hierarchy/zero state, dirty-state UX
and delete response/copy require explicit approval. The exact readiness,
security, REST, UI, E2E and 1D.2–1D.7 contracts are recorded in
`docs/25-elementor-vertical-slice-1d-private-notes-readiness.md`.

1D.1 changes documentation only. It adds no product code, Core/REST/UI,
Elementor/Crocoblock, E2E/Playwright, schema, migration or functionality.

### Vertical Slice 1D.2 — Private Notes application read boundary

Status: **Implemented — GO**

All 1D.1 product conditions are now locked: Work-wide multiple separate Notes,
the visible multi-Note presentation and zero state, dirty-state choices and the
accessible hard-delete dialog contract. 1D.2 does not implement UI behavior.

Core now exposes `GetMyPrivateNotesForWorkService` through `CoreApplication`.
It resolves the actor server-side, performs the existing bounded owner+Work
query once and returns `PrivateNoteViewPage`: each `PrivateNoteView` contains
only opaque Note identity, render-validated safe HTML and optimistic version.
The nullable internal cursor contains only the existing `updated_at` and Note-
ID ordering keys for later opaque REST encoding.

Ordering remains `updated_at DESC, private_note_id DESC`; default page size is
50 and maximum 100; the repository keeps keyset pagination and `limit + 1`.
Projection/rendering is in-memory, so one page is one SQL query with no N+1.
Unsafe stored or compromised content fails the existing validation/render
boundary and cannot become an adapter view.

Existing create-without-Round, member read, update/no-op/stale and conditional
hard-delete services are reused unchanged. Schema remains 1007. No REST route,
controller, HTTP/JSON contract, UI, Elementor, Crocoblock, Playwright,
ActivityEvent, private audit, schema or migration is added. 1D.3 is READY.

### Vertical Slice 1D.3 — Private Notes REST API

Status: **Implemented — GO**

The `biblio/v1` adapter now exposes four owner-scoped Private Note operations:
GET and POST on `/me/works/{work_id}/private-notes`, and PATCH and DELETE on
`/me/private-notes/{private_note_id}`. They reuse the standard cookie plus
`X-WP-Nonce` authentication convention and call only named `CoreApplication`
services; no repository or wpdb access crosses the REST boundary.

GET returns the exact `items`/`next_cursor` page allowlist with Core default 50,
maximum 100 and a dedicated version-1 URL-safe opaque cursor. Each item contains
only `private_note_id`, render-validated `content_html` and `version`. POST
accepts only `content` and returns 201; PATCH accepts only `content` plus
`expected_version` and returns 200 authoritative state; DELETE accepts only
`expected_version` and returns 204 without content.

Strict parsing rejects malformed IDs, query/body types, unsupported fields and
invalid cursors. Foreign-only collections remain 200 empty; unknown, foreign
and deleted members share one generic 404; stale update/delete maps to 409;
Core content validation maps to 422; unavailable Core maps to 503; unexpected
failures remain privacy-safe 500. Cursors never authorize and actor+Work scope
is reapplied on every page.

Schema remains 1007. No Core application/domain, persistence query,
schema/migration, Biblio UI, Elementor, Crocoblock, Playwright, ActivityEvent,
private audit or ReadingRound product behavior changed. 1D.4 is READY.

### Vertical Slice 1D.4 — Private Notes UI

Status: **Implemented — GO**

The existing Item detail now renders `Privénotities` after
`Leesgeschiedenis` and before `Uitgave`/`Exemplaar`. The section is always
present, uses authoritative Work identity only after successful Item-detail
validation and loads an independent Work-wide multi-Note collection at an
explicit UI page size of 10. Server order and opaque continuation cursors are
preserved; zero state creates no card or persisted draft.

One constrained editor can be active at a time. It supports exactly paragraph,
line break, strong, emphasis, unordered/ordered list and blockquote semantics.
Rich paste is reduced to plain text; the deterministic serializer rebuilds
only the Core allowlist without attributes and fails closed for unsupported
DOM. Saved HTML is independently validated and reconstructed inside the Note
body; raw unsaved input never becomes authoritative read state.

Create, update and conditional hard delete use the existing nonce-protected
REST contracts, one-in-flight locks and server versions. Every successful
mutation is followed by a fresh page-1 GET; a failed reconciliation never
repeats the mutation and retains the best confirmed local state with GET-only
recovery. Divergent 409 and unavailable 404 states never overwrite or delete
silently. Dirty state is canonical-serializer based, protects Cancel and app
navigation with a native dialog and registers native `beforeunload` only while
dirty. Delete uses the locked accessible dialog copy and no undo.

Focused Private Notes frontend verification passes 29 tests; the complete
Biblio UI suite passes 167 tests. Start/End Reading passes 25 tests, Reading
History 8 and app runtime/detail/router 74. UI PHP/JS syntax, isolated UI smoke,
Private Notes REST 11/311, complete `RestApiTest` 39/747, manifest JSON and Git
whitespace are green. No Core, REST route/contract, schema/migration,
Elementor/Crocoblock or Playwright change was made. 1D.5 is READY for the
dedicated responsive/accessibility polish and acceptance pass only.

### Vertical Slice 1D.5 — Private Notes responsive/accessibility polish

Status: **Implemented — GO WITH CONDITIONS**

The 1D.4 product, CRUD, serializer and information-hierarchy contracts are
unchanged. Private Notes remain in the existing readable detail column with
mobile `<768px`, tablet `768–1023px` and desktop `>=1024px`. Root-scoped CSS
now makes region/editor width and long-content wrapping explicit, preserves a
wrapping toolbar/actions at 320 CSS px and effective 200% reflow, and keeps the
mobile bottom sheet within dynamic viewport height with safe-area-aware
padding. No fixed Note/editor width, nowrap, ellipsis, hidden content overflow,
new palette or motion was introduced.

The existing H2, zero state and native list remain exact. The visible
multiline textbox is programmatically tied to its label, help, dirty and field-
error descriptions. Toolbar toggles are native buttons with accessible names
and updated `aria-pressed`. Short local polite messages remain subordinate;
the list is never live. The 44px pointer target, 2px focus and tested contrast
tokens remain authoritative.

Focus now targets connected replacement DOM: create/edit editor; Add/Edit after
clean or discarded Cancel; editor after dirty retain; scoped confirmation
across save/delete reconciliation; continuing load-more, pagination retry or
the Notes H2 after the final page; and the Notes H2 only after a stale delete
refresh closes its modal. Native dialog naming, description, safe initial
focus, keyboard containment, idle Escape, pending locks and return focus are
preserved. `beforeunload` still exists only while canonically dirty.

Private Notes frontend passes 34 tests, the complete UI 172, runtime/detail/
router/design 84, Start/End 25 and Reading History 8. UI JS/PHP syntax,
PHPStan, isolated smoke, Private Notes REST 11/311, complete REST 39/747 and
Core/WordPress smoke are green.

A safe fixture-free browser smoke measured no shell overflow and at least 45px
retry controls at 320, 390, 640 effective-width, 900 and 1280 CSS px. The
available browser was not authenticated, so real Notes content, actual 200%
zoom, mutation/dialog flows and guarded cleanup/fingerprint evidence are not
claimed; they remain 1D.6. There is no known structural blocker. **1D.6 is
READY**, with that real browser/fixture evidence as the sole explicit
condition.

### Vertical Slice 1D.6 — guarded authenticated Private Notes browser evidence

Status: **Implemented — GO WITH CONDITIONS; 1D remains OPEN**

The existing guarded WP-CLI fixture and authenticated Playwright storage state
now cover Private Notes without a second framework or tracked credentials. The
shared 1A–1C graph is reused unchanged and setup adds exactly 21 synthetic
Notes: seven isolated scenario Notes, thirteen actor-owned pagination Notes and
one foreign-owned same-Work Note. No new Work or Item is required. Five guard
tests fail closed for missing opt-in, non-local WordPress, wrong project, wrong
host and cleanup with a non-E2E username.

Sixteen new one-worker Chromium tests prove zero/create/edit/no-op/dirty/delete,
overview/Item/Back navigation, `beforeunload` registration, stale 409 and
unavailable 404 behavior, owner privacy and Library-role non-override,
pagination/error retry, mutation-refresh failure, safe rich content/XSS/paste,
keyboard/focus/semantics and authenticated responsive behavior at 320, 390,
640 reflow-equivalent, 900 and 1280 CSS px. Every critical mutation occurs
exactly once and reload/reconciliation uses public UI/REST truth.

Two browser-found UI defects received minimal fixes and regressions: retaining
a dirty popstate no longer destroys its pending Back destination, and editor
focus lost after native keyboard activation is restored only when the browser
drops focus to the document body. The existing 1C `Meer laden` test is scoped
to Reading History so it coexists with the Notes pagination control. No Core,
REST contract, schema/migration, Elementor or Crocoblock delta exists.

Private Notes browser tests pass 16/16; existing 1A–1C pass 24/24; total
Playwright passes 40/40 on one worker. Private Notes frontend is 35/35, full UI
174/174, focused REST 15/328 and complete REST 39/747. Syntax, UI PHPStan,
isolated UI smoke and Core/WordPress smoke pass. Cleanup is idempotent, leaves
all fixture counts at zero and preserves the exact non-E2E SHA-256 fingerprint.

Exact 200% browser zoom is not claimed: headless Chromium stayed at
`innerWidth=1280`, DPR 1 and visual scale 1 after zoom shortcuts. Authenticated
640px reflow-equivalent and 320px evidence is green, but a headed manual 200%
smoke remains. Consequently 1D.6 is **GO WITH CONDITIONS**. **1D.7 is READY
WITH CONDITION** for only that headed zoom smoke plus formal exit/audit work;
no exit document exists yet and Vertical Slice 1D remains open.

### Vertical Slice 1D.7 — formal Private Notes exit

Status: **GO — Vertical Slice 1D CLOSED**

The sole 1D.6 condition is closed by the user's manual local authenticated
headed-browser acceptance at actual 200% browser page zoom with real Private
Notes content. Notes remained readable and usable without problematic
horizontal overflow; create/edit controls, dialogs and keyboard/focus behavior
remained usable. This is manual browser evidence, not Playwright proof.

The formal exit reconfirms the user-owned Work-wide multi-Note contract,
Core/REST/UI boundaries, privacy/non-enumeration, safe-format allowlist,
optimistic concurrency, semantic no-op, no mutation retry, authoritative
pagination, dirty state, dialogs, keyboard/focus and responsive behavior.
Fresh gates pass: frontend 35/35 and 174/174, REST 15/328 and 39/747,
Playwright 16/16 for 1D and 40/40 total on one worker, syntax, PHPStan, UI/Core
smokes, five fixture guards, double cleanup, zero residue and identical
non-E2E fingerprint.

There are no 1D blockers. Non-blocking presentation polish is registered in
`docs/26-future-roadmap-decisions.md`; no CSS or product behavior changes in
1D.7. The authoritative exit record is
`docs/27-elementor-vertical-slice-1d-private-notes-exit-evidence.md`.

### Mijn Bibliotheek — Design System implementation slice

Status: **Implemented — GO WITH EXPLICIT DEFERRED CAPABILITIES**

The existing Core-backed `Mijn Bibliotheek` application now renders inside an
Ink/Light Deep Library shell. Desktop has a 224px Classic Sidebar with a
remembered 72px rail; tablet and mobile recompose to rail/off-canvas navigation.
The overview defaults to a 148px-cover Grid and provides a working List view,
an explicit Bookshelf placeholder and a native right-overlay Quick View backed
by the existing scoped Item-detail GET. Semantic tokens isolate Theme,
Appearance and future Atmosphere roles; only the personal sidebar collapse
preference is stored locally.

Search, filter values, active chips and alternate server sort remain deferred.
The current overview REST contract supports only cursor pagination, while the
Slice 1A contract explicitly excludes Search and Filters. Controls are present
but disabled/explanatory rather than falsely operating on one fetched page.
Bookshelf remains a placeholder because cover-ratio/spine behavior is open.
Exact palette/font/icon/Atmosphere values also remain non-canonical work.

No Core, REST, schema, authorization, domain behavior, Elementor Page or theme
template changes were made. Full scope, collisions, non-scope and acceptance
mapping are recorded in
`docs/32-mijn-bibliotheek-design-system-slice.md`.

### Mijn Bibliotheek — server-side catalog query readiness

Status: **Finalized — PRODUCT GO / TECHNICAL SOURCE READY**

The readiness analysis for full-catalog server-side Search, Filters, Sort and
query-bound keyset pagination is recorded in
`docs/33-mijn-bibliotheek-server-side-catalog-query-readiness.md`.

The final contract fixes live partial case-/accent-insensitive Search, omnibus
matches through contained canonical metadata, OR within filter groups and AND
between groups, direct filter application, removable chips/reset, complete
Collection-filter semantics, URL plus session state, `Titel A–Z`, `Auteur A–Z`,
conditional `Serievolgorde`, and one mixed active/archive result list with
`Archief` labels. Search relevance remains authoritative while Search is active.

The first implementation wave is now fixed as Leesstatus, Auteur, Serie,
Locatie, Boeksoort, Genre, Onderwerp, Collecties and `Zonder collectie`.
Taal, Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds` remain in the
broader v2.001 design as **deferred within v2.001**; they are not removed or
future-version-only. No product decision remains for the first wave.

Technical source readiness is complete. Schemas 1009–1013 provide the central
Author/Series, remaining Search-metadata, Item Location, Archive and
Collection/membership foundations. The reusable Leesstatus and
LibraryCatalogContext filter/read boundaries are closed in Slice 5 below.
Document 33 records the now-completed Slice 6 typed query composition and the
remaining Slice 7 transport/UI boundary.

The completed technical foundations do not enable REST, UI, Elementor or new
catalog-query behavior. The existing disabled controls and current active
title-ordered cursor overview remain authoritative until a separately approved
implementation scope closes the documented product and data prerequisites.

### Library Item Location foundation

Status: **GO / CLOSED**

Schema `1011` provides Library-owned typed Locations, an optional tenant-safe
Item relation and authorized batch reads. No canonical free-text Location
source existed, so existing Items remain losslessly unlocated. The evidence is
recorded in `docs/36-library-item-location-foundation-exit-evidence.md`.
Archive lifecycle was the next prerequisite and is now closed below.

### Library Item Archive lifecycle foundation

Status: **GO / CLOSED**

Schema `1012` adds explicit `active`/`archived` Item state, optimistic Item
versioning and historically retained archive periods with reason and microsecond UTC
timestamps. Archive and restore retain the same Item, Edition, inventory and
Location identities. Owner and Beheerder management is authorized server-side;
batch lifecycle/history reads require validated Library Context and remain
tenant-scoped. Current-source reads reject archived Items, while no private
ReadingRound or other user-owned record is changed.

The active-InternalLoan archive guard is currently vacuously satisfied because
InternalLoan persistence and lifecycle do not yet exist. Integrating that guard
and the special loan-settlement routes remains explicitly deferred to the
lending foundation; no lending behavior was introduced here. Evidence is in
`docs/37-library-item-archive-lifecycle-foundation-exit-evidence.md`.
Collection membership history now connects to this archive transition as
described below.

### Library Collection and membership foundation

Status: **GO / CLOSED**

Schema `1013` adds Library-owned active/archived Collections and historical
membership periods between Collections and same-Library Items. Collections and
their Items have explicit manual order. Active normalized names are unique per
Library; an Item can be active at most once per Collection while a later
explicit re-add creates a new period.

`ManageLibraryCollectionsService` resolves the actor server-side and requires
Owner authority or the canonical Beheerder permission `collections`.
`LibraryCollectionQueryService` validates Library Context before tenant-scoped
batch reads. Missing, foreign and unauthorized Collection resources share a
non-enumerating availability failure.

Collection archive preserves membership rows and Item state. Item archive now
ends every active membership with reason `item_archived` in the same
transaction; active reads/counts exclude the Item. Item restore never
reactivates prior membership. Collection timestamps change only through
Collection/detail/order/content management, not through Item metadata,
Location, cover, reading status or Item archive.

No REST, frontend, Elementor, Collection UI, catalog-query composition,
Condition, Acquisition or lending behavior is introduced. Evidence is in
`docs/38-library-collection-membership-foundation-exit-evidence.md`. The next
prerequisite was the existing-source filter/read foundation from doc 33 and is
now closed below.

### Mijn Bibliotheek — existing-source filter/read foundation

Status: **GO / CLOSED — READY FOR TYPED CATALOG QUERY COMPOSITION**

Schema remains `1013`. Existing Author/Series, Search metadata, inventory,
Location, Archive and Collection read contracts were already batch-capable and
remain unchanged. Slice 5 adds the two missing narrow boundaries: authorized
Library-scoped active classification options plus batch Work classification,
and actor-private batch projection of the existing Work reading status.

Classification batches preserve explicit missing context, keep Boeksoort,
Genre and Onderwerp typed and separate, and execute in three queries rather
than per Work. Personal reading status derives from owned ReadingRounds in one
query, remains Work-level and never becomes Library-owned. Both batch inputs
are bounded to 100 Works. No existing Archive, Collection, Rating/Review, Note,
Next Reading or Goal state is changed.

No schema/migration, combined catalog query, Search/filter execution, sort,
cursor, REST, frontend or Elementor behavior is introduced. Evidence is in
`docs/39-existing-source-filter-read-foundation-exit-evidence.md`. At Slice 5
exit, the next technical slice was Slice 6; it is now closed below.

### Mijn Bibliotheek — typed catalog query composition

Status: **GO / CLOSED — READY FOR CATALOG TRANSPORT / REST INTEGRATION**

`CatalogQueryService` is now the single Core application boundary for the
server-side `Mijn Bibliotheek` result set. Its immutable typed query composes
active/archive scope, Search, the exact first-wave filters, the three canonical
sorts, a bounded page size and an actor-/Library-/query-bound opaque cursor.
Primary SQL selection remains Item-based and tenant-scoped; one-to-many sources
use semijoins and the selected page is batch-enriched with Authors, Series,
Location, classification, active Collections and the current actor's reading
status. Item identity therefore remains unique and ownership stays separated.

Schema remains `1013`. Representative 1,000- and 10,000-Item MariaDB plans use
the existing `items_by_library_status_location` index; no new index or
migration was required. A fully enriched first page uses 15 database calls and
continuation uses one additional anchor-resolution call, with no call per Item.
REST parsing/serialization, URL/session behavior, frontend controls and
Elementor remain Slice 7. Suggestion/recommendation engines remain a separate,
unimplemented feature layer. Evidence is in
`docs/41-typed-catalog-query-composition-exit-evidence.md`.

### Mijn Bibliotheek — catalog REST transport foundation

Status: **GO / CLOSED — READY FOR MIJN BIBLIOTHEEK UI QUERY INTEGRATION**

`GET /biblio/v1/libraries/{library_id}/catalog` now provides a strict typed
read adapter over `CatalogQueryService`. It exposes only the canonical Search,
nine first-wave filter groups, three sorts, Core page size, active/mixed archive
scope and opaque query-bound cursor. Library Context and actor remain
server-authorized; the response is explicitly allowlisted and actor-private
reading status remains isolated.

Schema remains `1013`. The existing `/items` overview and all earlier REST/UI
contracts remain unchanged. No frontend, browser URL/session state, Elementor,
mutation or suggestion engine is included. Evidence is in
`docs/42-catalog-rest-transport-foundation-exit-evidence.md`.

### Metadata provider benchmark — research evidence only

Status: **COMPLETED / DECISION EVIDENCE**

A reproducible read-only benchmark against 300 unique valid ISBNs from the
explicitly designated current Biblio V1 dataset measured Open Library, Google
Books, Wikidata and BookBrainz. The result is research evidence only: no
provider, Metadata Hub behavior, adapter, schema, runtime or product choice is
introduced by this benchmark.

The minimum free evidence stack is assessed as conditional rather than approved
for implementation. Open Library is strong for English Work/Edition evidence
but weak for Dutch ISBN coverage; Google Books materially fills coverage but
requires renewed legal/licensing review before commercialization and may not
become a Core dependency. Wikidata and BookBrainz showed insufficient
incremental v2.001 coverage in this sample. Full methodology, normalized rows,
machine-readable metrics, reviews and recommendation are under
`tools/metadata-benchmark/output/`, starting with
`metadata-benchmark-summary.md`.

### Metadata Hub and Series Intelligence decisions

Status: **LOCKED DESIGN / NOT RUNTIME IMPLEMENTED**

The approved metadata strategy is now provider-neutral and local-first. ISBN is
Edition evidence; confirmed canonical data is never silently overwritten.
The v2.001 target uses conditional Open Library evidence with Google Books only
as a temporary conditional fallback, presents whole-record candidates for
review, preserves minimum provenance and keeps manual/no-ISBN plus Biblio-owned
cover paths first-class. Wikidata and BookBrainz are NO-GO for v2.001 runtime.
Google use has a mandatory current-terms and replacement review before a
commercial release. The normative architecture is ADR-010 and the product
contract is in `docs/01-functional-design.md` §4.

Series Intelligence is now a multidimensional product model rather than one
universal `hoofddeel`/`novelle`/`companion`/`spin-off`/`omnibus` role list.
Series kind, membership state, group, descriptive relation, order, position,
lifecycle, Series relationships and derived coverage remain separate. The
existing schema-1009 Work→Series relation is only the implemented minimal
foundation; no Series Intelligence schema, runtime, provider mutation or UI is
added.

Post-`bdd5714` decisions now define core/supplemental evidence, private
`user-confirmed`/`user-rejected` versus Biblio-wide `canonical-confirmed`,
trusted central verification rights, released-member completeness, three
separate coverage metrics and Library/cross-Library/platform-wide scopes.
The v2.001 target permits at most one primary confirmed order. Future order
schemes must reuse membership identity. A future Library-bound Series collection
goal and the existing platform-wide Series reading-goal concept remain separate
frozen targetsets; the new collection-goal runtime is not in scope here.

The v2.001 Metadata conflict contract remains whole-record candidate selection:
identity-critical differences are reviewable, publication characteristics may
be non-blocking and enrichment conflicts never block book import. There is no
field-level merge UI or provider fusion. Current product truth is in
`docs/01-functional-design.md` §4 and §11. Remaining open implementation and
governance choices are registered in `docs/26-future-roadmap-decisions.md`.

### Metadata Hub technical readiness

Status: **SUPERSEDED BY MH-B1 IMPLEMENTATION BELOW**

The concrete repository integration, schema/API impact, local-first algorithm,
provider-neutral candidate contract, Open Library → conditional Google
orchestration, provenance, cache/error/security policy, test matrix and five
implementation slices are fixed in
`docs/43-metadata-hub-technical-readiness-and-implementation-design.md`.

Schema remains 1013 and no runtime behavior was added. A later implementation
requires a minimal next schema with an Edition canonical-ISBN claim and accepted
candidate provenance. It also requires a read-only duplicate audit before
migration. The current repository has no metadata provider client, lookup/add
REST flow, rich Edition/cover persistence or end-user manual/no-ISBN add flow.
Those facts are explicit readiness conditions, not silently assumed features.
Series hints remain out of the v2.001 Hub.

`Biblio Library Intelligence` is the overarching direction, with Biblio Lens
and Series Intelligence as its first two pillars. v2.001 promises only the
first ISBN/Edition Intelligence maturity, not the full Lens roadmap.

### Metadata Hub MH-B1 — ISBN identity foundation

Status: **GO / FOUNDATION READY FOR PROVIDER ADAPTERS**

Schema `1014` is active. ISBN-10 and ISBN-13 now share one typed normalization,
checksum and conversion contract; a valid ISBN-10 and its 978 ISBN-13 alias map
to one canonical ISBN-13 identity. Valid 979 identifiers remain ISBN-13-only.
Unknown ISBN and explicit no-ISBN remain distinct valid Edition states; invalid
input is typed validation, not no-ISBN.

`biblio_edition_identifier_claims` is the database authority that permits one
canonical ISBN-13 claim per Edition and one Edition per canonical ISBN-13.
Migration audits existing Edition metadata before table creation, fails closed
on invalid/conflicting/equivalent duplicates, and backfills only safe claims
without changing Edition, Work or Item IDs. The read-only maintenance audit is
`web/wp-content/plugins/biblio-core/scripts/isbn-integrity-audit.php`.

`LocalEditionResolver` returns `local_exact`, `local_none` or legacy-only
`local_ambiguous`. All ISBN-bearing new-Edition paths in
`AddLibraryItemService` use this boundary. A unique-claim race rolls the losing
compound transaction back and then adds its Item against the winner's existing
Edition and Work. Existing-Edition, manual and no-ISBN paths remain available;
title/author similarity is never used to merge Works.

Schema 1014 also creates the empty structural provenance table with provider
key, provider record ID, retrieval time, exact match method, queried identifier
and confirmation state. No provider, provider request, cache, provenance write,
REST lookup route, UI, cover or Series Intelligence runtime is implemented.
Detailed evidence is in
`docs/44-metadata-hub-mh-b1-isbn-identity-exit-evidence.md`.
