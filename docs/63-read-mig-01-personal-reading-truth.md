# READ-MIG-01 — Personal Reading Truth

Status: **GO / CLOSED** after the recorded gates and independent review pass.

## 1. Product and ownership contract

`PersonalReadingTruth` is normal source-neutral Biblio V2 state for exactly one
user + Work. It is private owner data and is never Library-, Edition- or
Item-scoped. Its closed states are:

- `read_known_date_unknown`;
- `explicit_not_read`;
- `unknown`.

The aggregate stores product truth only. MIG-FND separately owns source
observations, hashes, mappings, preservation, quarantine and provenance.

## 2. ReadingRound boundary and effective status

Reading Truth is never a ReadingRound. It has no start/end date, concrete
source or technical timestamp reinterpreted as a reading date. It never raises
the ReadingRound count and is excluded from year/month timelines, streaks,
round-based goals and date-based statistics.

Effective owner status is resolved in this order: active ReadingRound
(`reading`), completed ReadingRound (`read`), read-known marker (`read` with
date unknown), explicit-not-read (`not_read`), explicit unknown (`unknown`),
then the existing no-record default (`not_read`). Active/completed rounds do
not delete a retained marker. Read-known proves an earlier Work-level read, so
a later concrete round is a reread even when it is the only concrete round;
the marker adds no fictive first round and no reread count.

A new explicit-not-read beside an existing completed round is contradictory
and fails closed. A read-known marker beside a completed round is valid; the
round is the more concrete effective evidence and no double count occurs.

## 3. Write, persistence and concurrency

Schema 1019 adds one versioned truth row and one technical mutation-lock row
per user + Work. Normal writes resolve the authenticated actor server-side,
validate an active platform user and existing Work, acquire the same user +
Work lock as ReadingRound mutations and perform locked versioned persistence.
Identical writes are idempotent; divergent state replacement uses compare-and-
swap semantics. Repositories and batch projections are owner-scoped.

`PersonalReadingTruthRecorder` is a non-transaction-owning application
participant for callers such as MIG-FND. `RecordPersonalReadingTruthService`
is the normal authenticated transaction-owning facade. Neither interface uses
V1 field names.

## 4. MIG-FND integration

Synthetic integration proves observation, product write and target mapping in
one transaction; target type is `personal_reading_truth` and target identity is
the Work ID within the already explicit migration target context. Rollback
leaves no truth or mapping, identical committed retry does not repeat the
write, and the completed-round contradiction can be routed to quarantine.

No V1 parser, importer, `/data/` access or production data is part of this
slice. V1 remains source of truth until cutover. MIG-01 ZIP,
`.local/fixture-source`, DATA-01, earlier `/data/` copies and previously
reported counts remain historical/regression evidence only. Any later
source-dependent work must request a current export explicitly designated by
Renée and pin that snapshot per run.

## 5. REST and UI

Existing Book Detail and Mijn Bibliotheek projections expose the effective
four-value response status plus nullable `read_date_known`. They render
`Uitgelezen · datum onbekend` and `Leesstatus onbekend` without exposing other
users' markers. The catalog request/filter allowlist deliberately remains
`reading`, `read`, `not_read`; explicit `unknown` is excluded rather than
collapsed into `not_read`.

The Core contract can carry a future ordinary “Mark as read — date unknown”
action. READ-MIG-01 adds no broad reading-history editor or write UI; that
focused normal-user control is follow-up `READ-UI-01`.

## 6. Verification scope

Automated coverage includes all three states, no-record default, active and
completed precedence, read-known reread semantics, multiple concrete rounds,
marker retention, contradiction rollback, idempotency/version replacement,
missing/inactive identity checks, owner isolation, same-Work multi-Library
projection, competing writes, schema migration/health, MIG-FND commit/retry/
rollback/quarantine and strict REST/UI decoding/rendering. Final validation and
independent-review evidence is reported in the READ-MIG-01 completion report.
