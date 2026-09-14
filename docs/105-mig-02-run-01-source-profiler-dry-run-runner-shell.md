# MIG-02-RUN-01 — Source profiler and dry-run runner shell

Status: **GO / CLOSED**

Scope: reusable migration infrastructure only. No current V1 parser, V1 field
mapping, domain migration, apply command, production trial or cutover is part
of this slice.

## 1. Pre-coding audit

MIG-FND already owns runs, target locks, observations, mappings,
preservation/quarantine, lifecycle, replay and reconciliation. Logical source
identity is family+type+ID; observations add snapshot and payload hash.
`PersonalMigrationTargetService` already owns active user, designated personal
Library and Owner/direct validation. `CommitMigrationRecordService` already
owns the product-write plus ledger-outcome transaction. Dry-run primitives are
in-memory, but no package, adapter, profiler, planner, artifact or CLI shell
existed. No production V1 parser/executor existed; old DATA-01 scripts are
historical/reference tooling only.

## 2. Source package

`FilesystemMigrationSourcePackageFactory` requires one explicit real directory
and inventories every regular file by normalized relative path, byte size and
raw-byte SHA-256. The sorted canonical inventory produces one aggregate
manifest digest. It rejects missing/unreadable roots, unreadable files,
non-files and every symlink. Adapter reads are limited to inventoried paths and
revalidate size/hash, detecting changed bytes between profiling and planning.
Source bytes are never normalized, rewritten or placed in artifacts.

## 3. Source adapter and version boundary

`MigrationSourceAdapter` supplies adapter ID, source family, structural profile,
explicit version support and deterministic typed record enumeration. It answers
only which source facts exist. `MigrationParticipant` separately answers their
V2 meaning. Unknown adapter/version fails before target validation or planning.
Production registers no adapter; synthetic test adapter version `test-1` is not
a V1 format claim.

## 4. Observation identity and hashing

Every `MigrationSourceRecord` has stable source type/ID and canonical JSON
payload SHA-256. No timestamp, run ID or target ID enters source identity. The
shape aligns with MIG-FND and can create the existing `SourceObservation`
during future apply. Changed payload content changes the payload hash.

## 5. Deterministic enumeration

Filesystem paths are bytewise sorted. Records are duplicate-checked by exact
type+ID and sorted by type, ID and payload hash. Categories, findings,
operations, references, counts and unknown types are sorted before canonical
artifact encoding. Ordering carries no domain ranking or product semantics.

## 6. Participant registry

Each participant owns exactly one stable source type. Duplicate ownership fails
closed. A supported record routes once; absent ownership remains an explicit
unsupported type. A participant may plan mapped/transformed operations,
preservation, quarantine, intentional reasoned omission or failure. At RUN-01
closure, production provided no domain participant; MIG-02-CAT-01 subsequently
adds the approved catalog participants without changing this generic boundary.

## 7. Target validation

Dry-run requires exact user and Library IDs. It checks runtime schema first and
delegates target validity to `PersonalMigrationTargetService`; optional
`--require-empty` uses its existing readiness counts. There is no actor,
administrator, first-user, display-name or Library-name fallback.

## 8. Profile mode

Profile emits package and adapter identity, validated source version, ordered
file inventory/totals, category and typed-record totals, unknown categories,
malformed/unreadable counts and typed findings. It performs no V2 mapping.

## 9. Dry-run planning

Dry-run reuses the same package, adapter, version and enumeration path, then
invokes participant `plan()` methods. The artifact reports dispositions,
unsupported types, preservation/quarantine candidates, planning errors and
participant-known unmatched references. It stores neither a run nor an
observation and invokes no product write.

## 10. Apply boundary choice

No production apply command is exposed. `MigrationParticipant::apply()` must
consume the exact `PlannedMigrationRecord` produced by `plan()` plus the
MIG-FND `SourceObservation`; a later apply orchestrator must call it inside
`CommitMigrationRecordService`. This keeps mapping semantics shared without
claiming that a useful migration can run before approved adapters/participants.

## 11. Zero-write proof

The targeted MariaDB integration test bootstraps an explicit synthetic personal
target, snapshots row counts for every `wp_biblio_*` table, runs profile and
dry-run through the CLI command, and proves every count is identical afterward.
Only JSON and checksum files appear in the selected temporary output directory.

## 12. Artifact format and provenance

Canonical JSON uses `migration_artifact_version: 1`. It includes product,
schema and plugin-header Core version; exact Git SHA when available; dirty-tree
state; source digest/adapter/version; and for dry-run the explicit target and
validation result. Files are atomically replaced outside the source root and a
companion SHA-256 is generated. No raw record payload or secret is emitted.
The stale `Biblio\Core\Plugin::VERSION = 2.14.0` is not consumed.

## 13. CLI

The registered surface is:

```text
wp biblio migration profile --source-root=<path> --source-adapter=<id> [--output-dir=<path>]
wp biblio migration dry-run --source-root=<path> --source-adapter=<id> --target-user-id=<id> --target-library-id=<id> [--require-empty] [--output-dir=<path>]
```

The safe default output is ignored `.local/migration`. Success prints mode,
zero-write state, artifact path/checksum and checksum path. Fatal structural,
adapter, version, schema and target failures are typed and nonzero.

## 14. Resume foundation

No scheduler or new durable cursor exists. Stable enumeration, exact payload
hashes, one shared plan and MIG-FND committed-observation/replay semantics are
the resume foundation. A later apply slice can skip or reuse committed exact
observations through the existing ledger without duplicating planning.

## 15. Failure model and reason codes

Bounded fatal codes cover missing/unreadable/unsafe/changed/duplicate source,
unsupported adapter/version, invalid target, unhealthy schema, duplicate
participant ownership and artifact-write failure. Record-level adapter
findings and participant planning errors remain separate from fatal pre-write
failures. Human text is informative; automation consumes codes.

## 16. Explicitly deferred domain migrations

Catalog Work/Edition/Item, Authors, ReadingRounds/truth, Notes, Wishlist,
Archive, Collections/Series, loans, classifications, Item-local source mapping,
full reconciliation, trial and cutover remain separate slices. No provider or
network enrichment occurs.

## 17. Current V1 export rule

RUN-01 used no current export and did not inspect historical MIG-01/DATA-01
records as current truth. Completion does not request an export. Catalog,
Authors, concrete ReadingRounds, Notes and reconciliation participants are
still mandatory before the defined current-export checkpoint; circulation
treatment remains a separate product decision when current evidence exists.

## 18. Tests and quality gates

- focused runner/package/adapter/participant/artifact unit suite: 10 tests,
  39 assertions;
- focused runner CLI/zero-write MariaDB suite: 2 tests, 13 assertions;
- final full Core gate: 691 unit tests / 2,747 assertions with 2 existing
  PHPUnit notices; 519 MariaDB integration tests / 5,982 assertions; complete
  gate green in 356 seconds;
- PHP syntax, PHPStan, Composer/platform, WordPress smoke, manifest and
  whitespace: green; plugin active, class loaded, init hook 1 and HTTP 200;
- independent second review: no blocker after correcting the artifact writer
  to reject an output directory inside the source root before creating it.

## 19. Versions

- Product: `v2.001` unchanged.
- Schema: `1025` unchanged; MIG-FND storage was sufficient.
- Biblio Core: `2.27.0 -> 2.28.0`.
- Biblio UI: `0.20.0` unchanged.

## 20. Git and closure

Start HEAD: `19bea504b570c4d7ac2c7f964621b35af0344f49`.
One local implementation commit is created after all gates and review. Nothing
is pushed. No safety branch is required because the slice begins from a clean,
committed `main` and produces one bounded local commit.
