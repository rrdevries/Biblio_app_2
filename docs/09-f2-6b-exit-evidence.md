# 09 — F2.6b exit evidence

Status: **GO**

Scope: Biblio V2 v2.001, ADR-007 ReadingRound lifecycle and historical truth.

## Git trace

- start branch: `main`;
- implementation baseline: `87e15ee21c0d2c9671303d28484c8dc9a01ca792`;
- final repository HEAD: the commit containing this evidence and its
  implementation; the exact immutable hash is recorded in the final exit
  report because a commit cannot embed its own hash.

## Implemented contract

ReadingRound now derives active/ended lifecycle from nullable outcome and uses
only `completed` or `stopped` for ended rounds. Identity, owner, Work and
creation provenance are immutable. A normal start requires one authorized
Item or owned active ExternalLoan and derives Work from that source. Manual
history is registered directly as ended, source-free `historical_manual`
truth linked to an existing Work; an applicable same-Work source can later be
attached explicitly.

Content dates retain year, month or exact-day precision as components and are
separate from technical UTC timestamps. Calendar validity and possible period
ordering are enforced in the domain and at the database boundary. Historical
registration never passes through an artificial active state.

Named owner-scoped services implement finish, stop, ended-content correction,
active/ended source correction and conditional manual-history deletion. Real
mutations use a locked decision and versioned compare-and-swap write. Semantic
no-op and stale-no-op return current truth without a version increment;
divergent stale intent returns the typed current-state conflict. Source
correction accepts only an authorized source resolving to the immutable Work.
Unknown source correction requires explicit confirmation. Only provenance
`historical_manual` can be hard deleted, including after source attachment.

Both source-start paths and manual history obtain opaque IDs from the shared
server-side `ReadingRoundIdGenerator`. A translated primary-key collision can
retry three times after the initial attempt; exhaustion is controlled and no
client-supplied ReadingRound ID is exposed by application composition.

Personal Work-read-status and first-read/reread remain projections over owned
ReadingRounds. Only completed rounds establish read truth and chronology.
Stopped rounds cannot erase an earlier completion, and overlapping precision
intervals return `chronology_indeterminate`. No ReadingRound mutation writes a
Library ActivityEvent or generic private audit event.

## Schema 1002→1003

Formal baseline remains 1000 and the production chain is contiguous through
1003. Migration preserves each existing ReadingRound ID, User, Work, source
and exact legacy `started_at`, assigns `legacy_source_started` and version 1,
and does not invent content-date components or technical creation timestamps.

Schema 1003 stores nullable outcome, immutable provenance, optional
year/month/day start and required ended-date components, separate technical
timestamps and a positive round version. Database constraints allow zero or
one current source, never two; generated unique keys continue enforcing at
most one active round per User + concrete source. The repository additionally
re-resolves source→Work for every insert.

Migration is safe to rerun when its DDL completed before the version bump.
Version advances only after the 1003 postcondition. Version-aware health checks
cover required columns, types/nullability, generated expressions, indexes,
foreign keys and lifecycle/provenance/date/version checks.

## Authorization, privacy and concurrency

All reads and mutations resolve the authenticated actor server-side and query
ReadingRounds by ID + owner before mutation. Another user receives the same
unavailable result regardless of Library membership or Manager status; Library
Context cannot substitute for ownership. Applicable Item or ExternalLoan
authorization runs before source attachment and cross-Work correction fails
without changing the round.

The database remains the final authority for active-source uniqueness and ID
uniqueness. Integration coverage proves duplicate rejection, different-source
same-Work starts, bounded ID retry, optimistic stale/no-op behavior and
conditional provenance-scoped deletion. Existing independent-process active-
source concurrency coverage remains green.

## Canonical verification

Final verification on 2026-08-22:

- `./scripts/test-biblio-core-all.sh`: PASS in 52 seconds;
- Composer metadata and locked platform requirements: PASS;
- PHP syntax: PASS;
- PHPStan level 6 over production `src`: PASS, no errors;
- unit: 183 tests, 600 assertions;
- isolated real-MariaDB integration: 124 tests, 861 assertions;
- total: 307 tests, 1,461 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- manifest JSON and Git whitespace: PASS;
- the gate did not change visible repository state.

Targeted integration evidence includes lifecycle outcomes, direct historical
registration, year/month/day roundtrip without invented parts, source-free and
later source-backed history, active and ended same-Work source correction,
cross-Work and cross-user denial, immutable provenance, allowed/denied delete,
bounded ID collision retry, zero Library ActivityEvents, derived Work status,
provable first/reread and indeterminate chronology. Schema tests cover fresh
1003, health drift and preservation plus idempotent retry of 1002 legacy rows.

## Deferred and non-scope

F2.6b does not add REST, WordPress Abilities, Elementor or another UI adapter;
InternalLoan as a third source; ExternalLoan lifecycle changes; goals,
statistics, Timeline or Home; ratings or notes; or a private audit engine.

## Final verdict

**GO** — the binding ADR-007 implementation contracts requested for F2.6b are
implemented, the owner/privacy and migration boundaries are proven, all
existing regressions remain green and the complete canonical quality gate
passes.
