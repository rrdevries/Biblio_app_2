# Vertical Slices 1A and 1B E2E

This directory contains the shared local-only Playwright acceptance layer for
Vertical Slice 1A and the ReadingRound end evidence in Vertical Slice 1B.

## Safety boundary

The fixture refuses to run unless all of these conditions hold:

- `BIBLIO_E2E_ALLOW_FIXTURES=1` is set by the local wrapper;
- WordPress reports the exact `local` environment;
- the runtime is DDEV project `biblio-v2`;
- home, site and DDEV primary URLs all use
  `https://biblio-v2.ddev.site`.

Setup and cleanup touch only the two marker-owned formal accounts and the
allowlisted `e2e-*` Core identifiers. Cleanup uses no truncate, schema reset or
prefix wildcard. An unmarked account collision makes the fixture stop.

Credentials are generated once in ignored file `.local/e2e.env`, mode `0600`,
and become process environment only inside the DDEV wrapper. No password is
printed or stored in a tracked file.

## Fixture data

The fixture has two designated personal Libraries, nine actor-owned active
Items and one foreign active Item. Eight Items start with an active
ReadingRound. Six dedicated actor records isolate the completed, stopped,
stale, invalid-nonce, idempotent and incompatible-lifecycle end scenarios. The
actor is also a direct manager of the other Library while the foreign active
ReadingRound remains owned by the other user; this proves that Library
management never transfers private ReadingRound ownership.

The copied Biblio1 source was inspected only to select these safe
bibliographic titles for the original 1A cases:

- `Dagboek van een slecht jaar`;
- `The Secret Commonwealth`;
- `Utopia Avenue`;
- `Ripper`.

The six 1B titles are synthetic. No notes, acquisition data, reading history,
private identifiers or other personal fields were copied. The fixture is
self-contained and never reads the ignored Biblio1 archive at runtime. The
current Core read model exposes titles but intentionally returns authors,
covers and edition metadata as `unknown`; the E2E suite verifies that omission
instead of expanding production scope.

The `stale-end` fixture action invokes the authenticated owner's existing Core
finish service. State and fingerprint actions are read-only and emit only
allowlisted lifecycle fields, aggregate counts and hashes; they do not expose
private non-E2E row content.

## Commands

```sh
npm run e2e:setup
npm run e2e:test
npm run e2e:cleanup
npm run e2e:verify-clean
```

`npm run test:e2e` runs the negative guard checks, establishes a clean
before-fingerprint, wraps setup and all browser tests, performs cleanup twice,
verifies zero residue and requires the after-fingerprint to match.
