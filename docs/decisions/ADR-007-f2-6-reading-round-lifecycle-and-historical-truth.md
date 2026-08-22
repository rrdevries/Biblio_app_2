# ADR-007 — F2.6 ReadingRound lifecycle en historische waarheid

Status: Accepted

Scope: Biblio V2 v2.001 / F2.6

## Context

Schema 1002 en de huidige productiecode ondersteunen alleen een actieve,
user-owned ReadingRound. Iedere rij heeft precies een Item- of
ExternalLoan-bron, `round_status` kan alleen `active` zijn en `started_at` is
een door de startflow aangeleverde inhoudelijke datum/tijd die als UTC-instant
wordt opgeslagen. De database bewaakt één actieve ronde per User + concrete
bron. De applicationlaag leidt Work af uit de geautoriseerde bron en leest een
ronde owner-scoped terug.

v2.001 vereist daarnaast afsluiten als uitgelezen of gestopt, bronloze
historische registratie, behoud van bekende datumprecisie, correctie van
afgesloten historie en afgeleide persoonlijke Work- en herleesstatus. Deze
uitbreiding mag technische mutatietijd niet als inhoudelijke leesdatum
presenteren en mag bestaande bron-, ownership-, isolation- en
concurrencycontracten niet verzwakken.

## Decision

### 1. Aggregate en lifecycle

`ReadingRound` blijft private user-owned data met een stabiele
`ReadingRoundId`, `UserId` en `WorkId`.

De enige opgeslagen lifecyclewaarheid is een nullable `ReadingRoundOutcome`:

- `null` betekent `active`;
- `completed` betekent `ended` en Uitgelezen;
- `stopped` betekent `ended` en Gestopt.

`ReadingRoundLifecycle` (`active` of `ended`) wordt in het domein uit outcome
afgeleid en wordt niet als tweede mutable waarde opgeslagen. Er is geen
`paused`. Een normaal doorlopen ReadingRound wordt niet hard verwijderd om
leesgeschiedenis te corrigeren. Alleen een volledig foutieve
`historical_manual` registratie mag volgens §13 worden verwijderd.

Een `ReadingRoundVersion` is een positieve integer. Creatie start op versie 1.
Iedere echte lifecycle- of correctiemutatie verhoogt de versie exact eenmaal.
No-op en stale-no-op verhogen niet.

### 2. Provenance en bron

`ReadingRoundProvenance` heeft precies drie waarden:

- `legacy_source_started`: vóór schema 1003 via een bestaande source-startflow
  aangemaakt;
- `source_started`: vanaf schema 1003 via een normale source-startflow
  aangemaakt;
- `historical_manual`: handmatig als afgesloten historie geregistreerd.

Een `legacy_source_started` of `source_started` ronde begint met exact één
Item- of ExternalLoan-bron. Work is bij creatie uit die geautoriseerde bron
afgeleid en de repository verifieert opnieuw dat bron en Work overeenkomen.
Provenance beschrijft hoe de ronde is ontstaan en blijft immutable; de huidige
bron is afzonderlijke, corrigeerbare provenance-informatie.

Een expliciete broncorrectie is toegestaan voor active en ended rondes. Een
nieuwe concrete bron moet volgens de toepasselijke source-regels geldig en
toegankelijk zijn en via Item→Edition→Work of ExternalLoan→Work exact hetzelfde
Work vertegenwoordigen als de ronde. Daardoor zijn Item→ander Item en
Item↔ExternalLoan toegestaan wanneer beide hetzelfde Work vertegenwoordigen.
Broncorrectie wijzigt nooit Work en maakt geen nieuwe ReadingRound. Een
bestaande concrete bron mag alleen expliciet naar unknown/source-free worden
gecorrigeerd wanneer die opgeslagen bron fout is en de juiste bron niet meer
bekend is. Andere lifecycle- of datacorrecties laten de bron onaangeroerd.

Een `historical_manual` ronde wordt afgesloten en bronloos geregistreerd. De
service accepteert een bestaand `WorkId` pas nadat de actor server-side is
vastgesteld. Later mag via dezelfde expliciete broncorrectieregels een concrete
bron van datzelfde Work worden gekoppeld. Work is platformbrede
bibliografische identiteit en de ronde blijft van de actor; een Library,
membership of Library ActivityEvent wordt daardoor niet de owner. F2.6
introduceert geen pseudo-Item, pseudo-ExternalLoan of InternalLoan-bron.

De directe historische registratieroute registreert conform het functioneel
ontwerp een `completed` ronde. Een latere correctie mag de foutief vastgelegde
outcome naar `stopped` wijzigen; provenance blijft `historical_manual`.

### 3. Inhoudelijke leesdatum en technische tijd

`ReadingDate` bewaart uitsluitend bekende kalendercomponenten:

- exacte dag: jaar, maand en dag;
- maandprecisie: jaar en maand, dag `null`;
- jaarprecisie: jaar, maand en dag `null`.

Jaar ligt binnen 1000–9999. Maand is 1–12. Een dag vereist een maand en moet
een bestaande dag in die maand en dat jaar zijn. Andere vormen zijn ongeldig.
Er wordt nooit een onbekende component met 1 januari, de eerste dag van een
maand of een andere fictieve waarde gevuld.

`ReadingPeriod` bestaat uit:

- een inhoudelijke startdatum;
- voor een afgesloten ronde een verplichte inhoudelijke einddatum.

Een nieuwe normale actieve ronde heeft een exacte startdag en geen einddatum.
Finish of stop legt een exacte einddag vast. Een handmatige historische ronde
heeft een verplichte einddatum met dag-, maand- of jaarprecisie en een
optionele startdatum met dezelfde precisiekeuzes. Correctie van een afgesloten
ronde mag deze inhoudelijke start- en einddatum wijzigen en bij handmatige
historie de optionele startdatum verwijderen.

Een periode is geldig wanneer de bekende intervallen ten minste één mogelijke
chronologie toelaten: `start.earliest <= finish.latest`. Overlappende
onzekere intervallen blijven geldig en worden niet kunstmatig geordend.

Technische `created_at`, `updated_at` en nullable `ended_at` zijn UTC
`DATETIME(6)`-instants voor persistence en concurrency:

- nieuwe rijen hebben `created_at = updated_at`;
- active heeft `ended_at = null`;
- de eerste echte overgang naar ended zet `ended_at` en `updated_at`;
- een correctie van ended behoudt `ended_at` en wijzigt `updated_at`;
- no-op en stale-no-op wijzigen geen technische timestamp.

Deze instants zijn nooit de inhoudelijke leesdatum. Voor bestaande schema-1002
rijen is de technische creatietijd onbekend; migration verzint die niet.
Daarom mogen `created_at` en `updated_at` alleen voor
`legacy_source_started` null zijn totdat een echte mutatie `updated_at` zet.

### 4. Legacy `started_at`

De bestaande `started_at` bevat een exacte inhoudelijke UTC-instant, maar niet
de oorspronkelijke gebruikerstijdzone of expliciete kalenderprecisie. Schema
1003 bewaart deze kolom en waarde ongewijzigd als legacy content evidence. De
migration leidt daaruit geen vermeend lokale dag af en vult geen nieuwe
jaar/maand/dagvelden.

`LegacyReadingStartInstant` is daarom alleen voor gemigreerde
`legacy_source_started` rondes toegestaan. Nieuwe writes gebruiken uitsluitend
`ReadingDate`. Een afgesloten legacy ronde krijgt wel een expliciete
inhoudelijke einddatum. Projecties mogen de legacy start als exact UTC-instant
tonen of daarmee deterministisch sorteren, maar mogen haar niet labelen als een
door de gebruiker bevestigde lokale kalenderdag.

### 5. Chronologie, eerste lezing en herlezing

Voor een `ReadingDate` geldt een kennisinterval:

- dag: die kalenderdag;
- maand: eerste tot en met laatste kalenderdag van die maand;
- jaar: 1 januari tot en met 31 december van dat jaar.

Alleen `completed` rondes van dezelfde User + Work nemen deel. Active en
`stopped` doen niet mee.

Voor twee completed rondes A en B geldt:

- A is aantoonbaar eerder dan B als `A.finish.latest < B.finish.earliest`;
- gelijke exacte datums, overlappende maand-/jaarintervallen en gemengde
  precisies met overlap leveren geen aantoonbare volgorde;
- technische timestamps, startdatums, ID's en invoervolgorde breken geen
  inhoudelijke gelijkstand.

`ReadingSequenceClassification` is afgeleid als:

- `first_read`: deze ronde is de enige completed ronde, of haar eindinterval
  ligt aantoonbaar vóór dat van iedere andere completed ronde;
- `reread`: er bestaat ten minste één completed ronde die aantoonbaar eerder
  eindigde;
- `chronology_indeterminate`: er is meer dan één completed ronde, maar op basis
  van de bekende einddata kan voor deze ronde geen van beide worden bewezen.

Daardoor is er per User + Work hoogstens één bewezen `first_read`; er kunnen
nul bewezen eerste lezingen zijn. Een deterministische lijstsortering gebruikt
`finish.earliest`, daarna `finish.latest` en ten slotte `ReadingRoundId`. Deze
laatste tie-break is alleen presentatie-orde en mag nooit als eerste
lezing/herlezing worden uitgelegd.

### 6. Persoonlijke Work-read-status

`PersonalWorkReadingStatus` wordt per User + Work afgeleid en niet redundant
op Work opgeslagen:

1. minstens één active ronde: `reading` / Aan het lezen;
2. anders minstens één completed ronde: `read` / Uitgelezen;
3. anders: `not_read` / Niet gelezen.

Een of meer gestopte rondes zonder completed ronde blijven `not_read`. Een
gestopte herlezing wist een eerdere completion niet. Historische en normale
completed rondes tellen gelijk. Een active herlezing heeft door regel 1 status
`reading`; first/reread is een afzonderlijke historische projectie.

### 7. Application boundaries en authorization

De production applicationlaag krijgt expliciete, owner-scoped services:

- `FinishReadingRoundService`;
- `StopReadingRoundService`;
- `RegisterHistoricalReadingRoundService`;
- `CorrectEndedReadingRoundService`;
- `CorrectReadingRoundSourceService`;
- `DeleteHistoricalReadingRoundService`;
- owner-scoped reads voor ronde, User + Work-status en leesvolgorde.

Iedere mutatieservice:

1. resolveert de actor via `AuthenticatedUser`;
2. gebruikt uitsluitend owner-scoped repositorymethoden;
3. doet geen caller-supplied User- of Library-authorization;
4. start pas daarna de mutationele transactie en locked/CAS-read;
5. geeft onbekend en niet-owned dezelfde niet-onthullende uitkomst.

Finish/stop accepteren ronde-ID, verwachte versie en de gewenste inhoudelijke
einddag. Correctie accepteert ronde-ID, verwachte versie en de volledige
gewenste corrigeerbare toestand: outcome, inhoudelijke start en einddatum.
Broncorrectie is een afzonderlijke expliciete use-case met ronde-ID, verwachte
version en een concrete Item-/ExternalLoan-bron of expliciet unknown. Daardoor
kan geen andere correctie de bron impliciet wijzigen. Immutable zijn ID, User,
Work, provenance, `created_at` en `ended_at`.

Historische registratie accepteert geen ID of bron van de caller. Zij vereist
een bestaande Work, outcome is `completed`, en legt de opgegeven precieze
ReadingPeriod plus `historical_manual` vast. Nieuwe Work/Edition-creatie en
entity resolution zijn geen onderdeel van F2.6.

Verwijdering is een afzonderlijke owner-scoped use-case en accepteert alleen
een `historical_manual` ronde. Een source-started of legacy-source-started
ronde wordt nooit hard verwijderd, ongeacht latere broncorrecties. Wanneer een
historische registratie het verkeerde Work gebruikt, wordt zij verwijderd en
wordt voor het juiste Work afzonderlijk een nieuwe historische ronde
geregistreerd; Work wordt niet op de bestaande ronde gewijzigd.

`CoreApplication` exposeert alleen deze benoemde use-cases en readprojecties.
Repositories, ID-generator, transaction primitives en een generieke
ReadingRound writer blijven composition details.

### 8. Server-side ReadingRoundId

`ReadingRoundIdGenerator::next(): ReadingRoundId` is een ReadingRound-specifieke
applicationport. De productie-adapter maakt een opaque, betekenisloze ID met
voldoende entropy. ID's blijven binnen het gedeelde persistable-ID-contract.

Start via Item, start via ExternalLoan en historische registratie gebruiken
dezelfde generator. Geen adapter/caller levert een ReadingRoundId.
Primary-keyuniciteit in de database is finaal. Alleen een expliciet als
ReadingRound-primary-keycollision vertaalde insertfailure wordt maximaal drie
issuancepogingen opnieuw geprobeerd. Iedere poging krijgt een nieuwe ID. Een
bron-uniekheidsconflict, andere constraintfout of persistencefailure wordt
niet als ID-collision herhaald.

### 9. Repository- en transactioncontract

De repository biedt geen publieke willekeurige state replacement. Benodigde
ports zijn owner-scoped reads, create voor de twee geldige constructiepaden,
CAS end/contentcorrect/sourcecorrect, conditional delete van uitsluitend
`historical_manual` en User + Work-queryprojecties.

Een mutationele service voert locked current-state read, beslissing en write
in één transactie uit. De database-update gebruikt minimaal
`reading_round_id + user_id + expected_version` en de toepasselijke huidige
lifecyclevoorwaarde. Geen Library ActivityEvent of private audit-event wordt
geschreven. De rij zelf bevat alleen actuele domeintoestand, provenance en
technische concurrencydata; dit ADR introduceert geen event sourcing.

Stable failure reasons onderscheiden minimaal:

- validatie;
- source/Work unavailable bij start;
- ReadingRound not available (onbekend of niet-owned, zonder disclosure);
- stale divergent ReadingRound;
- ReadingRound source correction unavailable of Work-mismatched;
- ReadingRound deletion not allowed;
- actieve bron al in gebruik;
- ID collision na begrensde retries;
- persistence/transaction failure.

Conflict exceptions voor stale divergent dragen de actuele ReadingRound of
een gelijkwaardig veilig current-state-resultaat, zodat een adapter gericht kan
herladen zonder een tweede onbeveiligde lookup.

### 10. No-op-, stale- en concurrencymatrix

| Current | Request | Expected version | Result |
|---|---|---:|---|
| active | finish naar X | current | één update naar completed, version +1 |
| active | stop naar X | current | één update naar stopped, version +1 |
| completed X | finish naar X | current of stale | success, actuele state, geen write |
| stopped X | stop naar X | current of stale | success, actuele state, geen write |
| ended | identieke volledige correctie | current of stale | success, actuele state, geen write |
| active/ended | identieke broncorrectie | current of stale | success, actuele state, geen write |
| active/ended | geldige andere bron of expliciet unknown | current | één CAS-update, version +1 |
| active/ended | afwijkende broncorrectie | stale | typed stale conflict met actuele state |
| historical_manual | delete | current | conditionele hard delete |
| legacy/source-started | delete | elke | stable deletion-not-allowed, geen write |
| active/ended | afwijkende mutatie | stale | typed stale conflict met actuele state |
| completed | stop of andere einddatum | current | alleen via correctionservice, één update |
| stopped | finish of andere einddatum | current | alleen via correctionservice, één update |

Twee identieke finish- of stoprequests vanaf dezelfde versie convergeren: één
CAS-winnaar, waarna de verliezer de reeds bereikte gewenste toestand als
stale-no-op terugkrijgt. Concurrent completed versus stopped heeft exact één
winnaar; de verliezer ziet een afwijkende actuele toestand en krijgt het stable
stale conflict. Er is geen merge of last-write-wins.

Content- en broncorrectie vergelijken eerst de volledige semantische gewenste
toestand met de locked actuele toestand en toetsen pas daarna de versie.
Daardoor is een identieke stale correctie een no-op; een afwijkende stale
correctie een conflict. Een echte correctie verhoogt version exact eenmaal.
Delete gebruikt dezelfde owner- en expected-versiongrens en slaagt alleen als
de actuele provenance nog `historical_manual` is.

### 11. Schema 1002 → 1003

F2.6 verandert het ReadingRound-persistencecontract en vereist daarom formele
migration `1002 -> 1003`. `FORMAL_BASELINE_VERSION` blijft 1000; de
baseline-installer en de bestaande 1000→1001 en 1001→1002 migrations blijven
ongewijzigd. `CURRENT_VERSION` wordt pas in de implementatieslice 1003 en de
production registry krijgt dan de derde ordered stap.

De migration wijzigt uitsluitend de bestaande ReadingRound-tabel:

- behoudt `reading_round_id`, `user_id`, `work_id`, bron-FK's en alle data;
- behoudt `started_at`, maakt haar nullable en gebruikt haar na migration
  alleen als legacy content evidence;
- vervangt mutable `round_status` door nullable `round_outcome`
  (`completed|stopped`), waarbij lifecycle wordt afgeleid;
- voegt `provenance` toe;
- voegt nullable `reading_started_year`, `reading_started_month`,
  `reading_started_day`, `reading_finished_year`, `reading_finished_month` en
  `reading_finished_day` toe;
- voegt nullable `created_at`, `updated_at`, `ended_at` en non-null
  `round_version` toe;
- bouwt de twee generated active-userkolommen en hun unique indexes opnieuw op
  met `round_outcome IS NULL` als active predicate;
- vervangt de oude status- en source-XOR-checks door de hieronder beschreven
  lifecycle-, provenance-, bron-, precisie- en timestampchecks;
- behoudt alle drie `RESTRICT` foreign keys en de bestaande user/work
  readindexes;
- voegt `reading_rounds_by_user_work_finish` toe op `user_id`, `work_id`,
  `round_outcome`, de drie finishcomponenten en `reading_round_id` voor de
  owner-scoped afleidingen.

Het exacte nieuwe/gewijzigde kolomcontract is:

| Column | SQL type | Null | Betekenis |
|---|---|---:|---|
| `started_at` | `DATETIME(6)` | ja | ongewijzigde legacy content instant, uitsluitend legacy provenance |
| `round_outcome` | `VARCHAR(16)` | ja | null active; `completed` of `stopped` ended |
| `provenance` | `VARCHAR(32)` | nee | een van de drie waarden uit §2 |
| `reading_started_year` | `SMALLINT UNSIGNED` | ja | bekend inhoudelijk startjaar |
| `reading_started_month` | `TINYINT UNSIGNED` | ja | bekende startmaand |
| `reading_started_day` | `TINYINT UNSIGNED` | ja | bekende startdag |
| `reading_finished_year` | `SMALLINT UNSIGNED` | ja | verplicht inhoudelijk eindjaar voor ended |
| `reading_finished_month` | `TINYINT UNSIGNED` | ja | bekende eindmaand |
| `reading_finished_day` | `TINYINT UNSIGNED` | ja | bekende einddag |
| `created_at` | `DATETIME(6)` | ja | technische creatie-instant; alleen legacy mag null |
| `updated_at` | `DATETIME(6)` | ja | laatste echte mutatie; alleen ongemuteerde legacy mag null |
| `ended_at` | `DATETIME(6)` | ja | technische eerste lifecycle-overgang; verplicht voor ended |
| `round_version` | `BIGINT UNSIGNED` | nee | positieve CAS-version, expliciet geschreven |

Geen van de nieuwe non-null kolommen houdt na migration een database-default;
iedere production insert moet provenance en version expliciet leveren. De
bestaande `round_status` wordt verwijderd. De bestaande generated kolommen
houden hun namen en typen, maar hun expressions worden exact:

- `CASE WHEN round_outcome IS NULL AND item_id IS NOT NULL THEN user_id ELSE NULL END`;
- `CASE WHEN round_outcome IS NULL AND external_loan_id IS NOT NULL THEN user_id ELSE NULL END`.

De unique indexes `one_active_item_round_per_user` en
`one_active_external_round_per_user` blijven op de overeenkomstige generated
userkolom plus bron-ID staan.

De vervangende named checks zijn:

- `reading_rounds_outcome`: toegestane outcome-vormen;
- `reading_rounds_provenance`: toegestane provenancewaarden;
- `reading_rounds_source_shape`: nooit tegelijk een Item- en ExternalLoan-bron;
  nul of één bron is persistabel omdat iedere provenance na een expliciete
  correctie concrete of unknown source-informatie kan hebben;
- `reading_rounds_start_shape`: legacy instant uitsluitend bij legacy,
  exacte nieuwe startdag voor `source_started`, en optionele geldige precisie
  voor `historical_manual`;
- `reading_rounds_finish_shape`: geen finishcomponenten voor active en een
  geldige dag-/maand-/jaarvorm voor ended;
- `reading_rounds_calendar_dates`: jaarbereik, maandbereik en echte
  kalenderdag via MariaDB-kalenderfuncties voor beide contentdatums;
- `reading_rounds_period_possible`: wanneer start bekend is, ligt diens
  vroegst mogelijke dag niet na de laatst mogelijke einddag;
- `reading_rounds_technical_time`: active/ended-`ended_at`, verplichte
  new-row technische instants en `updated_at >= created_at` waar beide bekend
  zijn;
- `reading_rounds_version_positive`: `round_version >= 1`.

Alle bestaande rijen worden zonder inhoudelijke herinterpretatie:

- `round_outcome = null` en dus active;
- `provenance = legacy_source_started`;
- `round_version = 1`;
- met exact dezelfde IDs, Work, bron en `started_at` gemigreerd;
- met nieuwe contentcomponenten en onbekende technische create/update-instants
  op null gehouden.

Databasechecks borgen minimaal:

- outcome is null, `completed` of `stopped`;
- provenance is een van de drie waarden;
- `legacy_source_started` heeft een legacy `started_at`, geen nieuwe
  startcomponenten en mag active of ended zijn;
- `source_started` heeft geen legacy `started_at`, een volledige exacte
  startdatum en mag active of ended zijn;
- `historical_manual` heeft geen legacy `started_at` en is ended;
- iedere provenance heeft na creatie/correctie nul of één bron, nooit beide;
- active heeft geen finishdatum en geen `ended_at`; ended heeft een geldige
  finishdatum en `ended_at`;
- componentnullable-vormen en kalendergeldigheid volgen §3;
- nieuwe niet-legacy rijen hebben `created_at` en `updated_at`;
- version is minstens 1.

Cross-table bron→Work-consistentie blijft defense-in-depth in de wpdb-adapter,
omdat de huidige relationele structuur geen generieke cross-table CHECK kan
uitdrukken. De bestaande bron-FK's en source-active uniqueness blijven echte
databasegaranties.

### 12. Migration en schema-health

De migration heeft een expliciete gezonde-1002-precondition, een herkenbare
lijst van eigen tussenstappen en een volledige 1003-postcondition. Omdat
MariaDB-DDL niet volledig transactioneel is, zijn add/drop/alter-stappen
idempotent op bekende tussentoestanden. Een onbekende combinatie of drift
faalt gesloten. De version option wordt pas 1003 na de volledige
postcondition; failure laat haar 1002 en retry convergeert zonder dat bestaande
inhoud wordt herschreven.

De ordered implementation sequence is:

1. bewijs gezonde schema-1002-DDL en de huidige ReadingRound-data-invarianten;
2. voeg de nieuwe gewone kolommen eerst nullable toe, inclusief tijdelijk
   nullable `provenance` en `round_version`;
3. backfill uitsluitend bestaande rijen naar `legacy_source_started`, version
   1 en outcome null; verifieer dat aantallen en legacywaarden gelijk zijn;
4. maak `started_at` nullable en maak na bewezen volledige backfill
   `provenance` en `round_version` non-null zonder default;
5. verwijder de twee active unique indexes, daarna hun generated kolommen en
   vervolgens de oude status/source-checks; verwijder `round_status` pas nadat
   `round_outcome` en de backfill aantoonbaar aanwezig zijn;
6. maak de generated active-userkolommen opnieuw met de §11-expressions en
   herstel hun unique indexes;
7. voeg het nieuwe User+Work+finish-readindex en de negen named checks uit §11
   toe zonder bestaande FK's of overige indexes te vervangen;
8. voer volledige data- en DDL-postcondition plus schema-health 1003 uit;
9. laat pas daarna de bestaande migrator de version option naar 1003 zetten.

Iedere stap detecteert `absent`, `exact expected intermediate` of `exact final`
en voert alleen de ontbrekende overgang uit. Een afwijkende definitie, een
halfgevulde backfill of een onbekende constraint/index-expression is geen
retrycandidate en faalt gesloten. Tijdens migration wordt Core application
niet gecomposeerd; daardoor schrijft geen F2.6-applicationservice tegen een
tussenschema.

Schema-health 1003 inspecteert de nieuwe kolomtypen/nullability, generated
expressions, indexes, drie foreign keys en alle essentiële CHECK-constraints.
Zij controleert tevens data-invarianten die niet betrouwbaar uit DDL-metadata
alleen blijken, inclusief provenance/source-vorm, datumcomponentvorm,
lifecycle/finish/timestamps en positieve versions. Afwijking is unhealthy en
blokkeert Core boot. Er is geen non-blocking adoption of automatische
datarepair voor ReadingRound.

Fresh install blijft eerst baseline 1000 installeren en doorloopt daarna
1000→1001→1002→1003. Een gezonde 1002-installatie doorloopt alleen de nieuwe
stap. Baseline 1000 wordt niet herschreven.

### 13. Correctie, broncorrectie, verwijdering en afgeleide effecten

Outcome en de volledige inhoudelijke ReadingPeriod zijn alleen bij een ended
ronde corrigeerbaar. Broncorrectie is voor active en ended toegestaan en staat
los van deze datacorrectie. Zij kan een concrete bron van hetzelfde Work
vervangen door een andere concrete bron, een source-free ronde aan zo'n bron
koppelen of een aantoonbaar fout opgeslagen bron expliciet unknown maken als
de juiste bron niet meer bekend is. Provenance en Work veranderen niet; zonder
een expliciete source-correction-intentie blijft de bron exact behouden.

Alleen een volledig foutieve `historical_manual` registratie mag conditioneel
hard worden verwijderd. Dit blijft toegestaan nadat zo'n historische ronde
via broncorrectie een concrete bron heeft gekregen, omdat provenance en niet
de actuele bronvorm de deletebevoegdheid bepaalt. Een
`legacy_source_started` of `source_started` ronde wordt nooit hard verwijderd.
Een verkeerd Work wordt nooit op een ronde gecorrigeerd: alleen de foutieve
handmatige historische registratie mag worden verwijderd, waarna voor het
juiste Work een nieuwe historische ronde wordt geregistreerd.

Een contentcorrectie of historische delete kan direct gevolgen hebben voor:

- persoonlijke Work-status wanneer de laatste/een eerste completion in
  stopped verandert of omgekeerd;
- bewezen first-read, reread of chronology-indeterminate voor alle completed
  rondes van dezelfde User + Work;
- latere goals, statistiek en tijdlijnprojecties.

Broncorrectie wijzigt geen Work, outcome of contentperiode en daarmee niet de
persoonlijke Work-status of first/rereadclassificatie. Zij kan wel de afgeleide
bron-/Library-context van latere Tijdlijn-, goal- of statistiekprojecties
wijzigen. Zij maakt geen afzonderlijke correctie-ReadingRound.

Deze waarden worden na de transactie opnieuw uit actuele ReadingRounds
afgeleid. F2.6 slaat geen invalidatieflag, cached Work-read-status of
first/reread-vlag op. Goals, statistiek, Timeline en UI blijven buiten scope.

## Implementation slices

### F2.6b — domain, schema 1003, persistence en ID issuance

Scope:

- lifecycle/outcome/provenance/version/date-value objects;
- ReadingRound aggregate met legacy-hydrationcontract;
- migration 1002→1003, health en registry/current-version;
- uitgebreide owner-scoped repository en wpdb-adapter, inclusief optional
  source-shape en CAS/conditional-delete primitives;
- ReadingRoundIdGenerator plus production-adapter en maximaal drie
  collisionpogingen;
- bestaande Item- en ExternalLoan-startflows migreren zonder gedragsregressie;
- composition en readboundary voor het nieuwe persistencemodel.

Non-scope: finish/stop/historical/contentcorrect/sourcecorrect/delete use-cases
en derived reads.

Afhankelijkheden: ADR-004, ADR-005 en dit ADR. Acceptatie: fresh/upgrade,
failure/retry, legacybehoud, constraints/health, ID collision, start-
concurrency en alle F2.3/F2.5/ReadingRound-regressies groen.

Voorgestelde commits: domain/value model; schema/migration/health; repository;
ID issuance + startflows; composition/regressies.

### F2.6c — lifecycle, historie en correctie

Scope:

- finish en stop;
- handmatige completed historische registratie;
- ended content correction;
- expliciete source correction voor active en ended;
- conditional delete van uitsluitend `historical_manual` registraties;
- owner-first authorization;
- transactionele CAS, no-op/stale-no-op/conflictsemantiek;
- productiecomposition en benoemde CoreApplication-use-cases;
- independent-process concurrentietests.

Non-scope: derived Work/reread projecties, goals/statistiek/UI en private
audit. Afhankelijkheid: F2.6b.

Acceptatie: de matrix in §10, immutable Work/provenance, expliciete
source-correctionregels, provenance-beperkte delete, datumprecisie, rollback,
geen Library ActivityEvent en één CAS-winnaar zijn aantoonbaar.

Voorgestelde commits: lifecycle services; historical registration; content- en
source-correction; historical delete; composition; concurrency/regressies.

### F2.6d — afgeleide reads en exit

Scope:

- owner-scoped persoonlijke Work-status;
- first-read/reread/chronology-indeterminate-projectie;
- deterministische sortering zonder schijnprecisie;
- query/index-evaluatie en complete F2.6 exit-evidence/documentatie.

Non-scope: goals, statistics, Timeline, Year overview, Home, REST/Abilities/UI.
Afhankelijkheid: F2.6c.

Acceptatie: alle combinaties uit §5–6, correctie-effecten, privacy/isolation,
querygedrag en de canonieke volledige gate zijn bewezen.

Voorgestelde commits: projection domain; persistence/query adapter;
application reads; acceptance/evidence closure.

## Test contract

Minimale unit- en real-MariaDB-dekking:

- lifecycle/outcome kan geen redundante of ongeldige combinatie vormen;
- alle geldige datumprecisies en kalendergrenzen; geen fictieve componenten;
- periodevalidatie met exacte, overlappende en onmogelijke intervallen;
- provenance/source/Work-invarianten, optional source-shape en legacy
  hydration;
- schema 1002→1003, fresh chain, failure vóór version bump, partial retry,
  drift/health en ongewijzigde 1000-baseline;
- bestaande rows behouden ID, User, Work, bron en legacy `started_at` exact;
- nieuwe source-backed en source-free historical roundtrips;
- owner/non-owner/anonymous resultaten en authorization vóór gevoelige lookup;
- ID issuance voor beide startflows en historie, collision retry alleen voor
  primary key en een begrensde terminal failure;
- finish/stop/no-op/stale-no-op/stale divergent en rollback;
- twee identieke en twee tegengestelde eindrequests in onafhankelijke
  processen;
- contentcorrectievelden en immutable Work/provenance/identity;
- source correction active/ended voor Item→Item, Item↔ExternalLoan,
  unknown→concrete en fout concrete→unknown, altijd met hetzelfde Work;
- source authorization vóór gevoelige lookup en non-disclosing afwijzing van
  ontoegankelijke, onbekende of Work-mismatched kandidaten;
- andere correcties wijzigen de bron niet impliciet;
- alleen `historical_manual` delete, ook na source attachment; normale
  lifecycleprovenance weigert delete;
- verkeerd historisch Work vereist delete plus afzonderlijke nieuwe registratie;
- concurrente source-correcties en delete-versus-correctie leveren via
  expected version één winnaar, stale-no-op of stable conflict zonder partial
  write;
- Work-status voor nooit, active, stopped, completed, historical en combinaties;
- first/reread/indeterminate voor dag, gelijk, maand, jaar en gemengde
  precisie;
- geen ReadingRound-mutatie schrijft een Library ActivityEvent;
- CoreApplication exposeert benoemde services en geen repository/generator;
- F2.3, F2.5, Library-isolatie en bestaande ReadingRound source-concurrency
  blijven groen.

## Consequences

- schema 1003 is noodzakelijk; F2.6 kan niet uitsluitend applicationcode zijn;
- bestaande leesstartinstants blijven waarheidsgetrouw behouden, maar hebben
  door ontbrekende historische timezone-informatie niet automatisch dezelfde
  semantiek als nieuwe kalenderdatums;
- iedere normale actieve start vereist nog steeds een concrete bron; een ronde
  kan alleen door expliciete foutcorrectie later source-free worden;
- historische provenance blijft herkenbaar wanneer een concrete bron later
  wordt gekoppeld;
- alleen volledig foutieve handmatige historische registraties zijn hard
  verwijderbaar; normale lifecyclehistorie blijft behouden;
- correcties zijn zichtbaar in technische current-state data, maar er ontstaat
  geen private auditgeschiedenis;
- first/reread kan bewust `chronology_indeterminate` zijn; productprojecties
  mogen die onzekerheid niet als een stellige volgorde presenteren;
- toekomstige goals/statistiek kunnen op deze afleidingen bouwen zonder een
  tweede leeswaarheid op Work te introduceren;
- InternalLoan vereist later opnieuw een expliciete source-modelbeslissing.

## Rejected alternatives

- lifecycle en outcome als twee onafhankelijke mutable kolommen;
- onbekende datumdelen invullen met een standaarddag of -maand;
- technische `ended_at` gebruiken als content completion date;
- bestaande UTC-instants zonder timezone-evidence tot lokale dagen migreren;
- pseudo-bronnen voor handmatige historie;
- impliciete source switching door een andere correctie;
- Work wijzigen onder de noemer source correction;
- hard delete van een normaal via Biblio gestarte ReadingRound;
- first/reread opslaan als handmatig of mutable attribuut;
- ID's door REST/UI laten aanleveren of een generieke ID-engine bouwen;
- Library ActivityEvents of een nieuwe generieke private audit-engine voor
  ReadingRound.
