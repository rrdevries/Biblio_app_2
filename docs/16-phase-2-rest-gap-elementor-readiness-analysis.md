# 16 — Fase 2 Rest-Gap & Elementor Readiness Analysis

Status: **READY AFTER 3 STEPS**

Scope: Biblio V2 v2.001, bron- en repositorybrede analyse. Dit document
wijzigt geen productiecode, tests, migrations, schema, data of UI.

## 1. Baseline

### Repository

- branch: `main`;
- begin-HEAD: `272cc588ca74424a8e6f03fa318d268f96255943`;
- beginstatus: schoon;
- tracking bij aanvang: gelijk aan `origin/main` (`0` ahead, `0` behind);
- productversie: `v2.001`;
- Core packageversie: `2.1.0`;
- formele schemabaseline: `1000`;
- actuele schema- en lokale databaseversie: `1006`;
- actuele migratieketen: `1000→1001→1002→1003→1004→1005→1006`;
- actuele lokale Core-persistence: 21 `wp_biblio_*`-tabellen;
- F2.6 ReadingRounds: **GO**;
- F2.7 Private Notes: **GO**;
- F2.8 Ratings & Reviews: **GO**;
- F2.9 Hierna lezen: **GO**.

De lokale runtime gebruikt WordPress 7.0.2. Alleen `biblio-core` 2.1.0 is als
projectplugin actief. Elementor en Elementor Pro zijn lokaal niet geïnstalleerd.
Dat laatste is een operationele voorwaarde voor F3.0, geen reden om meer Core-
domeinen vooraf te implementeren.

### Analysegrens

De vraag is niet of alle v2.001-functies af zijn. De vraag is of één gekozen
verticale UI-slice kan worden gebouwd zonder dat Elementor:

- business rules of lifecycle nabouwt;
- authorization uit zichtbaarheid afleidt;
- Library Context uit pagina-, cookie- of formulierstate vertrouwt;
- repositories of Biblio-tabellen rechtstreeks benadert;
- meerdere Core-records zelf transactioneel probeert te muteren.

Een bestaand domeincontract telt pas als UI-ready wanneer ook een veilig,
stabiel en getest application-/adaptercontract bestaat.

## 2. Bronnen en conflicten

### Gebruikte bronvolgorde

| Bron | Gebruik in deze analyse |
| --- | --- |
| `AGENTS.md`, `docs/00-current-state.md`, `docs/01-functional-design.md` | Canonieke product- en scopewaarheid. |
| `docs/02-architecture.md`, ADR-001 t/m ADR-007 | Bindende scope-, ownership-, persistence- en adaptergrenzen. |
| `docs/03-scope-and-deferred.md`, `docs/04-terminology.md`, `docs/05-source-register.md` | v2.001-grenzen, terminologie en bronprioriteit. |
| `docs/06-testing-and-acceptance.md` | Vereiste security-, integriteits- en testacceptatie. |
| `docs/07` t/m `docs/15` | Bewezen implementatie en expliciete non-scope van Fase 1 en F2.5–F2.9. |
| Actuele Domain/Application/Persistence-code, `CoreApplication`, `ProductionComposition`, schema/migrations en tests | Technische waarheid op de baseline-HEAD. |
| `manifest.json`, README, lokale WordPress-/pluginstatus | Repository- en runtimeconsistentie. |

### Gevonden conflicten en interpretatie

1. `docs/02-architecture.md` eindigt inhoudelijk na F2.7. Dat maakt F2.8 en
   F2.9 niet ongedaan: current state, acceptance, hun analyse-/exitdocumenten,
   production composition, schema 1005/1006 en tests zijn later en bewijzen de
   actuele toestand.
2. Oudere exit- en ADR-secties noemen terecht de schemaversie van hun eigen
   moment. Alleen code, schema-1006-registry, actuele current state en het
   laatste exitbewijs bepalen de huidige versie.
3. Vroegere roadmap-/faseverwijzingen noemen REST, Abilities en UI als later
   werk. Repository-inspectie bevestigt dat dit nog steeds waar is: er is geen
   Biblio REST-route, Ability, controller, noncegrens, serializer of transport-
   error mapper.
4. `AssessmentQueryService` noemt Library-publicaties “public”, maar de
   Library-collection zelf is canoniek membership-scoped. De public Rating/
   Review-querymethods verrichten geen `canViewCollection`-check. Zij mogen
   daarom niet rechtstreeks als ongeautoriseerde HTTP-route worden gepubliceerd
   totdat de bedoelde publieksgrens expliciet is bevestigd en de adapter/
   applicationlaag die grens afdwingt.
5. De functionele documentatie vereist Library-namen en Library-switching,
   terwijl het actuele `Library`-aggregate en `wp_biblio_libraries` alleen ID,
   type en active status bevatten. Dit is een echt model-/schemagat, niet alleen
   ontbrekende presentatie.
6. De lokale runtime is WordPress 7.0.2 en bevat de Abilities API. De gelockte
   static-analysisstubs zijn 6.9.4. Nieuwe adaptercode moet daarom zowel tegen
   de echte runtime als tegen passende stubs worden gevalideerd; aanwezigheid
   van de runtime-API alleen is geen Biblio-contract.

Historische fasecodes worden niet gebruikt als bewijs dat een niet-aangetroffen
capability bestaat. De resterende nummering hieronder volgt de actuele HEAD.

## 3. Huidige bewezen Core-capabilities

### Horizontale Core-foundation

- één production composition root en één getypeerde `CoreApplication`;
- server-side WordPress-current-user-resolutie via `AuthenticatedUser`;
- expliciete `LibraryContext` binnen Library-scoped applicationservices;
- active-membership-, management-role-, use-access- en aanvullende-permission-
  policies voor de reeds geïmplementeerde handelingen;
- owner-scoped reads en writes voor private data;
- stabiele Core failure reasons voor validation, auth/access, conflict,
  persistence en transaction failure;
- transaction manager, rollbacksemantiek, CAS, locking en onafhankelijke-
  process concurrencytests;
- formele forward-only migrations, schema-health, fail-closed pluginboot en
  activationchecks;
- opaque server-issued IDs voor ReadingRound, Note, Rating, Review,
  Publication en Hierna-lezen-entry;
- één canonieke gate met PHPStan, unit, echte MariaDB-integratie, runtime smoke,
  manifest- en whitespacecontrole.

### Library en catalog

Bewezen zijn:

- personal Privébibliotheek-provisioning met Owner · Directe toegang;
- Library membership lookup voor één expliciete Library + User;
- authorizationpredicates voor collectie bekijken, Item toevoegen, directe
  fysieke bron, contributionpublicatie/moderatie en classificatiebeheer;
- Work → Edition → active Item persistence;
- drie transactionele Item-add-paden;
- LibraryCatalogContext per Library + Work;
- Boeksoort, Genre en Onderwerp met lifecycle, CAS, tenantintegriteit en audit;
- één toegankelijk Item op ID + expliciete Library Context;
- actieve Work-representatie in een Library.

Niet bewezen zijn een Librarylijst voor de actor, Librarynaam, Library-
overzicht, catalogussearch, Item-/Work-detailprojectie of algemene catalog-
edit/lifecycle.

### ReadingRounds

Bewezen en production-composed zijn:

- starten vanaf een direct bruikbaar Library Item of owned active ExternalLoan;
- finish, stop, historische registratie, ended-contentcorrectie,
  broncorrectie en conditionele historische delete;
- owner-scoped single read;
- persoonlijke Work-leesstatus en first-read/rereadprojectie;
- source/Work-integriteit, datumprecisie, CAS en concurrency.

Er bestaat geen gepagineerde actieve/historische leeslijst, `Nu aan het lezen`-
projectie, Leesvoorraadprojectie of samengestelde UI-detail-DTO.

### Private Notes

Bewezen zijn complete owner-scoped create/update/context/delete-contracten,
single/Work/Round/Mijn-notities-reads, cursorpagination en veilige HTML-
rendering. Voor een UI ontbreekt alleen transport/serialization en een
samengestelde Work-detailcontext.

### Ratings & Reviews

Bewezen zijn private Rating/Review CRUD, Round-contextcorrectie, publication-
lifecycle, moderation, private/public queries en exacte averages. Publieke DTOs
zijn geminimaliseerd en Review-output is escaped. De transportgrens ontbreekt;
de doelgroep/autorisatie van de Library-public read moet vóór publicatie van
zo'n route worden vastgelegd.

### Hierna lezen

Bewezen zijn Work-/Item-/ExternalLoan-add, remove, complete reorder, full-list-
readmodel, first-three Home-projectie, snapshots, ownerprivacy, listversion en
concurrency. Dit is het meest UI-vormige bestaande readmodel, maar Work/source-
discovery en transport ontbreken.

### ActivityEvent

Bewezen zijn het immutable eventmodel en transactionele append-only persistence
voor catalogusclassificatie. Er is geen reader, gepagineerde auditprojectie,
event-level read authorization of `CoreApplication`-query voor een Library-
activiteitenlog. ActivityEvent is dus een schrijf-fundament, geen UI-ready
auditfunctie.

## 4. Resterende domeingaten

| Domein/slice | Huidige repositorywaarheid | Werkelijk restant |
| --- | --- | --- |
| Verlanglijst | Geen domain/application/persistence. | Volledig user-owned lijstmodel, lifecycle, queries en schema. |
| Gewenste aanwinsten | Geen implementatie. | Library-owned lijst, rechten, fulfilment en audit. |
| Collecties | Geen implementatie. | Aggregate, draft-save, ordering, archive, Itemrelaties, goal-snapshotbron en schema. |
| Archief | Item is technisch active-only. | Item lifecycle, archiveperioden/redenen, restore, loaninteractie, audit en queries. |
| InternalLoan | Alleen een authorizationpredicate voor ontvangbaarheid. | Aggregate, lifecycle, privacyprojecties, transacties, audit, schema en ReadingRound-sourcekeuze. |
| ExternalLoan | Active-only aggregate, owned single read en privileged testwriter. | Veilige create/edit/return/delete- of historielifecycle, lijsten en UI-contracten. |
| Library Item lifecycle | Alleen create en active single lookup. | Edit, archive/restore, Location/Condition/Acquisition, server-ID en concurrencycontract. |
| Library catalog | Work heeft alleen title; Edition alleen Work; Item alleen IDs/status. | Bounded overzicht/detail/search, edit/governance, entity resolution en rijkere metadata. |
| Authors/Series | Geen model of schema. | Centrale identities, relaties, Library-index en personal history/goalprojecties. |
| Metadata-taxonomieën | Lokale Boeksoort/Genre/Onderwerp-writes bestaan. | UI-readlijsten en overige Edition/Item-metadata, mappings, Locations en Conditions. |
| Ratings/Reviews public read | Query bestaat. | Bevestigde audiencegrens, collection-view authorization, HTTP DTO/cursors en contracttests. |
| Private Notes read | Complete Core-reads bestaan. | Adapter, serializer en Work-detailcompositie. |
| Hierna lezen | Complete Core-list/mutations bestaan. | Adapter en veilige Work/source-pickerquery; eventuele grote-lijstprofiling. |
| ReadingRounds | Lifecycle is compleet. | Current/history/Work-detail UI-projecties en bronkeuzedata. |
| Leesvoorraad | Geen projectie. | Afleiding over direct Items, InternalLoans, ExternalLoans en active rounds. |
| ActivityEvent | Alleen append. | Owner/Manager-scoped reader, paging en eventserialization. |
| Timeline | Geen implementatie. | Afgeleide persoonlijke eventprojectie over brondata. |
| Statistieken | Geen implementatie. | Afgeleide persoonlijke en afzonderlijke Library-operationele projecties. |
| Jaaroverzicht | Geen implementatie. | Afgeleide kalenderjaarprojectie. |
| Leesdoelen | Geen implementatie. | Goal aggregates, snapshots, lifecycle, progressqueries en schema. |
| Openstaande acties | Geen implementatie. | Afgeleide actionable-signalprojectie. |
| Home | Alleen Hierna-lezen first-three. | Current reading, goals, inventory, loans, actions, prefs en samengestelde Home-projectie. |
| Instellingen/defaults | Geen model/applicationcontract. | Persoonlijke per-Library voorkeuren, Library default en Home-configuratie. |
| Membership/rollen/rechten | Model, persistence en enkele predicates bestaan. | Actor-librarylijst, capability DTO, membershipmutations, transfer/self-escalation en audit. |
| Library switch/context | Geen query/switchservice; context wordt alleen per call opgebouwd. | Owner-scoped Librarylijst en één expliciete requestcontext, zonder trusted client-context. |
| Library identity/lifecycle | Geen Librarynaam; general create/rename is niet exposed. | Naamcontract, provisioningdefault, readmodel en create/rename-use-cases. |
| REST/Abilities/adapters | Geen Biblio-transportadapter. | Routes, auth/nonce, validation, serialization, error mapping en registration lifecycle. |
| UI-facing read models | Alleen enkele private/projectionservices. | Library/capability/catalog/current-reading/composite DTOs. |
| UI-facing mutation contracts | F2.6–F2.9 grotendeels benoemd; andere domeinen ontbreken. | Transportcommands; catalog/lending/item/membership lifecycle waar nog niet in Core aanwezig. |
| Migration/health/integrity | Sterk t/m 1006. | Alleen gerichte nieuwe migrations per nieuw domein en operationele healthpresentatie. |
| Security/privacy | Core-servicegrenzen sterk. | Transportauth, CSRF, routepermission, input/output allowlists en non-enumerating HTTP mapping. |
| Performancekritische queries | Note-paging en gerichte sourcequeries bestaan. | Bounded Library overview/search, join-/sortindexbewijs, audit/timeline/home-querybudget. |
| Acceptance coverage | Core en MariaDB sterk. | Adapter/API securitytests en eerste UI-E2E-pad; geen Playwrightsetup aanwezig. |

## 5. A/B/C-classificatie

Elk werkelijk restant hieronder staat in exact één categorie. “Beter vóór
Elementor” betekent: vóór de UI van dat domein of vóór brede UI-uitrol. Het is
geen verborgen extra gate vóór de gekozen smalle F3.0-slice; anders zou B in
de praktijk alsnog A betekenen.

### A. ELEMENTOR-BLOCKER

| ID | Slice | Waarom blocker |
| --- | --- | --- |
| A1 | Library identity, actor-librarylijst, Library switch/context en capabilityprojectie | Zonder Librarynaam en owner-scoped memberships/readmodel kan de UI geen betrouwbare Library tonen of selecteren. Rechten uit Elementor afleiden zou authorization dupliceren. Dit vereist waarschijnlijk een gerichte schemawijziging. |
| A2 | Bounded Library overview + Item/Work detail + start-reading read contract | De mutation bestaat, maar list/detail/capability DTOs ontbreken. Rechtstreeks joins/repositories gebruiken zou Core omzeilen; onbegrensde of instabiele queries maken het UI-contract kwetsbaar. |
| A3 | Versioned WordPress REST-adapter, security/error contract en adaptertests | Zonder servercontroller, requestvalidation, nonce/auth, serializers en foutmapping kan geen Elementor-call veilig of stabiel aan `CoreApplication` worden gekoppeld. |

### B. BETER VÓÓR ELEMENTOR

| ID | Slice | Waarom eerder dan de betreffende UI |
| --- | --- | --- |
| B1 | Volledige catalogusmutaties, entity resolution, search en server-side Item/Work/Edition-ID-strategie | `AddLibraryItemService` is veilig, maar verwacht gekozen IDs en biedt geen search/edit. Eerst UI-formulieren bouwen zou discovery- en governance-logica in de page builder trekken. |
| B2 | Item lifecycle + Archief | Active-only Item/schema verandert wezenlijk bij archive/restore en internal-loaninteractie. Bouw dit vóór catalogusbeheer-/archiefschermen, niet vóór de eerste active-only vertical slice. |
| B3 | Authors, Series en kernbibliografische metadata | Work-detail en catalogusformulieren veranderen zichtbaar wanneer relaties en Editionvelden worden toegevoegd. Stabiliseer de DTO vóór rijke boekdetail-/edit-UI. |
| B4 | Classificatie-, Location-, Condition- en Acquisition-read/write contracten | Classificatiewrites bestaan, maar keuze-/lijstprojecties en de overige Itemmetadata niet. Formulieren hebben betrouwbare options en capabilities nodig. |
| B5 | Membership-, role-, permission-, Library create/rename- en transfercontracten | Het huidige model kan authorization beslissen maar beheert de lifecycle niet. Bouw dit vóór Bibliotheekbeheer-UI om self-escalation en multi-recordregels uit Elementor te houden. |
| B6 | Current reading, ReadingRound history en Leesvoorraadprojecties | Lifecyclemutaties bestaan; gedeelde lijst-/sourceprojecties ontbreken. Bouw deze vóór Home/Leeslog/finish-stop-UI om schermspecifieke joins te voorkomen. |
| B7 | Ratings/Reviews Library-public read boundary | Bevestig audience en voeg collection-view authorization, cursor/paging en DTO-adaptertests toe vóór een Library-beoordelingenscherm. |

### C. KAN NA START ELEMENTOR

| ID | Slice | Waarom veilig later |
| --- | --- | --- |
| C1 | Verlanglijst | Afzonderlijk user-owned domein; geen afhankelijkheid van de eerste Library Item/ReadingRound-flow. |
| C2 | Collecties | Nieuw Library-owned domein achter eigen services; eerste slice gebruikt geen Collection. |
| C3 | Gewenste aanwinsten | Los Library-managementdomein en niet nodig voor bekijken/lezen starten. |
| C4 | InternalLoan | Groot en risicovol, maar de eerste slice beperkt zich tot Owner/Directe toegang. Een typed source-DTO houdt latere source-uitbreiding achter Core. |
| C5 | ExternalLoan lifecycle | Bestaande read/startfundering blijft bruikbaar; create/return UI is geen voorwaarde voor Item-start. |
| C6 | Private Notes adapter/scherm | Core CRUD/read/render is compleet; kan na de gedeelde adapterbasis als dunne uitbreiding volgen. |
| C7 | Hierna-lezen adapter/scherm | Core list/mutations/Home-readmodel is compleet; alleen discovery/transport/UI resteert. |
| C8 | ActivityEvent read / Library audit | Append-only waarheid bestaat; audit is niet nodig om de eerste private reading action uit te voeren. |
| C9 | Timeline | Afgeleid en persoonlijk; blokkeert geen bronmutatie of authorization. |
| C10 | Statistieken | Afgeleid van brondata; geen tweede schrijfwaarheid nodig. |
| C11 | Jaaroverzicht | Afgeleide jaarprojectie; kan onafhankelijk worden toegevoegd. |
| C12 | Leesdoelen | Eigen domein, maar niet nodig voor de eerste reading vertical slice. |
| C13 | Openstaande acties | Afgeleid signalenmodel; geen kernlifecycle-eigenaar. |
| C14 | Brede Home-projecties en Home-configuratie | Alleen afnemer van betrouwbare bronservices; F3.0 heeft geen volledig Home nodig. |
| C15 | Overige instellingen/defaults en operationele health-UI | Geen invloed op de gekozen startflow; alleen concrete instellingen worden later toegevoegd. |

Samenvatting: **3 A-slices, 7 B-slices en 15 C-slices**. Alleen A1–A3
vormen de Elementor-startgate.

## 6. UI-adapteranalyse

### Huidige toestand

`Plugin` publiceert na gezonde boot één `CoreApplication` via
`biblio_core_initialized`. Dat is een goede composition boundary voor een
adapter. Er is echter geen Biblio-code op `rest_api_init`,
`wp_abilities_api_init`, `wp_ajax_*` of een server-rendered controllerhook.

De Core heeft dus veel applicationservices, maar nog geen transportlaag.

### Kleinste robuuste strategie

Gebruik voor F3.0 één versioned custom WordPress REST-namespace, bijvoorbeeld
`biblio/v1`, met dunne routecontrollers die uitsluitend `CoreApplication`-
services en nieuwe UI-queryservices aanroepen.

Waarom REST eerst:

- Elementor/custom JS heeft een normale HTTP read/write-grens nodig;
- REST ondersteunt resource-URLs, bounded lijsten, cursors, HTTP-statussen,
  ETags/versionvelden en een eenduidig fout-envelope;
- dezelfde endpoints zijn testbaar zonder Elementor;
- één transport voorkomt dubbele REST- en Ability-mapping in de eerste slice.

WordPress 7.0.2 Abilities kan later nuttig zijn voor capability discovery of
automation. Registreer een Ability dan als tweede facade over hetzelfde
applicationcontract, niet als alternatieve domeinimplementatie. Abilities is
geen Core-dependency en vervangt de benodigde Library/read-resource-endpoints
niet.

### Verplichte adapterregels

- registreer de adapterhooks via de pluginlifecycle, maar voer een route alleen
  uit met een gezonde Core-composition; een bekende route geeft 503 zolang de
  application boundary niet beschikbaar is;
- gebruik cookie-auth plus een geldige WordPress REST nonce voor de ingelogde
  browserclient;
- gebruik `permission_callback` als grove transportgrens, maar laat de
  applicationservice de finale ownership-/Library-authorization uitvoeren;
- accepteer nooit `user_id`, vertrouwde `LibraryContext`, owner of server-ID
  uit self-service requestdata;
- valideer route-, query- en bodyvelden met expliciete schemas en bouw typed
  value objects vóór de servicecall;
- serialize uitsluitend expliciete DTOvelden; serialize geen repositorymodel,
  exception, stacktrace of MariaDB-tekst;
- behoud Core versions in mutatiecommands en responses;
- map auth naar 401, niet-beschikbare scoped resources non-enumerating naar
  404, validation naar 422, CAS/businessconflict naar 409 en onbeschikbare
  Core-runtime naar 503;
- gebruik één stabiel fout-envelope met machinecode, veilige boodschap en
  alleen waar toegestaan een veilige actuele state;
- laat Elementor alleen deze routes aanroepen. Geen direct wpdb, JetEngine-
  mutation, hidden actor field of client-side permissionwaarheid.

Server-rendered controllers zijn geen kleinere hoofdstrategie: zij zouden
dezelfde validation/error/securitymapping per formulier moeten herhalen.
Ze mogen later uitsluitend als presentatieconsumer van dezelfde adapter/
applicationlaag bestaan.

## 7. Read-model readiness

| UI-readmodel | Status | Readiness-oordeel |
| --- | --- | --- |
| Current actor | Actor wordt server-side resolved, maar niet als UI DTO. | Kleine A1-projectie nodig; exposeer geen private accountvelden. |
| Mijn bibliotheken / switch | Ontbreekt; membershiprepo leest alleen één Library+User en Library heeft geen naam. | Echte missing Core capability en schema-/querywerk in A1. |
| Capabilities current context | Predicates bestaan, gebundelde capability DTO ontbreekt. | Kleine nieuwe A1-projectie; bereken server-side per Library. |
| Library overview | Ontbreekt; Itemrepo heeft alleen single lookup. | Nieuwe bounded A2-projection met stabiele sortering/cursor en indexbewijs. |
| Item detail | `AccessibleLibraryItem` bevat Item + direct-use bool. | Nieuwe A2 DTO met Work title, Edition, personal status en acties. |
| Work detail | `Work` bevat ID/title; status/notes/assessments zijn losse services. | Later composeerbaar; minimale variant in A2, rijke variant na B3/B7. |
| Current reading | Alleen single owned Round en Workstatus. | Nieuwe B6-lijstprojectie; niet nodig om de startresponse te tonen. |
| Reading history | `readingSequence()->forWork()` bestaat. | Kleine adapter voor Work-context; bredere lijsten vallen onder B6. |
| Private Notes | Single en drie gepagineerde owner-lijsten plus safe renderer bestaan. | Kleine C6-adapter nodig, geen nieuw domeinmodel. |
| Ratings/Reviews private | Own detail/Work/Round/My/average bestaan. | Adapter en betere paging nodig bij het scherm. |
| Ratings/Reviews public | Minimal public DTO en aggregate bestaan. | B7 authorization-/audiencewrapper vóór exposen. |
| Hierna lezen | Full list en Home first-three DTO bestaan inclusief status. | Kleine C7-adapter; Work/source discovery is apart nodig. |
| Classificatie options | Repositories lezen losse IDs maar bieden geen UI-lijsten. | Nieuwe B4 optionsprojecties. |
| ActivityEvent | Alleen appender. | Nieuwe C8 reader/projectie/paging/authorization. |
| Timeline/stats/year/goals/actions | Ontbreken. | Nieuwe C-projecties of domaincapability zoals in §5. |
| Home | Alleen Hierna-lezen first-three. | Nieuwe C14 composite projection; geen startblocker. |

Readmodels moeten schermgericht en immutable zijn. Elementor krijgt geen
generieke “query table”-endpoint. Per route worden alleen de velden geleverd
die dat scherm nodig heeft, inclusief server-berekende capabilities.

## 8. Mutation-contract readiness

| Mutatie | Bestaat en bewezen | Veilig aan UI na A3 | Restant |
| --- | --- | --- | --- |
| Catalog Item add | Ja, drie atomic paths met auth. | Conditioneel. | Search/entity selection, server-ID, adapter en invoer-DTO ontbreken; daarom B1 vóór het add-formulier. |
| Work/Edition/Item edit | Nee. | Nee. | B1/B3/B4. |
| Item archive/restore | Nee; Item is active-only. | Nee. | B2. |
| ReadingRound start vanaf direct Item | Ja. | **Ja**, gekozen F3.0-write. | Alleen typed request/response/error mapping in A3. |
| ReadingRound start ExternalLoan | Ja. | Ja, zodra loan UI/selection bestaat. | C5 transport/discovery. |
| ReadingRound finish/stop/history/correct/delete | Ja. | Ja. | B6 read/composition en A3-adapterpatroon. |
| Note create/update/context/delete | Ja. | Ja. | C6 route/serializer/editorintegration. |
| Rating/Review create/update/context/delete | Ja. | Ja. | Routes/paging; geen direct repositorygebruik. |
| Publish/move/withdraw/moderate/restore | Ja. | Pas na B7. | Audience/read-auth en adaptercontract. |
| Hierna lezen add/remove/reorder | Ja. | Ja. | C7 routes en pickerquery. |
| Classificatieterm/context mutations | Ja. | Pas na B4. | Options/readmodel en formuliercontract. |
| ExternalLoan create/return | Nee. | Nee. | C5. |
| InternalLoan create/return/settle | Nee. | Nee. | C4. |
| Membership/role/use-access/transfer | Nee. | Nee. | B5. |
| Ordinary Library create/rename | Niet production-exposed. | Nee. | B5 en A1 Librarynaamcontract. |
| Verlanglijst/Collectie/Goal/settings | Nee. | Nee. | Eigen C-slices. |

Alleen “bestaat” plus een route is onvoldoende. Een mutation wordt pas UI-
ready verklaard wanneer server-actor, authorization, requestvalidatie,
transactionele semantiek, versioning en veilige foutmapping samen bewezen zijn.

## 9. Schema-stability

### Wat 1006 al stabiel afschermt

Schema 1006 is gezond en stabiel voor de geïmplementeerde Library/
membershipbasis, minimal catalog, classificatie, ActivityEvent-writes,
ReadingRounds, Private Notes, Ratings/Reviews/Publications en Hierna lezen.
De application boundary voorkomt dat UI aan tabelnamen of joins hoeft te
koppelen.

### Waarschijnlijke schema-impact van restwerk

| Restant | Waarschijnlijke impact |
| --- | --- |
| A1 Library identity | Bestaande Library-tabel krijgt waarschijnlijk een naamveld en mogelijk een readindex; exacte naam/default/normalisatie is nog te beslissen. |
| A2 overview/detail | Waarschijnlijk geen nieuw domeintabel; mogelijk gerichte index voor bounded Library + Work/Edition-sortering na queryplanmeting. |
| Archive/Item lifecycle | Bestaande Item-status verandert en archiveperioden/redenen vragen waarschijnlijk eigen historie. |
| InternalLoan | Nieuwe loan-tabellen en bewuste herbeoordeling van ReadingRound-sourcepersistence. |
| Wishlist/Collections/Goals/settings | Nieuwe tabellen per owner/lifecycle/querypatroon. |
| Authors/Series/rich metadata | Nieuwe centrale identity-/relatietabellen en uitbreiding van Work/Edition-projecties. |
| Timeline/stats/year/actions/Home | Bij voorkeur afgeleide queries/projections; alleen cachetabellen wanneer profiling dat later rechtvaardigt. |

### Oordeel

Schema-instabiliteit blokkeert Elementor niet globaal. Zij blokkeert alleen
wanneer een gekozen UI-contract nog rechtstreeks van de veranderende shape
afhangt. Na A1 en een schermgerichte A2 DTO kan later schema 1008+ achter
dezelfde application-/REST-contracten evolueren.

Elementor mag daarom nooit kolomnamen, table joins of JetEngine-relaties als
Core-contract gebruiken. De enige actuele schema-blocker voor F3.0 is het
ontbrekende Librarynaam-/switchmodel in A1.

## 10. Security-readiness

### Reeds sterk

- actor wordt op het moment van de servicecall uit WordPress afgeleid;
- Library Context wordt in Core opgebouwd uit target Library + actor;
- Item access controleert membership én recordscope;
- private reads/writes gebruiken ownerpredicates;
- F2.6–F2.9 hebben cross-user/cross-Library en concurrencybewijs;
- foutredenen bevatten geen publiek databasecontract;
- Core boot failt gesloten bij migration-/healthproblemen.

### Nog blocker

- er is geen route-authenticatie- of REST-noncebeleid;
- er zijn geen requestschemas of transport DTO allowlists;
- er is geen uniforme HTTP-mapping voor non-enumeration en conflicts;
- er is geen current-context capability response;
- er zijn geen API-tests die forged actor/library/body, ontbrekende nonce,
  cross-user IDs, inactive membership en error leakage afdekken;
- publieke assessmentqueries mogen nog niet ongefilterd als route worden
  gebruikt.

A3 moet aantonen dat een rechtstreeks HTTP-request exact dezelfde uitkomst
heeft als de zichtbare UI. Elementor mag knoppen verbergen voor UX, maar die
zichtbaarheid levert nooit permission.

## 11. Testing-readiness

### Bestaande basis

De laatste F2.9b-exitgate op deze HEAD was groen met:

- unit: 219 tests / 834 assertions;
- real-MariaDB integration: 165 tests / 1.240 assertions;
- PHPStan level 6: geen fouten;
- WordPress smoke: plugin actief, Core geladen, init-hook 1, HTTP 200;
- manifest en whitespace: groen.

Dit is sterk Core-bewijs, maar geen API- of UI-bewijs.

### Minimum vóór F3.0

1. application contract tests voor A1/A2 DTOvelden, paging, capabilities,
   active-only scope en non-enumeration;
2. echte WordPress REST integrationtests voor route registration, auth,
   nonce/CSRF, validation, Library isolation, owner isolation, 401/404/409/
   422/503 mapping en geen technische leakage;
3. één deterministische fixturebuilder met minimaal twee users, twee Libraries,
   active/inactive memberships, Direct/View access, same-/cross-Library Items
   en een Work/Edition;
4. query-count/-plan of gerichte indexcontrole voor de bounded overviewroute;
5. de bestaande volledige Core-gate groen en een adapter smoke op de echte
   WordPress-runtime.

### Met F3.0, niet ervoor

Introduceer Playwright of een gelijkwaardige browserlaag samen met de eerste
werkelijke Elementor-slice. Het eerste pad test:

`login → Library kiezen → active Item openen → Lezen starten → nieuwe Round zien`.

Vereist zijn één happy path, één unauthorized/cross-Library direct-request en
één stale/duplicate-conflictpad. Een brede responsive E2E-suite voor alle
v2.001-modules is geen precondition voor de eerste UI.

## 12. Aanbevolen eerste verticale UI-slice

### Keuze

**Mijn Privébibliotheek: active boekoverzicht → Itemdetail → Lezen starten.**

De eerste uitvoering richt zich op de designated personal Privébibliotheek van
een Owner met Directe toegang. Daardoor wordt geen toekomstig InternalLoan-
contract nagebootst en blijft de slice toch een echte read/write-flow.

### Waarom deze slice

- zij valideert de belangrijkste architectuurgrens: authenticated actor,
  expliciete Library Context en server-side capability;
- zij gebruikt een bewezen transactionele mutation met concurrencybescherming;
- zij creëert de navigatie-/detailbasis waar Notes, Ratings, Hierna lezen en
  latere catalogusacties op kunnen aansluiten;
- zij wacht niet op Wishlist, Goals, Timeline, Stats, Home of lending;
- de ontbrekende Core-delen zijn gericht en grotendeels query/adapterwerk.

### Reeds beschikbare capabilities

- personal-Library designation en Owner · Directe toegang;
- active membership en collection-view authorization;
- scoped single Item access;
- Item → Edition → Work-derivatie;
- Work title en persoonlijke Work-leesstatus;
- source-validatie en `StartReadingFromLibraryItemService`;
- server-issued Round-ID, exact-date/precisionmodel, active-source uniqueness;
- owner-scoped readback van de aangemaakte Round;
- schema/migration/health/concurrency-testfundering.

### Nog nodig

- Librarynaamcontract plus owner-scoped Library/switch DTO (A1);
- bounded active Item overview en Item/Work detail/capability DTO (A2);
- REST-routes, nonce/auth, validation, serialization en error envelope (A3);
- adapter-/permissiontests en F3.0-fixture;
- installatie/beschikbaarheid van Elementor en Elementor Pro bij F3.0.

Minimale routevorm:

- `GET /biblio/v1/me/libraries`;
- `GET /biblio/v1/libraries/{library_id}/items`;
- `GET /biblio/v1/libraries/{library_id}/items/{item_id}`;
- `POST /biblio/v1/libraries/{library_id}/items/{item_id}/reading-rounds`.

De POST accepteert alleen de inhoudelijke startdatum met expliciete precisie.
Actor, Work, source en Round-ID komen van de server. De response bevat een
expliciete ReadingRound DTO en version, geen gehydrateerd repositoryobject.

## 13. Concrete resterende roadmap

### Gate-kritieke route

| Fase | Doel | Cat. | Afhankelijkheden | Zwaarte | Risico | Waarom vóór/na Elementor |
| --- | --- | --- | --- | --- | --- | --- |
| F2.10 — Library Identity & Context Readiness | Librarynaamcontract, veilige 1006→volgende migration, actor-librarylijst, designated markering en capability DTO. | A | Schema 1006, membership/auth foundation. | Middel | Productkeuze voor default naam; data-evolutie en tenantleak. | Vóór: zonder herkenbare/scoped Library kan geen betrouwbare switch of route bestaan. |
| F2.11 — Catalog UI Read Models | Bounded active overview, Item/Work detail, personal status, start capability, cursor en query/indexbewijs. | A | F2.10, catalog/ReadingRound foundation. | Middel | N+1, instabiele ordering, scopelek via joins. | Vóór: Elementor mag deze joins/capabilities niet zelf definiëren. |
| F2.12 — WordPress UI Adapter Foundation | `biblio/v1`, routecontrollers, auth/nonce, request/response schemas, error envelope, contract/securitytests en fixture seam. | A | F2.10–F2.11, healthy composition. | Groot | CSRF, enumeration, dubbele authorization, transportcontract drift. | Vóór: dit is de enige veilige toegang van Elementor tot Core. |
| Core/UI Readiness Gate | Objectief bewijs uit §15 verzamelen. | Gate | F2.10–F2.12. | Klein | Een groen Core-testresultaat verwarren met een groen API-contract. | Moet GO zijn vóór de eerste echte UI-mutation. |
| F3.0 — Eerste Elementor vertical slice | Active personal-Library overview → detail → start reading, plus eerste Playwrightpad. | UI | Gate GO, Elementor/Pro beschikbaar. | Middel | UI-state wijkt van serverstate af; licensed config niet reproduceerbaar. | Eerste echte Elementorbouw. |

**Aantal resterende pre-Elementor gate-stappen: 3.**

### Daarna, op afhankelijkheid en niet op oude fasenummers

| Voorgestelde slice | Doel | Cat. | Afhankelijkheden | Zwaarte | Risico | Plaatsing |
| --- | --- | --- | --- | --- | --- | --- |
| Catalog & Bibliographic Completion | B1/B3/B4: search/entity resolution, edits, Authors/Series, metadata en formulieroptions. | B | F2.11/A3. | Groot | Centrale governance en datamodelgroei. | Vóór rijke catalogus-/edit-UI; mag parallel na F3.0-start. |
| Item Lifecycle & Archive | B2: archive/restore, historie, audit en loaninteractie. | B | Catalogcontract; InternalLoan-besluit voor speciale settlement. | Groot | Historische waarheid en cross-domain transacties. | Vóór archief-/Itembeheer-UI. |
| Library Administration | B5: create/rename, memberships, rights, transfer en self-escalation. | B | F2.10/A3. | Groot | Authorization en multi-record integrity. | Vóór Bibliotheekbeheer-UI. |
| Reading Projections | B6: current/history/Leesvoorraad en source selection. | B | A2/A3; C4/C5 voor volledige voorraad. | Middel | Duplicatie van sources en private contextlek. | Vóór Home/Leeslog-uitrol. |
| Assessments Public Boundary | B7: audience, scoped reads, paging en transporttests. | B | A3. | Middel | Public/private leakage. | Vóór Library Ratings/Reviews UI. |
| Thin Completed-Core UI Slices | C6/C7 en daarna F2.6/F2.8 adapters: Notes, Hierna lezen, Rating/Review ownerflows. | C | A3 en passende Work/source discovery. | Middel | Editor escaping, stale state en pickercontract. | Goede tweede golf na F3.0. |
| Wishlist, Collections & Acquisition | C1–C3. | C | Catalog identity; Collections vóór Collection-goals. | Groot | Nieuwe ordering/lifecycle/schema. | Onafhankelijk na UI-start. |
| Lending | C4–C5: ExternalLoan lifecycle en InternalLoan. | C | Expliciet source-schema-/lifecyclebesluit. | Groot | Privacy, settlement en ReadingRound-source-evolutie. | Na start; vóór lending UI. |
| Goals, Insights & Home | C9–C14: goals, Timeline, Stats, Jaaroverzicht, actions en Home. | C | Betrouwbare reading/lending/list-bronnen. | Groot | Schijnprecisie en dure aggregaties. | Afgeleid; niet gate-kritiek. |
| Audit, Settings & Operations | C8/C15: ActivityEvent reader, defaults/preferences en healthpresentatie. | C | Relevante mutationdomains. | Middel | Eventauthorization en instellingsovererving. | Later per concrete UI-behoefte. |

## 14. Work-level en creditinschatting

Er staat geen historisch creditverbruik in de repository. Numerieke provider-
of factureringscredits zijn daarom niet verantwoord te voorspellen. De
bandbreedtes hieronder zijn **relatieve implementatiecredits** voor planning,
waarbij recente F2.7b–F2.9b-slices als kwalitatieve referentie dienen. Ze zijn
geen prijs- of tijdgarantie en hebben circa ±50% onzekerheid.

| Pre-Elementor fase | Work-level | Relatieve creditband | Vergelijking/ onzekerheid |
| --- | --- | ---: | --- |
| F2.10 Library Identity & Context | GEMIDDELD | 4–7 | Kleiner dan F2.7b, maar migration/backfill en nog open naamcontract kunnen uitlopen. |
| F2.11 Catalog UI Read Models | GEMIDDELD | 4–7 | Minder domeinmutatie dan F2.9b; query/index- en isolationbewijs bepalen de bovengrens. |
| F2.12 REST Adapter Foundation | HOOG | 6–10 | Geen nieuw zwaar aggregate, maar wel een geheel nieuwe security-/transport-/testlaag. |
| Readiness Gate/closure | LAAG | 1–2 | Alleen bewijs, regressiegate en documentatie wanneer A1–A3 compleet zijn. |

Gate-kritiek totaal: grofweg **15–26 relatieve credits**. De grootste
onzekerheden zijn de nog te kiezen Librarynaam/backfill en hoeveel WordPress
REST-testinfrastructuur vanaf nul nodig blijkt. Splits elke fase in een eigen
analyse-/contractcommit en implementatie-/exitcommit om creditverlies door
scopegroei te beperken.

## 15. Elementor-readiness gate

De gate is **GO** uitsluitend wanneer ieder vak objectief bewijs heeft.

- [ ] **Core business rules stabiel:** de gekozen slice gebruikt alleen de
  canonieke collection-view- en ReadingRound-startregels; geen open productvariant
  in de route.
- [ ] **Library identity compleet:** Librarynaam/default/backfill is besloten,
  gemigreerd en schema-health is groen.
- [ ] **Auth/context server-side:** actor wordt uitsluitend via WordPress
  resolved; Library target is expliciet en Core bouwt de context.
- [ ] **Readcontract compleet:** owner-scoped Librarylijst, bounded active Item-
  overzicht en Itemdetail hebben stabiele DTOs, sortering, cursor en capability-
  fields.
- [ ] **Writecontract compleet:** start-reading accepteert geen actor, Work,
  source of Round-ID; duplicate/source-unavailable/conflict zijn stabiel.
- [ ] **Adapter veilig:** routes zijn versioned; cookie-auth/REST-nonce,
  permission callbacks, typed input en allowlist serialization zijn bewezen.
- [ ] **Error contract stabiel:** 401/404/409/422/503 en machinecodes lekken
  geen database-, stack-, foreign-user- of foreign-Library-details.
- [ ] **Schema afgeschermd:** Elementor/JS kent geen tabel/kolom/join en A2-
  queries hebben passend index-/querybewijs.
- [ ] **Tests groen:** nieuwe application- en REST-integratietests plus de
  volledige bestaande Core-gate slagen zonder worktreemutatie.
- [ ] **Direct-request security:** forged actor, foreign Library/Item,
  inactive membership, ontbrekende/ongeldige nonce en Alleen bekijken-start
  worden server-side geweigerd.
- [ ] **Geen UI-domeinlogica:** capabilities zijn informatief uit Core; de
  route blijft beslissend bij iedere mutation.
- [ ] **E2E testbaar:** deterministische fixture, loginpad, routehealth en
  stabiele selectors/responsecontract zijn klaar voor de eerste F3.0-test.
- [ ] **Runtime gereed:** Elementor/Pro is beschikbaar en reproduceerbare
  export/versionering is gekozen zonder licensed packages in Git te plaatsen.

Eén ontbrekend A-vak geeft **NO-GO** voor de F3.0-mutation. Een ontbrekend B- of
C-domein geeft geen NO-GO zolang de eerste slice er niet van afhankelijk is.

## 16. Open beslissingen

### Blockerend voor A1

1. **Librarynaamcontract:** verplichte naam, maximale lengte/normalisatie,
   uniqueness (aanbevolen: niet uniek) en de default/backfill voor reeds
   automatisch geprovisioneerde persoonlijke Libraries. Dit is een kleine
   productbeslissing met schema-impact en mag niet uit technische ID worden
   afgeleid.

### Niet blocker voor F3.0, wel vóór hun eigen slice

2. **Ratings/Reviews audience:** betekent “published to a Library” zichtbaar
   voor iedere webbezoeker of alleen voor gebruikers die de Librarycollectie
   mogen bekijken? Tot besluit geldt de veiligste grens: geen ongeautoriseerde
   public REST-route.
3. **InternalLoan als ReadingRound-source:** derde expliciete FK of een
   gemeenschappelijke physical-source-identiteit. ADR-004 laat dit bewust open.
4. **Rijke cataloguspersistence/governance:** exacte Auteur/Serie/Edition-
   relaties, correctievoorstel en search/entity-resolutioncontract.
5. **Elementor configuration delivery:** welke exportvorm/versionering wordt
   gebruikt zodra de gelicentieerde lokale plugin beschikbaar is.

Geen van beslissingen 2–5 hoeft de drie gate-stappen of het gekozen direct-
access F3.0-pad te vertragen. Beslissing 1 hoort expliciet in F2.10.

## 17. Eindverdict

**READY AFTER 3 STEPS**

Biblio Core is niet “READY NOW”: er is geen transportadapter, geen betrouwbare
Library-switch/readmodel en geen bounded catalogusoverzicht/detailcontract.
Elementor nu aansluiten zou context, joins, capabilities, validation en error-
semantiek in de UI dwingen.

Biblio is evenmin algemeen “NOT READY”. De gekozen eerste mutation en haar
authorization/integriteit zijn al aantoonbaar volwassen. Na F2.10 Library
Identity & Context, F2.11 Catalog UI Read Models en F2.12 WordPress UI Adapter
kan de objectieve gate worden uitgevoerd. Wishlist, Collections, InternalLoan,
Timeline, Statistieken, Jaaroverzicht, Leesdoelen, Openstaande acties en brede
Home hoeven daarvoor niet eerst gebouwd te worden.

De kortste verstandige route is daarom:

`HEAD 272cc588 → F2.10 → F2.11 → F2.12 → Core/UI Gate GO → F3.0`.
