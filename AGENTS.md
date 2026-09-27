# AGENTS.md — Biblio V2

Dit document is het operationele uitvoeringscontract voor coding-agents binnen de Biblio-repository. Projectbrede AI-orkestratie en de keuze tussen Chat, Work, Codex en eventuele specialistagents worden buiten deze repository bepaald.

## Source of truth

Before changing Biblio behavior, read:

1. `docs/00-product-decision-index.md` as a route to the sources, not as a substitute for them
2. `docs/00-current-state.md`
3. the relevant part of `docs/01-functional-design.md`
4. `docs/02-architecture.md`
5. applicable ADRs
6. `docs/03-scope-and-deferred.md`
7. `docs/06-testing-and-acceptance.md`

Do not infer current product behavior from historical source files when the canonical docs contain a later decision.

## Product and UX decision preflight for Work

Vóór ieder substantieel product-, functioneel of UX-besluit: raadpleeg eerst
`docs/00-product-decision-index.md`, lees daarna de gelinkte canonieke bronnen
zelf, benoem kort wat al `SETTLED`, `DEFERRED` of `SUPERSEDED` is, en leg alleen
echte `OPEN` keuzes aan Renée voor. Benoem bronconflicten voordat een besluit
wordt genomen. Verifieer implementatieclaims tegen actuele code en evidence.
Chatgeschiedenis mag ondersteunende context zijn, maar vervangt geen canonieke
repositorybeslissing.

Na een afgeronde product- of designuitkomst volgt een korte decision delta:
**Nieuw**, **Gewijzigd**, **Open**. Werk de juiste canonieke documenteigenaar
bij, pas release/scope alleen aan indien nodig, werk de decision index bij en
actualiseer acceptance-evidence indien van toepassing. Pas daarna is de
uitkomst duurzaam afgerond; maak geen los handoffdocument als een bestaand
canoniek document eigenaar is.

## Preflight

Classificeer de technische taak vóór wijzigingen als Light, Medium of High.

- Light: één uitvoerder, gerichte tests.
- Medium: één primaire uitvoerder; aparte review waar zinvol.
- High: analyse/review expliciet scheiden van implementatie; één primaire implementatie-eigenaar en onafhankelijke review waar tooling dit ondersteunt.

## Reviewer

Als geen afzonderlijke reviewer-agent beschikbaar of zinvol is, voer na implementatie een expliciete tweede reviewpass uit alsof je een onafhankelijke reviewer bent. Controleer daarbij de uiteindelijke diff opnieuw tegen requirements, architectuur, security en regressierisico.

## Non-negotiable engineering rules

- `Biblio Core` owns domain rules and authorization.
- UI visibility is never authorization.
- Every library-scoped operation requires explicit Library Context and authorization.
- Every user-owned operation requires authenticated ownership checks.
- A library reference on user-owned data never transfers ownership to the library.
- Do not place core business logic in Elementor, Code Snippets or direct JetFormBuilder mutations.
- Do not silently mutate related records merely to make an operation succeed.
- Preserve historical truth and known date precision.
- Do not change unrelated files.
- Do not alter existing user data unless the requested migration explicitly requires it.
- Add/adjust tests before declaring domain behavior complete.
- Run relevant validation and report assumptions instead of inventing missing product rules.

## Current v2.001 focus

`Privébibliotheek` is the only selectable and fully supported Library type. `Uitleenbibliotheek` is visible as a future disabled choice only.

Media scope is physical books only.

Biblio-owned custom tables are the proven Fase-0 baseline for integrity-, scope-, transaction- and concurrency-sensitive Core-data. Persistence remains selectable per domain; see `docs/decisions/ADR-004-fase-0-persistence-and-reading-sources.md`.
