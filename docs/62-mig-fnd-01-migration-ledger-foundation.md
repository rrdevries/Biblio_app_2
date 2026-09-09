# MIG-FND-01 — Migration ledger and preservation foundation

Status: **GO / CLOSED**

Scope: source-neutral Core/application/persistence foundation only. No V1
parser, import executor, product-domain migration, CLI, UI or source data is
included.

## 1. Architecture decision

MIG-FND-01 reuses the existing Core application-service, repository,
`TransactionManager`, opaque-ID, UTC `DATETIME(6)`, explicit InnoDB schema,
health-check and ordered migration-registry patterns. It does not reuse
`ActivityEvent`: that record is a Library audit event and lacks immutable
source observation, payload hash, disposition, preservation, quarantine and
reverse mapping semantics. Metadata evidence is likewise bound to its own
catalog/provider contexts and is not a generic migration ledger.

The foundation uses explicit relational records. Canonically serialized JSON
is permitted only as bounded evidence; identity, lifecycle, status and links
remain typed columns and foreign-keyed relations.

## 2. Schema 1018

The additive `1017 -> 1018` migration creates six `wp_biblio_*` tables:

- `biblio_migration_runs`: immutable source/run identity, explicit target user
  and Library, mode, lifecycle and summary state;
- `biblio_migration_run_locks`: one active apply run per exact target
  user+Library context;
- `biblio_migration_source_observations`: logical source identity, pinned
  snapshot, payload hash/evidence, processing lifecycle and one disposition;
- `biblio_migration_target_mappings`: zero-to-many committed target edges with
  created/reused classification;
- `biblio_migration_quarantine`: fixed reason, safe explanation, resolution
  state and optional evidence/reference;
- `biblio_migration_preservations`: deferred reason, future-processing state
  and optional evidence/reference.

Primary/unique keys prevent duplicate run identities, observations, target
edges and multiple quarantine/preservation rows per observation. Target and
logical-source indexes support both trace directions. Run, observation and
Library foreign keys follow current Core policy; polymorphic product target
IDs deliberately have no fabricated foreign key. Checks close the lifecycle,
disposition, hash, reason, timestamp, evidence-size and status vocabularies.
The migration is retry-safe only from absent or structurally healthy partial
1018 state and records version 1018 only after full post-health succeeds.

## 3. Run and source identity

`MigrationRun` records source family, snapshot, SHA-256 fingerprint, optional
source version, migrator version, explicit `target_user_id`, explicit
`target_library_id`, `dry_run|apply`, status, summary state and timestamps. Its
natural unique identity makes an identical rerun deterministic.

Logical source identity is `source_family + source_type + source_id`. Each run
records a snapshot observation with `source_snapshot + payload_hash`; its
opaque observation ID is a deterministic SHA-256 over that context. A changed
payload in a later snapshot is therefore a new observation. Absence in a later
snapshot performs no delete or overwrite.

## 4. Lifecycle, idempotency and concurrency

Runs represent `planned`, `running`, `interrupted`, `failed` and `completed`.
Source processing represents `observed`, `processing`, `committed`,
`retryable_failure` and `terminal`. Resume is allowed only from interrupted or
failed. A completed run requires exact reconciliation, zero uncommitted
observations and zero failed observations.

DB constraints and repository conflict handling make duplicate run,
observation and mapping writes fail closed or return the same identity. Prior
target reuse is available only for the same source family, logical record,
payload hash and exact target user+Library context. A changed payload is never
silently treated as reusable. The target lock serializes apply runs for one
personal target while allowing another target context independently.

## 5. Transaction boundary

`CommitMigrationRecordService` locks the observation and executes the supplied
product write and committed outcome in one existing Core transaction. It writes
target edges, preservation/quarantine and final disposition only after the
product participant succeeds. An exception rolls back both product and ledger
writes; a committed observation cannot be processed again. MIG-02 must compose
its domain repository participants inside this boundary and must not create a
nested transaction.

## 6. Loss policy and evidence

Every committed observation has exactly one main disposition:
`mapped`, `transformed`, `preserved_deferred`, `quarantined`,
`intentionally_dropped` or `failed`. Mapped/transformed require target edges;
intentional drop and failure require a reason. Failure is distinct from
quarantine, and retryability is explicit. Preservation remains
`awaiting_future_processing` until a later authorized domain slice handles it.

Quarantine uses the fixed MIG-01 reason vocabulary, including invalid ISBN,
identity/reference/read/taxonomy/Work/contributor/circulation and unsupported
target conflicts. Optional target edges can retain safe partial relationships.
Evidence is deterministic JSON of at most 65,535 bytes or a bounded durable
reference, always accompanied by a payload hash. No executable serialization,
artifact or source payload is committed to Git.

## 7. Traceability and reconciliation

Target-scoped repository queries answer logical source -> committed targets
and committed target -> source observations without crossing the explicit
user+Library boundary. Reconciliation groups observations, not mapping joins,
so one source mapped to Work, Edition and Item is counted once. It reports all
six dispositions, uncommitted records, mapping edges and created/reused target
counts.

## 8. IDENTITY-01 and dry-run

`BeginMigrationRunService` always delegates the explicit user+Library pair to
`PersonalMigrationTargetService`; there is no actor, admin, name or first-user
fallback. Apply persists the run and lock. MIG-FND-01 deliberately makes
dry-run fully in-memory: it validates the same target and can build the same
typed observations/outcomes, but writes neither product data nor ledger data.
Future MIG-02 owns safe external dry-run artifacts.

## 9. MIG-01 representability

Synthetic tests prove the contract can represent multiple Work/Edition/Item
edges, invalid-ISBN and ambiguous-conflict quarantine, and deferred Wishlist,
unknown-date reading truth, legacy archive facts, open circulation, ratings
without source time, cover references and Goals. No listed domain is
implemented by this slice.

## 10. Privacy and actual V1 source rule

Normal exceptions expose only safe state, reason categories and IDs; they do
not interpolate payload contents. Fixtures contain no Renée or production V1
data. Raw evidence is opt-in, bounded and stored only in Core tables.

V1 remains source of truth until cutover. The historical MIG-01 ZIP,
`.local/fixture-source`, DATA-01 and any earlier `/data/` copy are design or
regression evidence only. Every future source-dependent phase must ask Renée
for a current `/data/` directory or export that she explicitly designates, and
each run must pin its own snapshot and fingerprint.

## 11. Verification and boundaries

Automated coverage includes synthetic dry-run/apply metadata, target
validation, canonical evidence, logical/observation identity, identical and
changed observations, multi-target edges, safe reuse, all dispositions,
mandatory reasons, preservation/quarantine, duplicate prevention,
interrupt/resume, rollback, bidirectional trace, one-source reconciliation,
cross-target isolation and competing-run locking. Schema install, upgrade,
retry and unknown-partial-state failure are covered separately.

No browser/human UI acceptance is required because no frontend or operator UI
changed. MIG-02, a V1 parser/CLI, current-source profiling, domain cleanup,
Wishlist/ReadingRound/Loan/Goal/cover/review behavior and remaining cutover
decisions remain outside scope.

Schema is `1018`. Biblio UI remains `0.11.0`.
