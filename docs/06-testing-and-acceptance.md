# 06 — Testing and acceptance

This file converts canonical product rules into a baseline for domain, integration and end-to-end acceptance.

It is not a complete test-case catalogue yet.

## 1. Tenant isolation

Acceptance:
- Library A data is never returned merely because user belongs to Library B.
- Every Library-scoped query/mutation has explicit Library Context.
- Former membership does not allow Library collection/archive search.
- Historical private records may retain historical Library labels without reopening protected Library data.

No-go:
- any cross-Library data leak;
- any Library-scoped write accepted without authorization.

## 2. User ownership/privacy

Acceptance:
- ReadingRounds, Notes, private Ratings/Reviews, Goals, Verlanglijst, Hierna lezen and personal insights are readable/writable only by the owning user unless an explicitly designed publication projection applies.
- Library Eigenaar/Beheerder cannot inspect another user's private ReadingRounds.
- Support access never grants private user-owned data access.

No-go:
- role-based Library admin access bypasses ownership.

## 3. Membership and use access

Test matrix must cover:
- Eigenaar · Directe toegang;
- Beheerder · Directe toegang/Lenen/Alleen bekijken;
- Lid · Directe toegang/Lenen/Alleen bekijken.

Acceptance:
- management role and physical use access operate independently;
- non-owner initial membership is Lid · Alleen bekijken;
- Beheerder has baseline shared catalog/Item management but no automatic rights in additional management domains;
- Beheerder cannot self-escalate;
- Beheerder with `Leden/toegang beheren` may change use access and deactivate/reactivate ordinary Lid memberships only;
- that permission cannot create platform accounts, create initial membership links, promote to Beheerder or manage Beheerders;
- Beheerder permission loss occurs on demotion;
- re-promotion does not silently restore old permissions;
- Eigenaar transfer sets new Eigenaar direct access and explicitly resolves former Eigenaar access.

## 4. Platform account vs membership

Acceptance:
- a newly created platform account may initially have no Library;
- first relevant reading/borrowing action auto-creates the user's designated personal Privébibliotheek once if absent;
- auto-created personal membership is Eigenaar · Directe toegang;
- a user has at most one designated personal Privébibliotheek in v2.001;
- personal ReadingRounds/external loans remain user-owned after that Library is created;
- an external borrowed physical source is not converted into a Library Item;
- one platform account can have multiple Library memberships;
- Platformbeheer reuses existing account;
- platform deactivation blocks login but does not rewrite memberships;
- reactivation restores reachability only to memberships still active;
- normal platform deactivation is blocked while user owns a Privébibliotheek.

## 5. Reading source invariants

Acceptance:
- new active ReadingRound cannot exist without one valid concrete physical source;
- at most one active round per user + source;
- same user may have two active rounds for same Work using different sources;
- Work status becomes Aan het lezen when one or more rounds active;
- a second unused source of same Work remains eligible for Leesvoorraad;
- source lifecycle/access changes do not silently end private rounds.

Historical acceptance:
- closed round may retain unknown source;
- partial date precision is preserved.

## 6. Leesvoorraad

Acceptance:
- direct-access available Item appears for eligible user if no active round on exact Item;
- same Item may appear for multiple direct-access users;
- internal loan appears for borrower;
- external active loan appears;
- previously read Work does not remove source;
- same source with internal-loan context does not duplicate;
- Alleen bekijken Library Item does not appear;
- Item being read on exact source does not appear.

## 7. Hierna lezen

Acceptance:
- Work and specific-source entries supported;
- identical Work entry cannot duplicate;
- identical source entry cannot duplicate;
- Work + multiple different source entries may coexist;
- starting reading never auto-removes;
- source becoming unavailable never auto-removes;
- manual order persists.

## 8. Internal lending

Acceptance:
- Lenen member requires active loan before Item is personal reading source;
- Directe toegang member may use without loan;
- Directe toegang member may still receive explicit loan;
- Alleen bekijken member cannot receive internal loan;
- member cannot self-request/create a loan in v2.001;
- active loan remains after access downgrade or membership termination;
- former member may still use that active loan source;
- return removes current physical access from the loan without deleting history.

## 9. Archive

Acceptance:
- ordinary archive blocked by active internal loan;
- special not-returned/given-away route settles loan then archives;
- archived Item excluded from current Leesvoorraad;
- archive never deletes or auto-closes private reading data;
- restore reuses same Item ID;
- Collection memberships not silently restored;
- Collection goal snapshot not silently modified.

## 10. Collections

Acceptance:
- only active Items from same Library;
- multi-Collection membership allowed;
- draft edit can cancel without persistence;
- save commits atomically;
- removal selection needs no redundant confirmation before save;
- archived Collection read-only;
- completion-goal snapshot deduplicates Works.

## 11. Central bibliographic identity

Acceptance:
- the personal Privébibliotheek Eigenaar can create the minimum missing central Work/Edition/Auteur/Serie identity needed by a valid personal read/borrow flow;
- Biblio searches existing central identities before creating a new one;
- this creation never makes an external borrowed source a Library Item;
- same Work can be referenced by Items in multiple Libraries;
- same user's rereads across different Libraries still resolve to one Work;
- Work can exist without Edition/Item for legitimate central personal use case;
- local Boeksoort/Genre/Onderwerp do not mutate central Work/Edition.

Governance:
- one-Library central record can be directly ordinarily corrected by authorized admin;
- once shared across multiple Libraries, ordinary Library admin cannot directly mutate central record;
- correction proposal can be submitted;
- structural merge/split remains platform-managed.

## 12. Search

Mijn Biblio:
- respects access/ownership;
- can group by Work but keeps every concrete source visible;
- former Library collection/archive disappears after membership loss;
- private historical context remains searchable.

Library:
- strict current Library;
- active Items default;
- temporary archive search resets on refresh/navigation;
- inventory number works;
- no Item deduplication.

No-result:
- no hidden external metadata search;
- add Item requires valid target Library and authorization.

## 13. Home

Acceptance:
- fixed elements cannot be disabled;
- module visibility/order persists account-wide;
- Openstaande acties with zero content occupies no space;
- Nu aan het lezen lists rounds, not unique Works;
- Hierna lezen uses first three manual entries;
- Leesvoorraad is source-based;
- no duplicate Home configuration under Mijn voorkeuren.

## 14. Ratings/reviews/notes

Acceptance:
- private contributions need no Library;
- publication requires one explicit active Library context with active Item of Work;
- publication never silently duplicates across Libraries;
- Library moderator cannot edit another user's text;
- Notes always private.

## 15. Reading goals/statistics

Acceptance:
- successful ReadingRound only counts as completion;
- stopped round does not;
- reread classification based on chronological successful completion;
- two overlapping successful rounds can yield 2 rounds / 1 unique Work / 1 reread;
- Library-scoped personal goal/stat requires actual ReadingRound source context;
- Collection/Series snapshots do not silently mutate.

## 16. Library audit

Acceptance:
- Eigenaar sees full Library audit;
- Beheerder sees only domains covered by current rights;
- Lid sees none;
- private user data never leaks through audit;
- contextual Item/history view uses same ActivityEvent records;
- actor is visible/stable;
- users cannot edit/delete ActivityEvents;
- navigation/search/filter actions do not create audit entries.

## 17. Settings/platform admin

Acceptance:
- only implemented v2.001 settings visible;
- Home settings only Home aanpassen;
- only Standaardweergave is Library default;
- Archief tonen is personal per Library;
- Platform Gebruikersbeheer does not expose private user content;
- Platform Bibliotheken module does not expose Library content absent Supporttoegang;
- only Super admin manages Admins/platform rights in v2.001;
- recovery actions are traceable.

## 18. Integrity and concurrency

Acceptance:
- compound domain mutations are atomic;
- invalid transition blocked with reason;
- concurrent shared-data edits do not silently overwrite;
- hidden/stale form fields do not mutate data;
- failure leaves previous valid state intact.

## 19. E2E critical flows

Minimum E2E candidates:
- platform account exists without Library → first relevant reading/borrowing action auto-creates designated personal Privébibliotheek;
- Platformbeheer links existing account to another Library;
- Eigenaar changes member access/role;
- add physical book/Edition/Item;
- central metadata correction direct vs proposal when shared;
- Library search and temporary archive search;
- Start/finish/stop ReadingRound;
- two simultaneous rounds same Work on different sources;
- Collection draft save/cancel;
- archive/restore;
- internal loan/return;
- external loan;
- Home customization;
- permission/privacy boundaries.

## 20. Technical test layers

### Unit/domain
Biblio Core rules without Elementor.

### Integration
WordPress + database + Biblio Core:
- persistence;
- queries;
- migrations;
- APIs/adapters;
- authorization;
- integrity.

### End-to-end
Playwright for critical user flows and permission boundaries.

Responsive E2E must verify same functionality, not pixel identity.

## 21. Core schema migrations

Acceptance:
- an empty Core schema installs formal baseline version 1000;
- a healthy current run is a schema and data no-op;
- a forward migration preserves existing data;
- a failed step does not record its target version;
- retry is allowed only for an explicitly recognized safe partial state;
- current version plus missing essential table, index or constraint is
  unhealthy and is not automatically repaired;
- legacy Fase-0 spike versions 1–5 are not treated as production upgrade
  sources;
- migration and health behavior is tested against the real MariaDB baseline.

## 22. Core failures and transactions

Acceptance:
- expected validation, authorization, conflict, persistence and transaction
  failures expose stable reason codes;
- ReadingRound and personal-Library conflicts are distinguishable without
  inspecting MariaDB text;
- known duplicate-key conflicts are recognized only in the infrastructure
  adapter and mapped semantically by the owning repository;
- raw database diagnostics are not the application contract;
- valid read absence remains `null`, authorization predicates may remain
  boolean, and mutation failure is never an ambiguous `false`;
- transaction success commits once;
- operation failure rolls back and remains primary when rollback succeeds;
- begin failure does not execute the operation;
- commit failure is explicit and triggers only a best-effort rollback attempt;
- rollback failure retains the preceding operation or commit failure and marks
  the transaction outcome as potentially uncertain;
- nested transactions on the same manager or database connection fail with
  `nested_transaction_not_supported` and do not use savepoints;
- real MariaDB integration tests retain rollback, provisioning and concurrency
  coverage, while deterministic transaction-level failures use a test double.

HTTP status mapping, REST/Abilities responses and UI copy are not part of this
Core contract.

## 23. Production plugin lifecycle and composition

Acceptance:

- a real plugin activation against an empty Core schema installs baseline 1000
  and finishes with healthy schema;
- activation on a healthy current schema preserves schema and representative
  data;
- runtime version checking executes only missing explicit formal migrations;
- migration failure does not bump the version or remove existing data and a
  matching immediate retry is temporarily deferred;
- current version plus drift blocks the application boundary without repair;
- a legacy Fase-0 version blocks activation and is not adopted;
- production composition returns one stable `CoreApplication` graph with the
  existing named services and no repository locator;
- shared lower-level services are reused where both public use-cases depend on
  them;
- repeated plugin boot/initialize calls register and execute lifecycle only
  once per request;
- the existing initialization action still fires on healthy boot and receives
  the application boundary;
- an unhealthy boot leaves WordPress available, emits technical diagnostics
  and shows only a sanitized notice to administrators who can activate plugins.

REST endpoints, WordPress Abilities, UI adapters, WordPress identity resolution
and the complete authenticated write boundary are not F1.3 acceptance scope.

## 24. Authenticated identity and scoped production boundaries

Acceptance:

- a valid current WordPress user maps server-side to the corresponding domain
  `UserId`, while no current/valid user fails with
  `authentication_required`;
- production operation signatures accept no caller-supplied actor `UserId` or
  trusted `LibraryContext`;
- all public services in one production composition share the same
  authenticated-identity dependency;
- personal-Library provisioning creates or returns only the authenticated
  actor's designation and remains idempotent, transactional and
  concurrency-safe;
- owned ExternalLoan and ReadingRound reads resolve the actor internally and
  reveal foreign records only as `null`;
- Library-scoped Item access derives context from trusted actor plus target
  Library and checks both membership authorization and Item Library scope;
- Library Item and ExternalLoan Reading starts persist the authenticated actor
  as ReadingRound owner and cannot start on a foreign source;
- ExternalLoan read/write adapters are separate and the writer is absent from
  production composition; ReadingRound writes reject mismatched owners;
- `CoreApplication` exposes named services only, no repositories, generic
  resolver or arbitrary-owner mutation route;
- zero-Library user-owned behavior stays supported;
- cross-user, cross-Library, concurrency, migration, lifecycle/activation,
  transaction and smoke regressions stay green.

REST/Abilities/UI, a public ExternalLoan creation lifecycle, membership/catalog
CRUD and ReadingRound source/Work hardening are outside F1.4.

## 25. ReadingRound source and Work invariants

Acceptance:

- no supported production method accepts a caller-selected `WorkId` together
  with a `ReadingSource`;
- Library Item start validates Item → Edition and derives Work from Edition;
- ExternalLoan start requires the server-side authenticated owner, requires an
  active loan and derives Work from that loan;
- foreign, unknown, inactive or relationally inconsistent sources fail as
  `reading_source_unavailable` without disclosing existence;
- a privileged inconsistent ReadingRound cannot be inserted through the wpdb
  repository and fails as `persistence_write_failed`;
- XOR, source foreign keys, historical behavior and active uniqueness per User
  + concrete source remain unchanged;
- different concrete sources for the same Work may still have simultaneous
  active rounds;
- two independent processes starting from the same Item produce one active
  round and one semantic conflict;
- two independent processes starting from the same ExternalLoan produce one
  active round and one semantic conflict;
- production composition exposes neither the ReadingRound writer nor an
  arbitrary Work/source creation route;
- identity, Library scoping, migrations, lifecycle/activation, transactions,
  personal-Library concurrency and smoke regressions remain green.

InternalLoan, a new source representation, database triggers, denormalization,
REST/Abilities/UI and source-creation lifecycles are outside F1.5.

## 26. Domain/persistence contract alignment

Acceptance:

- every persistent User, Library, Work, Edition, Item, ExternalLoan and
  ReadingRound ID accepts 191 Unicode characters and rejects 192 characters
  with `validation_failed` before a database call;
- empty or invalid UTF-8 identifiers are rejected by the same shared contract;
- a valid 512-character Work title roundtrips and an overlong or invalid UTF-8
  title fails validation;
- ExternalLoan exposes only the currently persistable active state;
- ExternalLoan borrowed/due dates and ReadingRound start date reject values
  outside MariaDB `DATETIME(6)`'s supported UTC year range;
- AdditionalPermissions rejects empty, duplicate or invalid UTF-8 values while
  preserving order, whitespace and exact values;
- valid permissions JSON roundtrips without meaning loss;
- stored permission objects, non-string list elements, empty identifiers,
  duplicates and invalid JSON fail as `persistence_read_failed` without silent
  coercion or partial hydration;
- Library, Membership, Work, Edition, Item, ExternalLoan and ReadingRound
  current persistable states roundtrip to equivalent domain state;
- Core-wide infrastructure has no misleading remaining `LibrarySchema*`,
  `LibraryTableNames` or Library-only reset name;
- product, plugin/package and schema versions remain independent and unchanged;
- all F1.1–F1.5 regressions remain green.

ExternalLoan/ReadingRound completion, Item archive, membership mutation,
new permission functionality and REST/UI are outside F1.6.

## 27. Fase-1 quality gate and exit

Acceptance:

- one documented root command runs the complete Fase-1 verification without
  duplicate full suite executions;
- the gate fails non-zero on every failed subsection and identifies each
  subsection in its output;
- Composer metadata and current-platform requirements are valid from the
  committed lockfile;
- every plugin PHP file passes syntax validation;
- PHPStan analyses production `src` at configured level 6 without a
  baseline or ignored errors, with explicit WordPress stubs;
- the complete unit and isolated real-MariaDB integration suites pass,
  including migration, lifecycle/activation, identity/authorization,
  transaction and both independent-process concurrency coverage;
- the active local WordPress runtime passes plugin/class/init-hook/HTTP smoke;
- `manifest.json`, unstaged diff and staged diff validation pass;
- the gate leaves the visible repository status exactly as it found it;
- the F1.1–F1.7 documentation distinguishes proven implementation from
  deferred product behavior and records a criterion-by-criterion exit result;
- product `v2.001`, plugin/package `2.1.0` and schema baseline `1000` remain
  independent and unchanged.

Legacy Fase-0 schema versions 1–5 are **N/A** as Fase-1 upgrade paths: ADR-005
replaces them with the first formally supported baseline `1000`. See
`docs/07-fase-1-exit-evidence.md` for the durable exit checklist and evidence.

## 28. First catalog creation vertical slice

Acceptance:

- one production application service exposes explicit, invalid-combination-free
  paths for existing Edition, new Edition under existing Work, and new
  Work+Edition creation;
- actor identity is resolved server-side per operation; no public method
  accepts actor `UserId` or trusted `LibraryContext`;
- target Library is explicit, and Item-add requires an active Owner or active
  Manager with `catalog.item_add`, regardless of `UseAccess`;
- `catalog.classification_manage` is independent from `catalog.item_add` and
  governs existing-context and classification-term management without
  granting Item-add;
- Member, inactive membership, foreign-Library actor and unauthenticated actor
  fail with the appropriate stable authorization/authentication reason;
- authorization occurs before central Work/Edition existence inspection;
- the service constructs the Item with the authorized Library ID;
- Work and Edition remain platform-wide, Edition references exactly one Work,
  and Item references exactly one Library plus Edition;
- Work without Edition remains schema-valid, multiple Items may reference one
  Edition in one Library, and multiple Libraries may reuse that Edition;
- existing-Edition creation writes only Item when its Library + Work context
  exists; otherwise context, Item and context-created event commit as one unit;
  compound paths leave no partial Work/Edition/context/Item/event rows after
  any failed step;
- duplicate Work, Edition and Item primary IDs—including an independent-process
  race—produce `catalog_record_already_exists`, with one race winner and one
  conflict;
- Item lookup never crosses Library scope, while a newly created Item can
  immediately start a valid ReadingRound whose Work is derived through Edition;
- `ProductionComposition` remains the only production constructor of wpdb
  catalog repositories, and `CoreApplication` exposes one named Item-creation
  service plus explicit classification services, never a repository or
  resolver;
- no schema migration occurs and product `v2.001`, package `2.1.0` and schema
  baseline `1000` remain unchanged;
- the complete canonical quality gate stays green, including PHPStan,
  MariaDB, all concurrency regressions, WordPress smoke, manifest and Git diff
  validation.

No-go:

- a parallel LibraryItem aggregate or per-Library Work/Edition identity;
- authorization based on caller-supplied context, UI state or physical
  `UseAccess`;
- a partial compound catalog chain after failure;
- repository exposure through the production application boundary;
- schema/table changes or expansion into deferred Fase-2 domains.

## 29. F2.5 foundations, migrations and seed evolution

Acceptance and durable evidence:

- formal baseline remains 1000, current version is 1002 and the production
  registry contains the ordered 1000→1001 and 1001→1002 steps;
- fresh install reaches healthy 1002, a real healthy 1001 state upgrades to
  1002, and a failed data-evolution leaves version 1001 for an idempotent retry;
- schema 1001 introduces separate local term/context/audit persistence without
  rewriting baseline 1000; migration 1001→1002 is data-only and leaves its DDL
  unchanged;
- 1000→1001 gives every existing Manager membership `catalog.item_add`, does
  not grant `catalog.classification_manage`, and preserves unknown additional
  permissions and membership status;
- every new or migrated Library converges on 9 Boeksoort seeds, 12 Genre seeds
  and 0 Onderwerp seeds through one shared transactional, idempotent and
  concurrency-safe seed-evolution service;
- an existing seed key is a no-op; one safe normalized local candidate adopts
  the immutable seed key without changing ID, display name, status or context
  links; missing seeds are created and no rename, merge or reactivation occurs;
- unsafe or ambiguous adoption performs no content mutation and reports
  `classification_seed_adoption_ambiguous` with Library, taxonomy, seed and
  candidate IDs as a non-blocking schema-health warning;
- automatic migration/bootstrap/adoption writes no Library ActivityEvent,
  while a seed failure during new-Library creation rolls back Library and Owner
  membership through the existing transaction;
- schema constraints enforce Library+Work context identity, normalized and
  seed-key uniqueness, duplicate-free junction sets and same-Library composite
  foreign keys for every selected term;
- `Schema1001MigrationTest`, `Schema1001IntegrityTest`,
  `Schema1002SeedEvolutionTest`, `ClassificationSeedConcurrencyTest`,
  `ClassificationPersistenceTest` and `ActivityEventPersistenceTest` provide
  the real-MariaDB migration, tenant-integrity, seed, retry and append-only
  evidence.

No-go:

- a hidden boot-time bootstrap, a second data-version marker or premature
  version bump;
- automatic rename, merge, reactivation, context mutation or ActivityEvent;
- rewriting schema-1001 DDL in migration 1001→1002;
- cross-Library context-term relationships.

## 30. Classification authorization, context and term management

Acceptance:

- explicit missing-context creation succeeds only for a Work represented by a
  same-Library Item→Edition→Work chain;
- classification authorization is checked before representation, term or
  context lookup and before starting a mutation transaction;
- context selection is exactly one Boeksoort plus duplicate-free unordered
  Genre and Onderwerp sets;
- equal current-state and stale-current-state requests are successful no-ops
  returning the current version without update or ActivityEvent;
- a stale divergent request returns the stable
  `library_catalog_context_stale` reason and current context;
- a real save increments version exactly once, never merges automatically and
  requires explicit confirmation for a changed Boeksoort ID;
- retained inactive Boeksoort, Genre and Onderwerp links remain valid when
  another part of the context changes, while every newly introduced link is
  active at the serialized validation point; changing the Boeksoort ID selects
  an active Boeksoort and requires explicit confirmation;
- context mutation and one structured context ActivityEvent commit atomically;
- each type-specific term service implements create, rename, deactivate and
  reactivate without delete, merge or automatic reactivation;
- normalized uniqueness covers active and inactive terms; no-op and conflict
  outcomes produce neither write nor event;
- last-active Boeksoort deactivation requires explicit confirmation, while
  zero active Boeksoorten remains schema-healthy;
- independent-process tests prove context CAS winner/stale behavior,
  equal/different context-create outcomes, duplicate term create,
  rename/create collision, deactivate/new-link serialization and last-active
  Boeksoort serialization;
- audit failure rolls back both context and term mutations;
- AddLibraryItemService, schema 1002, ReadingRound and earlier isolation
  regressions remain unchanged and green.

Evidence is provided by `LibraryAuthorizationPolicyTest`,
`ClassificationManagementAuthorizationTest`,
`ClassificationManagementApplicationTest`,
`ClassificationManagementConcurrencyTest`, `ActivityEventPersistenceTest`
and `ProductionApplicationBoundaryTest` in the complete suites.

## 31. Item-add classification initialization

Acceptance:

- the three explicit F2.3 Item-add paths remain the only production creation
  paths and accept at most one optional typed context-initialization intent;
- authorization and actor resolution precede every central or Library-local
  lookup; Owner and active Manager with `catalog.item_add` are allowed, while
  classification-only Manager, Member, inactive and foreign membership are
  denied regardless of `UseAccess`;
- initialization is not exposed outside `libraryItemCreation()` and does not
  require or grant `catalog.classification_manage` authority;
- an existing Library + Work context is reused unchanged even with retained
  inactive terms or supplied differing input: no context write, version change
  or context-created ActivityEvent occurs;
- an absent context requires exactly one active Library-owned Boeksoort;
  active Genres and Onderwerpen are optional duplicate-free unordered sets;
- missing input, inactive new selections and cross-Library term IDs fail with
  no partial Work, Edition, context, Item or ActivityEvent;
- the shared internal initializer uses the same Library/term locking,
  active-selection validation, context identity and persistence path as
  explicit F2.5.5c context creation;
- applicable writes occur in one transaction in Work → Edition → context →
  Item → context-created-event order, and event failure rolls the compound
  mutation back;
- a created context starts at version 1 and produces exactly one structured
  `library_catalog_context.created` event with primary context, related Work,
  trusted actor and historical IDs plus labels;
- independent connections prove that equal concurrent initialization produces
  one context, two valid Items and one context event, while different initial
  classifications produce one winner, one stable context conflict and no
  losing Edition/Item rows;
- Library isolation, Item→Edition→Work ReadingRound derivation, F2.5.5c
  management, schema 1002 and all earlier regressions remain green;
- no migration, DDL, metadata mapping, REST/Abilities/UI, search or new term
  management behavior is introduced.

## 32. F2.6 ReadingRound lifecycle acceptance evidence

F2.6a accepted ADR-007 without production behavior. The F2.6b implementation
and its regression suite now prove:

- lifecycle is derived from nullable completed/stopped outcome and cannot be
  persisted as a contradictory second state;
- every normal active start has one valid Item or ExternalLoan source; persisted
  rounds allow zero or one source so explicit correction can represent unknown;
- manual historical rounds start ended and source-free, remain linked to an
  existing Work and may later receive a valid same-Work source;
- exact-day, month+year and year-only dates roundtrip without invented parts;
- technical timestamps never substitute for content dates and migration
  preserves every schema-1002 `started_at` value exactly as legacy evidence;
- formal baseline stays 1000, migration chain becomes
  1000→1001→1002→1003, version advances only after healthy 1003 and a known
  partial failure retries idempotently;
- 1003 health fails closed on column, generated expression, index, FK, CHECK
  or persisted lifecycle/provenance/date/version drift;
- Item and ExternalLoan start still authorize before sensitive lookup, derive
  Work from the source and enforce one active round per User + source;
- every creation path obtains an opaque ReadingRoundId server-side; only a
  translated primary-key collision is retried and at most three times;
- finish/stop perform one transactional CAS mutation, identical concurrent
  requests converge through stale-no-op and completed-versus-stopped yields
  one winner plus one typed stale conflict with current state;
- ended content correction changes only outcome and content period, preserves
  identity/Work/provenance, never changes source implicitly and follows
  no-op/stale-no-op/stale-conflict semantics;
- explicit source correction works for active and ended Item→Item,
  Item↔ExternalLoan, unknown→concrete and proven-wrong concrete→unknown cases,
  always preserves Work and uses applicable source authorization/validation;
- only `historical_manual` provenance can be conditionally hard deleted;
  normal start→end provenance is corrected instead, and wrong-Work history is
  delete plus a separate new registration rather than Work replacement;
- personal Work status covers never read, active, stopped, completed,
  historical, reread and mixed combinations without a stored Work flag;
- first/reread uses only completed finish-date intervals and returns explicit
  chronology-indeterminate where day/month/year evidence cannot prove order;
- owner/non-owner/anonymous boundaries are indistinguishable where needed,
  mutation authorization precedes lookup and no Library-role bypass exists;
- no ReadingRound mutation writes a Library ActivityEvent or generic private
  audit event;
- `CoreApplication` exposes only named owner-scoped services/projectors and
  keeps repositories, generators and arbitrary writers private;
- independent-process concurrency plus the canonical full gate keep F2.3,
  F2.5, Library isolation and existing ReadingRound behavior green.

The implementation delivers the previously planned F2.6b domain/schema/
persistence/ID work together with the lifecycle, history, correction, deletion
and derived-read behavior needed by the binding implementation request. The
formal evidence is recorded in `docs/09-f2-6b-exit-evidence.md`. InternalLoan,
ExternalLoan lifecycle, goals/statistics/Timeline/Home, ratings/notes,
REST/Abilities/UI and any private audit engine remain outside these criteria.

## 33. F2.7 Private Notes acceptance evidence

F2.7a fixed the binding O1–O3 contracts. F2.7b and its regression suite prove:

- Note ID, owner, Work and creation time remain immutable; Note IDs are opaque,
  server-issued persistent identifiers with bounded collision retry;
- the exact safe HTML allowlist roundtrips, while invalid UTF-8, NUL, empty,
  oversized, malformed, attributed, linked, styled, scripted, embedded,
  image and unknown markup fail without silent stripping;
- the render boundary revalidates stored content before returning HTML;
- create-for-Work supports unlinked and multiple Notes; create-for-Round
  derives Work from an owned locked Round and supports multiple Notes per Round;
- active, ended and historical same-owner/same-Work Round contexts work;
  foreign and same-owner/cross-Work contexts fail without mutation;
- content correction and Round attach/change/remove preserve identity and Work,
  implement semantic/stale no-op and increment version once per real mutation;
- single reads, Work listings, Round listings and Mijn-notities listings always
  include the authenticated owner predicate and use bounded stable pagination;
- another user, including one with unrelated Library/platform authority,
  cannot enumerate, read, project, change, relink or delete a Note;
- conditional hard delete requires owner plus current version and removes only
  the Note, never Work, Round or other reading truth;
- legitimate F2.6 historical Round delete preserves its Notes and nulls only
  `reading_round_id`; a denied Round delete leaves Note context unchanged;
- independent-process races prove one winner/one stale for divergent updates,
  one version increment for equal updates and consistent update-versus-delete;
- schema 1004 fresh install, real 1003→1004 upgrade, DDL-before-version retry,
  unknown partial-table failure, health drift, FKs, checks and indexes run on
  real MariaDB and preserve pre-existing data;
- no Note mutation writes Library ActivityEvent, no Timeline/private-event
  engine or global full-text Note search is introduced;
- production composition exposes named Note services/rendering only, never the
  repository, ID generator, sanitizer/policy or unrestricted writer;
- the canonical full gate keeps all F2.3, F2.5, F2.6, Library isolation,
  migration, concurrency and WordPress-smoke regressions green.

The formal implementation and verification record is
`docs/11-f2-7b-exit-evidence.md`.

## 34. F2.8 Ratings & Reviews acceptance evidence

The 79 numbered cases in `docs/12-f2-8a-ratings-reviews-analysis.md` remain the
binding contract. F2.8b proves exact Rating/Review validation and escaping,
owner isolation, Round cardinality and explicit delete choices, source and
Publication CAS, server-issued ID retry, publish eligibility and lifecycle,
moderation authorization/reasons, minimal public DTOs, exact personal/public
averages, schema 1005 install/upgrade/retry/health and all earlier regressions.
Dedicated independent-process tests cover concurrent unlinked/Round creation,
divergent/equal source updates, cross-Library publication, publish/delete,
withdraw/moderate and eligibility loss under the shared Library lock.

The formal case-family mapping and exact gate results are recorded in
`docs/13-f2-8b-exit-evidence.md`.

## 35. F2.9 Hierna lezen acceptance evidence

The 73 numbered cases in `docs/14-f2-9a-next-reading-analysis.md` are the
binding contract. F2.9b proves closed immutable Work/Item/ExternalLoan targets,
server-derived ownership and Work, concrete-source snapshots, source-delete
retention, target and position uniqueness, one owner listversion, contiguous
append/compaction, complete reorder, semantic no-op and typed stale state.

Real MariaDB covers the two schema-1006 tables, nullable `SET NULL` live FKs,
equivalent trigger-backed live-target shape enforcement, generated uniqueness,
fresh install, real 1005 upgrade, retry, unknown partial state, health and data
drift. Independent processes prove all specified add/reorder/delete/source-
delete races. Owner privacy, first-three unfiltered Home output, bounded
server-ID retry, zero ActivityEvents, named composition and all F2.6–F2.8
regressions remain part of the canonical gate.

The formal case-family mapping and exact gate results are recorded in
`docs/15-f2-9b-exit-evidence.md`.

## 36. F2.10 Library Identity & Context acceptance evidence

Acceptance:

- automatic personal provisioning persists exactly `Mijn Bibliotheek` and
  remains idempotent, transactional and concurrency-safe;
- Library ID, normalized name, type and status round-trip through persistence;
- schema 1007 performs a real 1006 upgrade, backfills missing/empty supported
  Library names, enforces `NOT NULL` plus non-empty CHECK, is safely retryable
  and is a data/schema no-op after convergence; its actor-membership index is
  present with the expected `(user_id, library_id)` order;
- an actor Library list is derived from the authenticated actor and includes
  only active memberships, stable identity, designated-personal marker and
  server-calculated capabilities;
- explicit context resolution accepts only a target Library ID, rebuilds
  `LibraryContext` in Core and rejects missing, foreign and inactive access
  without trusting client state;
- two actors and multiple Libraries retain tenant isolation; Library roles do
  not grant another user's private data ownership;
- `CoreApplication` exposes the named context query service but no repository,
  generic resolver or caller-supplied actor/context boundary;
- all earlier migration, provisioning, authorization, concurrency and smoke
  regressions remain green through the canonical full gate.

No-go:

- an optional/UI-only Library name;
- trusted cookie, page, form or JavaScript Library Context;
- a capability flag used as the final mutation authorization decision;
- direct membership, table or repository reads from Elementor;
- expansion into F2.11 catalog DTOs, F2.12 REST or F3.0 Elementor.

The exact gate record is `docs/17-f2-10-exit-evidence.md`.

## 37. F2.11 Catalog UI Read Models acceptance evidence

Acceptance:

- overview resolves authenticated actor plus explicit F2.10 Library Context
  before reading catalog data;
- only active Items from the validated Library are returned, ordered by Work
  title then Item ID with a typed continuation cursor and page size 1–100;
- empty Libraries return an empty page without technical error or fake Item;
- overview and detail expose named Library/Item/Work/Edition identities,
  reliable title, active state and adapter-independent DTOs;
- author, cover, ISBN, language, publisher, publication date, Series,
  Location, Condition, Acquisition and availability are explicit `unknown`
  while the current schema has no reliable source; no placeholder is invented;
- personal status is derived from actor-owned ReadingRounds for never-read,
  active, stopped-only, completed, stopped-after-completion and historical
  completion combinations;
- detail counts active, completed, stopped and historical completed rounds
  without exposing another user's records;
- view/start capabilities come from the validated F2.10 context plus exact-
  source active-round state; view-only access cannot start and capability
  output never authorizes a mutation;
- missing and foreign Item IDs share one non-enumerating failure; foreign or
  inactive Library Context fails before the Item projection;
- one projection query per overview/detail avoids Item-level N+1 and an
  EXPLAIN assertion proves the bounded `items_by_library` access path;
- production `CoreApplication` exposes the named read service but no query
  repository, wpdb row, serializer or generic table interface;
- all F2.10 context, F2.6 Reading and catalog creation regressions remain part
  of the complete canonical gate.

No-go:

- trusting client actor, context or capability values;
- unbounded Item lists, archived Item mixing or unstable pagination;
- storing a second catalog reading-status truth;
- empty strings or fabricated values for unimplemented metadata;
- REST, WordPress Abilities, Elementor, search/filter, archive or rich catalog
  persistence inside F2.11.

The exact gate record is `docs/18-f2-11-exit-evidence.md`.

## 38. F2.12 WordPress REST Adapter Foundation acceptance evidence

Acceptance:

- `biblio/v1` registers the four first-slice routes exactly once through
  `rest_api_init`, with an explicit authentication permission callback on each;
- current WordPress identity is the only actor source; request `user_id`, role
  or capability claims cannot switch actor or authorize a mutation;
- cookie browser authentication uses the standard `wp_rest` nonce;
  valid/missing/invalid nonce and valid nonce with insufficient authorization
  are proven separately;
- Library/Item route IDs are typed but untrusted and reach only the existing
  F2.10, F2.11 and Reading application boundaries;
- overview preserves active-only Library scope, deterministic opaque cursor,
  page size 1–100, empty Library and server-derived status/capabilities;
- detail preserves explicit unknown metadata and makes unknown/cross-Library
  Items externally identical;
- Start Reading accepts only exact `YYYY-MM-DD`, calls the named existing
  service and leaves direct-use, source/Work and active-round invariants in
  Core;
- success payloads are named allowlists and contain no rows, owner IDs,
  technical timestamps, stacks, SQL or private exception details;
- one central adapter mapper covers authentication, transport, validation,
  not-available, conflict, unavailable-Core and internal failures, while
  WordPress retains its standard invalid-nonce code;
- real `WP_REST_Server` plus isolated MariaDB tests cover authentication,
  tenant isolation, pagination, detail, mutation, conflicts, spoofing,
  unknown fields and error privacy;
- unhealthy Core fails closed with 503, WordPress smoke stays available and no
  schema 1008, Ability, Elementor, Notes, Ratings/Reviews or Next Reading route
  is introduced;
- the complete canonical gate stays green.

No-go:

- permission callback or REST code reproducing membership, reading status,
  availability, capabilities, source consistency or lifecycle rules;
- client-supplied actor/context/capability authority;
- generic serialization or raw exception/database output;
- direct table/repository access from REST or Elementor;
- expanding beyond the first UI vertical slice.

The exact contract and Elementor Readiness Gate are
`docs/19-f2-12-exit-evidence.md`.

## 39. Elementor Vertical Slice 1A acceptance evidence

Acceptance:

- the published ordinary Page `/mijn-bibliotheek/` has one outer Elementor
  container, one Shortcode widget, exactly one `[biblio_library_app]`, one
  frontend mount and one visible view H1;
- Biblio UI assets are Page-only and the isolated UI smoke proves no Elementor
  or Core dependency inside the presentation plugin;
- URL state is limited to `library_id` and `item_id`, with working direct link,
  reload, back and forward behavior;
- overview requests active Items only and renders a semantic list from the
  allowlisted Core-backed contract; unknown optional metadata is omitted;
- detail remains non-enumerating for unknown/cross-Library Items and exposes no
  foreign title or Library identity;
- Start Reading sends one exact nonce-protected mutation, prevents duplicate
  submit and presents success/conflict only after an authoritative reread;
- invalid nonce is not retried, stale active-source conflict leaves one Round
  and client capabilities never replace server authorization;
- desktop, tablet and mobile acceptance covers no horizontal overflow,
  logical headings, visible focus, at least 44 px targets, native dialog
  labels/focus, Escape/return focus and the mobile sheet;
- the exact Elementor Page/Kit artifact clean-imports into an isolated local
  database after CSS regeneration, with Page/mount/title/asset verification;
- guarded fixture setup/cleanup refuses missing opt-in, non-local WordPress,
  another DDEV project or another host; cleanup is allowlisted, transactional,
  idempotent and leaves all formal E2E data at zero;
- `biblio_dev` and the complete non-E2E user fingerprint remain unchanged;
- the full UI, Core, PHPStan, smoke, Playwright, import, repository and secret
  gates remain green.

No-go:

- application or authorization logic in Elementor or Biblio UI;
- a new UI REST route, test-only endpoint or hidden database dependency;
- retrying a mutation after invalid nonce or creating a second Round after a
  conflict;
- broad fixture cleanup, production-capable fixture tooling, tracked
  credentials or private Biblio1 data;
- search/filter/archive or another later-slice feature entering this exit.

The authoritative counts, runtime, security proof and final **GO** verdict are
recorded in `docs/21-elementor-vertical-slice-1a-exit-evidence.md`.

## 40. Vertical Slice 1B ReadingRound end browser evidence

The local-only Playwright acceptance layer extends the existing 1A fixture,
login, browser and runner architecture. Technical coverage now includes:

- independent active ReadingRounds for completed, stopped, stale, invalid
  nonce, idempotent retry and incompatible lifecycle scenarios;
- visible overview/detail/dialog flows with one mutation, locked pending state,
  authoritative detail reread and persistence checks after reload and browser
  history navigation;
- same-owner Core-driven competing completion followed by a divergent stale
  stop request, one 409 response and no client retry;
- equal non-enumerating 404 responses for known foreign-owned and unknown
  ReadingRound IDs, including when the actor manages and directly accesses the
  foreign Library;
- a repeated identical version-1 request returning the same ended state
  without another write, and a divergent version-2 request returning 422
  without changing the completed state;
- invalid nonce returning 403 once and leaving the round active;
- native dialog labels, radio keyboard operation, Escape/return focus, modal
  focus containment, pending dismissal lock and responsive behavior at
  390x844, 900x900 and 1280x900;
- explicit refusal checks for missing opt-in, non-local WordPress, another
  DDEV project and another host;
- exact allowlisted cleanup twice, zero formal fixture residue and an unchanged
  before/after fingerprint of all Core-table rows and non-E2E users.

The formal 2026-08-30 exit rerun is **GO**: 230 Core unit tests with 882
assertions, 209 Core integration tests with 1693 assertions, 112 frontend
tests and 13 Playwright cases (5 existing 1A, 8 new 1B) all pass. The targeted
ReadingRound end plus REST run passes 33 tests with 353 assertions. PHPStan,
syntax, Composer, schema/migrations, WordPress/REST smoke, manifest, diff,
fixture refusal/cleanup/residue/fingerprint and the clean canonical Elementor
1A import are green. All 67 criteria are proven; see
`docs/22-elementor-vertical-slice-1b-exit-evidence.md`.

## 41. Vertical Slice 1C.2 Reading History Core read model evidence

Acceptance:

- the current actor is resolved server-side and every page is scoped by exact
  actor + Work; caller owner override and Library Context are absent;
- completed and stopped rounds share one newest-first stream, while active
  rounds, another actor and another Work are excluded;
- same-Work rounds through another Item, another Edition, an ExternalLoan,
  `historical_manual` and `legacy_source_started` are included without joining
  source metadata;
- the entry exposes only outcome, nullable precision-aware start, required
  precision-aware finish, coarse source type and historical-registration flag;
  it exposes no technical or resource identity;
- exact, month and year precision roundtrip without invented parts; a legacy
  UTC start projects as null;
- finish earliest DESC, finish latest DESC and internal ReadingRound ID DESC
  form a deterministic non-semantic order;
- page size defaults to 10, has maximum 50, fetches limit + 1 and emits a
  validated continuation cursor; zero, below-limit, exact-limit, over-limit,
  multi-page and tied-boundary cases have no skips or duplicates;
- one service page performs exactly one projection query and no N+1, Work,
  Item, Edition, Library or ExternalLoan lookup;
- a 2,075-row integration fixture proves that large same-actor/other-Work,
  other-actor/same-Work and unrelated populations do not alter the 75-row
  actor+Work result;
- a separate 50,000-row local MariaDB proof selected
  `reading_rounds_by_user_work_finish` with `range` access for first and cursor
  pages, estimated and read exactly the 600 target rows and left zero residue;
- the precision-aware filesort is confined to the actor+Work partition and
  uses the limit priority queue, so no new index or schema migration is needed;
- reads write no Library ActivityEvent or private audit record;
- the production `CoreApplication` exposes the named service, not its
  repository or wpdb adapter;
- ReadingRound, Catalog UI readmodel and the canonical full Core quality gates
  remain required regressions.

No-go:

- unbounded aggregate hydration, offset pagination or a per-entry query;
- Library role access to another user's history;
- technical timestamps or filled unknown date parts as reading dates;
- public ReadingRound/source identities or raw provenance in an entry;
- a schema/index change without a separate measured migration decision;
- REST, Itemdetail, Biblio UI, Elementor, Crocoblock or E2E expansion inside
  this Core-only slice.

## 42. Vertical Slice 1C.3 Reading History REST evidence

Status: **GO**

Acceptance evidence:

- exactly six `biblio/v1` routes register once; Reading history is GET-only and
  has an explicit coarse authenticated permission callback;
- cookie authentication plus valid, absent and invalid `X-WP-Nonce` behavior
  follows the existing private GET convention: 200, 401 and WordPress 403;
- the controller calls only the named Core Reading-history service; no REST
  repository/table access or Library authorization rule was added;
- request parsing accepts only `work_id`, optional `limit` and optional cursor;
  invalid IDs, zero/over-maximum/non-integer limits, malformed/version-invalid
  cursors and owner-spoofing query fields are rejected with safe 400 errors;
- default 10, explicit pagination, maximum 50 and keyset cursor roundtrips are
  covered, including identical finish-date ties with no duplicate or skipped
  entries;
- every page is re-scoped to the authenticated actor and URL Work; unknown,
  empty and foreign-only history yield the same empty 200 page, including when
  the actor is a manager of the foreign source Library;
- the response envelope allowlists only `items` and `next_cursor`; every item
  has exactly outcome, nullable start, finish, coarse source type and historical
  registration, with exact/month/year/null precision preserved;
- negative assertions exclude user, Library, Work, Item, Edition, ExternalLoan
  and ReadingRound IDs, version, raw provenance and technical timestamps;
- Core unavailable maps to 503 and the existing mapper retains generic 500
  privacy for unexpected failures;
- ReadingRound row counts and Library ActivityEvent counts are unchanged by
  reads; Itemdetail's exact field contract still has no history field;
- Schema 1007, ReadingRound lifecycle, Biblio UI, Elementor, Crocoblock and E2E
  fixtures remain unchanged.

No-go:

- accepting an actor, Library role or capability as history authority;
- returning a history-specific 404 or any private identity/raw provenance;
- offset or unbounded pagination, cursor scope transfer or write side effects;
- embedding history into Itemdetail or expanding into UI/E2E in this slice.
