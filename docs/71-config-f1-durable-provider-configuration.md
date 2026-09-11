# CONFIG-F1 — Durable Metadata provider environment configuration

Status: **GO / CLOSED** after the recorded gates and independent review.

## Scope

CONFIG-F1 corrects only the production provider-configuration boundary. It
does not change provider order, candidate sufficiency, fallback, provider
mapping, Add Book, Wishlist, authorization, REST, schema or UI behavior.

No credential or real contact value is stored in Git, fixtures, documentation,
test output, provider results, provenance or logs.

## Audited cause

`ProductionComposition` previously read
`BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL` and `GOOGLE_BOOKS_API_KEY` only as
WordPress constants. It repeated that logic separately for Add Book's
first-sufficient ISBN lookup and generic bibliographic text discovery. DDEV
could expose both environment variables to PHP, but Biblio ignored them unless
generated `web/wp-config.php` also defined the constants.

## Configuration contract

`RuntimeMetadataProviderConfiguration` resolves both settings once when the
production composition is built. Each setting uses this precedence:

1. a defined WordPress constant;
2. otherwise the environment variable with the identical name;
3. otherwise no configured value and the existing typed
   `configuration_error` provider result.

A defined constant is authoritative for backwards compatibility. If it is not
a valid string or fails the existing provider-specific validation, Biblio fails
that provider closed with the controlled configuration result; it does not
silently fall through to the environment.

Open Library and Google Books resolve independently. The immutable resolved
snapshot is shared by Add Book's ISBN provider adapters and the generic ISBN/
text discovery adapters, preventing drift between the consumers.

## Operations

Local DDEV configuration stays in ignored `.ddev/config.local.yaml` under the
existing variable names. DDEV passes those values to PHP, and Biblio now reads
them without a generated `web/wp-config.php` customization. WordPress constants
remain supported for deployments that already define them.

## Verification

Deterministic unit tests cover constant-only, environment-only, both-source
precedence, both missing, Open Library only and Google Books only. Existing
provider tests retain missing/invalid configuration error behavior without live
networking or real secrets. The DDEV runtime probe reports only boolean source
presence/resolution and never prints values.

Recorded final gates:

- focused resolver matrix: 6 tests, 12 assertions;
- full Core gate: syntax clean, PHPStan clean, 449 unit tests with 1,925
  assertions and the two existing PHPUnit notices, 409 integration tests with
  4,855 assertions, WordPress smoke, manifest JSON and whitespace clean;
- post-restart DDEV runtime: both WordPress constants absent, both environment
  variables present, and both settings resolved; values were not emitted; and
- independent diff review: no architecture, secret-exposure, provider-policy,
  Add Book, Wishlist or regression blocker.

Biblio Core advances to `2.5.1`; schema remains `1023`; Biblio UI remains
`0.15.0`.
