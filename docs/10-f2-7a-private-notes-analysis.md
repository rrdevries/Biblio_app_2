# 10 — F2.7a analyse: Mijn notities / Private Notes

Status: **GO voor F2.7b**

Scope: Biblio V2 v2.001, bron- en repositoryanalyse zonder productiecode,
tests, schema of migration te wijzigen.

## 1. Baseline

### Repository

- branch: `main`;
- begin-HEAD: `c53fa8d5b8e1c0feba73ecc2c991457792bb5bde`;
- werkboom bij aanvang: schoon;
- tracking bij aanvang: gelijk aan `origin/main`;
- productversie: `v2.001`;
- Core packageversie: `2.1.0`;
- formele schemabaseline: `1000`;
- actuele code- en lokale databaseschemaversie: `1003`;
- actuele migratieketen: `1000→1001→1002→1003`;
- actuele lokale Core-persistence: 15 `wp_biblio_*`-tabellen;
- er bestaat geen Note-domainmodel, Note-applicationservice, Note-repository,
  Note-tabel, Note-migration, Note-composition of Note-test.

De lokale schema-option en daadwerkelijke ReadingRound-DDL zijn read-only
gecontroleerd. Schema 1003 bevat de F2.6 outcome/provenance/date/version-vorm,
drie echte foreign keys en de actieve-source-indexen. Dit bevestigt dat 1003,
niet een oudere documentatiesnapshot, de technische upgradebron voor F2.7b is.

### Relevante bestaande infrastructuur

- `AuthenticatedUser` resolveert de actor server-side;
- user-owned reads gebruiken owner-scoped repositoryqueries en onthullen een
  onbekend of vreemd ID niet;
- `TransactionManager` en locked reads ondersteunen één mutationele
  beslis/write-boundary;
- ReadingRound en LibraryCatalogContext bewijzen positieve versions,
  compare-and-swap, semantic no-op, stale no-op en typed stale conflict;
- ReadingRound gebruikt een aggregatespecifieke server-side ID-generator,
  database-primary-keyuniciteit en maximaal drie collision-retries na de
  eerste poging;
- `CoreSchemaMigrator`, het production registry en schema-health ondersteunen
  een geordende, pas na postcondition gebumpte migration;
- `ProductionComposition` bouwt concrete adapters en `CoreApplication`
  exposeert uitsluitend benoemde applicationservices;
- `ActivityEvent` is uitsluitend de Library-auditgrens; F2.6 koppelt private
  user-owned mutaties daar bewust niet aan;
- het echte MariaDB-harnas, WordPress-bootstrap, onafhankelijke processen en
  de canonieke root-quality-gate zijn herbruikbaar voor F2.7b.

## 2. Classificatiemethode

Dit document gebruikt vier labels:

- **Bindend**: expliciet huidige product- of architectuurwaarheid;
- **Definitieve productbeslissing**: voor F2.7a expliciet vastgesteld contract;
- **Consequentie**: noodzakelijk om meerdere bindende regels tegelijk geldig
  te houden;
- **Aanbeveling**: minimale technische keuze, nog geen bestaand productfeit.

## 3. Bronnenmatrix

| Contract | Bron | Status | Analyse |
| --- | --- | --- | --- |
| Notes zijn user-owned | Functioneel ontwerp §§2 en 12; current state | Bindend | Een Library is nooit eigenaar. |
| Notes zijn altijd privé | Functioneel ontwerp §12; terminologie; acceptance §2/§14 | Bindend | Er bestaat geen publicatieprojectie voor Notes. |
| Alleen de eigenaar leest/schrijft | Architectuur §§3/7; ADR-003; acceptance §2 | Bindend | Actor server-side, iedere read en mutation owner-scoped. |
| Library- of platformrol geeft toegang | Functioneel ontwerp §§3/12; terminologie Supporttoegang | Bindend: nee | Eigenaar, Beheerder, Lid, support en platformrechten leveren geen bypass. |
| Note hoort bij Work | Functioneel ontwerp §12 | Bindend | Work is de verplichte inhoudelijke hoofdcontext. |
| Meerdere Notes per User + Work | Functioneel ontwerp §12 | Bindend | Geen uniqueness op User + Work. |
| ReadingRound-koppeling | Functioneel ontwerp §12 | Bindend: optioneel | Een persisted Note zonder ReadingRound is toegestaan. |
| Meerdere Notes aan dezelfde ReadingRound | F2.7a O2 | Definitieve productbeslissing | Er geldt geen uniqueness op User + ReadingRound. |
| ReadingRound-link later wijzigen/verwijderen | F2.7a O2 | Definitieve productbeslissing | De optionele context mag worden attached, gewijzigd en verwijderd. |
| Active/ended/historical ReadingRound als context | F2.7a O2 | Definitieve productbeslissing | Iedere eigen same-Work Round is geldig, ongeacht lifecyclestatus. |
| Work van bestaande Note wijzigen | Geen expliciete route; ADR-007-patroon | Consequentie: niet generiek toestaan | Zonder productcontract blijft Work immutable; fout Work betekent delete + nieuwe Note. |
| Private contribution vereist Library | Functioneel ontwerp §12 | Bindend: nee | Work-only Note-create mag geen personal-Library provisioning of membership eisen. |
| Note-mutaties in Library ActivityEvent | Functioneel ontwerp §§16/18; architectuur §8 | Bindend: nee | Private inhoud mag niet in gedeelde audit verschijnen. |
| Note in persoonlijke Tijdlijn | Functioneel ontwerp §14 | Bindend: nee | “Notes never appear”; F2.7 voegt geen private audit/event engine toe. |
| Globale full-text Notes-search | Functioneel ontwerp §15 | Bindend: nee | Geen FULLTEXT-index of zoekservice in F2.7b. |
| Archiveren van Item verwijdert Notes | Functioneel ontwerp §9 | Bindend: nee | Note blijft via Work/optionele ReadingRound onafhankelijk bestaan. |
| Eenvoudige tekstopmaak | F2.7a O1 | Definitieve productbeslissing | Beperkt veilig HTML met vaste allowlist; plain text is afgewezen. |
| Afbeeldingen, attachments en blokken | Niet canoniek beschreven; expliciet buiten F2.7-scope in de opdracht | Scopegrens | F2.7b ondersteunt ze niet en modelleert geen media/blob/block-relaties. |
| Handmatig opslaan, geen autosave | Niet in huidige canonieke bron; UI buiten scope | Deferred UI | Core krijgt expliciete create/update-calls en geen autosave-protocol. |
| Korte deletebevestiging | Niet in huidige canonieke bron; UI buiten scope | Deferred UI | Geen Core-boolean of bevestigingstekst modelleren. |
| Hard/soft/tombstone delete | F2.7a O3 | Definitieve productbeslissing | Owner-scoped conditionele hard delete; geen soft-delete of tombstone. |
| Account-erasuregedrag | Scope/deferred | Deferred | Normale user hard-delete/erasure/anonimisering is niet F2.7b. |
| Persistencekeuze | ADR-004; architectuur §9 | Technisch te beslissen | De relationele owner/Work/Round/CAS/migrationbehoefte maakt een Core-tabel de kleinste bewezen keuze. |
| Huidige schema-upgradebron | Code, lokale DB, F2.6-exitbewijs | Bindend technisch | F2.7b wordt migration `1003→1004`; baseline 1000 blijft ongewijzigd. |

### Bronconflicten en resolutie

- ADR-004 en de vroegere architectuurtekst beschrijven persisted ReadingRound-
  source nog als exact-one/XOR. ADR-007, current state, F2.6-exitbewijs, code en
  lokale schema-1003-DDL zijn later en staan zero-or-one toe terwijl normale
  start exact één source blijft eisen. F2.7 gebruikt de latere 1003-waarheid.
- F2.5-secties noemen terecht hun toenmalige schema 1002; zij zijn geen actuele
  versionclaim na F2.6. Code, registry, current state, F2.6-exitbewijs en de
  lokale option bevestigen 1003.
- Het source register verwijst naar historische note-foundations die niet in
  deze checkout aanwezig zijn. Het geeft die bronnen bovendien slechts
  `ACTUEEL MET LATERE CORRECTIES`; zij kunnen de expliciete canonieke hoofdstukken
  niet aanvullen met niet-verifieerbare details.
- Er is geen conflict aangetroffen over de wel canonieke Note-regels: owner,
  privacy, Work, multipliciteit en optional ReadingRound.

### Gecontroleerde functionele baseline

Bevestigd zijn: user-owned, altijd privé, uitsluitend owner-access,
Work-level, meerdere Notes per Work, beperkt veilig HTML, een optionele en
corrigeerbare ReadingRound-link, alle eigen same-Work ReadingRound-statussen,
meerdere Notes per ReadingRound en conditionele hard delete. Afbeeldingen,
attachments, complexe blokken, autosave en UI zijn buiten F2.7-scope geplaatst.

## 4. Gap-analyse

| Onderdeel | Status nu | F2.7b-wijziging |
| --- | --- | --- |
| Private Note aggregate/value objects | Ontbreekt | `PrivateNote`, ID, content en version toevoegen. |
| Ownership/application authorization | Patroon bestaat | Authenticated actor en owner-scoped Note-ports toepassen. |
| Work-validatie | Repository bestaat | Work-only create controleert bestaande Work. |
| ReadingRound-validatie | Owner-read bestaat | Round-create deriveert Work; correctie controleert owner + same Work. |
| Note mutations | Ontbreekt | Gescheiden create/content/context/delete-services. |
| Note reads | Ontbreekt | Single, by Work, by ReadingRound en personal overview. |
| Optimistic concurrency | Patroon bestaat | Note version + locked read + CAS + typed stale result. |
| Server-side ID | Strategie bestaat | Note-specifieke port/adapter/retry; geen client-ID. |
| Contentveiligheid | Alleen incidentele output escaping bestaat | Expliciete fixed-format policy en rendergrens toevoegen. |
| Persistence | Ontbreekt | Nieuwe `wp_biblio_private_notes`-tabel. |
| Migration/schema-health | Infrastructuur bestaat t/m 1003 | Gerichte `CoreSchema1004Migration` en 1004-health. |
| ActivityEvent | Library-infrastructuur bestaat | Niet injecteren in Note-services; nul-eventtests. |
| Tests/fixtures/reset | Harnas bestaat | Note-unit/integratie/concurrency/migrationtests en cleanup uitbreiden. |
| Production boundary | Patroon bestaat | Alleen benoemde services/projectors exposen. |
| REST/UI | Ontbreekt en buiten scope | Niet toevoegen. |

## 5. Definitief Core-contract

De onderstaande vorm is implementatieklaar. De definitieve productbeslissingen
O1–O3 zijn in §11 vastgelegd. Technische namen volgen de bestaande
Engelstalige Core-conventie; het UI-label blijft “Mijn notities”.

### 5.1 Aggregate en waarden

`PrivateNote` bevat minimaal:

| Veld | Type | Null | Mutability | Herkomst |
| --- | --- | --- | --- | --- |
| ID | `PrivateNoteId` | nee | immutable | server-side generator |
| owner | `UserId` | nee | immutable | authenticated actor |
| Work | `WorkId` | nee | immutable | expliciete bestaande Work of afgeleid uit Round |
| ReadingRound-context | `?ReadingRoundId` | ja | corrigeerbaar/verwijderbaar | owner-scoped same-Work Round |
| inhoud | `PrivateNoteContent` | nee | wijzigbaar | fixed safe-format policy |
| created at | UTC `DateTimeImmutable` | nee | immutable | server-side clock |
| updated at | UTC `DateTimeImmutable` | nee | echte mutation | server-side clock |
| version | `PrivateNoteVersion` | nee | +1 per echte mutation | start op 1 |

Niet opnemen zonder latere use-case:

- `library_id`, visibility/publication/status of Library-auditvelden;
- titel, tags, afbeeldingen, attachment/blob-ID of block-JSON;
- provenance, omdat er maar één creationpad en geen importcontract is;
- `deleted_at` of soft-delete/tombstone-lifecycle;
- apart rendered HTML, excerpt, searchdocument of first/last ReadingRound-state.

`PrivateNoteId` hergebruikt het persistable-ID-contract: niet leeg, geldig
UTF-8, maximaal 191 tekens. `PrivateNoteVersion` is een positieve integer.
Technische timestamps moeten in MariaDB `DATETIME(6)` UTC-bereik vallen en
`updated_at >= created_at` houden.

### 5.2 Invarianten

1. Iedere Note heeft exact één owner en exact één bestaande Work.
2. Een Note kan nul of één ReadingRound-context hebben.
3. Een gekoppelde ReadingRound is van dezelfde owner en hetzelfde Work.
4. Work wijzigt nooit op een bestaande Note.
5. Er is geen maximum van één Note per Work of per ReadingRound.
6. Inhoud is na normalisatie geldig UTF-8, inhoudelijk niet leeg en past in
   het vaste opslagcontract.
7. ID, owner, Work en `created_at` zijn immutable.
8. Alleen inhoud en ReadingRound-context zijn corrigeerbaar.
9. Een expliciete Note-mutation verhoogt version exact eenmaal en zet
   `updated_at`; de in O3 vastgestelde referentiële Round-delete-unlink is de
   enige uitzondering en is geen user edit.
10. Een Note-create/update/delete schrijft geen Library ActivityEvent,
    Timeline-event of generiek private audit-event.
11. Verwijderen raakt Work, ReadingRound en alle andere leesdata niet.

### 5.3 Creation zonder dubbele Work-waarheid

Eén `CreatePrivateNoteService` krijgt twee expliciete publieke paden:

- `createForWork(WorkId, string content)` valideert de bestaande Work en maakt
  een Note zonder ReadingRound;
- `createForReadingRound(ReadingRoundId, string content)` leest de Round
  owner-scoped en deriveert Work uit die Round.

Geen create-signature accepteert tegelijk een caller-supplied WorkId en
ReadingRoundId. Geen signature accepteert `UserId` of `PrivateNoteId`. Dit
volgt hetzelfde invalid-combination-resistant patroon als F2.6-startflows.

### 5.4 Mutations

`UpdatePrivateNoteContentService` accepteert Note-ID, expected version en de
volledige gewenste broninhoud. Na normalisatie:

- identiek aan current: actuele Note terug, ook bij stale expected version,
  zonder write/version/timestampwijziging;
- afwijkend en current version: één CAS-write, version +1;
- afwijkend en stale: `PrivateNoteStale` met veilig actuele Note-state.

`CorrectPrivateNoteReadingRoundService` is een aparte intentie en accepteert
Note-ID, expected version en een gewenste `?ReadingRoundId`:

- concrete Round: owner-scoped lookup, Work moet gelijk zijn;
- null: verwijdert alleen de contextlink;
- identieke link: semantic/stale no-op;
- geldige andere link: één CAS-write en version +1;
- vreemde, onbekende of cross-Work Round: één niet-onthullende stable failure;
- inhoud en Work wijzigen nooit impliciet mee.

`DeletePrivateNoteService` accepteert Note-ID en expected version. Het contract
voor conditionele hard delete is:

- locked owner-scoped read in één transactie;
- stale expected version geeft `PrivateNoteStale`;
- conditionele delete op ID + owner + version;
- onbekend, reeds verwijderd en niet-owned delen `private_note_not_available`;
- alleen de Note-row verdwijnt; geen cascade naar Work of ReadingRound;
- geen event/tombstone.

### 5.5 Reads en projecties

Benodigde benoemde owner-scoped reads:

- `GetPrivateNoteService::get(PrivateNoteId): ?PrivateNote`;
- `ListPrivateNotesForWorkService::list(WorkId, page): PrivateNotePage`;
- `ListPrivateNotesForReadingRoundService::list(ReadingRoundId, page): PrivateNotePage`;
- `ListMyPrivateNotesService::list(page): PrivateNotePage` voor de canonieke
  module `Mijn notities`.

Alle queries bevatten de authenticated `user_id` in het repositorypredicate.
List-by-ReadingRound valideert de Round owner-scoped of levert dezelfde lege/
onbeschikbare uitkomst als onbekend; zij onthult geen vreemd Round-bestaan.

**Technische aanbeveling:** één stabiele sortering
`updated_at DESC, private_note_id DESC` en cursorpagination met default 50 en
hard maximum 100. Het aantal Notes per gebruiker is onbegrensd, dus een
platformbreed overzicht zonder pagination is geen veilige basis. Er komt geen
full-text-search, opgeslagen excerpt of aparte sorteerwaarheid. Een adapter mag
uit de veilige inhoud een tijdelijke preview afleiden.

### 5.6 Ownership en foutcontract

Iedere service roept eerst `AuthenticatedUser::requireUserId()` aan. Note-
mutaties starten daarna hun transactie en gebruiken een locked owner-scoped
read. Library Context, membership, management role, supporttoegang en platform-
permission zijn geen input en spelen geen rol.

Nieuwe stable failure reasons zijn minimaal:

- `private_note_not_available` voor onbekend/niet-owned;
- `private_note_stale` met veilige current state;
- `private_note_reading_round_unavailable` voor onbekend, niet-owned of
  Work-mismatched context;
- bestaande `validation_failed`, persistence- en transactionredenen;
- `private_note_id_collision_exhausted` na begrensde retries.

### 5.7 ActivityEvent en persoonlijke historie

Note-repositories en -services krijgen geen `ActivityEventAppender` of
`ActivityEventFactory`. Een Note is geen Library-primary of related entity.
F2.7b introduceert evenmin een private eventstore. Omdat Notes canoniek niet
in Tijdlijn verschijnen, worden `created_at` en `updated_at` niet als nieuwe
Timeline-events geprojecteerd.

## 6. Tekst en eenvoudige opmaak

### Definitief contentformat

O1 stelt voor v2.001 één canoniek, server-side gesanitiseerd HTML-subset als
broninhoud vast, met uitsluitend:

- paragrafen en regeleinden: `p`, `br`;
- nadruk: `strong`, `em`;
- lijsten: `ul`, `ol`, `li`;
- citaat: `blockquote`;
- geen attributen op deze elementen.

Niet toegestaan zijn onder meer links, styles, classes, afbeeldingen, embeds,
scripts, willekeurige HTML en complexe contentblokken. Daaronder vallen ook
`style`, `iframe`, `object`, `embed`, `img`, `svg`, `video`, `audio`, `form`,
URL-attributen, eventhandlers, CSS, comments en block-markers. Geen enkel
toegestaan element accepteert attributen. Plain text is als contentformat
afgewezen.

### Opslag- en rendergrens

- Sla alleen de genormaliseerde veilige broninhoud op; geen tweede rendered
  HTML-cache of JSON-blockwaarheid.
- Normaliseer CRLF naar LF, eis geldig UTF-8, verwerp NUL en inhoud zonder
  zichtbare tekst.
- Gebruik één expliciete `PrivateNoteContentPolicy` op iedere create/update;
  verboden markup veroorzaakt validation failure en wordt niet stilletjes
  weggeknipt.
- De WordPress-adapter mag `wp_kses`/HTML-tokenization gebruiken, maar de
  allowlist is een Biblio-contract en geen globale editorconfiguratie.
- Render uitsluitend via een Note-renderer die tekstnodes escaped en opnieuw
  dezelfde allowlist toepast als defense in depth; adapters mogen nooit raw
  databasecontent rechtstreeks echoën. Sanitization en output escaping volgen
  de bestaande WordPress/Biblio-securityconventies.
- Gebruik `TEXT` met een domeingrens van maximaal 65.535 UTF-8-bytes na
  normalisatie. Dit sluit exact op MariaDB `TEXT` aan zonder een willekeurige
  veel grotere blobcapaciteit te introduceren.

## 7. Persistence- en migrationplan

### 7.1 Keuze

Gebruik één Biblio-owned Core-tabel:

`wp_biblio_private_notes`

Dit is geen universele custom-tablekeuze. Voor Notes geven de combinatie van
owner-isolatie, echte Work/Round-FK's, CAS, conditionele delete, drie bounded
queries, migration-health en onafhankelijk testen concrete nettowinst boven
CPT/meta of JetEngine/CCT. Directe JetEngine-mutaties zouden bovendien de
owner- en same-Work-grens kunnen omzeilen.

### 7.2 Definitieve kolommen

| Kolom | MariaDB | Null | Regel |
| --- | --- | --- | --- |
| `private_note_id` | `VARCHAR(191)` binair | nee | primary key |
| `user_id` | `VARCHAR(191)` binair | nee | owner; geen FK naar `wp_users` volgens huidige Core-conventie |
| `work_id` | `VARCHAR(191)` binair | nee | FK Work, `RESTRICT` |
| `reading_round_id` | `VARCHAR(191)` binair | ja | FK ReadingRound, `ON DELETE SET NULL` |
| `note_content` | `TEXT` | nee | genormaliseerde veilige broninhoud |
| `created_at` | `DATETIME(6)` | nee | UTC technical instant |
| `updated_at` | `DATETIME(6)` | nee | UTC technical instant |
| `note_version` | `BIGINT UNSIGNED` | nee | start 1 |

Geen `library_id`, `content_format`, `visibility`, `deleted_at`, `provenance`,
rendercache, excerpt of auditkolommen.

### 7.3 Constraints en indexes

- primary key `private_note_id`;
- FK `work_id → works.work_id ON DELETE RESTRICT`;
- FK `reading_round_id → reading_rounds.reading_round_id`;
- index `(user_id, work_id, updated_at, private_note_id)`;
- index `(user_id, reading_round_id, updated_at, private_note_id)`;
- index `(user_id, updated_at, private_note_id)`;
- CHECK `note_version >= 1`;
- CHECK `updated_at >= created_at`;
- geen uniqueness op User + Work of User + ReadingRound;
- geen FULLTEXT-index.

De eenvoudige ReadingRound-FK bewijst bestaan maar niet same-owner/same-Work.
Een samengestelde FK kan dat alleen met extra unique sleutel op ReadingRound en
maakt `ON DELETE SET NULL` onmogelijk zonder ook non-null owner/Work te willen
nullen. Daarom is de minimale aanbeveling: eenvoudige echte FK, owner/same-
Work in de applicationlaag en een hercontrole in de Note-repository vóór
insert/CAS. Gerichte integratietests moeten deze persistence defense in depth
bewijzen.

### 7.4 ReadingRound-delete-interactie

De optionele FK gebruikt volgens O3 `ON DELETE SET NULL`:

- het verwijderen van toegestane `historical_manual` ReadingRound-history
  verwijdert nooit de Note;
- de Note blijft aan dezelfde immutable Work gekoppeld;
- alleen de inmiddels niet-bestaande contextreferentie vervalt;
- deze referentiële invalidatie verandert `note_version` en `updated_at` niet;
  iedere latere expliciete mutation leest eerst de actuele null-link en kan
  daardoor geen oude link terugschrijven;
- Note-delete zelf raakt de Round nooit.

Dit is een expliciet lifecyclecontract, geen toevallige cascade. `CASCADE` van
ReadingRound naar Note is verboden. `RESTRICT` zou het legitieme F2.6-
deletecontract onverwacht blokkeren en is daarom eveneens verboden.

### 7.5 Migration 1003→1004

F2.7b:

1. voegt `privateNotes()` aan `CoreTableNames` en alle current-schema-sets toe;
2. verhoogt `CURRENT_VERSION` naar 1004;
3. registreert `CoreSchema1004Migration` na 1003;
4. eist als normale precondition een gezonde 1003-state;
5. maakt uitsluitend de Note-tabel; bestaande tabellen/data blijven intact;
6. accepteert bij retry alleen “tabel afwezig” of een volledig herkenbare,
   gezonde 1004 Note-tabel;
7. faalt gesloten bij een gedeeltelijke/onbekende tabeldefinitie;
8. controleert de 1004-postcondition vóór de version bump;
9. voegt volledige 1004 column/index/FK/CHECK-health toe;
10. laat fresh install baseline 1000 plus alle ordered migrations doorlopen;
11. breidt integratietest-cleanup uit zodat Note-rows vóór Round/Work worden
    verwijderd.

Er is geen Note-databackfill: schema 1003 bevat geen Note-persistence.

## 8. Concurrency- en ID-contract

### Optimistic concurrency

Optimistic locking is noodzakelijk: handmatig opslaan sluit gelijktijdig open
tabs/sessies niet uit en zonder version kan de laatste save de eerste stil
overschrijven.

Voor content- en contextcorrectie geldt locked current-state read, semantische
vergelijking, expected-versioncontrole en conditionele CAS-write binnen één
transactie. Twee afwijkende updates vanaf dezelfde version leveren precies één
winnaar en één `PrivateNoteStale`. Twee gelijke updates convergeren naar één
write en één stale no-op. Delete-versus-update levert één winnaar; de verliezer
krijgt stale of not-available, nooit een gedeeltelijke toestand.

### Server-side ID

Introduceer `PrivateNoteIdGenerator` en `OpaquePrivateNoteIdGenerator` in
plaats van de ReadingRound-interface semantisch te misbruiken. De adapter mag
hetzelfde formaatpatroon gebruiken, bijvoorbeeld
`private-note-` plus 128 random bits.

Create accepteert geen ID. Alleen een door de Note-repository expliciet als
primary-keycollision vertaalde insertfout krijgt maximaal drie retries na de
eerste issuancepoging. Andere FK-, validatie-, persistence- of
transactionfouten worden niet herhaald. Uitputting levert
`private_note_id_collision_exhausted`.

## 9. Threat- en invariantmatrix

| Dreiging | Bescherming | Primaire laag | Defense in depth/test |
| --- | --- | --- | --- |
| Andere gebruiker leest direct Note-ID | Actor intern; query ID + owner; vreemd = null | Application/repository | Integratietest met bestaande vreemde ID |
| Andere gebruiker wijzigt Note-ID | Locked owner-read; niet-onthullend unavailable | Application/repository | Geen row/version/timestampwijziging |
| Andere gebruiker verwijdert Note-ID | Conditional owner + version delete | Application/repository | Note blijft voor eigenaar bestaan |
| Library Manager probeert toegang | Geen Library-role/contextdependency | Composition/application | Manager met toegang tot gerelateerde Library blijft geweigerd |
| Foreign ReadingRound koppelen | Owner-scoped Round-read vóór mutation | Application | Onbekend en foreign dezelfde reason |
| Same-owner Round van ander Work | Vergelijk immutable Work; repository hercontroleert | Application/persistence | Geen link of versionwijziging |
| Gemanipuleerde WorkId bij Round-create | Work uit owned Round afleiden | Application API-vorm | Reflection/signaturetest |
| Onbestaande Work bij Work-create | WorkRepository lookup en echte FK | Application/database | Geen Note-row |
| Stale content/contextwrite | Expected version + CAS | Application/repository/database | Current veilige state in conflict |
| Update versus delete | Locked decision + conditional write/delete | Transaction/repository | Onafhankelijke-processentest |
| Script/HTML-injectie | Fixed parser/allowlist; reject; render opnieuw veilig | Content policy/rendering | Stored-XSS payloadmatrix |
| ID-enumeration | Opaque random ID plus owner predicate | Generator/repository | Voorspelbaarheid verleent nooit toegang |
| Direct repository owner mismatch | `addForUser`/replacement owner assertion | Repository | Privileged contracttest |
| Data via Library audit | Geen auditdependency/eventwrite | Composition | Eventcount blijft nul |
| Note-delete wist leesdata | Geen cascade naar Work/Round | Database | Rijcounts en reload vóór/na delete |

Outputveiligheid is nooit uitsluitend een domain- of databaseprobleem. De
Core-policy bepaalt toegestane inhoud; de renderadapter bepaalt dat opgeslagen
tekst niet als ongecontroleerde executable markup wordt uitgevoerd.

## 10. Testmatrix F2.7b

### Domain/unit

1. ID: leeg, invalid UTF-8 en >191 geweigerd; geldige grens toegestaan.
2. Version: start 1, positief, exact één stap per replacement.
3. Content: geldige subset, Unicode, regeleinden en bytegrens roundtrippen.
4. Lege/alleen-markup-, invalid-UTF-8-, NUL- en te lange inhoud faalt.
5. Alle verboden tags/attributen/protocollen falen zonder silent stripping.
6. Aggregate kan geen owner/Work/created-at-wijziging uitvoeren.
7. Content no-op en echte replacement hebben correcte version/timestamps.
8. Contextreplacement wijzigt alleen Round-link/version/updated-at.
9. Opaque generator levert geldige, server-side Note-ID's.

### Application/authorization

10. `createForWork` maakt eigen unlinked Note voor bestaande Work.
11. Onbestaande Work faalt zonder write.
12. Meerdere Notes voor dezelfde User + Work zijn toegestaan.
13. `createForReadingRound` gebruikt eigen same-Work Round en deriveert Work.
14. Meerdere Notes voor dezelfde Round zijn toegestaan.
15. Foreign/unknown Round-create is niet-onthullend geweigerd.
16. Contentupdate: owner success, semantic no-op en stale divergent.
17. Context attach/change/remove: owner, same Work, no-op en stale matrix.
18. Active, ended en historical Round-context slagen.
19. Same-owner cross-Work Round-correctie wordt geweigerd zonder mutation.
20. Andere gebruiker kan individuele Note niet lezen.
21. Andere gebruiker kan Note niet wijzigen, context corrigeren of verwijderen.
22. Library Eigenaar/Manager/Lid en support/platformrechten geven geen toegang.
23. Hard delete vereist actuele version en verwijdert uitsluitend de Note.
24. Delete raakt Work, ReadingRound en ReadingRound-derived truth niet.
25. Geen Note-mutation schrijft Library ActivityEvent of Timeline/private event.
26. Production signatures accepteren geen UserId, LibraryContext of Note-ID bij create.
27. `CoreApplication` exposeert alleen benoemde Note-services, geen repository,
    generator, sanitizer of generic writer.

### Persistence/queries/concurrency

28. Volledige Note roundtrip inclusief Unicode, content, nullable Round,
    microseconds en version.
29. FK weigert onbekende Work en onbekende ReadingRound.
30. Repository defense weigert owner- of Work-inconsistente Round-relatie.
31. List-by-Work bevat alleen eigen Notes en stabiele sortering/pagination.
32. List-by-Round bevat alleen eigen Notes en lekt geen foreign Round.
33. `Mijn notities` bevat alleen eigen Notes over meerdere Works/Rounds.
34. Geen unique beperking op User + Work of User + Round.
35. Successful primary collision krijgt nieuwe ID; bron/FK-fout wordt niet geretryd.
36. Drie collision-retries worden gevolgd door gecontroleerde uitputting.
37. Twee afwijkende parallelle contentupdates: één winner, één stale.
38. Twee gelijke parallelle updates: één write, één no-op current result.
39. Update versus delete: één consistente winner, geen partial state.
40. Deleting linked historical Round zet alleen Note-context null en
    behoudt Note, Work, content, version en timestamps.

### Migration/security/regression

41. Fresh empty schema bereikt gezonde 1004 via de volledige keten.
42. Gezonde echte 1003-state upgrade naar 1004 behoudt alle bestaande data.
43. Bekende create-table-before-version-bump retry convergeert idempotent.
44. Onbekende partial Note-table faalt gesloten en version blijft 1003.
45. Health detecteert ontbrekende/afwijkende table, column, index, FK en CHECK.
46. Huidige 1000/1001/1002/1003 migrationcontracten blijven groen.
47. Stored-XSS-payloads kunnen de rendergrens niet als script, eventhandler,
    actieve URL of embedded content passeren.
48. Geen global Note full-text-index/search wordt geïntroduceerd.
49. Bestaande ReadingRound owner/source/CAS/delete/derived-readtests blijven groen.
50. Volledige canonieke gate, PHPStan level 6 en WordPress smoke slagen.

## 11. Definitieve productbeslissingen

**O1 — Contentformat**

Private Notes gebruiken in v2.001 beperkt veilig HTML met exact de allowlist
uit §6. Server-side sanitization en veilig renderen/output escaping zijn
verplicht. Links, attributen, media, embeds, scripts, willekeurige HTML en
complexe contentblokken zijn niet toegestaan. Plain text is afgewezen.

**O2 — ReadingRound-contextlifecycle**

- Een Note mag zonder Round bestaan of aan precies één Round gekoppeld zijn.
- Meerdere Notes per dezelfde ReadingRound zijn toegestaan.
- De link mag na creatie worden attached, gewijzigd en verwijderd.
- Iedere eigen same-Work ReadingRound is geldig, ongeacht active, ended of
  historical status.
- Cross-user en cross-Work koppelingen zijn verboden.
- ReadingRound is context, geen eigenaar; Work blijft immutable en wordt nooit
  uit een nieuwe link overgenomen.

**O3 — Delete en Round-delete-interactie**

- Note-delete is een owner-scoped conditionele hard delete met expected-version-
  controle volgens §5.4.
- Er is geen tombstone, Note-audit of soft-delete lifecycle.
- Note-delete raakt Work, ReadingRound en andere leesdata niet.
- Legitieme hard delete van een gekoppelde ReadingRound volgens F2.6 behoudt de
  Note en zet uitsluitend `reading_round_id` op null via `ON DELETE SET NULL`.

### Niet-blockerende technische keuzes

- canonieke Core-naam `PrivateNote`/`PrivateNoteId`;
- Note-specifieke generator en klok in plaats van generieke refactor;
- `TEXT` en maximaal 65.535 bytes na normalisatie;
- stable updated-desc cursorpagination, default 50/max 100;
- geen apart content-formatveld zolang precies één format bestaat;
- geen stored excerpt/rendercache/full-textindex;
- simpele ReadingRound-FK plus application/repository same-owner/same-Work
  defense in depth.

Deze keuzes kunnen in review technisch worden aangepast zonder een nieuw
functioneel model te openen, zolang de vastgestelde privacy- en datagrenzen
gelijk blijven.

### Deferred

- UI/Elementor, editorselectie, deletecopy en confirmationcomponent;
- autosave/offline editing/drafts/revisions;
- afbeeldingen, attachments, block editor en mediarelaties;
- delen/publiceren, reviews/ratings en moderation;
- Timeline, statistieken, doelen en Home;
- REST/Abilities-adapter;
- import/export, account erasure en private audit/history;
- globale full-text search.

## 12. Aanbevolen F2.7b-implementatievolgorde

1. Gebruik de in §11 vastgelegde O1–O3-contracten als functionele baseline.
2. Voeg domainwaarden, aggregate, failures, contentpolicycontract, generator en
   klok toe met unit tests.
3. Voeg `CoreSchema1004Migration`, table names, registry/version en health toe;
   bewijs fresh/upgrade/retry/drift op echte MariaDB.
4. Voeg owner-scoped read/write-repository met source-defense, CAS, delete en
   paged queries toe; bewijs roundtrip, FKs en isolation.
5. Implementeer createForWork/createForReadingRound en ID-collisionpolicy.
6. Implementeer aparte contentupdate, contextcorrectie en delete-use-cases met
   transacties/no-op/stale/concurrencytests.
7. Voeg contentpolicy- en renderboundary-adapter toe en bewijs de XSS-matrix.
8. Composeer alleen benoemde services/projectors in `ProductionComposition`
   en `CoreApplication`; injecteer geen ActivityEvent-infrastructuur.
9. Draai gerichte Notes-tests, alle ReadingRound-regressies en daarna de
   volledige canonieke quality gate.
10. Werk current state, architecture, acceptance, manifest en F2.7-exitbewijs
    alleen bij voor aantoonbaar geïmplementeerd gedrag.

De stappen zijn afzonderlijk committable als: contract/domain; schema1004;
persistence; application/security; composition/concurrency; exitdocs.

## 13. F2.7a-validatie

De volledige bestaande gate is na de documentatie-analyse uitgevoerd op
2026-08-22:

- `./scripts/test-biblio-core-all.sh`: PASS in 51 seconden;
- Composer metadata en locked platform requirements: PASS;
- PHP-syntax: PASS;
- PHPStan level 6 over production `src`: PASS, geen fouten;
- unit: 183 tests, 600 assertions;
- isolated real-MariaDB integration: 124 tests, 861 assertions;
- totaal: 307 tests, 1.461 assertions;
- WordPress smoke: plugin active, Core loaded, init hook eenmaal, HTTP 200;
- manifest JSON en staged/unstaged whitespace: PASS;
- de gate veranderde de zichtbare werkboomstatus niet.

F2.7a wijzigt uitsluitend dit analysedocument en de registratie ervan in
`manifest.json`. Productiecode, tests, schema en migrations zijn ongewijzigd.

## 14. Exit verdict

**GO**

De repository biedt een sterke, bewezen technische basis en er is geen reden
voor UI-, JetEngine- of nieuwe generieke infrastructuur. Het private owner-
model, Work-hoofdcontext, optionele ReadingRound-relatie, CAS, server-side ID,
custom-table/migration en ActivityEvent-scheiding zijn concreet uitwerkbaar.

O1–O3 zijn definitief vastgesteld en in het Core-contract, persistenceplan en
de testmatrix verwerkt. Er resteert voor deze scope geen open productbeslissing.
F2.7b kan daarom zonder verdere productbeslissing starten.
