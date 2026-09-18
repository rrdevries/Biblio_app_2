# D-MIG-CUTOVER-01 — Final CURRENT source and cutover rehearsal contract

Status: **DESIGN GO — PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-18

Task severity: **High**

Scope: final-source export, source freeze, deterministic drift review,
manifest-bound contract regeneration, isolated full rehearsal, replay,
interruption/resume, reconciliation, backup/restore, privacy, evidence and the
later production-authorization gate. This document changes no production code,
schema, Core/UI version or data. It does not run dry-run, apply/import,
rehearsal or cutover.

V1 remains source evidence, never product authority. Accepted mapper semantics
remain authoritative. A final-source observation outside those semantics is a
`HOLD`, not permission to extend a mapper during cutover.

## 1. Current migration readiness

The audited repository baseline is:

| Control | Audited value |
|---|---|
| Git HEAD | `9fa5efb95274e06a0695874d974e755f2a4ba1e9` |
| branch | local `main`, clean, 75 commits ahead of `origin/main` |
| remote safety ref | `origin/wip/shared-search-rebuild` resolves to the exact HEAD |
| product | `v2.001` |
| schema | `1026` |
| Biblio Core | `2.49.0` |
| Biblio UI | `0.20.0` |
| CURRENT adapter | `current-v1-json-29` |
| source family/version | `biblio-v1` / `books-29.authors-2.reading-goals-2` |

The accepted CURRENT control totals supplied for this design are 6,675
executable records: 5,846 mapped, 828 `preserved_deferred`, one quarantined,
zero unsupported source types, planning errors and unmatched references, and
734 complete Item plans. They are comparison evidence only; they are not
expected totals for a new final export.

There is one documentation timing difference to preserve rather than hide:
the committed closure draft in doc 140 and `manifest.json` still say that its
clean exact-SHA zero-write trial is required, while this slice's explicit
baseline states that the implementation has been accepted. The exact HEAD and
versions are verified. The supplied post-commit totals may be used as the
reviewed-snapshot drift baseline, but no cutover tool may infer final-source or
production authorization from that status wording.

Production apply remains **NOT AUTHORIZED**.

## 2. Current architecture audit

| Area | Exists now | Required for cutover preparation |
|---|---|---|
| SOURCE-01 intake | version-bound adapter, exact path/shape validation, stable record enumeration | guarded final-export intake command and durable package registry |
| package manifest | sorted relative path, byte size and SHA-256; aggregate manifest digest; bytes rechecked on read | retained manifest file, archive hash, logical package ID, recovery copies and extraction proof |
| source adapter | exact JSON-29/Author-2/Goal-2 production adapter; unknown shape fails closed | compatibility report against final package; no semantic expansion |
| CURRENT mappers | all accepted CAT, classification, Item-local, Author, Reading, Note, Assessment, Series, Wishlist, Goal, Copy-exclusion and contained-work collaborators | external typed final-population contract bundle replacing the old embedded manifest/population bindings |
| preparation | one `PreparedMigrationPlan` shared by dry-run, apply preflight and reconciliation; target/build/contracts/findings enter the plan-set digest | full contract-bundle hash and a target-neutral intent digest for cross-environment comparison |
| dry-run | public zero-write CLI, explicit target, optional target-empty validation, artifact version 3 | cutover acceptance wrapper, final-source drift attachment and immutable attempt directory |
| apply | source-neutral internal `MigrationApplyRunner`; accepted plan digest; per-observation atomic commit; dependency ordering | guarded rehearsal-only composition/command; mandatory empty-target and zero-planning-error checks before `begin`; no routine production command |
| participants | complete typed production registry including no-target preservation and circulation | exact registry fingerprint in evidence |
| MIG-FND | runs, target locks, observations, mappings, preservation, quarantine, lifecycle and traceability | no schema change expected |
| reconciliation | exact source/ledger/target accounting; broken targets and unresolved dependencies fail; quarantine is counted separately | cutover verdict layer `accepted`, `accepted_with_reviewed_quarantine` or `rejected` |
| interruption/resume | internal integer observation boundary; interrupted/failed run resume; exact committed observations skip | named rehearsal fault points after product work and after preservation, each in its own disposable clone |
| replay | completed exact source/target/migrator resolves to the same run and skips committed records | explicit before/after table and MIG-FND fingerprints plus immutable replay artifact |
| target validation | explicit User/Library, active user, designated private Library, Owner/direct membership | subscriber/non-super-admin assertion in every environment and target identities in authorization |
| empty-target guard | 23 user/Library-scoped buckets; OPS adds all-57-table baseline check | reusable cutover guard covering all product, relation and MIG-FND tables with allowlisted seeds only |
| normal-DB guard | positive DDEV trial marker plus normal-DB fingerprint | retain; make mandatory around every rehearsal mutation |
| OPS-01 | detached clean-SHA worktree, separate DDEV project/volume/database, marker, backup/restore and normal fingerprint | production-like runtime record, post-apply backup mode, full-apply operation and evidence orchestration |
| backups | guarded immutable pre-apply DDEV gzip SQL backup with checksum/provenance and proven restore | current helper validates only an empty target and cannot create a post-apply backup; add phase-aware backup and comparison |
| artifacts | canonical privacy-safe JSON plus SHA-256 outside source root | writer currently overwrites a fixed name; add no-overwrite attempt IDs and content-addressed accepted storage |
| restricted recovery | source-relative locators, manifest and evidence hashes; no absolute path | retained package lookup by logical ID and tested recovery from both durable stores |
| runtime | local DDEV pins PHP 8.3 and MariaDB 10.11; trial pins WordPress 7.0.2 | exact production inventory is not stored in this repository and must be captured before rehearsal acceptance |
| production target | no repository-owned production environment description or backup procedure | environment-specific guarded runbook, target proof and restore proof before authorization |

The current public migration CLI intentionally exposes only `profile` and
`dry-run`. `MigrationApplyRunner` is not production-composed. Its target
validation does not itself require an empty target, and `RuntimeMigrationEnvironment`
checks schema but not the OPS marker, runtime parity or clean-target race.
Those are fail-before-write gaps, not reasons to expose the internal runner
directly.

The current artifact filename is based on source and target only and may be
atomically replaced. It is deterministic output, but not immutable retained
evidence. Cutover tooling must refuse an existing path and write each attempt
under a unique run directory with its own checksum index.

## 3. FINAL CURRENT SOURCE definition

`FINAL CURRENT SOURCE` is exactly one frozen, complete byte snapshot of the
authoritative V1 data root, exported after the V1 write barrier and explicitly
designated for cutover. It consists of:

1. the original immutable archive;
2. a canonical relative-path manifest with byte size and SHA-256 for every
   member;
3. the archive SHA-256 and manifest SHA-256;
4. source family, source schema/version and adapter ID/version;
5. export UTC instant, operator ID, V1 build/Git/runtime metadata where
   available and the freeze evidence ID;
6. one logical package ID
   `final-current-<UTC>-<archive-sha-prefix>`; and
7. two verified restricted storage receipts.

The package root passed to Biblio is the extraction parent containing `data/`,
not the inner `data/` directory. Finder metadata, if present, is part of the
manifest; it is never silently removed. A historical archive, partial export,
fixture, old extraction or mixed file tree can never receive this designation.

The archive hash identifies the transported object. The extracted-manifest
hash identifies its source bytes independent of workstation path. Both are
required and neither substitutes for the other.

## 4. Export procedure

The first preparation slice must provide one project-owned, reviewed operator
tool, provisionally named `scripts/migration-cutover-source.sh`. Its interface
must implement the sequence below; this document intentionally provides no
production-ready apply command.

1. Record V1 deployment, authoritative data root/database, V1 build, runtime,
   operator, UTC clock and intended package ID in a new mode-0700 staging root.
2. Enter the hard write freeze from section 5 and obtain a signed/hashed freeze
   evidence record. The tool refuses an absent or expired freeze record.
3. Invoke the audited native V1 export when one exists. If V1's authority is
   the file-backed `data/` tree, copy that complete tree byte-for-byte; do not
   parse and reserialize JSON. If the actual authority is a database, use its
   consistent-snapshot export before packaging the resulting complete V1
   `data/` tree. The exact exporter binary/version and invocation are captured
   in metadata.
4. Write only to a new empty staging directory outside the live V1 data root.
   Refuse symlinks, devices, sockets, absolute/traversal archive members,
   unreadable files and a source that changes during hashing.
5. Build a sorted path/size/SHA-256 manifest, archive the complete package,
   compute both hashes and write a canonical metadata sidecar. Existing
   archive, manifest or sidecar paths are never overwritten.
6. Test the archive, extract it into a second new empty directory, make the
   extraction read-only and independently recompute the manifest.
7. Run `profile` with `current-v1-json-29` against that extraction. Unsupported
   version/shape stops the procedure; the adapter is not widened here.
8. Upload the archive, manifest, metadata and checksums to the primary and
   recovery stores from section 7; download/read back from each and verify.
9. Designate the package only after both recovery receipts are green. The
   designation is append-only and binds the logical ID to both hashes.

The implementation slice must first audit the actual V1 native export
mechanism. If it cannot prove a consistent snapshot, it must stop; copying a
live mutating directory is not an export procedure.

## 5. V1 write freeze

The selected sequence is **hard freeze before export**:

1. announce the V1 maintenance window;
2. block interactive/API/admin writes and scheduled/background writers;
3. drain in-flight writes and record the final V1 commit/file-set marker;
4. make the authoritative data store read-only to application credentials;
5. verify a representative write is rejected without mutating source data;
6. create and designate the final export; and
7. keep V1 read-only through rehearsal, production authorization, production
   apply and V2 acceptance.

There is no delta-capture lane. If any V1 write occurs after the barrier, the
designation, all later artifacts and any authorization are invalid. The
operator must create a new final package and repeat the relevant gates.

V2 production writes are also placed in maintenance/read-only mode from its
final target check until apply acceptance or restore, preventing a target from
becoming non-empty between preflight and the first migration write.

## 6. Source package provenance

The package metadata is canonical JSON and contains only operational metadata,
not source bodies. Required fields are:

- logical package ID, archive filename/bytes/SHA-256 and manifest SHA-256;
- ordered member count/bytes and manifest filename/SHA-256;
- `biblio-v1`, source version and `current-v1-json-29`;
- V1 export tool/version/invocation digest, V1 build/runtime and UTC instant;
- freeze evidence ID, operator ID and designation approver;
- primary and recovery storage receipt IDs;
- extraction-verification result; and
- access class `restricted-migration-source`.

Local absolute paths are permitted only in ephemeral operator logs. They never
enter source identity, mapping plans, MIG-FND or the durable package contract.

## 7. Source retention

The final bundle is retained in two independently recoverable, encrypted,
access-restricted stores under the logical package ID: one primary migration
evidence store and one recovery store on a different failure domain. Their
concrete URIs and custodians are environment secrets recorded in the
restricted operator register, not in Git or ordinary artifacts.

Retention has no automatic deletion date while any MIG-FND preservation or
quarantine can require source-located evidence. Deletion requires a separate
review proving every dependent item has been promoted or intentionally
retired, legal/privacy retention permits deletion, both DB and evidence audit
needs are closed, and Renée explicitly approves it.

Quarterly until that retirement decision, and once immediately before
rehearsal and production apply, restore the bundle from each store into a new
empty location and verify archive, extraction, manifest and one allowlisted
restricted locator. Loss of either recoverable copy is a `HOLD`.

## 8. Drift analysis

The final-source preparation tool creates a deterministic, privacy-safe drift
artifact comparing the reviewed CURRENT manifest with FINAL CURRENT SOURCE.
It compares stable IDs and payload hashes first, then typed domain projections;
it never compares names to infer identity.

The report covers Books, Copies, Authors, contributor occurrences,
classifications, Item-local acquisition/evidence, circulation, Series,
contained works, ReadingRounds, Reading Truth, Notes, Ratings, Reviews,
Reflections, Wishlist, Reading Goals, erroneous Copies and archive/correction
evidence. For each domain it reports unchanged/added/changed/deleted identity
counts, affected structural-parent counts, category, decision and reason code.
Private values and bodies remain replaced by stable IDs, hashes and counts.

Every final record or structural observation must be in exactly one drift
bucket. Domain totals must reconcile to both source profiles.

## 9. Drift categories

| Code | Meaning | Default action |
|---|---|---|
| A | no change | reuse semantic rule; regenerate final provenance |
| B | count-only increase in an already supported source shape | proceed to full final dry-run when no population-bound exception is involved |
| C | existing payload change still fully covered by accepted semantics | regenerate plans; require reasoned drift review |
| D | new stable record using supported reviewed semantics | regenerate plans; proceed when dependencies and identity stay unambiguous |
| E | new enum, field, value class or shape | `HOLD` for bounded mapping/design |
| F | changed identity, grouping or provenance contract | `HOLD` |
| G | new contradiction, conflict or quarantine | `HOLD` pending explicit review; new reason always needs a bounded slice |
| H | source deletion/disappearance | `HOLD` until explained and approved |
| I | previously reviewed stable identity changed in a way that changes a special approval or structural slot | `HOLD` |
| J | unsupported source type | `HOLD` |

An added ordinary Book/Copy/Reading Truth/Note is not automatically low risk:
it is B/D only after the adapter, mapper, identity, enum, dependency and
privacy checks all classify it under existing semantics. New Book Type,
acquisition kind, contributor role, assessment kind, circulation shape,
Series identity shape, Wishlist meaning, contained-work structure or
archive/correction meaning is E/F/J and cannot proceed.

## 10. Manifest-bound contract review

Classes mean: **A** reusable semantics for the same adapter/schema contract;
**B** explicitly manifest-bound; **C** final per-record/population material
must be regenerated; **D** exact reviewed set or exceptions requiring
re-approval when changed.

| Lane | Class | Final-source treatment |
|---|---|---|
| `CurrentV1SourceAdapter` | A | exact shape/version only; unfamiliar paths/fields/markers fail |
| CAT Work/Edition/Item and ISBN grouping | A+C | rules remain; recompute ISBN components, representatives, aliases and plan population; any changed grouping is F |
| classification | A+B+C | raw allowlists remain; bind final manifest and regenerate approval digest; unknown raw value is E |
| Item-local | A+B+C | accepted bought/received/absence rules remain; regenerate evidence/population |
| erroneous Copy exclusion | B+C+D | rebuild exact stable-ID/full-row-hash set; additions, deletions or changed rows require explicit per-record review |
| Author/contributor | A+B+C+D | stable-ID and occurrence rules remain; regenerate exception set and positional vectors; changed exceptional row or ordering is I |
| ReadingRound/Reading Truth | A+B+C | regenerate identities/hashes; new lifecycle/value is E |
| Note | A+B+C | regenerate stable Note population; unsupported time/content shape is E |
| Rating/Review/Reflection | A+B+C | regenerate slot vectors, hashes and preservation locators; slot drift is I |
| Series | A+B+C+D | recompute exact-byte groups, positions and preservation; a new exact-name identity group or changed grouping requires review |
| contained works | A+B+C+D | regenerate parent/slot vectors, child plans, exact base-Series resolution and preservation; insertion/reorder or new structure is I/E |
| Wishlist | A+B+C | regenerate stable entries and auxiliary preservation; a type other than reviewed semantics is E |
| Reading Goals | A+B+C+D | accepted no-product-target decision remains; regenerate stable IDs, hashes and locators |
| circulation | A+C+D | recompute every stable ID; known conflict is never copied forward; changed conflict is G |
| preserved source evidence | A+B+C | source-neutral admission remains; regenerate every locator, evidence hash and source/manifest/contract binding |
| source-neutral participants/MIG-FND/reconciliation | A | reuse only after final registry/runtime tests |

All production `CurrentV1Reviewed*Contract` implementations currently bind the
old extracted manifest, and some embed exact exception/exclusion populations.
A different final manifest therefore correctly fails closed today. Final
source cannot reach dry-run merely by replacing one manifest constant.

## 11. Final mapper-contract regeneration

Preparation introduces one typed, immutable **final population contract
bundle**. Semantics remain in code at the candidate Git SHA; the bundle may
contain only allowlisted population evidence:

- archive/manifest/source/adapter identities;
- contract schema and mapper versions;
- stable source IDs plus payload hashes for reviewed exceptions/exclusions;
- per-record approvals and their decision provenance;
- ISBN equivalence components and representative/alias digest;
- exact Series-name group digest and membership/position vector;
- structural parent/slot vectors for ID-less observations;
- preservation identity/locator/evidence-hash set;
- expected executable identity-set digest; and
- bundle SHA-256 and reviewer acceptance.

The bundle contains no Note, Review, Reflection, acquisition-source or
counterparty body. A typed loader rejects unknown keys, reasons, enum values,
contract versions and arbitrary diagnostic persistence. The complete bundle
SHA-256 enters `PreparedMigrationPlan` provenance and the plan-set digest.

Unchanged special approvals can be carried forward only when stable ID and
full payload hash still match. Changed or absent special rows, new exceptions,
new exclusion candidates, new Series groups, changed ISBN components or new
quarantine never auto-approve.

## 12. Structural identity drift

Stable V1 IDs remain authoritative where present. For ID-less occurrences the
comparison records a parent-level ordered vector of canonical slot hashes for:

- `containedWorks[]` and child Author/Series/ISBN observations;
- Author/name occurrence alignment;
- Review slots;
- Reflection slots;
- classification assignment slots where position is identity; and
- any other admitted parent-plus-one-based-slot evidence.

Existing prefix equality plus newly appended supported observations may be
classified D after mapper review. Insertion, deletion, reorder, split, merge or
different bytes at an existing slot is I, because a slot identity may have
shifted. The tool reports it as structural drift and never claims it is the
same observation. Exact names, titles or content cannot repair a shifted ID.

## 13. Final dry-run

The accepted candidate SHA runs one complete zero-write dry-run against the
read-only FINAL CURRENT SOURCE and the exact target context, with target
emptiness required. Acceptance requires:

- archive and extracted manifest match the designation;
- adapter/source version and final contract bundle match;
- clean exact Git SHA, schema/Core/UI and runtime fingerprint match;
- explicit target User/Library validate;
- all cutover-empty checks pass;
- unsupported source types, planning errors and unmatched references are zero;
- every executable record has one deterministic outcome;
- plan-set and target-neutral intent digests are stable over two preparations;
- all drift is A-D and explained;
- quarantine exactly equals the reviewed final allowlist;
- before/after all-Biblio-table fingerprints are equal; and
- artifact/privacy scans are green.

The report includes source totals, executable/mapped/preserved/quarantined
totals, both plan digests, participant and operation counts, dependencies,
Works/Editions/Items/details, Authors/contributors, classifications,
Series/memberships, containment, Reading, Notes, assessments, Wishlist,
preservation and quarantine reasons. Final expected counts come only from this
artifact. The old 6,675/5,846/828/1 figures remain comparison columns.

The target-neutral intent digest replaces target User/Library values with
typed placeholders but retains all source, plan, operation, contract, build
and semantic fields. It permits an isolated rehearsal target and production
target to have different IDs without pretending their target-bound plan-set
digests are equal. Production apply always binds the production plan-set
digest.

## 14. Quarantine policy

A rehearsal may be `accepted_with_reviewed_quarantine` only when every final
quarantine:

- was freshly produced by the final mapper, not copied from an old artifact;
- has an accepted fixed reason and explicit Renée review;
- creates no product target and satisfies no target dependency;
- preserves recoverable restricted evidence;
- compromises no referential or application integrity; and
- appears in the authorization packet by privacy-safe identity and reason.

Zero quarantine is not a cosmetic requirement. Any unreviewed quarantine,
new reason, changed conflict shape or unexplained dependent blocks rehearsal
acceptance and production authorization.

The existing reconciliation boolean already permits accounted quarantine. The
cutover layer adds the three-state verdict without changing MIG-FND product
semantics.

## 15. Circulation quarantine and cutover

The final export decides the circulation population:

- the known Book-open/Copy-closed conflict still exists with the same reviewed
  shape: quarantine it afresh and require explicit acceptance;
- it is now coherent: use the existing circulation contract, normally
  `preserved_deferred`; do not retain the old quarantine;
- its shape/reason changed: `HOLD`;
- additional coherent open records: preserve for rehearsal under the accepted
  contract and report the drift;
- additional/new contradictions: `HOLD`.

An isolated rehearsal may proceed with reviewed preserved open circulation so
that preservation, reconciliation and recovery are proven. **Production
cutover may not proceed while operationally open circulation remains and V2
has no usable loan model or separately approved transition measure.** This is
the inherited D-MIG-LOAN-01 final-cutover gate. No loan is forcibly closed, no
counterparty identity is invented and no preservation is treated as
operational continuity.

If open circulation remains, the required next product slice is a bounded
`D-MIG-CIRC-CUTOVER-02` decision followed, only if authorized, by an
implementation/backfill slice. This design does not choose that measure.

## 16. Rehearsal environment

The accepted rehearsal runs in a new disposable clone, isolated from normal
local `db`, production and any stale trial database. It uses a clean detached
worktree at the candidate SHA, a new trial ID, separate DDEV project/container/
volume/URL and explicit non-default database. The OPS positive guard binds all
of those values to a database-resident marker before any mutation.

The existing OPS pattern is project `biblio-v2-migration-trial`, database
`biblio_migration_trial` and a distinct trial URL. A new cutover rehearsal may
reuse those literal identities only after guarded destruction/recreation proves
that no stale state survives; otherwise it receives equally explicit unique
identities.

Prefer a disposable production-shape clone. Do not copy private production
content merely for realism. Required system configuration and the explicit
target identity may be provisioned; all Biblio product/MIG-FND data remains
empty except allowlisted seeds.

The existing `biblio-v2-migration-trial` environment can supply patterns, but
a stale instance is rebuilt or a uniquely named cutover rehearsal instance is
created. It is never adopted merely because its hostname looks correct.

## 17. Runtime parity

The audited local baseline is WordPress `7.0.2`, PHP `8.3.31`, MariaDB
`10.11.18`, DDEV `1.25.3`, database/server charset `utf8mb4` and collation
`utf8mb4_general_ci`. Required PHP modules include at least `json`,
`mbstring`, `mysqli`, `openssl`, `pdo_mysql` and `zip`.

Before rehearsal, capture the intended production WordPress, PHP patch,
MariaDB patch, charset/collation, SQL modes, required extensions, filesystem
case/symlink/permission behavior, plugin versions, schema and relevant PHP
limits. Those production facts are not currently canonical in this repository.

Acceptance requires equality for WordPress, PHP major/minor, MariaDB major/
minor, charset/collation, schema, Core/UI and required extensions. Patch-level
differences are acceptable only when documented, compatibility-tested and not
known to affect JSON, SQL, collation, date or filesystem behavior. DDEV itself
may differ from production orchestration; application/runtime semantics may
not silently drift. No component upgrade is combined with cutover.

## 18. Target User and Library

Each environment selects its own explicit IDs. The operator resolves the
intended login/account through a protected identity register, then validates:

- active WordPress account;
- role exactly `subscriber` for rehearsal and non-admin production target;
- `super_admin=false`;
- one designated active personal `Privébibliotheek`;
- exact active Owner/direct membership; and
- no display-name, current-actor, first-user or admin fallback.

The target User/Library IDs, identity-validation artifact hash and environment
enter preparation provenance. Rehearsal and production IDs may differ. No ID
is copied from the old trial or hardcoded in code/docs.

## 19. Empty-target guard

Before first write the cutover guard counts every schema-1026 Biblio table.
Allowed nonzero state is limited to:

- exactly the intended personal Library, one designation and one active
  Owner/direct membership;
- standard seeded Book Type/Genre/Subject rows only;
- WordPress core/system rows and required site/page/plugin configuration; and
- the environment's cutover guard marker outside product semantics.

All central catalog/product/relation data must be zero: Works, Editions,
identifier claims, Items/details, Authors/credits/contributors, Series/
memberships, alternate titles, containment, contexts and selections,
Locations, Collections/memberships, archive periods, loans, ReadingRounds and
locks/truth, Notes, Ratings, Reviews/publications, Hierna-lezen, Wishlist,
metadata/discovery observations/candidates/identities and Library activity.
All MIG-FND runs, locks, observations, mappings, preservation and quarantine
must be zero.

The existing 23-bucket identity guard remains, but is insufficient alone
because it does not cover every central/shared table. The OPS all-table guard
is promoted into the reusable cutover preflight. Any unexpected row fails
before `BeginMigrationRunService`.

## 20. Backup

Immediately before rehearsal apply, create an immutable full database backup
after the V2 target write freeze and empty-target proof. Record gzip integrity,
bytes, SHA-256, schema/table counts, target IDs, runtime, candidate SHA, source
package ID and purpose `cutover-rehearsal-pre-apply`. Restore it into another
guarded disposable database and verify the complete pre-apply fingerprint
before it is accepted.

After successful apply, create a second backup with purpose
`cutover-rehearsal-post-apply`, reconciliation run ID and final table/count
fingerprints. The current OPS helper's `backup` starts with empty-baseline
validation, so it cannot produce this post-apply backup unchanged. Preparation
must add phase-aware validation without weakening the positive target guard.

Production requires a full DB backup plus relevant files/config, encrypted and
restricted, checksum, independent restore proof and operator-readable restore
procedure. “A backup command exists” is not restore evidence.

## 21. Apply preflight

Immediately before `begin`, in one guarded operation, recompute and require:

- designated archive/manifest/package ID and recovery receipts;
- clean candidate Git SHA and full runtime fingerprint;
- product/schema/Core/UI exact versions;
- explicit valid target and frozen target writes;
- all-table empty-target proof;
- final contract-bundle hash and typed registry fingerprint;
- accepted production- or rehearsal-target plan-set digest;
- accepted target-neutral intent digest;
- zero unsupported/planning/unmatched records;
- reviewed quarantine allowlist exact match;
- no stale/divergent prior mappings or preservation;
- verified pre-apply backup/restore receipt; and
- a fresh authorization/operation token for the exact environment and mode.

Any mismatch fails before a migration run or source observation is written.
The public `dry-run` output is evidence, not by itself an apply permission.

## 22. Rehearsal apply

The later rehearsal uses the real source-neutral apply coordinator and
production participant/writer/transaction path behind a new rehearsal-only
guard. It does not create a one-off importer.

Phases are: guarded preflight; begin/resume exact run; deterministic executable
participants; typed preservation/quarantine; reconciliation; completion only
on acceptance; target counts/integrity; application smoke; human QA; post-
apply backup. Each product write and its MIG-FND outcome retains the existing
single-observation transaction. The whole migration is not one long database
transaction; environment restore is the rollback boundary.

No generally available production `wp ... apply` command is introduced.
The final production packet may render one single-use guarded invocation bound
to the authorization file and environment marker after Renée authorizes it.
The normal-development database fingerprint is captured immediately before
and after every rehearsal mutation and must remain identical.

## 23. Interruption and resume

Run at least two controlled interruption rehearsals in separate disposable
clones restored from the verified baseline:

1. stop after a known positive number of product observations; and
2. stop after at least one typed preservation has committed.

Preparation adds named, test/rehearsal-only fault points resolved from the
actual dependency order. A guessed integer is not adequate evidence of the
requested boundary. Each test proves interrupted status, atomic committed
records, zero partial product/ledger outcome, exact-source/build/target resume,
no duplicate target/observation/evidence, divergence refusal and final
equivalence with a clean run.

The sole accepted full-rehearsal environment is not deliberately interrupted;
the fault tests use sibling clones with verified restore plans.

## 24. Replay

After successful full rehearsal, invoke the exact same source, build, target,
contract bundle and accepted plan-set digest again. MIG-FND must resolve the
same completed run. Expected changes are:

- zero new product rows or relations;
- zero new observations, mappings, preservation or quarantine;
- zero writer calls and created/reused target operations;
- unchanged product and MIG-FND semantic fingerprints; and
- one new external attempt artifact/receipt only.

No new MIG-FND run row is expected under current exact replay semantics. The
attempt receipt is operational evidence, not a new migration run. Production
does not perform a ceremonial second full run; replay safety supports controlled
resume and is proven in rehearsal.

## 25. Reconciliation

Rehearsal acceptance requires every executable identity accounted, every
mapping target and graph edge valid, every preservation/quarantine exact,
zero uncommitted/unexplained/unexpected observations, zero failed records,
zero unresolved dependencies, zero broken mappings, complete source strategy,
no duplicate relations and a green privacy scan.

The cutover wrapper reports:

- `accepted`: Core reconciliation true and quarantine empty;
- `accepted_with_reviewed_quarantine`: Core reconciliation true and the exact
  non-empty quarantine set equals the explicit allowlist; or
- `rejected`: every other outcome.

The wrapper never rewrites a Core result or converts quarantine to success; it
adds the human cutover interpretation the existing boolean intentionally lacks.

## 26. Product-count validation

The final dry-run supplies expected counts for Works, Editions, canonical ISBN
claims, Items, ItemLocalDetails, Authors, contributor credits/relations,
classification contexts/selections, Series/memberships, contained Works/
relations, ReadingRounds, Personal Reading Truth, Notes, Ratings,
WrittenReviews, Wishlist and every preservation/quarantine reason.

Post-apply queries compare exact target-scoped and central counts plus
relationship counts to those expectations. Reused versus created targets are
reported separately. Old CURRENT counts are never used as expected final
values.

## 27. Item integrity

Acceptance proves no final erroneous legacy Copy or external-borrowed Copy
became an Item; every Item resolves to the exact Edition/Work and target
Library; ItemLocalDetails exist only for valid Items; there are no orphan
details; archive/history state matches the accepted plan; no active-loan
integrity rule is violated; and no inventory, location, acquisition or
condition default was invented.

The final Copy-exclusion set is taken from the approved final contract bundle,
not the old 23-row set by assumption.

## 28. Bibliographic integrity

Acceptance verifies Work/Edition/cardinality and titles, canonical ISBN claims
and aliases, Author/contributor identities/roles/order, Series identities and
positions, ordered containment, absence of containment cycles, provisional
contained entities, and exact relation counts. Equal names/titles never prove
identity. No provider call, enrichment or fake provider claim occurs.

Apply runs with network metadata providers disabled. Provider API credentials
are not migration inputs. If normal plugin boot requires provider settings,
the rehearsal/production runbook supplies only inert local boot configuration;
it must prove that no Open Library, Google Books or other provider request was
made.

## 29. User-data integrity

Acceptance verifies exact owner-scoped ReadingRounds and Reading Truth,
private Notes, Ratings, WrittenReviews and Wishlist. Reading Goals remain
product-absent and durably preserved. Reflections remain source-located
restricted evidence. No Rating/Review publication, Library ownership transfer,
fictive Round/date or privacy visibility change is introduced.

## 30. Preservation and quarantine integrity

For every preservation and quarantine, verify source family/type/ID, payload
hash, reason, manifest, mapper contract, final bundle, privacy class, logical
locator and evidence hash. Restricted recovery uses a freshly restored final
package and checks the hash without emitting the body.

Every quarantine has zero product mapping and no dependent target. Every
processed prior preservation retains immutable historical evidence. Ordinary
artifacts, logs, errors and docs contain no restricted payload or private
timestamp/body.

Operational logs may contain attempt/run ID, participant, progress counts,
privacy-safe source identity, reason code and hashes. They may not contain Note,
Review or Reflection bodies, acquisition free text, circulation counterparties
or notes, raw observation/preservation payloads, DB dump fragments or
credentials. Source/extraction/artifact directories are owner-restricted;
accepted files become read-only. Credentials are injected from the environment's
secret store and never written to command history, artifacts or Git.

## 31. Application smoke

After apply, as the target normal User, run bounded checks for login, My
Biblio, Library, basic Search, Work/Edition/Item reads, Author page, Series
page, Reading history, Notes and Wishlist. Prove no admin/super-admin
requirement and no provider/network dependency. Automated HTTP/CLI coverage is
preferred where already available; this is not a broad browser regression.

## 32. Human QA

Renée receives privacy-safe rehearsal identifiers for one representative of:

- ordinary owned Item;
- Item with non-empty local details;
- Work with Authors;
- Work with Series;
- contained Work;
- Reading history;
- private Note;
- Rating/WrittenReview;
- Wishlist;
- deliberately absent erroneous Copy; and
- preserved/deferred evidence that must not appear as product data.

The checklist records pass/fail, environment, run ID, tester and UTC instant.
It contains no private content. A failure is not waived by green counts.

## 33. Performance

Record wall-clock duration per phase, observation/operation throughput, peak
PHP memory when practical, database size before/after, backup/restore duration
and artifact sizes. Report timeouts, lock waits and resource limits. No runtime
estimate is accepted before this measurement, and no optimization is included
unless measured risk makes production unsafe.

## 34. Rollback

Rollback is environment restore, not inverse domain writes:

1. finish and accept one full rehearsal apply;
2. preserve its artifacts and post-apply backup;
3. restore the verified pre-apply backup into the guarded target;
4. verify exact baseline schema/data/target fingerprints and absence of the
   migration run;
5. optionally rerun apply with the exact accepted inputs; and
6. compare the second successful result with the first semantic fingerprints.

Any restore mismatch is a `HOLD`. The same restore-first principle applies to
production whenever integrity/usability is uncertain.

## 35. Production cutover sequence

The accepted sequence is:

1. freeze candidate code/dependencies/runtime;
2. hard-freeze V1 writes;
3. create/designate/retain FINAL CURRENT SOURCE;
4. produce drift and final contract bundle;
5. run final zero-write plans, including the production target-bound digest;
6. complete exact-source isolated apply, replay, interruption and rollback
   rehearsal evidence;
7. present the production authorization packet;
8. receive Renée's exact bounded authorization;
9. freeze V2 target writes, revalidate all inputs and create/restore-test the
   production backup;
10. execute the one authorized apply, reconcile and run smoke/QA;
11. accept V2 or restore production; and
12. retain V1 read-only for at least 30 days and until two verified post-
   cutover backups plus explicit Renée retirement approval, whichever is later.

The final source package is retained beyond V1 application retirement under
section 7. V1 is not deleted at cutover.

## 36. Failure handling

| Failure | Required response |
|---|---|
| preflight/source/authorization mismatch | stop before first write; invalidate attempt |
| clean controlled interruption with only committed/uncommitted state and no broken target | resume only exact authorized run after re-preflight |
| planning, reconciliation or target-integrity failure | stop; preserve evidence; restore if any write occurred; diagnose isolated |
| smoke or human-QA failure | keep V1 read-only; restore when usability/integrity is uncertain; no live repair |
| privacy/security issue | stop, restrict evidence, follow incident procedure and restore/rotate as applicable |
| unexpected quarantine/drift | `HOLD`; bounded review/design; never waive live |
| backup/restore/recovery failure | `HOLD`; no apply |

There is no fix-forward improvisation in production. Resume is allowed only
for the same source, bundle, code, runtime, target, plan and authorization and
only when reconciliation shows no broken/ambiguous state. Otherwise restore
first and require a new rehearsal/authorization as appropriate.

## 37. Final evidence bundle

The immutable bundle is indexed by an evidence-bundle SHA-256 and contains:

- **source:** package ID, archive/manifest hashes, export/freeze metadata,
  source/adapter versions and two recovery receipts;
- **code:** Git SHA, clean status, product/schema/Core/UI, dependency and
  participant-registry fingerprints;
- **plan:** contract-bundle hash, drift report, target-bound plan-set digest,
  target-neutral intent digest and final dry-run summary;
- **target:** environment/runtime fingerprint, explicit User/Library and
  empty-target proof;
- **rehearsal:** apply/run/attempt IDs, reconciliation, counts/integrity,
  interruption/resume, replay, performance, smoke/human QA, backups and
  rollback proof; and
- **security:** privacy scan, access classification and source-retention/
  recovery proof.

Every file is checksum-indexed, no-overwrite and read-only after acceptance.
There is no mutable `latest.json` authority. Source packages, DB dumps,
credentials and restricted bodies are excluded from Git and the ordinary
safe evidence bundle; it points to their restricted receipts.

Attempt identities use these distinct prefixes plus manifest prefix, target-
scope digest, UTC instant and random nonce: `final-dry-run`,
`rehearsal-apply`, `rehearsal-interrupt-product`,
`rehearsal-interrupt-preservation`, `rehearsal-replay` and
`production-apply`. Directories and receipts are append-only and never reused.
The MIG-FND run ID remains separately recorded; an exact replay may reuse it.

## 38. Production authorization packet

Before authorization Renée receives a concise safe packet with:

1. final archive/manifest/package and contract-bundle hashes;
2. final drift summary and classification of every delta;
3. final dry-run totals and both plan digests;
4. exact quarantine identities/reasons and acceptance request;
5. preserved/deferred evidence and product limitations;
6. rehearsal, replay, interruption/resume and reconciliation verdicts;
7. product integrity, smoke and human-QA verdicts;
8. rollback and final package recovery verdicts;
9. production backup/restore readiness;
10. candidate Git/runtime/registry fingerprint;
11. production target User/Library;
12. the single-use guarded production process and restore process; and
13. explicit open-circulation gate status.

Silence, old general approval, rehearsal GO or approval for another source,
target or digest is not authorization.

## 39. Authorization binding

Authorization wording is exact:

```text
PRODUCTION APPLY AUTHORIZED FOR:
- Git SHA: <40-hex>
- FINAL CURRENT archive SHA-256: <64-hex>
- FINAL CURRENT manifest SHA-256: <64-hex>
- final contract bundle SHA-256: <64-hex>
- production target User: <id>
- production target Library: <id>
- production plan-set digest: <64-hex>
- production runtime fingerprint: <64-hex>
- pre-apply backup receipt: <id-and-sha256>
- authorization packet SHA-256: <64-hex>
```

The authorization artifact also states whether an exact-run controlled resume
is included. Any changed value, final-source write, code/dependency/runtime
change, target write, bundle regeneration or expired/missing freeze invalidates
authorization before first/next write.

## 40. Change freeze

The candidate SHA, lockfiles/vendor resolution, WordPress/PHP/MariaDB versions,
schema, Core/UI, participant registry, adapter and mapper/contract loader are
frozen before the accepted exact-source rehearsal. No code, dependency,
WordPress/plugin or configuration upgrade is allowed between rehearsal and
production apply. Any executable change requires renewed tests, final dry-run
and at least the affected rehearsal gates; a semantic/transaction/runtime
change requires a new full rehearsal.

## 41. Final-export-after-rehearsal policy

The accepted full rehearsal uses **the actual final production source
snapshot**. V1 freezes before export and remains read-only. Therefore no
ordinary fresh export follows accepted rehearsal.

An earlier pre-final snapshot may be used for tooling practice, performance or
operator training, but it cannot satisfy final rehearsal or authorization. If
an exceptional new export is required after rehearsal, the old designation
and authorization are invalid. Re-run intake/recovery, drift, contract bundle,
full dry-run and quarantine review. Another full apply rehearsal is mandatory
unless the change is solely transport metadata with byte-identical extracted
manifest and contract/plan/intent digests; added records are not transport-only
drift.

## 42. Required implementation slices

Do not start these automatically.

1. **MIG-CUTOVER-PREP-01A — Final source intake, drift and contract bundle.**
   Implement guarded export/intake, immutable manifest/package registry,
   source recovery, deterministic drift classifier, typed final population
   bundle, target-neutral intent digest and no-overwrite artifacts.
2. **MIG-CUTOVER-PREP-01B — Guarded rehearsal apply and rollback tooling.**
   Add rehearsal-only apply composition, all-table empty guard, positive OPS
   guard, zero-error preflight, named fault points, phase-aware backups,
   replay/fingerprint/privacy evidence and three-state cutover reconciliation.
3. **MIG-CUTOVER-REHEARSAL-01 — Execute the final-source rehearsal.** Run the
   exact freeze-bound final source through dry-run, apply, interruption,
   replay, counts, QA, recovery and rollback; produce the verdict only.
4. **MIG-CUTOVER-AUTH-01 — Production authorization packet and guarded
   environment runbook.** Capture actual production runtime/target/backup
   facts and render the single-use process. This slice still performs no apply.
5. Conditional **D-MIG-CIRC-CUTOVER-02** if any operationally open circulation
   remains, followed only after a decision by a bounded implementation slice.

Each implementation slice is separately authorized, High risk, independently
reviewed and committed locally without push unless Renée later says otherwise.

## 43. Acceptance criteria

This design is complete because it defines:

- hard V1 and V2 write barriers and the exact-source invalidation rule;
- archive, manifest, logical ID, designation, dual retention and recovery;
- deterministic A-J drift and structural-slot detection;
- A-D manifest/population classification and a typed final bundle;
- zero-write final planning and complete final-source reporting;
- reviewed-quarantine and circulation-specific gates;
- isolated production-like target, runtime capture, explicit identity and
  exhaustive empty-target rules;
- pre/post backup, restore proof and rollback;
- real-path rehearsal apply, named interruptions, resume and exact replay;
- reconciliation, domain counts/integrity, privacy, smoke and human QA;
- performance, artifact immutability and evidence retention;
- exact production packet, authorization binding and change freeze; and
- smaller bounded implementation/rehearsal slices.

No new product or architecture decision is required to implement this
contract. Concrete storage URIs, production runtime facts, target IDs and
operator identities are environment facts supplied under the contract. If
final circulation remains operationally open, that evidence activates the
separate decision in section 15 and production authorization stays blocked.

## 44. Production apply gate

Production apply is authorized only after all of the following are true:

1. PREP-01A and PREP-01B are GO/CLOSED at the exact candidate SHA;
2. V1 is hard-frozen and FINAL CURRENT SOURCE is designated, retained and
   recoverable from both stores;
3. drift contains only accepted A-D cases and the final bundle is approved;
4. final dry-run is zero-write with zero unsupported/planning/unmatched state;
5. all quarantine is explicitly reviewed and accepted;
6. no operationally open circulation remains, or a separate transition
   decision and implementation are GO/CLOSED;
7. exact-source isolated apply, interruption/resume, replay, reconciliation,
   counts/integrity, privacy, smoke, human QA, performance and rollback pass;
8. production runtime/target/empty-state and full backup/restore are proven;
9. code/runtime/source/bundle/target/plan are frozen;
10. the final authorization packet is complete; and
11. Renée issues the exact authorization from section 39.

Until then the verdict is:

```text
PRODUCTION APPLY NOT AUTHORIZED
```

**Design verdict: DESIGN GO.** The contract is closed; implementation,
rehearsal and production authorization remain separate future actions.
