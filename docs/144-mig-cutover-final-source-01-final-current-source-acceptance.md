# MIG-CUTOVER-FINAL-SOURCE-01 — FINAL CURRENT SOURCE acceptance

Freeze-verification status: **FREEZE VERIFIED**.
Final source acceptance status: **CIRCULATION_CUTOVER_REVIEW_REQUIRED**.
ReadingRound drift blocker: **CLOSED — reviewed effective C, mechanical E retained**.
The one final retry completed all FV-00–FV-15 phases successfully under Renée's
explicitly corrected, V1-specific threat boundary. Earlier failed attempts
remain failed/inconclusive historical evidence; they are not retroactively GO.
Updated: 2026-09-23, approved ReadingRound disposition implemented, independently
reviewed and verified by one fresh zero-write driftgate. Earlier classification
closure below is historical evidence, not the current authorization boundary.
Severity: High. Core tooling correction is uncommitted; no product/schema/UI change.

## Approved ReadingRound completion disposition — 2026-09-23

Renée approves **CURRENT USER DATA — EXISTING CONTRACT COMPATIBLE** only for
Book `1771014295306`, round `rr-1-20260915T133135697-na`. This is newly
registered completion, not representational equivalence. `finishedAt` changes
EMPTY STRING→`2026-09-16T12:00:00.000Z`; `finishedAtPartial` changes
NULL→`{"value":"2026-09-16","precision":"day"}`. All other round fields are
exactly unchanged. Operational start/end are calendar days2026-09-15/16;
noon UTC is not proof of an actual reading-clock instant. Source remains null,
lifecycle ended/completed, provenance migration_imported.

`ReviewedReadingRoundCompletionDisposition` binds the reference/FINAL package
digests recorded below, the complete `{book_id,record}` payload hashes and all
53 round observations. Mechanical E and original reason remain auditable;
only this exact finding gets reviewed effective C. Report/bundle binding uses
the existing closed architecture, with no general exception registry.

- Raw payload old/new:
  `06e3d14f8a801bc2094333ae5f8fe97e1e388c3fe076202e883d05416e75dff7` /
  `145d3f0e42f2451e0d68a63c1c6bd0aa60e33faea0278df7a081ffb9b08abdf0`.
- All53 round observations old/new:
  `e61b23bf4e1ca3d92e0f4bb327057d759c59a1b1fd6b45181bb252bfcfc8b60a` /
  `3d88379c2ad5017e8931c18a036a50d8e933dad3ce9857ca93f19295fbdc2576`.
- Approval SHA: `5e170abda7c68f6556778746ceb00e8456af931a73a819e174e1054c32c8935a`.
- Review SHA: `dd6c9cbb89a87fa7a30745641888372cbec43061a7a582b42d4467d83b084c70`.
- Review closure SHA: `bf22f5a2f583c9d13c03c8778273e50bf5a9108649d18be581168ebec16394d9`.
- Population evidence SHA: `a7693037ce1972a5ebc8971c6f995f990e955549f7684e8dd231258d4f90b813`.
- Mapper evidence SHA: `40961a69e1490f142cba1db6dd5edf3068e7aefe1cc2755780b4726055309f83`.

Old target has no plan (`active_round_missing_concrete_source`); FINAL has one
`stable_reading_round_planned` plan. Total plans53→54, completed51→52,
stopped2→2, including three unchanged registration plans. Source rounds stay53;
52 are byte-equivalent. All existing Reading plans and all1,064 PRT plans
(378/326/360) stay equal, with no new target-Book truth. Diagnostic planhash
`87bc9d8696b1fa30124e7f5fea9d2be41af713e3f968421f35a76bf5a64f867e`
and PRT hash `ef99096aa92c551b8ca52612570ac818b5600df63efe398745ab77db39e414e8`
are bound to review-user, not future production/rehearsal plan authority.

Raw finish fields, Book-save timestamps, history and original representation
remain in immutable restricted source/retention. No extra round or exact-clock
truth follows from history; no new preservation plan/reason is introduced.
Reading/PRT mapper and accepted lifecycle/precision rules remain unchanged.

Negative boundaries: any other Book/round, changed start or pauses, stopped or
contradictory lifecycle, removed or different end, changed precision,
NULL/ABSENT/EMPTY mismatch, extra round, inferred completion from save/history,
reread or Item inference, different package/manifest or changed PRT output is
not covered. The golden test fixture contains only53 privacy-safe hash/ID
observations. After independent review, rerun only the zero-write driftgate;
then assess the separate circulation gate and STOP if review is required.
No FINAL dry-run, rehearsal, database action, apply/import, commit or push.

### ReadingRound-disposition closure — stop at circulation review

Targeted tests: **154 tests / 4,272 assertions PASS**, covering the new closed
disposition, unchanged Reading mapper, prior Author/classification dispositions,
source adapter, preparation and rehearsal-authorization regressions. Three
changed production PHP classes pass scoped PHPStan and syntax; whitespace
passes. All14 inventoried CURRENT mappers/contracts stay hash-identical. A
fresh real-source in-memory comparison is exactly equal to the complete
approved mapper diagnostic: one new completed/day plan, all existing plans
and all1,064 PRT plans unchanged. Both retention DMGs match their independently
verified container hashes without new mount/decryption or mutation.

Independent review confirmed both package identities, all53 golden/raw round
hashes, evidence/approval pins, negative boundaries, production-diff scope and
the read-only/networkless execution helper. No must-fix remained; explicit GO
preceded the single new attempt `final-reading-disposition-20260923-01`.

- Result: `MAPPING_CONTRACT_COMPATIBLE`, exit0, `reviewed_compatible`.
- Mechanical counts: A9798, C1139, E2, I1; all others0.
- Reviewed/effective counts: A9798, C1142; E–J all0.
- Author, classification queue and ReadingRound dispositions are separately
  bound; no unreviewed drift remains. Mechanical evidence is not rewritten.
- Artifact SHA: `32fd35548536f80a23663ddcd2f1a4f87076fee397334c27056c66a999b4b405`.
- Bundle SHA: `54228469e65a458d94266961e167988931bafcf0ca2874870cdea4155e851ff4`.
- Tooling evidence SHA: `29a1ea05102a3f1f05d4c67d99a7835984a4e9c9204b0f8fcd56752da018cb2c`.
- Executable-tree SHA: `a9a5be990cbb81843d3ca2f41d3aad3ee6c798b84fa481ff4c47bfbe54a69d29`.
- Fresh mapper/retention check SHA: `adbfdf88d1df209069550d17686d44dfafcd4caac6c95dc34a67cb08d7d1ea02`.

Evidence is append-only under WORK/artifacts (`reading-disposition-*` and
`prep01a-reading-disposition`); a fresh read-only extraction is under
WORK/intake/final-reading-disposition. Existing source/evidence/repo mounts
were read-only; only new intake/evidence mounts were writable. No LIVE mount,
network, WordPress/database bootstrap or FINAL migration dry-run occurred.

The next gate is **CIRCULATION_CUTOVER_REVIEW_REQUIRED** under docs/141 §15 and
docs/113 §11: eight coherent open IDs (four borrowed, four lent_out), zero
unambiguous closed IDs, one contradictory ID. All nine circulation payloads
are exactly reference-equal (A); no new or changed conflict shape appears.
The existing `circulation-1780741593722-1crrv` Book-open/Copy-closed conflict
remains a no-target `circulation_source_conflict_candidate`, requiring its
own explicit review. Preservation does not provide operational loan continuity.
No loan is auto-closed/backfilled and no circulation disposition is granted.
**STOP at FINAL circulation review; no FINAL zero-write dry-run until all
contract/circulation gates are explicitly resolved.**

## Approved classification review-queue disposition — 2026-09-23

Renée approved **CONTRACT COMPATIBLE WITH PRESERVATION** only for the exact
reviewed reference/FINAL queue delta. `ReviewedClassificationQueueDisposition`
is a closed private-constructor value admitted alongside the existing Author
value in `SourceDrift`. Mechanical I, original reason and old/new payload hashes
remain intact; the separate reviewed disposition makes this one finding
effective C. Report validation dispatches to each actual disposition type's
factory. The existing report/bundle digest carries both dispositions, without
new mapper behavior or a parallel approval system.

All 65 classification definitions, seven alias rules and 1,139 ordered Book
assignments are identical. Queue446→450 consists of442 existing unchanged,
four existing entries updated only with observations/context/lastSeenAt and
four new pending provider proposals, with zero removals or existing
status/resolution changes. The419 changed existing positions follow the
existing V1 sort. All Book-side classification metadata differences are
limited to `taxonomyMeta.canonical.normalizedAt`; that exact review does not
establish a general timestamp-ignore rule.

### Closed bindings and preservation

The reference/FINAL package digests remain respectively
`d720dacf37a611761549525d33d186f64bd9b3ee9bf5bc4f68feb5edfd9caf69` and
`0ac55dbac2b98b4e2141573a94a3aa600d4cf3bdda8fc72a827574cf0cadac4a`.
They bind the exact archives/manifests, logical package IDs, source family,
version and adapter documented below. No path, normalized label or user
provided override is an alternative identity.

- Reference queue payload:
  `a37d40c73f73b496500d87cbc564127b00d54f828c0e34739fb3a1864ea4b864`.
- FINAL queue payload:
  `4a0e25f651005c83f021fd9d7350cbab03196fff2c9dd663affb520e28ab0bcd`.
- Reference queue file:
  `1a1a742bee1a30f162f72a370dfafc9162c4afbffab7a504d41a39672e89ba2c`.
- FINAL queue file:
  `0af964c1938cf3af1e63c492e531ee949aa059c878ef1312c229d251e1ca862a`.
- All other classification observations: count1,208, SHA
  `f4c8e58812cdd86cad525573b0fba21c5685cfe2004b2a3f2ea1964b75e912e8`.
  This is1,139 Bookvectors +65 classification definitions +3 Carriers +1
  seven-rule aliasaggregate, ordered by the existing observation identity.
- Book assignment vector-set SHA:
  `f642e66c57993a2e03ebdc7a20ee050e9d3e9ea366a82097b6b7fd61605896fc`.
- Definition-set SHA including the three Carriers:
  `98a68e9def674f069c39f5df39fe2f1318a1c08456aebaa2492adc56af25d7c4`.
- Alias-observation SHA:
  `01538f9d3d864964c46f4edc68a12bc0e88b1da71068f208619ca1f2b836a5f2`.

The complete restricted FINAL queue bytes, including entries, context,
timestamps, resolution, provider observations and reconstruction structure,
remain in the immutable source/archive/retention. Historical446-entry bytes
remain independently recoverable from the reference. Ordinary reports retain
hash/count evidence and the existing `taxonomy_review_queue_preserved` route.
No synthetic assignment, context-derived identity, new taxonomy, fuzzy match,
new typed preservation plan or private-value output is introduced.

Checksum-bound review artifacts under WORK/artifacts:

- `final-classification-contract-review.md`:
  `b61430be6412da5452de4fcf6775d175f9ecf3883b8bcd3692a0a8f4315732a1`;
- `classification-review-population.json`:
  `564dcc0c0fe43208dc7988d33f4a15f3ada7be6926af00ebd842855d6dca8a21`;
- `classification-review-mapper.json`:
  `bb926171e05b00d698b29ec53c20abb98dacbdab997040e1ef4ac4bfdc7e1d12`;
- `classification-review-exact-metadata.json`:
  `59e0f4b44d084cc32501590138624f9dc86fb834e7f2677c54f3195201c09408`;
- approval-text SHA:
  `775620017769bc07c9f56f41568df1432659d309839b1304cee9cdaa836944f2`.

The exact payload/manifest binds reviewed file/population facts; snapshot
observations do not independently expose raw queue counts or metadata. Book
classification vectors do not contain taxonomyMeta: its reviewed invariance
is bound by the complete package manifest and metadata evidence. The new
golden test fixture contains only grouped stable IDs/hashes and restores all
1,208 actual observations, with no private source contents or approval knobs.

Negative boundaries include changed definitions/IDs/aliases, Book assignments
or source-array order, removed queue rows, altered review status/resolution,
new taxonomy/context-derived Book identity, changed hashes/population and any
different package/manifest. All additional unreviewed drift remains blocking.

### Required next stop: independent ReadingRound E

ReadingRound `rr-1-20260915T133135697-na`, Book `1771014295306`:
`finishedAt` empty→timestamp and `finishedAtPartial` null→day-precision object.
Reference payload `06e3d14f8a801bc2094333ae5f8fe97e1e388c3fe076202e883d05416e75dff7`;
FINAL payload `145d3f0e42f2451e0d68a63c1c6bd0aa60e33faea0278df7a081ffb9b08abdf0`.
Reason `new_raw_value_enum_or_source_shape` remains mechanical/effective E.
It receives no disposition, normalization or preservation/mapping change.
After the classification gate check, STOP on **HOLD — CONTRACT_REVIEW_REQUIRED**;
do not automatically begin a ReadingRound review or FINAL dry-run.

### Classification-disposition closure: HOLD on independent ReadingRound E

Targeted verification: **92 tests, 675 assertions, exit0** across
ReviewedClassificationQueueDispositionTest, ReviewedAuthorShapeDispositionTest,
CurrentV1ClassificationMapperTest, CurrentV1SourceAdapterTest,
FinalSourcePreparationTest and RehearsalSourceReviewTest. Four changed
production classes pass scoped PHPStan and syntax; whitespace passes. All14
inventoried CURRENT mapper/Author/classification contract files are unchanged.
No broad suite, WordPress smoke, database bootstrap or FINAL dry-run ran.

The independent reviewer compared the full golden fixture to the existing
real PREP-artifact (1,208 observations and all three component hashes), checked
approval/review/preservation pins, the closed union/report rebinding guards,
combined Author+queue+ReadingRound regression and the one-shot execution
helper. No must-fix remained; explicit GO preceded the driftgate, not a FINAL
dry-run or commit. Golden fixture SHA:
`7a691b2420c0cad8affd6b9624bbd831716a16a0d2602bf5f8bca225c57b4fd2`.

New immutable evidence under WORK/artifacts:

- `classification-disposition-targeted-tests.json`:
  `6509fbc7d0901c8cebc483824d6351afa8c01039f80bf5454bb7c2f209b4b309`;
- `classification-disposition-tooling.json`:
  `7845c8680a5c14d35a949a80c78461d19860cb545a4ef7dc4d2c32a1fb288bf8`;
- executable-tree SHA:
  `00f892d063d603808f4ec51b3d005a3355f8f84722e481222cd6e8b2b9daf279`;
- `classification-disposition-preparation-result.json`, with full command and
  exact mount/attempt bindings; and
- `classification-disposition-before.json`, `classification-disposition-after.json`
  and `classification-disposition-closure.json`, each with SHA-sidecar.

PREP-01A ran exactly once as `final-classification-disposition-20260923-01`.
It inspected the original unchanged FINAL ZIP through fresh hardened intake
under `WORK/intake/final-classification-disposition`. Only that new intake root
and `WORK/artifacts/prep01a-classification-disposition` were writable. Existing
WORK/repository were read-only mounts; network disabled, LIVE not mounted,
no database/normal WordPress boot.

- Result **CONTRACT_REVIEW_REQUIRED**, expected exit2, state `review_required`.
- Artifact SHA:
  `d9970b93c5159410061f6d7af30094e82d1ae7e3b91c46b4ca414cebd2b485b4`.
- Regenerated bundle SHA:
  `f87695441b33da6ace17dfe4ab0c5a51bbf5a5a4177634cb23f2bbf63dc6073f`.
- Mechanical counts unchanged: A9,798; C1,139; E2; I1; other0.
- Effective counts: A9,798; C1,141; E1; I0; other0.
- Exactly two dispositions: prior Author E→effective C and approved queue
  I→effective C with `CONTRACT COMPATIBLE WITH PRESERVATION`.
- Classification blocker closed. Exactly one effective drift blocker remains:
  ReadingRound `rr-1-20260915T133135697-na` as specified above, with
  `reviewed_disposition=null`. No automatic ReadingRound review began.
- Separate `CIRCULATION_CUTOVER_REVIEW_REQUIRED` remains unchanged; no circulation
  acceptance is implied by closing the classification blocker.

The before/after source checks bind the exact accepted source/protection
observation `6addc605f38ac22b88a0c03cf33a89175770ddaec24b669a368dc6f4e309ea62`,
contentfingerprint `381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`
and the unchanged reference/FINAL archive hashes. No protection, retention,
source or historical evidence was modified. No normal-DB equality claim is
made: execution had no DB access. Work remains local/uncommitted on base HEAD
`16e76f2d0ec32557b46b509d2a70df375c23f4cb`; no push or apply/import.

Exact eleven repository files changed in this queue-disposition task (earlier
dirty changes elsewhere are preserved):

- `docs/119-mig-02-class-map-01-current-v1-classification-mapper.md`;
- `docs/141-d-mig-cutover-01-final-source-and-rehearsal-contract.md`;
- `docs/142-mig-cutover-prep-01a-final-source-intake-and-drift-tooling.md`;
- `docs/144-mig-cutover-final-source-01-final-current-source-acceptance.md`;
- `web/wp-content/plugins/biblio-core/src/Application/Migration/Cutover/ReviewedClassificationQueueDisposition.php` (new);
- `web/wp-content/plugins/biblio-core/src/Application/Migration/Cutover/SourceDrift.php`;
- `web/wp-content/plugins/biblio-core/src/Application/Migration/Cutover/FinalSourceDriftEngine.php`;
- `web/wp-content/plugins/biblio-core/src/Application/Migration/Cutover/FinalSourceCompatibilityReport.php`;
- `web/wp-content/plugins/biblio-core/tests/Fixtures/final-classification-reviewed-observations.json` (new);
- `web/wp-content/plugins/biblio-core/tests/Unit/Infrastructure/ReviewedClassificationQueueDispositionTest.php` (new);
- `web/wp-content/plugins/biblio-core/tests/Unit/Infrastructure/CurrentV1SourceAdapterTest.php`.

The Author-disposition attempt below remains historical evidence of its then
open classification blocker; it is not rewritten retroactively.

## Approved Author-shape disposition — 2026-09-23

Renée approved only `1771014295306`: `authorIds` ABSENT → EMPTY ARRAY and
`authorsLocked` ABSENT → FALSE, with unchanged authors/order and stable-ID/
occurrence semantics. The original mechanical E remains visible. The closed
typed disposition resolves this one observation as effective C; the two fields
do not become two separate drift records. No Author mapper, preservation plan
or preservation reason code changes. Other E/I/circulation issues remain open.

Evidence under WORK/artifacts (immutable, with SHA-256 sidecars):

- review `author-shape-contract-review.md`:
  `7023ac1cad556f07de2f18152575b65c0287cd0fa2f4912862b9f92ea540273e`;
- population `author-shape-population-review.json`:
  `a609f84764da9d2622992b84543111161ab5587fe20d2585b3363c6c0316e76f`;
- mapper comparison `author-shape-mapper-review.json`:
  `97db14a66b4acf209db419fa3e637458c459de195f03697312815a647a3eaab2`;
- approval-text SHA:
  `f275997ccbdf7e0074705e091bb2f448fb1c261a758ab94c78d3b7888bc1a176`.

The binding includes the exact existing reference/FINAL archives and manifests
documented below. Full package identity digests are
`d720dacf37a611761549525d33d186f64bd9b3ee9bf5bc4f68feb5edfd9caf69`
(reference) and
`0ac55dbac2b98b4e2141573a94a3aa600d4cf3bdda8fc72a827574cf0cadac4a`
(FINAL). The full Book payload hashes are
`6e4a63b4ef007488a788ad4b7c1dc74a2e4b6cc5ae773a0a23193e6baaaa6f72`
and `d7228e61b0c367a78dce0fe5bdd1a8cfdbebc36ce97864df208f8294f7490c0a`.
The unchanged contributor-vector hash is
`4ba30f5f02b3d18cfd2895913bd9209dad78b9a8415bffc856d990dc1fb88c00`.
The policy also pins both raw shape hashes, source identity, semantic class,
ordinary state and structural flag. No name/raw private payload is required in
code or evidence. NULL, TRUE, non-empty/changed IDs, name/order/count changes,
extra fields, other Books or different package/manifest claims cannot match.

The report retains mechanical counts and adds effective counts/dispositions.
The bundle binds those dispositions and its exact report candidate snapshot.
`reviewed_compatible` means drift compatibility only; the rehearsal guard is
unchanged. With any remaining effective E–J, state stays `review_required`.
The existing Core 2.51.1 patch remains local/uncommitted, as part of the ongoing
FINAL-source tooling correction. Prior evidence and export provenance below
remain historical, not retroactively rewritten.

Before implementation, `WORK/artifacts/author-disposition-before.json` confirmed
the exact accepted source/protection observation and both archive hashes.
Final targeted results, independent review and new drift-attempt outcome follow.

### Author-disposition closure: HOLD at the next contract blocker

Targeted verification: **61 tests, 384 assertions, exit 0** across
ReviewedAuthorShapeDispositionTest, CurrentV1AuthorMapperTest,
FinalSourcePreparationTest, CurrentV1SourceAdapterTest and
RehearsalSourceReviewTest. Six changed production classes passed scoped PHPStan
and PHP syntax; whitespace passed. All 13 inventoried CURRENT mapper/Author
contract files remain byte-identical to the previous tooling inventory.
No full suite, WordPress smoke, database bootstrap or FINAL dry-run ran.

The independent reviewer required exact report-candidate versus bundle-snapshot
digest validation. It was implemented with wrong-package and wrong-population
tests, alongside explicit refusal to grant rehearsal authority. The final
independent code/docs review was clean before the real drift rerun.

New evidence: `WORK/artifacts/author-disposition-targeted-tests.json` and
`author-disposition-tooling.json`. The latter SHA is
`22018d0da0061ddba8c4fd0a32f6df6e16aaaba728a6160e9942768911972eed`;
executable-tree SHA is
`715d93fe4b9ee05b0b64f8dfdb519af6480b305e9957f0d5c4ab3ba5ad9f099d`.
This is local/dirty tooling on the same base HEAD, not a committed final SHA.

Actual PREP-01A ran once under attempt
`final-author-disposition-20260923-01`. It used the original unchanged FINAL ZIP
and fresh hardened extraction under `WORK/intake/final-author-disposition`.
Repository and existing WORK data were read-only mounts; only the new intake
root and `WORK/artifacts/prep01a-author-disposition` were writable. Network was
disabled and LIVE V1 was not mounted. No database or normal WordPress boot.

- Result **CONTRACT_REVIEW_REQUIRED**, exit 2, state `review_required`.
- Artifact SHA:
  `65e415c5a1dc8675f54078dbb8a5e42c028d6b9a6a6beb3bc3eadff8978da49a`.
- Regenerated bundle SHA:
  `ef9b33c8000f2a03c5270ced5a10a64ded0bc11d048e09d734f7d850194daebc`.
- Mechanical totals unchanged: A=9,798; C=1,139; E=2; I=1; other=0.
- Effective totals: A=9,798; C=1,140; E=1; I=1; other=0.
- Exactly one applied disposition: Book `1771014295306`, mechanically E,
  reviewed/effective C. Its original reason, old/new hashes and review-required
  finding remain visible; it is no longer an effective blocker.

The next blocking observation is **I**, domain `classifications`, type
`v1.classification_review_vector`, ID `classification-review-queue`, reason
`stable_identity_material_or_structural_evidence_changed`.
Reference payload SHA:
`a37d40c73f73b496500d87cbc564127b00d54f828c0e34739fb3a1864ea4b864`;
FINAL payload SHA:
`4a0e25f651005c83f021fd9d7350cbab03196fff2c9dd663affb520e28ab0bcd`.
No interpretation or acceptance of this vector drift occurred. One further
effective E is also unresolved. The separate circulation gate remains
`CIRCULATION_CUTOVER_REVIEW_REQUIRED`. Stop at **HOLD — CONTRACT_REVIEW_REQUIRED**;
no mapper preparation or FINAL dry-run follows this gate.

`author-disposition-before.json` and `author-disposition-after.json` each bind
the accepted source/protection observation
`6addc605f38ac22b88a0c03cf33a89175770ddaec24b669a368dc6f4e309ea62`,
content fingerprint
`381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`,
and unchanged reference/FINAL archive hashes. Freeze protection was never
changed. Earlier artifacts remain untouched; no normal-DB fingerprint claim is
made because this operation had no database access.

Exact repository files changed in this Author-disposition slice (existing
DS_Store changes elsewhere are retained, not counted as new work here):

- `docs/122-d-mig-auth-map-01-current-v1-author-mapping.md`;
- `docs/123-mig-02-auth-map-01-current-v1-author-mapper.md`;
- `docs/141-d-mig-cutover-01-final-source-and-rehearsal-contract.md`;
- `docs/142-mig-cutover-prep-01a-final-source-intake-and-drift-tooling.md`;
- this existing uncommitted `docs/144` dossier;
- Core `src/Application/Migration/Cutover/ReviewedAuthorShapeDisposition.php` (new);
- Core `src/Application/Migration/Cutover/SourceDrift.php`;
- Core `src/Application/Migration/Cutover/FinalSourceDriftEngine.php`;
- Core `src/Application/Migration/Cutover/FinalSourceCompatibilityReport.php`;
- Core `src/Application/Migration/Cutover/FinalSourceApprovalState.php`;
- Core `src/Application/Migration/Cutover/FinalPopulationContractBundle.php`;
- Core `tests/Unit/Infrastructure/ReviewedAuthorShapeDispositionTest.php` (new);
- Core `tests/Unit/Infrastructure/CurrentV1AuthorMapperTest.php`.

No Author mapper implementation, preservation reason/plan, source/archive,
schema, UI, normal database, retention credential or freeze mechanism changed.
No commit, push, rehearsal or production apply/import occurred.

## Previous bounded DS_Store correction and FINAL drift HOLD

### Corrected interpretation, not changed source bytes

Renée explicitly admitted only `data/.DS_Store` as
`NON_SOURCE_PACKAGE_METADATA`. The implementation uses an optional typed
`MigrationPackageMetadataProvider` and `NonSourcePackageMetadata` value. The
CURRENT adapter admits only that exact path; the intake receipt separately
inventories its path, size, SHA-256 and classification. No basename/dotfile
wildcard, source record, finding, preservation, quarantine or mapper population
is introduced. Existing `__MACOSX/` behavior is unchanged.

Manifest choice is **A**: every file remains in the full extracted-package
manifest. There is no separate semantic manifest. The package factory,
deterministic hash definition, ZIP guards and all mapper implementations are
unchanged. The freshly recomputed FINAL manifest is therefore still
`43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`.
No source cleanup, archive rebuilding or metadata normalization occurred.

Version: Core **2.51.1**, Product **v2.001**, schema **1026**, UI **0.20.0**.
Base HEAD remains `16e76f2d0ec32557b46b509d2a70df375c23f4cb`; the correction is
**uncommitted / dirty**. The complete executable-file inventory is bound by
`dfca6ff9984fa99c8d8862892428276b47f3dc247f5488bfc19091fd53680c06`.
The export's original Git provenance is retained unchanged; it is not falsely
presented as the complete identity of corrected tooling.

### Targeted tests and independent code review

Only targeted synthetic tests ran in standalone PHP 8.3.31, network disabled,
repository mounted read-only, no WordPress bootstrap or DB. Final result:
**17 tests, 159 assertions, exit 0, no PHPUnit notices**. Evidence:
`WORK/artifacts/dsstore-targeted-tests.json` and
`WORK/artifacts/dsstore-corrected-tooling.json`, each checksum-bound.

The new tests first reproduced the original exact-path rejection. They prove:

- binary metadata is retained verbatim, inventoried and never JSON-parsed;
- identical domain profiles, records, findings, source-type populations,
  structural observations, circulation and quarantine evidence with/without it;
- real Catalog/ReadingGoal mapping plus real `MigrationPlanPreparer` yields
  identical plan populations/dispositions with positive mapped, preserved and
  quarantined controls; synthetic target participants explicitly forbid apply;
- plan-set digests correctly differ when the full package manifest changes;
- root/nested `.DS_Store`, arbitrary hidden/unknown paths and malformed or
  unreviewed canonical fields still fail closed;
- traversal, absolute paths, duplicate normalized paths, symlink and FIFO at
  the exact allowed path, and extraction overwrite are still refused; and
- source/archive bytes and existing accepted extraction remain unchanged.

Independent review closed its additional direct-plan-test request and approved
the bounded correction before the real intake retry. No full Core gate or
normal-environment smoke was run under this targeted-tests-only authorization.

### Same-archive intake and two verified recoveries

The new hardened intake succeeded against the exact original export ZIP,
creating `WORK/intake/final-export-dsstore-correction/<package-id>/source`.
All 1,830 files / 19,438,086 bytes are retained. Receipt:
`WORK/artifacts/dsstore-corrected-intake-20260923-01.json`.
The separate metadata inventory contains exactly:

- path `data/.DS_Store`;
- size `6148`;
- SHA-256 `0272b10300ba8e7b4c70468d020f5d1d6f3e35075bbc9d141b53b24dcc493cb9`;
- classification `NON_SOURCE_PACKAGE_METADATA`.

Two real AES-256 encrypted, read-only-format copies now exist at
`WORK/retention-primary/<package-id>.dmg` and
`WORK/retention-recovery/<package-id>.dmg`. Each was independently attached
read-only using its separate pre-provisioned login-keychain password, fully
read back, checksum/byte-size verified and detached. No password was printed.
Each recovered archive then received its own fresh hardened extraction and
reproduced the exact manifest plus **all five original restricted Reflection
locator/evidence hashes**. Expected hashes were established once from accepted
FINAL intake, not recomputed from each recovered copy. Recovery probes confer
no population/mapping approval. Receipts are
`final-{primary,recovery}-retention.json` and
`final-{primary,recovery}-intake-recovery.json` under WORK/artifacts.

Retention remains `two_local_encrypted_copies`:
`independent_hardware_failure_domains=false` and
`residual_risk_accepted_by_owner=true`. No hardware independence claim.

### Prospective invariance

Three checksum-bound checkpoints exist under WORK/artifacts:
`dsstore-correction-before-20260923-01`,
`dsstore-correction-before-intake-20260923-01` and
`dsstore-correction-after-20260923-01`.
Their source/protection observations are byte-identical to the accepted freeze
observation, SHA-256
`6addc605f38ac22b88a0c03cf33a89175770ddaec24b669a368dc6f4e309ea62`.

- Frozen source fingerprint unchanged:
  `381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`.
- FINAL archive unchanged:
  `89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a`,
  **13,688,229 bytes**.
- Reference archive remains `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
  reference manifest remains `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
- Source immutable flags were never changed. V1 remains frozen.

### Fresh PREP-01A result and first blocking issue

Actual PREP-01A ran once with standalone PHP 8.3, network disabled, current
corrected tooling read-only, WORK as the only writable data mount. It verified
the reference ZIP and manifest, performed fresh FINAL intake and generated:

- result **CONTRACT_REVIEW_REQUIRED**, approval state **review_required**, exit 2;
- artifact SHA-256
  `67374024e9595b83423ecc2ba2e2a0d480742251bee7ecf7e3eead9a9ea40ae6`;
- freshly computed `FinalPopulationContractBundle` digest
  `060c2b27d4b1a9aaa82f0108c570825282468ea22f14af0a0e91bf30e943a0b6`;
- drift totals A=9,798; C=1,139; E=2; I=1; all other categories=0.

The first blocking observation is **category E**, domain `catalog_books`,
source type `v1.book`, source ID **`1771014295306`**, reason
`new_raw_value_enum_or_source_shape`. Relative to the reference, `authorIds`
is newly present as an empty array and `authorsLocked` is newly present as a
boolean. These are the shape changes established for this record; other
changed field payloads are represented only by hashes, not private values.
At that attempt no interpretation, cleanup, authority change or mapper extension
was approved. The later exact Author-default disposition above supersedes only
the interpretation of its two named transitions; it does not erase this E.
Two further review-required observations remain unresolved in the full report;
they are not silently accepted or asserted equivalent.

Privacy-safe first-case detail:
`WORK/artifacts/first-final-drift-detail.json`. The generated circulation profile
records 4 open borrowed, 4 open lent-out, 0 closed and 1 contradictory case;
its gate remains `CIRCULATION_CUTOVER_REVIEW_REQUIRED`, without automatic repair
or authorization. Fresh adapter counts are 1,139 Books, 1,106 Copies, 257
Authors, 53 ReadingRounds, 17 Notes, 41 Wishlist entries, 2 Reading Goals and
9 circulation observations. These are source counts, **not accepted mapped
Item totals or a completed dry-run**.

### Privacy scope, closure and stop

A read-only check found no exact selected private strings (45 distinct strings
of at least eight characters, plus JSON-escaped forms) in six ordinary evidence
artifacts. Six shorter strings were not substring-scanned to avoid meaningless
collisions. No private body/credential was emitted. This is a scoped evidence
check, not a claim that the unexecuted FINAL dry-run passed all privacy gates.

Closure: `WORK/artifacts/dsstore-correction-closure.json`, SHA-256
`b72b77f0c0fb211089032259fbf02896c63ea6b20e9f0da39ba4ccb45e933e37`.
Independent final review verified that receipt, ten relevant sidecars, six
linked evidence files, the first-case detail, all three source observations
and the targeted-test artifact. It approved this **HOLD handoff**, not source
compatibility or cutover authorization.
The FINAL dry-run and final plan-set digest are **not produced** because PREP
requires contract review. No DB access, WordPress startup, apply/import,
rehearsal, unfreeze, commit or push occurred. No normal-DB before/after
fingerprint proof is claimed. Stop at **HOLD — CONTRACT_REVIEW_REQUIRED**.

Changed repository files for the correction:

- `docs/141-d-mig-cutover-01-final-source-and-rehearsal-contract.md`;
- `docs/142-mig-cutover-prep-01a-final-source-intake-and-drift-tooling.md`;
- this existing uncommitted `docs/144` dossier;
- Core `biblio-core.php`, `src/Plugin.php` and `tests/Unit/PluginTest.php`;
- `src/Application/Migration/Runner/NonSourcePackageMetadata.php` (new);
- `src/Application/Migration/Runner/MigrationPackageMetadataProvider.php` (new);
- `src/Application/Migration/Cutover/FinalSourceIntakeReceipt.php`;
- `src/Infrastructure/Migration/CurrentV1SourceAdapter.php`;
- `src/Infrastructure/Migration/FinalSourceIntakeService.php`; and
- `tests/Unit/Infrastructure/FinalSourcePreparationTest.php`.

Operational helpers/evidence are confined to WORK; none were committed. Earlier
failed intake and freeze records remain historical evidence, not overwritten.

## Historical initial FINAL export and intake HOLD — resolved by exact-path authorization

Renée explicitly accepted the freeze checkpoint and authorized one fresh,
complete export followed by intake, retention, drift and zero-write preparation.
The export completed once. The hardened intake failed closed before accepting
an extraction. No subsequent retention, drift, bundle or dry-run step ran.

### One established blocker

`data/.DS_Store` is present in the canonical frozen source and therefore in the
complete fresh export: **6,148 bytes**, SHA-256
`0272b10300ba8e7b4c70468d020f5d1d6f3e35075bbc9d141b53b24dcc493cb9`.
It is the only path outside the current adapter's reviewed layout allowlist.
All 20 required data files are present. `CurrentV1SourceAdapter::assertLayout`
rejects it at line 722 with `UnsupportedStructure`, before the remaining
profile/semantic checks. This is an intake-layout contract blocker, not an
already completed A–J drift classification or proof of product compatibility.

Read-only independent review confirmed the exact file, allowlist rejection,
manifest checksum and the requirement to retain Finder metadata. Nothing was
removed, filtered, repacked, silently ignored or allowed through the adapter.
The failed intake service removed only its own newly created failed extraction,
as designed; the complete archive, fresh staging copy and evidence remain.

### Created candidate package (not accepted for rehearsal)

| Binding | Value |
| --- | --- |
| Candidate Git SHA | `16e76f2d0ec32557b46b509d2a70df375c23f4cb` |
| Logical package ID | `final-current-20260923t124538449z-89b2ba3e94f9` |
| Archive | `WORK/final-source/final-v1-20260923-01/data.zip` |
| Archive bytes | `13688229` |
| Archive SHA-256 | `89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a` |
| Manifest SHA-256 | `43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480` |
| Complete source | `1830` files; `19438086` bytes; no exclusions |
| Export interval UTC | `2026-09-23T12:45:38.449Z` — `2026-09-23T12:45:39.313Z` |
| Family / adapter | `biblio-v1` / `current-v1-json-29` |
| Source version | `books-29.authors-2.reading-goals-2` |
| V1 app version | `507.0.14` |

The ZIP member hashes and file count were independently reproduced read-only
using PHP 8.3 and the accepted deterministic manifest representation. ZIP and
fresh staging manifests both equal the recorded hash. This proves archive
fidelity; it does **not** substitute for successful hardened intake.
The historical reviewed ZIP was not used as FINAL or mixed into this export.

### Live source invariance and isolation

The complete live source observation immediately after copying, before ZIP
construction, exactly equals the accepted freeze observation. A further full
read-only observation at HOLD also matches, including every protected object's
identity, owner, mode and immutable flags. Content fingerprint remains
`381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`.
No source flag was changed; V1 remains frozen. No V1 app restart or HTTP request.

Intake ran in a standalone PHP 8.3 container with `--network none`, read-only
candidate code and only WORK writable. Read-only diagnostics mounted WORK
read-only. No WordPress bootstrap, normal DB access, product/MIG-FND write,
rehearsal, apply/import, commit or push occurred. No before/after normal-DB
fingerprint proof is claimed: the FINAL dry-run was not reached.

### Evidence and unfinished work

- Complete export evidence: `WORK/artifacts/final-source-export-20260923-01/`.
- Manifest and checksum-bound export metadata are beside the new archive.
- HOLD receipt: `WORK/artifacts/final-source-intake-hold-20260923-01/receipt.json`;
  SHA-256 `4c52491a72eb0d4deea0d5079dc638b17cd309ceb1ddd0681ce3325743c14148`.
- HOLD directory includes the final source/protection observation, read-only
  ZIP/profile diagnostics and a checksum-bound evidence index; private source
  bodies and credentials are not emitted.
  Receipt `diagnostic_artifact_hashes` keys name each diagnostic helper, but
  their values hash its `.php.result.json` evidence; the helper's own SHA is
  separately recorded as `helper_sha256` inside that result.
- Actual encrypted retention copies: **not created**. Earlier synthetic
  encryption tests are not actual FINAL recovery proof. The newly drafted
  retention orchestrator is unexecuted and still requires review fixes before
  any future use; its presence is not operational acceptance.
- PREP-01A drift report, `FinalPopulationContractBundle`, final circulation
  profile, mapper totals, plan-set digest and FINAL dry-run: **not produced**.
  Further structural/semantic blockers cannot yet be ruled out.

Independent final artifact/document review confirmed all five JSON sidecars,
all eight evidence-index entries, the exact HOLD receipt hash and byte-identical
HOLD/freeze source observations. Review approves this **HOLD handoff only**.

STOP for an explicit bounded decision on the intake-layout incompatibility.
Do not change the source/archive or adapter automatically; do not unfreeze.
This is not `FINAL CURRENT SOURCE READY FOR RENÉE REVIEW` and there is no
rehearsal-approval question or authorization tuple yet.

## Accepted freeze checkpoint (history preceding the export)

## Current successful final freeze verification

### Identity and independent preconditions

- Attempt: `fv-final-20260923-01`, executed exactly once, exit 0.
- Start: `2026-09-23T09:31:35.273Z`; verified end:
  `2026-09-23T09:31:36.717Z`.
- Candidate Git: `16e76f2d0ec32557b46b509d2a70df375c23f4cb`, main; worktree dirty
  solely because this untracked documentation draft exists. No clean-worktree
  claim, implementation change, commit or push.
- Separate prerequisite check first closed FV-11 and FV-10 safely. Its receipt
  SHA-256 is `fb7635f2b4d646e5ebc26b2379030b142ea4cc43c12826e23c9c59c2dcec89c2`.
- Final execution was bound to prerequisite type, both PASS fields, checksum,
  prerequisite-script SHA and background-module SHA. It then **re-ran every
  phase itself**; preliminary receipts were not stitched into the final proof.
- Independent review approved the narrow changes and execution sequence.
  Twelve boundary tests, one fail-closed prerequisite test and four phase/FV-10
  integration checks were independently repeated successfully, all synthetic.

### Corrected FV-11 threat boundary and NordVPN

Renée supplied the original plist SHA-256 explicitly. The agent independently
rehashes the readable copy and compares it against that owner-provided original
hash: both are
`4c4ca88ff1dcd99433936a086d815c1ff13c07ed8f9a8634079337fee839d930`.
The protected original was not read or modified by the agent. Both the original
pathname and exact copy bytes bind the one readable-copy substitution; other
unknown/unreadable launch configurations still fail closed.

NordVPN classification is **NOT_AN_IDENTIFIED_V1_WRITER**: its verified complete
configuration has no explicit V1 relationship; source/process scans identify
no V1 writer or source descriptor. This does **not** mean NOT_CAPABLE_OF_WRITING
or a guarantee against arbitrary privileged code, deliberate flag removal, or
every hypothetical future root action. Neither NordVPN nor its configuration
was stopped, disabled, unloaded or altered.

The accepted boundary concerns the previously identified real V1 application,
API/admin, startup/read-normalization, background, watcher, import, provider,
local-script and cache write paths, actual source-tree descriptors and explicit
V1-related configurations. It does not require ruling out technical capability
of unrelated privileged daemons. Descriptor observations retain the stated
current-user OS-visibility limit; warnings, unknown records or concrete source
descriptors are not silently ignored.

### Same-attempt results

| Phase | Result and actual evidence |
| --- | --- |
| FV-00 PRECONDITION | PASS; roots, Git, prior evidence, copy binding and initial descriptor scan |
| FV-01 SOURCE_FINGERPRINT_BEFORE | PASS; full 1,830-file byte/hash observation |
| FV-02 PROTECTION_STATE_INVENTORY | PASS; 1,830 files, 3 canonical directories, 2 ancestors; complete per-object protection inventory |
| FV-03 ORDINARY_FILE_WRITE_DENIAL | PASS; 1,830 live nontruncating O_WRONLY probes refused |
| FV-04 TRUNCATING_WRITE_DENIAL | PASS on equivalent protected synthetic object; no live truncate |
| FV-05 CREATE_NEW_FILE_DENIAL | PASS on protected synthetic directory |
| FV-06 RENAME_MOVE_DENIAL | PASS; synthetic rename-out and atomic replacement refused |
| FV-07 DELETE_DENIAL | PASS; synthetic unlink refused |
| FV-08 DIRECTORY_MUTATION_DENIAL | PASS; synthetic mkdir/rmdir/child rename/root rename refused |
| FV-09 APPLICATION_WRITE_PATH_DENIAL | PASS; exact live writer on protected synthetic tree returns EPERM, preserved separately from phase_id=FV-09 |
| FV-10 API_OR_SERVICE_WRITE_PATH_DENIAL | PASS by accepted code-path + live filesystem protection + exact writer proof; no service listener; no live startup or HTTP request |
| FV-11 BACKGROUND_WRITER_STATUS | PASS; 18 launch configurations classified, 0 unresolved, 0 V1 configuration/process matches, empty user cron, empty source descriptor scan |
| FV-12 READ_PATH_VALIDATION | PASS; every canonical file readable; expected JSON versions parse |
| FV-13 SOURCE_FINGERPRINT_AFTER | PASS; complete fresh after observation |
| FV-14 INVARIANCE_COMPARISON | PASS; exact file/byte/hash, object identity/mode/owner, flags and build equality; historical receipts unchanged; final descriptor scan empty |
| FV-15 OVERALL_FREEZE_VERDICT | **FREEZE VERIFIED**; all 16 unique valid phase records are PASS |

All destructive-operation probes targeted only newly created synthetic objects
outside LIVE, with successful unprotected controls and matching filesystem/UID/
immutable protection. Live probes never truncate, create or write bytes. The
actual V1 application and HTTP routes were not started; FV-09/FV-10 use the
explicitly accepted indirect safe proof, not a fabricated live HTTP rejection.
Initial, background and final source-tree descriptor scans all reported zero
descriptors, zero write-capable descriptors and no warnings/errors.

### Exact prospective invariance and evidence

- Files: **1,830**; bytes: **19,438,086**.
- Before = after content SHA-256:
  `381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`.
- Protection inventory before = after:
  `74066709bc36efcdd7008fcd5d51de6fbf4a2db349cf4672edd05cb7db60639e`.
- `source-before.json` and `source-after.json` are byte-identical; artifact SHA:
  `6addc605f38ac22b88a0c03cf33a89175770ddaec24b669a368dc6f4e309ea62`.
- Prospective live-tree manifest:
  `43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`.
  This is not a created FINAL archive/package.
- Final receipt: `WORK/artifacts/fv-final-20260923-01/receipt.json`.
  SHA-256: `beb8c20241683f0c2b703bce4dba9c0e04919b5b0bd3b2eb21f72fa754697fd6`.
- Complete evidence index SHA-256:
  `a02a1d41ee410a8a84348bc6996f4bac60d517dda857fc7a8ed1c5c7b9d9a98b`.

Evidence files are exclusively created with 0400 mode in the unique 0500
attempt directory, with sidecar checksums. Previous failed/inconclusive receipts
and their original script versions remain unchanged. Success is prospective
from this completed verification, not a correction of earlier history.

Independent artifact-only final review confirmed the exact receipt, all 16
ordered/unique PASS phase IDs and their detail hashes, 29 final JSON sidecar
checksums, 5 prerequisite checksums and all 56 evidence-index entries. It also
confirmed byte-identical before/after observations and the explicit FV-09/10/11
proof boundaries. Final review verdict: **FREEZE VERIFIED**. No additional
live source or OS checks were performed during that review.

### Changed local tooling and stop state

New files under WORK/artifacts in this continuation:
`freeze-v1-background.cjs`, `freeze-v1-background.test.cjs`,
`freeze-final-prerequisites.cjs`, `freeze-prerequisite-failclosed.test.cjs`,
`verify-active-freeze-final-v3.cjs`, `freeze-phase-integration-v3.test.cjs`, and
new evidence directories `fv-final-prerequisites-20260923-01/` and
`fv-final-20260923-01/`. New synthetic probes remain under WORK/recovery-tests.
Only docs/144 changed in the candidate working tree; no production/migration
implementation changed.

**V1 frozen-state = VERIFIED; existing live flags remain active and unchanged.**
STOP. No FINAL export, intake, dry-run, database operation, rehearsal/apply/
import, commit or push. A fresh FINAL export requires Renée's next explicit
instruction. This freeze verdict is not FINAL source acceptance or rehearsal
authorization.

## Historical FV-11 copy review — superseded by threat-boundary correction

Renée supplied only the copy at
`/Users/renee/Migratie V1-V2/artifacts/fv-nordvpn-plist-review/com.nordvpn.macos.helper.plist`.
The agent used that copy exclusively for configuration inspection and did not
read the protected original. No service/configuration/permission change.

The 661-byte copy SHA-256, independently reproduced, is:
`4c4ca88ff1dcd99433936a086d815c1ff13c07ed8f9a8634079337fee839d930`.
Renée attests that the original and copy were locally hash-compared. However,
the supplied review directory initially contained only the plist, and the
referenced original-hash value/receipt was not found among the supplied
artifacts. Therefore **our original-versus-copy hash comparison is not yet
performed**; this is missing evidence, not a reported mismatch. The original
hash/receipt location has been requested without asking for credentials.

### Complete configuration findings

The copy contains exactly seven keys:

| Key | Exact finding |
| --- | --- |
| Program | `/Library/PrivilegedHelperTools/com.nordvpn.macos.helper` |
| Label | `com.nordvpn.macos.helper` |
| ProcessType | `Interactive` |
| GroupName | `nordvpn_helper` |
| KeepAlive | true |
| RunAtLoad | true |
| MachServices | `com.nordvpn.macos.helper=true` |

`ProgramArguments`, `Sockets`, `WatchPaths`, `QueueDirectories`, `StartInterval`,
`StartCalendarInterval`, `WorkingDirectory` and `RootDirectory` are absent.
No explicit V1 path, V1 arguments, V1 filesystem watcher or timed V1 job is
configured. Absence of a `Sockets` key does not deny the declared Mach IPC
endpoint or prove that the executable cannot open resources itself.

### Bounded runtime findings and classification limit

Read-only `launchctl print` still reports the exact program running as PID 533,
with keepalive/runatload properties. A restricted `ps` query confirms UID 0,
state `Ss`. The PID-only descriptor query returned exit 1, empty stdout and
empty stderr: it yielded **no process-descriptor visibility**, not proof that
this running root process has no descriptors or V1 access.

Consequently:

- No configured V1 job and no actual V1 write have been observed.
- Root privilege is not evidence of actual V1 writes, but absence of a V1
  path in the plist does not prove that the executable cannot reach or mutate
  canonical data. No unconditional `NOT_A_WRITER` is asserted.
- `NOT_RUNNING` and `DISABLED` conflict with the observed runtime state.
- `BLOCKED_BY_ACTIVE_PROTECTION` is not proven for this privileged helper;
  an immutable flag alone is not that proof.
- Complete process/capability classification remains unresolved, so the user's
  explicit uncertainty gate requires **HOLD — FREEZE NOT VERIFIED**.

Independent review confirms the copy hash, all seven configuration keys and
these evidence limits. FV-10 and the one unused final verification attempt
were NOT executed. No source fingerprint was recomputed or fabricated.

New review artifact:
`WORK/artifacts/fv-nordvpn-plist-review/review-20260923-01.json`, SHA-256
`83d373b4b2dd5abd5749ef4b11591d65a1ae0c1da18aa17734584b110e347645`,
with 0400 checksum sidecar. This records the partial classification, not a
freeze acceptance. Historical evidence and existing tools are unchanged.

Required remaining evidence: the already obtained original-hash receipt/value,
plus sufficient authorized, read-only process/descriptor evidence to assess
the helper's concrete relationship to V1. Do not stop NordVPN or broaden
permissions merely to pass the check. Do not consume the final retry while
classification remains uncertain.

## Historical preparation — bounded fix plus final-retry prerequisites

Renée authorized a narrow local verification/reporting fix, complete audit of
the one unresolved launch configuration, and **one final attempt only after all
prerequisites are satisfied**. The final attempt has NOT been invoked. The
prior attempts and original helper remain unchanged.

### FV-09 fix and targeted regression

A new helper version uses a separate phase-record module. The semantic envelope
has `phase_id`, `phase_result`, `observed_errno`, `process_exit_status` and
`evidence_sha256`; callback observations are nested under `details`, never
spread over the semantic fields. Complete, unique phase-set validation prevents
an invalid identifier being silently accepted in the overall receipt.

- Eight targeted unit checks pass, including exact coexistence of
  `phase_id=FV-09` and `observed_errno=EPERM`.
- Overwrite attempts involving EACCES, numeric exit status and exception-name
  strings cannot replace the phase identity/result.
- Four integration checks against the actual updated phase/FV-10 code pass
  with **all system calls mocked**: FV-09 survives, FV-10 recognizes its
  prerequisite, FV-00 failure still prevents callbacks, and a thrown EACCES
  remains a separate `observed_errno` in a HOLD record.
- Syntax check passes. These are local synthetic tests, NOT a new source
  verification attempt or a real API/service test.

### Exact unresolved launch configuration

| Fact | Read-only finding |
| --- | --- |
| Configuration | `/Library/LaunchDaemons/com.nordvpn.macos.helper.plist` |
| Permissions | root:wheel, 0700, 661 bytes |
| Full file read | EACCES, including outside the workspace sandbox |
| Non-interactive administrative read | `sudo -n` failed: password required; no password requested, captured or supplied |
| Loaded service | `system/com.nordvpn.macos.helper` |
| Exact executable | `/Library/PrivilegedHelperTools/com.nordvpn.macos.helper` |
| Process | PID 533, UID 0, state `Ss`; launchctl reports running |
| Observed trigger properties | keepalive, runatload; Mach endpoint `com.nordvpn.macos.helper` |
| Complete schedule/watch/argument inspection | Not proven: original plist remains unreadable |
| Canonical V1 reach/write capability | Unresolved; a root helper cannot be declared source-irrelevant solely from its name, or blocked solely from user immutable flags |
| Classification | HOLD; none of the accepted resolved writer verdicts is asserted |

`launchctl print` provides useful loaded-state evidence but does not establish
that every field of the on-disk launch configuration has been assessed. No
service was stopped/unloaded, no configuration changed, and no permissions or
source-protection flags were altered. Independent review agrees that full
classification is not yet proven and that the final retry must not start.

Required input is an owner-assisted, authorized **read-only administrative
inspection** of this exact plist, or an owner-provided exact, privately readable
copy plus source checksum. Do not change permissions on the original or send an
administrator password in chat. After that, finish the limited program/trigger/
process/descriptor assessment before deciding whether FV-11 is resolved.

### FV-10 and final retry

The formerly broken prerequisite is fixed and synthetically tested. The actual
API/service verification remains unexecuted, because the user explicitly makes
complete FV-11 classification a precondition. No live application startup or
HTTP request occurred. The proposed final attempt ID `fv-final-20260923-01` is
reserved in the new script, but its evidence directory does not exist and the
single final-attempt authorization has not been consumed.

No new source fingerprint is claimed: this preparation did not re-run the
source verification. The last verified retry fingerprint remains historical
evidence, not a fabricated fresh before/after pair.

### Exact changed/new files in this preparation

Only the local documentation below was modified. All operational files are new
under `/Users/renee/Migratie V1-V2/artifacts/`; the old helper was not patched.

1. `freeze-phase-record.cjs` — distinct semantic/error record fields.
2. `freeze-phase-record.test.cjs` — targeted namespace regression tests.
3. `freeze-phase-integration.test.cjs` and `freeze-phase-integration-v2.test.cjs`
   — mocked integration; v2 adds the independently identified thrown-errno case.
4. `verify-active-freeze-final.cjs` and `verify-active-freeze-final-v2.cjs` — new
   verifier versions; reporting-only fix, distinct proposed attempt ID and
   historical-receipt binding; NEITHER executed. v2 preserves thrown OS errno.
5. `record-final-freeze-preparation.cjs` and
   `record-final-freeze-preparation-v2.cjs` — preparation receipt writers only.
6. `fv-final-preparation-20260923-01/preparation.json` and
   `fv-final-preparation-20260923-02/preparation.json` — checksum-bound preparation
   and partial launch-audit receipts, each with its `.sha256` sidecar.
7. `docs/144-mig-cutover-final-source-01-final-current-source-acceptance.md` — this
   current status update, retaining all prior findings below.

Current preparation receipt (`...-02`) SHA-256:
`0b7a27957272b11cd15cada3967423d6bda46ec078ae759f5e6aba7b673f91dc`.
Earlier preparation (`...-01`) remains unchanged with SHA-256
`5be4680f1d3b4d31656721cce3c3c808e5ae9c47146fe50a41d9b17e039768e2`.
Versioned helper/test files preserve that earlier evidence without overwriting
its executable references. Independent review identified the thrown-errno gap
in the first fix; the v2 regression explicitly covers it.
Final independent review closed the reporting fix with no remaining findings:
8 unitchecks and 4 synthetic integration checks repeated successfully. It also
verified the current preparation checksum, sidecar and all 8 referenced tool/
historical hashes. This review does not waive the unresolved launch-audit gate.
The receipt includes hashes of every new helper/test and verifies the old
helper, first failure and retry receipt hashes unchanged. It is preparation
evidence only, not a freeze-success receipt.

### Current stop state

**HOLD — FREEZE NOT VERIFIED**. Active live flags left unchanged. No final
retry, FINAL export, intake, dry-run, database action, rehearsal/apply/import,
Dropbox access, commit or push. Implementation SHA remains
`16e76f2d0ec32557b46b509d2a70df375c23f4cb`; only docs/144 is untracked in Git.

## Historical FINAL V1 Freeze Verification Retry

### Attempt ID

`fv-retry-20260923-01`. Exactly one execution, exit 2.
Start `2026-09-23T08:43:29.093Z`; end `2026-09-23T08:43:30.840Z`.
This attempt verifies active protection only; it does not activate a new freeze.

### Prior attempt status

**FAILED / INCONCLUSIVE VERIFICATION**, unchanged. Historical evidence hashes
were checked before and after. Neither the previous attempt nor this retry is
relabelled successful.

### FV-00 Precondition

PASS. Current roots, prior evidence, fixed Git, clean descriptor scan and the
exact previously audited server hash passed. New synthetic controls succeeded
before setting immutable flags on their new scratch subtree only. Candidate
Git is dirty solely because docs/144 remains untracked; this is recorded, not
presented as a clean worktree. Independent pre-execution review gave GO for the
attempt, not advance approval of its result.

### FV-01 Source fingerprint before

PASS. 1,830 files / 19,438,086 bytes. Content SHA-256:
`381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`.
Per-file sizes/hashes and hashed path identities are in `source-before.json`.

### FV-02 Protection-state inventory

PASS. 1,830 files + 3 canonical directories + 2 protected ancestors; complete
per-object flag/identity/owner/mode inventory, not just an aggregate count.
No symlinks, special objects or hardlinked file escape. All objects are on the
same filesystem and owned by the executing user; immutable flags are active.
Inventory SHA-256:
`74066709bc36efcdd7008fcd5d51de6fbf4a2db349cf4672edd05cb7db60639e`.

### FV-03 Ordinary write denial

PASS. All 1,830 live files reject `O_WRONLY` without truncate/create/write.

### FV-04 Truncating write denial

PASS via new sacrificial object outside canonical source. Truncating open was
rejected; no truncating syscall targeted a live file.

### FV-05 Create denial

PASS via sacrificial protected directory. New-file creation rejected.

### FV-06 Rename/move denial

PASS via sacrificial objects. Rename-out and incoming atomic replacement rejected.

### FV-07 Delete denial

PASS via sacrificial object. Unlink rejected.

### FV-08 Directory mutation denial

PASS via sacrificial directories. mkdir, rmdir, child-directory rename and
protected-root rename under an unprotected parent rejected. These indirect
proofs share the live kernel, filesystem, UID and immutable protection, and are
bound to full live coverage plus live nontruncating refusal checks. All protected
synthetic bytes remained unchanged. Positive controls altered/deleted only newly
created synthetic objects, never existing evidence or canonical source.

### FV-09 Application write-path denial

The exact live `writeJsonAtomic` function, extracted without application
bootstrap, rejected a synthetic mutation with **EPERM** on the protected scratch
tree. Its positive control had succeeded. No running V1 application or HTTP
route was exercised; startup and GET normalization can write. The authorized
indirect method is actual writer + code-path audit + filesystem protection.

**Evidence defect:** the probe's error field `code: EPERM` overwrote the phase
field `code: FV-09` during object composition. `FV-09.json` therefore incorrectly
contains `code: EPERM`, and the receipt repeats it. The actual denial succeeded,
but this is NOT accepted as a valid completed phase record. Artifacts and script
are retained exactly as executed, not repaired retrospectively.

### FV-10 API/service write-path denial

HOLD / `APPLICATION_PROOF_MISSING`. The phase guard looked for FV-09, did not
find it because of the code collision, and stopped before its service-listener
check. No API/service verification is claimed.

### FV-11 Background writers

HOLD / `BACKGROUND_WRITER_UNRESOLVED`. The existing receipt records no matching
live-root process, an empty user crontab, an empty descriptor scan and no matching
launch configurations. However, **1 of 18 launch configurations could not be
fully inspected**. The receipt stores only the unresolved count, not that
configuration's identity or whether reading, conversion or symlink handling
caused it. No missing detail is invented, no configuration excluded ad hoc, and
no new environment scan was used to promote the failed attempt.

### FV-12 Read availability

PASS. All 1,830 files remain readable, with no missing file. Required JSON
inputs parse as Books29 / Authors2 / Goals2. No adapter bootstrap or export.

### FV-13 Source fingerprint after

PASS. Content SHA-256 equals the before digest exactly. Prospective live-tree
manifest remains `43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`;
this is not a FINAL archive or package designation.

### FV-14 Invariance comparison

PASS. Exact equality of file set, sizes, content hashes, aggregate digest,
inode/device identity, owner/modes, flags and build hashes. Historical receipts
unchanged. Final descriptor scan empty. Zero source-byte writes and zero live
flag changes. This establishes invariance during this retry, not freeze success.

### FV-15 Verdict

**HOLD — FREEZE NOT VERIFIED**. The requested complete, valid phase evidence
does not exist. The only authorized retry is consumed; no second execution.

### Privacy

No private payloads, credentials or raw process arguments in evidence/output.
Paths and process/configuration content are represented by hashes where needed.
New synthetic test data only; no DB operation, V2 bootstrap, provider request,
Dropbox access, intake, dry-run, apply/import or export.

### Evidence artifact / checksum

Evidence directory: `WORK/artifacts/fv-retry-20260923-01` (0500), individual
artifacts/checksums 0400, exclusively created. Append-only new attempt identity.

- `receipt.json`: `44fbaaa480c7b86d10ed1e3a4e3f29295d320bca5cbf265ff880335c5ccb0686`.
- `evidence-index.json`: `9b66a5f6b6cac7f2d5652e3c06e2d79492dd3c3a2c342f4eee84b84dd631088c`.
- Executed helper `verify-active-freeze-retry.cjs`:
  `a6070f1220809768197df17f8b28a880558b3b9304ee199bc707eacc04b1ce06`.

Independent artifact-only post-review verified all 27 JSON sidecar checksums
and 52 index references. Before/after source artifacts are byte-identical; all
1,830 ordinary-open probes returned EPERM and all three descriptor receipts are
empty. The reviewer confirms both HOLD grounds. The pre-execution review also
missed the phase-code collision; it is not treated as permission to waive it.

Any future separately authorized helper must use a noncolliding `phase_code`
versus `error_code` schema, validate uniqueness/completeness before finalizing,
and retain per-configuration hashed identity plus a closed inspection reason.
These fixes are NOT applied in this completed retry.

### Git

HEAD `16e76f2d0ec32557b46b509d2a70df375c23f4cb`, branch main. Only docs/144
untracked; no tracked/staged implementation change. No commit or push.

### V1 frozen-state

**WRITE FLAGS ACTIVE — FREEZE NOT VERIFIED**. Existing flags remain unchanged.
Stop. No FINAL export/package created; no automatic continuation or thaw.

## Historical result — reference restored; first freeze attempt retained as HOLD

The authoritative roots remain LIVE `/Users/renee/Biblio V1 tbv migratie` and
WORK `/Users/renee/Migratie V1-V2`. No obsolete LIVE/WORK path was accessed in
this continuation. Earlier sections below are preserved forensic history.

### Verified pre-freeze gates

- `WORK/reference/data.zip` exactly matches required SHA-256
  `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`.
- Independent safe extraction reproduced reviewed manifest
  `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`
  across 3,587 files. This ZIP/extraction is **DRIFT REFERENCE ONLY**, never FINAL.
- Both synthetic encrypted retention probes were independently remounted
  read-only using their separate login-keychain items; byte comparison passed,
  then both were detached. No password was emitted. Real-source retention
  copies have NOT been made.
- Independent review cleared the operational freeze helper after closed-error
  reporting, cross-mount descriptor scanning and same-device checks. This
  review was permission to attempt the procedure, not proof it would succeed.

### Freeze attempt and read-only diagnosis

`activate-freeze.cjs` was invoked once. Its guarded sequence passed prechecks
and reached the mutation boundary, then exited 1 with a failure receipt at
2026-09-23T08:29:13.499Z. No `freeze-evidence.json` or immediate-after success
receipt was created. The recorded error is deliberately privacy-safe but too
coarse to identify the failing verification stage retrospectively.

Subsequent **read-only** checks established:

- All 1,835 checked objects (LIVE/app ancestors plus canonical data tree) have
  `UF_IMMUTABLE` / `uchg` set. They had no immutable flag in immediate-before.
- All 1,830 files / 19,438,086 bytes remain exactly equal to immediate-before,
  as do inode/device/mode identities and application build hashes.
- Deterministic source-content digest:
  `381737cd9d880d973c948f7b6d7389b7b6073055786bea1f21a975b7d2cedc04`.
- Prospective package-manifest digest of the live tree, **not a FINAL export**:
  `43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`.
- Nontruncating `O_WRONLY` open probes were refused for all 1,830 files;
  unexpected opens and other probe errors were both zero. No bytes were written.
- One subsequent descriptor scan returned exit 1 with 27 stdout bytes and no
  stderr. Its fields were not retained. Later direct and wrapper scans were
  empty with exit 1. A transient descriptor is a hypothesis, **not a proven
  explanation** of the original failure. Full stat/flag inspection also passed.

These facts prove current flags and byte equality, but do not prove that the
original required descriptor-free interval existed. The failed attempt is NOT
retroactively accepted. Independent review agrees with HOLD. No retry, flag
clearing, application startup, HTTP request, export or intake followed.

### Checksum-bound evidence under WORK/artifacts

| Artifact | SHA-256 |
| --- | --- |
| `reference-recovery.json` | `bd04a8f0d0f611a43d32e5501c7b60eed04797fdfb69eef6419b293d9e6bfd7e` |
| `activate-freeze.cjs` | `c7e54c1f5a5c85485e63d615470f5134109d6e1fad0a3da89efe414c5baccfae` |
| `freeze-failure.json` | `5b69c3cde121b4c43df56f03464f20f3d887fc6c9e50b57851472f7eacc83ffa` |
| `freeze-hold-observation.json` | `8f397a812fec4ebf93a14385b0a42fa921102022a5b3b9d0fbcbdaa8d47d4eef` |
| `freeze-hold-diagnostics.json` | `bbf5b59c9ab0ea51973262dce0d168a37aa79546ba4a25656732e5f28c258ce4` |

The observation/failure/diagnosis artifacts are exclusive-created, mode 0400,
with sidecar checksums. Original attempts remain intact. Helpers are local
operational orchestration outside the Git candidate, not modified PREP/mapper
implementation. Ordinary evidence contains counts, identities and hashes only.

### Current closure coverage (supersedes historical sections 1–40 below)

| Required topic | Current status |
| --- | --- |
| 1. Code/runtime | main, HEAD `16e76f2d0ec32557b46b509d2a70df375c23f4cb`; Product v2.001, schema 1026, Core 2.51.0, UI 0.20.0; standalone PHP 8.3.31 available but no FINAL intake run |
| 2–4. Live location / canonical source / writers | Confirmed current roots and file-based `Biblio/data`; app 507.0.14; UI/API/startup/read-normalization/CLI/cache writers audited |
| 5–6. Freeze procedure / verification | One attempt, flags active, verification HOLD; no successful freeze receipt |
| 7–9. Export / package / manifest | No export or FINAL package; only live-tree diagnostic digest exists |
| 10–11. Retention / recovery | Two independent synthetic encrypted recovery tests pass; no actual FINAL copies |
| 12–17. Intake / drift / domains / compatibility / bundle / digest | Not run; no FINAL identity to bind them |
| 18–29. Erroneous Copies / classification / Authors / Series / contained / Wishlist / Reading / assessment / Goals / circulation / quarantine / inventory | FINAL populations not computed; no old counts substituted |
| 30–33. Prepared plan / dry-run / integrity / preservation | Not run; no plan-set digest or source approval |
| 34. Privacy | No source bodies, passwords or private values emitted; source and operational evidence remain outside Git |
| 35. Normal DB | No DB connection or WordPress bootstrap; no normal-DB mutation; no historical invariance claim |
| 36. Retained frozen state | Immutable flags remain active; do not describe unsuccessful verification as verified freeze |
| 37–38. Verdict / rehearsal tuple | HOLD — CONTRACT_REVIEW_REQUIRED; no valid rehearsal-binding tuple |
| 39. Owner decision | Authorize a separate instrumented verification attempt while retaining flags, or formally abort and explicitly authorize reopening; neither happens automatically |
| 40. Production / Git | No apply/import, rehearsal, commit or push; only docs/144 is untracked; tracked/staged diff empty; local main 78 ahead / 0 behind origin/main, 2 ahead / 0 behind safety branch |

### Bounded proposed continuation — NOT executed

Keep the existing failed attempt immutable. If explicitly authorized, prepare
a separate append-only verification attempt with closed stage codes and
privacy-safe descriptor evidence (PID, descriptor mode/type and hashed relative
path identity), independently review it, and recheck writers, flags and exact
source bytes. Do not blindly rerun activation, clear flags or accept later empty
scans as proof of the failed historical interval. No candidate-code fix or
semantic exception is requested. No export until a new complete gate succeeds.

## Historical pre-activation checkpoint — 2026-09-23

The following checkpoint predates the freeze attempt above. Its remaining-input
blocker and statements that no live flags changed are superseded, not current.

Use ONLY these roots for the resumed operation:

- LIVE: `/Users/renee/Biblio V1 tbv migratie`.
- WORK: `/Users/renee/Migratie V1-V2`.

All older Documents/Dropbox live/work locations below are obsolete history.
They were not accessed during this resume. Renée still accepts
`two_local_encrypted_copies`, `independent_hardware_failure_domains=false`,
`residual_risk_accepted_by_owner=true`. She explicitly approved two separate
random passwords stored as identifiable items in her macOS login keychain.

### Verified current pre-freeze audit

- The application is `LIVE/Biblio`, package 507.0.14. Canonical persistence is
  `LIVE/Biblio/data`, fixed by current `server.js:131–214`. The ZIP next to the
  application is NOT nominated as FINAL or used as migration input.
- Current-root code independently re-reviewed: UI/API, CLI tools, startup,
  normalization-on-read, release batches and cache writes all require the
  barrier. The built-in 17-dataset backup is not a complete raw export.
- No V1 Node/npm server or port-3000 listener was found; no open files were
  reported by the current application-tree lsof scan. The user-cron check
  reported no active entries. These are observations, not an active freeze.
- Full current-source observations before/after this audit agree exactly on
  file bytes, inode/device identity, flags and build hashes. 1,830 files,
  19,438,086 bytes; Books29 / Authors2 / Goals2. Counts remain PRE-FREEZE only:
  1,139 Books, 1,106 Copies, 41 WishlistItems, 257 Authors, 2 Goals.
- `artifacts/pre-freeze-observation.json` SHA-256:
  `fe9185dbf2e928e315db64fb41889db676d948ed3c8171bd7211c7d7973352e2`.
- `artifacts/post-preflight-observation.json` SHA-256:
  `effce697f2a49c9190210d4ff003baff77de03d8cca974d8f1935f2512e6a57f`.
- Pure filesystem operational helpers and a clean tooling clone live under
  WORK, not in the candidate repository. Candidate SHA remains
  `16e76f2d0ec32557b46b509d2a70df375c23f4cb`. Standalone PHP must explicitly use
  `/usr/bin/php8.3` (8.3.31); the image's default alias is 8.4 and was only
  inspected, not used for acceptance. No WordPress or DB bootstrap occurred.

### Tested barrier and key custody

A disposable scratch test exposed that `uchg` does NOT revoke an already-open
write descriptor. The procedure therefore requires complete writer drain and
open-descriptor/mapping checks immediately before AND after flagging. Under
that condition the refined test passed new write-open, create, atomic replace,
directory rename and unlink refusal, while preserving reads and bytes.
The owner can deliberately clear flags; no adversarial-owner guarantee is made.
Independent review conditionally accepts drain + flags, not flags alone.
Live refusal tests must not truncate or create/rename source records.

`artifacts/freeze-mechanism-proof.json` SHA-256:
`e5797f7c5ff75bb6f564d729ace93ec58443d34c9cf29dff568dd465ba23cd9f`.
All flag changes so far affected newly generated scratch data only and were
reversed there after tests. No live file or directory flag was changed.

Native AES-256 hdiutil encryption was tested independently for both retention
accounts on synthetic text only. Each separate process recovered its password
from the login keychain, mounted read-only, reproduced the exact input and
detached. The two owner-approved keychain items use service
`Biblio FINAL SOURCE retention 20260923`, accounts `primary` and `recovery`.
No password/key is in argv, artifacts, logs, docs or Git. Preserve these items
while source recovery may be needed. Synthetic probes are NOT FINAL copies.

### Remaining blocking pre-freeze input

The separately designated historical drift reference
`/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip`
cannot be read: checksum verification returned `Operation not permitted` even
with sandbox escalation. This is not one of the prohibited old LIVE/WORK roots;
it was accessed solely to verify the expressly retained DRIFT REFERENCE.

Renée is asked to make that exact reviewed ZIP accessible as
`/Users/renee/Migratie V1-V2/reference/data.zip`. Required SHA-256 remains
`835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`.
Verify bytes and the reviewed manifest before continuing. It can never replace
the fresh freeze-bound FINAL export. No alternative source was substituted.

**Current verdict: HOLD — CONTRACT_REVIEW_REQUIRED (operational access gate).**
V1 is **NOT FROZEN**. No FINAL export/archive, retention copy of real data,
intake/drift, final population bundle, dry-run or rehearsal-binding tuple exists.
No normal-DB access, production/rehearsal apply, broad tests, commit or push.
Once the reference is accessible, repeat the immediate writer/source checks
before activating the previously tested barrier. Do not reuse a pre-freeze
observation as immutable source authority.

## Superseded HOLD resolution — earlier 2026-09-23

Renée superseded the previous source/storage instructions:

- Sole designated live starting point:
  `/Users/renee/Documents/Biblio V1 tbv migratie`.
- Migration working root:
  `/Users/renee/Documents/Websites/Migratie V1-V2`.
- Required logical separation: staging, final-source, intake, artifacts,
  retention-primary, retention-recovery and recovery-tests.
- Dropbox is entirely outside this cutover's scope: do not inspect, read,
  compare, export to, or retain packages there.
- The old reviewed archive remains only a drift reference, not FINAL source.

The explicit, one-cutover exception to docs/141 §7 is:

```text
retention_policy = two_local_encrypted_copies
independent_hardware_failure_domains = false
residual_risk_accepted_by_owner = true
```

Both encrypted local copies must independently decrypt/read back, reproduce
the same archive bytes/hash and extracted manifest, and prove restricted
locator/hash recovery. Same-device loss risk is accepted; recovery verification,
encryption, privacy, hard freeze and explicit FINAL approval are not waived.
No cloud/NAS/external disk is required. Keys/passwords must not enter Git,
documentation, artifacts or captured output. Concrete encryption/key-custody
mechanism has not yet been selected or exercised.

### Current read-only resume result

HEAD remains `16e76f2d0ec32557b46b509d2a70df375c23f4cb`; the only untracked
Git candidate is this local documentation draft. Neither code nor DB was changed.

Metadata inspection could identify both named directories, but listing their
contents returned `Operation not permitted`. A bounded retry with sandbox
escalation returned the same error for both directories. This is not evidence
that the directories are empty, that a data root has been identified, or that
the prior application's version/layout applies to the new location. The exact
macOS access-control cause has not been established.

No source contents were read from the new location. No writes to either new
location were attempted. No Dropbox location was accessed during this resume.
No freeze, export, retention encryption, intake, drift comparison, plan/dry-run,
normal-DB access, rehearsal, commit or push was performed.

**V1 remains NOT FROZEN.** No FINAL package or rehearsal-binding tuple exists.
Current verdict: **HOLD — CONTRACT_REVIEW_REQUIRED**, operational access gate.

Required next action: grant the executing agent/process access to the two
explicitly designated directories (read access to the live source, permission
for the later authorized freeze, and read/write access to the migration work
root), then resume at the pre-freeze audit. Do not move/fall back to a different
source, treat old audit results as current, or bypass the access controls.

## Historical audit — 2026-09-22 (superseded, not current source evidence)

The sections below are retained forensic history only. Their old source
location, process IDs, counts, fingerprints, runtime and retention blocker do
NOT describe or authorize the newly designated live source. The storage
question in historical §39 has been answered by the explicit exception above.
Do not use these old observations in the resumed FINAL acceptance.

## 1. Code/runtime baseline

Candidate HEAD `16e76f2d0ec32557b46b509d2a70df375c23f4cb`, branch main,
initially clean: no staged, unstaged or untracked changes. Locally recorded
origin/main comparison: 78 ahead, 0 behind. Remote-tracking
`origin/wip/shared-search-rebuild` equals HEAD; the separate local branch is
still `8973b35d52f65cef0a9febff2b217303934c9698`. No fetch/push performed.
Product v2.001, Core 2.51.0, UI 0.20.0; schema 1026 is the accepted PREP-01B
closure baseline, not a newly queried normal database result. PREP-01B external
closure checksum index revalidated. Its committed precommit wording is not
mistaken for the later external exact-SHA evidence. The external Current Phase
summary is older than these accepted repository contracts.

## 2. Live V1 location

The user-designated Biblio_1 installation contains a `Biblio` Node application.
The process listening on TCP port 3000 was PID 87029, child of `npm start`
PID 87010, started 2026-09-22 19:28:03 local time. Its working directory is the
designated Biblio application directory, verified with lsof. Runtime executable
reports Node v26.9.0; package.json version is 507.0.14. No application-local
`.git` exists; Git traversal resolves to an ancestor repository and is not a
V1 build revision. File hashes bind the observed build instead.

## 3. Canonical V1 source identified

`server.js:131` sets DATA_DIR to `path.join(__dirname, 'data')`; this is not an
environment-selected or current-working-directory database. `books.json`
contains Books, Copies and WishlistItems. Additional JSON stores and generated
subdirectories live under that data root (`server.js:132–214`). No separate
database is identified in the audited persistence path.

Privacy-safe read-only observation: 1,830 files / 19,438,086 bytes, identical
across two immediate complete reads. Observation-specific sorted content digest:
`218c69390552977827ebbda59b6042538b13a93170f4cf479c100d1062bb884b`.
This is NOT a PREP-01A manifest, freeze proof or FINAL package designation.
Observed shapes: Books schema29, Authors2, Reading Goals2; 1,139 Books,
1,106 Copies, 41 WishlistItems, 257 Authors and 2 Goals. These are pre-freeze
observations only, never final acceptance totals.

## 4. Write paths

Independent code review identified ordinary UI/API writes for Books/Copies,
Wishlist, Reading Goals, preferences, next-to-read, Authors/taxonomy, enrichment,
releases and backup restore. CLI migrations/backfills/rebuild tools are separate
writers. The ordinary atomic writer creates a sibling temporary file and
renames it over the destination (`server.js:4107–4123`); chmod on JSON files
alone therefore cannot establish a freeze.

GET is not a read-only boundary: readBookStore can migrate/save (`1946–1950`),
Goals normalize/save (`2515–2516`), recommendations markShown persists preferences
(`9620–9645`), and covers writes cache (`6647–6657`). Startup prunes Genres and
may save Books before listening (`12431–12459`). No application restart or HTTP
probe was performed. Browser-driven release batches are also writers.

No matching local LaunchAgent/Daemon configuration was identified in accessible
locations; the user-cron check reported zero active lines. This does not prove
absence of every external scheduler. Dropbox processes are running; neither
their presence nor timestamps establish sync consistency or source authority.

## 5. Freeze procedure

NOT ACTIVATED. Before the freeze, the required primary/recovery retention
destinations, custodians and distinct failure domains must be identified.
Those are missing operational inputs under docs/141 §7 and this task's
pre-freeze gate. Do not invent them or count two folders on the same disk.

The eventual reversible write barrier must cover in-flight Node writes,
browser/API batches, startup/CLI tools, directory replacement and Dropbox.
Stopping/pausing identified writers plus a verified OS-enforced data/directory
barrier is a candidate procedure, not yet verified or executed. Dropbox pause
alone and read-only file modes alone are insufficient. No process was stopped,
no source permission/flag changed and no sync state changed in this attempt.

## 6. Freeze verification

Not run. No refusal tests against live data, no drain proof and no operational
freeze timestamp exist. Two equal reads are explicitly not a write barrier.

## 7. Final export procedure

No export performed. The built-in GET `/api/admin/backup/export` uses 17
selected dataset readers (`server.js:6232–6418`, `6468–6474`, `9729`). It is
sequential, reserializes/projects Books, omits files including releases/cache/
cover-cache/reports, and readers can normalize/write. It is not suitable for
the required complete raw FINAL export.

For this file-backed authority, docs/141 §4 permits byte-preserving capture of
the complete frozen `data/` tree into new external staging. That includes
metadata/cache files unless an accepted contract explicitly excludes them;
none is excluded in this audit. Before/after full content inventories and an
independent archive extraction must agree. No live directory or old baseline
may be used as staging or nominated directly as FINAL.

## 8. Final package identity

Absent. No new archive, package ID or export timestamp exists.

## 9. Manifest

Absent. The read-only observation digest is not the accepted intake manifest.

## 10. Retained copies

Not established. Docs/141 requires two encrypted, access-restricted stores on
different failure domains. FileVault is on for the local Mac; this alone does
not provide independent recovery. Dropbox's running state proves neither a
verified retained remote copy nor its protection/access policy. Renée has been
asked to identify the intended stores without supplying secrets.

## 11. Recovery proof

Not run: no final archive or verified storage destinations exist.

## 12. PREP-01A intake

Not run; no old ZIP reused. Accepted `prepare` tooling exposes intake/drift,
not a live application freeze/export mode. No tooling changes were made.

## 13. Drift summary

Not evaluated. The older reviewed ZIP remains DRIFT REFERENCE only.

## 14. Domain drift

All requested domains remain pending a freeze-bound FINAL package. Pre-freeze
record counts above do not classify A–J drift or establish compatibility.

## 15. Mapper-contract compatibility

Not evaluated. No new semantics approved or inferred.

## 16. Final population bundle

Not generated.

## 17. Bundle digest

Absent.

## 18. Erroneous Copy set

Not regenerated or approved.

## 19. Classification

Final population not evaluated; no fallback mapping introduced.

## 20. Authors

Final contributor/exception population not evaluated; no name-based repair.

## 21. Series

Final exact-byte identity groups not evaluated or approved.

## 22. Contained works

Final parent/slot vectors not evaluated or rebound.

## 23. Wishlist

Final shape/contract compatibility not evaluated.

## 24. Reading

Final rounds/truth/lifecycle compatibility not evaluated.

## 25. Notes/assessments

Final private content/slot comparison not performed. No bodies emitted.

## 26. Reading Goals

Observed source version2 only; final disposition validation remains pending.
The accepted preserved_deferred contract is not changed.

## 27. Circulation profile

Not generated. No conflict or open-circulation count inherited from reference;
no circulation cutover design started.

## 28. Quarantine candidates

Not generated or approved.

## 29. Source-type inventory

Final adapter inventory not executed. Zero unsupported types is NOT claimed.

## 30. Final prepared plan

Not prepared. No target provisioning or product/MIG-FND writes.

## 31. Final dry-run

Not run. No planning-error/unmatched-reference zero claim.

## 32. Integrity previews

Pending final package, contract bundle and zero-write preparation.

## 33. Preservation recovery

Not exercised against FINAL; no retained FINAL source exists.

## 34. Privacy

Only read-only counts, shape metadata and hashes were captured, in restricted
ignored local evidence. No source body, credentials or environment file contents
were emitted; no source package/extraction was created or Git-tracked. Full
local reads succeeded; no dataless/offline flags or conflict-named files were
found. These checks do not prove remote Dropbox conflict/queue absence.

## 35. Normal DB zero-impact

No normal-DB connection, WordPress bootstrap or HTTP request was made by this
slice. No baseline replacement or new equality claim. No rehearsal database
or migration apply was used. Broad suites were not rerun.

## 36. Retained V1 frozen-state

V1 IS NOT FROZEN. The live server and Dropbox were left as found. No freeze
was activated and subsequently reopened. A later authorized continuation must
capture a fresh pre-freeze observation; this audit is not a frozen source.

## 37. Final-source verdict

**HOLD — CONTRACT_REVIEW_REQUIRED**.
The blocking input is operational retention configuration, not a newly found
source-semantic defect. No FINAL CURRENT SOURCE is ready for review.

## 38. Rehearsal-binding tuple

Only candidate Git SHA is fixed. Package/archive/manifest/bundle/plan digests,
quarantine candidates and final circulation status are absent. No
`approved_for_rehearsal` designation.

## 39. Decision required from Renée

Identify the two intended encrypted, restricted retention destinations on
different failure domains (primary and recovery), including custodian/access
arrangement without passwords/keys. Do not authorize source approval yet.
After these pre-freeze inputs are settled, resume the bounded freeze validation;
do not bypass it or treat this read-only observation as FINAL.

## 40. Production apply gate / Git

PRODUCTION APPLY NOT AUTHORIZED. No rehearsal apply, import, fault injection,
resume/replay or rollback. No executable code changed; candidate HEAD unchanged.
Only this local draft documentation is a new Git candidate; no commit or push.
Evidence directory: `.local/final-source-01-preflight-20260922/`.
