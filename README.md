# Biblio V2

Lokale ontwikkelomgeving voor Biblio V2.

## Baseline

- Biblio: v2.001
- WordPress: 7.0.2
- PHP: 8.3
- MariaDB: 10.11
- DDEV
- Docker-provider op macOS: OrbStack

## Lokale URL

https://biblio-v2.ddev.site

## Biblio Core setup en tests

Installeer na een verse checkout eerst de vastgelegde Composer-dependencies:

```bash
ddev composer --working-dir=web/wp-content/plugins/biblio-core install
```

Voer de tests uit vanaf de projectroot:

```bash
./scripts/test-biblio-core-unit.sh
./scripts/test-biblio-core-integration.sh
./scripts/test-biblio-core-all.sh
```

De integratietest gebruikt uitsluitend de wegwerpdatabase
`biblio_core_test`. Het script bouwt die database per run opnieuw op en
verwijdert haar ook wanneer de test faalt. De normale DDEV-database `db`
wordt niet als testdatabase gebruikt.

## Architectuur

Biblio V2 wordt gebouwd als één WordPress-site met een custom Biblio Core-plugin.

Biblio Core is eigenaar van business rules, autorisatie, bibliotheekcontext, lifecycle-transities en integriteitsregels.

Biblio-owned custom tables zijn de in Fase 0 bewezen baseline voor
integriteits-, scope-, transactie- en concurrencygevoelige Core-data.
Persistence blijft per domein beoordeeld volgens ADR-004.

De formeel ondersteunde Core-schemahistorie begint op schema baseline `1000`.
Productversie `v2.001`, plugin/packageversie `2.1.0` en schemaversie zijn
onafhankelijk. Zie ADR-005. Pluginactivation voert de formele migration en
schema-healthcheck uit. Tijdens normale runtime controleert Core vroeg de
schemaversie en gebruikt het een kortlevende health-cache; alleen een gezonde
runtime publiceert de getypeerde application-serviceboundary.

F1.4 laat de bestaande production application services hun actor uitsluitend
server-side via WordPress bepalen. Caller-input kan wel een Library als target
selecteren, maar nooit de actor of een vertrouwde `LibraryContext` leveren.
User-owned en Library-scoped reads en Reading-startflows gebruiken daardoor
dezelfde authenticated identity; concrete repositories blijven intern aan de
composition root. REST, Abilities, Elementor, JetEngine en UI-adapters zijn nog
niet gebouwd.

F1.5 laat een ReadingRound uitsluitend via een gevalideerde concrete bron
starten. Voor een Library Item wordt Work via Item → Edition → Work afgeleid;
voor een ExternalLoan komt Work uit de actieve, door de actor bezeten lening.
Geen ondersteunde production-call accepteert een losse combinatie van Work en
ReadingSource. De database behoudt de ADR-004-baseline met XOR, foreign keys en
uniekheid per gebruiker + concrete bron; de repository controleert aanvullend
dat de bron werkelijk bij het afgeleide Work hoort.

F1.6 laat publiek geldige domainstate aansluiten op de huidige persistence:
persistente Core-ID's zijn niet leeg, geldige UTF-8 en maximaal 191 tekens;
ExternalLoan, Item, Library en ReadingRound zijn in de huidige technische scope
active-only; en aanvullende membershippermissions zijn een geordende lijst van
unieke, niet-lege UTF-8-identifiers die zonder normalisatie roundtrippen.
Productversie `v2.001`, plugin/packageversie `2.1.0` en schemabaseline `1000`
blijven onafhankelijke versiedimensies.

## Repository

WordPress Core, lokale secrets, uploads en gelicentieerde pluginpackages worden niet in Git opgeslagen.
DDEV-configuratie, Biblio Core-code en reproduceerbare projectconfiguratie worden wel in Git opgeslagen.
