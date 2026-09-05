# 40 — Wat zal ik lezen? functionele basis

Status: **Functionele basis v2.001 — vastgezet**

Datum: 2026-09-04.

## 1. Doel en afbakening

`Wat zal ik lezen?` is een private persoonlijke keuzehulp die de gebruiker
helpt kiezen welk beschikbaar boek die nu kan gaan lezen. De functie is
gebruiker-eigen, platformbreed en onderdeel van Mijn Biblio.

De functie staat nadrukkelijk los van `Hierna lezen`. Hierna lezen blijft één
volledig handmatig samengestelde, geordende persoonlijke planning/shortlist.
Suggestion-engines herschikken of muteren nooit Hierna lezen, Verlanglijst,
leesstatus, ReadingRounds, Collecties, leesdoelen, classificaties of andere
brondata.

Dit document legt productgedrag vast, geen implementatie. Exacte UI, aantallen,
wegingen, opslag, API-contracten en fasering blijven open.

## 2. Ownership, privacy en context

Suggestion-resultaten, afgeleid smaakprofiel en persoonlijke ranking zijn
uitsluitend toegankelijk voor de betreffende gebruiker. Library Eigenaren,
Beheerders, andere gebruikers en supporttoegang krijgen daar geen toegang toe.

De suggestion-logica behoort niet toe aan een Bibliotheek. Bibliotheken leveren
alleen context en beschikbare kandidaatbronnen. Persoonlijke signalen mogen
voor de gebruiker platformbreed worden benut, ook wanneer de kandidatenpool
tot één Library is beperkt. Leesgeschiedenis uit Library B kan dus persoonlijke
relevantie leveren voor kandidaten uit Library A zonder ownership over te
dragen of Library-data buiten toegestane context te ontsluiten.

Historische user-owned signalen blijven voor de eigenaar bruikbaar nadat toegang
tot een eerdere Library is verloren. Classificaties uit die verloren
Library-context worden daarbij niet gelezen of afgeleid: een toekomstige
historische classificatiesnapshot vraagt een afzonderlijk besluit.

## 3. Bereikbaarheid

`Wat zal ik lezen?` heeft twee bereikbare contexten:

- `Mijn Biblio → Wat zal ik lezen?`: standaard uit alles wat de gebruiker nu
  daadwerkelijk kan lezen;
- `Bibliotheek → Wat zal ik lezen?`: dezelfde persoonlijke engines, met de
  kandidatenpool automatisch beperkt tot de actuele geautoriseerde Library.

Binnen iedere Bibliotheek is dit een zelfstandige duidelijke ingang naast
Home, niet uitsluitend een Home-widget en geen aparte Library-engine. Home
blijft primair actiecentrum. Een kleine contextuele ingang op Home kan later
worden overwogen, maar is niet de hoofdinteractie.

## 4. Kandidatenpool en Work-identiteit

De standaardpool start bij concrete bronkandidaten die de gebruiker binnen de
actieve scope op dat moment daadwerkelijk kan lezen. De gesloten fysieke
v2.001-set is: een direct toegankelijk actief Library Item, een actief intern
geleend Item aan de huidige gebruiker en een actieve externe lening. Digitale,
provider-, pseudo- en generieke andere bronnen horen niet bij deze basis.

Beschikbaarheid wordt per concrete bron bepaald. Voor een Library-bron wordt
daarna alleen de geautoriseerde, Library-lokale `LibraryCatalogContext`
gebruikt voor Boeksoort, Genre en Onderwerp. Een externe lening zonder
toepasselijke context blijft een geldige beschikbare bron, maar krijgt geen
verzonnen of overgeërfde classificatie.

Een Work met een actieve ReadingRound is geen kandidaat voor een nieuw te
starten boek, ook wanneer voor hetzelfde Work nog een tweede geldige bron
beschikbaar is. Dit is uitsluitend een suggestie-regel: Leesvoorraad,
ReadingRound-model en brongebaseerde Leesvoorraad-deduplicatie veranderen niet.
Na de bron- en contextstappen worden kandidaten per Work gegroepeerd en
gededupliceerd. Selectie en ranking gebeuren op Work-niveau; meerdere geldige
exemplaren of bronnen van hetzelfde Work leveren geen extra kans of gewicht.
Hierna lezen heeft in de standaardpool geen automatische prioriteit.

Een beschikbare omnibusbron maakt ieder werkelijk daarin opgenomen Work tot
kandidaat. Het getoonde resultaat is steeds het onderliggende Work; een set of
boxset die niet zelf als Work leesbaar is, wordt niet als zelfstandig resultaat
getoond. Bij `Start lezen` gebruikt Biblio de bestaande concrete-bronselectie,
die ook de omnibusbron kan kiezen. De engine kiest bij meerdere geldige bronnen
niet automatisch een voorkeursbron.

## 5. Modussen en gemeenschappelijke pipeline

De gebruikersingangen zijn `Voor mij gekozen`, `Herontdek`, `Verras me` en
`Kies uit…`. De eerste drie zijn selectie-engines. `Kies uit…` is geen vierde
engine maar een scope-, filter- en orchestratielaag die daarna één engine
toepast.

De conceptuele pipeline is broncontext-eerst:

1. concrete bronkandidaten binnen de actieve context bepalen;
2. per bron toegang en actuele beschikbaarheid bepalen;
3. actieve scope toepassen;
4. per toepasselijke Library-bron Library-lokale classificaties en persoonlijke
   uitsluitingen toepassen;
5. eventuele `Kies uit…`-filters toepassen;
6. de overblijvende bronkandidaten per Work groeperen en dedupliceren;
7. de gekozen engine op unieke Works uitvoeren;
8. het resultaat op Work-niveau tonen;
9. bij `Start lezen` de bestaande concrete-bronselectie gebruiken.

Beschikbaarheid, filtering en recommendation-ranking blijven conceptueel
gescheiden.

## 6. Voor mij gekozen

Doel: beantwoorden wat waarschijnlijk goed bij de gebruiker past om nu te
lezen. v2.001 gebruikt een uitlegbare rule-based engine, zonder vereiste voor
machine learning, collaborative filtering of black-boxscore.

Mogelijke persoonlijke signalen zijn eigen ReadingRounds, waarderingen,
herlezingen, auteurs, Series, Boeksoort, Genre, Onderwerp waar beschikbaar,
Hierna lezen als positief intentiesignaal, actieve relevante leesdoelen,
seriecontinuïteit, relevante leencontext en recente én langdurige
leespatronen. Betekenisvolle stopredenen mogen voorzichtig negatief meewegen.

Principes:

- expliciete waardering weegt sterker dan puur bezit;
- uitgelezen Works wegen sterker dan alleen registratie;
- herlezingen kunnen een sterk voorkeurssignaal zijn;
- seriecontinuïteit is sterk positief, maar nooit verplicht nummer één;
- Hierna lezen is positief maar krijgt geen automatische topprioriteit;
- actieve leesdoelen mogen relevantie verhogen zonder een takenlijst te maken;
- recente patronen mogen geen `meer van hetzelfde`-feedbackloop veroorzaken;
- lage waarderingen mogen voorzichtig negatief meewegen en negatieve
  generalisatie neemt af naarmate het signaal abstracter wordt;
- `Gestopt` is context, niet automatisch dislike: `Niet mijn smaak` kan
  relevant negatief zijn, `Moest terug` nauwelijks;
- ontbrekende Boeksoort-, Genre- of Onderwerpdata wordt genegeerd en is geen
  nulscore of automatische benadeling.

Zowel nooit uitgelezen als eerder uitgelezen Works mogen worden aanbevolen.
Lichte diversificatie tussen meerdere resultaten is toegestaan zonder
kunstmatige categoriequota.

Iedere suggestie heeft minimaal één concrete begrijpelijke reden, bijvoorbeeld
een hoog gewaardeerde auteur, eerder uitgelezen seriedelen of de combinatie van
Hierna lezen en een actief leesdoel. `Past bij jouw profiel`, matchpercentages
en schijnprecisie zijn niet toegestaan.

Andere gebruikers leveren in v2.001 geen signalen: geen populariteit, publieke
gemiddelde rating, sociaal model of `mensen zoals jij`.

### Cold start

Bij weinig data gebruikt de engine alleen betrouwbare signalen en communiceert
zij transparant dat weinig leesgeschiedenis beschikbaar is. Zij doet niet alsof
een sterk smaakprofiel bestaat. Zonder voldoende grond voor persoonlijke
ranking wordt geen pseudo-personalisatie getoond en kan `Verras me` als
alternatief worden aangeboden.

## 7. Herontdek

Herontdek brengt beschikbare boeken opnieuw onder de aandacht die de gebruiker
waarschijnlijk over het hoofd ziet. De engine optimaliseert op herontdekking,
niet op hoogste smaakmatch. Nooit uitgelezen en eerder uitgelezen Works mogen
voorkomen, zonder geforceerde balans.

Primaire v2.001-strategieën zijn:

- **Lang in de kast:** langere tijd beschikbaar en nooit uitgelezen;
- **Oude favoriet:** eerder uitgelezen, positief gewaardeerd en langere tijd
  niet herlezen;
- **Uit beeld geraakt:** betekenisvolle eerdere geschiedenis met auteur of
  Serie, recent weinig teruggekomen, en nu een geldige kandidaat beschikbaar.

`Lang op Hierna lezen` is hoogstens ondersteunend. Iedere suggestie legt
concreet uit wat wordt herontdekt. Complexe novelty-profielen, mood-logica, een
automatische `anders-dan-normaal`-engine en externe ontdekking vallen niet in
de eerste v2.001-versie.

Een strategie gebruikt alleen tijdclaims waarvoor de betrokken bron- of
persoonlijke historie de vereiste precisie heeft. Ontbrekende of grovere data
wordt niet als een schijnexacte duur, datum of volgorde gepresenteerd.

## 8. Verras me

Verras me kiest echt willekeurig één geldig boek. Ieder geldig Work heeft
uniform één gelijke kans; meerdere exemplaren of bronnen geven geen extra
kans. Nooit gelezen en eerder uitgelezen Works mogen meedoen, actieve
ReadingRounds niet.

De engine rankt niet op waardering, auteur, Serie, Hierna lezen, leesdoel,
recente voorkeur of smaakprofiel. Binnen één sessie wordt een getoonde
kandidaat niet herhaald zolang andere geldige kandidaten bestaan. Na volledige
doorloop mag de tijdelijke exposure-set resetten.

Er is geen langetermijn-exposureweging, novelty-score of permanente
smaakafleiding uit `Nog een` of `Niet nu`. Recommendation-uitleg is niet nodig;
wel mag transparant worden vermeld uit hoeveel momenteel leesbare Works is
gekozen.

## 9. Kies uit…

De flow is: scope bepalen, optioneel verfijnen en vervolgens één van de drie
engines toepassen. Primaire scopes voor v2.001 zijn alles wat ik kan lezen, de
actuele/specifieke Bibliotheek, Hierna lezen, één Collectie, een geschikt actief
leesdoel en geleende boeken. Een leesdoel is alleen rechtstreeks bruikbaar als
het voldoende concrete Works of selectieregels oplevert.

Primaire filters zijn Boeksoort, Genre en leesgeschiedenis (`Maakt niet uit`,
`Nog nooit uitgelezen`, `Eerder uitgelezen`). Onder `Meer keuzes` mogen
Onderwerp, Auteur en Serie staan. Groepen combineren met EN; meerdere waarden
binnen één groep met OF. Er komt geen handmatig AND/OR-menu.

Waar praktisch wordt vooraf het kandidaataantal getoond. Bij nul kandidaten
worden filters niet automatisch versoepeld. Mood, complexe querybouw,
automatische versoepeling en uitgebreide pagina-/lengtelogica zijn geen
kernfilter voor deze basis.

## 10. Classificaties en externe bronnen

Boeksoort, Genre en Onderwerp blijven strikt verschillende dimensies:

- **Boeksoort:** functionele soort, zoals Leesboek, Kennisboek, Kookboek,
  Studieboek, Stripboek, Prentenboek, Reisgids, Woordenboek of Fotoboek;
- **Genre:** literair/verhalend genre, zoals Thriller, Fantasy,
  Sciencefiction, Detective/Mystery, Horror of Romance;
- **Onderwerp:** inhoudelijk onderwerp, zoals Tweede Wereldoorlog,
  Psychologie, Vulkanen of Geologie.

Deze classificaties zijn Library-owned en horen bij `LibraryCatalogContext`.
Dezelfde genormaliseerde termnaam in twee Libraries is geen gedeelde term-ID,
merge of globale classificatie. `Thriller` impliceert bijvoorbeeld niet
Psychologische thriller, Suspense of Crime, tenzij precies die bestaande lokale
term is gekoppeld.

Bij een externe lening zonder toepasselijke `LibraryCatalogContext` ontbreekt
deze classificatiedata. Een expliciete filter `Genre: Fantasy` laat zo'n bron
dus niet matchen; een uitsluiting `Genre: Horror` sluit haar niet uit wegens
ontbrekende data. Hetzelfde geldt voor Boeksoort en Onderwerp.

## 11. Mijn voorkeuren → Algemeen → Suggesties

Deze instellingen zijn gebruiker-eigen, privé en platformbreed, zonder
Librarydefault, Library-eigendom of Librarybeheer. De zichtbare structuur is:

    Mijn voorkeuren
    └── Algemeen
        └── Suggesties
            ├── Voor mij gekozen
            │   ├── Uitgesloten Boeksoorten
            │   └── Uitgesloten Genres
            ├── Herontdek
            │   ├── Uitgesloten Boeksoorten
            │   └── Uitgesloten Genres
            └── Verras me
                ├── Uitgesloten Boeksoorten
                └── Uitgesloten Genres

`Algemeen` is zichtbaar zodra er ten minste één concrete instelling bestaat.
De eerdere v2.001-regel dat een lege/beperkte set `Algemeen` verborgen houdt,
is hiervoor bewust vervangen. `Kies uit…` heeft geen eigen uitsluitlijst.
Standaard is niets uitgesloten.

Een uitsluiting werkt per concrete kandidaatbron tegen de lokale
genormaliseerde naam van de toepasselijke Library-classificatie. Dezelfde naam
mag daarom in meerdere Libraries matchen zonder term-ID's te verenigen of een
globale classificatie te vormen. Als een toepasselijke context meerdere Genres
heeft, sluit minimaal één uitgesloten Genre die bron standaard voor de engine
uit. Ontbrekende classificatiedata, waaronder bij een externe lening zonder
toepasselijke context, is geen uitsluitingsreden. Er is geen automatische
semantische synoniemmapping.

De betekenis is `standaard niet suggereren`, niet `nooit tonen`. Kiest de
gebruiker in `Kies uit…` expliciet een normaal uitgesloten Boeksoort of Genre,
dan mag die uitsluiting alleen voor die selectie worden genegeerd. De opgeslagen
voorkeur verandert niet.

## 12. Overlap tussen engines

Eén Work mag bij meerdere engines passen wanneer doel en uitleg verschillen.
Wanneer meerdere engine-secties tegelijk zichtbaar zijn, worden dubbele Works
waar voldoende goede alternatieven bestaan vermeden. Bij afzonderlijk openen
mag hetzelfde Work opnieuw voorkomen.

## 13. Deferred en expliciete non-scope

Niet onderdeel van deze basis zijn externe Works die de gebruiker niet kan
lezen, externe catalogus-discovery, collaborative/social filtering,
populariteitsranking, ML/AI als vereiste, matchpercentages, mood-taxonomie,
automatische bronvoorkeur, complexe recommendation-feedback, permanente
smaakaanpassing uit `Niet nu`, complexe novelty/exposure-history en automatisch
wijzigen van Hierna lezen, leesdoelen of classificaties.

`Smart Hierna lezen` blijft een afzonderlijk deferred concept. De activering
van `Wat zal ik lezen?` verandert Hierna lezen niet in een slimme lijst.

## 14. Open ontwerp- en implementatiepunten

Eerstvolgende open ontwerpvraag:

> Hoeveel resultaten toont iedere modus per selectie?

De voorlopige richting — geen besluit — is mogelijk drie voor `Voor mij
gekozen`, drie voor `Herontdek`, één voor `Verras me`, en voor `Kies uit…` het
aantal van de gekozen engine.

Nog niet definitief ontworpen zijn exacte UI-layout, rankinggewichten,
scoringformule, tijdsdrempels en definities van `recent`/`lang geleden`, opslag
van suggestion preferences, endpoint/API-contracten en implementatiefasering.

## 15. Relatie tot bestaande contracten

Dit document vult Mijn Biblio, Library Context, Leesvoorraad, ReadingRounds,
Ratings, Goals, Collections en classifications aan zonder ownership of
lifecycle te wijzigen. De handmatige grens van Hierna lezen blijft normatief in
[ADR-008](decisions/ADR-008-next-reading-intent-model-and-transactional-consumption.md)
en [het contractcorrectiedocument](28-next-reading-contract-correction.md).

De technische source foundations uit
[document 39](39-existing-source-filter-read-foundation-exit-evidence.md) zijn
geen implementatie van deze feature. Nieuwe technische boundaries, opslag,
REST en UI vereisen afzonderlijke analyse en goedkeuring.
