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
- every entry has stable server identity, exactly one Work and optional typed preferred source;
- identical Work, source and complete entries may duplicate;
- preferred Item validates view access and same Work; preferred ExternalLoan validates owner and same Work;
- preferred source can change/clear without changing entry identity;
- source loss preserves entry, Work, order and version and projects generically;
- successful active ReadingRound start consumes at most one deterministic matching entry atomically;
- explicit start-from-entry consumes exact entry ID and leaves duplicates;
- failed start/consume rolls back Round and list; historical registration consumes nothing;
- manual remove returns short-lived owner-scoped one-use Undo restoring the same entry snapshot;
- source becoming unavailable never auto-removes;
- manual order persists with one owner list version and semantic no-op behavior;
- Library roles do not override ownership and no mutation emits Library ActivityEvent.

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

Search/query acceptance:
- ordinary text is partial, case-insensitive and accent/diacritic-tolerant;
- live Search starts from two characters and only the newest valid query may
  determine results;
- exact identifiers may be recognized and ranked as exact matches;
- contained title/Auteur/Co-auteur/Serie may return the omnibus Item with
  `Bevat:` context, never a virtual contained Item;
- relevance remains primary during Search, with alphabetical title inside
  equal relevance.

Filter acceptance:
- values within one group use OR and groups combine through AND;
- changes apply directly without an Apply button;
- every active value has a removable chip and `Alle filters wissen` exists;
- the first v2.001 implementation wave contains Leesstatus, Auteur, Serie,
  Locatie, Boeksoort, Genre, Onderwerp, Collecties and `Zonder collectie`;
- Collection multi-select uses OR, combines through AND, yields each Item once,
  supports exclusive `Zonder collectie` and offers active Collections only;
- Taal, Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds` remain
  **deferred within v2.001**, not removed, rejected or future-version-only;
- no varying physical/digital form filter exists in v2.001.

Sort acceptance:
- `Titel A–Z` is default and `Auteur A–Z` is available;
- `Serievolgorde` is available only with an active Serie filter and puts
  unknown volume numbers last;
- date, Location, Rating and other unlisted sorts are absent in v2.001;
- alternate sort does not override canonical relevance order during Search.

State/archive acceptance:
- allowlisted stable URL state reproduces Search/filter/sort and browser
  Back/Forward restores it;
- without explicit URL state, session fallback is scoped to authenticated user
  + Library + module;
- URL/session state never saves a permanent preference;
- `Archief tonen` or `Ook in archief zoeken` produces one mixed active/archive
  result list and every archived Item is labelled `Archief`.

No-result:
- no hidden external metadata search;
- add Item requires valid target Library and authorization.

## 13. Biblio Home and Bibliotheek Home

Acceptance:
- Biblio Home remains outside one active Library Context and exposes accessible
  Libraries/opening/switching without presenting the full Library shell as
  active;
- opening a Library establishes the explicit server-authorized Library Context;
- within that context, Home / Action Center and `Mijn Bibliotheek` full catalog
  remain separate primary destinations;
- Bibliotheek Home stays selective and does not enumerate the full catalog;
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

Status: **historical evidence — superseded for the current contract**

The 73 numbered cases in `docs/14-f2-9a-next-reading-analysis.md` were the
binding F2.9 contract at closure time. F2.9b proved closed immutable Work/Item/ExternalLoan targets,
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

The later current contract and replacement acceptance evidence are canonical
in `docs/28-next-reading-contract-correction.md` and §53 below.

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
  that historical F2.12 slice introduced no schema 1008, Ability, Elementor,
  Notes, Ratings/Reviews or Next Reading route;
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

## 43. Vertical Slice 1C.4 Reading History UI evidence

Status: **GO**

Acceptance evidence:

- the detail contract remains authoritative and strictly validates `work_id`
  before the UI constructs the fixed-limit owner-history request;
- history loading, empty, error, ready, pagination and refresh state remain
  separate from `currentDetail`; a valid Itemdetail renders first and is never
  replaced by a history failure;
- the response validator requires exactly `items` plus `next_cursor`, and
  exactly the five approved entry fields with valid outcome, source type and
  precision-aware dates; malformed or additional fields fail locally;
- empty history renders no section or H2, while non-empty history renders a
  semantic list with textual `Uitgelezen`/`Gestopt` outcomes;
- Dutch exact/month/year dates and nullable starts render without ISO copy,
  timezone conversion or fabricated precision;
- presentation exposes only `Externe lening` or the priority label
  `Historische registratie`; Library and unknown sources add no speculative
  label and no private/technical identity is rendered;
- initial loading and safe 400/malformed, 401, invalid-nonce 403, 503/500
  recovery stay local and require explicit user action;
- `Meer laden` uses only the last valid opaque cursor, locks duplicates,
  appends in server order, replaces the cursor and preserves entries plus the
  same retry cursor on failure;
- pagination focus remains on the continuing button or moves to the history
  heading when the button disappears; initial passive loading moves no focus;
- the active navigation AbortSignal plus generation/revision checks prevent a
  late old-Work response from updating a newer route;
- direct Item links and fresh runtime reloads request the matching Work history
  without relying on previous in-memory navigation;
- Start Reading preserves ended history and adds no duplicate history GET;
  End Reading first rereads authoritative detail and only then refreshes page
  1 from GET, resetting pagination without trusting the POST acknowledgement;
- a failed post-End history refresh retains authoritative ended detail and old
  entries with a local stale-history warning and retry, and never sends a
  second mutation;
- the one-column wrapping layout, native list/headings/status controls,
  existing visible focus and 44 px control baseline remain usable across the
  existing breakpoints;
- frontend contract/runtime/view/design tests, complete Biblio UI JS and smoke
  gates, relevant Core REST regression, syntax, manifest and repository gates
  are required before the local 1C.4 commit.

No-go:

- embedding history in detail state or blocking/replacing valid detail;
- deriving history from POST data, automatic mutation retry or client-side
  authorization;
- fabricated date precision, speculative source copy or exposed IDs;
- Core, REST, Elementor, Crocoblock, E2E, schema or migration changes.

## 44. Vertical Slice 1C.5 Reading History responsive/accessibility evidence

Status: **GO**

Acceptance evidence:

- the existing detail grid and breakpoint families remain authoritative:
  mobile `<768px`, tablet `768–1023px` and desktop `>=1024px`; history is one
  column within the readable detail width at every breakpoint;
- structural 320 CSS-pixel and 200% zoom/reflow contracts exclude history
  fixed widths/heights, nowrap, ellipsis, hidden overflow, absolute
  positioning and horizontal scrolling;
- exact, month, year and null-start date strings remain intact, including long
  Dutch month names, ten entries and appended pages;
- non-empty history has one H2, one native `ul` and native `li` entries; zero
  history has no H2, section, residual spacing or hidden control;
- textual outcomes and source context remain sufficient without color or
  icons; no entry is interactive or focusable;
- the stable subordinate region alone owns `aria-busy`; short history updates
  are separate polite live messages and the complete list is not live;
- history live messages do not claim `role=status`, preserving the existing
  unique Itemdetail/Reading mutation status contract;
- retry and load-more are native controls with visible accessible names,
  genuine disabled behavior, the existing focus ring and at least 44 px
  target size; mobile recovery/load-more controls are full width;
- passive initial success/error, explicit initial retry and automatic
  post-End refresh request no focus movement;
- successful pagination focuses only after the replacement DOM is connected:
  the continuing button remains in normal tab order, while a disappearing
  final button moves focus to an H2 with `tabindex=-1`;
- pagination failure retains entries and deliberately focuses its local
  recovery control; retry follows the same success focus rules;
- post-End refresh and refresh failure do not override existing End Reading
  reconciliation focus; the error states that reading status was updated but
  history could not be refreshed and exposes no technical detail;
- all history rules are scoped below the Biblio UI root and use existing
  `--biblio-*` tokens; measured text/control contrast remains at least 4.5:1
  and the focus color at least 3:1 against white;
- history defines no animation or transition, so no additional reduced-motion
  rule is required;
- 106 targeted history/design/runtime/detail/Start/End tests and all 135 UI
  tests pass; PHP/JS syntax, UI smoke, 28 REST tests with 425 assertions and
  Core smoke are green;
- the unchanged existing 1A/1B Playwright suite passes 13/13 with guard
  refusals, cleanup twice, zero residue and unchanged non-fixture fingerprint.

No-go:

- changing History, REST/Core or Start/End Reading semantics;
- adding global shell styling, Elementor/Crocoblock coupling or a new
  breakpoint system;
- adding a second competing status role or announcing the complete list;
- adding or changing an E2E case/fixture, schema or migration in 1C.5.

## 45. Vertical Slice 1C.6 Reading History browser evidence

Status: **GO**

Acceptance evidence:

- the local-only fixture retains the exact opt-in, WordPress `local`, DDEV
  project and hostname guards and expands only explicit `e2e-*` allowlists;
- deterministic setup contains 2 Libraries, 18 Items, 16 Works, 17 Editions,
  1 ExternalLoan, 29 ReadingRounds, 3 memberships, 2 designations, 16 catalog
  contexts, 42 classification terms, 16 activity events and 2 marked users;
- one shared Work has 13 actor-owned ended rows plus an excluded active row
  and excluded foreign-owner row, with same-Item, same-Edition/other-Item,
  other-Edition, ExternalLoan, historical-manual and legacy sources;
- exact `12 maart 2025`, month `maart 2025`, year `2024` and legacy null-start
  `Afgerond 2 februari 2025` render without a fabricated legacy/local or
  partial-date day;
- zero-history and active-only detail show neither history H2, list nor
  load-more control; active-plus-ended detail keeps active status separate;
- initial page is exactly 10 rows, one opaque-cursor request appends 3 rows in
  server order, all 13 texts are unique and the final H2 receives focus;
- initial failure and page-two failure remain local, preserve valid detail or
  page-one entries and recover explicitly; page-two retry uses the identical
  cursor; a Reading History-specific 403 exposes session recovery without an
  automatic request loop;
- the successful End case observes exactly one POST, authoritative detail GET
  and then history page-one GET; holding the GET proves no POST-derived entry,
  and reload proves persisted server truth;
- failed post-End history refresh retains authoritative ended detail and old
  history; explicit retry performs only another history GET and never repeats
  the mutation;
- a direct link, reload, back/forward and a controlled delayed old-Work
  response prove route isolation without timing sleeps;
- 390×844, 900×900 and 1280×900 browser checks prove semantic H1/H2/ul/li,
  native 44 px controls, keyboard pagination focus and zero document/history
  horizontal overflow;
- Reading History Playwright passes 11/11; unchanged 1A/1B passes 13/13; the
  combined one-worker run passes 24/24;
- both cleanup passes report zero for every fixture entity, including
  ExternalLoan and source-free/legacy ReadingRounds, and the non-fixture
  fingerprint remains identical;
- UI passes 135/135; targeted Core readmodel passes 7 tests/157 assertions;
  REST passes 28/425; full Core passes 242 unit tests/919 assertions and 219
  integration tests/2006 assertions; Composer/platform, PHPStan, syntax,
  schema/migrations, smoke, manifest and diff checks are green.

No-go:

- treating this evidence step as final 1C exit evidence;
- changing Core/REST/UI product contracts, Elementor/Crocoblock, schema or
  migration to make a browser case pass;
- broad cleanup, unmarked identities, non-local execution or test-order
  dependence.

## 46. Vertical Slice 1C.7 formal exit evidence

Status: **GO — formally closed**

The final exit audit classifies the 141 prescribed criteria plus eight
additional binding 1C contract points: **149 BEWEZEN, 0 GEDEELTELIJK BEWEZEN,
0 NIET BEWEZEN**. The additions make strict browser response validation,
fail-closed stored projection data, exact once-only route registration, read
side-effect freedom, contrast, scoped token CSS, absence of new motion and
unknown-Work empty semantics explicit.

Fresh canonical evidence:

- complete Core: 242 unit tests/919 assertions and 219 integration tests/2005
  assertions; Composer metadata, platform requirements, PHP syntax, PHPStan
  level 6, schema/migrations/integrity and WordPress smoke are green;
- focused Core Reading History: 12 unit tests/31 assertions and 7 real-MariaDB
  integration tests/157 assertions;
- complete REST: 28 tests/425 assertions, including six routes registered
  once, GET-only history, auth/nonce, input/error, non-enumeration, cursor and
  exact serializer contracts;
- complete frontend: 135/135; targeted history/detail/runtime/navigation/
  design 85/85; Start Reading 6/6; End Reading 19/19; PHP/JS syntax and UI
  smoke are green;
- complete browser: Reading History 11/11, unchanged 1A/1B 13/13, combined
  24/24 with one worker;
- four guard refusals pass, deterministic setup has the documented exact
  entity counts, both cleanup passes report zero and the non-fixture
  fingerprint is identical;
- the unchanged canonical Elementor 1A artifact retains SHA-256
  `4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a`
  and passes a fresh clean import; Crocoblock remains unused;
- the committed 50,000-row performance proof remains applicable because no
  post-1C.2 commit changed query, schema or index code.

Responsive acceptance is GO. Accessibility is GO within the Vertical Slice
acceptance contract; a full WCAG audit is a documented non-blocking future
assurance activity. There are no privacy, security, lifecycle, pagination,
performance, responsive or browser blockers.

The complete matrix, architecture evidence, exact fingerprint and known
limitations are recorded in
`docs/24-elementor-vertical-slice-1c-exit-evidence.md`.

## 47. Vertical Slice 1D.2 Private Notes read boundary

Status: **GO**

Acceptance evidence:

- the application boundary resolves the actor internally and performs one
  existing owner+Work bounded repository query;
- zero, one and multiple Notes remain valid; other-Work and foreign-owner Notes
  are excluded even for the same Work identity;
- each adapter view exposes only opaque Note ID, render-validated safe HTML and
  version; page state exposes only a nullable internal continuation cursor;
- user, Work per item, Library, Item, Edition, ReadingRound, source content,
  provenance and technical timestamps are absent from the view;
- the existing exact safe-HTML policy is reused at hydration and render; no
  second sanitizer or expanded allowlist exists;
- corrupt stored content fails closed before projection;
- ordering remains `updated_at DESC, private_note_id DESC`; equal timestamps
  use descending Note ID; pagination uses the existing default 50, maximum 100,
  keyset cursor and `limit + 1`;
- two fixed pages have no duplicate or skipped Note and each page costs one SQL
  query with no N+1;
- existing owner-scoped member read, create without ReadingRound, semantic and
  stale update, conditional hard-delete, schema 1004/1007 and zero ActivityEvent
  contracts remain green;
- `CoreApplication` exposes the named view service, never a repository.

Canonical Core verification passes Composer/platform, syntax, PHPStan level 6,
247 unit tests/939 assertions, 221 real-MariaDB integration tests/2,048
assertions in the canonical gate; the final complete integration rerun with
explicit member/delete evidence passes 221 tests/2,049 assertions.

No-go:

- serializing `PrivateNote` aggregates or raw content directly;
- accepting client actor/Library authority or changing Work-wide multiplicity;
- adding a REST codec/controller, UI, schema, migration, ActivityEvent or audit
  feature in 1D.2.

## 48. Vertical Slice 1D.3 Private Notes REST API

Status: **GO**

Acceptance evidence:

- exactly four Note operations register once under `biblio/v1`: Work-wide GET
  and POST collection plus PATCH and DELETE member operations;
- all operations use the standard cookie/`X-WP-Nonce` boundary and server-side
  actor; no client owner, Library Context or Library role can authorize;
- collection output is exactly `items` plus `next_cursor`; each item is exactly
  `private_note_id`, render-validated `content_html` and `version`;
- GET proves zero, one/multiple, foreign isolation, Work isolation, existing
  ordering, equal-timestamp ties, default 50, maximum 100, continuation and no
  duplicates/skips;
- the dedicated version-1 cursor is canonical URL-safe and strictly decoded;
  cross-actor and cross-Work use never grants source data because every page
  reapplies actor+Work scope;
- POST accepts only content, creates without ReadingRound and returns 201 from
  authoritative rendered Core state;
- PATCH accepts only content plus positive integer expected version, returns
  200 for write/semantic no-op/stale-identical and 409 for stale divergence;
- DELETE accepts only positive integer expected version, returns empty 204 on
  success, 409 when stale and generic 404 when foreign/unknown/deleted;
- malformed path/query/body, wrong types, unsupported fields and invalid
  cursors are 400; malformed JSON remains WordPress `rest_invalid_json`/400;
  authentication is 401 and invalid cookie nonce remains WordPress 403;
- invalid/empty/attributed/scripted/oversized content is safe generic 422;
  raw request content is never echoed and corrupt stored HTML fails closed as
  privacy-safe 500;
- Core unavailable remains 503 and unexpected failures disclose no exception,
  SQL, identity or object data;
- focused Private Notes REST passes 11 tests/311 assertions; complete REST
  passes 39/747; relevant unit/readmodel passes 16/314; focused PrivateNote
  persistence/concurrency/schema/migration passes 26/237; PHP syntax and
  PHPStan level 6 are green;
- complete Core unit passes 247 tests/939 assertions and complete real-MariaDB
  integration passes 232 tests/2,371 assertions in the final canonical gate;
- Composer metadata/platform, complete PHP syntax, PHPStan level 6,
  schema/migrations, WordPress smoke, manifest and Git whitespace are green.

No-go:

- REST access to repositories/wpdb, aggregate/reflection serialization, raw
  content echo, cursor authorization or client actor/Library authority;
- Core/domain/persistence/schema changes, UI/Elementor/Crocoblock/Playwright,
  ActivityEvent/private audit or ReadingRound product changes in 1D.3.

## 49. Vertical Slice 1D.4 Private Notes UI

Status: **GO**

Acceptance evidence:

- Private Notes start only after authoritative Item-detail validation and use
  only its Work ID; URL/Item identity never becomes Note scope;
- the Item hierarchy is `Lezen`, `Leesgeschiedenis`, `Privénotities`,
  `Uitgave`, `Exemplaar`; the H2 and add action remain present at zero Notes;
- the collection is multi-Note, uses native list semantics, renders complete
  safe content and exposes only per-record edit/delete controls;
- page size is the explicit UI choice 10; opaque cursor continuation appends in
  server order, deduplicates defensively, preserves existing Notes on error and
  retries exactly the same cursor once per explicit action;
- the exact Note/page response allowlists reject malformed fields, IDs,
  versions and cursors locally without replacing Item detail;
- saved HTML is parsed in an inert template, checked against exactly `p`, `br`,
  `strong`, `em`, `ul`, `ol`, `li`, `blockquote` without attributes and
  reconstructed with new DOM nodes only inside the Note body;
- one active constrained editor supports that same subset, strips editor
  attributes, normalizes browser `div`/`b`/`i` equivalents, escapes text,
  reduces rich paste to plain text and fails closed on unsupported DOM;
- create stays local until Save; POST sends only content once, update PATCH
  sends only content plus authoritative expected version once, and neither
  mutation performs local version arithmetic or optimistic saved rendering;
- semantic/stale-identical 200 is authoritative success; divergent update 409
  preserves local intent and offers explicit GET refresh without retry/merge;
- dirty compares the canonical serializer output with the canonical saved
  baseline, clears after exact revert, guards clean/dirty Cancel and internal
  push/pop navigation, and owns `beforeunload` only while dirty;
- delete uses a named native dialog with exact approved copy, idle Escape,
  cancel-first focus, pending dismissal/duplicate locks, target ID plus current
  version and no undo; 409/404 never remove the Note silently;
- every successful POST/PATCH/DELETE performs fresh page-1 reconciliation and
  resets continuation state; refresh failure never repeats the mutation and
  exposes GET-only list recovery;
- initial, pagination, editor validation, stale/unavailable, authentication,
  nonce and uncertain-outcome failures remain local and preserve the maximum
  confirmed state;
- navigation generation, AbortSignal and request revision guards prevent late
  Work/page/mutation-refresh responses from writing stale state;
- accessibility baseline includes H2, native list, visible editor label/help,
  native buttons/toolbar/textbox semantics, associated errors, busy states,
  named dialogs, idle Escape, focus return and existing 44px controls;
- responsive baseline uses the existing detail column, full-width wrapping
  editor, wrapping toolbar/actions and the existing mobile bottom-sheet dialog
  at `<768px`, without horizontal overflow;
- Start Reading, End Reading and Reading History state and requests remain
  independent; no Note reload is coupled to ReadingRound mutations.

Fresh verification:

- focused Private Notes frontend: 29 tests, PASS;
- complete Biblio UI JavaScript: 167 tests, PASS;
- Start/End Reading view selection: 25 tests, PASS;
- Reading History module: 8 tests, PASS;
- app runtime/detail/router selection: 74 tests, PASS;
- JavaScript syntax, Biblio UI PHP syntax and isolated UI smoke: PASS;
- focused Private Notes REST: 11 tests/311 assertions, PASS;
- complete `RestApiTest`: 39 tests/747 assertions, PASS; the deliberately
  logged privacy-safe unexpected-failure diagnostic is expected test output;
- manifest JSON and Git whitespace: PASS.

No-go remains any Core/domain/application/repository, schema/migration, REST
route/contract, Elementor/Crocoblock, Playwright/E2E, ActivityEvent/private
audit, Note title/context, autosave or new product-field delta. None occurred.
The Biblio UI asset version remains `0.2.0` under the established convention
that the prior Reading History and ReadingRound UI feature commits did not
bump it. 1D.5 may perform only dedicated responsive/accessibility polish and
acceptance without reopening CRUD, state or security semantics.

## 50. Vertical Slice 1D.5 Private Notes responsive/accessibility polish

Status: **GO WITH CONDITIONS**

Acceptance evidence:

- product semantics, Work-wide multi-Note scope, hierarchy, page size,
  formatting allowlist, manual-save CRUD, concurrency and reconciliation are
  byte-for-byte contractually unchanged;
- mobile `<768px`, tablet `768–1023px` and desktop `>=1024px` retain the
  existing readable detail column; Notes/editor are explicitly full available
  width with zero minimum inline size and long-content wrapping;
- 320/390 CSS px and effective 200% reflow have one column, wrapping toolbar
  and actions, usable full-width mobile controls and no Notes/document overflow
  in structural contracts; 900px tablet and 1280px desktop remain contained;
- Note content has no nowrap, ellipsis, fixed-height truncation, hidden content
  overflow or fixed inline width; paragraphs, lists, blockquotes and long
  unbroken strings remain complete;
- dialogs are root-scoped, compact at tablet/desktop and mobile bottom sheets
  with bounded dynamic-viewport height, internal scrolling and safe-area-aware
  bottom padding;
- exact one detail H1 remains outside 1D ownership; Notes contributes one H2,
  no artificial record headings, no empty list at zero, and native `ul`/`li`
  with member-local actions when records exist;
- the editor has a visible programmatic label, multiline textbox semantics,
  help/dirty/error descriptions, `aria-invalid`, preserved selection/cursor
  behavior and no `role=application`;
- all five formatting controls are native named buttons, expose/update
  `aria-pressed`, retain native Enter/Space behavior and add no unsupported
  format;
- dirty state has visible and programmatically associated text; clean Cancel
  returns to Add/Edit, dirty retain returns to the editor, dirty discard either
  returns to read state or performs the intended navigation exactly once;
- successful create/update preserves scoped confirmation focus across the
  asynchronous page-1 reread; delete success focuses confirmation; Delete
  Cancel returns to its opener; stale refresh closes the modal before focusing
  the connected Notes H2;
- pagination focuses the continuing control, local retry after failure or the
  H2 with `tabindex=-1` when continuation disappears; existing Notes remain on
  failure;
- loading/errors use short local polite messaging and scoped `aria-busy`; the
  complete Notes list is never live and no competing whole-detail live region
  is introduced;
- initial, validation, conflict, unavailable and session errors are textual,
  preserve confirmed/input state and provide accurately named recovery such as
  `Notities vernieuwen`;
- all relevant controls retain the 44px project target and visible 2px focus
  ring; tested text/control/error contrast is at least 4.5:1 and focus contrast
  at least 3:1 against white; meaning is never color-only;
- all Biblio component selectors are scoped below `[data-biblio-ui-root]`; no
  Elementor, WordPress, theme or undocumented DOM selector is added;
- serializer allowlist, attribute rejection, plain-text paste, safe saved-HTML
  reconstruction, raw-input prohibition, abort/revision guards, pending locks,
  beforeunload lifecycle and no-mutation-retry behavior remain green.

Fresh verification:

- Private Notes frontend: 34/34 PASS;
- complete Biblio UI JavaScript: 172/172 PASS;
- app/runtime/detail/router/design: 84/84 PASS;
- Start/End Reading: 25/25 PASS;
- Reading History: 8/8 PASS;
- JavaScript syntax, Biblio UI PHP syntax, focused Biblio UI PHPStan and
  isolated smoke: PASS;
- Private Notes REST: 11 tests/311 assertions, PASS;
- complete `RestApiTest`: 39 tests/747 assertions, PASS; its deliberately
  logged privacy-safe unexpected-failure diagnostic is expected;
- Core/WordPress smoke, manifest JSON and Git whitespace: PASS.

Manual browser evidence is deliberately bounded. The existing unauthenticated
local runtime shell was measured without fixtures or mutations at 320, 390,
640 effective-width, 900 and 1280 CSS px: document/root scroll width equalled
client width and the recovery target measured at least 45 CSS px. Because no
authenticated Notes data was available, this is not claimed as Private Notes
browser acceptance or an actual 200% zoom matrix.

Condition: guarded authenticated zero/create/edit/dirty/delete/pagination/
dialog browser evidence, actual 200% zoom on real safe/long Note content,
double cleanup and unchanged non-fixture fingerprint remain 1D.6 scope. There
is no known structural responsive/accessibility defect. 1D.6 may proceed
without changing product, CRUD, state, security or UI semantics.

## 51. Vertical Slice 1D.6 guarded authenticated browser acceptance

Status: **GO WITH CONDITIONS — 1D remains OPEN**

Acceptance evidence:

- the existing local-only fixture, generated ignored credentials, authenticated
  storage state and one-worker Playwright contract are reused unchanged in
  architecture;
- five negative guards refuse missing opt-in, non-local WordPress, wrong DDEV
  project, wrong host and cleanup aimed through a non-E2E username;
- setup reuses the exact existing graph and adds only 21 synthetic Notes, with
  thirteen actor-owned pagination members and one foreign same-Work member;
- stale/unavailable changes run through owner-scoped application services;
- zero state keeps its H2/Add action and creates no list/card or mutation;
- create, update and delete each send one mutation, reconcile through one
  page-1 GET and remain correct after reload;
- semantic no-op uses the current server contract once and creates no duplicate
  mutation;
- clean/dirty Cancel, overview, other Item and browser-Back routes preserve or
  discard intent without implicit save and with the intended route once;
- dirty state alone owns the observed `beforeunload` listener lifecycle;
- delete copy, initial/cancel/success focus, hard-delete/no-undo and persistence
  match the locked contract;
- stale update/delete use old versions, return one 409, never retry mutation,
  preserve intent/state and refresh through GET only;
- externally deleted membership returns one generic 404 and GET-only
  reconciliation;
- foreign content/ID/count never reaches actor DOM or collection data; known
  foreign and unknown member requests are equivalent generic 404 and Library
  management does not transfer ownership;
- page size 10 plus one continuation GET yields all thirteen actor Notes in
  server order without duplicates/skips; final-page focus and identical-cursor
  retry are proven;
- forced post-PATCH page-1 failure leaves PATCH count one and recovers via GET;
- supported markup remains semantic, raw markup is not shown, unsupported
  script/event/style/attributes never become executable DOM and paste uses the
  real plain-text event path;
- keyboard-only Add, toolbar, editor, Save/Cancel, Edit/Delete and native dialog
  flows are operable with deliberate connected focus and no observed trap;
- real authenticated Notes pass 320×800, 390×844, 640×900 reflow-equivalent,
  900×900 and 1280×900 without document, region, body or editor overflow;
- DOM/accessible semantics prove H2, native outer list/members, labelled
  multiline textbox, named toolbar/buttons/dialogs, status and busy state;
- 16/16 new 1D and 24/24 existing 1A–1C scenarios pass: 40/40 total, one worker;
- Private Notes frontend 35/35, complete UI 174/174, app runtime 65/65,
  Private Notes REST 15/328 and complete REST 39/747 pass;
- JavaScript/PHP syntax, UI PHPStan, isolated UI smoke, Core/WordPress smoke,
  manifest JSON and Git whitespace pass;
- final cleanup runs twice, all fixture counts including Notes are zero and
  `verify-clean` passes;
- canonical sorted-JSON/SHA-256 fingerprint before and after is identical:
  Core 0 rows/hash
  `0ce27bbce14013bfb222a2eb124a0bd8647df81294cbf8b874dd395580b6eb9b`;
  non-E2E users 2/hash
  `b6d2e8b80642d96611fc23d42ea98a0105b6634ee81e34721850d3ad8ae0395d`;
  `biblio_dev_present=true`.

Two real browser defects were minimally fixed and regressed: retained dirty
popstate no longer overwrites its Back destination, and keyboard-triggered
editor render restores dropped document-body focus without stealing a later
deliberate target. Neither changes product semantics. The 1C pagination
selector was scoped to Reading History to avoid the legitimate second Notes
`Meer laden` button.

Exact 200% zoom is the only remaining condition. Automated zoom shortcuts left
headless Chromium at 1280 CSS px, DPR 1 and visual scale 1; they are not claimed
as zoom. Authenticated 640px reflow-equivalent plus 320px acceptance is green,
but no headed manual 200% smoke was performed. 1D.7 is ready only to complete
that smoke and the formal exit/audit. No full WCAG claim or 1D exit is made.

## 52. Vertical Slice 1D.7 formal exit

Status: **GO — Vertical Slice 1D CLOSED**

The exact-200%-zoom condition is closed by explicit manual acceptance: the
user tested real Private Notes in a local authenticated headed browser at
actual 200% page zoom and reported PASS for readability, horizontal reflow,
reachable create/edit controls, dialogs and keyboard/focus behavior. This is
manual evidence and is not attributed to Playwright.

Fresh formal-exit verification passes:

- Private Notes frontend 35/35 and complete Biblio UI frontend 174/174;
- Start Reading 6/6, End Reading 19/19 and Reading History 8/8;
- Private Notes REST 15 tests/328 assertions and complete `RestApiTest` 39/747;
- Playwright 1D 16/16, previous 1A–1C 24/24 and total 40/40 in Chromium on one
  worker;
- JavaScript/PHP syntax, focused UI PHPStan, isolated UI smoke and Core smoke;
- five fixture guards, cleanup twice, zero residue, `verify-clean` and equal
  non-E2E before/after fingerprint.

Complete traceability, security/privacy, concurrency, final criteria and
non-blocking polish are recorded in
`docs/27-elementor-vertical-slice-1d-private-notes-exit-evidence.md`. No full
WCAG claim is added. There are no open 1D acceptance blockers.

## 53. Next Reading current-contract correction evidence

Status: **GO**

Acceptance requires and the current Core proves:

- stable server-side entry identity with mandatory Work and optional typed
  preferred Item/ExternalLoan source;
- unrestricted content duplicates, contiguous order, one owner list version,
  exact-once mutation increments and semantic no-op behavior;
- current collection-view/same-Work validation for Item preferences and strict
  owner/same-Work validation for ExternalLoan preferences;
- preferred-source change/clear and source loss without entry, Work, order or
  version loss, with generic unavailable projection;
- schema 1007→1008 maps old Work, Item and ExternalLoan targets without reset,
  preserves entry IDs, owner, Work, positions, created-at and list version,
  removes content uniqueness and adds match indexes, shape defenses and
  owner-scoped hashed Undo persistence;
- add, remove plus Undo, reorder, preference set/change/clear and automatic
  consumption all serialize on the same owner list-state lock;
- manual removal returns a 30-second technical-default opaque owner-scoped
  one-time token and Undo restores the same entry snapshot using valid neighbor
  anchors or a bounded ordinal fallback;
- Library Item and ExternalLoan active starts share one transaction for source
  validation, ReadingRound creation and at-most-one deterministic consumption;
  explicit entry start consumes exact identity, matching elsewhere prefers the
  first exact live source then the first general Work entry;
- snapshot-only source IDs never match, failed start/required consume rolls
  back both records, no-match is valid, historical registration consumes none
  and automatic consumption creates no Undo;
- owner privacy is non-enumerating for entries, preferences and Undo; Library
  roles confer no override; no mutation or consumption emits a Library
  ActivityEvent; public projections expose no protected foreign source IDs;
- independent-process races cover remove/start-from-entry, reorder/external
  start, add/start, preference/start, Undo/reorder, Undo/consumption and
  duplicate add, plus stale manual mutation after automatic consumption;
- unit 252/252 (963 assertions) and real-MariaDB integration 239/239 (2,458
  assertions) pass, including schema/migration, lifecycle, privacy, rollback
  and concurrency families.

Docs 14 and 15 remain historical evidence but are explicitly superseded. The
formal current contract, implementation mapping and gate record are in
`docs/28-next-reading-contract-correction.md`. This correction adds no Next
Reading REST route, C7 UI, Elementor/Crocoblock change, Home module or C7
Playwright. C7 is not implemented and is **READY FOR BUILD SCOPE** with only
capability-specific discovery, REST/serialization/security, UI and E2E work
remaining; no Next Reading domain product blocker remains.

## 54. C7 Hierna lezen adapter and screen acceptance evidence

Status: **GO — C7 CLOSED**

C7 reuses the schema-1008/ADR-008 contract without domain or migration change.
Acceptance requires and now proves:

- private cookie/nonce `biblio/v1` list, add, remove, Undo, reorder and
  preferred-source set/change/clear routes;
- bounded stable title/keyset Work discovery and actor-safe same-Work source
  options across viewable Libraries plus actor-owned ExternalLoans;
- exact DTO allowlists, generic unavailable projection, owner isolation and
  non-enumerating unknown/foreign errors;
- unrestricted duplicate Works/preferences and distinct entry IDs;
- Work-first optional-source add, exact empty copy, non-clickable rows,
  authoritative reorder, direct remove and server-token Undo;
- one-in-flight mutation locks, stale reload without retry, abort/revision
  search, deliberate focus restoration and polite live announcements;
- keyboard-only operation, minimum 44px controls, narrow wrapping and the
  existing 640px 200%-equivalent reflow strategy without horizontal overflow;
- guarded fixtures for duplicate/Item/loan/unavailable/foreign/Undo state and
  schema-1008 cleanup/fingerprint coverage.

Focused REST passes 7 tests/215 assertions, focused C7 frontend passes 11 tests
and focused C7 Playwright passes 9/9 combined scenarios. Complete REST passes
46/978, complete frontend 185/185, Start Reading/consumption 3/23, Core unit
252/970, Core MariaDB integration 246/2,690 and full guarded Playwright 49/49
(unchanged 1A–1D 40/40 plus C7 9/9). Double cleanup, zero residue and the
unchanged schema-1008 non-fixture fingerprint pass. The authoritative
route, privacy, UI, non-scope and shell evidence is
`docs/29-c7-next-reading-exit-evidence.md`. A real manual browser-toolbar zoom
measurement is not claimed; the explicitly permitted equivalent reflow
strategy is used. No permanent Page or Elementor layout is created.

## 55. B7 Ratings & Reviews public read foundation

Status: **GO**

Acceptance requires and the current implementation proves:

- personal average is a dedicated owner + Work SQL aggregate with no display-
  list limit; zero, one, multiple and 101 own Ratings are covered, including a
  published/private mix and exclusions for other actor/Work;
- `GET /biblio/v1/libraries/{library_id}/works/{work_id}/assessments` requires
  WordPress cookie authentication and nonce, then authorizes through
  `LibraryContextQueryService::get()` and Core `canViewCollection` before the
  assessment repository is called;
- missing, inactive and foreign Library contexts return the same non-
  enumerating 404; malformed limits/cursors and unknown query fields return
  safe 400 responses;
- one mixed public contribution list is ordered by publication update time and
  publication ID descending, uses an opaque keyset cursor, defaults to 20 and
  has a server maximum of 50;
- every currently visible historical Rating/Review publication may occur in
  the list, while the aggregate selects at most one Rating per User + Work by
  `rating.updated_at DESC, rating_id DESC`;
- withdraw, hide, remove and source delete fall back to an older still-visible
  Rating; membership end does not retract an existing publication; active Work
  presence loss suppresses list and aggregate and restoration reveals valid
  history again;
- Rating and Review publication remain independent, Review output is escaped,
  hidden Reviews do not affect a visible Rating, and cross-Library rows do not
  leak;
- assessment unavailable failures map to non-enumerating 404, duplicate/stale
  failures to 409 and publication ineligibility to 422;
- schema remains 1008 and no migration, UI, Elementor/Crocoblock or permanent
  runtime testdata is added.

The authoritative scope, route, DTO and gate record is
`docs/30-b7-ratings-reviews-public-read-evidence.md`.

## 56. Mijn Bibliotheek Design System implementation slice

Status: **GO WITH EXPLICIT DEFERRED CAPABILITIES**

Acceptance requires:

- one root-scoped Ink/Light shell around all existing Biblio UI states;
- desktop 224px sidebar and remembered 72px rail, tablet recomposition and
  mobile off-canvas navigation with keyboard close and focus return;
- semantic token use, canonical spacing/radii direction and no component-local
  second palette;
- Grid default with 148px desktop cover work value, working List, explicit
  Bookshelf placeholder and no invented missing covers;
- a non-layout-shifting Quick View that rereads the existing authorized detail
  endpoint and retains the full detail route;
- no client-only search/filter/sort claim against a cursor-paginated partial
  result;
- no Core/REST/schema/Elementor/domain regression and no fixture residue;
- authenticated desktop/tablet/mobile geometry, overflow, keyboard/focus and
  visual artifacts.

The complete rationale and criterion mapping are in
`docs/32-mijn-bibliotheek-design-system-slice.md`. Exact Theme palettes,
production font validation, iconography, cover-ratio choice and Atmosphere
assets remain open Design System work. Search/filter/sort requires a separately
approved server read contract. No full WCAG claim is made.

Final evidence: UI smoke and frontend 191/191; focused UI PHPStan; Core unit
252/252 with 977 assertions; MariaDB integration 253/253 with 2,966 assertions;
complete Core syntax/PHPStan, Composer, WordPress smoke, manifest and whitespace;
guarded authenticated Chromium 50/50 plus final focused Quick View Chromium
1/1. Five fixture guards fail closed. Double cleanup and `verify-clean` leave
all fixture counts at zero; the before/after non-fixture fingerprint is equal
with 39 Core rows and SHA-256
`314a9fc54ef83367cb7cfa4dc4030e9c0d49fcc665a58a1fb00562abca039cfa`.

## 57. Mijn Bibliotheek server-side catalog query readiness

The finalized documentation-only product contract is recorded in
`docs/33-mijn-bibliotheek-server-side-catalog-query-readiness.md`. Product
readiness is **GO**. Technical implementation readiness remains **BLOCKED** on
the relevant source-model/migration prerequisites.

Readiness acceptance established:

- canonical Library search scope, fields, ranking classes, minimum query length,
  active default and title-ascending default order are traced to sources;
- personal `Standaardweergave`, personal per-Library `Archief tonen`, temporary
  archive search and the no-silent-default-mutation boundary are reconciled;
- the physical-only scope excludes a varying media-form filter in v2.001;
- live partial case-/accent-insensitive matching and contained-Work omnibus
  behavior are fixed;
- OR within a filter group, AND between groups, direct apply, removable chips,
  reset and Collection multi-select/`Zonder collectie`/active-option behavior
  are fixed;
- URL query state, Back/Forward, copied URLs and user+Library+module session
  fallback preserve the permanent-preference boundary;
- `Titel A–Z`, `Auteur A–Z`, conditional `Serievolgorde` with unknown volumes
  last, relevance-first Search and mixed active/archive results are fixed;
- the first implementation wave is fixed as Leesstatus, Auteur, Serie, Locatie,
  Boeksoort, Genre, Onderwerp, Collecties and `Zonder collectie`;
- Taal, Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds` remain
  explicitly deferred within v2.001 rather than removed or moved to a future
  version;
- no product decision remains for the first implementation wave;
- normalization, cursor/fingerprint, transport, SQL, tie-breaker and index
  choices are classified as technical rather than user product decisions;
- current Core → repository → REST → UI flow and exact missing layer contracts
  are mapped;
- a versioned sort-specific cursor with a canonical query fingerprint is the
  recommended technical direction, while authorization remains independent;
- schema 1008 and live local indexes/plans are inspected read-only; the local
  1-Item dataset is explicitly not treated as 1k/10k performance evidence;
- required unit, MariaDB integration, REST, frontend, E2E, security and
  performance acceptance is specified for later implementation slices;
- no production code, test, schema, runtime data or UI behavior is changed.

Implementation acceptance may not be declared until the applicable
source-model prerequisites in doc 33 are closed or explicitly removed from a
newly approved scope. This finalization itself changes no production code,
test, schema, runtime data or UI behavior.

## 58. Central Author and Series relationship foundation

Status: **GO**

Schema `1009` adds the first technical prerequisite from doc 33:

- stable central Author and Series IDs with validated UTF-8 display names;
- typed ordered Work contributors limited to `author` and `co_author`;
- duplicate-free Work-Series membership with known decimal or `NULL` unknown
  position;
- forward-only 1008→1009 migration with fresh-install, health, retry,
  unknown-partial-state and existing-Work preservation evidence;
- batched Work/Author and Work/Series repository reads in both directions;
- database foreign keys, duplicate constraints and restrictive deletion;
- no Library, Item or User key in the new central tables and no REST, UI,
  Search, filter or sort behavior.

Names may be corrected behind stable IDs. Equal names do not merge identities.
No primary-author cardinality was added because the canonical sources require
only explicit role and deterministic order. Unknown Series position stays
unknown; no external completeness claim is made.

The authoritative implementation and gate record is
`docs/34-author-series-relationship-foundation-exit-evidence.md`. Overall Mijn
Bibliotheek technical implementation readiness remains blocked on the
remaining prerequisite slices in doc 33.

Final gate evidence: Core unit 267/267 with 1,014 assertions; MariaDB
integration 261/261 with 3,030 assertions; complete syntax/PHPStan, Composer,
WordPress smoke, manifest and whitespace checks passed. The full Core gate
completed in 104 seconds.

## 59. Remaining Search metadata foundation

Status: **GO**

Schema `1010` closes Slice 2 from doc 33 with:

- validated, lossless alternative Work titles and deterministic per-Work
  duplicate identity;
- optional checksum-valid ISBN-10/ISBN-13 on Edition, distinct from explicit
  no ISBN and unknown/not entered;
- optional Item inventory number, unique per Library when present;
- ordered, duplicate-free and acyclic Work containment for omnibus/bundles;
- forward-only 1009→1010 migration with health, retry, unknown-partial-state
  and existing Work/Edition/Item preservation evidence;
- batch reads for alternative titles, Edition/ISBN and containment in both
  directions, plus authorized Library-scoped inventory reads;
- no Search execution, filter, sort, REST, UI or virtual contained Item.

The authoritative implementation and gate record is
`docs/35-remaining-search-metadata-foundation-exit-evidence.md`. Overall Mijn
Bibliotheek technical implementation readiness remains blocked on Location,
archive lifecycle, Collections and the later query-integration slices in doc
33.

Final gate evidence: Core unit 274/274 with 1,050 assertions; MariaDB
integration 270/270 with 3,079 assertions; complete syntax/PHPStan, Composer,
WordPress smoke, manifest and whitespace checks passed. The full Core gate
completed in 108 seconds.

## 60. Library Item Location foundation

Schema `1011` is accepted when:

- Location identity, Library ownership and UTF-8 display name round-trip
  losslessly; empty, invalid and overlong names fail before persistence;
- an Item supports zero or one Location and assignment can be replaced or
  cleared without duplicate membership state;
- composite Library+Location referential integrity rejects dangling and
  cross-Library relations;
- listing and both batch directions are explicitly Library-scoped,
  duplicate-free and execute without a query-per-Item contract;
- every application read authorizes Library Context before repository access;
- migration 1010→1011 preserves existing Items with `NULL` Location, is
  retryable after completion, rejects unknown partial state before version
  bump and passes fresh-install/current health;
- no Archive, Collections, Search/filter query, REST, frontend or management
  UI behavior is introduced.

Evidence is recorded in
`docs/36-library-item-location-foundation-exit-evidence.md`.
