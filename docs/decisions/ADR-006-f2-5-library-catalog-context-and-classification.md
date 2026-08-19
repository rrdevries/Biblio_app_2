# ADR-006 — F2.5 LibraryCatalogContext en lokale classificatie

Status: Accepted  
Scope: Biblio V2 v2.001  
Fase: F2.5  
Baseline: d265b0489d35c809b956385cbf54c9006fc061e2

## 1. Context

Biblio gebruikt centrale, platformbrede bibliografische identiteit voor Work
en Edition. Een Item/Exemplaar behoort daarentegen aan exact één Library.

LibraryCatalogContext vormt de Library-lokale laag tussen centrale
bibliografische identiteit en het lokale catalogusgebruik.

De functionele baseline kent drie afzonderlijke lokale classificaties:

- Boeksoort;
- Genre;
- Onderwerp.

Deze classificaties mogen centrale Work- of Edition-data niet dupliceren of
Library-lokaal maken.

F2.5 concretiseert het domein-, lifecycle-, authorization-, audit- en
concurrencymodel voor deze classificatielaag.

## 2. Besluit: ankerniveau

LibraryCatalogContext is uniek voor:

    Library + Work

Niet voor Edition en niet voor Item.

Alle Editions en Items van hetzelfde Work binnen dezelfde Library delen
dezelfde LibraryCatalogContext.

Dezelfde centrale Work mag in verschillende Libraries verschillende lokale
classificaties hebben.

Work en Edition blijven platformbreed en worden door deze context niet
gemuteerd.

## 3. Cardinaliteit

Een bestaande LibraryCatalogContext bevat:

- exact 1 Boeksoort;
- 0..n Genres;
- 0..n Onderwerpen.

Genre en Onderwerp zijn sets:

- dezelfde term-ID mag niet tweemaal aan dezelfde context gekoppeld zijn;
- er is geen primary Genre;
- er is geen primary Onderwerp;
- de volgorde heeft geen domeinbetekenis.

Genre en Onderwerp krijgen geen hard technisch maximum.

UX mag gebruikers wel sturen naar een beperkt aantal relevante waarden.
Voor Genre is circa 1–3 normaal; bij uitzonderlijk veel waarden mag een
zachte waarschuwing worden getoond. Onderwerp mag ruimer worden gebruikt.

Waarschuwingen blokkeren opslaan niet.

## 4. Boeksoort

Boeksoort is de brede praktische classificatie van wat voor soort boek een
Work binnen een Library is.

Standaardset voor een nieuwe Library:

- Leesboek
- Kookboek
- Studieboek
- Kennisboek
- Stripboek
- Prentenboek
- Reisgids
- Woordenboek
- Fotoboek

Bibliotheken mogen aanvullende eigen Boeksoorten beheren.

Boeksoort blijft exact één per LibraryCatalogContext.

Een nieuwe Boeksoort wordt niet vrij aangemaakt door gewone gebruikers tijdens
Item-add.

Wanneer een gewone gebruiker geen passende actieve Boeksoort kan kiezen, kan
de nieuwe Library + Work-combinatie niet worden afgerond.

Er komt hiervoor:

- geen `Onbekend`;
- geen `Overig` als technische fallback;
- geen pending Item;
- geen pending LibraryCatalogContext;
- geen automatische voorstelqueue vanuit Item-add.

Een verzoek om een werkelijk nieuwe Boeksoort loopt buiten de normale
Item-add-flow via een afzonderlijk, bewust hoger-drempelig aanvraagproces.

De exacte aanvraag-UI valt buiten F2.5.

## 5. Genre

Genre is:

- optioneel;
- meervoudig;
- Library-owned.

De bestaande standaardset blijft de functionele basis:

- Avontuur
- Fantasy
- Sciencefiction
- Thriller
- Detective / Mystery
- Horror
- Romance
- Historisch
- Literatuur
- Humor / Satire
- Dystopie
- Magisch realisme

Een Library mag aanvullende Genres beheren.

Genre is niet bedoeld voor Boeksoorten, doelgroepen of inhoudelijke
Onderwerpen.

## 6. Onderwerp

Onderwerp is:

- optioneel;
- meervoudig;
- Library-owned.

Er is geen vooraf gevulde standaardset.

Onderwerpen groeien organisch.

Metadata mag onbekende Onderwerpen voorstellen, maar een onbekende term wordt
niet automatisch actief.

Goedgekeurde metadata-mappings worden per Library onthouden.

Hetzelfde mappingprincipe kan voor Genres worden gebruikt.

## 7. Aanmaken tijdens Item-add

Item-add en taxonomiebeheer zijn verschillende bevoegdheden.

Een gebruiker die bevoegd is een Item toe te voegen mag bij het ontstaan van
een nieuwe Library + Work-context bestaande actieve classificaties selecteren:

- exact één bestaande actieve Boeksoort;
- 0..n bestaande actieve Genres;
- 0..n bestaande actieve Onderwerpen.

Bestaat al een LibraryCatalogContext voor Library + Work, dan wordt die context
hergebruikt en tijdens Item-add niet stilzwijgend gewijzigd.

Gewone gebruikers creëren tijdens Item-add geen nieuwe classificatietermen.

Ontbrekend Genre of Onderwerp blokkeert Item-add niet.

Ontbrekende passende Boeksoort blokkeert het ontstaan van de nieuwe
Library + Work-combinatie wel.

Een bevoegde Owner/Manager mag, waar de UI dit ondersteunt:

- gecontroleerd inline één nieuw Genre toevoegen;
- laagdrempeliger inline één nieuw Onderwerp toevoegen.

Nieuwe Boeksoorten worden niet inline aangemaakt.

Uitgebreid hernoemen, inactiveren, heractiveren en beheer vindt plaats in
Bibliotheekbeheer.

## 8. Authorization

De volgende bevoegdheden blijven afzonderlijk:

1. Item toevoegen;
2. een ontbrekende LibraryCatalogContext initialiseren met bestaande actieve
   termen;
3. bestaande Work-classificatie wijzigen;
4. classificatietermen beheren.

Een Item-add-permission geeft niet automatisch algemeen classificatie- of
taxonomiebeheer.

Bestaande LibraryCatalogContext wijzigen vereist:

- Owner; of
- Manager met de expliciete toepasselijke managementpermission.

Termen creëren, hernoemen, inactiveren en heractiveren vereist eveneens Owner
of een expliciet bevoegde Manager.

Authorization wordt server-side afgedwongen.

UI-zichtbaarheid is nooit de authorization boundary.

F2.5 mag deze rechten niet generiek laten samenvallen met één breed
`canManageCatalog()` wanneer de semantiek verschillende bevoegdheden vereist.

## 9. Lifecycle classificatietermen

Voor Boeksoort, Genre en Onderwerp geldt:

    active -> inactive -> active

Een inactieve term:

- behoudt dezelfde stabiele ID;
- behoudt bestaande koppelingen;
- is niet beschikbaar voor nieuwe classificatiekeuzes.

Heractivering maakt dezelfde term-ID opnieuw beschikbaar.

Naamuniciteit geldt over actieve én inactieve termen.

Wanneer een inactieve equivalente term bestaat, wordt geen nieuwe term-ID
aangemaakt; de bestaande term kan worden heractiveerd.

Via de normale v2.001-UI is geen hard-delete van classificatietermen
beschikbaar.

Term merge is buiten scope voor F2.5.

## 10. Hernoemen

Hernoemen verandert de bestaande term en niet de koppelingen.

Alle LibraryCatalogContexts die dezelfde term-ID gebruiken tonen daarna de
nieuwe naam.

Er worden geen oude labels per Work opgeslagen als afzonderlijke
classificatiewaarden.

Hernoemen van een term is taxonomiebeheer en geen LibraryCatalogContext-mutatie.

## 11. Naamnormalisatie en duplicaten

Classificatietermen behouden hun oorspronkelijke displaynaam.

Daarnaast wordt een beperkte technische normalisatie gebruikt voor
duplicate-detectie.

Harde duplicate-detectie mag onder andere negeren:

- hoofdletterverschillen;
- whitespace aan begin/einde;
- meervoudige of typografisch equivalente whitespace;
- triviale separatorvarianten zoals spatie versus verbindingsstreepje waar
  aantoonbaar alleen formattering verschilt.

De normalisatie is bewust conservatief.

Niet automatisch gelijkstellen:

- synoniemen;
- afkortingen;
- vertalingen;
- inhoudelijk vergelijkbare termen;
- algemene accentvarianten;
- agressief verwijderde leestekens.

Voorbeelden zoals `Sci-fi` versus `Sciencefiction` of `WO II` versus
`Tweede Wereldoorlog` zijn semantische mappings, geen harde technische
duplicates.

Een mogelijke semantische match mag als waarschuwing/suggestie worden getoond,
maar wordt niet automatisch samengevoegd.

Geen automatische synonym- of term-merge in F2.5.

## 12. Weergave en volgorde

Genre en Onderwerp hebben geen handmatige sorteerpositie.

Normale presentatie is alfabetisch.

Selectie- en zoekinterfaces mogen tijdens zoeken op relevantie sorteren.

Na selectie heeft de volgorde geen domeinbetekenis.

Hernoemen van een term mag daardoor vanzelf zijn alfabetische positie wijzigen.

## 13. Bestaande pre-F2.5-data

Er vindt geen fictieve classificatiebackfill plaats.

Een bestaande Library + Work-combinatie van vóór F2.5 mag tijdelijk zonder
LibraryCatalogContext blijven bestaan.

Biblio maakt daarvoor geen kunstmatige Boeksoort zoals `Onbekend` of `Overig`.

Zodra zo'n bestaande combinatie bewust wordt geclassificeerd, wordt een
LibraryCatalogContext aangemaakt en is exact één Boeksoort verplicht.

Na introductie van F2.5 mag een nieuwe Library + Work-combinatie via Item-add
niet meer zonder geldige LibraryCatalogContext ontstaan.

## 14. Inactieve termen in bestaande contexten

Wanneer een gekoppelde Boeksoort, Genre of Onderwerp later inactief wordt,
blijft de bestaande koppeling geldig.

Een inactieve term kan niet voor nieuwe koppelingen worden gekozen.

Een bestaande context wordt niet alleen vanwege inactivatie automatisch
gemuteerd.

Wanneer een bestaande context bewust wordt gewijzigd, moet bij opslaan een
geldige actieve Boeksoort zijn geselecteerd.

Genre- en Onderwerpkoppelingen worden niet stilzwijgend verwijderd alleen
omdat een term inactief is geworden.

## 15. Wijzigen van bestaande classificatie

LibraryCatalogContext wordt expliciet op Work-niveau binnen de huidige Library
bewerkt.

De UI moet duidelijk maken dat de wijziging geldt voor alle Editions/Items van
dat Work binnen die Library.

Genre- en Onderwerpwijzigingen mogen via de normale expliciete `Opslaan`-actie
worden toegepast zonder extra confirmatiestap.

Wijziging van Boeksoort vereist een aanvullende expliciete bevestiging.

Wanneer meerdere Items/Editions geraakt worden, mag de UI de concrete impact
tonen, bijvoorbeeld:

    Dit heeft invloed op 4 exemplaren.

Contextbeheer en termbeheer zijn afzonderlijke acties.

## 16. Metadata

Metadata mag classificaties voorstellen.

Metadata mag een bestaande Boeksoort nooit stilzwijgend wijzigen.

Onbekende Genre- of Onderwerptermen mogen worden voorgesteld, maar worden niet
automatisch actieve Library-termen.

Een bevoegde beheerder kan een voorstel:

- goedkeuren;
- koppelen aan een bestaande term;
- afwijzen.

Goedgekeurde mappings worden per Library onthouden.

Ontbrekende metadata-classificatie mag nooit een half-geldig of pending Item
veroorzaken.

## 17. Audit

Classificatie-audit gebruikt de bestaande Library ActivityEvent-architectuur.

Per geslaagde bewuste LibraryCatalogContext-save ontstaat één ActivityEvent.

Primary entity:

    LibraryCatalogContext

Related entity:

    Work

Het event bevat structured old/new changes voor:

- Boeksoort;
- Genres;
- Onderwerpen.

Waar het bestaande auditmodel dit vereist worden zowel technische IDs als
historische displaywaarden opgenomen.

Er ontstaan geen duplicaat-events per Item.

Contextuele Work- of Item-weergaven mogen hetzelfde ActivityEvent via
projectie/filtering tonen.

Geen ActivityEvent voor:

- openen;
- zoeken;
- filteren;
- annuleren zonder succesvolle mutatie.

Termbeheer krijgt eigen ActivityEvents op termniveau, waaronder:

- created;
- renamed;
- deactivated;
- reactivated.

Actor en source worden volgens het bestaande ActivityEvent-model vastgelegd.

Audit-events zijn immutable.

## 18. Concurrency LibraryCatalogContext

LibraryCatalogContext gebruikt optimistic locking.

Iedere context heeft een monotone `version`.

Een wijziging mag alleen slagen wanneer de aangeboden versie overeenkomt met
de actuele opgeslagen versie.

Bij een succesvolle contextmutatie:

    version = version + 1

Bij een stale version:

- volledige mutatie weigeren;
- niets gedeeltelijk opslaan;
- geen ActivityEvent creëren;
- actuele toestand beschikbaar maken voor review;
- gebruiker moet bewust opnieuw beslissen en opslaan.

Geen:

- last-write-wins;
- pessimistic edit locks;
- automatische merge in v2.001.

De contextmutatie, version increment en bijbehorend ActivityEvent moeten
transactioneel consistent zijn.

## 19. Welke wijzigingen verhogen contextversion

LibraryCatalogContext.version verandert uitsluitend wanneer de
classificatierelaties van die context veranderen.

Version +1 bij een succesvolle save waarin één of meer van deze wijzigingen
plaatsvinden:

- Boeksoort-ID verandert;
- Genre-ID wordt toegevoegd;
- Genre-ID wordt verwijderd;
- Onderwerp-ID wordt toegevoegd;
- Onderwerp-ID wordt verwijderd.

Meerdere wijzigingen binnen één succesvolle save verhogen de version samen
slechts eenmaal.

Termmutaties verhogen LibraryCatalogContext.version niet.

Dus geen context-version increment bij:

- term hernoemen;
- term inactiveren;
- term heractiveren.

## 20. Concurrency classificatietermen

Boeksoort, Genre en Onderwerp krijgen in F2.5 nog geen eigen optimistic-lock
`version`.

Bescherming bestaat voorlopig uit:

- authorization;
- domeinconstraints;
- genormaliseerde naamuniciteit;
- status/lifecycle-invarianten;
- transactionele writes;
- ActivityEvent-audit.

Concurrent aanmaken van dezelfde genormaliseerde term moet op
database-/persistence-niveau resulteren in:

- exact één winnaar;
- exact één stabiele conflictuitkomst.

Term-level optimistic locking wordt opnieuw beoordeeld wanneer termbeheer
complexer wordt, bijvoorbeeld bij:

- aliases;
- merge;
- bulk-herclassificatie;
- rijkere taxonomiebeheerflows;
- aantoonbare multi-admin lost-updateproblemen.

## 21. Hiërarchie en merge

Genre en Onderwerp blijven in v2.001 vlak.

Geen parent/child-taxonomie.

Geen automatische of handmatige term-merge in F2.5.

Rijkere taxonomiehiërarchie, aliases en merge zijn deferred.

## 22. Transactionele Item-add-integratie

Wanneer Item-add een nieuwe Library + Work-combinatie creëert, moeten de
vereiste nieuwe LibraryCatalogContext en het Item transactioneel consistent
worden aangemaakt.

Een mislukking mag geen half-geldige keten achterlaten.

Bestaat de LibraryCatalogContext al, dan wordt die hergebruikt en niet
stilzwijgend gewijzigd.

Concurrent creation van dezelfde Library + Work-context moet een stabiele,
voorspelbare uitkomst hebben en mag geen dubbele context opleveren.

## 23. Verwachte persistence-richting

F2.5 zal naar verwachting een formele migration na schema 1000 vereisen.

Verwachte conceptuele structuren:

- LibraryBookType;
- LibraryGenre;
- LibrarySubject;
- LibraryCatalogContext;
- LibraryCatalogContextGenre;
- LibraryCatalogContextSubject.

Exacte tabelnamen en DDL worden tijdens technische implementatie vastgesteld.

Persistence moet database-technisch afdwingen dat een classificatieterm uit
Library A niet aan een context van Library B gekoppeld kan worden.

De bestaande schema-baseline wordt niet herschreven.

## 24. Expliciete non-scope F2.5

Niet onderdeel van deze slice:

- Author/contributors;
- Series;
- centrale Work/Edition-metadata;
- centrale bibliografische correction proposals;
- Location;
- Condition;
- Acquisition;
- inventory number;
- search/index;
- REST/Abilities;
- Elementor/UI-implementatie;
- Collections;
- Archive;
- lending;
- generieke taxonomie-engine;
- term merge;
- taxonomiehiërarchie;
- automatische classificatie;
- exacte UI van het Boeksoort-aanvraagformulier;
- brede metadata-moderatie-UI;
- term-level optimistic locking.

## 25. Implementatieprincipes

F2.5 moet de bestaande F2.3-architectuur uitbreiden en niet vervangen.

Behoud:

- Work/Edition als platformbrede identiteit;
- Catalog\Item als canoniek Item-domeinobject;
- server-side actor resolution;
- expliciete Library Context;
- repository-encapsulatie;
- ProductionComposition als composition root;
- CoreApplication als adapter-facing application boundary;
- transactionele compound writes;
- ReadingRound Item -> Edition -> Work-afleiding;
- schema migration discipline;
- volledige regressie naar eerdere fases.

Nieuwe command/intent-value-objects hebben de voorkeur boven het onbeperkt
uitbreiden van AddLibraryItemService-signatures.

## 26. Acceptatie-intentie F2.5

F2.5 is pas technisch gereed wanneer minimaal bewezen is dat:

1. LibraryCatalogContext exact Library + Work scoped is.
2. Een context exact één Boeksoort bevat.
3. Genre en Onderwerp duplicatevrije sets zijn.
4. Cross-Library termgebruik onmogelijk is.
5. Verschillende Libraries hetzelfde Work verschillend kunnen classificeren.
6. Alle Editions/Items van hetzelfde Work binnen één Library dezelfde context
   delen.
7. Legacy catalogusdata zonder context behouden blijft.
8. Nieuwe Library + Work-combinaties niet contextloos ontstaan.
9. Bestaande context tijdens Item-add niet impliciet wordt gewijzigd.
10. Authorization de onderscheiden beheerrechten bewaakt.
11. Termnaamuniciteit ook inactieve termen omvat.
12. Inactiveren bestaande koppelingen niet vernietigt.
13. Heractiveren dezelfde term-ID gebruikt.
14. Contextmutaties optimistic locking gebruiken.
15. Stale writes geen partial mutation of ActivityEvent opleveren.
16. Eén context-save maximaal één version increment en één hoofd-auditevent
    produceert.
17. Concurrent duplicate-term-creation één winnaar en één stabiel conflict
    oplevert.
18. Context + Item compound creation atomair is.
19. Work/Edition platformbreed en ongemuteerd blijven.
20. ReadingRound- en F2.3-contracten groen blijven.
21. Migration vanaf schema 1000 bestaande data behoudt.
22. Volledige canonieke quality gate groen is.

## 27. Deferred follow-up

Na F2.5 opnieuw beoordelen:

- server-side ItemIdGenerator;
- rijkere conflictcontext;
- term-level optimistic locking;
- metadata-moderatie-UI;
- Boeksoort-aanvraagworkflow;
- aliases/merge;
- taxonomiehiërarchie;
- Contributor/Author-model;
- Series-model.

## 28. Consequenties

Voordelen:

- lokale classificatie vervuilt centrale bibliografische identiteit niet;
- dezelfde Work kan per Library anders worden geclassificeerd;
- geen fictieve migratiedata;
- geen pending Item/context-lifecycle nodig;
- taxonomie blijft beheersbaar;
- lost updates op gedeelde Work-classificatie worden voorkomen;
- audit blijft betekenisvol zonder eventspam;
- toekomstige metadata- en UI-uitbreiding krijgt een duidelijke domeingrens.

Nadelen / bewuste trade-offs:

- een ontbrekende Boeksoort kan Item-add blokkeren;
- beheerders hebben verantwoordelijkheid voor lokale taxonomie;
- optimistic locking voegt persistence- en UX-complexiteit toe;
- semantische duplicates worden in F2.5 niet automatisch opgelost;
- bestaande pre-F2.5-data kan tijdelijk contextloos blijven;
- termmutaties hebben voorlopig geen eigen lost-update-detectie.

Deze trade-offs zijn voor v2.001 geaccepteerd.
