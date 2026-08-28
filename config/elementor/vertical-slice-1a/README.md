# Elementor Vertical Slice 1a configuration

This directory is the versioned configuration boundary for the first
Elementor vertical slice. It contains the genuine Page/Kit export created in
implementation step 9. Elementor remains a thin shell around the Biblio UI
shortcode; all application behavior stays in the `biblio-ui` plugin and
Biblio Core.

## Tested runtime contract

- WordPress: 7.0.2
- PHP: 8.3.31
- active theme: Twenty Twenty-Five 1.5
- Biblio Core: 2.1.0, active
- Elementor: 4.2.3, active
- Elementor Pro: 4.2.2, active

The original runtime prerequisite was verified locally through WP-CLI on
2026-08-24. The step-9 export and clean import proof were run on 2026-08-28
with these exact versions. No Elementor account, Pro license connection or
vendor cloud feature was needed.

## Reproducible local installation

Elementor Core comes from the WordPress plugin repository at the exact pinned
version:

```bash
ddev wp plugin install elementor --version=4.2.3 --activate
```

Place the licensed Elementor Pro archive at this ignored local path:

```text
.local/packages/elementor-pro-4.2.2.zip
```

Validate and install that exact archive from the DDEV-mounted project root:

```bash
unzip -t .local/packages/elementor-pro-4.2.2.zip
ddev wp plugin install /var/www/html/.local/packages/elementor-pro-4.2.2.zip --activate
```

Verify the installed versions and activation state:

```bash
ddev wp plugin get elementor --fields=name,status,version --format=json
ddev wp plugin get elementor-pro --fields=name,status,version --format=json
```

Do not substitute or automatically upgrade either version for this slice.
The `.local/` directory is ignored by Git. Never copy the Pro archive, a
license key, token, account cookie or other credential into tracked files.
Plugin activation is separate from Elementor account or license activation;
no account or license connection is required for this runtime prerequisite.

## Page/Kit artifact

`biblio-vertical-slice-1a.zip` is an official Elementor CLI export with:

- one published ordinary WordPress Page named `Mijn Bibliotheek`;
- canonical slug `mijn-bibliotheek`;
- one outer Elementor container;
- one Shortcode widget with exactly `[biblio_library_app]`;
- Page setting `hide_title=yes`;
- Kit Page Title Selector `h1.wp-block-post-title`, matching the tested
  Twenty Twenty-Five theme so the component owns the only visible page H1;
- the relevant Site Settings/Kit configuration and no Elementor templates,
  Loop Grid, dynamic query, form or visibility configuration.

Artifact SHA-256:

```text
4fcaa0aec73566e5313ed4df99e274ca19e4f22a2ae896b6614c18167c67723a
```

The archive uses the non-routable source URL
`https://biblio-export.invalid`. Source post IDs inside an Elementor export
are importer mapping data; the clean import check proves the artifact does
not depend on those IDs or on a local DDEV URL.

## Export convention

Create exports only from a dedicated empty local database with the tested
theme and exact plugin versions active. Set that temporary site's URL to
`https://biblio-export.invalid`, create only the Page/Kit contract above, and
use Elementor's supported CLI exporter:

```bash
ddev exec env \
  DB_NAME=biblio_elementor_step9_source \
  DDEV_PRIMARY_URL=https://biblio-export.invalid \
  wp --path=/var/www/html/web elementor kit export \
  /var/www/html/config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip \
  --include=content,settings \
  --user=1
```

Elementor 4.2.3's CLI help names the Site Settings include value
`site-settings`, but that value omits `site-settings.json` in this tested
version. The internal value `settings` is therefore deliberate and is
verified by inspecting the archive and by clean import. The exporter may emit
an `Undefined array key "manifest"` warning while still reporting success and
producing a valid archive; the committed artifact must still pass every check
below.

Never export from a working database containing unrelated Pages or Biblio
data. Never include licensed packages, local filesystem paths, DDEV URLs,
secrets, credentials or `.local.*` overrides.

## Import and verification

Import into an existing local runtime with:

```bash
ddev wp elementor kit import \
  /var/www/html/config/elementor/vertical-slice-1a/biblio-vertical-slice-1a.zip \
  --include=content,settings \
  --user=1
ddev wp elementor flush-css --regenerate
```

Elementor 4.2.3 may emit its known `unfilteredFilesUpload` and deprecated
`libxml_disable_entity_loader()` warnings. Success is accepted only when the
CLI reports a successful import and the structural checks pass.

Run the destructive-isolated reproduction check from the project root:

```bash
./scripts/test-elementor-vertical-slice-1a.sh
```

The script refuses to run outside the exact local `biblio-v2` DDEV project,
refuses to reuse its fixed test database, installs a fresh WordPress site at
an `.invalid` URL, imports the artifact, verifies plugin versions, Page/Kit
structure, generated title-hiding CSS, the single mount and page-only assets,
and drops only the temporary test database and isolated `.local` uploads
directory on exit. It does not reset the working WordPress database or alter
existing Biblio tables.
