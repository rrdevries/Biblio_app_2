# 31 — Biblio V2 Design System

Status: **canonieke, levende ontwerpbaseline**.

Project: Biblio V2.

Doel: visuele, UX- en UI-implementatiestandaard voor WordPress/Elementor,
Biblio UI-componenten en toekomstige ontwerpbeslissingen.

## 1. Autoriteit, gebruik en afbakening

Dit document legt de vastgezette Biblio V2 Design System-baseline vast. Het is
een praktische ontwerp- en implementatiestandaard, geen marketing-styleguide.

Besluiten zijn aangeduid als:

- **Definitief:** leidend voor nieuwe Biblio V2 UI;
- **Werkwaarde / nog open:** de richting staat vast, maar waarden, assets of
  technische details vereisen nog validatie.

Nieuwe UI mag functioneel afwijken wanneer het betreffende domein dat vereist,
maar mag niet stilzwijgend afwijken van deze ontwerpprincipes. Een werkelijk
conflict wordt eerst als ontwerpbeslissing behandeld en niet lokaal in
Elementor of CSS opgelost.

Deze baseline verandert geen bestaand productgedrag en voert geen restyling
uit. De minimale, destijds voorlopige componenttokens in
[`docs/20-elementor-vertical-slice-1a-build-plan.md`](20-elementor-vertical-slice-1a-build-plan.md#15-minimum-design-system-contract)
blijven historische slicecontext; voor nieuw visueel ontwerp is dit document
leidend. Functionele en accessibility-eisen uit bestaande contracten blijven
onverminderd gelden.

De structurele rationale staat in
[`ADR-009`](decisions/ADR-009-biblio-ui-theming-and-atmosphere-architecture.md).

## 2. Visuele filosofie

Status: **Definitief**.

Biblio volgt **Editorial Library × Serious Utility**. De overkoepelende
visuele ontwerpfilosofie heet **Deep Library**.

Biblio is een persoonlijke, rijke boekenomgeving met rust en overzicht. Covers
en boekinhoud zijn het belangrijkste visuele materiaal. De interface blijft
tegelijk geschikt voor serieuze beheerflows en hoge informatiedichtheid.

> Kijken en ontdekken mag ruim en editorial zijn; beheren mag compacter en
> functioneler worden zonder van visuele identiteit te wisselen.

Deep Library betekent:

- uitgesproken editorial typografie, rustige compositie en een open layout;
- een Soft Ivory werkruimte, diepe kleurankers en subtiele brass-accenten;
- selectieve atmosfeer en verfijnde fysieke diepte;
- weinig cards en geen generieke SaaS-dashboardlook;
- geen nostalgische oude-bibliotheeklook, perkamentdecoratie of ornamentiek
  als standaard;
- niet overal donker, goud, grote afgeronde containers of decoratieve
  illustraties.

Deep Library is geen Theme en geen Atmosphere Pack.

## 3. Design-systemarchitectuur

Status: **Definitief**.

Drie systemen blijven strikt van elkaar gescheiden:

| Systeem | Bepaalt | Eerste opties |
|---|---|---|
| Appearance | licht/donker-weergave | `Light`, `Dark`, `System` |
| Theme | kleuridentiteit van de interface | `Ink` (default), `Aubergine`, `Petrol` |
| Atmosphere Pack | visuele hero-atmosfeer rond boeken | `Storyscape` (standaard), `Nature`, `Book Cover` |

Appearance is onafhankelijk van Theme en Atmosphere Pack. Theme verandert
geen informatiearchitectuur of componentstructuur. Atmosphere staat los van
Theme, Appearance, Genre en Werkmetadata.

**UI-REENTRY-02 — initiële Appearance voor Ink (keuze A, goedgekeurd
2026-09-27):** zonder expliciete persoonlijke Appearance-keuze start Biblio
in `Light`, ook wanneer het apparaat donker weergeeft. Met het standaard-Theme
`Ink` is dat Ink Light. Een expliciete keuze voor `Light` of `Dark` bepaalt de
weergave rechtstreeks; `System` volgt de licht/donkerinstelling van het
apparaat en gebruikt het bijbehorende Ink-palet. `System` is geen derde palet.
De voorkeureninterface, opslag en omschakeling zijn nog geen onderdeel van de
huidige Ink/Light-runtime en vragen een afzonderlijke implementatieslice.

Theme beïnvloedt onder andere navigatie- en interactieve kleuren, hover/active
states, subtiele washes, focusdetails, algemene surface textures en Dark
Mode-surfaces. Algemene sidebar-/page-surface-texture hoort bij Appearance,
Theme en de Deep Library surface language; zij is geen Atmosphere en wordt
niet gekozen door een Atmosphere Pack.

Voor later geparkeerde Themes zijn onder andere Forest en Golden Amber.
Mogelijke latere Atmosphere Packs zijn Abstract, Nocturne, Seasonal en Custom.
Deze uitbreidingen zijn nog geen uitgewerkt implementatiecontract.

## 4. Light, Dark en semantische kleurrollen

### 4.1 Light — Soft Ivory

Status: **Definitief voor Ink Light; overige Light-paletten nog open**.

De lichte hoofdwerkruimte gebruikt **Soft Ivory**: warmer dan zuiver wit, maar
niet beige of sepia. Zij contrasteert met de diepe sidebar, ondersteunt
kleurrijke covers en blijft rustig tijdens lange gebruikssessies.

**UI-REENTRY-02 — Ink Light (variant A, goedgekeurd 2026-09-27):** de bestaande
Ink Light-werkwaarden worden hieronder de exacte visuele productiebaseline.
Dit is een keuze voor de semantische tokenwaarden, geen wijziging van de
bestaande CSS en geen nieuwe visuele runtimeacceptatie van alle schermen.

| Rol | Ink Light | Rol | Ink Light |
|---|---|---|---|
| `page` | `#F7F4ED` | `surface` | `#FFFDF8` |
| `surface-elevated` | `#FFFAF0` | `navigation` | `#172238` |
| `navigation-hover` | `#26334B` | `navigation-active` | `#34425C` |
| `navigation-text` | `#FFFFFF` | `text-primary` | `#22252B` |
| `text-secondary` | `#4D535F` | `text-muted` | `#686E78` |
| `interactive` | `#243B53` | `interactive-hover` | `#172B43` |
| `interactive-subtle` | `#E9E4D9` | `border` | `#D5CEC0` |
| `border-strong` | `#9B9385` | `focus` | `#075F9E` |
| `brass` | `#866214` | `brass-subtle` | `#EEE2C5` |
| `status-success` | `#276749` | `status-warning` | `#805B10` |
| `status-danger` | `#9B1C1C` | `book-atmosphere` | `#D9D0C0` |

De statuskleuren blijven gedeelde semantische Light-rollen; de keuze voor Ink
maakt ze niet Theme-specifiek. `book-atmosphere` is hier alleen de neutrale
hero-werkkleur, niet een Atmosphere Pack of automatische packselectie.

De contrasttoets van deze tokenwaarden bevestigt de primaire tekst- en
actieparen op `page`, `surface` en `surface-elevated`, witte tekst op de drie
navigatiesurfaces en de interactieve actie, en de focuskleur op lichte
surfaces. De tokens mogen niet willekeurig worden gecombineerd:

- `text-muted` en `brass` op `interactive-subtle` of `brass-subtle` halen voor
  gewone tekst de `4.5:1`-grens niet; gebruik daar een passend donkerder
  teksttoken;
- `border-strong` op `page` haalt als zelfstandige interactieve begrenzing
  geen `3:1`; een relevante control heeft daarnaast een voldoende duidelijke
  begrenzing of focusstate nodig.

Directe-tokenberekening volgens de WCAG-contrastformule: `text-primary/page`
`13.98:1`, `text-muted/page` `4.67:1`, `navigation-text/navigation-active`
`10.10:1`, `navigation-text/interactive` `11.32:1` en `focus/page` `6.09:1`.
De vier afgewezen tekstparen hierboven meten respectievelijk `4.05:1`,
`4.39:1`, `3.99:1` en `4.33:1`; `border-strong/page` meet `2.77:1`.

Dit zijn gebruiksgrenzen voor de goedgekeurde kleuren, geen aanpassing van
de gekozen hexwaarden. Bij iedere UI-slice blijven de werkelijk gerenderde
states, overlays, color-mix-combinaties en toegankelijkheid te toetsen. Een
paletbesluit op zichzelf is geen volledige WCAG- of schermacceptatie.

### 4.2 Dark

Status: **Definitief voor Ink Dark; overige Dark-paletten nog open**.

Dark Mode is geen simpele inversie. Iedere Theme-familie levert eigen waarden
voor page, surfaces, navigatie, interactie, borders en tekst. Ink Dark,
Aubergine Dark en Petrol Dark blijven herkenbaar verschillend, behouden
surface-niveaus, worden niet volledig zwart en laten covers spreken.

**UI-REENTRY-02 — Ink Dark (variant A, goedgekeurd 2026-09-27):** het
blauwzwarte palet hieronder is de exacte visuele productiebaseline voor deze
Theme/Appearance-combinatie. De getoonde vergelijking hield inhoud en covers
gelijk aan de Ink Light-vergelijking. Ink Dark is nog niet geïmplementeerd;
dit besluit geeft geen nieuwe runtime- of schermacceptatie.

| Rol | Ink Dark | Rol | Ink Dark |
|---|---|---|---|
| `page` | `#151C28` | `surface` | `#202B3A` |
| `surface-elevated` | `#2B394C` | `navigation` | `#0C1422` |
| `navigation-hover` | `#1E2B40` | `navigation-active` | `#2D405B` |
| `navigation-text` | `#F4F5F3` | `text-primary` | `#F3F1EA` |
| `text-secondary` | `#CCD3DA` | `text-muted` | `#B2BCC8` |
| `interactive` | `#AACCEB` | `interactive-hover` | `#C7DFF4` |
| `interactive-subtle` | `#2A4055` | `border` | `#506176` |
| `border-strong` | `#7D91A7` | `focus` | `#9DD6FF` |
| `brass` | `#DDBE81` | `brass-subtle` | `#3B352D` |
| `status-success` | `#83D7AC` | `status-warning` | `#F2CE80` |
| `status-danger` | `#F4A4A4` | `book-atmosphere` | `#263448` |

In Ink Dark is `interactive` een lichte kleur die zowel als tekstlink op
donkere surfaces als achtergrond van een gevulde primaire actie kan werken.
Op die gevulde actie staat een donkere voorgrond, bijvoorbeeld `page`, en
geen witte of andere lichte tekst. Dit is de contextuele toepassing van de
bestaande semantische rollen, geen nieuwe token of wijziging van de vaste
Theme-architectuur. `book-atmosphere` blijft uitsluitend de neutrale
hero-werkkleur.

De directe-tokencontrasttoets geeft onder meer `text-primary/page` `15.12:1`,
`text-muted/surface` `7.44:1`, `navigation-text/navigation-active` `9.62:1`,
`interactive/page` en `page/interactive` beide `10.21:1`, `focus/page`
`10.98:1` en `border-strong/page` `5.27:1`. `border/page` meet `2.70:1` en
mag daarom niet de enige waarneembare begrenzing van een interactieve control
op de pagina zijn; gebruik daarvoor `border-strong` of een duidelijkere
focus-/controlstate. Werkelijk gerenderde componenten, overlays, hover- en
focusstates blijven bij uitvoering afzonderlijk te toetsen.

### 4.3 Semantische tokens

Status: **Definitief qua architectuur**.

Componenten gebruiken semantische tokens, geen hardcoded componentkleuren:

```text
page
surface
surface-elevated

navigation
navigation-hover
navigation-active
navigation-text

text-primary
text-secondary
text-muted

interactive
interactive-hover
interactive-subtle

border
border-strong

focus
brass
brass-subtle

status-success
status-warning
status-danger

book-atmosphere
```

Ink, Aubergine en Petrol leveren waarden voor deze rollen. In Light Mode zijn
Soft Ivory, lichte surfaces, tekst, neutrale borders, statuskleuren en brass
grotendeels Theme-onafhankelijk. Ink Light en Ink Dark zijn in §§4.1–4.2
vastgelegd; de exacte Aubergine- en Petrol-combinaties blijven open totdat
hun eigen visuele keuze en contrasttoets zijn afgerond.

## 5. Typografie

Status: **Definitief qua fontfamilies, rolverdeling en basis-desktopschaal;
overige typografische uitwerking nog open**.

> Serif = inhoudelijke identiteit. Sans-serif = interface, bediening en
> informatie.

Serif wordt gebruikt voor grote pagina- en boektitels, auteursnamen,
collectie-/serienamen, editorial highlights en quotes. Italic is selectief voor
grote boektitels op detail en Quick View, quotes, inhoudelijke nadruk en enkele
prominente collectie-/serietitels.

In grids zijn boektitels doorgaans serif regular of medium en niet standaard
italic, zodat grote grids scanbaar blijven.

Sans-serif wordt gebruikt voor navigatie, filters, knoppen, metadata,
statussen, formulieren, tabellen, lijsten, grafieken en microcopy.

**UI-REENTRY-02 — productiefonts (keuze A, goedgekeurd 2026-09-27):**
**Cormorant Garamond** is de serif voor inhoudelijke identiteit en
**Source Sans 3** is de sans-serif voor interface, bediening en informatie.
Inter blijft uitsluitend een technische fallback wanneer Source Sans 3 niet
beschikbaar is; het is geen tweede bedoelde interfacefont. De bestaande CSS
noemt deze families al in de font stacks, maar levert geen eigen fontbestanden.
Fontlevering en de werkelijke weergave in de productie-UI zijn niet bewezen.

Renée koos A boven de vergelijking met Inter en boven een later getoonde
Spectral-variant. Dat besluit betreft de **fontfamilies**, niet de visuele
acceptatie van de vergelijkingsmock. Renée vindt de aangeleverde warme
bibliotheekdashboardreferentie duidelijk verfijnder dan die mock; de afzonderlijke
oorzaken en hun uiteindelijke ontwerpwaarden zijn daarmee nog niet besloten.
De vergelijkingsmock krijgt geen scherm-GO en is geen pixelprecies
ontwerpcontract. De basisgewichten en -regelafstanden zijn hieronder apart
besloten; bijzondere tekstrollen en de uiteindelijke visuele afwerking
blijven open.

**UI-REENTRY-02 — basis-desktopschaal voor Ink (S1, goedgekeurd
2026-09-27):** de compacte editoriale schaal heeft de volgende
rolwaarden. Dit zijn ontwerpwaarden voor de desktopbasis, niet uit pixels
van de referentieafbeelding gemeten.

| Rol | Desktopschaal |
|---|---:|
| Paginatitel | `38px` |
| Quick View-titel | `30px` |
| Boektitel in catalogusgrid | `18px` |
| Algemene interfacetekst | `14px` |
| Aanvullende metadata | `12px` |

De `12px`-rol is voor aanvullende metadata; wezenlijke inhoud en bediening
mogen niet automatisch naar deze kleinste rol worden teruggebracht. De
verdere roltoewijzing, bijzondere tekstrollen, tekstzoom en
gerenderde leesbaarheid worden afzonderlijk uitgewerkt en getoetst. De
bestaande CSS bevat verspreide werkwaarden en is nog niet naar deze schaal
omgezet. S1 geeft geen visuele scherm-GO voor de vergelijkingsmock.

**UI-REENTRY-02 — mobiele basisschaal voor Ink (keuze A, goedgekeurd
2026-09-27):** de compacte mobiele typografie gebruikt dezelfde vijf rollen
als de desktopbasis, met de volgende ontwerpwaarden:

| Rol | Mobiele schaal |
|---|---:|
| Paginatitel | `32px` |
| Quick View-titel | `26px` |
| Boektitel in catalogusgrid | `18px` |
| Algemene interfacetekst | `16px` |
| Aanvullende metadata | `12px` |

De `12px`-rol blijft uitsluitend aanvullende metadata; wezenlijke inhoud,
formuliervelden en bediening gebruiken niet automatisch deze kleinste maat.
Dit besluit bepaalt de mobiele rolwaarden, niet het breakpoint waarop ze
ingaan, de precieze responsive compositie of de hieronder afzonderlijk
besloten basisgewichten en -regelafstanden.
De typografieproef is geen menselijke visuele GO voor een volledig mobiel
scherm. Bestaande CSS-werkwaarden zijn niet aangepast; tekstzoom en werkelijk
gerenderde leesbaarheid blijven te toetsen.

**UI-REENTRY-02 — typografisch basisritme voor Ink (keuze A, goedgekeurd
2026-09-27):** voor de getoonde pagina-, Quick View- en catalogusboektitels
geldt Cormorant Garamond `400` met een regelhoogte van `1.04`. Algemene
Source Sans 3-interfacetekst gebruikt `400` met een regelhoogte van `1.4`.
Deze verhouding geldt voor de desktop- en mobiele basisschalen hierboven.
Aanvullende metadata kan hetzelfde sans-serif basisritme volgen; de
kleinere grootte blijft uitsluitend voor die aanvullende rol.

Dit besluit gaat over het basisritme van deze rollen. Nadruk, actieve
bediening, langere leestekst, formulieren, uitzonderlijke titelvormen en
eventuele compactere metadata krijgen alleen waar nodig een afzonderlijke
rolregel. De proef is geen schermspecifieke visuele GO en bewijst geen
fontlevering, tekstzoom of gerenderde toegankelijkheid. De CSS is niet
aangepast.

## 6. Open compositie en surfaces

Status: **Definitief**.

**Open composition by default.** Structuur ontstaat primair door witruimte,
typografie, alignment, subtiele dividers en ritme. Een surface is niet
automatisch een card.

- **Open surface:** standaard pagina-opbouw zonder verplichte border, shadow of
  afgeronde container;
- **Section surface:** alleen een subtiele achtergrondverschuiving wanneer een
  inhoudelijk gebied onderscheiden moet worden;
- **Elevated surface:** voor Quick View, modals, dropdowns en popovers;
- **Card:** alleen voor een werkelijk zelfstandige functionele eenheid.

Er staat niet standaard een card rond ieder boek, filtergebied,
metadata-blok, formulierdeel of iedere detailsectie.

Belangrijke shell- en contentsurfaces mogen een subtiele organische textuur
dragen, bijvoorbeeld papiernerf, lichte plaster-/steenstructuur, zachte wolking
of zeer terughoudende marmering. Dit is een low-contrast surface treatment,
geen illustratie of dominante patroonlaag. Tekst, iconen en focusstates blijven
volledig leesbaar; textuur communiceert nooit betekenis, status, selectie of
interactie en blijft altijd ondergeschikt aan de content.

De donkere sidebar mag een rustige, Theme-aware textuur gebruiken wanneer die
diepte toevoegt zonder afleiding. Dat principe geldt in Light en Dark voor
zover de gekozen Theme-familie het logisch ondersteunt. Warme
contentachtergronden mogen dezelfde organische surface language gebruiken:
Bibliotheek Home relatief zichtbaarder, Mijn Bibliotheek subtieler en
rustiger. Dit zijn expressiviteitsvarianten binnen één Design System.

## 7. Spacing en informatiedichtheid

Status: **Definitief**.

De spacing-schaal is:

```text
4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 px
```

- `4–12 px`: interne UI-afstanden;
- `16–24 px`: componentniveau;
- `32–48 px`: sectieniveau;
- `48–64 px`: grote inhoudsovergangen.

> Ruimte is hiërarchie, niet decoratie.

Nieuwe secties worden eerst met witruimte onderscheiden en pas daarna
eventueel met een divider.

Er zijn drie functionele dichtheidsniveaus:

1. **Browse / Editorial** — onder andere grids, Collecties en Auteur-/Serie-
   detail;
2. **Standard Utility** — onder andere Boekdetail, Instellingen en standaard-
   formulieren;
3. **Compact Management** — onder andere selectiemodus, bulkbeheer,
   lijstweergaven en volgordebeheer.

Er komt in eerste instantie geen algemene gebruikersinstelling voor density.

## 8. Page shell en navigatie

Status: **Definitief**.

Desktop gebruikt standaard een **Classic Sidebar** van circa `220–224 px`, met
een donkere Theme-kleur, zichtbare labels, rustige groepering, subtiele active
state, Biblio-woordmerk bovenin en profiel/account onderaan. Er is geen zware
permanente topbar als standaard.

De desktopgebruiker kan de sidebar altijd inklappen tot icon rail en weer
uitklappen. Deze persoonlijke UI-voorkeur wordt onthouden. De rail behoudt alle
functies, actieve state en toegankelijke tooltips/focuslabels.

- tablet: rail of overlay/off-canvas, afhankelijk van beschikbare breedte;
- mobiel: off-canvas;
- responsive gedrag is hercompositie, niet simpel verkleinen.

### 8.1 Informatieniveaus en hoofdnavigatie

Biblio Home staat op platformniveau buiten één specifieke actieve Library
Context. Het ontsluit en wisselt Bibliotheken en activeert niet voortijdig de
volledige Library-sidebar. Na het openen van een Bibliotheek bestaan binnen de
actieve Library Context twee afzonderlijke hoofdbestemmingen:

- `Home`: Bibliotheek Home / Action Center; persoonlijk, selectief,
  actiegericht, discoverygericht en visueel expressiever;
- `Mijn Bibliotheek`: de volledige actieve catalogus; functioneel, rustig,
  scanbaar en informatiedichter.

`Home` staat conceptueel naast `Mijn Bibliotheek`, `Wat zal ik lezen?`,
`Collecties`, `Lezen` en andere Library-functies. `Wat zal ik lezen?` krijgt
binnen iedere Library een zelfstandige duidelijke ingang en is niet alleen een
Home-widget. Home is geen volledige catalogus en Mijn Bibliotheek
is geen Home-pagina. Historische mockups waarin deze functies onder één titel
staan, zijn geen actuele IA.

### 8.2 Gedeelde persoonlijke bestemmingen

De gerealiseerde App Shell toont `Mijn Bibliotheek`, `Verlanglijst` en
`Hierna lezen` als rustige, gelijkwaardige hoofdbestemmingen met precies één
actieve state. Op desktop, rail en off-canvas blijven labels en focusnamen
behouden. De links gebruiken server-generated canonical URLs; zichtbaarheid is
navigatie en nooit autorisatie.

## 9. Mijn Bibliotheek en views

Status: **Definitief**, behalve waar expliciet als werkwaarde aangegeven.

De standaard desktopdichtheid is **Gebalanceerd**. Werkwaarden rond `1440 px`:

- coverbreedte circa `148 px`;
- ongeveer 6 kolommen;
- horizontale spacing circa `24 px`;
- verticale spacing tussen rijen circa `36 px`;
- sectieafstand circa `40–48 px`.

Responsieve richting: groot desktop circa 5–7 kolommen, laptop 4–5, tablet 3–4
en mobiel doorgaans 2, of 1 bij grote accessibility scaling.

Mijn Bibliotheek ondersteunt uiteindelijk drie expliciete views:

- **Grid:** covergericht browsen;
- **Lijst:** snel scannen, metadata en beheer;
- **Boekenplank:** optionele user-selectable fysieke-kastweergave met ruggen
  naast elkaar en zichtbare titels.

**UI-REENTRY-02 — coverpresentatie (keuze B, goedgekeurd 2026-09-27):** in
covergerichte browsegrids is een uniform `2:3`-kader de standaard. Het echte
omslagbeeld blijft daarin volledig zichtbaar: niet bijsnijden, oprekken of
inhoudelijk aanvullen om het kader te vullen. De gebruiker kan expliciet een
weergave met de oorspronkelijke coververhouding kiezen. Die keuze verandert
alleen de presentatie en nooit het coverbestand, de bron, bibliografische
identiteit of metadata. In beide weergaven blijven titels en metadata rustig
uitgelijnd en scanbaar. Een ontbrekende cover behoudt het expliciete,
toegankelijke no-cover-object zonder verzonnen afbeelding of verhouding.

Dit is een product-/visual-designbesluit, geen implementatie- of visuele
runtimeacceptatie. De huidige `2:3`-CSS met `object-fit: cover` en zonder
keuze voor de oorspronkelijke verhouding is nog een implementatiewerkwaarde;
aanpassing daarvan vereist een afzonderlijke UI-slice.

Mijn Bibliotheek gebruikt **Functional / Refined Deep Library**: dezelfde
kleuren, typografie, tokens, surface language en componentfamilie als
Bibliotheek Home, met minder decoratieve diepte, cover-3D, zware shadows en
visuele effecten voor voorspelbare scanbaarheid op catalogusschaal.

### UI-REENTRY-03 — goedgekeurd visueel doelbeeld (2026-09-27)

Status: **productmatig goedgekeurd doelbeeld; nog geen implementatie of nieuwe
visuele runtime-GO**. De vergelijking gebruikte het werkelijke lokale
DDEV-scherm met een bewaakte, tijdelijke geauthenticeerde testbibliotheek.
Die fixture bevatte geen echte covers of bekende auteurs; hun werkelijke
rendering blijft bij uitvoering visueel te toetsen. De eerdere
UI-FOUND-01-scherm-GO en de CAT-UI-01-technische GO worden niet uitgebreid.

- De bestaande Ink Light-shell en de in §5 vastgelegde desktop- en mobiele
  rolwaarden bepalen typografie en dichtheid. De pagina behoudt een rustige
  titel, Library Context en één primaire actie. Grid blijft covergericht,
  Lijst compact; Search, Sort en `Meer laden` behouden hun echte
  catalogusgedrag. De mobiele zoektekst en wisactie mogen elkaar niet
  overlappen.
- Op desktop opent `Filters` een rechter rail **in de pagina**. Resultaten
  blijven daarnaast zichtbaar en herschikken binnen de beschikbare breedte.
  Op mobiel opent dezelfde bediening een afzonderlijk **filterblad**; een
  lange inline lijst duwt de catalogus daar niet omlaag. De keuze stelt geen
  nieuw responsive breakpoint vast.
- Actieve filterchips en `Alle filters wissen` blijven buiten de open rail
  of het filterblad direct bij de resultaten zichtbaar. Bij filtergroepen
  met meer opties dan de beknopte eerste reeks toont `Meer lezen` de overige
  opties binnen die groep; `Minder tonen` vouwt ze weer in. Een aangevinkte
  optie, actieve chip en catalogusquery blijven daarbij consistent. Groepen
  zonder verborgen opties krijgen deze bediening niet.
- De default voor echte covers blijft het in §9 vastgelegde uniforme
  `2:3`-kader met het volledige beeld zichtbaar; het no-cover-object blijft
  eerlijk. **Mijn Bibliotheek krijgt geen omslagverhoudingbediening** in
  toolbar, filterrail of mobiel filterblad. Een eventuele bediening voor de
  reeds besloten oorspronkelijke-verhoudingkeuze hoort hoogstens bij een
  afzonderlijk ontwerp voor persoonlijke voorkeuren; deze schermslice
  verzint daarvoor geen opslag of instellingenjourney.
- Lege, ladende en fouttoestanden behouden afzonderlijke, leesbare feedback.
  De feitelijke states, keyboard-/focusgedrag, tekstzoom en responsive
  hercompositie krijgen na implementatie een eigen runtime- en menselijke
  schermtoets.

Dit doelbeeld verfijnt de bestaande presentatie. Een ontbrekende
coverwaarde in de samengestelde catalogusrespons, nieuwe filteroptiebronnen
of andere ontbrekende data vragen een aparte functionele contractuitbreiding;
de visuele slice fabriceert die gegevens niet.

### UI-REENTRY-03 — menselijke visual-QA-delta Mijn Bibliotheek (2026-09-29)

Renée heeft de door UI-MYLIB-01 technisch gerealiseerde weergave visueel
beoordeeld en geeft **nog geen visuele scherm-GO**. De correctie op dit
schermdoelbeeld omvat precies deze drie presentatiepunten:

- **Filterrail:** de desktoprail oogt minder als een losse kaart en meer als
  onderdeel van de Soft Ivory-pagina. Border en radius treden terug; ruimte
  en eventueel een subtiele hairline dragen de scheiding.
- **Checkboxes:** filteropties gebruiken geen felblauwe standaardbrowsercontrol,
  maar een custom Biblio-stijl met een rustige Ink-outline. De geselecteerde
  state gebruikt Ink of het ochre-accent.
- **Oogknoppen op covers:** deze worden kleiner en rustiger, zonder dominant
  floating-action-buttongevoel. Op desktop zijn ze alleen zichtbaar bij
  hover of focus; op mobiel blijven ze direct bereikbaar. Hun functie,
  toegankelijke naam, toetsenbordbediening, zichtbare focus en bruikbaar
  aanraakdoel blijven behouden.

Deze visual-QA-delta verandert geen catalogusfunctie of eerder technisch
testresultaat. De drie correcties en de volledige weergave vragen na
uitvoering een nieuwe menselijke visuele schermbeoordeling; tot die tijd is
de visuele acceptatie van UI-MYLIB-01 / Mijn Bibliotheek open.

## 10. Page header en Quick View

Status: **Definitief**.

Een page header bevat doorgaans titel, korte context/telling en relevante
primaire actie(s) rechts. Op detail- en editpagina's heeft `Terug naar …` de
voorkeur. Breadcrumbs worden alleen gebruikt wanneer hiërarchie werkelijk
nuttig is.

Quick View is een optionele overlay die doorgaans van rechts inschuift. Het is
geen permanente split-view en reduceert de bibliotheekbreedte niet permanent.
De volledige detailpagina blijft bestaan. Quick View mag atmosferischer zijn,
met sterkere coverpresentatie, kerninformatie en snelle relevante acties.

## 11. Borders, radii, shadows en iconografie

Status: **Definitief qua richting**.

De richting is **Refined Deep Library**, bewust tussen Strak en Gebalanceerd.

- borders: circa `1 px` hairline, laag contrast en alleen functioneel;
- radii: `4 px` voor chips/compacte controls, `6 px` voor fields/dropdowns,
  `8 px` voor normale overlays en circa `10 px` voor grotere elevated overlays;
- shadows: een gebalanceerd/refined, zacht, gelaagd, gecontroleerd en
  contextafhankelijk systeem; niet op gewone content, zeer subtiel onder
  covers, licht bij dropdown/popover en zacht maar duidelijker bij Quick
  View/modal, sheets, elevated actions en geselecteerde panelen;
- covers krijgen geen sterke kunstmatige afronding.

Harde Material Design-drop shadows, zware card-elevation op ieder blok en
overmatig visueel zweven passen niet bij Deep Library. Bibliotheek Home mag
relatief meer elevation gebruiken dan Mijn Bibliotheek.

### 11.1 BookCoverPresentation

Featured en Catalog zijn presentatievarianten van één gedeelde visuele
covercomponent, conceptueel `BookCoverPresentation`, en geen losse
designsystemen. De gedeelde basis bewaakt later consistente fallback, theming,
responsive gedrag, accessibility, sizing en loading.

- **`featured` / Bibliotheek Home:** een rijkere, objectmatige behandeling met
  zachte contactshadow, subtiele fysieke diepte en optioneel een terughoudende
  rug/rand, paginarandillusie, perspective en gecontroleerde highlights. Faux
  3D is uitsluitend een enhancement; echte 3D-rendering is geen
  baselinevereiste en de cover blijft zonder effect volledig bruikbaar.
- **`catalog` / Mijn Bibliotheek:** vrijwel vlak, met hoogstens een subtiele
  shadow, vaste voorspelbare geometrie en zonder sterke perspective of
  opvallende spine-/3D-constructie. Grote catalogi krijgen geen zware
  3D-treatment per cover.

De in §9 gekozen coverregel is platformbreed voor `catalog`-presentatie:
Mijn Bibliotheek en andere covergerichte browsegrids, zoals toekomstige
Collection-, Author- en Series-views, gebruiken standaard het uniforme kader
met het volledige omslagbeeld. Waar een volwaardige covergrid bestaat, kan de
gebruiker de oorspronkelijke verhouding kiezen; compacte resultaatrijen en
kleine coververwijzingen hoeven daarvoor geen eigen schakelaar te krijgen.
Search en Verlanglijst tonen een echt omslag, wanneer hun autoritatieve
contract dat levert, volledig binnen hun beschikbare coverruimte. De
`featured`-variant en de goedgekeurde Book Detail-compositie blijven hun
eigen schaal en diepte houden; ook daar wordt echt omslagbeeld niet
bijgesneden of vervormd. Deze regel voegt geen coverbron, coverbeheer,
Atmosphere of nieuwe navigatie toe.

Bibliotheek Home volgt daarmee **Expressive Deep Library** en mag relatief
meer texture, shadow, fysieke coverwerking, decoratieve compositie, Atmosphere
en ademruimte inzetten. Mijn Bibliotheek volgt **Functional / Refined Deep
Library**. De identiteit blijft gelijk; alleen expressiviteit en
informatiedichtheid verschillen.

Iconografie volgt **Refined Outline**: standaard outline, circa `1.5–1.75 px`
stroke, meestal `18–20 px`, subtiel afgerond en niet overdreven speels of
technisch-geometrisch. Active state ontstaat door kleur, achtergrond of accent,
niet standaard door filled iconen.

Een klein custom domeiniconensetje is toegestaan waar nodig, bijvoorbeeld voor
Boek, Leesronde, Collectie, Exemplaar en Bibliotheek.

**UI-REENTRY-02 — iconenbasis (keuze B, goedgekeurd 2026-09-27):**
**Tabler Icons Outline** is de algemene icon library. Biblio past de iconen
toe in de al vastgelegde Refined Outline-richting: doorgaans `18–20px` en
circa `1.5–1.75px` lijngewicht. Het standaardlijngewicht van een library is
geen nieuwe Biblio-ontwerpwaarde. Labels en betekenis blijven leidend;
active state gebruikt kleur, achtergrond of accent en niet standaard een
filled-variant. Een beperkt eigen domeinsetje mag op dezelfde visuele maat en
lijnlogica aansluiten; de exacte glyphs en mappings blijven per toepasselijke
UI-slice te bepalen.

De huidige Biblio UI gebruikt een klein stel eigen SVG-maskiconen en nog
geen Tabler-library. Dit besluit wijzigt die implementatie niet en geeft
geen visuele scherm-GO of acceptatie van de referentieafbeelding.

## 12. Acties en interactiestates

Status: **Definitief**.

- **Primary:** maximaal één duidelijk dominante, terughoudende filled actie per
  context;
- **Secondary:** outlined of licht;
- **Tertiary:** tekst of icon;
- **Low-frequency:** onder `…`;
- **Destructive:** rood alleen wanneer relevant en pas prominent bij selectie
  of bevestiging.

States veranderen vooral kleur, border en subtiele surface, niet grootte, vorm
of positie. Hover is subtiel. Focus is duidelijk, keyboard-accessible,
contraststerk en Theme-afhankelijk; brass kan ondersteunen maar is niet
verplicht. Disabled blijft leesbaar maar teruggetrokken.

Tabs zijn typografisch, met underline/accent, niet standaard grote gevulde
blokken.

Een bibliotheektoolbar toont standaard Zoeken, Filters, Sorteren en een view
switch. Detailfilters verschijnen pas na openen. Actieve filters blijven als
compacte chips met `×` zichtbaar.

In selectie-/bulkmodus verschijnen checkboxes, geselecteerd-aantal en
bulkacties; normale hoveracties en eventueel drag handles verdwijnen.
Destructive bulkactie wordt pas prominent wanneer er een selectie is.

## 13. Formulieren

Status: **Definitief**.

Formulieren zijn rustig, open, helder en functioneel, met weinig
card-stapeling.

- labels blijven zichtbaar boven velden; placeholder is alleen hint/voorbeeld;
- toon `Optioneel` waar relevant;
- helptekst staat direct onder het veld;
- fouten staan bij het veld met tekst en visuele indicatie, nooit alleen kleur;
- langere formulieren mogen daarnaast een compacte foutensamenvatting tonen;
- datumvelden ondersteunen jaar-, maand- en dagprecisie zonder schijnprecisie;
- kleine bekende set: select; grote lijst: zoeken/autocomplete;
- notities en beschrijvingen gebruiken eenvoudige textarea's.

Formulierniveaus zijn **Compact Inline**, **Standard Form** en **Guided Flow**.
Een lang editformulier mag sectienavigatie links, hoofdformulier midden en
optionele context/preview rechts gebruiken, met open secties en dividers in
plaats van card-stapeling.

### 13.1 Gedeelde bibliografische discovery

De rijke gedeelde zoekflow van D-SEARCH-01 gebruikt een eigen scherm binnen de
App Shell en niet een krappe modal. Eén primair veld leidt naar zichtbaar
gescheiden Auteur- en Boek/Work-resultaten; Author→Works en Work→Editions
ontvouwen progressief zonder verplichte rigide wizardstappen. Kleine
bevestigingen, conflicten en refinements mogen wel in een dialog.

Lokale resultaten mogen vroeg bruikbaar zijn terwijl externe uitbreiding
zichtbaar doorlaadt. Loading, gedeeltelijke provideruitval, empty en retry zijn
tekstueel onderscheiden; reeds bruikbare lokale resultaten verdwijnen nooit
door provideruitval. Resultaatgroepen en hun `Meer …`-acties blijven keyboard-
en screenreader-navigeerbaar. De functionele entity-, identity-, ranking- en
consumerregels staan canoniek in
`docs/73-d-search-01-shared-bibliographic-search-model.md`.

## 14. Detailgrammatica

Status: **Definitief**.

### 14.1 Boekdetail

De **Identity Zone** bevat cover, grote editorial en vaak cursieve boektitel,
auteur, kernmetadata, maximaal één primaire actie en een Atmosphere hero.

Daaronder staat rustige context-/anchornavigatie met alleen canonieke secties,
bijvoorbeeld Overzicht, Leesgeschiedenis, Privénotities, Uitgave en Exemplaar.
Mockup-verzinsels worden geen productonderdeel.

Contentsecties staan open op het canvas met royale verticale spacing en weinig
cards. Een rechter contextkolom verschijnt alleen wanneer nuttig en gebruikt
open groepen/dividers. Leesgeschiedenis voelt meer als tijdlijn/leesronde en
minder als datatabel.

#### D-BOOK-01 — basiscompositie

Status: **Approved baseline**.

De huidige visuele baseline voor Boekdetail is goedgekeurd. Zij legt de
basiscompositie vast, zonder nieuw functioneel gedrag, nieuwe data of
implementatiedetails te bepalen:

- een compacte atmosferische hero, met op desktop een open sidebar;
- de hero bevat cover, titel, auteur, classificatie/tags, een korte
  introductie, primaire acties en een ondersteunende sfeerafbeelding;
- de hero mag sfeer geven, maar inhoud en cover niet overheersen;
- direct onder de hero staat rustige subnavigatie;
- onder de hero volgt een tweekolomsopbouw: links de persoonlijke/
  inhoudelijke boekervaring en rechts bibliografische, uitgave-, exemplaar- en
  collectiegegevens;
- de linkerinhoud bevat onder andere Overzicht, Leesgeschiedenis,
  Beoordelingen en Mijn notities;
- de rechterkolom gebruikt compacte informatieblokken voor Boekdetails,
  Uitgave, Exemplaar en In collecties.

Boekdetail moet primair voelen als een rijke boek-/leesomgeving, niet als een
database-record. De visuele richting blijft **Editorial Library × Serious
Utility**, met Soft Ivory, Ink en ingetogen messing/goud. Verdere
ontwerpvarianten mogen details verfijnen, maar wijzigen deze basisstructuur
niet zonder expliciete heropening van D-BOOK-01.

De goedgekeurde mockup `De verborgen bibliotheek – Boekdetailpagina.png` is
uitsluitend een visuele referentie en geen pixel-perfecte
implementatiespecificatie. De inhoud en copy daarin zijn illustratief, tenzij
elders al canoniek vastgelegd; de mockup introduceert geen nieuwe functionele
besluiten. Bestaande canonieke product- en domeinbesluiten blijven leidend.

UI-BOOK-01 implementeert deze compositie met uitsluitend het bestaande
geautoriseerde Item-detailcontract, Reading History en Private Notes. Een
neutrale token-gedreven hero draagt de visuele rol zolang er geen production
Atmospherebron is. Secties verschijnen alleen wanneer een afzonderlijk
goedgekeurd Book-Detail-contract daarvoor echte gegevens levert; afwezigheid
wordt niet met mockupcopy of clientdata gemaskeerd. BOOK-API-01 levert de echte
Library×Work-classificatie: Boeksoort staat als rustige chip in de hero en
Genres/Onderwerpen staan zonder dubbele weergave in Boekdetails. Lege
classificatie levert geen placeholder op. BOOK-API-02 levert de actieve
Collection memberships van het exacte Item als rustige tekstlijst in de
rechterkolom. BOOK-API-03 levert uitsluitend zichtbare, naar de huidige Library
gepubliceerde ratings/reviews als een open redactionele lijst in de
linkerkolom, met compacte rating, leesbare reviewtekst en bescheiden
identiteit/datum. Er zijn geen reviewcards, mutatieknoppen of client-side
privacybeslissingen. Beschrijving en overige niet-ondersteunde mockupvelden
blijven afwezig.

#### D-ADD-02 — Add Book vervolgcontext

Na succesvol Add Book is `Exemplaar verder beschrijven` een contextuele
deep-link binnen de bestaande Boekdetail-bewerkmodus: Boekdetail opent in
bewerkmodus met `Exemplaar` direct gefocust en de collector-fields zichtbaar/
geselecteerd. Opslaan behoudt de gebruiker op Boekdetail. Dit is geen aparte
collector-wizard of editor en specificeert geen Boekdetail-editmode of
collector-fieldimplementatie; de basiscompositie hierboven blijft ongewijzigd.

### 14.2 Collectie-detail

Kijkmodus is editorial, ruim en covergericht, met een grotere collection hero
en weinig beheercontrols. Beheermodus is compacter en taakgericht, met
management toolbar, toevoegen, Save/Cancel, selectie en drag handles.

`Collectie bewerken` betreft naam, beschrijving en omslag/details.
`Collectie beheren` betreft boeken toevoegen/verwijderen en volgorde. Tijdens
selectie verdwijnen drag handles tijdelijk en wordt destructive pas na selectie
prominent.

### 14.3 Auteur-detail

Een rustige auteurhero en het oeuvre staan centraal, met weinig beheer. Zonder
foto wordt een typografische hero, initialen of abstracte fallback gebruikt,
geen generieke stockfoto. Sectiehiërarchie:

1. In deze bibliotheek — primair;
2. Gewenste aanwinsten — secundair;
3. Archief — tertiair en terughoudend.

### 14.4 Serie-detail

De seriehero toont titel, auteur/context en bevestigde aanwezige delen rustig
maar prominent. Een compleetheidsclaim verschijnt alleen wanneer de bevestigde
Series-data dit volgens het actuele functionele contract ondersteunt. Een
bevestigde primaire volgorde is de structuur wanneer die bestaat; positie mag
ook tekstueel, contextueel of afwezig zijn en wordt dus niet als verplicht
nummer gepresenteerd. Kleine covers, status, vorm en locatie kunnen aanvullende
context geven. Gewenste aanwinsten en Archief blijven secundair.

De detailpresentatie voegt Bibliotheekdekking, persoonlijke leesdekking en
acquisitiegaps nooit samen tot één seriepercentage. In Library-context blijft
dekking/completeness op die ene Library gebaseerd en staat persoonlijke
leesdekking visueel en semantisch apart. In Mijn Biblio/Home mag cross-Library
beschikbaarheid naast persoonlijke leesdekking staan, zonder een afzonderlijke
Librarymetric te wijzigen. Aangekondigde titels staan apart van huidige gaps en
completeness; omnibusdekking mag `inhoudelijk gedekt` onderscheiden van `losse
uitgaven aanwezig`.

Boek-, Collectie-, Auteur- en Serie-detail delen shell, typografie, spacing,
surfaces, interactielogica en open compositie, maar krijgen een domeinspecifieke
hero. Er is geen universeel dashboard-detailtemplate.

## 15. Book Atmosphere

Status: **Definitief**, behalve waar expliciet anders aangegeven.

Book Atmosphere is uitsluitend presentatie. Het is geen Genre, Werkmetadata,
taxonomie of classificatie. Er komt geen Primary Genre of genre-resolver voor
hero-backgrounds.

### 15.1 Packs

- **Storyscape — standaard:** curated cinematic/editorial scenes met landschap,
  architectuur en licht; suggestief, niet een letterlijke verhaalillustratie;
- **Nature:** gecureerde natuur- en landschapsbeelden;
- **Book Cover:** dynamische treatments op basis van de echte cover, zoals
  blur, crop, kleurwash, gradient, extended edges of abstracte texture.

Voor gecureerde packs is `8–12` hoogwaardige backgrounds een richtwaarde; een
startidee is circa `10 + fallback`. Dit is geen universele limiet en dynamische
renderers kunnen een ander model gebruiken.

### 15.2 Automatische selectie en fallback

Automatische selectie is stabiel en niet random per page load. De
voorkeursrichting is visueel matchen op gecachete covereigenschappen zoals
kleurtemperatuur, brightness, saturation en accentkleur. Dit doet geen
inhoudelijke uitspraak over het boek.

Bij ontbrekende cover/analyse/match wordt een deterministische fallback gebruikt,
conceptueel bijvoorbeeld een hash op relevante Library-, Work- en Pack-context.

### 15.3 Persoonlijke keuze en overrides

Per gebruiker geldt één persoonlijk standaardpack per Library; automatische
cross-pack mix is niet de standaard. Via `… → Sfeer aanpassen` kan een gebruiker
per boek kiezen voor:

- mijn standaard + automatisch;
- een ander toegestaan pack + automatisch;
- een specifiek toegestaan pack + specifieke background/treatment.

Een gebruiker mag per boek vrij overriden met ieder pack dat in die Library is
toegestaan.

### 15.4 Governance en resolutie

Library Owner/Admin bepaalt toegestane packs, de Library-default en eventuele
publieke/Library-level per-book overrides. Een gebruiker kiest uit de
toegestane packs; persoonlijke keuzes beïnvloeden geen anderen of publieke
Library-presentatie.

Persoonlijke resolutie:

```text
persoonlijke per-book override
→ persoonlijk standaardpack voor deze Library
→ Library-default
→ Biblio-default
```

Publieke resolutie:

```text
Library per-book override
→ Library-default
→ Biblio-default
```

Als een Admin een persoonlijk gekozen pack uitschakelt, valt de gebruiker terug
op de Library-default. De voorkeur mag technisch bewaard blijven en bij opnieuw
toestaan automatisch terugkeren.

### 15.5 Sparse storage en coveranalyse

Alleen expliciete instellingen en overrides worden opgeslagen:

- Library settings;
- persoonlijke Library-packvoorkeur;
- persoonlijke per-book override;
- publieke Library per-book override.

Er wordt niet automatisch voor iedere `user × library × book`-combinatie een
record gematerialiseerd. Automatische sfeer hoeft niet per user-book opgeslagen
te worden. Coveranalyse wordt eenmaal uitgevoerd, gecachet en als klein visueel
profiel bewaard.

Custom packs zijn een latere capability waarmee een gebruiker eigen packs kan
maken. Zij vereisen afzonderlijke besluiten over mediaopslag,
rechten/governance, resizing, optimalisatie, thumbnails, limieten en WebP/AVIF
waar passend. Seasonal packs passen later binnen dezelfde architectuur zonder
de applicatiestructuur te veranderen.

## 16. Browse en manage

Status: **Definitief**.

Wanneer één pagina een kijk- en beheermodus heeft, is kijken ruimer,
editorialer en minder control-zwaar; beheren is compacter, taakgericht en
informatiedichter. De overgang is duidelijk, maar de gebruiker blijft visueel
in dezelfde Biblio-omgeving.

## 17. Responsive, accessibility en motion

Status: **Definitief qua principe**; exacte waarden zijn nog open.

Responsive betekent hercompositie:

- mobiel gebruikt off-canvas navigatie, minder gridkolommen en een vrijwel
  full-screen Quick View/sheet;
- toolbars mogen logisch breken en secundaire metadata verschuift omlaag;
- tablet gebruikt rail of overlay; context rails mogen verdwijnen.

Alle keuzes worden technisch getoetst op WCAG-contrast, keyboard focus,
zoom/scaling, screenreader-logica, non-color statuscommunicatie en geschikte
touch targets. Rustige styling mag focus, disabled state of status nooit
onduidelijk maken.

Texture, shadow en coverdiepte zijn decoratieve enhancements en nooit nodig om
status, selectie, interactie, beschikbaarheid of navigatie te begrijpen.
Content blijft zonder deze effecten bruikbaar, contrast blijft leidend en
focusstates blijven expliciet. Echte complexe 3D is geen baselinevereiste;
latere CSS-/DOM-faux-3D is alleen aanvaardbaar wanneer die performant en
onderhoudbaar blijft.

Motion blijft subtiel, rustig en functioneel en respecteert reduced motion.
Exacte durations, easing en reduced-motion-details zijn nog open.

### 17.1 D-UI-GAP-01 — gedeelde visuele productbaseline

Status: **Definitief als implementatiebaseline**.

De canonical Page Shell bezit op Biblio-applicatiepagina's het volledige canvas
onder eventuele operationele browser-/beheerchrome. Publieke WordPress
site-header/footer en door het block theme inline opgelegde buitenruimte worden
op zo'n pagina niet rond de applicatie herhaald. Dit is pagina-gescopeerd: het
wijzigt geen publieke template en Elementor blijft uitsluitend de gewone Page
Shell met één Biblio-shortcode.

De gedeelde minimumkwaliteit is:

- één Classic Sidebar/rail/off-canvas patroon voor desktop, tablet en mobiel;
- echte iconen of toegankelijke icon-markers, geen tijdelijke letterblokken;
- titelgedreven editorial hierarchy, met utility-controls en metadata in de
  sans-serif rol;
- één herbruikbare covergeometrie per context en een expliciet no-cover object
  dat ontbrekende data toont zonder die data te verzinnen;
- Grid en List als werkende presentaties van hetzelfde autoritatieve resultaat;
- niet-geïmplementeerde views en controls blijven zichtbaar disabled of worden
  eerlijk als niet beschikbaar uitgelegd; en
- gedeelde action hierarchy, empty/error/loading/status states, focus, targets,
  reflow en reduced-motion-regels voor Mijn Bibliotheek en flows zoals Add Book.

Deze historische UI-FOUND-01-baseline canoniseerde destijds geen exacte
kleurwaarden, productiefonts, icon library, coverratio-keuze,
Atmosphere-assets of Bookshelf-gedrag. De coverpresentatie is later door
UI-REENTRY-02 in §§9 en 11.1 besloten, de productiefonts in §5 en de
iconenbasis in §11; de
technische uitvoering en volledige visuele acceptatie daarvan zijn nog niet
geleverd. De technische closure en het bewijs van UI-FOUND-01 staan in
[`docs/54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md`](54-ui-found-01-app-shell-and-mijn-bibliotheek-visual-baseline.md).

## 18. Nog open en werkwaarden

De volgende punten zijn bewust niet definitief:

- exacte Aubergine/Petrol Light/Dark-hexwaarden;
- WCAG-validatie van werkelijk gerenderde states en de nog open combinaties;
- productie-fontlevering en validatie van Cormorant Garamond + Source Sans 3;
- afwijkende gewichten en regelafstanden voor bijzondere typografische rollen;
- exacte custom Biblio-icons en iconmapping per toepasselijke UI-slice;
- Storyscape-assets, Nature-assets en Book Cover-treatments;
- exact cover-matching-algoritme;
- custom en seasonal Atmosphere Packs;
- responsive breakpoints en precieze waarden per breakpoint;
- motion durations, easing en reduced-motion-details.

Deze lijst is geen toestemming om ontbrekende waarden of functionaliteit lokaal
in te vullen.

## 19. Implementatieregel

Status: **Definitief**.

Nieuwe componenten zijn vanaf het begin themeable by design voor Ink,
Aubergine en Petrol in Light, Dark en System. Zij gebruiken centrale
semantische tokens en introduceren geen lokale Elementor-designregels als
tweede waarheid.

Visual state vervangt nooit server-side autorisatie of domeinregels. Biblio
Core en zijn application boundaries blijven leidend; Elementor blijft alleen
de Page shell en styling blijft presentatie.

Nieuwe pagina's mogen functioneel een eigen compositie hebben, maar wijken niet
zonder expliciete ontwerpbeslissing af van Deep Library, typografie, Theme,
Appearance, Atmosphere, Soft Ivory, page shell/sidebar, spacing/density, open
composition/cardlogica, borders/radii/shadows, iconografie, interactiestates,
formulieren of responsive gedrag.
