# 03 — Scope and deferred

Status: **canonical V2.001 release boundary after D-SCOPE-01**.

## 1. Canonical V2.001 definition

> V2.001 is a reliable, migratable replacement for Biblio V1 for daily use.

V2.001 is assessed on migration suitability and daily usability, not on
feature completeness across all designed V2 domains. A designed or already
implemented capability can remain in the product without being a V2.001
release blocker.

A capability is V2.001-MUST only when at least one of these conditions applies:

1. it is needed to continue an everyday V1 core action in V2;
2. it is needed to migrate V1 data without meaningful loss;
3. it is needed to manage V2 catalog or reading data reliably;
4. it is needed for security, privacy, authorization, Library isolation or
   data integrity;
5. it is needed for an executable cutover and operational recovery.

A capability is not automatically MUST merely because it was previously named
for V2.001, has an approved functional design, has backend foundation, is
attractive, or was once architecturally planned.

V1 remains the source of truth until final cutover. General end-user import is
still deferred; the controlled V1-to-V2 migration required for cutover is not.

## 2. Release classes

Only these release classes are used:

- **V2.001 MUST** — required for the first reliable V1 replacement;
- **V2.001 MINIMUM IF MIGRATION REQUIRES** — only the smallest active support
  that MIG-01 proves necessary for safe migration/cutover;
- **V2.002+** — valuable product capability, but not a V2.001 blocker;
- **PRESERVE DATA ONLY** — retain recoverable source data without requiring an
  active V2.001 feature or UI;
- **DECISION REQUIRED** — a concrete product decision remains after MIG-01 has
  established the relevant V1 facts.

## 3. V2.001 release reclassification matrix

| Domain | Old status | New release class | Reason | Migration impact |
|---|---|---|---|---|
| Controlled V1 → V2 migration, dry-run, reconciliation, retry and cutover | Later controlled technical activity | V2.001 MUST | A reliable V1 replacement cannot cut over without a reproducible and explainable migration | Inventory, map, dry-run, reconcile, rehearse, cut over and prove no unexplained loss; V1 remains authoritative until cutover |
| Work, Edition, Item and canonical ISBN identity | V2.001 primary scope | V2.001 MUST | Core catalog identity is required for migrated and newly added physical books | Map every relevant V1 book/copy without silently collapsing identity |
| Add Book via ISBN, manual/no-ISBN and extra copy | V2.001 primary scope | V2.001 MUST | Everyday catalog growth must continue and provider failure may not block it | Reuse migrated identities safely; manual fallback remains operational |
| Metadata Hub minimum for Add Book | Broad V2.001 Metadata Hub target | V2.001 MUST | Normal ISBN Add Book needs one operational provider path or safe manual fallback | Provider evidence is not canonical truth; migration must not depend on live provider success |
| Provider-neutral bibliographic discovery foundation and shared search model | Previously missing dependency for broader consumer discovery | CONTRACT FOUNDATION IMPLEMENTED / TARGET MODEL CANONICAL | MH-DISC-01 remains the production Work/Edition discovery; WISH-DISC-01 consumes it for the personal Wishlist; D-SEARCH-01 fixes the Author/Work-first target and MH-SEARCH-01A now supplies its parallel typed pages, strong references and independent continuations without provider/runtime wiring | Uses no V1 data and does not change migration targets; MH-SEARCH-01B, Works-by-Author, lazy Editions, shared UI and consumer cutovers remain separate bounded slices |
| Mijn Bibliotheek Grid/List, Search/Filter/Sort | V2.001 primary scope | V2.001 MUST | Users must find and manage their migrated catalog daily | Migrated active and archived Items must remain discoverable under exact Library Context |
| Book Detail and essential Item data/management | V2.001 primary scope | V2.001 MUST | Migrated copies must be inspectable and usable after cutover | MIG-01 identifies the V1 Item fields that need active mapping versus preserved storage |
| Reading status, ReadingRounds, start/finish, rereads and history | V2.001 primary scope | V2.001 MUST | These are daily V1 reading flows and historical truth | Preserve user ownership, source identity and known date precision; source-neutral Personal Reading Truth retains read/date-unknown, explicit-not-read and explicit-unknown without fabricating a round |
| Private Notes | V2.001 primary scope | V2.001 MUST | Daily personal reading data must remain usable | Map notes owner- and Work-safely; never expose them through Library roles |
| Existing V1 ratings/reviews: migrate, retain and read | Ratings/Reviews as full V2.001 domain | V2.001 MUST | Existing user data must not disappear and must remain readable | Preserve ownership, ReadingRound links where known, publication/moderation state and readable projection |
| New rating/review create, edit, publish, withdraw and moderation UI | Ratings/Reviews as full V2.001 domain | V2.002+ | The V1 migration reference contains simple per-book rating/review writes, but D-SCOPE-01 explicitly makes Review writing/publication non-blocking for the first cutover | MIG-01 must map and retain the written V1 data and record the post-cutover limitation; V2.001 requires readability, not the new V2 write/publication lifecycle |
| Basic Collections: show, create/edit, membership, manual order and sufficient lifecycle | V2.001 primary scope | V2.001 MUST | Existing V1 Collection data must remain usable and manageable | Preserve Collection identity, membership, order and archive/lifecycle meaning |
| Smart Collections, wishlist grouping and rich Collection extensions | Partly future, partly designed adjacent scope | V2.002+ | Not required for daily V1 replacement | Preserve only source facts needed for later activation |
| Basic personal Verlanglijst: view, discover, add and remove | V2.001 primary scope | V2.001 MUST | V1 contains wishlist data and the daily list must remain usable | Core/schema/MIG-FND, owner-only REST, reachable UI and local/external title/author/ISBN discovery are implemented through WISH-DISC-01; entries remain separate from Collections, Gewenste aanwinsten and Hierna lezen |
| Wishlist grouping, smart groups and Series-completeness logic | Not uniformly classified | V2.002+ | Enrichment is not needed for first cutover | Preserve source relations if present; do not infer completeness |
| Manual Hierna lezen | V2.001 primary scope | V2.001 MUST | Explicitly retained by D-WR-01 and already has functional foundation/UI | Preserve owner, Work, duplicates, order and preferred-source meaning |
| Wat zal ik lezen? ranking, preferences, engines, API and UI | Functionally fixed for V2.001; not implemented | V2.002+ | D-WR-01 explicitly removes it from V2.001 | Preserve the approved design; no V2.001 runtime or migration target is required |
| Minimum Item/Collection archive support | V2.001 primary scope | V2.001 MUST | Existing archived V1 data must remain visible and manageable enough for daily use | Preserve identity, history and archive meaning; full new lifecycle is not implied |
| Login, logout/account reachability and release navigation shell | Broad Home/navigation design | V2.001 MUST | Every retained release flow needs a normal reachable path | No core flow may rely on a hidden direct URL as its sole normal entry |
| Library Context, authorization, privacy, isolation and Viewer/Kijker read-only boundary | V2.001 primary scope | V2.001 MUST | Security and data integrity are unconditional release gates | Reauthorize server-side; UI visibility never grants authority; no cross-Library/private leakage |
| Author/Series relations used by migration, catalog, search and detail | Central Auteur/Serie in V2.001 primary scope | V2.001 MUST | V1 bibliographic relations may not be lost and existing catalog flows need them | Map stable relations and retain unknown order without inventing completeness |
| Separate Authors/Series indexes, detail module and rich Series Intelligence | Broadly described for V2.001 with rich parts deferred | V2.002+ | A separate rich module is not needed for first daily cutover | Relations remain active data even though the dedicated module is deferred |
| Library-owned Gewenste aanwinsten feature | V2.001 primary scope | V2.002+ | Not a blocker unless V1 migration proves a minimal active target necessary | Inventory and retain V1 source data; do not silently merge with personal wishlist |
| V1 Gewenste-aanwinsten data without an active V2.001 target | Implicitly active feature data | PRESERVE DATA ONLY | Deferring UI must not discard source data | Classify as `PRESERVED_DEFERRED` unless MIG-01 proves an active minimal mapping is required |
| Active Leesdoelen feature/UI | V2.001 primary scope | V2.002+ | Goal management is not required for first daily replacement | No active release journey or UI required |
| Existing V1 goal data | Implicitly active feature data | PRESERVE DATA ONLY | Historical/personal goal data may not disappear | MIG-01 chooses mapped, preserved/deferred or quarantined; never silent discard |
| Full internal/external lending module | V2.001 primary scope | V2.002+ | Full lending is larger than first replacement needs | Inventory all circulation data and preserve lifecycle/history |
| Minimal support for migrated circulation state | Not separately bounded | V2.001 MINIMUM IF MIGRATION REQUIRES | Open V1 loans may need limited settlement support at cutover | MIG-01 proves the smallest safe lifecycle; no general lending UI follows automatically |
| Exact treatment of active/open V1 loans at cutover | Not yet decided | DECISION REQUIRED | The source facts and necessary settlement semantics are not yet known | Decide only after MIG-01 inventories open loans and presents concrete options |
| Biblio-owned cover acquisition/management | Part of broad V2.001 Metadata Hub target | V2.002+ | Truthful no-cover treatment is sufficient for V2.001 | Inventory V1 cover URLs/assets and preserve them without promising active management |
| Existing V1 cover information/assets without active cover management | Implicitly active cover target | PRESERVE DATA ONLY | Deferral may not silently lose assets or references | MIG-01 records source, ownership/licensing where known, storage and recovery path |
| Full Librarian queue, correction UI and merge tooling | Lightweight correction proposal in primary scope; rich queue deferred | V2.002+ | Data integrity/provenance can remain without a half-finished management UI | Preserve implemented provenance/foundation and provisional records |
| Rich Biblio Home, Library dashboard, Stats, Jaaroverzicht, Tijdlijn and Audit UI | V2.001 primary scope | V2.002+ | Navigation to daily core flows is sufficient for V2.001 | MIG-01 inventories relevant V1 source data and preserves it when needed |
| Existing V1 goals/stats/audit/home source data without active V2.001 feature | Implicitly active feature data | PRESERVE DATA ONLY | A deferred UI does not authorize data loss | Retain recoverable source data or quarantine ambiguity |
| Extensive platform, membership, delegated-permission and Librarian administration | V2.001 primary scope | V2.002+ | Broad administration is not required solely because roles exist | Preserve roles and permission meaning needed by migrated access |
| Minimal safe first-install/account/membership operation | Part of broad administration | V2.001 MINIMUM IF MIGRATION REQUIRES | The first installation must remain operable and secure | MIG-01 and cutover design identify only required provisioning/recovery actions |
| Bookshelf, extra Author/Series/Location/Collection option routes, social features, general import/export UI and Atmosphere Packs | Deferred or deferred-within-V2.001 | V2.002+ | None is required for the reliable first cutover | Do not fabricate controls; preserve any relevant V1 data independently |
| Institutional loan/reservation/fine systems and selectable Uitleenbibliotheek | Explicitly deferred | V2.002+ | V2.001 supports only Privébibliotheek and daily V1 replacement | Preserve source evidence if MIG-01 encounters it; do not reinterpret it as supported runtime behavior |

No `DECISION REQUIRED` item above blocks D-SCOPE-01 itself. MIG-01 must first
establish the relevant source facts; Renée remains the owner of any resulting
product choice.

## 4. V2.001 primary journeys

The compact release-acceptance set is:

1. log in and reach Mijn Bibliotheek;
2. see the V1 Library correctly migrated;
3. find an existing book;
4. add a book by ISBN;
5. add a book without ISBN;
6. add an extra copy;
7. view and manage essential Item data;
8. view Book Detail;
9. start reading;
10. finish reading;
11. start a reread;
12. view reading history;
13. use Private Notes;
14. use a basic Collection;
15. use the basic personal Verlanglijst;
16. use Hierna lezen;
17. find or retain archived V1 data correctly;
18. reach logout/account controls;
19. complete all journeys under correct Library Context, ownership and
    authorization boundaries;
20. execute migration and reconciliation without unexplained data loss.

Journeys for Wat zal ik lezen?, goal management, full lending, new
rating/review writes, rich Authors/Series, cover management, dashboards,
statistics, audit UI and extensive administration are not V2.001 release
acceptance.

## 5. V2.001 Definition of Done

V2.001 is releasable only when:

- V1 → V2 mapping is explicit and the migrator is reproducible;
- dry-run, reconciliation, idempotency/retry, a trial migration, a complete
  rehearsal and final-cutover procedure are proven;
- reconciliation explains every relevant V1 source category and there is no
  known unexplained data-loss case;
- the core catalog, essential Item data and Book Detail work for daily use;
- core reading, ReadingRounds, rereads, history and Private Notes work;
- basic Collections, personal Verlanglijst and Hierna lezen work;
- archived V1 data is correctly retained and sufficiently visible/manageable;
- existing V1 ratings/reviews are migrated, retained and readable;
- Viewer/Kijker, privacy, ownership, Library Context, authorization and tenant
  isolation are correct on every release flow;
- at least one metadata provider is operational **or** the safe manual fallback
  is operational, and provider failure never blocks manual Add Book;
- every release feature is normally reachable through the shell/navigation;
- fresh install and upgrade are healthy;
- backup, restore, cutover and operational recovery have been rehearsed;
- human responsive and accessibility acceptance is complete for release flows.

The following are explicitly **not** release blockers: Wat zal ik lezen?, Goals
UI, full lending UI, new Review/Rating writes or publication, rich
Authors/Series, owned cover management, Stats/Jaaroverzicht/Tijdlijn,
dashboards, Audit UI, extensive management UI, Bookshelf and rich Series
Intelligence.

## 6. Preserved/deferred migration principle

A feature does not have to be active in V2.001 for its V1 data to survive
cutover. MIG-01 classifies every relevant source category as exactly one of:

- **MAPPED** — it has an active V2.001 target model;
- **TRANSFORMED** — it is translated into another V2.001 structure;
- **PRESERVED_DEFERRED** — it is safely retained while V2.001 does not activate
  the associated feature;
- **QUARANTINED** — the source is ambiguous or conflicting and requires later
  review;
- **INTENTIONALLY_NOT_MIGRATED** — permitted only after an explicit product
  decision with a documented reason.

`INTENTIONALLY_NOT_MIGRATED` is never an implicit default. For cutover, every
relevant source category is inventoried, has an explicit mapping status, yields
no unexplained loss, and remains recoverable/activatable later when deferred.

## 7. Explicit V2.002+ product scope

The following active product capabilities are outside the V2.001 release gate:

- Wat zal ik lezen? ranking engine, recommendation API, preference storage,
  engines and UI; D-WR-01 is final for V2.001;
- Library-owned Gewenste aanwinsten beyond any MIG-01-proven preservation or
  minimal cutover need;
- active Leesdoelen;
- the full new lending module;
- new Rating/Review create/edit/publish/withdraw/moderation UI;
- separate Authors/Series indexes and detail module, and rich Series
  Intelligence;
- independent Biblio-owned cover management;
- full Librarian queue, correction UI and merge tooling;
- rich Biblio Home, Library Home/dashboard, Personal Stats, Jaaroverzicht,
  Tijdlijn, Library statistics and Audit UI;
- extensive platform, membership, delegated-permission and Librarian
  administration;
- Bookshelf;
- extra Author/Series/Location/Collection filter-option routes;
- wishlist grouping, smart groups and smart Collections;
- completeness claims, social and recommendation features;
- general import/export UI;
- institutional loan, reservation and fine systems;
- Atmosphere Packs until separately release-ready.

Approved design and existing implementation for a deferred capability remain
valid product/technical history. Deferral means “not required for V2.001”, not
“delete the design or remove safe implemented foundation”.

## 8. Stable cross-release boundaries

The scope reset does not weaken these rules:

- one WordPress site;
- only `Privébibliotheek` is selectable/fully supported in V2.001;
- physical books only;
- Work → Edition → Item identity;
- Core owns domain rules and authorization;
- every Library-scoped operation has explicit Library Context and server-side
  authorization;
- user-owned data requires authenticated ownership checks;
- a Library reference on user-owned data never transfers ownership;
- UI visibility is never authorization;
- historical truth and known date precision are preserved;
- provider failure never blocks manual Add Book;
- Elementor remains a thin page shell.

## 9. Direct next release-risk slice

After D-SCOPE-01, the next major release-risk-reducing task is:

> MIG-01 — V1 Mapping & Reconciliation Design

MIG-01 inventories V1 source categories and defines their mapping status,
reconciliation evidence, dry-run, idempotency/retry, quarantine and recovery
contracts. It also tests whether the reduced scope needs a minimal exception,
for example settlement of active V1 loans. Goal data may be preserve-only,
cover assets may be preserve-only, while Author/Series relations can require an
active mapping even though their dedicated module is deferred.

D-SCOPE-01 deliberately does not choose those detailed migration outcomes.

## 10. Other deferred scope

The following remains deferred independently of D-SCOPE-01:

- selectable/fully operational `Uitleenbibliotheek` and Library-type
  conversion;
- account invitations, activation, self-registration, self-join and ordinary
  hard-delete/erasure/anonymization workflows;
- e-books, audiobooks, digital files, licenses and other media;
- generic `Andere fysieke bron` outside approved source types;
- smart Hierna-lezen availability or automatic source preference;
- global external catalog search outside the bounded personal Wishlist
  consumer, popularity/collaborative filtering and black-box ranking;
  MH-DISC-01 plus WISH-DISC-01 keep provider order presentation-only;
- automatic central Work/Author/Series merge, broad bibliographic editing,
  record fusion, OCR/vision, community Metadata Graph and paid-feed expansion;
- generic Relationship management UI;
- public profiles and public/shared Hierna lezen;
- advanced-search implementation unless measured need proves it necessary;
  D-SEARCH-01 preserves the optional secondary product layer without making it
  a V2.001 release requirement;
- final hosting product selection until hosting context is known.
