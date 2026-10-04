# Biblio V2

Lokale ontwikkelomgeving voor Biblio V2. De huidige productlijn is v2.001.

## Vereisten

- DDEV met een werkende Docker-provider
- Composer voor de vastgelegde PHP-afhankelijkheden
- Node.js voor de JavaScript- en browsertests

## Lokale start

```bash
ddev start
ddev composer --working-dir=web/wp-content/plugins/biblio-core install
./scripts/bootstrap-wordpress.sh
ddev wp plugin activate biblio-core
```

De lokale URL is `https://biblio-v2.ddev.site`.

## Tests

De volledige Core-controle is beschikbaar via:

```bash
./scripts/test-biblio-core-all.sh
```

Gerichte UI- en browsertests staan onder `web/wp-content/plugins/biblio-ui/tests/` en `e2e/`. Gebruik voor browsertests de bewaakte tijdelijke fixture onder `scripts/e2e-fixture.sh` en controleer na afloop dat deze is opgeruimd.

## Architectuur

Biblio V2 draait als één WordPress-site. `biblio-core` beheert domeinregels, autorisatie, bibliotheekcontext en persistente gegevens. `biblio-ui` verzorgt de presentatie en gebruikt de Core-contracten. De versies van product, schema en plugins worden onafhankelijk beheerd.

De publieke repository bevat code en reproduceerbare configuratie. Projectbesluiten, werkregistratie, interne documentatie, Obsidian-vaultbestanden, lokale geheimen, uploads en gelicentieerde pluginpakketten blijven lokaal.
