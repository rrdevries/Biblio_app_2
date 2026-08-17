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

## Next phase

**Fase 1 — Biblio Core fundament stabiliseren**

Initial technical attention points are limited to:
- distinguish spike-only code from production-worthy code;
- stabilize module boundaries and naming;
- define the migrations/install/upgrade lifecycle;
- stabilize application-service contracts;
- establish a consistent exception model;
- review transaction boundaries;
- extend the authorization matrix where the next implemented behavior requires it;
- establish logging and audit boundaries.

This does not yet define the complete Fase-1 implementation.

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

Automatic plugin activation/upgrade wiring remains work for F1.3.

See `docs/decisions/ADR-005-formal-core-schema-migration-baseline.md`.

### F1.2 — Consistent exception and transaction model

Status: **Implemented — pending review/commit**

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
