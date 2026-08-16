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

## Architectuur

Biblio V2 wordt gebouwd als één WordPress-site met een custom Biblio Core-plugin.

Biblio Core is eigenaar van business rules, autorisatie, bibliotheekcontext, lifecycle-transities en integriteitsregels.

De definitieve keuze tussen CPT, CCT en eigen databasetabellen wordt pas gemaakt na de Fase-0 verticale spike.

## Repository

WordPress Core, lokale secrets, uploads en gelicentieerde pluginpackages worden niet in Git opgeslagen.
DDEV-configuratie, Biblio Core-code en reproduceerbare projectconfiguratie worden wel in Git opgeslagen.
