# 02 — Architecture

Status: canonical architecture direction with accepted Fase-0 persistence baseline.

## 1. Deployment model

Biblio V2 is one WordPress application in one WordPress site.

`Bibliotheek` is an internal tenant/domain entity.

WordPress Multisite is superseded.

## 2. Modular monolith

Biblio is built as a modular monolith.

### WordPress
Owns:
- authentication infrastructure;
- users/accounts;
- media infrastructure;
- platform/runtime infrastructure.

### Biblio Core
Owns:
- domain rules;
- authorization;
- Library Context;
- user ownership;
- lifecycle/integrity;
- application services;
- transactional behavior;
- adapters/interfaces;
- domain-level audit triggers.

Core rules must be testable without Elementor.

### Crocoblock / JetEngine
Can own suitable:
- structured storage;
- simple relations;
- listings;
- queries;
- filters;
- simple CRUD surfaces.

It does not own Biblio business rules.

### Elementor Pro
Owns presentation:
- app shell;
- layout;
- reusable templates;
- responsive presentation;
- ordinary information popups.

Do not implement critical domain workflows as hidden-field/container logic.

### Custom PHP/JS
Use when:
- integrity requires atomic workflows;
- interactive state is too complex for declarative tools;
- privacy/authorization requires custom handling;
- performance or UX requires it.

## 3. Scope architecture

### Library-scoped

Every Library-scoped query/mutation requires:
- explicit library_id / Library Context;
- authenticated user matching that context;
- valid active membership where applicable;
- relevant role, use-access and/or additional-permission checks;
- tenant isolation and verification that the involved record belongs to the current Library.

Library Context is not inferred from UI, cookie, session or page builder.

### User-owned

Every user-owned query/mutation requires:
- authenticated user;
- ownership check.

Optional library_id/source context never replaces ownership.

User-owned data can exist while its owner belongs to zero Libraries. Library roles do not grant automatic access to another user's private data.

## 4. Membership

Conceptual membership fields include at minimum:
- library_id;
- user_id;
- status;
- beheerrol;
- gebruikstoegang;
- additional_permissions.

WordPress roles alone are insufficient.

For `Beheerder`, authorization must distinguish baseline shared catalog/Item management from explicitly delegated additional management domains. `Leden/toegang beheren` applies only to ordinary Lid memberships and never grants platform-account creation, initial membership linking, Beheerder-layer management or self-escalation.

## 4A. Personal Privébibliotheek anchor

A platform account may initially exist without Library membership.

For v2.001, the first relevant reading or borrowing action auto-creates one designated personal Privébibliotheek if absent:
- user becomes Eigenaar;
- Gebruikstoegang = Directe toegang.

The designation is stored explicitly as user→Library data and is not inferred heuristically from Library ownership.

The provisioning primitive is transactional, idempotent and concurrency-safe. It is invoked by relevant application use-cases, not at login or account registration.

This Library provides a stable collection/authorization anchor but does not own personal Mijn Biblio data.

The model permits additional Library memberships and additional Privébibliotheken.

The personal Privébibliotheek may authorize minimal central bibliographic record creation needed by valid personal reading/borrowing flows. That authority does not transform an external loan into a Library Item.

## 5. Central bibliographic identity

Platform-wide:
- Work;
- Edition;
- Auteur;
- Serie.

Library-local:
- LibraryCatalogContext;
- Items and local collection context.

This allows one personal reading history to recognize the same Work across multiple Libraries without creating duplicate identities.

Central identity governance:
- an authorized Library Item-add flow may create missing Work/Edition identity;
- the Eigenaar of the designated personal Privébibliotheek may create minimum Work/Edition/Auteur/Serie identity needed by a valid personal reading/borrowing flow;
- existing central records are searched before new identity creation;
- direct ordinary correction while record is used by one Library;
- shared records require correction proposal;
- structural merges/splits remain central administration.

## 6. Reading source model

ReadingRound is user-owned. An active ReadingRound requires exactly one concrete source identity.

Do not model current active reading as Work-only.

The active uniqueness rule is User + concrete source, not User + Work. The same user may therefore have simultaneous active rounds for the same Work through different physical sources.

At start, Work is derived from the validated concrete source rather than trusted as a separate client-provided value.

The current v2.001 persistence supports:
- Library Item with direct access through nullable `item_id`;
- active ExternalLoan through nullable `external_loan_id`;
- exactly one populated source FK through a database check;
- concurrency-safe active uniqueness through generated columns and unique indexes.

InternalLoan is not implemented yet. Its addition requires a renewed choice between a third explicit FK and a common source identity; the current representation does not settle that future choice permanently.

Historical closed rounds may retain an unknown source.

## 7. Authorization

Rules:
- UI visibility never authorizes;
- Library-scoped endpoints/services check Library Context;
- private endpoints/services check user ownership;
- support access is explicit and does not grant private user-data access;
- platform rights do not imply Library content access;
- Beheerder self-escalation is blocked in domain authorization.

## 8. Audit

Two separate concerns:

### Library audit
Immutable ActivityEvents for shared Library mutations.
Visible according to Library management rights.

### Personal Timeline
Derived meaningful private user history.

Do not collapse these into one generic event feed.

Central bibliographic changes require separate central bibliographic audit/provenance rather than pretending they belong to one Library only.

## 9. Persistence

Biblio-owned custom tables are the proven baseline for relational and transactional Core-data where hard integrity, tenant isolation, user ownership, transactions or concurrency are central.

Fase 0 proved this baseline for:
- Library and LibraryMembership;
- personal-Library designation;
- platform-wide Work and Edition;
- Library-owned Item;
- user-owned ExternalLoan and ReadingRound.

Work and Edition remain in this persistence for v2.001. There is no technical need to move them to CPT or CCT now.

This is not a rule that every Biblio object must use a custom table. Persistence remains selectable per domain based on query patterns, volume, integrity, lifecycle, transactionality, migration, plugin coupling, testability and maintenance. CPT, CCT, JetEngine and combinations remain valid supporting options where they provide demonstrated net benefit without bypassing Core.

### Defense in depth

Core invariants are protected at the domain, application, transaction and database layers where appropriate.

Current database examples include:
- unique membership per Library + User;
- at most one active Owner per Library;
- one personal-Library designation per User;
- foreign-key integrity;
- exactly one active ReadingRound source;
- at most one active ReadingRound per User + concrete source.

The requirement that a Library has at least one current Owner cannot be fully expressed as one isolated database constraint. It requires a controlled ownership lifecycle and application boundary.

### Schema management

Biblio Core owns versioning and migrations for its Core tables. MariaDB DDL is not fully transactional, so migrations remain versioned, reproducible, rerunnable where possible and integration-tested against real MariaDB. The installed schema version is advanced only after successful migration steps.

No source FK uses cascade-delete in a way that removes personal ReadingRound history when a physical source changes or ends.

See `docs/decisions/ADR-004-fase-0-persistence-and-reading-sources.md`.

## 10. Interfaces/adapters

Abilities API may be an adapter if useful, not a Core dependency.

External metadata sources are adapters and never a precondition for core data integrity.

## 11. Configuration discipline

Repository should version where practical:
- JetEngine definitions;
- relations;
- queries;
- filters;
- forms;
- Elementor templates/site parts;
- architecture manifest.

Production must never be the only place the application configuration exists.

## 12. Development/test direction

Current local stack:
- DDEV;
- Git;
- Composer;
- WP-CLI;
- PHPUnit;
- WordPress;
- MariaDB 10.11.

The Fase-0 integration harness is the structural baseline for Core integration tests:
- real WordPress bootstrap;
- real MariaDB;
- isolated `biblio_core_test` database;
- automatic setup and cleanup;
- explicit guards against integration writes to the development database;
- independent processes/connections for concurrency-sensitive invariants.

Playwright and configuration exports remain relevant for later end-to-end and reproducibility work; they were not part of the completed Core persistence proof.

## 13. Fase-0 result

Status: **GO**

The vertical technical spike proved:
- a platform user can exist with zero Libraries;
- personal Privébibliotheek provisioning creates one explicit designation and Eigenaar · Directe toegang atomically, idempotently and concurrency-safe;
- Library-scoped Item access requires explicit context and server-side authorization;
- Work and Edition can remain platform-wide while Item remains Library-owned;
- ExternalLoan and ReadingRound remain user-owned without Library-role bypass;
- active ReadingRound uses exactly one concrete Item or ExternalLoan source;
- different concrete sources of the same Work may be active simultaneously;
- the same User + source cannot be active twice, including under concurrent starts;
- cross-Library isolation and cross-user privacy hold;
- invalid and failed compound operations leave no half-valid state;
- Core flows run without Elementor.

The accepted persistence and source conclusions are recorded in ADR-004. Persistence for domains not investigated in Fase 0 remains open to domain-specific evaluation.
