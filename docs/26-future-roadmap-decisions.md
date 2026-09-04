# 26 — Future roadmap decisions

Status: **canoniek register voor expliciete toekomstige Biblio V2-besluiten, richtingen en open ontwerpvragen**.

Laatst gereconcilieerd: 2026-09-01.

## 1. Doel, autoriteit en afbakening

Dit document is vanaf nu de centrale, Git-versieerbare lijst voor onderwerpen
die expliciet buiten de actieve productscope van `v2.001` zijn geplaatst of als
latere product-/architectuurkeuze zijn benoemd. Het is geen releaseplanning,
backlogprioriteit of toezegging dat ieder genoemd idee wordt gebouwd.

De categorieën betekenen:

- **A — Vastgelegd voor later:** de inhoudelijke grens of invariant staat vast;
  alleen timing en eventueel uitvoeringsdetail ontbreken.
- **B — Richting/idee voor later:** de uitbreiding of ontwerpintentie is
  expliciet benoemd, maar het contract is nog niet definitief.
- **C — Open ontwerpvraag:** de repository of een later goedgekeurd besluit
  zegt expliciet dat een keuze nog niet is gemaakt.

Een onderwerp dat wel tot `v2.001` behoort maar alleen buiten één technische
slice viel, staat hier niet automatisch in. Historische handovers zijn alleen
gebruikt voor zover hun inhoud in de huidige repository is gecanonicaliseerd.
Bij conflict geldt de bronvolgorde uit
[`05-source-register.md`](05-source-register.md); dit register maakt geen nieuw
productgedrag.

## 2. A — Vastgelegd voor later

| ID | Domein | Vastgelegd besluit | Bron |
|---|---|---|---|
| A-01 | Bibliotheektypen | `Uitleenbibliotheek` blijft een erkend toekomstig Library-type. In `v2.001` is alleen `Privébibliotheek` selecteerbaar en staat `Uitleenbibliotheek` uitsluitend als uitgeschakelde toekomstige keuze in de UI. | [`01-functional-design.md` §2](01-functional-design.md#library-types); [`03-scope-and-deferred.md`](03-scope-and-deferred.md#library-types--circulation) |
| A-02 | Collecties | Een Collection blijft editie-/Bibliotheek-itemgericht: alleen daadwerkelijk aanwezige, actieve fysieke Items uit dezelfde Library kunnen Collection members zijn. Verlanglijstitems, Gewenste aanwinsten, externe leenbronnen, ReadingRounds, private data en gearchiveerde Items zijn geen Collection members. | [`01-functional-design.md` §10](01-functional-design.md#10-collections); [`06-testing-and-acceptance.md` §10](06-testing-and-acceptance.md#10-collections); goedgekeurde aanvulling 2026-09-01 |
| A-03 | Collecties | Een toekomstige Collection-detailpagina mag een afzonderlijke laag **Gewenste toevoegingen** tonen voor een Work, voorkeurseditie of exacte Edition die later aan de Collection moet worden toegevoegd. Deze records zijn nooit Collection members en tellen niet als aanwezige Items. | Goedgekeurde aanvulling 2026-09-01, aansluitend op [`01-functional-design.md` §7 en §10](01-functional-design.md#7-verlanglijst-gewenste-aanwinsten-and-hierna-lezen) |
| A-04 | Collecties / Verlanglijst | Verlanglijst en Collection-Gewenste-toevoegingen blijven verschillende concepten, maar mogen expliciet gekoppeld worden. Bij aanschaf mag Biblio voorstellen het nieuwe Item aan de betreffende Collection toe te voegen; er vindt geen stille toevoeging of vervulling plaats. | Goedgekeurde aanvulling 2026-09-01; [`01-functional-design.md` §7 en §18](01-functional-design.md#7-verlanglijst-gewenste-aanwinsten-and-hierna-lezen) |
| A-05 | Leesdoelen / Collecties | Leesdoelen blijven Work-gericht en staan los van Verlanglijst of Gewenste toevoegingen. Een Collection completion goal gebruikt een bevroren snapshot van unieke Works uit de actuele Collection members en verandert alleen na een expliciete update. | [`01-functional-design.md` §10 en §13](01-functional-design.md#collection-reading-goal); goedgekeurde aanvulling 2026-09-01 |
| A-06 | Migratie / import | Migratie van bestaande Biblio V1-data is een latere gecontroleerde technische activiteit en niet hetzelfde als een algemene, gebruikersbediende importfunctie. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#data-interchange); [`05-source-register.md`](05-source-register.md#biblio_v507--v507d-actuele-datazip) |
| A-07 | Nieuwe leesbronnen | Een later nieuw source-type vereist een expliciete contract- en schema-uitbreiding; bestaande source-types worden niet stilzwijgend op digitale, audio-, provider-, pseudo- of generieke bronnen toegepast. | [`14-f2-9a-next-reading-analysis.md` §8.3](14-f2-9a-next-reading-analysis.md#83-geen-afzonderlijke-internalloan-target-in-f29b); [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md#internalloan) |
| A-08 | Schema-evolutie | Toekomstige Core-schemawijzigingen zijn afzonderlijke, geordende forward migrations met expliciete preconditie, wijziging en postconditie; de targetversie wordt pas na een geslaagde postconditie vastgelegd. | [`02-architecture.md` §9](02-architecture.md#schema-management); [`ADR-005`](decisions/ADR-005-formal-core-schema-migration-baseline.md) |
| A-09 | Architectuur / persistence | Toekomstige CPT-, CCT-, JetEngine- of andere persistencekeuzes mogen alleen bij aantoonbare nettowinst worden ingezet en mogen Core-authorization, integriteitsregels en application services niet omzeilen. | [`02-architecture.md` §9](02-architecture.md#9-persistence); [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md) |

**Totaal A: 9 items.**

## 3. B — Richting/idee voor later

| ID | Domein | Richting of idee | Bron |
|---|---|---|---|
| B-01 | Uitleenbibliotheek / circulatie | Volledig operationele Uitleenbibliotheken en eventuele typeconversie, met institutionele leenprocessen zoals aanvragen, reserveringen, wachtrijen, verlengen, boetes, beleid/limieten, baliewerk, rapportage en uitgebreidere zichtbaarheid van retourdatums. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#library-types--circulation) |
| B-02 | Accounts / onboarding | Library-gestuurde accountcreatie, uitnodigingen, activatie, accepteren/weigeren, zelfregistratie, join requests en een uitgewerkt onboarding-/credentialproces. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#accountsonboarding) |
| B-03 | Membershipbeheer | Geavanceerder ledenbeheer en een eventuele hiërarchische beheerlaag, bijvoorbeeld `Hoofdbeheerder`. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#library-types--circulation) |
| B-04 | Accounts / privacy | Een normaal hard-delete-, erasure- of anonimiseringsproces voor gebruikersaccounts. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#accountsonboarding) |
| B-05 | Digitale media | E-books, luisterboeken, digitale bestanden, licenties en provider-/toegangsrechten. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#media); [`01-functional-design.md` §1](01-functional-design.md#1-purpose-status-and-authority) |
| B-06 | Andere media | Mediatypen buiten de huidige fysieke-boeken- en benoemde digitale-boekrichting. De concrete domeinen en modellen zijn nog niet gespecificeerd. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#media) |
| B-07 | Leesbronnen | Een generieke `Andere fysieke bron` buiten Library Item, internal loan en external loan. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#reading-sources) |
| B-08 | Leesplanning | Een brede `Wil ik lezen`-markering en slimmer Hierna-lezen-gedrag, waaronder beschikbaarheidsregels, automatische bronvoorkeur en eventueel automatisch verwijderen. Dit blijft een afzonderlijke deferred richting en is niet de vastgezette v2.001-keuzehulp `Wat zal ik lezen?`; Hierna lezen blijft handmatig. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#reading-planning); huidige handmatige grens in [`01-functional-design.md` §7](01-functional-design.md#hierna-lezen); [`40-what-shall-i-read-functional-design.md`](40-what-shall-i-read-functional-design.md) |
| B-09 | Catalogusbeheer | Een volledig institutioneel catalogiseerproces en een bredere centrale bibliografische editor voor Library-beheerders. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog) |
| B-10 | Identiteit / taxonomie | Ondersteuning voor automatische structurele Work-/Auteur-/Serie-merge en uitgebreidere alias-, merge- en taxonomiehiërarchieworkflows. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog); [`08-f2-5-exit-evidence.md`](08-f2-5-exit-evidence.md#deferred-and-non-scope) |
| B-11 | Metadata | Publisher-hiërarchie, bredere bibliografische metadata, lokale mappings, gecontroleerde metadata-assistentie en classificatievoorstellen. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog); [`08-f2-5-exit-evidence.md`](08-f2-5-exit-evidence.md#deferred-and-non-scope) |
| B-12 | Series | Externe serievolgorde, ontbrekende-delen-analyse en ondersteuning voor externe compleetheidsinformatie zonder voortijdig een compleetheidsclaim te doen. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog); huidige grens in [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series); goedgekeurde verduidelijking 2026-09-01 |
| B-13 | Collecties / classificatie | Tags en slimme Collections naast handmatig samengestelde Collections. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog) |
| B-14 | Relaties | Een expliciete generieke Relationship-beheerlaag voor handmatig koppelen, bevestigen en negeren, zonder bestaande domeinrelaties te dupliceren. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#relationships); [`01-functional-design.md` §16](01-functional-design.md#relationships) |
| B-15 | Import / export | Gebruikersdata-export, Library-export, import, uitwisselingsformaten en een migratie-UI. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#data-interchange) |
| B-16 | Externe bronnen | Live OBA/API-integratie en geautomatiseerde synchronisatie met externe bronnen, verder dan gecontroleerde metadata-assistentie. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#integrations) |
| B-17 | Releases | Release-tracking en het volgen van nieuwe releases. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#integrations) |
| B-18 | Instellingen / notificaties | Een generieke platformdefault-editor en een notificatie-/e-mailvoorkeurenframework zodra daar concrete functionaliteit voor bestaat; lege toekomstige instellingen blijven verborgen. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#settings); [`01-functional-design.md` §17](01-functional-design.md#17-settings-and-administration) |
| B-19 | Publiek / social | Publieke profielen, avatar/bio/sociale profielfuncties en publiek of gedeeld `Hierna lezen`. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#uiproduct) |
| B-20 | Home / zoeken | Volledige page-builderachtige Home-customization en zoekgeschiedenis/recente zoekopdrachten. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#uiproduct) |
| B-21 | Notities | Verdere Note-editorfuncties: autosave, server drafts, offline editing, revisies/diff merge, uitgebreidere sneltoetsen, fullscreen, attachments, Markdown, links en block editing. | [`25-elementor-vertical-slice-1d-private-notes-readiness.md` §16.2](25-elementor-vertical-slice-1d-private-notes-readiness.md#162-future-enhancements) |
| B-22 | Beoordelingen / social | Een eventuele globale Review-zoekfunctie of feed en sociale functies zoals comments, likes en recommendations, los van de huidige private/publication-boundary. | [`12-f2-8a-ratings-reviews-analysis.md` §3](12-f2-8a-ratings-reviews-analysis.md#3-functional-contract-matrix) |
| B-23 | Leesgeschiedenis | Een volledige Reading History-beheerpagina met filters en export. | [`24-elementor-vertical-slice-1c-exit-evidence.md` §12](24-elementor-vertical-slice-1c-exit-evidence.md#12-known-non-blocking-limitations) |
| B-24 | Kwaliteitsborging | Een volledige WCAG-audit buiten de reeds bewezen slice-specifieke accessibility acceptance. | [`24-elementor-vertical-slice-1c-exit-evidence.md` §12](24-elementor-vertical-slice-1c-exit-evidence.md#12-known-non-blocking-limitations) |
| B-25 | Zoekinfrastructuur | Geavanceerde zoekinfrastructuur kan later worden onderzocht wanneer profiling of productbehoefte aantoont dat de huidige aanpak tekortschiet. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical) |
| B-26 | Private Notes / detail-UI | Toekomstige niet-blokkerende polish mag meer verticale scheiding geven tussen Leesgeschiedenis, Privénotities, Uitgave en Exemplaar en de focusstijl van de H2 `Privénotities` minder invoerveldachtig maken, met behoud van duidelijke keyboardfocus en exact de volgorde Lezen → Leesgeschiedenis → Privénotities → Uitgave → Exemplaar. | Goedgekeurde 1D-exitpolish 2026-09-01; [`27-elementor-vertical-slice-1d-private-notes-exit-evidence.md` §11](27-elementor-vertical-slice-1d-private-notes-exit-evidence.md#11-known-non-blocking-future-polish) |

**Totaal B: 26 items.**

## 4. C — Open ontwerpvragen

| ID | Domein | Nog niet besloten | Bron |
|---|---|---|---|
| C-01 | Collecties / Series | Er is **geen** formele compleetheidsmeter zoals `23/60` besloten. Eerst moeten definitie, bronbetrouwbaarheid, serievolgorde, ontbrekende-delen-analyse en de betekenis van teller/noemer worden uitgewerkt. | Goedgekeurde verduidelijking 2026-09-01; terughoudende compleetheidsgrens in [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| C-02 | Collecties | Voor de optionele laag Gewenste toevoegingen zijn onder meer lifecycle/status, ordening, autorisatie, cardinaliteit van Wishlist-koppelingen, exacte proposal-/fulfilmentflow en UI-presentatie nog niet besloten. Alleen de grenzen uit A-02 t/m A-05 staan vast. | Goedgekeurde aanvulling 2026-09-01 |
| C-03 | ReadingRound / InternalLoan | Bij toevoeging van `InternalLoan` als derde ReadingRound-bron moet opnieuw worden gekozen tussen een derde expliciete foreign key en migratie naar een gemeenschappelijke source-identiteit. | [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md#internalloan); [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical) |
| C-04 | Persistence | De definitieve CPT/CCT/custom-table-mapping blijft per domein open totdat een concrete spike en de domeinspecifieke query-, integriteits-, lifecycle- en beheerbehoeften voldoende bewijs leveren. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical); [`02-architecture.md` §9](02-architecture.md#9-persistence) |
| C-05 | Hosting / operations | De definitieve hosting- en backupproductkeuze wordt pas gemaakt wanneer de hostingcontext bekend is. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical) |
| C-06 | ReadingRound | Of een gestopte ReadingRound later een redenveld krijgt, is nog een productbeslissing. | [`22-elementor-vertical-slice-1b-exit-evidence.md` §11](22-elementor-vertical-slice-1b-exit-evidence.md#11-known-non-blocking-limitations) |

**Totaal C: 6 items.**

## 5. Onderhoudsregel

1. Wanneer een besluit over een toekomstonderwerp wordt genomen of gewijzigd,
   wordt dit document in **dezelfde wijziging** bijgewerkt met status en bron.
2. Een B-item verhuist naar A zodra zijn inhoudelijke grens definitief is. Een
   C-item verhuist naar A of B zodra de expliciete open vraag is beantwoord of
   tot richting is teruggebracht.
3. Wanneer een item actieve release-/versiescope wordt, blijft de historie
   zichtbaar: markeer het item als `VERPLAATST op YYYY-MM-DD naar <bron/scope>`
   en verplaats het uit de actuele A/B/C-telling naar een sectie
   `Verplaatste/afgesloten items` die bij de eerste verplaatsing wordt gemaakt.
4. Voeg geen impliciete feature toe omdat zij technisch mogelijk is of ooit in
   een historische handover stond. Zonder actuele expliciete bron hoort zij
   niet in A of B; een werkelijk expliciet onbesliste kwestie hoort in C.
5. Timing, release, eigenaar en prioriteit worden elders gepland. Dit document
   registreert product-/architectuurbesluiten en open vragen, niet uitvoering.

## 6. Huidige reconciliatie-uitkomst

- A: **9**
- B: **26**
- C: **6**
- Totaal actuele items: **41**

Er is geen bindend bronconflict gevonden. De echte ambiguïteiten staan onder C.
Oudere handoverbronnen die alleen in het source register worden genoemd maar
niet in deze checkout aanwezig zijn, zijn niet gebruikt om ontbrekende details
in te vullen.
