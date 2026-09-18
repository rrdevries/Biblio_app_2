# D-MIG-WISHLIST-MAP-01 — Current V1 Wishlist mapping

Status: **DESIGN GO / CLOSED**

Date: 2026-09-18

Task severity: **High**

Scope: product, Wishlist and migration-mapping design only. This document adds
no production mapper, migration participant, schema, REST, UI or runtime
behavior and authorizes no apply/import. V1 is source evidence, not product
authority.

## 1. Decision inheritance

Authority for this decision, in order:

1. Renée's 2026-09-18 manifest-bound product decision that all 41 CURRENT
   Wishlist records map as Work-only;
2. accepted V2 Wishlist canon and the implemented schema-1022 Core/API
   contracts;
3. the closed CURRENT CAT Work/Edition mapping;
4. MIG-FND, RUN-01, RECON-01 and MIG-02-PRESERVE-01; and
5. the designated immutable CURRENT package as source evidence.

The audited checkout is clean `main` at
`3c2b74f1f227fbd78cd96ba0d41ae4c543c41644`. Product is `v2.001`, schema is
`1026`, Biblio Core is `2.45.0` and Biblio UI is `0.20.0`.

The earlier source-dependent design was held because the raw value
`type=edition` did not prove deliberate Edition intent. Renée has now closed
that product question for this exact manifest: all 41 entries are Work-only.
This is a bounded CURRENT mapping decision, not a general rule for every
legacy source that uses the word `edition`.

## 2. CURRENT source evidence

Only this designated package was used:

| Provenance | Audited value |
|---|---|
| Authoritative ZIP | `/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip` |
| Validated read-only extraction | `.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/` |
| Local intake ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Files / bytes | 3,587 / 19,801,196 |
| Adapter | `current-v1-json-29` |
| Source family | `biblio-v1` |
| Source version | `books-29.authors-2.reading-goals-2` |

The production package builder recomputed the exact manifest and file count.
The latest clean zero-write artifact and its checksum sidecar are valid. The
extraction was not recreated or changed. DATA-01, fixtures, historical ZIPs,
providers and network lookup were not used.

The source contains 41 stable `v1.wishlist_item` observations. All IDs are
unique, all 41 `bookId` values are unique and every Book reference exists.
The privacy-safe sorted Wishlist→Book→CAT relation set has SHA-256:

```text
56c1a94f955c10b3f8389e9acbb3e5fe362c1aea0ba87e9e831062ab7ea99489
```

## 3. Existing V2 Wishlist canon

An active Wishlist Entry is private, platform-wide and owned by exactly one
User. It targets exactly one existing Work and has exactly one of two forms:

- Work-only: the user wants the Work and Edition is irrelevant; or
- Edition-specific: the user wants one exact Edition of that Work.

For one User+Work, V2 permits at most one Work-only entry or zero or more
distinct Edition-specific entries, never both. Exact duplicate adds are
idempotent. Work-only→Edition refinement is explicit and retains stable entry
identity and creation time. Edition-specific→Work-only is not an implicit
update path.

Wishlist is not Library-owned, Desired Acquisitions, Hierna lezen, Collection
membership, Item possession or fulfillment state. Add Book, acquisition,
reading, Collection changes and Item ownership never mutate it automatically.

Schema 1022, still active in schema 1026, has sufficient product storage:

- `wishlist_work_states` owns the exclusive User+Work mode/lock;
- `wishlist_entries` stores active stable entries, owner, Work, optional
  Edition and creation/update instants; and
- `wishlist_entry_history` stores removed snapshots and typed removal reason.

No schema, REST or UI extension is required by this decision.

## 4. Source shape

`data/books.json#wishlistItems/*` has one exact 16-field shape:

```text
authorSnapshot
bookId
createdAt
desiredBinding
desiredCarrier
desiredLanguage
fulfilledAt
fulfilledCopyId
id
notes
priority
status
titleGroupKey
titleSnapshot
type
updatedAt
```

There is no owner/User ID, Edition ID, ISBN, publisher, publication date or
other direct publication identity on a Wishlist record. Its primary relation
is the stable `bookId` to one legacy Book aggregate. A non-empty
`fulfilledCopyId` would be its only direct Copy reference, but CURRENT has none.

The exact populations are:

| Field/evidence | CURRENT count |
|---|---:|
| stable records / unique IDs | 41 / 41 |
| unique, valid `bookId` references | 41 |
| `type=edition` | 41 |
| `status=active` | 41 |
| non-empty `fulfilledCopyId` / `fulfilledAt` | 0 / 0 |
| valid non-empty `createdAt` / `updatedAt` | 41 / 41 |
| `updatedAt >= createdAt` | 41 |
| equal creation/update instant | 40 |
| non-empty notes / priority | 0 / 0 |
| non-empty desired language / binding | 0 / 0 |
| `desiredCarrier=Fysiek boek` | 2 |
| non-empty `titleGroupKey` | 41 |
| non-empty title / author snapshot | 41 / 40 |

The Wishlist object contains no exact Edition identity. Thirty-five referenced
Books carry some ISBN evidence, but that is Book/CAT evidence and never proves
Wishlist intent.

## 5. Ownership

CURRENT carries no owner field. Existing migration governance closes this
without inference: each prepared Wishlist plan must use the explicit,
server-validated `MigrationPlanningTarget::userId()`.

The latest clean isolated artifact used target User `2`. That concrete value is
run evidence, not a hardcoded mapper constant. Planning, apply, replay and
target inspection must all require the plan User to equal the validated run
target User. There is no fallback to current actor, administrator, first User,
display name, Library Owner or membership role.

## 6. Library boundary

The explicit target Library remains part of run identity and is required by
CAT/Item dependencies and migration authorization. It does not own or scope a
Wishlist Entry. The Work-only product write uses only the exact target User and
resolved platform Work.

No Library membership, role, Item ownership, catalog context or possession
state may change the mapping or grant access to another User's Wishlist.

## 7. Work dependency

Every active plan resolves only through this exact chain:

```text
v1.wishlist_item:<stable-wishlist-id>
  → v1.book:<exact-book-id>
  → catalog_work:v1.book/<exact-book-id>/work
```

All 41 Work dependencies exist in the reviewed prepared CAT stream. No title,
Author, Series, ISBN, snapshot, group key or fuzzy lookup participates.

## 8. Edition-specificity

For manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`,
all 41 records map to **Work-only Wishlist**.

The raw value `type=edition` is not interpreted as deliberate user choice of
the linked CAT Edition because:

- it is the only observed raw value;
- the Wishlist record contains no direct Edition/publication identity;
- `bookId` points only to the legacy Book aggregate;
- available CAT Edition identity proves catalog identity, not Wishlist intent;
- `wishlistEditionPreference=specific` is a global default on all 1,139 Books;
  and
- accessible CURRENT source/code provides no stronger intent evidence.

The mapper therefore creates no active CAT Edition dependency. An ISBN, one
available CAT Edition or one archived Copy cannot narrow the intent.

This decision does not change V2's general Edition-specific Wishlist feature
and does not establish a cross-source `edition`→Work-only fallback.

## 9. Raw type semantics

| Raw value | Population | Reviewed source semantics | V2 specificity | Mapping status | Active | Preserved | Product decision required |
|---|---:|---|---|---|---|---|---|
| `edition` | 41 | Uniform legacy Wishlist record type; insufficient evidence of deliberate exact-Edition intent | Work-only | `EXACT_TARGET` | Yes | Raw value remains traceable; auxiliary evidence is preserved separately | No |
| any other value | 0 | Not reviewed for this manifest | None | `NO_DATA` | No | No | A future population requires a new review |

The implementation contract must fail closed if the manifest, population or
raw type distribution differs. There is no generic fallback.

## 10. Active / fulfillment semantics

All 41 records explicitly say `status=active`; activity is not inferred merely
from an empty fulfillment field. Both `fulfilledCopyId` and `fulfilledAt` are
empty in every record, so CURRENT requires no fulfilled history write.

The active product outcome is presence in `wishlist_entries`. No fulfillment
flag is invented. The legacy active status and empty fulfillment shape remain
source/mapping evidence.

If a future reviewed source contains a populated `fulfilledCopyId`, this
contract does not automatically apply. A valid, exact Copy reference must
resolve through stable Copy→Item mapping and remain `preserved_deferred` until
its source lifecycle semantics are separately approved. Missing, malformed or
contradictory Copy evidence is a quarantine candidate. An arbitrary owned Item
is never substituted.

## 11. Owned Copy coexistence

Thirty-nine Wishlist Books have no Copy. Two have exactly one Copy each; both
Copies are `archived=true`, `status=disposed`, `ownershipStatus=none`.

CURRENT therefore has:

- zero wished Books with an active Copy;
- two wished Books with archived Copy evidence; and
- zero active currently-owned Copy overlaps.

Neither active possession nor archived evidence would automatically fulfill,
remove, narrow or otherwise mutate Wishlist intent. The two archived Copies
retain their independent CAT Item/archive lanes.

## 12. Archive boundary

Archive and Wishlist are separate lifecycles. An archived Copy does not prove
that the Wishlist is newly active, stale, fulfilled, corrected or intended for
another Edition. Wishlist mapping writes no Item archive state, and Archive
mapping writes no Wishlist state.

## 13. Duplicate / convergence behavior

CURRENT has:

- 41 unique Wishlist IDs;
- 41 unique source Books;
- 41 unique resolved CAT Work roots;
- zero Work convergence;
- zero duplicate target slots; and
- zero mixed-intent conflicts.

The general implementation rule remains fail-closed. Multiple source
observations that resolve to exactly the same approved Work-only target may
only converge through explicit idempotent reuse, with every stable source
identity mapped to that one target. Different payload, specificity or target
state is a conflict and never last-write-wins.

## 14. CAT aliases

CAT has 17 duplicate-ISBN representative/alias groups. No CURRENT Wishlist
record occurs on a representative or alias side, and no group contains a
Wishlist record on both sides.

Wishlist still resolves only through CAT mappings. ISBN receives no special
Wishlist rule. A future alias convergence would use exact CAT Work mappings
and the convergence contract above.

## 15. CAT-quarantined dependencies

Neither of the two invalid-only CAT Books is Wishlist-referenced. All 41 Work
dependencies are available; none is quarantined or unmatched.

A future quarantined Book cannot produce a dangling Wishlist target. Valid
intent without a safe current Work projection is `preserved_deferred`;
malformed or contradictory source is quarantined. No Work is fabricated.

## 16. Source identity

The authoritative observation identity is:

```text
source_family = biblio-v1
source_type   = v1.wishlist_item
source_id     = <unchanged stable wishlistItems[].id>
```

The future typed source-neutral plan may use repository-native participant
type `wishlist_entry`, but it must carry the unchanged logical source ID and
the raw observation hash. It may not derive identity from Work, Edition, ISBN,
target User, timestamp or group key.

The generated V2 Wishlist Entry ID remains product identity. MIG-FND binds the
stable source observation to target type `wishlist_entry` and that generated
target ID.

## 17. Target cardinality

The manifest-bound result is exactly one Work-only target slot per record:

```text
41 source observations
→ 41 unique User+Work slots
→ 41 Work-only Wishlist plans
```

There are no Edition-specific plans, duplicate target slots, target
convergences or target-mode conflicts. Work-only exclusivity is validated
against the complete prepared set before any future write.

## 18. Metadata / timestamps

All 41 exact UTC `createdAt` and `updatedAt` instants map to the matching V2
creation/update semantics. Migration time, Book timestamps and `now()` are not
historical substitutes.

Schema 1026 can store both instants, but the current `WishlistRecorder` uses
its clock and creates entries with equal creation/update time. The future
source-neutral migration writer therefore needs a migration-only historical
create path that accepts both validated instants while preserving ordinary
self-service clock behavior.

Priority, notes, desired language and desired binding are empty for all 41 and
create no active fields. Title/author snapshots do not override CAT identity.
The 41 group keys and two desired-carrier values follow the preservation rule
below.

## 19. Typed migration contract

`MIG-02-WISHLIST-MAP-01` must add the smallest source-neutral contract,
conceptually `WishlistPlan`, with:

- unchanged stable source Wishlist identity;
- exact target User;
- target shape fixed to `work_only` for this mapping contract;
- exact CAT Work source dependency;
- no CAT Edition dependency;
- exact source `createdAt` and `updatedAt` instants;
- active state only;
- reviewed mapping-contract ID and source/manifest provenance; and
- deterministic replay payload/hash.

The participant/writer must run inside `CommitMigrationRecordService`, create
or exactly reuse the Work-only entry, emit target mapping `wishlist_entry`,
and verify owner, Work, target form and timestamps on replay. Divergent prior
product state, reverse mapping, unexpected Edition-specific state or changed
payload fails closed.

The lane also requires participant registration, CURRENT prepared-stream
composition, mapping-contract registration, reconciliation accounting and
`CoreMigrationTargetInspector` support. REST is not a migration endpoint.

## 20. Preservation / quarantine

Every raw Wishlist record also carries valid auxiliary truth that is not an
active V2.001 Wishlist field:

- the raw `type=edition` evidence;
- one non-empty `titleGroupKey` on all 41 records; and
- `desiredCarrier=Fysiek boek` on two records.

Each raw type, `titleGroupKey` and exact desired-carrier value receives one
bounded Wishlist auxiliary `preserved_deferred` plan per raw source record
under MIG-02-PRESERVE-01. Empty desired-carrier values remain exact envelope
values; the two non-empty values therefore require no alternate plan shape.

The executable preservation contract is:

| Field | Exact contract |
|---|---|
| prepared source type | `preserved_source_evidence` |
| source identity | `v1.wishlist_item/<stable-wishlist-id>/auxiliary` |
| evidence type | `current_v1_wishlist_auxiliary` |
| reason | `wishlist_auxiliary_evidence_preserved` |
| privacy | `restricted_source` |
| source file | `data/books.json` |
| source collection | `wishlistItems` |
| source entity ID | exact stable `wishlistItems[].id` |
| source field | `auxiliaryEvidence` |
| logical locator | `data/books.json#wishlistItems/<urlencoded-id>/auxiliaryEvidence` |
| occurrence count | `1` |
| mapping contract | `d-mig-wishlist-map-01.2026-09-18:35a18156490f103d4b6b610f` |

`evidenceSha256` is `DeterministicJson::hash()` of exactly this canonical
envelope:

```text
source_slot      = "wishlistItems"
raw_type         = exact string "edition"
title_group_key  = exact source string
desired_carrier  = exact source string, including empty string
```

The descriptor and artifacts contain only the hash, closed type/reason,
privacy class, occurrence count and logical locator, never the raw group key
or carrier value. Title/author snapshots, notes, timestamps and Book metadata
are excluded from this auxiliary envelope. Raw `type=edition` is thereby
durably traceable without becoming product specificity.

The implementation must add exactly the
`current_v1_wishlist_auxiliary` +
`wishlist_auxiliary_evidence_preserved` + `restricted_source` admission. It
must extend `CurrentV1RestrictedSourceEvidenceResolver` only for the exact
Wishlist locator above: find one stable Wishlist ID, reconstruct the four-key
envelope from that row and verify the canonical evidence hash without
returning or logging raw values. Missing/duplicate identity, different field,
changed manifest or changed bytes fails closed.

Preservation changes neither the Work-only product target nor target
cardinality. It does not activate Series grouping, priority, notes or
fulfillment.

Malformed IDs/Book refs/timestamps, changed mapping-contract provenance,
impossible timestamp order, unavailable Work dependency or contradictory
active/fulfilled state fail closed into the reviewed quarantine/dependency
path. Ambiguity alone is not quarantine; this decision has resolved the only
CURRENT ambiguity.

## 21. CURRENT mapping counts

### Source

| Measure | Count |
|---|---:|
| stable Wishlist records | 41 |
| unique stable IDs | 41 |
| raw `type=edition` | 41 |
| valid / invalid Book refs | 41 / 0 |
| active status | 41 |
| populated `fulfilledCopyId` / `fulfilledAt` | 0 / 0 |
| populated created / updated timestamps | 41 / 41 |
| source owner IDs | 0 |

### CAT

| Measure | Count |
|---|---:|
| resolvable Work dependencies | 41 |
| available but intentionally unused Edition dependencies | 41 |
| Wishlist records in duplicate-ISBN groups | 0 |
| Wishlist records on CAT-quarantined Books | 0 |

### Lifecycle / convergence

| Measure | Count |
|---|---:|
| Books also having active Copies | 0 |
| Books also having archived Copies | 2 |
| source records converging to one Work-only slot | 0 |
| target-mode conflicts | 0 |

### Final design result

| Outcome | Count |
|---|---:|
| active Work-only Wishlist plans | **41** |
| active Edition-specific Wishlist plans | **0** |
| auxiliary Wishlist preservation plans | **41** |
| prepared executable observations | **82** |
| quarantined observations | **0** |
| converged duplicate observations | **0** |
| conflicts | **0** |
| unmatched dependencies | **0** |
| unexplained drops | **0** |

The package contains 41 raw Wishlist records. The prepared executable universe
contains 41 product-plan records plus 41 distinct
`preserved_source_evidence` records, therefore 82 MIG-FND observations. One
observation never has both `mapped` and `preserved_deferred` disposition. Each
pair is deterministically grounded in the same raw stable Wishlist ID, and
reconciliation must account both prepared identities without claiming that
the package contained 82 raw Wishlist records.

## 22. Downstream effects

- **Personal Wishlist view:** all 41 become ordinary Work-only entries and
  communicate that any Edition is acceptable.
- **Work detail:** may expose the existing Work-only Wishlist state through
  normal owner-only contracts.
- **Edition detail:** must not claim that any of the linked CAT Editions is the
  wished Edition; a later explicit user refinement remains possible.
- **Future acquisition/fulfillment:** adding or finding an Item does not
  fulfill/remove an entry automatically. A future explicit fulfillment flow
  remains separate.
- **Future Series grouping:** preserved group evidence may support a later
  V2.002 decision but creates no current group, merge or completeness claim.
- **Statistics/filtering:** this slice adds no Wishlist statistic, priority,
  carrier filter, grouping or Library-scoped count.

## 23. Product questions

None. Renée's manifest-bound Work-only decision closes the sole product
question. New manifests, raw type values or stronger source semantics require
a new evidence-bound review rather than reuse by analogy.

## 24. Required implementation slice

After this design closure, the separately authorized next slice is:

```text
MIG-02-WISHLIST-MAP-01 — Current V1 Wishlist mapper
```

Expected scope:

- CURRENT Wishlist source → typed Work-only `WishlistPlan` records;
- explicit target User and exact CAT Work dependencies;
- historical timestamp-capable source-neutral participant/writer;
- bounded auxiliary preservation plans/admission;
- cardinality/replay/conflict handling;
- prepared-stream, registry and reconciliation integration; and
- zero-write CURRENT dry-run proving the raw unsupported lane is removed.

This design does not authorize that implementation, production apply/import,
Archive work or any adjacent mapping lane.

## 25. Acceptance criteria

D-MIG-WISHLIST-MAP-01 is accepted because:

1. all 41 stable records are accounted;
2. the explicit validated run User is the sole product owner;
3. Library context is never Wishlist ownership;
4. raw `type=edition` has one exact manifest-bound Work-only meaning;
5. every product plan has an exact CAT Work dependency and no Edition
   dependency;
6. all 41 source timestamps are retained without migration-time invention;
7. all records remain active and none is falsely fulfilled;
8. active/archived Copy evidence does not mutate Wishlist intent;
9. duplicate, convergence and target-cardinality behavior is explicit;
10. CAT aliases and quarantined dependency behavior are explicit;
11. stable source identity and MIG-FND target mapping are defined;
12. auxiliary group/carrier evidence is durably accountable through
    MIG-02-PRESERVE-01 without changing product semantics;
13. the typed participant/writer, replay and reconciliation gaps are bounded
    for the future implementation slice;
14. no source record is dropped, fuzzily matched or inferred; and
15. no product question remains.

Verdict: **DESIGN GO / CLOSED**. Production apply remains unauthorized.
