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

De definitieve keuze tussen CPT, CCT en eigen databasetabellen wordt pas gemaakt na de Fase-0 verticale spike.

## Repository

WordPress Core, lokale secrets, uploads en gelicentieerde pluginpackages worden niet in Git opgeslagen.
DDEV-configuratie, Biblio Core-code en reproduceerbare projectconfiguratie worden wel in Git opgeslagen.
