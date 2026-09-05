# 05 — Source register

Purpose: preserve provenance without allowing historical material to override current canonical product truth.

Statuses:
- `ACTUEEL`
- `ACTUEEL MET LATERE CORRECTIES`
- `HISTORISCH / SUPERSEDED`
- `REDUNDANTE POINTER / KOPIE`
- `DATA / MIGRATIEREFERENTIE`

## Masterprompt Biblio_2.pdf
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- project identity and original product direction;
- physical collection/reading separation principles;
- root/version lineage;
- conservative data-safety principles.

Superseded in particular:
- ZIP-only development approach;
- digital media in v2.001;
- old Wishlist/Releases/Profiel scope;
- old generic settings/import-export scope.

## Broad early Biblio V2 handovers (including Biblio_V2_overdracht_1-10.md / pasted markdown variants)
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- broad functional history;
- early module intentions;
- many still-valid detailed decisions not later reopened.

Superseded examples:
- WordPress Multisite;
- roles Owner/Manager/Lezer as one-dimensional access model;
- mandatory Library context for ReadingRounds;
- old Wishlist/Next to read terminology and behavior;
- old fixed Home;
- old Leesvoorraad = unread stock;
- old Profile model;
- digital media scope.

## Gebruikersdata, leesrondes & beoordelingen
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- user-owned vs Library-owned distinction;
- ReadingRound, rating/review/note foundations;
- privacy principles.

Superseded:
- Multisite assumptions;
- one active round per user+Work;
- pause model where present;
- mandatory Library source/context assumptions.

## Leesdoelen
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- count/completion goal structure;
- time/date semantics;
- Series/Collection/manual goal foundations.

Superseded:
- old `Alle bibliotheken` wording → `Alle gelezen boeken` / `Alles` as applicable;
- old reading/source assumptions corrected by concrete-source model;
- Home presentation replaced by chapter 15.

## Statistieken, Tijdlijn, Locaties, Onderwerpen
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- personal insights foundations;
- Location lifecycle;
- Topic/classification design.

Superseded:
- old mixing of Library operational metrics with private reading metrics;
- old Profiel container;
- Timeline categories now narrowed to personal events.

## Series en Collecties.txt
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- ActivityEvent original structured model;
- original Relationships design history;
- Authors/Series;
- early Collection decisions.

Superseded:
- old visible personal Activity Logs;
- old hidden actor assumption;
- generic Koppelingen tab/Relationship management for v2.001;
- Collection content where replaced by later dedicated Collections handover.

## Collecties.txt
Status: `ACTUEEL MET LATERE CORRECTIES`

Primary source for:
- final detailed Collection behavior;
- draft management mode;
- archive/restore;
- manual order;
- Collection reading-goal source logic.

Corrections:
- physical Items only;
- current source/access semantics;
- no automatic goal snapshot mutation.

## Biblio_V2_overdracht_metadata_sets_doelgroep_t_m_vraag_1071.md
Status: `ACTUEEL MET LATERE CORRECTIES`

Primary source for:
- language;
- publisher;
- publication date;
- ISBN;
- Edition types;
- physical characteristics;
- covers;
- external metadata IDs;
- inventory numbering;
- Sets/boxsets;
- audience;
- contributors;
- Condition and related metadata.

Later corrections:
- central Work/Edition/Auteur/Serie identity with LibraryCatalogContext;
- governance of shared central metadata;
- physical-book-only v2.001 scope.

Duplicate copies may exist; one should be treated as primary and other identical copies as redundant.

## Taal, Uitgave-kenmerken.txt
Status: `REDUNDANTE POINTER / KOPIE`

Use the consolidated metadata handover as primary when content is duplicated.

## Ontwerpoverdracht en Vragen.txt — Bibliotheekdefaults 1072–1094
Status: `ACTUEEL MET LATERE CORRECTIES`

Primary source for:
- fallback personal preference → Library default → Biblio fallback;
- Library default principles;
- only concrete v2.001 Library default `Bibliotheek → Standaardweergave`;
- `Archief tonen` personal per Library, default off.

Later correction:
- role terminology Eigenaar/Beheerder/Lid + Gebruikstoegang;
- broader chapter-17 administration structure.

## Biblio_V2_overdracht_mijn_voorkeuren_vragen_1095-1109.md / later Ontwerpoverdracht pointer
Status: `ACTUEEL MET LATERE CORRECTIES`

Useful for:
- My preferences foundations;
- Library-context clarity;
- inheritance/session principles.

Superseded:
- old fixed set where `Leenacties op Home` was configurable;
- Home personalization is now exclusively `Home aanpassen`;
- pause-related preferences removed.

## Branch · Gap-analyse Bibliotheekbeheer.txt
Status: `ACTUEEL MET LATERE CORRECTIES`

Primary technical architecture source for:
- one WordPress site;
- internal Library tenant;
- modular monolith;
- Biblio Core;
- Crocoblock/Elementor boundaries;
- authorization principles;
- testing and development discipline;
- configuration/version-control discipline.

Later functional corrections to incorporate:
- `Privébibliotheek` type and future Uitleenbibliotheek;
- Beheerrol + Gebruikstoegang;
- platform account/membership split;
- concrete-source ReadingRounds;
- central bibliographic identity + LibraryCatalogContext;
- central metadata governance.

## Biblio_v507 / v507d actuele data.zip
Status: `DATA / MIGRATIEREFERENTIE`

Use as:
- existing-data reference;
- later migration analysis/input.

Do not use as current functional design authority.

## Current approved ChatGPT Project decisions after question 1109
Status: `ACTUEEL`

Canonicalized in this repository.

Includes especially:
- information architecture and canonical-doc strategy;
- one designated personal Privébibliotheek per user in v2.001, auto-created on first relevant reading/borrowing action when absent;
- Mijn Biblio model;
- Privébibliotheek/Uitleenbibliotheek scope;
- membership two-axis model;
- platform account administration;
- concrete-source ReadingRounds;
- revised Leesvoorraad and Hierna lezen;
- Home/search redesign;
- personal vs Library statistics;
- Library audit redesign;
- settings/platform administration;
- central bibliographic identity and correction governance.

## Approved Metadata Hub and Series Intelligence decisions — 2026-09-05
Status: `ACTUEEL`

Canonicalized in:

- `docs/01-functional-design.md` §4 and §11;
- `docs/02-architecture.md` §5 and §11;
- `docs/decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md`;
- `docs/26-future-roadmap-decisions.md` for future directions and open points.

The benchmark artifacts from commit
`924e3ae657ff6139bc649563c8b4ca1dbbe14a50` are evidence for the provider
decision, not an independent source of product truth or a universal market
measurement. The accepted decisions preserve provider neutrality, local
canonical authority, Work/Edition/Item separation and a multidimensional Series
Intelligence direction without implementing runtime or schema behavior.

## Post-bdd5714 Series Intelligence and metadata-conflict decisions — 2026-09-05
Status: `ACTUEEL`

These approved follow-up decisions refine, rather than replace, the 2026-09-05
Metadata Hub and Series Intelligence basis. They are canonicalized in the same
functional, architecture, ADR-010, scope and roadmap sources listed above.

They define core/supplemental, personal versus canonical confirmation,
verification rights, completeness and separate coverage scopes, the one-order
v2.001 boundary, distinct future Series collection/reading goals and the
whole-record metadata-conflict UX. Runtime, schema and final open governance/UI
implementation choices remain outside this documentation source.

## Authority rule

Historical timestamps do not determine authority.

A newer file can contain copied older decisions.

Always interpret content using:
1. explicit latest approved decision;
2. canonical repository docs;
3. accepted ADRs;
4. still-valid handovers;
5. older historical material.
