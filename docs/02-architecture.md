# 02 — Architecture

Status: canonical architecture direction with accepted Fase-0 persistence baseline and completed Fase-1 Core stabilization.

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

### Persistable domain contracts

Publicly valid state supported by the current repositories must be
representable by the formal schema and hydrate to equivalent domain state.
Current persistent Core identifiers therefore use one shared contract:
non-empty valid UTF-8 with at most 191 characters, matching their
`VARCHAR(191)` columns. Work title similarly follows its 512-character schema
limit. Persisted ExternalLoan and ReadingRound dates are validated against the
UTC 1000–9999 range of MariaDB `DATETIME(6)`.

Additional membership permissions are not arbitrary JSON and are not a
catalogue of product permissions. They are an ordered list of unique,
non-empty valid UTF-8 identifiers. Persistence preserves values, whitespace
and order exactly. Hydration distinguishes a JSON array from an object,
requires every element to be a string and reapplies the domain invariants;
corrupt data fails as a controlled persistence read.

The current Library, Item, ExternalLoan and ReadingRound persistence models
represent only their active technical state. Membership is different: active
and inactive membership states are already present in both domain and schema.
Removing unsupported states is contract alignment, not implementation of the
deferred ExternalLoan, ReadingRound, Item archive or membership mutation
lifecycles.

These constraints require no schema migration: they align public construction
and hydration with the already formalized baseline. Product version `v2.001`,
plugin/package version `2.1.0` and schema baseline `1000` remain independent.

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
explicit named accessors for personal-Library provisioning, catalog Item
creation, accessible Item, owned ExternalLoan/ReadingRound and Reading start
services. It exposes no generic lookup and no concrete wpdb repository.

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
Library and membership write adapters remain internal facilities. Work,
Edition and Item writers are supplied only to the dedicated authorized
`AddLibraryItemService`; they are not themselves published through
`CoreApplication`.

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
- PHPStan with WordPress stubs;
- WordPress;
- MariaDB 10.11.

`./scripts/test-biblio-core-all.sh` is the single canonical Fase-1 quality
gate. It composes metadata/platform validation, PHP syntax, level-6
static analysis of production `src`, the complete unit and isolated real
MariaDB integration suites, runtime smoke, manifest JSON and Git whitespace
checks. Category coverage is obtained from the complete suites rather than by
running overlapping targeted suites again. Its before/after repository-status
check makes unexpected visible file mutations a gate failure.

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

## 15. Fase-1 result

Status: **GO**

Fase 1 turns the accepted spike baseline into a production-wired Core
foundation: formal forward-only schema lifecycle and health, stable failure and
transaction contracts, fail-closed plugin lifecycle, a single composition
root, server-side authenticated operation boundaries, source-derived
ReadingRound Work invariants, aligned domain/persistence contracts and one
reproducible quality gate. The formal schema baseline remains `1000`; internal
spike versions 1–5 are deliberately not production migrations.

This GO applies only to the implemented Core foundation. Product adapters and
flows that are still described as deferred—including REST/Abilities/UI,
ExternalLoan/InternalLoan creation and completion lifecycles, membership and
broader audit behavior—are not implied to be implemented. The first catalog
mutation slice added later in F2.3 is described separately below. The
criterion-by-criterion Fase-1 evidence is maintained in
`docs/07-fase-1-exit-evidence.md`.

## 16. First Fase-2 catalog vertical slice

F2.3 keeps the Fase-1 catalog model authoritative. `Catalog\Work` and
`Catalog\Edition` are platform-wide identities; `Catalog\Item` is the canonical
Library-owned physical item. There is no parallel `LibraryItem` aggregate,
Library-specific Work/Edition identity, new table or schema migration.

`AddLibraryItemService` is the sole production catalog mutation boundary. Its
three explicit methods represent only valid construction paths:

1. add an Item for an existing Edition;
2. atomically add Edition+Item under an existing Work;
3. atomically add Work+Edition+Item.

The target `LibraryId` is caller-selected scope, never authorization. At the
start of every call, the service resolves the WordPress actor through
`AuthenticatedUser`, constructs `LibraryContext`, and asks
`LibraryAccessService::canAddCatalogItem()`. That check requires an active
Owner or an active Manager with `catalog.item_add` and deliberately ignores
`UseAccess`, because physical use and catalog management are independent
membership dimensions. Authorization precedes Work/Edition existence checks.
The service then constructs the Item with the authorized Library ID.

Classification authorization is separately exposed inside Core as
`canInitializeCatalogContextDuringItemAdd()`,
`canModifyLibraryCatalogContext()` and `canManageClassificationTerms()`.
Context initialization inside Item-add follows the Item-add permission and is
not a standalone configurable right. Existing-context and term management use
the independent `catalog.classification_manage` permission. These predicates
are consumed by named classification application services; `CoreApplication`
exposes those services but no repository or generic mutation primitive.

The existing writable Work, Edition and Item ports and wpdb adapters are
reused. Compound writes run through the shared transaction manager. Repository
duplicate-primary-key translation produces
`catalog_record_already_exists`; unrelated constraint and database failures
retain their persistence semantics. The database foreign keys continue to
enforce Edition→Work and Item→Library/Edition, while absence of a uniqueness
constraint on Item `(library_id, edition_id)` deliberately permits multiple
physical copies and cross-Library reuse of one Edition.

`ProductionComposition` owns all concrete construction and supplies the same
authenticated-user, library-access, transaction and repository instances to
the relevant services. `CoreApplication` keeps one named
`libraryItemCreation()` accessor and exposes separate named classification
services, but no repository or resolver. The existing Item→Edition→Work
ReadingRound path is unchanged and immediately accepts an Item created through
this boundary when the actor has direct use access.

F2.5.6 adds one optional typed `LibraryCatalogContextInitialization` to each
of the three creation paths. The Item-add service authorizes both Item-add and
its inseparable initialization predicate before Work, Edition, context or term
lookup. An existing context short-circuits classification handling completely.
Only an initially absent context enters the shared internal initializer.

That initializer is also used by explicit represented-Work context creation.
It owns Library-row serialization, a locked current-context read, locked term
resolution, active-new-link validation and the unique context insert, but owns
neither authorization, transaction boundaries nor audit ordering. Those remain
with the enclosing use-case. This keeps it unavailable through
`CoreApplication` and prevents `catalog.item_add` from becoming standalone
context-management authority.

For Item-add, the enclosing transaction orders the applicable writes as Work,
Edition, context, Item and then the context-created ActivityEvent. A context
that appears after the initial absence observation is reread under the Library
lock: equal desired classification is reused without a second event, while a
different desired classification raises the existing stable context conflict
and rolls back the losing compound writes. No schema or repository write path
is added.

This slice does not solve bibliographic entity resolution or shared-record
governance: callers must select existing central identities or deliberately
request a new identity with a unique ID. Author/contributors, Series, rich
metadata, search/entity resolution, taxonomy and all other deferred catalog or
product lifecycles remain later work.

## 17. F2.5 classification persistence and application boundary

F2.5 keeps Work and Edition platform-wide and adds one Library-owned context
identity per Library + Work. Typed Boeksoort, Genre and Onderwerp repositories
remain separate; Genre and Onderwerp are unordered sets rather than a generic
taxonomy abstraction. Schema 1001 persists the three term types,
LibraryCatalogContext, its junction sets and append-only ActivityEvents.
Composite foreign keys make cross-Library term links invalid at database level.

The formal migration chain is 1000→1001→1002 while formal baseline 1000 stays
unchanged. Migration 1000→1001 owns DDL and the compatibility backfill that
adds `catalog.item_add` to existing Managers without granting
`catalog.classification_manage`. Migration 1001→1002 is data-only: it uses the
same idempotent seed-evolution service as transactional new-Library creation,
preserves local identity/display/status/context choices, creates no automatic
ActivityEvent and records seed-adoption ambiguity only as a non-blocking health
warning. Current schema version is 1002.

Classification foundations and user-driven audit share Core persistence
contracts. Context and term ActivityEvents capture trusted actor/source,
technical identities and historical labels through the append-only appender.
Automatic migration, bootstrap and seed-adoption operations deliberately do
not produce Library ActivityEvents.

F2.5.5c builds on schema 1002 without changing DDL. Context creation and save
are separate use-cases. Explicit legacy creation first authorizes the trusted
actor, then proves Work representation with one Library-scoped
Item→Edition→Work query. A Library-row `FOR UPDATE` lock serializes the absent
Library+Work identity before checking and inserting the unique context.

Existing-context save locks and reads the current context before deciding:

1. desired selection equals current selection: return current context;
2. desired differs and expected version is stale: return a stable stale
   conflict carrying current state;
3. desired differs and version matches: validate confirmations and terms,
   perform one CAS replacement and append one ActivityEvent.

The selection resolver is explicitly typed for Boeksoort, Genre and Onderwerp.
It locks referenced term rows so a deactivation cannot race past validation of
a new link. Only IDs newly introduced relative to the current selection must
be active. Already linked inactive Boeksoort, Genre and Onderwerp IDs remain
legal when another part of the selection changes; a changed Boeksoort ID must
reference an active Boeksoort. There is no automatic replacement or
reactivation. Set order is normalized by the domain value object and never
creates a mutation.

Boeksoort, Genre and Onderwerp have separate concrete management services, not
a generic taxonomy engine. Database uniqueness remains the concurrency
authority for normalized create/rename conflicts. Boeksoort operations also
take the Library-row lock; active-term counting and the last-active
confirmation decision are therefore serialized.

`WordPressActivityEventFactory` captures a UUID, timestamp and historical
WordPress actor display name. Context and term audit builders capture technical
IDs and display labels. Domain write and append-only event insert run through
one `TransactionManager` operation, so either both commit or both roll back.

## 18. F2.6 ReadingRound lifecycle design

ADR-007 is the binding implementation contract for the next ReadingRound
slices. Lifecycle has one stored truth: nullable outcome, where null is active
and completed/stopped is ended. ReadingRound provenance distinguishes legacy
source-started, new source-started and manually historical records. Source is
mandatory when a normal round is started. It is separate, correctable
provenance information: active and ended rounds may explicitly switch between
same-Work Item/ExternalLoan, attach a same-Work source to historical unknown,
or remove a proven wrong source when the correct one is unknown. Work and
provenance remain immutable.

Content reading dates use explicit year/month/day components with day-, month-
or year-precision. Technical `created_at`, `updated_at` and `ended_at` remain
separate UTC instants. The schema-1002 `started_at` values are preserved as
legacy content instants and are not reinterpreted as confirmed local dates.

The persistence change requires ordered migration 1002→1003 and explicit
1003 health. Baseline 1000 and earlier migrations remain immutable. Existing
active rows retain ID, User, Work, source and `started_at`; new database checks
allow zero or one source, never both. Application services enforce the
stronger creation and correction rules. Active uniqueness remains User +
concrete source whenever a concrete source is present.

All ReadingRound mutation services resolve the actor server-side, use
owner-scoped reads and perform locked decision plus CAS write transactionally.
Identical stale requests are no-op successes; divergent stale requests return
a typed conflict with current state. No ReadingRound mutation writes a Library
ActivityEvent or introduces a private audit engine.

Source correction is a separate named use-case so no content/lifecycle
correction can change source implicitly. Conditional hard delete is exposed
only for `historical_manual` provenance. Normal source-started provenance is
never hard deleted, even after its source was corrected; a wrong Work on
manual history requires delete plus a separate new registration.

`ReadingRoundIdGenerator` is a specific composition detail shared by both
existing start paths and historical registration. Database primary-key
uniqueness remains final and only a translated ID collision receives at most
three attempts. `CoreApplication` exposes named use-cases and projections, not
the generator or repository.

Personal Work-read-status and first-read/reread are calculated from owned
ReadingRounds. Date intervals determine only provable chronology; overlap is
`chronology_indeterminate`. Presentation tie-breaks remain deterministic but
have no historical meaning.
