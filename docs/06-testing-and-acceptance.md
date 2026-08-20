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
- existing-Edition creation writes only Item; compound paths commit as one
  unit or leave no partial Work/Edition/Item rows after any failed step;
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

## 29. Classification context and term management

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
- retained inactive links remain valid while every newly introduced link is
  active at the serialized validation point;
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
