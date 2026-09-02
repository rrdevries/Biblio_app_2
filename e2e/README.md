# Vertical Slices 1A, 1B, 1C, 1D and C7 E2E

This directory contains the shared local-only Playwright acceptance layer for
Vertical Slice 1A, the ReadingRound end evidence in Vertical Slice 1B, the
Reading History browser evidence in Vertical Slice 1C and the authenticated
Private Notes browser evidence in Vertical Slice 1D. It also contains the
standalone Next Reading adapter evidence for capability C7.

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
The formal fixture usernames are exact; even cleanup refuses when either is
overridden to a non-E2E account.

Credentials are generated once in ignored file `.local/e2e.env`, mode `0600`,
and become process environment only inside the DDEV wrapper. No password is
printed or stored in a tracked file.

## Fixture data

The fixture has two designated personal Libraries, nine original actor-Library
Items and one foreign active Item. Eight original Items start with an active
ReadingRound. Six dedicated actor records isolate the completed, stopped,
stale, invalid-nonce, idempotent and incompatible-lifecycle end scenarios. The
actor is also a direct manager of the other Library while the foreign active
ReadingRound remains owned by the other user; this proves that Library
management never transfers private ReadingRound ownership.

The 1C layer adds eight Items in that other allowlisted Library across six
Works. One shared Work has a primary Item, another Item for the same Edition,
an Item for another Edition, one actor-owned ExternalLoan, thirteen ended
actor rounds, one active actor round and one foreign ended round. Its exact,
month, year, stopped, source-free historical and legacy dates are fixed. The
remaining Works isolate zero history, active-only, successful End refresh,
failed End refresh and rapid-navigation behavior. Setup contains 18 Items, 17
Works, 18 Editions, three ExternalLoans and 29 ReadingRounds in total. The 1C
browser spec never depends on a mutation from an earlier test.

The 1D layer reuses those exact Works and Items and adds 21 allowlisted Private
Notes: dedicated edit, delete, stale-update, stale-delete, unavailable,
refresh-failure and responsive records; thirteen actor-owned pagination Notes
on one shared Work; and one foreign-owned Note on that Work. No additional Work
or Item is created. Owner-scoped stale/unavailable fixture actions invoke the
existing authenticated Core application services rather than repositories or
direct SQL. The 1D browser spec resets the complete fixture before every full
run and executes serially on one worker.

The C7 layer creates one temporary marked Page at `/hierna-lezen/`, reuses the
primary Work and Item, and adds one dedicated Work whose Item source is made
unavailable after seeding. It adds one actor-owned and one foreign-owned
ExternalLoan plus five actor-owned Next Reading entries covering duplicate
general intent, Item intent, ExternalLoan intent and unavailable-source state.
The `next-reading-reset` action restores that exact actor-owned list through
the existing authenticated Core application services. The C7 browser spec is
serial and proves empty, add, duplicate, reorder, direct remove and Undo,
preferred-source changes, stale recovery, ownership privacy, keyboard flow and
narrow responsive behavior.

Cleanup removes ReadingRounds by the exact allowlisted Work set, including
source-free, ExternalLoan and legacy rows, then removes the three exact
ExternalLoans, the Next Reading rows and the existing exact entity allowlists.
It also removes the marked C7 Page. Counts report every relevant fixture entity
separately.

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

The `stale-end`, `note-stale-update`, `note-stale-delete`,
`note-unavailable-delete` and `next-reading-reset` fixture actions invoke the
authenticated owner's existing Core application services. State and
fingerprint actions are read-only and emit only allowlisted
lifecycle/ownership fields, aggregate counts and hashes; they do not expose
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
verifies zero residue and requires the printed after-fingerprint to match the
printed before-fingerprint exactly.
