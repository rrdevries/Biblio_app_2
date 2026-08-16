# ADR-004 — Fase 0 persistencebaseline en concrete ReadingRound-bronnen

Status: Accepted

Scope: Biblio V2 v2.001

## Context

Fase 0 heeft met Biblio Core, een echte WordPress-bootstrap en MariaDB 10.11 onderzocht of harde scope-, ownership-, transactie- en concurrencyregels zonder Elementor kunnen worden gedragen.

De spike omvatte Library, LibraryMembership, de persistente aanwijzing van een persoonlijke Privébibliotheek, Work, Edition, Item, ExternalLoan en ReadingRound.

## Decision

### Biblio Core-persistencebaseline

Biblio-owned custom database tables zijn de bewezen technische baseline voor Core-data waarvoor harde relationele integriteit, tenantisolatie, user ownership, transacties of concurrencyregels centraal staan.

Dit is in Fase 0 aangetoond voor:
- Library;
- LibraryMembership;
- personal-Library designation;
- Work;
- Edition;
- Item;
- ExternalLoan;
- ReadingRound.

Dit betekent niet dat ieder Biblio-object automatisch een custom table krijgt. Persistence blijft per domeingebied beoordeeld op integriteit, querygedrag, lifecycle en onderhoudbaarheid. CPT, CCT en JetEngine kunnen ondersteunend worden gebruikt wanneer zij aantoonbare nettowinst bieden en de Core-boundary niet omzeilen.

Biblio Core blijft eigenaar van business rules, authorization en mutations die Core-invarianten raken.

### Work en Edition

Platformbrede Work- en Edition-records functioneren technisch correct in Biblio-owned custom tables. Zij blijven voor v2.001 voorlopig in deze persistence; er is geen technische noodzaak om ze nu naar CPT of CCT te verplaatsen.

Toekomstige inzet van JetEngine/CCT voor deze records vereist aantoonbare nettowinst en mag Core-authorization, integriteitsregels of application services niet omzeilen. Dit is geen eeuwigdurend verbod op andere persistencevormen.

### Explicit Library Context

Iedere Library-scoped application-operatie vereist expliciete Library Context en server-side controle van:
- de authenticated user;
- de Library;
- actief membership;
- relevante beheerrol, gebruikstoegang en/of aanvullende permission;
- ownership en Library-scope van het betrokken record.

Tenantcontext wordt niet impliciet afgeleid uit UI, cookie, sessie of page builder.

### User-owned data

ExternalLoan en ReadingRound zijn user-owned. Libraryrollen leveren geen automatische toegang tot deze persoonlijke data. Een gebruiker kan user-owned data hebben terwijl die gebruiker nul Libraries heeft.

### Persoonlijke Privébibliotheek-provisioning

De aangewezen persoonlijke Privébibliotheek wordt vastgelegd met een expliciete persistente user→Library-designation. Zij wordt niet heuristisch afgeleid uit Library-ownership.

De provisioningprimitive is transactioneel, idempotent en concurrency-safe. Automatische provisioning wordt alleen aangeroepen vanuit een relevante application-use-case, niet bij login of accountregistratie.

### ReadingRound en concrete bron

ReadingRound blijft user-owned. Een actieve ReadingRound heeft exact één concrete fysieke bron.

De actieve uniquenessregel is:

`User + concrete source`

en niet:

`User + Work`

Dezelfde gebruiker mag daardoor hetzelfde Work tegelijkertijd via verschillende concrete bronnen lezen. Bij de start wordt Work afgeleid uit de gevalideerde bron; een los door de client aangeleverd Work is geen tweede waarheid.

### Source persistence voor v2.001

Fase 0 onderzocht:
1. `source_type + source_id`;
2. een gemeenschappelijke `PhysicalSource`-identiteit;
3. expliciete nullable foreign keys per brontype.

Voor de huidige v2.001-baseline is optie 3 gekozen:
- `item_id` is nullable;
- `external_loan_id` is nullable;
- exact één van beide is gevuld;
- beide verwijzingen gebruiken echte foreign keys;
- een database-XOR-check dwingt precies één bron af;
- generated columns en unique indexes beschermen concurrency-safe maximaal één actieve ReadingRound per User + concrete source.

Deze vorm biedt sterkere database-integriteit dan een typed reference en vraagt minder extra abstractie en synchronisatie dan een gemeenschappelijke PhysicalSource. Het nadeel is schema-uitbreiding wanneer een nieuw brontype wordt toegevoegd.

De keuze past bij de kleine huidige set geïmplementeerde fysieke brontypen en hoeft niet onbeperkt naar toekomstige versies te worden doorgetrokken.

### InternalLoan

InternalLoan is nog niet geïmplementeerd als ReadingRound-bron. Bij toevoeging van dit derde brontype wordt opnieuw beoordeeld of een derde expliciete FK nog de beste oplossing is of dat migratie naar een gemeenschappelijke source-identiteit inmiddels meer nettowinst biedt.

Deze ADR legt dus geen premature definitieve sourcevorm voor InternalLoan vast.

### Historie

Concrete bronnen gebruiken geen cascade-delete waardoor persoonlijke ReadingRounds verdwijnen wanneer een bron later eindigt of verandert. De precieze source-lifecycle valt buiten Fase 0, maar de persistence houdt behoud van historie technisch mogelijk.

### Defense in depth

Kritieke regels worden waar passend beschermd in domain, application services, transactions en databaseconstraints.

Bewezen voorbeelden zijn:
- uniek membership per Library + User;
- maximaal één actieve Owner per Library;
- één personal-Library designation per User;
- foreign-key-integriteit tussen Core-records;
- exact één concrete bron per actieve ReadingRound;
- maximaal één actieve ReadingRound per User + concrete source.

`Minimaal één actuele Owner` kan niet volledig als losse databaseconstraint worden afgedwongen. Die ondergrens vereist een gecontroleerde ownership-lifecycle en application boundary.

### Migrations

Biblio Core beheert schema-versioning en migrations voor zijn Core-tabellen. MariaDB-DDL is niet volledig transactioneel.

Daarom blijven migrations:
- versioneerbaar;
- reproduceerbaar;
- rerunnable waar mogelijk;
- expliciet getest tegen echte MariaDB;
- pas op de nieuwe schemaversie gezet nadat alle stappen succesvol zijn uitgevoerd.

### Integratietestbaseline

Het Fase-0-integratietestharnas is de structurele basis voor Core-integratietests:
- echte WordPress-bootstrap;
- echte MariaDB;
- geïsoleerde testdatabase;
- automatische setup en cleanup;
- geen integratiewrites naar de developmentdatabase;
- echte onafhankelijke processen/connecties voor concurrencytests waar een invariant dat vereist.

### Elementor en JetEngine

Elementor blijft presentatie en app-shell. JetEngine/Crocoblock blijft ondersteunend waar passend voor gestructureerde data, queries, filters, listings en eenvoudige CRUD-oppervlakken.

Business rules en Core-mutations blijven in Biblio Core. Directe plugin-writes mogen Core-invarianten niet omzeilen.

## Consequences

- relationele en concurrencygevoelige Core-data heeft een bewezen default, zonder alle toekomstige persistencekeuzes vooraf vast te zetten;
- Work en Edition hoeven voor v2.001 niet opnieuw gemapt te worden;
- Library- en user-boundaries blijven expliciet in application services en repositories;
- een volgend concreet brontype vereist een bewuste source-schemaherbeoordeling;
- productiegeschikte install/upgrade-lifecycle en verdere hardening blijven werk voor de volgende fase.

## Not selected for the current baseline

- **Alle Core-data als posts/meta:** biedt voor de bewezen relationele, transactionele en concurrencyregels onvoldoende directe database-integriteit als algemene baseline.
- **Alles als JetEngine CCT:** maakt een externe plugin te breed eigenaar van Core-persistence en mutations zonder bewezen nettowinst voor alle domeinen.
- **Typed source reference voor ReadingRound:** is uitbreidbaar, maar kan geen echte heterogene FK vanaf één `source_id` afdwingen.
- **Gemeenschappelijke PhysicalSource voor ReadingRound:** biedt één FK, maar introduceert voor de huidige twee brontypen een extra persistentielaag en synchronisatie-invariant.

Deze richtingen zijn voor de huidige baseline niet gekozen; zij zijn niet voor altijd uitgesloten.
