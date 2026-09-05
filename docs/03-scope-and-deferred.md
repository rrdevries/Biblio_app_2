# 03 — Scope and deferred

## v2.001 primary scope

- One WordPress site.
- Mijn Biblio personal environment.
- `Privébibliotheek`.
- one designated personal Privébibliotheek per active reading/borrowing user, auto-created on first relevant action if absent.
- Physical books only.
- Work → Edition → Item.
- central Auteur and Serie.
- LibraryCatalogContext.
- multiple Library memberships.
- Beheerrol + Gebruikstoegang.
- basic Platform account administration.
- physical collection, Locations, Conditions and Acquisition.
- Collections.
- Archive.
- simple internal loans.
- external loans.
- ReadingRounds with concrete physical sources.
- completed historical ReadingRounds without a source when genuinely unknown.
- Verlanglijst.
- manual Hierna lezen.
- private platform-wide `Wat zal ik lezen?` choice aid with rule-based,
  explainable selection from currently readable concrete sources, presented as
  unique Works.
- Ratings/Reviews/Notes.
- Reading goals.
- personal Stats/Jaaroverzicht/Tijdlijn.
- Library operational statistics.
- platform-level Biblio Home plus modular Bibliotheek Home / Action Center,
  distinct from the `Mijn Bibliotheek` full-catalog destination.
- Library audit.
- Library settings and platform administration required for v2.001.
- lightweight central metadata correction proposal.
- provider-neutral Metadata Hub minimum: ISBN validation, local-first lookup,
  conditional Open Library adapter, temporary conditional Google fallback,
  whole-record review, minimum provenance, manual/no-ISBN entry and independent
  Biblio-owned covers; implementation requires a separately approved build task.

## Explicitly deferred

### Library types / circulation
- selectable/fully operational `Uitleenbibliotheek`;
- conversion Privébibliotheek ↔ Uitleenbibliotheek;
- loan requests;
- reservations;
- queues;
- renewals;
- fines;
- institutional loan policies/limits;
- desk/circulation workflows;
- institutional reporting;
- advanced member administration;
- expanded return-date visibility rules for institutional users;
- hierarchical manager layer such as `Hoofdbeheerder`.

### Accounts/onboarding
- account creation by Library Eigenaar/Beheerder;
- invitations;
- email activation;
- acceptance/rejection;
- self-registration;
- self-join / join requests;
- onboarding/credential-distribution workflow;
- normal user hard-delete/erasure/anonimization workflow.

### Media
- e-books;
- audiobooks;
- digital files;
- licenses;
- providers/access entitlements;
- other media types.

### Reading sources
- generic `Andere fysieke bron` outside Library Item/internal loan/external loan.

### Reading planning
- `Wil ik lezen` broad someday marker;
- smart Hierna-lezen availability rules, distinct from the v2.001 `Wat zal ik
  lezen?` feature and without changing the manual Hierna lezen contract;
- automatic source preference;

### Recommendations beyond the v2.001 choice aid
- external recommendations for Works the user cannot currently read;
- external catalog discovery;
- collaborative/social filtering and popularity based on other users;
- ML/AI as a requirement, match percentages and black-box ranking;
- mood taxonomy and complex long-term novelty/exposure modelling;
- automatic suggestion-driven mutation of source data.

### Metadata/catalog
- full institutional cataloguing workflow;
- broad central bibliographic editor for all Library managers;
- automatic structural Work/Author/Series merge;
- publisher entity hierarchy;
- field-level evidence/confidence and merge policy;
- multi-provider record fusion;
- OCR/vision and shelf/spine recognition;
- community metadata and Metadata Graph;
- paid metadata feeds and licensed-provider partnerships;
- extensive automatic Work resolution;
- automatic Series Intelligence and unverified external completeness claims;
- Edition/Publisher Series and richer Series membership/group/order/lifecycle
  persistence beyond the current minimal Work→Series foundation;
- multi-order Series support and user-defined `Mijn serie` scope;
- tags;
- smart Collections.

### Relationships
- generic Relationship management UI;
- manual relationship link/confirm/ignore workflow.

### Data interchange
- user data export;
- Library export;
- import;
- exchange formats;
- migration UI.

Migration from existing Biblio V1 data is a later controlled technical activity, not a user-facing v2.001 import feature.

### Integrations
- OBA/API live integration;
- release tracking;
- automated external source synchronization beyond controlled metadata assistance.

### Settings
- generic platform-default editor;
- future empty settings placeholders;
- notification/email preference framework without concrete functionality.

### UI/product
- public profiles;
- avatar/bio/social profile functions;
- public/shared Hierna lezen;
- full page-builder style Home customization;
- search history/recent searches.

### Technical
- final CPT/CCT/custom-table persistence mapping before spike;
- final hosting/backup product selection before hosting is known;
- advanced search infrastructure (Elastic/Algolia/SearchWP) unless proven necessary.
