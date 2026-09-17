# D-MIG-PRESERVE-01 — Durable mapper-only preservation contract

Status: **DESIGN GO — contract closed; implementation not started**

Date: 2026-09-17

Task severity: **High**

Scope: migration-foundation and preservation design only. This document adds
no production code, schema change, apply/import command, product entity,
product relation or production-data write. V1 is source evidence, not product
authority.

## 1. Problem statement and production apply blocker

CURRENT source-specific mappers can identify valid source truth with semantic
disposition `preserved_deferred` even when that truth has no active V2 product
target and no normal product participant. The dry-run can currently expose
such truth only as a privacy-safe `MigrationSourceMappingFinding`.

That is not durable cutover evidence. Findings live only in the dry-run
artifact, are not MIG-FND observations and cannot prove replay, resume,
divergence or reconciliation. The five CURRENT Reflections are the first
explicit proof case.

The global state therefore remains:

```text
PRODUCTION APPLY BLOCKED
```

This blocker is not removed by this design. It may be reconsidered only after
this design and the later implementation slice are GO/CLOSED with exact
replay, resume, reconciliation and privacy proof. Final mapping completion, a
fresh final export, a final cutover rehearsal and explicit Renée authorization
remain separate gates.

## 2. Audited baseline and authority

The audit ran against:

- branch `main`;
- clean start HEAD
  `6d29a136ec149369dc45a3ce2a511cd112ef1319`;
- product `v2.001`;
- schema `1026`;
- Biblio Core `2.43.0`;
- Biblio UI `0.20.0`;
- adapter `current-v1-json-29`;
- source version `books-29.authors-2.reading-goals-2`; and
- immutable extracted-manifest SHA-256
  `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.

Authority order was the project guides, current Git/schema and canonical docs,
then the accepted MIG-FND/RUN/RECON and CURRENT mapper contracts, and finally
the designated immutable CURRENT package as source evidence. Historical
fixtures, DATA-01 and other ZIPs were not substituted. No apply/import ran.

## 3. Current apply architecture

The required A–Q audit found:

| Area | Exact current behavior | Consequence |
|---|---|---|
| A. `MigrationApplyRunner` | Inspects the package, begins/resumes a run and plans raw adapter records through `MigrationParticipantRegistry`. It never obtains `MigrationSourceMapperRegistry` or `MigrationSourceMappingResult`. | CURRENT mapper semantics do not reach apply. |
| B. dry-run/planner | `MigrationRunner::dryRun()` invokes the adapter mapper and plans `mapping.records()`. | Dry-run sees reviewed typed output. |
| C. participant registry | Routes exactly one source type to one source-neutral participant. | Correct execution boundary once a typed preservation record exists. |
| D. adapter enumeration | Emits only stable raw records and separately accounts ID-less embedded categories. Reflection is an embedded non-observation. | Apply cannot discover Reflection from raw records without reinterpreting source structure. |
| E. CURRENT collaborators | `CurrentV1CatalogMapper` composes CAT, classification, Item-local, Author, Reading, Note and Assessment mapper output. | One reviewed mapping stream already exists in dry-run. |
| F. source observation | `SourceObservation` supports deterministic identity, payload hash, optional canonical JSON and optional reference. | Restricted bodies need not be stored in the observation. |
| G. target mappings | Target mappings are a separate zero-to-many table. | A final observation with zero product mappings is valid. |
| H. mapper finding | A finding carries safe identity, disposition, reason, count, optional hash and planned identities only. | It is an artifact projection, not an executable or durable contract. |
| I. disposition/state | MIG-FND already supports `preserved_deferred`; preservation state is `awaiting_future_processing|processed`. | No new semantic pseudo-status is needed. |
| J. restricted evidence | Observation and preservation rows support JSON and a bounded reference; ordinary snapshots omit both. | Existing storage can carry safe descriptors and logical locators. |
| K. hashes | Canonical JSON is SHA-256 bound and record payload hashes are deterministic. | Plan and restricted-source hashes can fail closed. |
| L. replay | Within one run, the same type+ID+hash reuses; changed content conflicts. Product writers additionally inspect prior mappings. | No-target preservation needs an equivalent prior-preservation divergence check across applicable runs. |
| M. reconciliation | Expects raw `inspection.records()` and validates ledger observations/mappings against that raw set. | It cannot yet reconcile the transformed CURRENT mapped-plan universe. |
| N. interruption/resume | Each observation outcome commits transactionally; committed/terminal observations are skipped on resume. | The same mechanism is suitable for preservation. |
| O. second run | A completed run is reused and committed observations are counted as skipped. | Preparation/provenance validation must occur before the completed-run early return. |
| P. privacy/artifacts | Typed plan payloads are not serialized by `PlannedMigrationRecord::toArray()`; artifacts expose safe identities/hashes/reasons; repository writes suppress SQL error output. | Raw restricted evidence must remain outside artifacts, logs and exceptions. |
| Q. provenance | Run binds source family, manifest/fingerprint, source version, migrator version and target context. Reviewed mapper contract IDs currently appear only in findings. | Contract ID/version must become part of the durable typed preservation payload and prepared-plan digest. |

There is no `MigrationSourceMapping` domain class. The operative structures are
`MigrationSourceMappingResult`, `MigrationSourceMappingFinding`, executable
`MigrationSourceRecord` and durable `MigrationTargetMapping`.

Mapper-only evidence currently stops when `MigrationRunner::dryRun()` converts
the mapper findings to artifact arrays. Apply and reconciliation then restart
from raw adapter enumeration. This gap affects all CURRENT mapper-produced
plans, not only Reflection.

## 4. Existing MIG-FND capabilities

Schema 1026 already supports the required final state:

- one durable source observation with deterministic type, ID, snapshot and
  payload hash;
- optional canonical payload JSON and payload reference;
- final disposition `preserved_deferred` plus bounded reason;
- one preservation row linked to the observation;
- zero target mappings; and
- bounded safe evidence JSON/reference.

`MigrationRecordOutcome::preserved()` already accepts an empty mapping list,
and `WpdbMigrationLedgerRepository` inserts the preservation independently of
target mappings. CIRC-01 proves eight accepted no-target preservations. The
Item lane separately proves `preserved_deferred` with active Item/context
mappings.

The representational gap is therefore application/planning/reconciliation,
not schema cardinality.

## 5. Definition of mapper-only preserved evidence

Mapper-only preserved evidence is one reviewed atomic source observation that:

1. is discovered by a manifest-bound source-specific mapper;
2. is valid source truth;
3. has no active product projection and no normal product participant;
4. is not malformed, corrupt or intrinsically contradictory;
5. must survive cutover and may later be promoted by a separate backfill;
6. has deterministic source identity and exact source provenance;
7. has a reproducible canonical evidence hash; and
8. may be restricted/private.

The capability records source truth only. It creates no product entity,
relationship, publication state, lifecycle state, fallback taxonomy or
“miscellaneous” target.

## 6. Admission criteria and exclusions

An executable preservation plan is admitted only when all of these are true:

1. a closed mapper contract names the exact evidence type and reason code;
2. the mapper has classified the atomic evidence as `preserved_deferred`;
3. the type+reason pair is registered in a bounded preservation-admission
   contract;
4. deterministic identity is proven independently of target IDs, random UUIDs
   and private body text;
5. adapter ID, source family/version, manifest and mapper contract are exact;
6. a canonical evidence hash and recoverable source-relative locator exist;
7. the privacy class is explicit;
8. no active participant already durably covers the same evidence; and
9. the related safe finding points to the executable plan identity.

The following never qualify merely because they appear in dry-run:

- diagnostics and generic “source retained” notices;
- warnings, planning errors or unmatched references;
- downstream dependency blockers;
- derived/no-op or convergence findings;
- malformed/contradictory evidence requiring quarantine;
- unsupported source structure without a reviewed semantic mapper contract;
- unknown adapter categories or arbitrary files; and
- already participant-owned preservation.

`MigrationSourceMappingFinding` remains a safe audit projection. It is never
persisted wholesale.

## 7. Source identity

The participant source type is one fixed source-neutral type:

```text
preserved_source_evidence
```

Its `source_id` is a reviewed semantic identity, not a content hash. Examples:

```text
v1.book/<book-id>/reflection
v1.copy/<copy-id>/auxiliary
v1.book/<book-id>/category/<one-based-slot>
v1.book/<book-id>/contained-work/<one-based-slot>
v1.book/<book-id>/read-history/<one-based-slot>
```

For a stable source entity, use its unchanged source ID plus a bounded semantic
slot. For ID-less nested structures, use the stable parent source ID plus the
reviewed field/one-based structural slot only where the source contract proves
that position is unique. Reordering or altered content changes source-package
provenance/hash and fails closed; it does not silently rebind identity.

An aggregate identity is allowed only when the closed mapper contract declares
the aggregate itself atomic. The current taxonomy review queue is the relevant
case: its existing decision forbids 446 invented entry IDs. It may use one
file/category population identity, occurrence count and file/category hash
until a later contract approves per-entry structural identity.

## 8. Restricted payload strategy

The selected strategy is **source-relative locator + immutable package binding
+ restricted evidence hash**, not raw-body duplication in MIG-FND.

The canonical plan stored as observation payload contains only a replay-safe
descriptor. The preservation row contains the same safe hash metadata and a
logical source-relative locator. The immutable retained source package remains
payload authority.

For Reflection, the locator identifies `data/books.json`, the stable Book ID
and the `reflection` field. It never contains a developer-machine absolute
path. The evidence hash covers a typed envelope such as source slot plus exact
body, so a body change is detected without putting the body in DB output.

This choice is already settled by the closed assessment contract: Reflection
bodies remain exclusively in the immutable CURRENT package. It also follows
the existing `CatalogItemPreservationPlan` hash+reference pattern. No Renée
decision is required.

If a future admitted evidence class cannot remain recoverable through a stable
locator and retained package, that class requires a new bounded design. It may
not fall back to a raw arbitrary JSON dump.

## 9. Privacy model

MIG-FND may store:

- semantic source identity;
- evidence type and bounded reason;
- adapter/source/manifest/contract provenance;
- privacy classification;
- source-relative locator;
- canonical plan hash and restricted evidence hash;
- safe booleans/counts explicitly allowlisted by the typed plan; and
- processing state.

MIG-FND must not store Reflection bodies, Copy-note text, private circulation
counterparties/notes, private assessment content, private reading prose or
unbounded raw source objects for this capability.

Restricted evidence is readable only from the retained package through a
future internal migration/backfill evidence reader operating under explicit
operator authorization. No REST route, normal product repository, admin page,
Library role, support dump or public CLI output may read it. Super-admin status
alone does not create product-level access.

Ordinary dry-run/apply/reconciliation artifacts, logs and exceptions expose
only allowlisted identity, hashes, reasons, counts, contract/provenance and
safe processing state. Database backups contain restricted operational
metadata; the separate source bundle contains the private payload and requires
restricted backup/access handling.

## 10. Durable disposition and no-target representation

The only admitted semantic disposition is exactly:

```text
preserved_deferred
```

Every plan has one explicit bounded reason such as
`reflection_target_not_available`. The MIG-FND observation becomes
`processing_status=committed`, the preservation becomes
`awaiting_future_processing`, and target mappings remain empty.

No `ignored`, `skipped`, `archived` or `unresolved` pseudo-disposition is
introduced. Technical resume/skipping and future-processing state remain
separate from semantic disposition.

## 11. Architecture options

| Option | Strengths | Blocking weakness | Verdict |
|---|---|---|---|
| A. Mapper-aware apply runner | Makes reviewed mapper output reachable by apply and improves parity. | Alone it could persist arbitrary findings and has no bounded source-neutral execution contract. | Necessary component, insufficient alone. |
| B. Source-neutral preservation participant | Reuses participant, transaction, replay and registry boundaries; contains no CURRENT semantics. | Alone it is unreachable because apply does not consume mapper output. | Necessary component, insufficient alone. |
| C. Auxiliary preservation phase | Keeps product and evidence loops visibly separate. | Duplicates planning/order/resume/accounting, increases drift and complicates active+preserved truth. | Rejected. |
| D. Shared prepared mapping stream + typed preservation participant | One plan universe for dry-run/apply/reconciliation; semantic decision stays in mapper; durable write stays source-neutral. | Requires a bounded runner/reconciliation refactor and explicit admission registry. | **Chosen.** |

## 12. Chosen architecture

Introduce one source-neutral prepared-planning boundary, provisionally named
`PreparedMigrationPlan` produced by a `MigrationPlanPreparer`.

```text
immutable source inspection + exact target
    -> registered source-specific mapper
    -> prepared executable records + safe findings + contract bundle
    -> dry-run artifact
    -> apply through participant registry
    -> reconciliation against the same executable record set
```

The prepared result contains:

- the original immutable inspection;
- the sorted executable `MigrationSourceRecord` list;
- safe diagnostic/mapping findings;
- reviewed mapper contract identities;
- exact target context; and
- a deterministic privacy-safe plan-set digest.

The runner infrastructure does not decide whether Reflection, Series,
classification or another source fact deserves preservation. The reviewed
source mapper does. The generic layer only validates admission/provenance and
commits the typed outcome.

## 13. Typed preservation contract

Use a source-neutral `PreservedSourceEvidencePlan` implementing
`TypedMigrationPlan`, named finally according to repository conventions.

Required typed fields are:

| Field | Constraint |
|---|---|
| evidence type | bounded token registered by the admission contract |
| semantic disposition | fixed internally to `preserved_deferred`; not caller-selectable |
| reason code | bounded registered type+reason pair |
| source identity | exact semantic identity equal to the record identity |
| adapter/source | adapter ID, source family and source version |
| source package | exact manifest SHA-256 |
| mapping contract | existing reviewed mapper contract ID/version convention |
| locator | logical source-relative semantic locator, max existing 1,024-char bound |
| evidence hash | SHA-256 of the exact restricted evidence envelope |
| privacy class | fixed bounded enum such as `restricted_source` or `ordinary_source` |
| occurrence count | positive integer only for an approved atomic aggregate |
| safe metadata | closed typed fields only; no arbitrary map |

The plan canonical payload contains these fields and no raw restricted body.
Its `MigrationSourceRecord` payload hash therefore binds reason, contract,
manifest, locator and evidence hash.

The one participant:

- creates no product entity or relation;
- accepts no disposition other than `preserved_deferred`;
- accepts no target mapping;
- normally accepts no product dependency;
- validates record, plan, observation, target and provenance equality;
- validates the type+reason admission;
- performs no product write; and
- returns `MigrationRecordOutcome::preserved(reason, safeMetadata, locator)`.

Register one empty reconciliation mapping contract. Any accidental target edge
is then broken reconciliation evidence.

## 14. Mapper to apply handoff

Mappers emit an executable preservation record beside their safe finding. The
finding lists that record in `planned_identities`; it does not substitute for
the record.

Dry-run and apply both call the same preparer. `MigrationApplyRunner::plans()`
must receive the prepared executable records, never `inspection.records()`.
Reconciliation must receive the same prepared result. Apply must not rediscover
or reclassify preservation semantics.

This shared handoff also closes the broader current defect where CURRENT
product plans are visible to dry-run but raw records are used by apply.

## 15. Dry-run/apply parity

The plan-set digest is SHA-256 over canonical, sorted safe data containing:

- adapter ID, source family/version and manifest;
- exact target User and Library;
- mapper-contract bundle identities;
- every executable record type, ID and payload hash;
- references/dependencies and planned disposition/reason; and
- safe finding-to-plan links.

The later internal apply call requires the accepted dry-run plan-set digest as
an explicit input. It rebuilds the prepared result and refuses before any run
or observation write when the digest, manifest, adapter/source version,
contract bundle or target context differs.

Preparation and parity validation occur before the completed-run early return.
Thus an exact second apply can be a zero-new-observation replay, while changed
mapping semantics cannot be hidden by returning an older completed result.

No public or broad production apply CLI is authorized here.

## 16. Replay and divergence

Required behavior:

| Condition | Result |
|---|---|
| Same run, source type+ID, payload/provenance/reason/contract equal | Reuse committed observation; no new preservation. |
| Same run, same identity, divergent plan/evidence hash | Fail closed through observation conflict. |
| Applicable prior run, same logical identity and exact preservation contract | Reuse/confirm prior preserved truth according to the run contract; never duplicate within one run. |
| Same identity, changed reason or mapping contract | Fail closed by default. |
| Explicit future contract upgrade | Separate reviewed upgrade path and run identity; no implicit last-write-wins. |

The later implementation adds a prior-preservation query because existing
prior-target lookup sees only observations with mappings and prefilters on
payload hash. That shape cannot detect the changed-hash conflict which this
contract must reject.

For this query, an applicable prior run is any other run for the exact target
User and Library that contains a matching observation with
`processing_status=committed`, `disposition=preserved_deferred` and its
preservation row. This applies regardless of whether that run is currently
running, interrupted, failed or completed: a committed per-record truth
survives the later run status. Lookup uses target User, target Library, source
family, preservation source type and semantic source ID, with **no payload-hash
prefilter**. Zero matches permits the new observation. One match, or multiple
matches that are byte-for-byte equivalent on the fields below, permits
reuse/confirmation according to the run contract. Multiple non-equivalent
matches fail closed as an already-existing divergence.

Before any observation or product write, compare the record payload hash,
evidence hash, disposition, reason, locator, privacy class, mapper contract,
adapter ID, source family/version and manifest provenance. Any difference in
payload, evidence, contract or provenance across runs fails closed unless a
separately reviewed explicit upgrade path applies. There is no last-write-wins
or automatic repair.

## 17. Mapping-contract provenance

Use the current reviewed-contract convention, for example:

```text
d-mig-assess-map-01.2026-09-17:<manifest-prefix>
```

Do not invent a parallel versioning system. The exact contract identity is
stored in each plan; the sorted contract bundle contributes to the plan-set
digest and existing `migrator_version` run identity. Adapter ID, source family,
source version and full manifest digest remain independently bound.

## 18. Active plus preserved coexistence

Choose the model according to atomic source truth:

1. **Independent structural evidence** becomes its own no-target preservation
   observation. Reflection and Book-level acquisition evidence use this model.
2. **Auxiliary evidence inseparable from an active product observation** stays
   participant-owned in that observation. The existing `CatalogItemPlan` plus
   `CatalogItemPreservationPlan` is the accepted model: one
   `preserved_deferred` outcome may carry the required Item/context mappings.

The new generic participant handles model 1 only. It must not duplicate the
744 Item observations already covered by model 2. One atomic observation never
receives contradictory final dispositions.

## 19. Transaction, interruption and resume

Each independent preservation observation commits through
`CommitMigrationRecordService` in one transaction containing:

- locked source observation;
- preservation row; and
- final observation disposition/reason.

There is no product write and no target edge. Independent preservation normally
has no dependency on a product plan, because source truth must survive even
when product projection fails. A future exception requires an explicit typed
dependency justified by its mapper contract.

If apply stops after product records, midway through preservation records, or
after all records but before the reconciliation artifact, the same prepared
plan is rebuilt. Committed observations are skipped, missing observations are
committed, and reconciliation is regenerated. No duplicate preservation is
created. Record order is deterministic but correctness does not depend on a
single all-run transaction.

## 20. Reconciliation

RECON-01 must use two explicitly separate universes:

1. raw adapter/category completeness from the immutable inspection; and
2. durable executable-record completeness from `PreparedMigrationPlan`.

For executable records it proves:

```text
prepared executable observations
= committed mapped/transformed observations
+ committed preserved_deferred observations
+ committed quarantined/other explicit dispositions
+ uncommitted/unexplained observations
```

Acceptance requires the last term to be zero and also requires:

- each preservation observation has exact type, identity, payload hash,
  reason, contract and provenance;
- no-target preservation has zero mappings and one awaiting preservation;
- participant-owned active+preserved observations satisfy their normal mapping
  contract;
- findings linked to plans are not counted as extra observations;
- diagnostic findings remain artifact accounting only; and
- no preservation/quarantine double count exists.

Reports expose only safe identities, hashes, reasons and counts. They never
load or print restricted payload.

## 21. Future promotion

Promotion is not implemented or authorized here. A future bounded backfill
must atomically:

- lock the original observation and preservation;
- verify the retained package, locator, evidence hash and approved upgraded
  contract;
- leave original observation payload/hash/reason/disposition unchanged;
- create the approved product mapping referencing that original observation;
- mark preservation `processed`; and
- reconcile the promoted target.

Source identity is never replaced and historical evidence is never rewritten.
Normal product authorization and mapping rules apply at promotion time.

## 22. CURRENT Reflection proof case

The pinned source contains exactly five valid non-empty Reflections. The
assessment mapper already proves five distinct identities of the form
`v1.book/<book-id>/reflection` and five distinct SHA-256 evidence hashes.

The later implementation emits five `preserved_source_evidence` records with:

- evidence type `current_v1_reflection`;
- the existing structural identity;
- disposition `preserved_deferred`;
- reason `reflection_target_not_available`;
- adapter/source/manifest and
  `d-mig-assess-map-01.2026-09-17:<manifest-prefix>` provenance;
- privacy class `restricted_source`;
- a source-relative `data/books.json` Book+field locator;
- the existing exact evidence hash; and
- no body, dependency, operation or target mapping.

Expected durable result:

| Measure | Result |
|---|---:|
| deterministic Reflection observations | 5 |
| `preserved_deferred` | 5 |
| distinct evidence hashes | 5 |
| preservation rows awaiting future processing | 5 |
| product targets/mappings | 0 |
| bodies in ordinary DB descriptor/artifact/log/error | 0 |

An internal restricted recovery check resolves every locator from the retained
package and recomputes the exact hash without emitting the body. Exact replay
adds zero observations; changed body/reason/contract fails closed.

## 23. Other CURRENT preserved evidence audit

Classification:

- **A:** existing durable participant route;
- **B:** valid semantic mapper-only evidence requiring this capability; and
- **C:** diagnostic/derived/profile-only or quarantine; do not persist through
  this capability.

### A. Already durably covered

| Domain/population | Count | Class | Current route | Needs new capability? | Sensitive? | Reason/promotion |
|---|---:|---|---|---|---|---|
| coherent circulation | 8 | A | `CirculationMigrationParticipant`, zero mappings | no | yes | `circulation_product_target_deferred`; later loan backfill only |
| planned Item + Copy auxiliary evidence | 744 | A | `CatalogItemMigrationParticipant`, Item/context mappings plus preservation | no | mixed/private possible | `copy_auxiliary_evidence_preserved`; later bounded auxiliary promotion only |
| stable Book Notes | 17 | A | active `PrivateNoteMigrationParticipant` mapping | no | yes | Not preservation; no new capability needed |

Within Copy evidence, three Copy-note occurrences exist: two are already in the
744 Item observations and one belongs to an external-borrowed Copy in B. The
single disposal and single exemplar-photo occurrence are already in A.

### B. Mapper-only populations requiring typed admission

| Domain/population | Count | Class | Current route | Needs new capability? | Deterministic identity | Sensitive? | Reason | Future promotion |
|---|---:|---|---|---|---|---|---|---|
| Category assignments | 1,264 | B | mapper finding only | yes | Book + category slot | no | `category_assignment_preserved` | possible after reviewed taxonomy mapping |
| unsupported Genre assignments | 761 | B | mapper finding only | yes | Book + genre slot | no | `genre_assignment_preserved` | possible after reviewed mapping |
| preserved classification definitions | 55 | B | mapper finding only | yes | dimension+status+exact raw definition identity | no | `classification_definition_preserved` | possible |
| taxonomy review queue | 446 occurrences | B | mapper finding only | yes | approved atomic file/category population identity; no invented entry IDs | restricted contexts | `taxonomy_review_queue_preserved` | later explicit review |
| taxonomy alias rules | 7 | B | mapper finding only | yes | stable rule ID + file hash | no | `taxonomy_alias_rules_preserved` | separate contract only |
| classification review-blocked Books | 355 | B | mapper finding only | yes | Book + classification-review slot | no | `classification_source_review_blocked` | approved per-Book decision only |
| Copy auxiliary evidence without Item plan | 357 | B | mapper finding only | yes | Copy + auxiliary slot | mixed | `copy_auxiliary_evidence_preserved` | after blocking lane resolves |
| external-borrowed Copy evidence | 5 | B | mapper finding only | yes | Copy + external-borrowed slot | yes where text exists | `external_borrowed_copy_preserved` | later loan/backfill; never Item by assumption |
| Book acquisition projections | 68 | B | mapper finding only | yes | Book + acquisition-evidence slot | yes | `book_acquisition_evidence_preserved` | later bounded lane only |
| extended stable Author metadata | 256 | B | mapper finding only | yes | stable Author + auxiliary slot | bibliographic | `author_source_evidence_retained` | possible metadata lane |
| unsupported Author kinds | 3 | B | mapper finding only | yes | stable Author or Book+occurrence position | bibliographic | `unsupported_author_entity_kind` | entity-kind decision only |
| contributor under CAT-quarantined Book | 2 | B | mapper finding only | yes | Book + author occurrence position | bibliographic | `catalog_work_unavailable` | exact CAT resolution only |
| active-like Round without concrete source | 3 | B | mapper finding only | yes | stable ReadingRound ID | private | `active_round_missing_concrete_source` | exact physical source only |
| historical paused audit event | 1 | B | mapper finding only | yes | Book + read-history slot | private | `historical_paused_audit_evidence` | no automatic promotion |
| ambiguous registration/reread | 1 | B | mapper finding only | yes | Book + registration slot | private | `ambiguous_duplicate_or_reread` | reviewed future decision |
| conflicting unread fact | 2 | B | mapper finding only | yes | Book + read-status slot | private | `conflicting_book_read_status` | reviewed future decision |
| Reflections | 5 | B | mapper finding only | yes | Book + reflection slot | private | `reflection_target_not_available` | only after Reflection target |
| variant relations | 5 | B | mapper finding only | yes | Book + variant-relation slot | no | `deferred_variant_relation` | reviewed relation semantics |
| contained-work occurrences | 22 | B | mapper finding only | yes | parent Book + contained-work position | bibliographic | `deferred_contained_work` | expected containment lane |
| contained-work Author values | 17, subset of 22 | B | mapper finding only | yes | contained-work identity + author slot | bibliographic | `contained_work_author_preserved` | containment/Author lane; do not double-count parent occurrence |
| allowlisted deferred Edition evidence | 1,139 Books | B | mapper finding only | yes | Book + bounded Edition-evidence slot | generally no | `deferred_edition_evidence` | later metadata lanes |

### C. Finding-only, derived or not yet admitted

| Population | Count | Class | Current route | Needs new capability? | Why it is excluded |
|---|---:|---|---|---|---|
| `catalog_source_evidence_retained` | 1,139 | C | diagnostic finding | no | Generic provenance diagnostic, not an atomic semantic fact. |
| `conservative_item_subset` | 910 | C | diagnostic finding | no | Planning classification, not source truth. |
| `unresolved_classification_dependency` | 355 | C | diagnostic finding | no | Derived blocker duplicating underlying classification evidence. |
| duplicate reading-truth evidence | 14 | C | transformed/no-op | no | Transformed/no-op convergence. |
| other derived read-history events | 1,654 | C | transformed/no-op | no | Corroborating no-op evidence; only the paused slot is admitted. |
| classification convergence conflicts | 7 groups / 14 members | C | quarantine | no | Quarantine boundary, not preservation. |
| invalid-only ISBN Books | 2 | C | quarantine | no | Quarantine boundary; distinct valid Copy auxiliary evidence may still be B. |
| Series profile | 164 `series=true`, 161 named, 108 positions | C | source profile only | no | No closed Series mapper or reviewed occurrence/position contract. Requires a separate Series lane; this design does not start it. |
| Reading Goals | 2 stable records | C | source profile only | no | No reviewed mapper disposition/reason yet; requires a bounded mapping/admission slice. |
| caches, reports, preferences and release state | package populations | C | retained source package | no | No reviewed semantic mapper/atomic identity; package retention is sufficient for now. |

The listed counts overlap and must not be summed blindly. In particular,
contained-work Author values are a subset of contained-work occurrences and
Copy note/disposal/photo/source-number facts are flags within Copy auxiliary
observations.

## 24. Production-cutover completeness

The final privacy-safe CURRENT artifact reports 7,922
`preserved_deferred` mapping-finding occurrences. That number is not the number
of durable observations:

- some findings are already represented by participant-owned preservation;
- some are valid B populations that need typed atomic/aggregate plans;
- some are C diagnostics that must remain non-durable; and
- several populations overlap structurally.

Production apply completeness is therefore proven by an identity-set ledger,
not by summing finding counts. Before apply can be reconsidered, every B row
must either have an admitted typed plan with a unique identity or a separately
reviewed reclassification. Every C row must remain excluded and every
quarantine population must remain quarantine.

The five Reflections are the first symptom, not the only mapper-only gap.

## 25. Quarantine boundary

`preserved_deferred` means valid truth with no current projection. Quarantine
means malformed, structurally ambiguous, contradictory or untrustworthy atomic
evidence.

A valid auxiliary slot may record counter-evidence beside a separately active
truth only when its closed mapper contract explicitly establishes that slot as
valid evidence. This explains the reviewed preserved unread-status facts
without turning corruption into preservation. Preservation never repairs an
invalid ISBN, classification convergence conflict, malformed Author scalar or
other quarantine case.

## 26. Schema impact

**No schema change is required.** Schema 1026 already provides:

- observation payload/hash/reference;
- zero-to-many target mappings;
- `preserved_deferred` plus reason;
- one preservation row per observation; and
- awaiting/processed future state.

The later slice changes application services, typed plans, participant
composition, prior-preservation lookup and reconciliation only. Product schema
is out of scope. If implementation discovers that an admitted identity,
locator or safe payload exceeds existing bounds, it must stop for a new design
instead of widening columns or storing raw evidence opportunistically.

## 27. Security and privacy review

- Migration tables are restricted operational data, not product-readable data.
- No REST/controller/UI projection is added.
- No Library role gains private evidence access.
- The future evidence reader requires explicit internal migration authority and
  exact target/run/source scope.
- Ordinary snapshots/reconciliation continue to omit payload/evidence JSON.
- SQL error output remains suppressed around evidence writes.
- Error text uses fixed safe messages and reason codes only.
- Hashes may be exposed; bodies and exact private timestamps may not.
- Backups containing the source package receive private-data controls.
- Debug/support tooling must not dump preservation/observation raw columns.

## 28. Source-retention requirements

Because the immutable package remains restricted payload authority, cutover
must retain a location-independent evidence bundle containing:

- the exact source archive or canonical extracted package;
- original archive SHA-256 where available;
- complete extracted manifest and SHA-256;
- adapter/source version and mapper-contract bundle;
- plan artifact and checksum;
- access-control classification; and
- at least one tested restricted backup/restore path.

MIG-FND stores logical locators, never one local absolute path. Disaster
recovery is incomplete unless both the migration DB and matching source bundle
can be restored. Loss of the package means restricted evidence is no longer
recoverable and blocks promotion/audit; hashes alone are not payload recovery.

## 29. Required implementation slice

Recommended bounded follow-up:

```text
MIG-02-PRESERVE-01 — Durable mapper-only preservation
```

It should implement only this accepted foundation contract:

1. shared prepared-plan builder and deterministic plan-set digest;
2. dry-run/apply/reconciliation use of the same prepared executable records;
3. typed plan, admission registry, plan guard and one source-neutral
   preservation participant;
4. safe no-target MIG-FND commit and prior-preservation divergence query;
5. Reflection mapper emission for the five proof records;
6. privacy-safe reconciliation/artifact updates;
7. interruption, resume and exact second-run evidence; and
8. synthetic active+preserved coexistence regression proof.

The remaining B populations must then be converted only through their already
closed mapper semantics or separately authorized mapping slices. They may not
be bulk-converted from findings. Production apply remains blocked until the
cutover identity-set audit shows no B gap.

No production apply CLI, product model, schema, promotion service, Series lane
or broad generic evidence API belongs in this implementation slice.

## 30. Acceptance criteria and future tests

The later implementation is GO only when tests prove:

1. a no-target preserved observation commits;
2. exact replay reuses it and adds zero observations/preservations;
3. divergent evidence payload fails closed;
4. changed reason or mapper contract fails closed absent explicit upgrade;
5. private payload is absent from artifacts, logs and exceptions;
6. restricted evidence is recoverable from retained package+locator and its
   hash matches;
7. no product target or relation is created;
8. reconciliation counts preservation distinctly from mappings, quarantine
   and diagnostics;
9. interruption/resume converges before, during and after preservation;
10. active mapping plus participant-owned auxiliary preservation remains
    supported without duplication;
11. all five Reflections commit with distinct hashes and zero mappings;
12. second apply produces zero new observations;
13. raw source, prepared plans, mapper findings and ledger accounting have no
    unexplained or double-counted identity; and
14. changed manifest/adapter/source/target/contract/plan-set digest is refused
    before writes;
15. a committed preservation observation in any prior scope-matching run is
    found without a payload-hash prefilter, including when that run is
    running, interrupted, failed or completed; exact equivalent preservation
    is reusable, and changed payload, evidence hash or provenance fails before
    observation/product writes; and
16. multiple prior preservation matches are accepted only when equivalent and
    otherwise fail closed.

No tests are implemented or run in this design slice.

## 31. Product/architecture decision required from Renée

**None.** Existing accepted contracts settle both consequential choices:

- restricted Reflection bodies remain source-located in the immutable package;
  and
- source-specific mappers decide semantics while source-neutral participants
  perform durable commits.

Storing raw Reflection bodies in MIG-FND, persisting generic findings or using
a separate rediscovery apply path would contradict those contracts and would
require a new decision.

## 32. Production apply gate

This design authorizes no apply/import. Production apply remains blocked until:

1. `D-MIG-PRESERVE-01` is GO/CLOSED;
2. `MIG-02-PRESERVE-01` is GO/CLOSED with replay/resume/reconciliation proof;
3. every admitted CURRENT B population has a durable route and C remains
   excluded;
4. all remaining CURRENT mapping lanes are complete;
5. a fresh final export is explicitly designated and manifest-bound;
6. final guarded cutover rehearsal and disaster-recovery evidence pass; and
7. Renée explicitly authorizes production apply.

## 33. Design verdict

**DESIGN GO.** Mapper-only preserved evidence, admission, deterministic
identity, source-located restricted payload, privacy, no-target MIG-FND state,
shared apply seam, replay, contract provenance, coexistence, resume,
reconciliation, future promotion, Reflection proof, population completeness,
schema sufficiency and source retention are closed without implementing or
authorizing production apply.
