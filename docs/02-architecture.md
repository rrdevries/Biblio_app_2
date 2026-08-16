# 02 — Architecture

Status: canonical architecture direction; persistence mapping remains open until spike.

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
- valid active membership where applicable;
- role/permission checks;
- tenant isolation.

### User-owned

Every user-owned query/mutation requires:
- authenticated user;
- ownership check.

Optional library_id/source context never replaces ownership.

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

Active ReadingRound requires a concrete source identity.

Do not model current active reading as Work-only.

A source adapter/reference must support:
- Library Item with direct access;
- internal loan of an Item;
- external loan.

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

Not yet fixed.

Candidates:
- WordPress CPT/post storage;
- JetEngine CCT;
- custom tables;
- combinations.

Decision criteria:
- query patterns;
- volume;
- integrity;
- transactionality;
- migration;
- plugin coupling;
- testability.

Transactional/history-heavy relations deserve serious consideration for Biblio-owned tables/application services even when presentation uses JetEngine.

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

Recommended local stack:
- DDEV;
- Git;
- private GitHub;
- Composer;
- WP-CLI;
- PHPUnit;
- Playwright;
- configuration exports.

Before final persistence mapping, run a vertical technical spike proving both Library-scoped and pure personal flows.

## 13. Spike acceptance

At minimum prove:

### Personal path
New user before first relevant reading/borrowing action:
- can have Mijn Biblio with no Library membership;
- cannot leak into arbitrary Library scope.

On first relevant reading/borrowing action:
- designated personal Privébibliotheek is created once if absent;
- user becomes Eigenaar with Directe toegang;
- private user-owned data remains user-owned rather than becoming Library-owned.

### Library path
User with membership:
- can execute authorized Item/Library flow;
- Library Context isolates data;
- role/use-access rules are enforceable server-side.

### Reading
- active ReadingRound uses a concrete source;
- multiple active rounds for same Work are possible on different sources;
- user-owned ReadingRounds remain private.

No-go if:
- personal data requires a Library without functional reason;
- Library roles expose another user's private reading data;
- cross-Library queries leak data;
- core domain logic requires Elementor;
- forbidden writes can be performed by hiding/bypassing UI.
