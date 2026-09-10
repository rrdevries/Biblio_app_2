# ARCH-MIG-01 — Historical archive reasons

Status: **GO / CLOSED** after the recorded gates and independent review pass.

## 1. Domain audit and decision

Item archive truth is Library-owned. Current Item state (`active` or
`archived`) is separate from append-only archive periods; restore closes the
open period and re-archive creates another. Native V2 writes require one of the
six canonical `ItemArchiveReason` values, use server-side Owner/Beheerder
authorization, emit Library activity audit and end active Collection
memberships. Archived catalog inclusion depends on Item state, not reason.

The semantic gap was confined to reasons that cannot truthfully map to that
closed native taxonomy. The minimum target therefore extends the existing
period instead of adding a parallel archive system. An archive period now has
exactly one source-neutral reason value: native or preserved historical.

## 2. Product and persistence contract

`PreservedHistoricalArchiveReason` retains required original human-readable
text and an optional original code/value. It carries an explicit
`preserved_historical` discriminant and no native enum. Text is stored exactly
as supplied after validation; Core does not trim, translate, keyword-match or
fall back to `anders`. Both fields require non-whitespace plain valid UTF-8,
reject control/HTML-like input and have limits of 500 and 191 characters.

Schema 1020 extends each existing archive-period row with the discriminant and
preserved fields. Existing rows remain `native`; mutually exclusive database
constraints prevent mixed or empty representations. The 1019→1020 migration
is additive and retry-recognizable, accepts only the exact source or complete
target shape and fails closed on unknown partial state. Fresh-install and
forward-compatible schema health are covered.

## 3. Lifecycle and ownership

Preserved reason belongs to the exact Library Item and archive period, never
to Work, Edition or a sibling Item. It does not archive an Item merely by
existing. Restoring an historically archived Item retains its closed period
unchanged; a later normal V2 archive adds a new native period. Normal V2 writes
remain native-enum-only and keep all authorization and audit behavior.

`LibraryItemArchiveQueryService` reads the typed distinction only after normal
Library Context authorization. The migration participant resolves the exact
Item within its explicitly supplied target Library and fails non-enumerating
for a foreign Item. It has no current-user, admin or first-Library fallback.

## 4. MIG-FND and reconciliation

`HistoricalItemArchiveRecorder` is source-neutral and does not own a
transaction. MIG-FND supplies the IDENTITY-01-validated target Library and
wraps source observation, archive product write and source-to-Item mapping in
one transaction. The archive aggregate stores no source family, source ID,
payload hash or run provenance. It also invents no historical actor and emits
no normal current-user ActivityEvent; migration provenance remains in MIG-FND.

Synthetic integration covers commit, rollback without false mapping,
committed retry without a second write and quarantine for unrepresentable
input. Later reconciliation can count source archived records, native mapped
reasons, preserved historical reasons, quarantines and failures from the
ledger plus the typed product target.

## 5. Read/UI and boundaries

Existing active/archived catalog filtering is unchanged and has no reason-based
search. Book Detail currently rejects archived Items and therefore has no
reachable archive-reason surface. This slice completes the authorized Core
history read contract; presenting a preserved reason in a future archived Book
Detail surface remains a small explicit UI follow-up. No REST, frontend,
lending, archive dashboard, bulk management, parser or importer is included.

The active-InternalLoan guard remains vacuous because that lifecycle does not
yet exist. This slice does not infer loan state or change the separate open
circulation migration decision.

## 6. Actual V1 data rule

No MIG-01 ZIP, `.local/fixture-source`, DATA-01, earlier `/data/` copy, old
count or concrete historical reason was used as current V1 truth. No current
V1 source was needed. All behavior and integration evidence uses synthetic
guarded fixtures. Any later source-dependent mapping or import must request a
current `/data/` directory or export explicitly designated by Renée and pin
that snapshot to the migration run.

## 7. Verification evidence

`./scripts/test-biblio-core-all.sh` passed Composer strict validation and all
locked platform requirements, every PHP syntax check, PHPStan with no errors,
the then-current 426 unit tests/1,812 assertions, all 375 integration
tests/4,466 assertions, WordPress plugin/class/init/HTTP smoke, manifest JSON
and Git whitespace. The two reported PHPUnit notices are the suite's known
negative-path notices, not failures. After the second review added explicit
invalid-UTF-8 and HTML original-value fixtures, the complete unit suite passed
again with 428 tests/1,814 assertions. The focused final schema-1020 suite
passed with 3 tests/19 assertions.

The integration suite covers archive lifecycle, Item isolation, authorized
history read, archived catalog composition, MIG-FND commit/retry/rollback/
quarantine and schema lifecycle. Schema-specific coverage proves fresh install
through the normal migrator plus 1019→1020 preservation, exact retry, health,
database constraints and partial-state failure.

The explicit second review pass rechecked the final diff against the request,
canonical architecture, authorization, privacy, source-neutrality, schema
linearity and regression boundaries. It found no blocker. Its only evidence
improvement was the two extra unsafe-input fixtures above; no product decision
changed.
