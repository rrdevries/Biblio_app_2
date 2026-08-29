# Vertical Slice 1A E2E

This directory contains the local-only Playwright acceptance layer for step 11.

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

The fixture has two designated personal Libraries, three actor-owned active
Items and one foreign active Item. One actor Item has a pre-existing active
ReadingRound. The copied Biblio1 source was inspected only to select these safe
bibliographic titles:

- `Dagboek van een slecht jaar`;
- `The Secret Commonwealth`;
- `Utopia Avenue`;
- `Ripper`.

No notes, acquisition data, reading history, private identifiers or other
personal fields were copied. The fixture is self-contained and never reads the
ignored Biblio1 archive at runtime. The current Core read model exposes titles
but intentionally returns authors, covers and edition metadata as `unknown`;
the E2E suite verifies that omission instead of expanding production scope.

## Commands

```sh
npm run e2e:setup
npm run e2e:test
npm run e2e:cleanup
npm run e2e:verify-clean
```

`npm run test:e2e` wraps setup, all browser tests and guaranteed cleanup.
