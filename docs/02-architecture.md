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

The supported application paths make that rule structural. A Library Item
start validates the supplied Item/Edition link and derives Work through Item →
Edition → Work. An ExternalLoan start accepts only the loan selected through
the owned, active-loan read and derives Work from that loan. The shared creation
service therefore has source-specific public methods and no public method that
accepts both an arbitrary Work identity and a Reading source.

The current v2.001 persistence supports:
- Library Item with direct access through nullable `item_id`;
- active ExternalLoan through nullable `external_loan_id`;
- exactly one populated source FK through a database check;
- concurrency-safe active uniqueness through generated columns and unique indexes.

Before insert, the ReadingRound repository also resolves the selected source's
persisted Work relation and rejects a mismatch. This is a persistence-boundary
guard for privileged internal/test use, not a replacement for application
authorization or source validation. The relational schema itself intentionally
retains the ADR-004 baseline: it enforces source XOR, source existence and
User + concrete-source uniqueness, but adds no cross-table Work trigger or
denormalized source identity.

InternalLoan is not implemented yet. Its addition requires a renewed choice between a third explicit FK and a common source identity; the current representation does not settle that future choice permanently.

Historical closed rounds may retain an unknown source.

## 7. Authorization

Rules:
- UI visibility never authorizes;
- the current actor is resolved through the application `AuthenticatedUser`
  port; in WordPress only the server-side current-user adapter maps a valid
  `wp_users.ID` to the domain `UserId`;
- caller input never determines the actor for a self-service operation;
- Library-scoped application services accept a target `LibraryId`, construct
  `LibraryContext` internally from that target and the trusted actor, then
  check membership permission and record scope independently;
- private application services check ownership against the trusted actor;
- support access is explicit and does not grant private user-data access;
- platform rights do not imply Library content access;
- Beheerder self-escalation is blocked in domain authorization.

The actor is the authenticated user performing an operation. A subject, owner
or target is data the operation concerns. Current self-service use-cases always
derive their owner from the actor; a future act-on-behalf use-case needs its own
explicit permission model and must not reintroduce a caller-supplied actor.

`UserId` remains a Biblio domain value object. Its WordPress representation is
an adapter concern, and F1.4 introduces no foreign key to `wp_users`.

User-owned and Library-scoped boundaries remain distinct. An authenticated
user may use permitted user-owned behavior without first having a Library.

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

The formally supported Core schema history starts at baseline version `1000`.
The earlier internal spike versions 1–5 are not production migration sources.
Product version (`v2.001`), Core plugin/package version (`2.1.0`) and database
schema version are independent. Plugin/package version `2.1.0` expects formal
schema baseline `1000`.

A fresh baseline installation is allowed only on an empty Core schema.
Future changes are separate ordered forward migration steps with an explicit
precondition, change and postcondition; each target version is recorded only
after its postcondition succeeds.

Schema health validates the essential baseline structures independently of
the stored version. Unexpected drift fails closed with specific diagnostics
and is not generically auto-repaired. See
`docs/decisions/ADR-005-formal-core-schema-migration-baseline.md`.

No source FK uses cascade-delete in a way that removes personal ReadingRound history when a physical source changes or ends.

See `docs/decisions/ADR-004-fase-0-persistence-and-reading-sources.md`.

## 10. Failure and transaction contracts

Expected Core failures expose a stable `FailureReason` through the
`CoreFailure` contract. The five categories are:

- validation: invalid domain or application input/state;
- authorization/access: the actor or context may not perform the operation;
- conflict: a valid operation conflicts with existing state or concurrency;
- persistence: storage or infrastructure could not complete a read/write;
- transaction: begin, commit, rollback or transaction nesting failed.

Reason codes are the adapter-facing contract. Exception messages are technical
developer summaries, not UI copy. Raw wpdb/MariaDB diagnostics are retained as
exception context where useful, but are not reason codes and are not included
in the public persistence message. HTTP status and user-facing text mapping are
outside Core and outside F1.2.

Read methods may return `null` when absence is a valid result. Authorization
policy predicates may return `false`. Mutations never use `false` as an
ambiguous failure result: validation, access, conflict, persistence and
transaction failures use their explicit exception category and reason.

wpdb duplicate-key recognition is centralized in the infrastructure adapter.
It translates a database error into a technical conflict with a constraint
name. The owning repository then maps only known constraints to domain-specific
reasons, such as an already-active ReadingRound or a personal-Library
designation conflict. Application and domain layers never inspect MariaDB text
or database index names.

Transactions do not nest implicitly. A transaction manager rejects both a
reentrant call on the same manager and an already-active transaction on the
same database connection with `nested_transaction_not_supported`. Savepoints
are not used.

Transaction execution follows these semantics:

- begin failure: callback is not invoked and `transaction_begin_failed` is
  reported;
- operation failure: rollback is attempted; after successful rollback the
  original failure remains primary;
- operation plus rollback failure: `transaction_rollback_failed` is primary,
  with both the operation and rollback failures retained;
- commit failure: success is never reported; because commit was not confirmed,
  rollback is attempted on a best-effort basis and
  `transaction_commit_failed` remains primary when rollback succeeds;
- commit plus rollback failure: `transaction_rollback_failed` reports an
  uncertain outcome and retains both failures.

A failed commit or rollback never implies that MariaDB guaranteed restoration;
the uncertain-outcome diagnostic is intentional.

## 11. Interfaces/adapters

Abilities API may be an adapter if useful, not a Core dependency.

External metadata sources are adapters and never a precondition for core data integrity.

### Production composition and runtime boundary

`ProductionComposition` is the single production composition root. It builds
the shared WordPress database infrastructure, schema lifecycle, transaction
connection/manager, repositories, authorization policy and current application
services once per plugin request. The stateless `WpdbErrorTranslator` remains
an internal repository/transaction infrastructure utility rather than a
runtime service.

Adapters receive only `CoreApplication`, either from a successfully initialized
`Plugin` or as the argument of `biblio_core_initialized`. This boundary has
explicit named accessors for the existing personal-Library, accessible Item,
owned ExternalLoan/ReadingRound and Reading start services. It exposes no
generic lookup and no concrete wpdb repository. Lower-level creation services
remain internal composition details.

For Reading start, the internal creation service exposes only source-specific
public entrypoints. It derives Work from an Item plus its Edition or from an
owned ExternalLoan. The aggregate factory and repository writer remain
privileged construction/persistence mechanisms; neither is a supported
production adapter boundary.

All exposed services share one `AuthenticatedUser` dependency created by the
composition root. The WordPress implementation reads `get_current_user_id()`
at operation time, validates that the user still exists, and returns
`authentication_required` when it cannot establish an actor. It consumes no
request, form, query or body actor field.

Public production operation signatures accept only resource/source targets;
they accept neither an actor `UserId` nor a trusted `LibraryContext`.
Repository read and write roles are split where useful. Concrete write
adapters remain construction details and `CoreApplication` offers no `get()` or
`resolve()` service locator.

ExternalLoan creation is not yet a public application use-case. Read and write
persistence adapters are separate, and `ProductionComposition` constructs only
the reader. The authorization-neutral writer is a privileged persistence port,
not a public self-service boundary. A future mutation service must authorize
the operation and derive or validate its owner before receiving that port.
Library, membership, Work, Edition and Item write adapters likewise remain
unpublished until a dedicated authorized mutation use-case exists.

Foreign or unknown user-owned and Library-scoped reads disclose only absence
through `null`. Reading starts deliberately collapse an inaccessible or unknown
source to `reading_source_unavailable`. Authentication is distinguishable as
`authentication_required`; authorization failures use stable Core reasons and
never expose MariaDB details. Transport status and UI-copy mapping remain an
adapter responsibility.

### Plugin lifecycle

The activation hook synchronously runs formal migrations and schema health.
Its exceptions are deliberately not swallowed: WordPress runs the hook before
adding the plugin to `active_plugins`, so an unhealthy schema cannot produce a
successful activation.

At normal `init` priority 1, lifecycle boot always reads the cheap formal
version option. A missing, older or otherwise non-current version enters the
F1.1 migration runner. A current version uses a successful health result for
at most 300 seconds; after expiry Core performs the full targeted schema
inspection again. Therefore drift detection can be delayed by at most that
cache window, but is never replaced by option-only trust.

Migration or health failure suppresses `biblio_core_initialized` and leaves the
application boundary unavailable while WordPress itself continues. The first
failure is logged with its F1.2 reason and made available through
`biblio_core_boot_failed`. A matching installed/expected-version failure is
cached for 60 seconds to avoid repeating DDL or flooding logs on every request.
Changing the version state bypasses that failure cache; otherwise retry occurs
after expiry or immediately through explicit plugin activation, which clears
the cache. No path performs unknown schema repair.

## 12. Configuration discipline

Repository should version where practical:
- JetEngine definitions;
- relations;
- queries;
- filters;
- forms;
- Elementor templates/site parts;
- architecture manifest.

Production must never be the only place the application configuration exists.

## 13. Development/test direction

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

## 14. Fase-0 result

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
