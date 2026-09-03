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

Theme beïnvloedt onder andere navigatie- en interactieve kleuren, hover/active
states, subtiele washes, focusdetails en Dark Mode-surfaces.

Voor later geparkeerde Themes zijn onder andere Forest en Golden Amber.
Mogelijke latere Atmosphere Packs zijn Abstract, Nocturne, Seasonal en Custom.
Deze uitbreidingen zijn nog geen uitgewerkt implementatiecontract.

## 4. Light, Dark en semantische kleurrollen

### 4.1 Light — Soft Ivory

Status: **Definitief qua richting**.

De lichte hoofdwerkruimte gebruikt **Soft Ivory**: warmer dan zuiver wit, maar
niet beige of sepia. Zij contrasteert met de diepe sidebar, ondersteunt
kleurrijke covers en blijft rustig tijdens lange gebruikssessies. Exacte
hexwaarden zijn nog niet definitief.

### 4.2 Dark

Status: **Definitief qua principe**.

Dark Mode is geen simpele inversie. Iedere Theme-familie levert eigen waarden
voor page, surfaces, navigatie, interactie, borders en tekst. Ink Dark,
Aubergine Dark en Petrol Dark blijven herkenbaar verschillend, behouden
surface-niveaus, worden niet volledig zwart en laten covers spreken.

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
grotendeels Theme-onafhankelijk. Exacte waarden blijven werkwaarden totdat alle
combinaties op toegankelijk contrast zijn gevalideerd.

## 5. Typografie

Status: **Definitief qua richting**.

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

- voorkeurs-serif: **Cormorant Garamond**;
- voorkeurs-sans: **Source Sans 3**;
- alternatieve sans: **Inter**.

De combinatie Cormorant Garamond + Source Sans 3 is nog niet definitief in de
echte productie-UI gevalideerd. Gewichten, type scale en line-heights zijn nog
open.

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

De mogelijke gebruikerskeuze tussen uniforme en oorspronkelijke coverratio is
nog open.

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
- shadows: niet op gewone content; zeer subtiel onder covers, licht bij
  dropdown/popover en zacht maar duidelijker bij Quick View/modal;
- covers krijgen geen sterke kunstmatige afronding.

Iconografie volgt **Refined Outline**: standaard outline, circa `1.5–1.75 px`
stroke, meestal `18–20 px`, subtiel afgerond en niet overdreven speels of
technisch-geometrisch. Active state ontstaat door kleur, achtergrond of accent,
niet standaard door filled iconen.

Een klein custom domeiniconensetje is toegestaan waar nodig, bijvoorbeeld voor
Boek, Leesronde, Collectie, Exemplaar en Bibliotheek. De exacte icon library en
custom set zijn nog open.

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

De seriehero toont titel, auteur/context en compleetheid/aanwezige delen rustig
maar prominent. Volgorde is de primaire structuur, met positienummers, kleine
covers, status, vorm en locatie. Gewenste aanwinsten en Archief blijven
secundair.

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

Motion blijft subtiel, rustig en functioneel. Exacte durations, easing en
reduced-motion-gedrag zijn nog open.

## 18. Nog open en werkwaarden

De volgende punten zijn bewust niet definitief:

- exacte Light/Dark-hexwaarden voor Ink, Aubergine en Petrol;
- brass-kleur en WCAG-validatie van alle combinaties;
- productievalidatie van Cormorant Garamond + Source Sans 3;
- fontweights, type scale en line-heights;
- icon library en custom Biblio-icons;
- uniforme of oorspronkelijke coverratio als gebruikerskeuze;
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
