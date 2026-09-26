# POST-CUTOVER-FIX-01 — bounded classification and Item repair

Status: owner-authorized implementation candidate; production outcome and exact
candidate/backup/checksums are retained in private external execution evidence.
Severity: High; primary implementation and independent review are separate.

The successful production run `migration-run-4058ab2b14dec369eeec6b6214e8ce00`
has 1,142 Works, 1,120 Editions and 734 Items. This repair never reopens that run
or applies the full FINAL package again. It targets exactly 342 blocked Copies,
341 source Books and 340 Work/Edition pairs in the accepted FINAL manifest
`43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`.
The exact member/mapping/payload roster digest is
`f91c174f527d6eadb192309781b94cd3247ef58f6538eb127ec454b916349867`.

## Owner decisions

The four ordered raw shapes have 309, 31, 1 and 1 Copies. Shapes A/B/C receive
Leesboek; D receives Kookboek. Raw Categories and the unsupported Genre remain
source evidence only. No new Genre/Subject or taxonomy term is authorized.
The expansion produces exact Book-ID/payload-bound approvals through the
existing classification contract; no other review/no_signal Book is approved.

The initial exact expansion exposed seven existing converged classification
conflicts and yielded only 335 Item plans. Renée subsequently explicitly
approved **only these seven exact pairs** on 2026-09-26. For each pair both
Book IDs and payload hashes bind one selection: Leesboek, no Genre/Subject.
Each conflicting peer has only an already excluded erroneous Copy; all seven
Works had no existing Library catalog context. Peer source classification
evidence remains intact. This is the explicit group decision required by
docs/118, not a general priority for representatives or eligible Copies.
The seven-group digest is
`48fcb27c323435fcd8dc90f56f78714c5db73ad5da4a0dd9ec3f81ea8b7e8a66`.

## Execution boundaries

The standalone `scripts/post-cutover-classification-repair.php` supports only
prepare and exact-packet-confirmed apply for this fixed source/population and
User 227's explicit personal Library. Preparation verifies accepted source,
original closed run, raw Book/Copy payloads, ordered shapes, old typed
Work/Edition plan hashes, exact targets, existing context compatibility and
absence of prior Item mappings. Full inspection is read in memory so existing
Copy exclusions and convergence checks remain effective. Only the resulting
342 catalog_item records may execute.

The existing Item participant, Core writer, Begin/Observe/Commit/Lifecycle
services create a distinct repair run with `post-cutover-fix-01` provenance.
No catalog_work/catalog_edition or unrelated preservation record executes.
Item identity stays Copy-bound even when Work/Edition converge. A narrow
reconciliation capability exposes the completed run's same-source/same-owner
Edition dependencies only to existing target inspection; accounting still
contains only repair Items. Ordinary reconciliation is unchanged.

Before writes a new full production backup must be independently restored and
fingerprinted. Existing POST_APPLY backup transport supports the already
migrated nonempty state; its binding explicitly says PRE-CLASSIFICATION-REPAIR.
Never use the empty PRE-CUTOVER backup. All-table PRE equality, clean candidate,
audited production configuration, operation lock, maintenance, server write
block and transaction-drain barrier remain mandatory. Execution cannot retry.
Any material failure preserves evidence, keeps writes blocked, restores the
new PRE-repair backup with existing native transport and requires exact full
fingerprint equality. Evidence-I/O failure must not skip attempted rollback.
Successful execution leaves access blocked for separate inspected release.

The first execution rolled back exactly after a verifier defect: the example
check required an Item for every Copy sharing a title, including an accepted
erroneous sibling Copy. Renée explicitly authorized a verifier-only correction
and a new execution. Classification decisions, eligibility and write semantics
are unchanged. The accepted PRE-repair backup is reused only after exact live
fingerprint equality and checksum/readability validation; changed data is HOLD.
Prior attempt evidence is retained and the authorized attempt has a new packet
and evidence directory. This does not permit automatic retries after failure.

## Verification and unchanged populations

Tests cover exact shape expansion, unsupported taxonomy, outside members,
changed payload/order, replaced members, exact group decisions and extra/stale
peers. Native integration proves separate Copies use the same prior Edition
without new Works/Editions, preserves old observations and verifies scoped
dependency inspection. The full Core gate runs once on the final candidate in
the existing isolated DDEV test project before production mutation.

`ItemRepairVerifier` derives required outcomes exclusively from the exact
approved Copy records admitted by the existing Item planner. It receives the
uncollapsed ledger mappings and authenticated Item-to-Work catalog projection,
requires exactly one distinct new visible Item per eligible Copy, rejects
unapproved/excluded mappings and forbids prior Items for erroneous Copies.
A Book with an eligible and an excluded Copy is visible through the eligible
Item; its excluded sibling remains without an Item. Example titles only check
user-facing visibility. They never select additional required Copies.
Focused regressions cover missing/duplicate/wrong-Work/invisible/reused Items,
mixed Copy dispositions and unchanged non-Item terminal populations.

Postverification requires 342 unique new Item identities, exact typed target
reconciliation, unchanged pre-existing rows and all unrelated table/schema/
trigger/database metadata fingerprints. All catalog pages are read through
the authenticated application service as User 227. Nora Roberts is additionally
reconciled by exact source Book IDs: 62 source Books, 37 previously visible,
22 newly visible and three unchanged erroneous exclusions. Five named examples
and MacKade's Author/Series/position are checked through application reads.

The 23 erroneous Copies, four external-borrowed cases, ambiguous circulation,
two CAT-invalid ISBN Books, 39 Wishlist-only Books and all old preservation/
quarantine evidence retain their original dispositions. Onderstromen may get
an Item but no operational loan; the manual register remains authoritative.

No covers, Book Detail redesign, Authors/Series pages, Reading History, loan
promotion, V1 unfreeze, backup deletion, push or merge is included. Functional
user QA remains separate. Schema stays 1026 and UI 0.20.0; Core is 2.51.2.
