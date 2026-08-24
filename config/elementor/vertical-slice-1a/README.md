# Elementor Vertical Slice 1a configuration

This directory is the versioned configuration boundary for the first
Elementor vertical slice. Step 1 establishes the tested runtime and export
location only. It does not create a WordPress Page, Elementor layout, Kit or
placeholder export.

## Tested runtime contract

- WordPress: 7.0.2
- PHP: 8.3.31
- active theme: Twenty Twenty-Five 1.5
- Biblio Core: 2.1.0, active
- Elementor: 4.2.3, active
- Elementor Pro: 4.2.2, active

This inventory was verified locally through WP-CLI on 2026-08-24. The only
other installed plugins were inactive Akismet 5.7 and Hello Dolly 1.7.2. No
`Mijn Bibliotheek` Page existed when this runtime prerequisite was recorded.

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

## Future export convention

Only genuine, validated exports created during the later ordinary Elementor
Page-shell step belong in this directory. That later step must export the
`Mijn Bibliotheek` Page and the relevant Site Settings/Kit configuration, then
document a clean import proof. Licensed packages, local IDs, secrets,
credentials and `.local.*` overrides remain outside Git.
