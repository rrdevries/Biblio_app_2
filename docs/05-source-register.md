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

## Authority rule

Historical timestamps do not determine authority.

A newer file can contain copied older decisions.

Always interpret content using:
1. explicit latest approved decision;
2. canonical repository docs;
3. accepted ADRs;
4. still-valid handovers;
5. older historical material.
