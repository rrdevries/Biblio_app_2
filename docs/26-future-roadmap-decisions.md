# 26 — Future roadmap decisions

Status: **canoniek register voor expliciete toekomstige Biblio V2-besluiten, richtingen en open ontwerpvragen**.

Laatst gereconcilieerd: 2026-09-27 tegen Git SHA
`772b9c44a5b4fcc0e798fcc0281800922297803f`.

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

De productiecutover is afgerond; de exacte afsluiting staat in
[`147-mig-v1-v2-final-exception-inventory.md`](147-mig-v1-v2-final-exception-inventory.md).
Afgesloten migratie- en cutoveritems staan onder §5A en tellen niet mee als
actuele open ontwerpvragen. Voor een route langs alle productgebieden geldt
[`00-product-decision-index.md`](00-product-decision-index.md).

## 2. A — Vastgelegd voor later

| ID | Domein | Vastgelegd besluit | Bron |
|---|---|---|---|
| A-01 | Bibliotheektypen | `Uitleenbibliotheek` blijft een erkend toekomstig Library-type. In `v2.001` is alleen `Privébibliotheek` selecteerbaar en staat `Uitleenbibliotheek` uitsluitend als uitgeschakelde toekomstige keuze in de UI. | [`01-functional-design.md` §2](01-functional-design.md#library-types); [`03-scope-and-deferred.md`](03-scope-and-deferred.md#library-types--circulation) |
| A-02 | Collecties | Een Collection blijft editie-/Bibliotheek-itemgericht: alleen daadwerkelijk aanwezige, actieve fysieke Items uit dezelfde Library kunnen Collection members zijn. Verlanglijstitems, Gewenste aanwinsten, externe leenbronnen, ReadingRounds, private data en gearchiveerde Items zijn geen Collection members. | [`01-functional-design.md` §10](01-functional-design.md#10-collections); [`06-testing-and-acceptance.md` §10](06-testing-and-acceptance.md#10-collections); goedgekeurde aanvulling 2026-09-01 |
| A-03 | Collecties | De toekomstige aparte laag **Gewenste toevoegingen** is geen Collection membership. Goedgekeurde grens van 2026-09-01; inhoudelijk eigenaar is nu het functioneel ontwerp. | [`01-functional-design.md` §10](01-functional-design.md#10-collections) |
| A-04 | Collecties / Verlanglijst | Expliciete koppeling en aanschafvoorstel zijn toegestaan; geen stille toevoeging of vervulling. Goedgekeurde grens van 2026-09-01; inhoudelijk eigenaar is nu het functioneel ontwerp. | [`01-functional-design.md` §10](01-functional-design.md#10-collections) |
| A-05 | Leesdoelen / Collecties | Leesdoelen blijven Work-gericht en staan los van Verlanglijst of Gewenste toevoegingen. Een Collection completion goal gebruikt een bevroren snapshot van unieke Works uit de actuele Collection members en verandert alleen na een expliciete update. | [`01-functional-design.md` §10 en §13](01-functional-design.md#collection-reading-goal); goedgekeurde aanvulling 2026-09-01 |
| A-07 | Nieuwe leesbronnen | Een later nieuw source-type vereist een expliciete contract- en schema-uitbreiding; bestaande source-types worden niet stilzwijgend op digitale, audio-, provider-, pseudo- of generieke bronnen toegepast. | [`14-f2-9a-next-reading-analysis.md` §8.3](14-f2-9a-next-reading-analysis.md#83-geen-afzonderlijke-internalloan-target-in-f29b); [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md#internalloan) |
| A-08 | Schema-evolutie | Toekomstige Core-schemawijzigingen zijn afzonderlijke, geordende forward migrations met expliciete preconditie, wijziging en postconditie; de targetversie wordt pas na een geslaagde postconditie vastgelegd. | [`02-architecture.md` §9](02-architecture.md#schema-management); [`ADR-005`](decisions/ADR-005-formal-core-schema-migration-baseline.md) |
| A-09 | Architectuur / persistence | Toekomstige CPT-, CCT-, JetEngine- of andere persistencekeuzes mogen alleen bij aantoonbare nettowinst worden ingezet en mogen Core-authorization, integriteitsregels en application services niet omzeilen. | [`02-architecture.md` §9](02-architecture.md#9-persistence); [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md) |
| A-10 | Series / persoonlijke doelen | Toekomstige Series-doelen blijven twee afzonderlijke concepten: een verzameldoel voor Work-level inhoudelijke dekking binnen één Library en een persoonlijk platformbreed leesdoel op afgeronde ReadingRounds. Beide kunnen core, core+supplemental of een persoonlijke selectie van bevestigde members bevriezen; die targetset wijzigt de canon niet en verandert alleen na expliciet bijwerken. | [`01-functional-design.md` §11 en §13](01-functional-design.md#11-authors-and-series) |
| A-11 | Series / multi-order | Toekomstige alternatieve order schemes gebruiken verschillende posities voor hetzelfde SeriesMembership en dupliceren dat membership niet. Een andere order verandert completeness niet zolang de memberscope gelijk blijft, en wordt nooit zonder voldoende evidence/bevestiging verzonnen. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| A-12 | Wat zal ik lezen? | D-WR-01 defert ranking-engine, recommendation API, preferenceopslag, engines en UI naar V2.002+. Het bewaarde functionele ontwerp blijft geldig; handmatig Hierna lezen blijft V2.001-MUST. | [`03-scope-and-deferred.md` §3 en §7](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix); [`40-what-shall-i-read-functional-design.md`](40-what-shall-i-read-functional-design.md) |
| A-13 | Goals / lending / beoordelingen | Actieve Leesdoelen, de volledige lending-module en nieuwe Rating/Review create/edit/publish/withdraw/moderationflows zijn V2.002+. De V1-data is bij cutover volgens de goedgekeurde grenzen gemigreerd, preserved/deferred of quarantined; bestaande beoordelingen zijn in V2.001 leesbaar. | [`03-scope-and-deferred.md` §3 en §7](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix); [`147`](147-mig-v1-v2-final-exception-inventory.md) |
| A-14 | Catalogusuitbreiding | Aparte Authors/Series-modules, rich Series Intelligence, onafhankelijk coverbeheer en volledige Librarian correction/merge tooling zijn V2.002+. Auteur-/serierelaties, provenance en andere benodigde dataintegriteit blijven behouden. | [`03-scope-and-deferred.md` §3 en §7](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix) |
| A-15 | Shell / inzichten / beheer | Rich Home/dashboard, Stats/Jaaroverzicht/Tijdlijn, Library statistics, Audit UI, uitgebreide administratie, Bookshelf, aanvullende filter-optionroutes en Atmosphere Packs zijn V2.002+. V2.001 vereist alleen de veilige bereikbare shell en minimale bediening voor zijn releaseflows. | [`03-scope-and-deferred.md` §3 en §7](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix) |

**Totaal actuele A: 14 items.** A-06 en A-16 staan met hun oorspronkelijke
cutoverbetekenis onder §5A.

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
| B-08 | Leesplanning | Een brede `Wil ik lezen`-markering en slimmer Hierna-lezen-gedrag, waaronder beschikbaarheidsregels, automatische bronvoorkeur en eventueel automatisch verwijderen. Dit blijft een afzonderlijke deferred richting naast het eveneens naar V2.002+ verplaatste `Wat zal ik lezen?`; alleen handmatig Hierna lezen blijft V2.001-MUST. | [`03-scope-and-deferred.md` §3 en §7](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix); huidige handmatige grens in [`01-functional-design.md` §7](01-functional-design.md#hierna-lezen); [`40-what-shall-i-read-functional-design.md`](40-what-shall-i-read-functional-design.md) |
| B-09 | Catalogusbeheer | Een volledig institutioneel catalogiseerproces en een bredere centrale bibliografische editor voor Library-beheerders. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog) |
| B-10 | Identiteit / taxonomie | Ondersteuning voor automatische structurele Work-/Auteur-/Serie-merge en uitgebreidere alias-, merge- en taxonomiehiërarchieworkflows. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog); [`08-f2-5-exit-evidence.md`](08-f2-5-exit-evidence.md#deferred-and-non-scope) |
| B-11 | Metadata | Na de minimale v2.001 Metadata Hub: field-level evidence, confidence-/mergebeleid, record fusion, paid feeds, uitgebreidere automatische Work-resolutie en een community Metadata Graph. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog); [`ADR-010`](decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md) |
| B-12 | Series | Runtime-uitwerking van multidimensionale Series Intelligence bovenop de minimale Work→Series-foundation: Edition/Publisher Series, twee bevestigingslagen, canonical verification management, confirmed gaps, lifecycle/release-state, Series→Series-relaties, afzonderlijke coverageviews, persoonlijke targetsets en multi-order. Externe evidence blijft voorstel en ondersteunt geen voortijdige compleetheidsclaim. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series); [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog) |
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
| C-01 | Series / governance | De exacte implementatie en UX van het vertrouwde Biblio-brede beheer-/verificatieproces voor `canonical-confirmed` zijn nog niet besloten. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| C-02 | Collecties | Voor de optionele laag Gewenste toevoegingen zijn onder meer lifecycle/status, ordening, autorisatie, cardinaliteit van Verlanglijst-koppelingen, exacte proposal-/fulfilmentflow en UI-presentatie nog niet besloten. Alleen de grenzen uit A-02 t/m A-05 staan vast. | Goedgekeurde aanvulling 2026-09-01; huidige eigenaar van de vaste grens: [`01-functional-design.md` §10](01-functional-design.md#10-collections) |
| C-03 | ReadingRound / InternalLoan | Bij toevoeging van `InternalLoan` als derde ReadingRound-bron moet opnieuw worden gekozen tussen een derde expliciete foreign key en migratie naar een gemeenschappelijke source-identiteit. | [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md#internalloan); [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical) |
| C-04 | Persistence | De definitieve CPT/CCT/custom-table-mapping blijft per domein open totdat een concrete spike en de domeinspecifieke query-, integriteits-, lifecycle- en beheerbehoeften voldoende bewijs leveren. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical); [`02-architecture.md` §9](02-architecture.md#9-persistence) |
| C-05 | Hosting / operations | De definitieve hosting- en backupproductkeuze wordt pas gemaakt wanneer de hostingcontext bekend is. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#technical) |
| C-06 | ReadingRound | Of een gestopte ReadingRound later een redenveld krijgt, is nog een productbeslissing. | [`22-elementor-vertical-slice-1b-exit-evidence.md` §11](22-elementor-vertical-slice-1b-exit-evidence.md#11-known-non-blocking-limitations) |
| C-07 | Series / governance | Of en hoe `user-confirmed` later automatisch naar `canonical-confirmed` kan promoveren is niet besloten; in v2.001 gebeurt dit nooit. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| C-08 | Series / community | De implementatie van community moderation, stemmen en reputatie voor Series-evidence is nog niet besloten. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| C-09 | Series / multi-order | De daadwerkelijke runtime-, persistence- en UI-uitwerking van meerdere order schemes blijft open; v2.001 gebruikt maximaal één primary confirmed order. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series) |
| C-10 | Series / doelen | De uiteindelijke Nederlandse product-/UI-namen van het Library-gebonden serie-verzameldoel en het platformbrede serie-leesdoel zijn nog niet besloten. De twee afzonderlijke domeinbetekenissen staan wel vast. | [`01-functional-design.md` §11 en §13](01-functional-design.md#11-authors-and-series) |
| C-11 | Metadata / commercieel | De commerciële metadata-provider die Google eventueel vervangt of opvolgt is nog niet gekozen. | [`ADR-010`](decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md) |
| C-12 | Metadata / partnerships | Eventuele samenwerking of licentiëring met CB/Bureau ISBN en NBD Biblion is nog niet besloten. | [`tools/metadata-benchmark/output/metadata-provider-comparison.md`](../tools/metadata-benchmark/output/metadata-provider-comparison.md#futurepartnership-candidates-not-benchmarked) |
| C-13 | Metadata / community | Governance, rechten en implementatie van een community Metadata Graph zijn nog niet besloten. | [`ADR-010`](decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md) |
| C-14 | Metadata / vision | De precieze OCR-/vision-, shelf- en spine-recognitionimplementatie is nog niet besloten. | [`03-scope-and-deferred.md`](03-scope-and-deferred.md#metadatacatalog) |

**Totaal actuele C: 14 items.** C-15 en C-16 zijn voor de uitgevoerde
productiecutover afgesloten en staan onder §5A. Een toekomstige nieuwe
productieoperatie ontleent hieraan geen autorisatie.

## 5. Deferred Feature Register

Dit register is alleen een traceability-index. `Deferred` betekent hier: niet
releasekritisch voor V2.001; het betekent niet geschrapt, ongeldig of opnieuw te
ontwerpen. De releaseklasse blijft bepaald door
[`03-scope-and-deferred.md`](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix).
De gelinkte ontwerp-, ADR- en evidencedocumenten blijven de inhoudelijke bron en
worden hier niet gedupliceerd. Waar geen actieve feature is gebouwd, is dat
expliciet vermeld zodat een V2.002+-slice ontwerpwaarheid niet verwart met
runtimewaarheid.

| Deferred domein | Release- en migratiegrens | Bestaande product-/designwaarheid | ADR, foundation en evidence |
|---|---|---|---|
| Wat zal ik lezen? | **V2.002+**; geen actief V2.001-migratiedoel. D-WR-01 behoudt handmatig Hierna lezen als afzonderlijke V2.001-MUST. | [`40-what-shall-i-read-functional-design.md`](40-what-shall-i-read-functional-design.md); [`01-functional-design.md` §7](01-functional-design.md#wat-zal-ik-lezen); [`06-testing-and-acceptance.md` §64](06-testing-and-acceptance.md#64-wat-zal-ik-lezen-functionele-acceptancebasis) | Kandidatenbron-/filterfoundation: [`39-existing-source-filter-read-foundation-exit-evidence.md`](39-existing-source-filter-read-foundation-exit-evidence.md). Ranking-engine, preferenceopslag, API en UI zijn niet geïmplementeerd. |
| Gewenste aanwinsten | **V2.002+**; de afgesloten migratie heeft geen actieve V2.001-feature ingevoerd. Niet samenvoegen met Verlanglijst of Hierna lezen. | [`01-functional-design.md` §7](01-functional-design.md#gewenste-aanwinsten); vastgelegde Collection-grenzen A-02 t/m A-05 en open uitwerking C-02 in dit register | Geen actieve V2-featurefoundation aangetoond; de handmatige Collection-foundation hieronder implementeert geen Gewenste aanwinsten. Productiecutover is afgesloten in [`docs/147`](147-mig-v1-v2-final-exception-inventory.md). |
| Leesdoelen | Actieve feature **V2.002+**; de twee aangewezen V1-goals zijn als `PRESERVED_DEFERRED` verantwoord, zonder Goals-engine of actief V2-doel. | [`01-functional-design.md` §13](01-functional-design.md#13-reading-goals); [`06-testing-and-acceptance.md` §15](06-testing-and-acceptance.md#15-reading-goalsstatistics); [`136`](136-mig-02-reading-goal-map-01-current-v1-preservation.md) | ReadingRound-bronwaarheid voor latere progressie: [`ADR-007`](decisions/ADR-007-f2-6-reading-round-lifecycle-and-historical-truth.md). Geen actieve Goals-feature aangetoond. |
| Lending | Volledige module **V2.002+**. De finale productiecutover behield acht open relaties als `PRESERVED_DEFERRED`, zette één ambigu conflict in `QUARANTINED` en maakte nul operationele V2-loans. Dit sluit de exacte cutovervraag C-15 af, niet het toekomstige leenproduct. | [`01-functional-design.md` §8](01-functional-design.md#8-borrowed-and-lent); [`147`](147-mig-v1-v2-final-exception-inventory.md); historische CURRENT-keuze [`113`](113-d-mig-loan-01-current-v1-circulation-cutover.md) | Bronmodelbesluit: [`ADR-004`](decisions/ADR-004-fase-0-persistence-and-reading-sources.md#internalloan). Een toekomstige derde ReadingRound-bron blijft C-03. |
| Ratings/Reviews write/publication | Nieuwe write-/publication-/moderation-UI **V2.002+**. De historische V1-beoordelingen zijn via het goedgekeurde private preservation-pad gemigreerd; eigenaarlezing en onbekende beoordelingstijd zijn ondersteund. | [`01-functional-design.md` §12](01-functional-design.md#12-ratings-reviews-and-notes); [`03-scope-and-deferred.md` §3](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix); [`65`](65-assess-mig-01-historical-assessments.md) | Core-lifecycle: [`13`](13-f2-8b-exit-evidence.md). [`65`](65-assess-mig-01-historical-assessments.md) bewijst owner-only read en nullable `assessed_at`; [`58`](58-book-api-03-book-detail-ratings-reviews-projection.md) blijft de afzonderlijke Library-public projectie. |
| Authors/Series UI en rich Series Intelligence | Aparte UI/modules en rich intelligence **V2.002+**; auteur-/serierelaties die catalogus en migratie nodig hebben zijn **MAPPED** in V2.001. Geen completeness afleiden. | [`01-functional-design.md` §11](01-functional-design.md#11-authors-and-series); latere invarianten A-10/A-11/B-12 en open punten C-01/C-07 t/m C-10 | Minimale centrale relaties zijn gebouwd: [`34-author-series-relationship-foundation-exit-evidence.md`](34-author-series-relationship-foundation-exit-evidence.md). Metadata-/governancegrenzen: [`43-metadata-hub-technical-readiness-and-implementation-design.md`](43-metadata-hub-technical-readiness-and-implementation-design.md) en [`ADR-010`](decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md). |
| Biblio-owned covers | Onafhankelijke acquisitie/management **V2.002+**; het V1-coverbewijs is als deferred Edition-evidence behouden. V2.001 toont truthful no-cover; een apart coverruntimecontract is nog niet vastgesteld. | [`01-functional-design.md` §4](01-functional-design.md#covers); [`147`](147-mig-v1-v2-final-exception-inventory.md); presentatiecontract [`31-biblio-design-system.md` §11.1](31-biblio-design-system.md#111-bookcoverpresentation) | De huidige Book Detail-evidence bewijst alleen truthful no-cover, geen owned-coverbeheer: [`55-ui-book-01-book-detail-visual-baseline.md`](55-ui-book-01-book-detail-visual-baseline.md). |
| Librarian/correction governance | Volledige queue, correction-UI en merge tooling **V2.002+**; bestaande provenance, voorstellen, provisional state en integriteitsbetekenis blijven actief/behouden. De productiecutover is afgesloten zonder een volledige governance-UI te introduceren. | [`01-functional-design.md` §4](01-functional-design.md#central-bibliographic-governance); [`ADR-012`](decisions/ADR-012-provisional-catalog-and-librarian-review-governance.md) | Provider-neutral evidence en provenance: [`ADR-010`](decisions/ADR-010-provider-neutral-metadata-hub-and-evidence-governance.md), [`ADR-011`](decisions/ADR-011-field-level-metadata-confirmation-and-provenance.md), [`45-metadata-hub-mh-b4-field-confirmation-exit-evidence.md`](45-metadata-hub-mh-b4-field-confirmation-exit-evidence.md) en [`47-metadata-hub-mh-b5b-add-book-commit-exit-evidence.md`](47-metadata-hub-mh-b5b-add-book-commit-exit-evidence.md). |
| Home / Stats / Jaaroverzicht / Tijdlijn / Audit | Rijke actieve features **V2.002+**; de V1→V2-overgang is afgerond en deze rijke productfuncties zijn daardoor niet automatisch gebouwd. Alleen veilige shell/navigatie was V2.001-MUST. | [`01-functional-design.md` §14–§16](01-functional-design.md#14-personal-insights); [`06-testing-and-acceptance.md` §13, §15 en §16](06-testing-and-acceptance.md#13-biblio-home-and-bibliotheek-home); visueel kader [`31-biblio-design-system.md`](31-biblio-design-system.md) | De gebouwde grens is shell/visuele baseline, niet het rijke dashboard: [`54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md`](54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md). ReadingRound-historie blijft basis voor latere afleidingen: [`ADR-007`](decisions/ADR-007-f2-6-reading-round-lifecycle-and-historical-truth.md). |
| Uitgebreide administratie | Platform-, membership-, delegated-permission- en Librarian-admin **V2.002+**; alleen veilig gebruik/provisioning/herstel is **V2.001 MINIMUM IF MIGRATION REQUIRES**. Rollen en permissionbetekenis worden waar nodig **MAPPED**. | [`01-functional-design.md` §17](01-functional-design.md#17-settings-and-administration); [`06-testing-and-acceptance.md` §17](06-testing-and-acceptance.md#17-settingsplatform-admin) | Bestaande Library Identity & Context-boundary: [`17-f2-10-exit-evidence.md`](17-f2-10-exit-evidence.md). Dit is geen bewijs voor een uitgebreide beheer-UI. |
| Bookshelf | **V2.002+**; geen afzonderlijke V2.001-datamigratie vereist. | View-/presentatierichting: [`31-biblio-design-system.md` §9](31-biblio-design-system.md#9-mijn-bibliotheek-en-views); implementatieslicegrens [`32-mijn-bibliotheek-design-system-slice.md`](32-mijn-bibliotheek-design-system-slice.md) | Huidige UI-foundation toont Bookshelf bewust disabled: [`54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md`](54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md). |
| Aanvullende filter-optionroutes | Author/Series/Location/Collection-optionroutes **V2.002+**; onderliggende relaties/metadata blijven **MAPPED** waar de V2.001-catalogus die nodig heeft. | Querycontract en expliciete implementatiegrens: [`33-mijn-bibliotheek-server-side-catalog-query-readiness.md`](33-mijn-bibliotheek-server-side-catalog-query-readiness.md) | Onderliggende foundations: [`34-author-series-relationship-foundation-exit-evidence.md`](34-author-series-relationship-foundation-exit-evidence.md), [`36-library-item-location-foundation-exit-evidence.md`](36-library-item-location-foundation-exit-evidence.md) en [`38-library-collection-membership-foundation-exit-evidence.md`](38-library-collection-membership-foundation-exit-evidence.md). Huidige query/UI-evidence: [`59-cat-ui-01-mijn-bibliotheek-search-filter-sort-integration.md`](59-cat-ui-01-mijn-bibliotheek-search-filter-sort-integration.md). |
| Wishlist-groepering | Groepering, smart groups en Series-completenesslogica **V2.002+**; de 41 `titleGroupKey`-hints zijn `PRESERVED_DEFERRED` zonder merge/completeness. De basis-Verlanglijst heeft inmiddels Core, owner-only REST, UI en een gemigreerd actief Work-only target. | Basis en afbakening: [`01-functional-design.md` §7](01-functional-design.md#verlanglijst); releasegrens: [`03-scope-and-deferred.md`](03-scope-and-deferred.md#3-v2001-release-reclassification-matrix); [`135`](135-mig-02-wishlist-map-01-current-v1-wishlist-mapper.md) | Geen afzonderlijke grouping-foundation of actieve grouping-feature; basis-UI en discovery in [`68`](68-wish-ui-01-personal-wishlist-ui.md) en [`70`](70-wish-disc-01-wishlist-discovery-integration.md). |
| Smart/rich Collections | Smart Collections en rijke uitbreidingen **V2.002+**; bestaande basis-Collectionidentiteit, membership, volgorde en lifecycle zijn **MAPPED** voor V2.001. Alleen aanvullende bronfeiten worden zo nodig preserved. | [`01-functional-design.md` §10](01-functional-design.md#10-collections); vastgelegde grenzen A-02 t/m A-05 en open uitwerking C-02 | Handmatige basisfoundation: [`38-library-collection-membership-foundation-exit-evidence.md`](38-library-collection-membership-foundation-exit-evidence.md). Book Detail readprojectie: [`57-book-api-02-book-detail-collection-membership-projection.md`](57-book-api-02-book-detail-collection-membership-projection.md). Geen smart-Collectionruntime aangetoond. |
| Atmosphere Packs | **V2.002+** zolang niet afzonderlijk releaseklaar; de goedgekeurde presentatiearchitectuur blijft geldig na de afgesloten migratie. | [`31-biblio-design-system.md` §15](31-biblio-design-system.md#15-book-atmosphere); [`ADR-009`](decisions/ADR-009-biblio-ui-theming-and-atmosphere-architecture.md) | Huidige implementatie bewijst semantische theming en neutrale fallback, niet Pack-assets/selectie/persistence: [`54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md`](54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md) en [`55-ui-book-01-book-detail-visual-baseline.md`](55-ui-book-01-book-detail-visual-baseline.md). |

Voor elk domein geldt bij herstart: lees eerst de actuele releaseklasse, daarna
de genoemde product-/designbron en vervolgens de foundation/evidence. Open
punten blijven open; een bestaande foundation maakt de deferred feature niet
automatisch V2.001-MUST en afwezige runtime maakt het bewaarde ontwerp niet
ongeldig.

## 5A. Verplaatste/afgesloten cutoveritems

De volgende IDs blijven traceerbaar als historische besluit- of
voorbereidingsevidence. Zij zijn voor de **exact uitgevoerde** productiecutover
op 2026-09-27 naar deze afsluitsectie verplaatst, tellen niet meer mee in
A/B/C en autoriseren geen nieuwe apply.

| Oorspronkelijk ID | Historische vraag/grens | Afsluiting en actuele eigenaar |
|---|---|---|
| A-06 | Gecontroleerde V1→V2-migratie was V2.001-MUST en stond los van een algemene gebruikersimport; MIG-01 was na D-SCOPE-01 de toenmalige volgende grote release-risicoslice. | Productieovergang voltooid op 2026-09-26: [`147`](147-mig-v1-v2-final-exception-inventory.md). Algemene import blijft deferred in [`03`](03-scope-and-deferred.md). |
| A-16 | D-MIG-LOAN-01 koos voor CURRENT rehearsal deferred circulation promotion: acht open relaties als `PRESERVED_DEFERRED`, één Book/Copy-conflict als `QUARANTINED`, nul operationele V2-loans en behoud van originele evidence. | Historische keuze in [`113`](113-d-mig-loan-01-current-v1-circulation-cutover.md); exacte finale uitkomst in [`147`](147-mig-v1-v2-final-exception-inventory.md). Het toekomstige leenproduct blijft deferred. |
| C-15 | Voor de finale export moest bij nog open circulatie en zonder bruikbaar V2-loanmodel een afzonderlijke overgangsmaatregel worden gekozen; kunstmatig sluiten was niet geautoriseerd. | De finale overgangsmaatregel is voor het exacte productierunbesluit uitgevoerd en verantwoord in [`147 §§3–4`](147-mig-v1-v2-final-exception-inventory.md). Geen actuele open cutovervraag. |
| C-16 | De single-user V1-bron leverde geen doelaccount of Library-ID; vóór een write-run was expliciete aanwijzing of gecontroleerde provisioning vereist. | De exact geautoriseerde targetcontext en productie-apply zijn afgesloten in [`145`](145-mig-cutover-production-tooling-01.md) en [`147`](147-mig-v1-v2-final-exception-inventory.md). Geen impliciete autorisatie voor andere runs. |

## 6. Onderhoudsregel

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

## 7. Huidige reconciliatie-uitkomst

- A: **14**
- B: **26**
- C: **14**
- Totaal actuele items: **54**

Er is geen bindend inhoudelijk productbronconflict gevonden na de
statusreconciliatie. De echte ambiguïteiten staan onder de actuele C-items.
De vier afgesloten cutoveritems hierboven zijn historische evidence en geen
actuele open beslissingen.

Oudere handoverbronnen die alleen in het source register worden genoemd maar
niet in deze checkout aanwezig zijn, zijn niet gebruikt om ontbrekende details
in te vullen.
