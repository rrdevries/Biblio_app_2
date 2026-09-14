# MIG-02-CAT-01 — Work / Edition / Item migration participants

Status: **GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

Scope: source-neutral catalog migration planning/apply only. No current V1
adapter, V1 field mapping, provider request, apply CLI, schema or UI is added.

## 1. Pre-coding audit and decision inheritance

The audit used the current repository and canonical documents before production
code. Product remains `v2.001`; the start baseline was clean local `main` at
`9febad5e1c6d62f41c863855458d75e29d2a3cfb`, schema `1025`, Biblio Core
`2.28.0` and Biblio UI `0.20.0`.

The inherited decisions are:

- Work is platform-wide and is never identified by title, normalized title,
  Author name, Series or fuzzy similarity. A migration may reuse it only from
  an exact committed logical-source mapping or an explicit approved existing
  Work ID.
- Edition is platform-wide, has one exact Work, and has a required concrete
  title. A canonical ISBN identifies Edition evidence, never Work or Item.
- `IsbnRules`, `CanonicalIsbnIdentity`, `LocalEditionResolver` and the unique
  canonical-ISBN claim remain the only ISBN identity boundary.
- canonical ISBN, explicit no-ISBN and unknown ISBN are distinct. CAT accepts
  only canonical ISBN or explicit no-ISBN. Invalid/unknown input cannot become
  no-ISBN.
- Item is one physical copy owned by one exact Library and linked to one
  Edition. Multiple Items may share an Edition; one source copy cannot create
  multiple Items.
- inventory number is optional and unique only within its Library. Location is
  optional and must already exist in that exact Library.
- classification is one Library+Work context with exactly one Book Type and
  duplicate-free optional Genres/Subjects. CAT accepts IDs only; it creates no
  term, guesses no string and never falls back to `Anders`.
- `ItemLocalDetailsRecorder` is the schema-1025 source-neutral, CAS/replay-safe,
  non-transaction-owning details boundary.
- `CommitMigrationRecordService` remains the only apply transaction owner.

The ordinary Add Book service was not reused because it authorizes through the
current actor, starts its own transaction and owns its own ISBN-race recovery.
CAT reuses the lower domain/value/repository/resolver/context/details boundaries
without nesting or retrying the outer migration transaction.

## 2. Participant contract

The source-neutral catalog phase owns three stable logical source types:

| Source type | Typed plan | Committed primary mapping |
|---|---|---|
| `catalog_work` | `CatalogWorkPlan` | source Work identity → `work` |
| `catalog_edition` | `CatalogEditionPlan` | source Edition identity → `edition` |
| `catalog_item` | `CatalogItemPlan` | source copy identity → `item` |

This separation avoids overloading one copy observation as the Work and Edition
source identity. The plans contain only already-reviewed V2 target facts. They
contain no V1 field names, raw source object or provider payload.

`MigrationSourceRecord::typed()` derives the canonical hash payload from the
typed plan and rejects divergence. `PlannedMigrationRecord` carries the same
typed plan internally for later apply, but its JSON artifact projection exposes
only allowlisted operations and dependency references. Raw title, ISBN,
classification labels and Item-local text are not emitted in the dry-run plan.

## 3. Planning and apply boundary

Planning validates the typed plan and exact target Library without writing.
Edition plans expose an explicit `catalog_work` dependency; Item plans expose an
explicit `catalog_edition` dependency. Artifact ordering remains deterministic,
and dependency correctness no longer relies on incidental record enumeration.

Apply requires agreement between source type/ID/hash, typed source plan,
planned record and MIG-FND observation. It resolves only committed dependency
mappings in the same source family and exact target user+Library scope. Apply
joins the existing `CommitMigrationRecordService` transaction and does not
start, commit or retry a transaction.

## 4. Work identity

A prior unique source mapping wins. An explicit approved existing Work must
agree with that mapping and must still exist. Otherwise CAT allocates one opaque
Work ID and creates a provisional Work with the approved title. Equal visible
titles are never queried or merged. Existing shared Work facts are not rewritten
to resemble source data.

## 5. Edition, ISBN and no-ISBN identity

An Edition dependency must resolve to exactly one existing Work. Prior or
explicit existing Edition reuse requires the same Work and exactly compatible
ISBN/no-ISBN state.

For canonical ISBN, CAT uses the current local resolver. A unique existing
Edition is reused only if it belongs to the dependency Work. Ambiguity, a claim
owned by another Edition or an ISBN winner below another Work fails closed. A
new Edition receives an opaque ID and claims its canonical ISBN in the caller's
transaction. A concurrent claim conflict is surfaced as a typed conflict for
outer migration retry/disposition handling; CAT never retries independently.

Explicit no-ISBN creates or reuses only through exact source/approved identity.
Two different no-ISBN source Edition identities remain distinct even when their
titles are equal. No canonical ISBN claim is created.

## 6. Item, Location and classification

The Item plan contains its exact target Library, Edition source dependency,
optional inventory number, optional already-approved Location ID, required
already-mapped classification and optional typed Item-local details. The target
Library must equal both the IDENTITY-01 planning target and MIG-FND run target.

An existing mapped/approved Item is read through a migration-only unscoped
identity port solely so foreign ownership can be detected. It must remain in the
exact Library, on the exact Edition, active, and equal on explicitly planned
inventory/Location facts. Archived Items are never reactivated. A changed
payload cannot silently reuse a previously committed Item mapping.

For a new copy CAT validates the Location in the target Library, initializes or
reuses the exact Library+Work classification context through the existing
initializer, creates one active Item and then records optional details. Existing
classification must be equal; new links follow the current active-term rules.
No classification term or Location is created.

## 7. Item-local details

CAT passes only `ItemLocalDetailsState` to `ItemLocalDetailsRecorder`. Absence is
valid and creates no row or invented default. A present exact state is created or
reused under the same Item/Library identity. Divergence fails closed; Work and
Edition are never contaminated with Item-local facts.

## 8. Mappings and reconciliation support

Truthful created/reused mappings are committed for:

- Work, Edition and Item under their respective logical source observations;
- canonical ISBN under the Edition observation where applicable;
- Library catalog context and non-empty Item-local details under the copy
  observation.

This lets later reconciliation count created/reused Works, Editions, ISBN
claims, Items, classification contexts and detail aggregates without adding a
CAT-specific report or schema.

## 9. Failure model

`CatalogMigrationFailure` exposes stable source-neutral reasons for invalid
typed plans, missing target references, unsafe Work mappings, ISBN conflicts,
Work/Edition conflicts, cross-Library targets, Item duplicate conflicts,
invalid classification, invalid Item-local details, archived targets and stale
or divergent replay. RUN-01 consumes the generic participant-failure interface
and exposes the stable reason during dry-run without including source content.
MIG-FND remains responsible for quarantine, preservation and retry disposition.

## 10. Transaction and rollback

The effective apply order is Work, Edition/ISBN, classification context, Item,
then Item-local details. Each logical source observation commits its product
write and mappings atomically. Work and Edition are valid standalone canonical
targets while later dependent observations remain incomplete. A failure during
Item, classification, details or ledger outcome leaves no partial Item graph or
false mapping for that copy observation.

## 11. Dry-run and provider boundary

Production now registers the three CAT participants, but still registers no
current V1 adapter and exposes no apply command. `wp biblio migration dry-run`
can exercise CAT only through an explicitly installed future typed adapter.
Synthetic CLI integration proves CAT planning changes no product or MIG-FND row
and emits only operations/dependencies. The dependency graph contains no HTTP
client, Metadata provider, provider materializer or lookup service.

## 12. Explicitly deferred

Current V1 adapter/export mapping, source field interpretation, classification
and Location source allowlists, Authors, contributor credits, Series,
ReadingRounds, Notes, Wishlist, Collections, loans, Archive, reconciliation,
apply CLI/trial and cutover remain separate slices. No current or historical V1
fixture was inspected or requested.

## 13. Verification

Exact acceptance evidence:

- focused CAT integration: 6 tests, 48 assertions;
- focused RUN-01/CAT CLI dry-run: 3 tests, 22 assertions;
- focused Item-local persistence: 4 tests, 22 assertions;
- full Core unit suite: 691 tests, 2,750 assertions, with the two existing
  PHPUnit notices and no failure;
- full Core integration suite: 526 tests, 6,039 assertions;
- full `test-biblio-core-all.sh`: GO in 324 seconds, including strict Composer
  metadata, locked platform requirements, PHP syntax, PHPStan, WordPress smoke,
  manifest JSON and staged/unstaged whitespace checks;
- independent second review: no blocking finding after extending divergent
  payload protection consistently across Work, Edition and Item identities;
- browser/E2E: not run, as explicitly excluded for this Core-only slice.

The final commit ID and clean/ahead state are reported in the completion report
after the single local commit is created. No push is performed.

## 14. Versions

- Product: `v2.001` unchanged.
- Schema: `1025` unchanged.
- Biblio Core: `2.28.0 -> 2.29.0`.
- Biblio UI: `0.20.0` unchanged.
