# ADR-005 — Formele Biblio Core-schema- en migrationbaseline

Status: Accepted

Scope: Biblio V2 v2.001 / F1.1

## Context

De interne schemaversies 1–5 zijn tijdens de Fase-0-spike ontstaan doordat
Library, personal-Library designation, catalogusrecords, ExternalLoan en
ReadingRound achtereenvolgens aan één cumulatieve schema builder werden
toegevoegd.

Die versies waren ontwikkelstappen en vormen geen ondersteunde
productie-upgradehistorie. De in ADR-004 geaccepteerde complete
Fase-0-structuur is het eerste formeel ondersteunde Biblio Core-schema.

## Decision

### Onafhankelijke versies

Biblio gebruikt drie gescheiden versiedimensies:

- productversie: `v2.001`;
- Core plugin/packageversie: `2.1.0`;
- databaseschemaversie: een onafhankelijke oplopende integer.

Plugin/packageversie `2.1.0` verwacht bij deze baseline databaseschemaversie
`1000`. Productversie en pluginversie worden niet als migration counter
gebruikt.

### Formele baseline 1000

De formele schemahistorie begint op versie `1000`. Deze nummering maakt
expliciet dat de oude spikeversies 1–5 geen production migration sources zijn.
Een toekomstige forward migration gebruikt een volgende expliciete versie,
bijvoorbeeld `1000 -> 1001`.

De formele version option is `biblio_core_schema_version`. De oude
`biblio_core_library_schema_version` is alleen een legacy-signaal. Een
installatie met uitsluitend die oude option wordt niet stilzwijgend
geadopteerd of gemigreerd; een developmentinstallatie wordt opnieuw vanaf de
formele baseline opgebouwd.

### Baseline-installatie

Baseline-installatie is alleen toegestaan wanneer geen Biblio Core-tabellen
bestaan. De installer maakt de acht geaccepteerde Fase-0-tabellen met hun
foreign keys, CHECK-constraints, unique indexes, generated columns en
`RESTRICT`-regels.

De baseline-DDL gebruikt geen `CREATE TABLE IF NOT EXISTS`. Een gedeeltelijke
of onbekende bestaande toestand wordt daardoor niet als lege installatie
behandeld.

### Ordered forward migrations

Iedere toekomstige migration heeft expliciet:

1. source- en targetversie;
2. precondition;
3. gerichte wijziging;
4. postcondition.

De runner registreert de targetversie pas na een geslaagde postcondition.
Migration steps vormen één ononderbroken, oplopende keten vanaf versie 1000.

Omdat MariaDB-DDL niet volledig transactioneel is, moet iedere migration zelf
bepalen welke bekende gedeeltelijke toestand veilig retryable is. Onbekende
tussentoestanden falen gesloten.

### Schema-health en drift

De opgeslagen version option is niet voldoende bewijs van gezondheid. De
healthcheck valideert gericht de tabellen, essentiële kolommen, engines,
indexes, foreign keys, CHECK-constraints, generated expressions en
`RESTRICT`-regels waarop de Core-baseline vertrouwt.

Bij actuele versie plus onverwachte drift:

- wordt het schema unhealthy gerapporteerd;
- vermeldt diagnostiek wat ontbreekt of afwijkt;
- wordt geen onbekende automatische reparatie uitgevoerd;
- blijft bestaande data ongemoeid.

Een bekende driftcase kan later alleen via een expliciete, geteste
repair/migration worden ondersteund.

### WordPress lifecycle

F1.1 levert de migration infrastructure en integratietests, maar sluit haar
nog niet aan op pluginactivation, normale pluginboot of WP-CLI. Die wiring is
onderdeel van F1.3.

## Consequences

- toekomstige schemawijzigingen worden append-only als expliciete migration
  steps toegevoegd; de oude baseline-DDL wordt niet stilzwijgend herschreven;
- bestaande data vanaf formele baseline 1000 moet bij iedere ondersteunde
  forward migration behouden blijven;
- pre-baseline developmentinstallaties hebben geen gegarandeerd upgradepad;
- onverwachte drift kan operationele interventie vereisen, maar wordt niet
  door een gokreparatie verergerd;
- product-, plugin- en schemaversies kunnen onafhankelijk evolueren.
