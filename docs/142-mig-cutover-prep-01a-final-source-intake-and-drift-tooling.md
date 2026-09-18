# MIG-CUTOVER-PREP-01A — Final source intake and drift tooling

Status: **GO / FINAL SOURCE INTAKE / DRIFT TOOLING READY**
Production apply: **NOT AUTHORIZED**
Date: 2026-09-18
Task severity: **High**

## 1. docs/141 inheritance

This implementation inherits
`docs/141-d-mig-cutover-01-final-source-and-rehearsal-contract.md` without
changing its product or cutover decisions. V1 is source evidence, not product
authority. A new semantic case produces `CONTRACT_REVIEW_REQUIRED`; the tool
does not widen a mapper or invent a fallback.

PREP-01A does not create or designate the real FINAL CURRENT SOURCE, freeze V1,
run final dry-run/apply, create a rehearsal target or authorize production.

## 2. Architecture

The implementation is divided into four closed boundaries:

1. typed package/export/retention and tool-provenance values;
2. guarded ZIP intake plus SOURCE-01 inspection and recovery verification;
3. privacy-safe CURRENT domain snapshots and A-J drift comparison; and
4. typed final population contract plus append-only evidence writing.

`scripts/migration-cutover-source.sh prepare` is the only operator mode. It
delegates to the Core classes through PHP in DDEV. There is deliberately no
`apply`, `rehearsal`, database mutation or source-export mode.

## 3. Final source intake

`FinalSourceIntakeService` verifies the declared archive SHA-256 before it
creates an extraction. It then creates exactly one new
`<intake-root>/<logical-package-id>/source` location and refuses overwrite.
Every ZIP member is checked for absolute paths, drive prefixes, `..`, empty
segments, duplicate normalized paths and Unix symlink mode. Files are streamed
with exclusive creation rather than trusted bulk extraction.

The existing `FilesystemMigrationSourcePackageFactory` supplies the sorted
relative path/byte-size/SHA-256 manifest and post-read byte verification. The
exact adapter revalidates source version/shape. Declared and calculated
manifest digests must match before the extraction becomes read-only.

## 4. Immutable package identity

`FinalSourcePackageIdentity` binds:

- logical package ID;
- archive SHA-256;
- extracted manifest SHA-256;
- source family and version; and
- adapter ID.

Archive filename, intake root and absolute extraction path are not identity.
The durable receipt contains only logical extraction locator
`<package-id>/source`. Archive transport and extracted bytes remain separately
verifiable.

## 5. Reference source

The drift baseline is the reviewed CURRENT package:

- archive SHA-256
  `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
- manifest SHA-256
  `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`;
- adapter `current-v1-json-29`; and
- source `biblio-v1 / books-29.authors-2.reading-goals-2`.

The old 6,675 executable records and related counts remain comparison values,
never candidate acceptance totals.

## 6. Drift engine

`CurrentV1FinalSourceSnapshotBuilder` combines exact adapter observations with
bounded domain projections. `FinalSourceDriftEngine` compares stable identity
and payload hash first. Added, removed and same-ID-changed evidence are
therefore distinct. Every union identity receives exactly one drift entry.

Only A-D can yield `MAPPING_CONTRACT_COMPATIBLE`. E-J yield
`CONTRACT_REVIEW_REQUIRED`. Generated compatibility never authorizes dry-run,
rehearsal or apply.

## 7. Drift categories

The exact docs/141 names are implemented as `SourceDriftCategory::A` through
`J`:

- A no relevant change;
- B supported population increase;
- C covered payload change;
- D supported new stable record;
- E new raw value, enum or shape;
- F identity/grouping/provenance change;
- G new conflict or quarantine;
- H source disappearance;
- I materially changed special approval or structural slot; and
- J unsupported source type.

No review-required category is collapsed into generic `changed`.

## 8. Contract inventory

`MapperContractInventory` contains all 16 docs/141 lanes: adapter; CAT/ISBN;
classification; Item-local; erroneous Copy exclusion; Author/contributor;
Reading; Note; assessments; Series; contained Works; Wishlist; Reading Goals;
circulation; preserved evidence; and source-neutral MIG-FND/reconciliation.

Each entry declares contract ID/version, semantic scope, exact A-D classes,
manifest/population binding, per-record approval presence, exact source-set
digest presence and final regeneration requirement. This removes a second
manually drifting prose-only inventory.

## 9. Final population contract bundle

`FinalPopulationContractBundle` contains only typed package identity, mapper
inventory, source-type counts, stable observation IDs/hashes, structural
vectors, candidate quarantine evidence and circulation profile. It exposes a
closed `mapperContract(domain)` seam rather than arbitrary metadata.

The bundle intentionally does not rewrite existing CURRENT mappers or accept
generic configuration. Existing manifest-bound mapper tests keep using their
compiled reviewed contracts. A later approved final bundle can be connected
through the closed seam without replacing code-owned semantics.

## 10. Bundle digest

`DeterministicJson` canonicalization computes the bundle SHA-256. The semantic
input contains no generation time, random nonce or absolute path. Same package
and contracts replay to the same digest; changed relevant observation,
inventory, manifest or approval state changes it.

Export time remains export provenance in the intake receipt, outside the
canonical bundle digest.

## 11. Approval and review state

The typed states are `mechanically_compatible`, `review_required` and
`approved_for_rehearsal`. PREP-01A can produce only the first two. Its bundle
constructor rejects `approved_for_rehearsal`, so successful generation cannot
masquerade as human/product acceptance.

## 12. Domain drift coverage

The snapshot covers Books/catalog, Copies, Authors, contributor occurrences,
classification, acquisition/Item-local evidence, erroneous Copy candidates,
circulation, Series, contained Works, ReadingRounds, Personal Reading Truth,
Notes, Ratings, WrittenReviews, Reflections, Wishlist and Reading Goals.

Exact adapter type counts and virtual structural evidence are separate. A new
real adapter source type is J; a known structural observation type is not
mistaken for an adapter source type.

## 13. Structural identity drift

Parent-scoped ordered vectors bind Author/name alignment, contained Works,
Rating/Review/Reflection slots and Series membership evidence. A same parent
with changed vector is category I. Reordering or insertion therefore never
silently rebinds a prior parent-plus-slot identity to different bytes.

Series groups use hashes of exact decoded name bytes plus ordered membership
and position evidence. No near-name or normalized-name identity is introduced.

## 14. Quarantine candidates

Candidate quarantine evidence contains only stable source type/ID, fixed
reason, evidence hash and `no_target=true`. It is freshly derived from the
candidate; the known reference conflict is not copied as approval. New or
changed conflicts are category G. PREP-01A creates no allowlist and records no
reviewer/product acceptance.

## 15. Circulation profile and gate

The profile reports open `borrowed`, open `lent_out`, total open,
contradictory, closed, dependency presence, counterparty-presence count,
archived/error-Copy overlap and safe source IDs. It emits no counterparty or
note text.

Any operationally open candidate yields
`CIRCULATION_CUTOVER_REVIEW_REQUIRED`. No loan is closed, mapped, mutated or
treated as operational continuity.

## 16. Privacy

Note, Review and Reflection bodies, acquisition source/text, circulation
counterparties/notes and other restricted payload remain only in the immutable
source. Drift and bundle evidence use stable IDs, shape/payload hashes, counts,
reason codes and structural vectors. Exceptions and the operator summary use
generic safe messages.

## 17. Artifacts

`FinalSourceEvidenceWriter` writes one owner-only attempt directory under the
logical package ID. Caller-supplied attempt IDs are validated; an existing
attempt is never replaced. The content-addressed `artifact-<sha256>.json` and
its checksum bind:

- exact Git SHA and dirty state;
- Product, schema, Core, UI and runtime;
- candidate package/archive/manifest/adapter;
- reviewed reference manifest; and
- final bundle digest.

There is no mutable `latest.json`. Evidence cannot be written within source
root.

## 18. Zero-write proof

All PREP-01A production classes are filesystem/source readers plus dedicated
intake/evidence writers. They have no database dependency, migration ledger,
participant apply, `CommitMigrationRecordService` or product repository. The
operator script exposes only `prepare`. Tests use synthetic files under new
temporary roots.

No actual FINAL CURRENT SOURCE, normal database or rehearsal target was used
or modified.

## 19. Tests

Focused automated evidence covers identical replay, supported addition, new
enum, deletion, same-ID private payload change, structural reorder, unknown
source type, unchanged/resolved circulation conflict, open-circulation gate,
ZIP traversal, read-only intake, path privacy, deterministic bundle,
no-overwrite artifact and checksum/privacy provenance.

Final evidence on 2026-09-18:

- focused PREP-01A: 5 tests, 36 assertions;
- complete unit suite: 810 tests, 3,517 assertions, 2 existing notices;
- complete integration suite: 605 tests, 6,824 assertions;
- PHPStan, exhaustive PHP syntax, WordPress smoke, Composer validation,
  platform requirements, manifest JSON and Git whitespace: passed;
- complete Core gate: exit 0 in 640 seconds; and
- read-only reviewed-CURRENT smoke: exact manifest
  `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`,
  2,624 adapter records, 10,940 bounded observations, all category A,
  `MAPPING_CONTRACT_COMPATIBLE`, and `mechanically_compatible` only.

The reviewed-CURRENT smoke did not nominate that package as FINAL and did not
write an intake, contract artifact, rehearsal target, database or source.

## 20. Versions and Git

Implementation baseline:

- parent HEAD `8973b35d52f65cef0a9febff2b217303934c9698`;
- safety branch `wip/shared-search-rebuild` at that exact parent;
- Product `v2.001` unchanged;
- schema `1026` unchanged;
- Biblio Core `2.50.0`;
- Biblio UI `0.20.0` unchanged.

One local implementation commit is created only after all final gates and the
independent second review pass. Nothing is pushed.

## 21. Remaining PREP-01B dependencies

PREP-01B remains responsible for the rehearsal-only apply composition,
positive environment guard, exhaustive empty-target guard, phase-aware
backups, named interruption points, target-neutral intent digest integration,
replay/fingerprints and three-state cutover reconciliation. PREP-01A exposes
none of those mutation paths.

## 22. Production apply gate

**PRODUCTION APPLY NOT AUTHORIZED.**

The real FINAL CURRENT SOURCE does not yet exist and no final bundle is
approved. PREP-01B, exact-source rehearsal, circulation gate, recovery/rollback
evidence and Renée's exact authorization remain mandatory.

## 23. Git

The implementation is bounded to Core cutover-preparation classes/tests,
zero-write scripts, canonical documentation, manifest and the Core patch
version. No unrelated file or user data is changed. Final commit, worktree and
push status are reported after closure.
