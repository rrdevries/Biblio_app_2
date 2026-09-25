# 145 — Guarded production cutover tooling

Status: implementation candidate; final test/gate evidence is external and SHA-bound.
No production authorization, apply, restore, V1 unfreeze or push is part of this slice.

## Scope and reuse

Production has a separate positive target, authorization and native transport.
`WpdbRehearsalTarget`, `MariaDbRehearsalTransport` and all mapper contracts remain
unchanged. `ProductionCutoverComposition` uses the proven FINAL planning context,
`RehearsalComposition::preApprovalPlanner`, existing participant registry,
`MigrationApplyRunner`, reconciliation and product verifier. No second importer,
bundle format, production fault injection or automatic restore is introduced.

## Operator surface

Run in the normal DDEV project, explicitly via standalone PHP:

```text
ddev exec php /var/www/html/scripts/migration-production.php preflight CONFIG.json
ddev exec php /var/www/html/scripts/migration-production.php apply CONFIG.json PACKET.json APPROVAL.json
ddev exec php /var/www/html/scripts/migration-production.php restore CONFIG.json PACKET.json APPROVAL.json 'RESTORE PRODUCTION <packet-digest>'
```

There is no default action and no approval-creation command. Preflight outputs
the packet and its digest; `PACKET.json` is the exact returned `packet` object,
not the outer CLI response. Private files must be owner-only, canonical and not
symlinks. `APPROVAL.json` must not be created until Renée explicitly approves the
final production preflight. Its exact fields are `purpose: production-cutover`,
`packet_digest` and `confirmation: AUTHORIZE PRODUCTION <packet-digest>`.
This is one local operator authority boundary, not a cryptographic defense
against the OS owner. Restore reuses that authority with a deliberate separate
restore invocation/confirmation; a failure never restores automatically.

The operational config supplies `target`, `source`, `evidence_root`,
`backup_root`, `restore_probe_database`. Target explicitly names purpose
`production`, project `biblio-v2`, database `db`, root `/var/www/html`, URL
`https://biblio-v2.ddev.site`, clean `git_sha`, `target_user_id` and
`target_library_id`. The source config locates existing intake evidence with
its SHA, immutable archive/extraction, drift-reference extraction, exact bundle
SHA and existing planning-review facts. The loader verifies/reconstitutes the
existing typed bundle in memory; it does not export, extract or approve it.
The external circulation CSV is never read by migration code.

All source/package/archive/manifest/bundle, planning review, runtime/build,
production targets, complete plan-set, neutral intent, PRE fingerprint and
validated backup are included in the owner-authorized packet digest. Any
change invalidates that authority. Restore checks physical identity/build and
exact PRE, without requiring healthy application tables or a source planner.

## Read-only preparation

The standalone entrypoint does not execute wp-config/wp-load, plugins, normal
WordPress lifecycle, cron, HTTP smoke or persistent cache drop-ins. It loads
definition-only WordPress libraries and memory-only cache. Ephemeral in-memory
salts satisfy unrelated cursor-codec construction without reading live secrets
or allowing `wp_salt` fallback writes; no login/cursor tokens are issued.
Preflight additionally rejects SQL writes on its PHP connections except the
explicit reserved disposable restore-probe CREATE DATABASE. The external native
restore process can write only the positively identified probe.

The installed audited DDEV config hashes and permitted environment overrides
are checked without executing configuration. This is deliberately one supported
local deployment, not a generic production framework. Config changes fail
closed and require a renewed config audit, not an automatic re-pin.

Preflight requires the proven all-57-table empty-target contract, creates a
full native gzip PRE backup in private `.local` storage outside the web/data
roots, and verifies it by restoring into a separately named disposable probe.
The native endpoint is proven against the actual wpdb connection by a random
connection-owned named lock. No credential appears in argv or receipts.
Before/after full DB fingerprints must match. No production authorization is
manufactured. A previous independently verified backup remains recoverable
without retaining its disposable restore probe.

## Application write control

Only after explicit production authority and exact PRE/source/plan validation:

1. Acquire a nonblocking cutover operation lock.
2. Validate ordinary app account `db` has only global USAGE and no roles; reject
   replication, event scheduler, cluster operation and other privileged client
   sessions. The separately audited DDEV config must still use that account.
3. Install the known persistent WordPress `.maintenance` content and set MariaDB
   `GLOBAL read_only=ON` through the privileged migration connection.
4. Acquire `FLUSH TABLES <explicit complete target table list> WITH READ LOCK`
   with a five-second lock timeout; release table locks only after the barrier.
   Existing table transactions must drain; cached `INNODB_TRX` counts are not
   evidence of quiescence. Recheck the exact PRE fingerprint before Begin.

The explicit table-list barrier follows
[MariaDB 10.11 implementation](https://raw.githubusercontent.com/MariaDB/server/10.11/sql/sql_reload.cc).
Ordinary new/existing connections cannot write while read_only is ON; the
privileged migration connection can. Native restore uses the same boundary.
The setting is server-wide, affecting all databases in this DDEV database
container. It is runtime-only, not resilient to a database-server restart;
the owner must not restart the server or change deployment configuration
during cutover. The connection-owned lock and read_only are rechecked at
migration observation/verification boundaries. Maintenance survives a restart.

Normal HTTP application access returns maintenance instead of rendering pages
that may incidentally write caches. Direct read-only DB access remains possible.
Write access is **never** restored in a finally block, after failure, or merely
because the migration returned. Following inspected success or exact PRE
rollback, the operator separately restores access by setting read_only OFF
and removing only the verified migration-created maintenance file. Do not remove
an unknown maintenance file; no automated generic access-management command is
added. This release step is outside the current no-production-execution task.

## Failure and recovery

Reconciliation, exact quarantine and post-apply product/preservation verification
are mandatory. Immutable structured failure evidence retains privacy-safe
subphase/Throwable fingerprints before any deliberate rollback. A completed
MIG-FND run alone is not cutover success if product verification failed.
Rollback restores the exact authorized PRE, including all 57 Biblio and all WP
tables, then compares the full fingerprint. It can recover from partially absent
tables or an absent production database. The source archive, old product schema
health and restore-probe database are not prerequisites for PRE recovery.

## Tests and current real-target blocker

Targeted unit/native integration cover positive identity, wrong environments,
authorization/tuple drift, required PRE, immutable readable/independently restored
backup, same plan as rehearsal, mandatory verification failure, ordinary writer
denial, active transaction barrier, deliberate rollback, cold partial/absent-DB
recovery and privacy. All mutations use disposable simulation databases on an
explicit cutover-test DDEV server, never the normal database. Rehearsal negative
guards remain covered by their existing tests. Run the broad Core gate once on
the locally committed candidate; no repeat FINAL-source rehearsal is warranted
when mapper/participant semantics remain unchanged.

Read-only inspection on 2026-09-25 resolved the ordinary personal target to
User `227`, Library `personal-a41aedfee4e20e3392355258f6a8adc3`. The normal DB
is **not empty**: it already contains 12 Works, 9 Editions, 6 Items, one
ExternalLoan, one ReadingRound, other product/metadata rows and two Libraries.
This slice does not decide deletion, merge, adoption, reuse or migration into
that existing population. Therefore tooling acceptance cannot establish real
production readiness. Keep the actual production preflight on HOLD; do not
relax empty-target or post-apply semantics to make that check pass.
