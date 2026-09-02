# 14 — F2.9a analyse: Hierna lezen

> **SUPERSEDED FOR CURRENT NEXT READING CONTRACT.** Dit document blijft het
> historische, destijds geldige analyse- en beslisbewijs voor F2.9. Het daarin
> beschreven targetmodel, de inhoudelijke uniqueness en het volledig handmatige
> niet-consumerende gedrag zijn later bewust vervangen. De actuele canonieke
> waarheid staat in `docs/28-next-reading-contract-correction.md`.

Status: **GO voor F2.9b**

Scope: Biblio V2 v2.001, repository- en bronnenanalyse zonder productiecode,
tests, migration of schema te wijzigen.

## 1. Baseline en repository-state

- branch: `main`;
- begin-HEAD: `1170c15384192c2ba4397b36c7d036283841bdcc`;
- werkboom bij aanvang: schoon;
- tracking bij aanvang: gelijk aan `origin/main`;
- productversie: `v2.001`;
- formele schemabaseline: `1000`;
- actuele code-schemaversie: `1005`;
- actuele keten: `1000→1001→1002→1003→1004→1005`;
- F2.6 ReadingRounds, F2.7 Private Notes en F2.8 Ratings/Reviews: `GO`;
- er bestaat geen Hierna-lezen-domainmodel, applicationservice, repository,
  tabel, migration, composition of test in de huidige Core.

De checkout is de technische waarheid voor beschikbare source-types en
infrastructuur. De canonieke documenten zijn de productwaarheid. O1–O4 uit de
F2.9a-opdracht zijn latere, definitieve productbeslissingen.

## 2. Classificatie en bronvolgorde

Dit document gebruikt:

- **Bindend productcontract**: expliciete huidige functionaliteit;
- **Definitieve productbeslissing**: O1–O4;
- **Technische consequentie**: noodzakelijk om bindende regels tegelijk te
  houden;
- **Technische aanbeveling**: kleinste bewezen implementatiekeuze;
- **Deferred**: geen F2.9b-contract en geen blocker.

Bij conflict geldt: nieuwste expliciete beslissing, geaccepteerde ADR,
canonieke architectuur, daarna historische bron. `Hierna lezen` vervangt
`Next to read`; oude regels worden niet stilzwijgend geërfd.

## 3. Bronnen- en conflictmatrix

| Onderwerp | Actuele bron | Status | Resolutie |
| --- | --- | --- | --- |
| Private, user-owned en platformbreed | Functioneel ontwerp §§2/7; current state; acceptance §2 | Bindend | Geen Library ownership of Library-brede query. |
| Volledig handmatig | Functioneel ontwerp §7; current state; acceptance §7 | Bindend | Alleen expliciete add/remove/reorder. |
| Work- en specifieke-source-entry | Functioneel ontwerp §7; terminologie | Bindend | Twee concepten, niet één optionele hint. |
| Duplicate-regels | Functioneel ontwerp §7; acceptance §7 | Bindend | Eén Work-entry per User+Work; één source-entry per User+concrete source. |
| Work en meerdere sources van hetzelfde Work | Functioneel ontwerp §7 | Bindend | Mogen naast elkaar bestaan. |
| Geen availability/use-access/loan/ReadingRound-automatisering | Functioneel ontwerp §7; current state; Home §15 | Bindend | Status is informatie, geen selectie- of mutationtrigger. |
| Home | Functioneel ontwerp §15; acceptance §13 | Bindend | Eerste maximaal drie in opgeslagen volgorde. |
| `Next to read` | Functioneel ontwerp openingswaarschuwing; terminologie; source register | Superseded | Geen oude naam, cardinaliteit of automatisch gedrag overnemen. |
| Definitief verdwenen source | F2.9a O1 | Definitieve productbeslissing | Entry en historische targetcontext blijven bestaan. |
| Immutable target | F2.9a O2 | Definitieve productbeslissing | Geen retarget; remove plus add. |
| Availability bij add | F2.9a O3 | Definitieve productbeslissing | Geen toelatingsvoorwaarde. |
| Maximum | F2.9a O4 | Definitieve productbeslissing | Geen functionele limiet. |
| User-owned authorization | Architectuur §§3/7; ADR-001/003 | Bindend | Actor server-side; iedere query/mutation owner-scoped. |
| Source-types | Architectuur §6; ADR-004; actuele Core | Bindend technisch | Alleen Library Item en ExternalLoan zijn geïmplementeerde concrete fysieke sources. |
| InternalLoan als source | ADR-004; architectuur §6 | Deferred | Niet als apart F2.9b-target modelleren. |
| Library ActivityEvent | Architectuur §8; functioneel ontwerp §§14/16 | Bindend: nee | Private planning is geen Library-audit. |
| Timeline | Functioneel ontwerp §14 | Bindend: nee | Hierna lezen is geen v2.001 Timeline-categorie. |
| Persistence | ADR-004/005; architectuur §9 | Technisch te kiezen | Geordende owner-collectie, uniqueness, FK-behoud en concurrency passen in Core-tabellen. |

### Conflicten

1. Historische handovers noemen `Next to read`; het source register verklaart
   naam en oud gedrag superseded. Alleen `Hierna lezen` is actueel.
2. “Geen use-access filtering” betekent dat `Directe toegang`, `Lenen` of
   `Alleen bekijken` geen source-targeteligibility bepaalt. Voor een Item is
   wel actuele collection-view authorization vereist om bij add het gekozen
   Library-record veilig te mogen resolveren.
3. ReadingRound-bronnen gebruiken harde source-FK's met `RESTRICT` om historie
   te beschermen. O1 vereist voor Hierna lezen juist behoud na echte
   source-delete. Daarom is dezelfde FK-deletevorm hier niet bruikbaar.
4. De huidige Item/ExternalLoan-records bevatten geen stabiel menselijk
   sourcelabel. Een label snapshot verzinnen is niet nodig om type, originele
   ID, Work en bij Item de oorspronkelijke Library-context te behouden.

## 4. Canoniek functioneel contract

`Hierna lezen` is één private, platformbrede, user-owned, volledig handmatige
geordende collectie per gebruiker.

Een entry is exact één van:

1. een Work-entry voor één bestaande Work;
2. een Library-Item-entry voor één bestaand, op add-moment zichtbaar Item;
3. een ExternalLoan-entry voor één bestaande ExternalLoan van de actor.

Regels:

- één identieke Work-entry per User+Work;
- één entry per User+Library Item;
- één entry per User+ExternalLoan;
- Work-entry plus source-entry(s) van hetzelfde Work mogen samen bestaan;
- meerdere verschillende concrete sources van hetzelfde Work mogen samen;
- targets zijn immutable;
- nieuwe entries komen onderaan;
- alleen expliciete remove en reorder muteren de lijst;
- lezen starten, availability, Item-status, membership/use-access, loanstatus
  en ReadingRound-status muteren entry of order nooit;
- source-state mag informatief worden geprojecteerd;
- er is geen functioneel maximum.

Een Hierna-lezen-operatie provisiont niet automatisch een persoonlijke
Privébibliotheek. Work-entry en owned ExternalLoan vereisen geen Library.

## 5. Definitieve beslissingen O1–O4

### O1 — verdwenen source blijft historische targetcontext

Source-delete verwijdert of retarget de entry niet. Work, targettype,
oorspronkelijke source-ID en minimale context blijven. Een live pointer mag
`NULL` worden. Vervangen blijft remove plus add.

### O2 — immutable target

Entry-ID, owner, Work, targettype, source-ID-snapshot en eventuele
Library-contextsnapshot wijzigen nooit. Er komt geen update/retarget-service.

### O3 — availability is geen add-eligibility

Een bestaande, geautoriseerd resolveerbare source mag worden toegevoegd
ongeacht actuele fysieke beschikbaarheid, leenstatus of directe
leesbruikbaarheid. Authorization en bestaan/same-Work blijven verplicht.

### O4 — geen maximum

Domain, application en database introduceren geen countlimiet. Pagination of
lazy loading is alleen adapter-/performancegedrag.

## 6. Gap-analyse huidige Core

| Onderdeel | Huidig | Nodig in F2.9b |
| --- | --- | --- |
| List/Entry domain | Ontbreekt | Owner-collectie, entry, target, ID en listversion. |
| Source resolutie | Item- en ExternalLoan-patronen bestaan | Add-specifieke veilige resolvers en historische readresolutie. |
| Owner authorization | `AuthenticatedUser` bestaat | Iedere use-case deriveert owner uit actor. |
| Ordering | Geen Hierna-lezen-model | Eén list lock/version en transactionele posities. |
| Duplicate-integriteit | Geen opslag | Domain/application check plus named unique indexes. |
| Source-deletebehoud | Geen entry | Snapshot + nullable live-FK met `SET NULL`. |
| Reads/Home | Ontbreekt | Eigen volledige lijst en top-drieprojectie. |
| IDs | Bewezen generators/retry | Entry-specifieke server-side generator. |
| Persistence | Schema 1005 | Twee tabellen in migration 1006. |
| Health/migration | Version-aware infrastructuur | 1006-keten, retry en driftdekking. |
| Composition | Named-service boundary | Alleen expliciete commands/projecties exposen. |
| Activity/Timeline | Library audit bestaat | Niet injecteren; nul-eventbewijs. |
| UI/REST | Buiten scope | Niet toevoegen. |

## 7. Entry-, target- en aggregate-model

### 7.1 Aggregate

`NextReadingList` is de concurrency-aggregate:

- owner `UserId`;
- `NextReadingListVersion`;
- volledig geordende `NextReadingEntry`-collectie.

`NextReadingEntry` is een entity binnen die collectie:

| Veld | Null | Mutability | Herkomst |
| --- | --- | --- | --- |
| `NextReadingEntryId` | nee | immutable | server-side |
| owner | nee | immutable | authenticated actor |
| Work | nee | immutable | expliciet of afgeleid |
| target | nee | immutable | discriminated value |
| position | nee | alleen via list mutation | server-side ordering |
| created at | nee | immutable | server clock |

Er is geen individuele entry-version. Targetdata wijzigt nooit en ordering is
een lijstinvariant; per-entry-CAS zou verloren collection updates niet
afdoende beschermen.

### 7.2 Discriminated target

Gebruik een gesloten `NextReadingTargetType`:

- `work`;
- `library_item`;
- `external_loan`.

Domainconstructors zijn afzonderlijk, bijvoorbeeld `forWork`,
`forLibraryItem` en `forExternalLoan`. Geen constructor accepteert willekeurige
nullable sourcevelden. Iedere concrete target bevat Work plus immutable source
snapshot; een Work-target bevat geen sourcevelden.

### 7.3 Invarianten per laag

**Domain** bewaakt geldige IDs, positieve positie/version, target shape,
immutability en unieke entry-ID's/order in een gehydrateerde lijst.

**Application** resolveert actor, source, Work en authorization; accepteert
geen dubbele Work-waarheid; serialiseert collectionmutaties; valideert het
exacte reorder-set.

**Persistence** bewaakt FKs, target-CHECKs, owner+target uniqueness,
owner+position uniqueness en owner predicates. Repositoryhydratie verwerpt
corrupt opgeslagen targetvormen.

## 8. Geldige concrete source-types

### 8.1 Library Item — geldig

Add-input: `LibraryId + ItemId`.

Preconditions:

- actor heeft actief membership en `canViewCollection` in die Library;
- Item bestaat exact in die Library;
- Edition bestaat en bepaalt het Work;
- Item-status, availability, use-access en loanstatus zijn geen eligibility.

`Alleen bekijken` is dus voldoende om een zichtbaar Item als leesintentie te
bewaren, maar niet om daarvan direct een ReadingRound te starten. Starten
controleert zijn eigen strengere sourcecontract later opnieuw.

Later membershipverlies of statuswijziging laat entry/order intact. Zonder
actuele view authorization levert het readmodel geen beschermd live
Itemdetail, alleen de eigen historische targetcontext.

### 8.2 ExternalLoan — geldig

Add-input: `ExternalLoanId`.

Preconditions:

- owner-scoped lookup voor authenticated actor;
- record bestaat;
- Work wordt uit ExternalLoan afgeleid;
- loanstatus/beschikbaarheid is geen eligibilityvoorwaarde.

Latere statuswijziging of verwijdering laat de entry bestaan. Een andere
gebruiker kan de loan niet als target gebruiken of enumereren.

### 8.3 Geen afzonderlijke InternalLoan-target in F2.9b

InternalLoan is nog niet geïmplementeerd als Core-source. Een intern geleend
Library-exemplaar blijft voor dit contract hetzelfde `library_item`-target;
leencontext is actuele informatie en geen immutable targetidentiteit.

Geen toekomstige digitale, audio-, provider-, pseudo- of generieke source-type
wordt toegevoegd. Een later nieuw type vereist expliciete contract- en
schemauitbreiding.

## 9. Source-snapshot en live/historische resolutie

Minimale immutable snapshot:

- Work ID voor ieder type;
- target/source-type;
- oorspronkelijke source-ID voor concrete targets;
- oorspronkelijke Library ID voor een Library Item.

Niet dupliceren:

- volledig Item-, Edition-, ExternalLoan- of Library-record;
- Worktitel;
- availability/loanstatus;
- datums of labels waarvoor geen stabiel huidig sourceveld bestaat.

Projectiestatus:

- `live`: geautoriseerde actuele source is resolveerbaar;
- `unavailable`: live source is resolveerbaar maar actuele status wijst op
  niet-direct bruikbaar; dit beïnvloedt selectie/order niet;
- `inaccessible`: Item-livegegevens mogen wegens huidige Library-toegang niet
  worden geopend; historische context blijft;
- `missing`: een binnen geldige owner/context uitgevoerde lookup bevestigt dat
  de source niet meer bestaat.

Een door `ON DELETE SET NULL` verwijderde live pointer is voor de owner
voldoende bewijs voor `missing`, ook na membershipverlies. Bij een nog
aanwezige live Item-pointer zonder actuele Librarytoegang is de status
`inaccessible`; beschermd live detail wordt dan niet gelezen. Bij iedere
andere twijfel prevaleert non-disclosure.

Een hard verwijderde source wordt niet automatisch opnieuw gekoppeld wanneer
later een record met dezelfde technische ID zou verschijnen. Restoring van
hetzelfde bestaande Item-record behoudt daarentegen de live pointer.

## 10. Ownership, authorization en privacy

1. Iedere command/query begint met `AuthenticatedUser::requireUserId()`.
2. Entry reads/deletes/reorders gebruiken altijd `entry_id + user_id` of
   `user_id` als predicate.
3. Een vreemd en onbekend entry-ID levert dezelfde non-enumerating unavailable
   failure.
4. Library Eigenaar/Beheerder/Lid, support of platformrol geeft geen toegang
   tot andermans lijst.
5. Work-add vereist alleen een bestaande platformbrede Work.
6. Item-add vereist actieve collection-view authorization in de expliciete
   Library, niet fysieke use-access.
7. ExternalLoan-add vereist ownership.
8. Later toegangsverlies mutereert niets en opent geen beschermd live detail.
9. Geen application-signature accepteert `UserId` of caller-entry-ID bij add.

## 11. Orderingcontract

### 11.1 Gekozen strategie

Gebruik per User contigue positieve integerposities `1..N`, beschermd door één
list-state-row met positieve `list_version`.

Deze strategie is gekozen boven sparse/rank ordering omdat:

- de lijst door één gebruiker wordt beheerd;
- collaborative editing buiten scope is;
- volledige reorder al de volledige gewenste order kent;
- transactionele hernummering eenvoudig te testen en te herstellen is;
- een `BIGINT`-positie geen functioneel maximum introduceert.

### 11.2 Mutaties

- **Add:** list row locken; duplicate controleren; `MAX(position)+1`; entry
  invoegen; listversion +1.
- **Remove:** list row locken; owner-entry controleren; hard verwijderen;
  resterende entries deterministisch `1..N` hernummeren; version +1.
- **Reorder:** expected listversion plus volledige gewenste geordende set
  entry-ID's. Set moet exact gelijk zijn aan de actuele owner-set, zonder
  duplicate/unknown/foreign/ontbrekende IDs. Daarna `1..N` herschrijven en
  version +1.
- **No-op reorder:** wanneer gewenste order al actueel is, current list
  teruggeven zonder write/version/timestampwijziging, ook bij stale expected.
- **Stale divergent reorder:** typed `NextReadingListStale` met actuele veilige
  owner-liststate.

Een aparte move-before/move-after-persistenceprimitive en rank-rebalancing zijn
niet nodig. UI kan één drag/drop of multi-move vertalen naar het volledige
ordercommand.

## 12. Concurrencycontract

Een list-state-row is de mutex voor add, remove en reorder. Zij is nodig omdat
een lege lijst geen entryrij heeft om te locken. De state wordt op eerste
mutation idempotent aangemaakt en daarna `FOR UPDATE` gelezen.

Een gebruiker zonder state-row leest een virtuele lege lijst met version 1.
De eerste mutation maakt de row atomair met version 1 en commit de gewenste
lijst als version 2. Iedere latere echte collectionmutation verhoogt exact één.
Add heeft bewust geen expected version: het is een zelfstandige append-intentie
die onder de list lock serialiseert. Remove en reorder vereisen de expected
listversion omdat zij bestaande collectionstate wijzigen.

Gedrag:

- twee verschillende adds serialiseren en komen beide eenmaal onderaan in
  lock-acquisitionvolgorde;
- twee gelijke adds leveren één success en één typed duplicate;
- add vóór reorder maakt de oudere reorder stale; reorder vóór add commit en
  daarna appendt de add;
- delete/reorder: één commit; de andere ziet stale state, nooit gedeeltelijke
  posities;
- twee verschillende reorders: één winner, één stale;
- twee gelijke reorders convergeren naar één write en een semantic no-op;
- source-delete versus add resulteert ofwel in unavailable add, of in een
  geldige entry waarvan de live-FK na delete `NULL` is; nooit orphan/loss;
- unique indexes zijn de finale defense voor duplicate target en positie.

Alle collectionmutaties draaien in één transaction. Geen silent
last-write-wins.

## 13. Applicationservices

| Service | Input | Contract |
| --- | --- | --- |
| `AddWorkToNextReadingService` | `WorkId` | Actor; bestaande Work; list lock; duplicate; server-ID; append. |
| `AddLibraryItemToNextReadingService` | `LibraryId`, `ItemId` | Actor; collection-view; Item→Edition→Work; snapshot; append. |
| `AddExternalLoanToNextReadingService` | `ExternalLoanId` | Actor; owned source→Work; snapshot; append. |
| `RemoveNextReadingEntryService` | entry ID, expected listversion | Actor; owner lookup; list lock; hard delete + compact; stale-safe. |
| `ReorderNextReadingListService` | expected listversion, ordered entry IDs | Actor; exact owner-set; atomic full reorder/no-op/stale. |
| `GetMyNextReadingListService` | geen ownerinput | Volledige eigen geordende lijst en listversion. |
| `GetNextReadingHomeProjectionService` | geen ownerinput | Eerste drie opgeslagen entries met informatieve source-state. |

Iedere add heeft maximaal drie nieuwe-ID-pogingen na de eerste, uitsluitend op
een vertaald entry-primary-keyconflict. Targetduplicate, positionconflict,
authorization of persistencefailure wordt niet blind geretryd.

Stabiele failures omvatten minimaal authentication, target unavailable,
entry unavailable, duplicate target, stale list, ID-collision exhaustion,
persistence en transaction failure.

Geen generieke save/retarget/repository/writer wordt via `CoreApplication`
geëxposeerd.

## 14. Home-projectie

Home selecteert na owner-scope exact de eerste maximaal drie entries op
`position ASC, entry_id ASC`. De tweede sleutel is alleen defense bij corruptie;
gezond schema heeft unieke posities.

Home:

- respecteert handmatige order;
- filtert niet op availability, loanstatus, use-access, ReadingRound of
  live-sourcebestaan;
- mutereert niets;
- levert dezelfde historische fallback en veilige source-status als de lijst;
- toont minder dan drie alleen wanneer de lijst minder entries bevat.

Geen Home-UI of Home-configuratie wordt in F2.9b gebouwd.

## 15. Delete en referential integrity

### Entry delete

Conditionele owner-scoped hard delete. Er is geen canonieke history,
tombstone, recovery of Timeline-requirement. Delete raakt uitsluitend entry,
posities en listversion; nooit Work, Item, ExternalLoan, ReadingRound of andere
data.

### Source delete

Concrete live-FK gebruikt `ON DELETE SET NULL`. De immutable snapshot blijft.
Geen cascade, conversion of retarget. De source-delete-lifecycle zelf valt
buiten F2.9b, maar schema 1006 moet die toekomstige delete kunnen dragen.

### Work delete/merge

De huidige Core heeft geen gewone Work-delete/merge-use-case en bestaande
Work-FK's gebruiken `RESTRICT`. Schema 1006 volgt dit: Work-delete wordt
geblokkeerd zolang entries verwijzen. Een toekomstige centrale merge/split
moet alle Work-referenties via een apart cataloguscontract behandelen; F2.9b
introduceert daarvoor geen retargetroute.

## 16. ActivityEvent en Timeline

Add, remove en reorder schrijven geen Library `ActivityEvent`: de lijst is
private planningdata zonder Library ownership. Zij schrijven evenmin een
Timeline-event. `Hierna lezen` staat niet in de canonieke v2.001
Timeline-categorieën en F2.9b introduceert geen private event engine.

Technische timestamps blijven beschikbaar voor beheer/debugging, maar zijn
geen persoonlijke gebeurtenisprojectie.

## 17. Persistence- en schema-1006-plan

Gebruik twee Biblio-owned InnoDB-tabellen.

### 17.1 `wp_biblio_next_reading_lists`

- `user_id VARCHAR(191) utf8mb4_bin` primary key;
- `list_version BIGINT UNSIGNED NOT NULL`;
- `created_at DATETIME(6) NOT NULL`;
- `updated_at DATETIME(6) NOT NULL`;
- CHECK non-empty user;
- CHECK version `>= 1`;
- CHECK `updated_at >= created_at`;
- geen FK naar `wp_users`.

De row blijft bestaan wanneer de lijst leeg wordt en vormt de collection-lock.
Account-erasure is deferred.

### 17.2 `wp_biblio_next_reading_entries`

- `entry_id VARCHAR(191) utf8mb4_bin` primary key;
- `user_id VARCHAR(191) utf8mb4_bin NOT NULL`;
- `work_id VARCHAR(191) utf8mb4_bin NOT NULL`;
- `target_type VARCHAR(32) NOT NULL`;
- `source_id_snapshot VARCHAR(191) utf8mb4_bin NULL`;
- `source_library_id_snapshot VARCHAR(191) utf8mb4_bin NULL`;
- `item_id VARCHAR(191) utf8mb4_bin NULL`;
- `external_loan_id VARCHAR(191) utf8mb4_bin NULL`;
- `position BIGINT UNSIGNED NOT NULL`;
- `created_at DATETIME(6) NOT NULL`;
- generated nullable keys voor Work-, Item- en ExternalLoan-targetuniciteit.

Constraints/indexes:

- FK owner state → list `ON UPDATE RESTRICT ON DELETE CASCADE`;
- Work FK `RESTRICT/RESTRICT`;
- Item en ExternalLoan live-FK `ON UPDATE RESTRICT ON DELETE SET NULL`;
- CHECK targettype in de drie waarden;
- CHECK work-target zonder sourcevelden;
- CHECK Item-target met source-ID en Library-snapshot, zonder ExternalLoan;
- CHECK ExternalLoan-target met source-ID, zonder Item/Library-snapshot;
- wanneer live-FK non-null is, is deze gelijk aan `source_id_snapshot`;
- CHECK positie `>= 1`;
- unique `(user_id, position)`;
- unique `(user_id, generated_work_target)`;
- unique `(user_id, generated_item_target)`;
- unique `(user_id, generated_external_loan_target)`;
- index `(user_id, position, entry_id)` voor volledige lijst/Home.

De FK naar list state maakt ownership niet Library-scoped; zij verankert alleen
de owner-collectie. Source-library snapshot heeft bewust geen FK, zodat
historische context niet door Library-delete of membershipverlies verdwijnt.

### 17.3 Migration en health

`CoreSchema1006Migration`:

1. vereist gezond 1005;
2. maakt list table, daarna entry table;
3. accepteert alleen een bekende gezonde DDL-prefix als retry;
4. faalt gesloten op andere partial shape;
5. vereist gezonde 1006-postcondition vóór version bump.

Geen backfill: er bestaat geen oude canonieke Hierna-lezen-opslag. Fresh
install doorloopt de volledige keten. Health inspecteert tabellen, engine,
kolommen/nullability/collations, generated expressions, named indexes, FKs,
delete/update rules en CHECKs. Data-health controleert positieve/contigue
owner-posities, positieve versions en geldige targetvorm.

## 18. ID-contract

`NextReadingEntryId` gebruikt het gedeelde persistent-ID-contract: non-empty,
geldige UTF-8, maximaal 191 tekens. `NextReadingEntryIdGenerator` is een port;
de WordPress-adapter maakt opaque server-ID's met voldoende entropy.

Geen add-signature accepteert een entry-ID. Alleen een vertaald primary-key-
collision krijgt drie retries na de eerste poging. Uitputting levert een
stabiele typed conflict failure. De lijst gebruikt `UserId` als identiteit en
heeft geen zinloos extra list-ID.

## 19. Readmodels

### Eigen lijst

Retourneert listversion plus alle entries in saved order. Iedere DTO bevat:

- entry-ID;
- Work-ID en huidige Work-presentatie;
- targettype;
- veilige live/historische sourcepresentatie;
- position;
- created-at.

De logische lijst heeft geen functionele limiet. Een adapter mag later
cursor-paginering/lazy loading toevoegen op `(position, entry_id)`, mits de
globale order en reordercontracten gelijk blijven.

### Home

Dezelfde owner-boundary en targetresolver, met harde querylimiet drie na order
en zonder statusfilter.

Er komt geen Library-brede, platformbrede, sociale of zoekquery voor entries.

## 20. Threat- en invariantmatrix

| Risico | Domain | Application | Persistence |
| --- | --- | --- | --- |
| Vreemde read/delete | Geen ownerwijziging | Actor + owner-scoped unavailable | `user_id` predicate/FK |
| Vreemde reorder | Lijst hoort bij één owner | Exact owner-set | User-keyed state/positions |
| Duplicate Work/source | Target equality | Duplicate check | Named generated unique key |
| Source van ander Work | Immutable target | Work uitsluitend afleiden | Repository re-resolve + Work FK |
| Ongeautoriseerd Item | — | Library Context + view authorization | Item+Library scoped lookup |
| Vreemde ExternalLoan | — | Owner lookup | `external_loan_id + user_id` read |
| Unavailable source | Status niet in targetinvariant | Niet als eligibility gebruiken | Geen availability CHECK |
| Source verdwijnt | Snapshot blijft geldig | Historische projectie | `SET NULL`, geen cascade |
| Gemanipuleerd type/ID | Gesloten targetconstructors | Gescheiden add-services | Shape CHECKs/FKs |
| Retargetpoging | Geen mutationmethode | Geen service | Geen target-update repository |
| Duplicate positie | Unieke order in aggregate | List lock/hernummering | Unique User+position |
| Stale reorder | Listversion | No-op of typed stale | Locked state/CAS version |
| Add/reorder/delete-race | Collection aggregate | Eén transaction/mutex | `FOR UPDATE`, unique constraints |
| Source-delete-race | Snapshot geldig zonder live pointer | Atomic add outcome | FK `SET NULL` |
| ID enumeration | Opaque ID | Non-disclosing owner lookup | Owner predicate |
| Cross-user list query | — | Actor bepaalt owner | Alle queries op `user_id` |

## 21. Genummerde F2.9b-acceptatiematrix

### Domain en targets

1. Entry-ID accepteert 191 geldige UTF-8-tekens en weigert leeg/invalid/192.
2. Listversion en position zijn positief.
3. Work-target bevat geen sourcevelden.
4. Item-target bevat Work, oorspronkelijke Item-ID en Library-context.
5. ExternalLoan-target bevat Work en oorspronkelijke loan-ID.
6. Meerdere of incomplete sourcevelden worden geweigerd.
7. Owner, Work, target, entry-ID en created-at zijn immutable.
8. Er bestaat geen retarget-domainmethode of applicationservice.

### Add, ownership en cardinaliteit

9. Bestaande Work-entry wordt onderaan toegevoegd.
10. Onbekende Work wordt zonder mutation geweigerd.
11. Geautoriseerd Library Item wordt toegevoegd met afgeleid Work.
12. Item met unavailable/niet-direct-bruikbare status mag worden toegevoegd.
13. `Alleen bekijken` mag zichtbaar Item toevoegen maar niet daardoor lezen.
14. Inactief/afwezig/foreign Librarymembership kan Item niet resolveren.
15. Item uit andere Library of inconsistent Item→Edition→Work faalt.
16. Owned ExternalLoan wordt toegevoegd met afgeleid Work.
17. Vreemde/unknown ExternalLoan is non-enumerating unavailable.
18. Niet-actuele loanstatus is geen verborgen eligibilityvoorwaarde.
19. Tweede identieke Work-entry wordt typed geweigerd.
20. Tweede identieke Item-entry wordt typed geweigerd.
21. Tweede identieke ExternalLoan-entry wordt typed geweigerd.
22. Work-entry en source(s) van hetzelfde Work bestaan samen.
23. Verschillende sources van hetzelfde Work bestaan samen.
24. Item- en ExternalLoan-target van hetzelfde Work bestaan samen.
25. Geen functioneel aantalmaximum blokkeert add.

### Ordering, delete en concurrency

26. Eerste add krijgt positie 1; volgende add appendt.
27. Eigen volledige lijst leest deterministisch op saved order.
28. Geldige volledige reorder schrijft contigue `1..N` en version +1.
29. Multi-entry reorder is atomisch.
30. Identieke current reorder is no-op.
31. Identieke stale reorder is no-op wanneer desired al current is.
32. Divergente stale reorder geeft actuele veilige stale state.
33. Reorder met duplicate, missing, unknown of foreign entry-ID faalt geheel.
34. Owner hard-delete verwijdert alleen entry en compact order.
35. Delete raakt Work, Item, ExternalLoan en ReadingRound niet.
36. Andere gebruiker kan entry niet lezen of verwijderen.
37. Andere gebruiker kan lijst niet reorderen.
38. Twee gelijktijdige verschillende adds blijven beide in één geldige order.
39. Concurrent duplicate add heeft één winner en één typed duplicate.
40. Add/reorder-race verliest geen entry of ordermutation.
41. Delete/reorder-race heeft één geldige serialiseerbare uitkomst.
42. Twee verschillende reorders hebben één winner en één stale.
43. Twee gelijke reorders convergeren zonder dubbele versionincrement.
44. Eerste-list-state-creatie is concurrency-safe.

### Source lifecycle, reads en Home

45. Availabilitywijziging mutereert entry/order/version niet.
46. Loanstatuswijziging mutereert entry/order/version niet.
47. ReadingRound-start/finish mutereert Hierna lezen niet.
48. Membership/use-accessverlies mutereert Item-entry niet.
49. Beschermde live Itemdata lekt na toegangsverlies niet.
50. Item-delete zet live pointer null en behoudt snapshot/entry/order.
51. ExternalLoan-delete heeft hetzelfde behoud.
52. Source-delete maakt geen Work-entry en retarget niet.
53. Source-delete/add-race levert entry-behoud of veilige add-failure.
54. Historische sourcecontext blijft betekenisvol resolveerbaar.
55. Home retourneert maximaal eerste drie entries.
56. Home respecteert exact manual order.
57. Home filtert niet op availability, access, loanstatus of missing source.
58. Er bestaat geen Library-/platformbrede Hierna-lezen-query.

### IDs, persistence, migration en regressie

59. Entry-ID wordt uitsluitend server-side uitgegeven.
60. Alleen PK-collision retryt; vierde collision na eerste poging put uit.
61. Beide tabellen roundtrip IDs, snapshots, timestamps, version en order.
62. Database weigert targetshape, FK, targetduplicate en positionduplicate.
63. Repository defense weigert source/Work/owner-inconsistentie.
64. Fresh install eindigt gezond op schema 1006.
65. Gezond 1005 migreert naar 1006 zonder bestaande data te wijzigen.
66. Bekende DDL-before-version retry convergeert.
67. Onbekende partial 1006-state faalt gesloten.
68. Health detecteert table/column/index/FK/CHECK/generated/data drift.
69. Add/remove/reorder schrijven nul Library ActivityEvents.
70. Er wordt geen Timeline-event/storage of UI toegevoegd.
71. Production composition exposeert alleen named commands/projecties.
72. F2.6–F2.8, migration, lifecycle, concurrency en smoke regressies blijven groen.
73. Canonieke volledige quality gate is groen.

## 22. Open beslissingen en deferred onderwerpen

Er zijn **geen resterende product- of architectuurbeslissingen die F2.9b
blokkeren**. De analyse sluit bronautorisatie, targetvorm, historical fallback,
ordering, concurrency, delete en schema af.

Deferred, zonder F2.9b-impact:

- UI-copy/icoon voor missing/inaccessible sources;
- concrete lazy-loading/pagination-UX;
- InternalLoan of andere toekomstige source-types;
- centrale Work merge/split/delete-lifecycle;
- platform-account erasure;
- Timeline-uitbreiding;
- REST/Abilities/Elementor/Home-UI;
- reminders, deadlines, recommendations en collaborative ordering.

## 23. Implementatievolgorde F2.9b

1. Domain: IDs, target value, entry, list/version en failures.
2. Ports: clock, generator, source resolvers en list repository.
3. Migration 1006, table names, registry en health.
4. Wpdb persistence met list-state lock, target constraints en source snapshots.
5. Add-services per targettype met server-side identity en bounded retry.
6. Remove en full-reorder met listversion/no-op/stale contract.
7. Eigen lijst, source-resolution en Home-top-drieprojecties.
8. Production composition en uitsluitend named CoreApplication-boundaries.
9. Unit-, MariaDB-, migration-, privacy- en independent-process racetests voor
   alle 73 gevallen.
10. Volledige canonieke gate en F2.9b-exit evidence.

## 24. Exit verdict

**GO** — F2.9b kan zonder verdere productbeslissing of architectuurgok starten.
O1–O4 zijn verwerkt; geldige source-types en authorization zijn gesloten; de
collection-wide ordering/CAS-vorm is vastgesteld; schema 1006 kan historie en
source-delete veilig dragen; de 73-case acceptatiematrix is implementatieklaar.
