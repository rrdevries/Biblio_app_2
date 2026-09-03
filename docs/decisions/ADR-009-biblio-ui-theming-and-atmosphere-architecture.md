# ADR-009 — Biblio UI theming and atmosphere architecture

Status: Accepted

Scope: Biblio V2 UI/theming architecture

## Context

Biblio moet een themable, personaliseerbare en schaalbare interface bieden aan
meerdere gebruikers binnen meerdere Libraries. De gedeelde presentatie van een
Library en de persoonlijke ervaring van een gebruiker moeten naast elkaar
kunnen bestaan zonder presentatievoorkeuren te verwarren met domeinwaarheid,
Genre, Werkmetadata of autorisatie.

Zonder één structureel model zouden Theme, licht/donker-weergave en boekhero-
sfeer gemakkelijk tot één configuratie versmelten. Hardcoded kleuren en lokale
Elementor-styling zouden componenten fragmenteren, Dark Mode reduceren tot een
inversie en toekomstige uitbreiding met nieuwe Themes of Atmosphere Packs
onnodig duur maken.

## Decision

### Designfilosofie en componentarchitectuur

**Deep Library** is de overkoepelende ontwerpfilosofie: Editorial Library ×
Serious Utility. Het is geen Theme en geen Atmosphere Pack.

Nieuwe componenten zijn themeable by design en gebruiken centrale semantische
design tokens in plaats van hardcoded componentkleuren. Open composition is de
standaard; een card wordt alleen gebruikt voor een werkelijk zelfstandige
functionele eenheid. De Classic Sidebar is de standaard desktop-shell en kan
door de gebruiker worden ingeklapt; die collapse-state is een persoonlijke
UI-voorkeur.

Elementor blijft Page shell. Lokale Elementor/CSS-styling wordt geen tweede
design-systemwaarheid en visual state wordt nooit gebruikt als autorisatie of
domeinregel.

### Appearance en Theme

Appearance en Theme zijn onafhankelijke dimensies:

- Appearance: `Light`, `Dark`, `System`;
- Theme-families: `Ink` (default), `Aubergine`, `Petrol`.

Iedere Theme-familie levert semantische tokenwaarden voor Light en Dark. Dark
Mode is geen simpele inversie en behoudt afzonderlijke page-, surface- en
elevated waarden.

### Atmosphere

Atmosphere Pack is een derde, onafhankelijke presentatiedimensie en staat los
van Appearance, Theme en Genre. Atmosphere is geen Werkmetadata, taxonomie of
classificatie. Er wordt geen Primary Genre geïntroduceerd om hero-backgrounds
te sturen.

De eerste packs zijn:

- `Storyscape` — standaard;
- `Nature`;
- `Book Cover`.

Automatische selectie binnen een pack is stabiel. Coveranalyse wordt eenmaal
uitgevoerd, gecachet en als klein visueel profiel bewaard. Bij ontbrekende
analyse of match wordt een deterministische fallback gebruikt. Automatische
cross-pack random mix is niet de standaard.

### Governance en persoonlijke ervaring

Library Owner/Admin bepaalt welke packs beschikbaar zijn, kiest de Library-
default en kan afzonderlijke publieke/Library-level per-book overrides
instellen.

Een gebruiker kiest per Library één persoonlijk standaardpack uit de
toegestane packs en kan een persoonlijke per-book override instellen. Die
persoonlijke keuzes beïnvloeden geen andere gebruikers en geen publieke
Library-presentatie.

Wanneer een pack niet langer is toegestaan, valt de effectieve keuze terug op
de Library-default. De eerdere persoonlijke voorkeur mag bewaard blijven zodat
zij bij opnieuw toestaan kan terugkeren.

De exacte technische identifier achter `book` en de concrete persistencevorm
worden niet door deze ADR bepaald. De implementatie moet de bestaande Library-
en user-ownershipgrenzen en server-side application boundaries respecteren.

### Override-resolutie

Persoonlijke weergave:

```text
user-book override
→ user-library pack preference
→ Library default
→ Biblio default
```

Publieke weergave:

```text
Library-book override
→ Library default
→ Biblio default
```

### Sparse storage

Alleen expliciete voorkeuren en overrides worden opgeslagen. Automatische
atmosphere wordt niet voor iedere `user × library × book`-combinatie
gematerialiseerd. Het opslagmodel omvat logisch alleen Library settings,
user-Library pack preference, persoonlijke per-book override en publieke
Library per-book override. Deze ADR kiest geen generieke postmeta/usermeta-
implementatie en geen concrete tabelstructuur.

## Alternatives considered

### Eén vaste globale kleurstijl

Niet gekozen omdat Biblio meerdere kleuridentiteiten en Light/Dark/System moet
ondersteunen zonder componentforks.

### Theme en Atmosphere als één concept

Niet gekozen omdat interfacekleur en inhoudelijke hero-presentatie onafhankelijk
moeten kunnen variëren.

### Genre of Primary Genre als Atmosphere-driver

Niet gekozen omdat presentatie daarmee cataloguswaarheid zou vervuilen en een
inhoudelijke classificatie zou worden misbruikt voor styling.

### Automatische cross-pack random mix

Niet gekozen omdat de gebruikerservaring coherent en de selectie stabiel moet
blijven.

### Automatische materialisatie per user × book

Niet gekozen vanwege onnodige opslaggroei en omdat deterministische selectie
zonder expliciete preference kan worden gereproduceerd.

### Alles in WordPress postmeta/usermeta

Niet gekozen als architectuurregel. De persistencevorm moet later per concreet
contract worden beoordeeld en mag ownership, integriteit en application
boundaries niet omzeilen.

### Elk UI-blok als card

Niet gekozen omdat dit de editorial compositie en informatiedichtheid
verzwakt. Open composition is de standaard.

### Sidebar alleen responsief laten inklappen

Niet gekozen omdat desktop collapse een expliciete, onthouden persoonlijke
voorkeur is en niet alleen een gevolg van viewportbreedte.

## Consequences

Positief:

- Appearance, Theme en Atmosphere hebben duidelijke verantwoordelijkheden;
- persoonlijke vrijheid blijft binnen Library-governance;
- nieuwe Themes en packs zijn later uitbreidbaar;
- componenten blijven centraal themeable;
- Genre en Werkmetadata blijven vrij van presentatiewaarheid;
- sparse storage voorkomt automatische combinatorische recordgroei.

Kosten en complexiteit:

- er is een centrale tokenarchitectuur nodig;
- persoonlijke preferences en overrides vereisen veilige server-side grenzen;
- Theme × Appearance × Atmosphere-combinaties moeten worden getest;
- toegankelijkheid moet per Theme en mode worden bewaakt;
- custom packs vereisen later afzonderlijke media-, storage- en governance-
  besluiten;
- coveranalyse en deterministic fallback vragen caching en reproduceerbaarheid.

## Relation to the Design System

Deze ADR legt de structurele keuzes en rationale vast. De levende visuele en
UI-specificatie, inclusief werkwaarden en expliciete open punten, staat in
[`docs/31-biblio-design-system.md`](../31-biblio-design-system.md).

## References

- [Biblio V2 Design System](../31-biblio-design-system.md)
- [Current state](../00-current-state.md)
- [Biblio architecture](../02-architecture.md)
- [ADR-003 — Biblio Core owns domain logic](ADR-003-biblio-core-owns-domain-logic.md)
- [Elementor Vertical Slice 1A build plan](../20-elementor-vertical-slice-1a-build-plan.md)
