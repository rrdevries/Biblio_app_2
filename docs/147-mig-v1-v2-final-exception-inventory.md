# 147 — MIG-CLOSE-01: final V1 → V2 exception inventory and closure

Status: **V1 → V2 PRODUCTION MIGRATION COMPLETE** (2026-09-26).
Migration program blockers remaining: **NONE**. This is a closure record,
not authority to rerun the migration or change source/product records.

## Evidence and accepted result

- FINAL package `final-current-20260923t124538449z-89b2ba3e94f9`:
  archive SHA-256 `89b2ba3e94f916d45e63f5aaa3595afac6241473f89d11eb062a165f053c8d9a`;
  manifest SHA-256 `43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480`.
- Original production run `migration-run-4058ab2b14dec369eeec6b6214e8ce00`:
  **5,847 mapped / 828 preserved_deferred / 1 quarantined / 0 planning errors**.
  Its exact report is retained in `.local/production-apply-DzxsWbkL/REPORT.md`.
- POST-CUTOVER-FIX-01 separately materialized 342 Items; its accepted report
  is `.local/post-cutover-fix-01-rerun/REPORT.md`.
- Read-only live SQL on 2026-09-26 confirmed **1,142 Works, 1,120 Editions,
  1,076 Items and 1,070 distinct catalog Works with an Item**. The last
  measure is also the accepted authenticated visible-catalog result in the
  repair report; direct SQL counts do not replace authorization checks.
- Source rows below come from the FINAL extracted `data/books.json` and the
  exact eight-row register. Product target existence and Item linkage were
  checked read-only against the production migration ledger and V2 tables.
  Titles are joined by exact V1 Book ID, never by fuzzy title matching.

## 1. Erroneous legacy Copy exclusions (23)

All 23 exact Copy observations have ledger reason
`erroneous_legacy_copy_not_carried_forward_v2`, disposition
`preserved_deferred`, and **no Item for the excluded Copy**. All 23 parent
Books have a V2 Work and Edition mapping. “Other Item” refers to a valid
Item on that exact mapped Edition; it never promotes the excluded Copy.
The source archive reasons are 20 `duplicate_correction`, 2
`incorrectly_registered` and 1 `wishlist_correction`.

| V1 Book ID | V1 Copy ID | Exact FINAL title | FINAL author evidence | Original archive reason | Work / Edition | Other valid Copy / Item on mapped Edition | Follow-up |
|---|---|---|---|---|---|---|---|
| 1770132685978 | copy-1770132685978-extra-h9lics | Het borrelhapjes bakboek | Laura Kieft | duplicate_correction | Both exist | Yes; same Book has a valid Copy and Edition has an Item | None; optional human inspection |
| 1770933193144 | copy-1770933193144-01-y1epi2 | Betrapt | Elizabeth George | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770933209550 | copy-1770933209550-01-50pe80 | Het graf | Not supplied | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770933500943 | copy-1770933500943-01-u4wrne | Carnal innocence | Nora Roberts | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770933964782 | copy-1770933964782-01-jkm4gc | The Pelican Brief | John Grisham | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934366206 | copy-1770934366206-01-9rcfl8 | Wit'ch Fire (The Banned and the Banished, Book 1) | James Clemens | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934417006 | copy-1770934417006-01-wx4ykf | Sullivan's Woman | Nora Roberts | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934446089 | copy-1770934446089-01-utrjhc | Avenger | Frederick Forsyth | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934733290 | copy-1770934733290-01-acj7mu | The Golden Buddha | Craig Dirgo | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934745810 | copy-1770934745810-01-f2dayx | Killing me softly | Nicci French | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934847989 | copy-1770934847989-01-e2v7cu | The revelation | Bently Little | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934920693 | copy-1770934920693-01-ewvrgd | Irish hearts | Not supplied | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1770934958001 | copy-1770934958001-01-fo8t1q | Golden Bats and Pink Pigeons | Gerald Malcolm Durrell | duplicate_correction | Both exist | No Item on this Edition in current V2 | None; optional human inspection |
| 1770935647569 | copy-1770935647569-01-es56ih | Het jaar waarin ik mijn haar verloor | Barbara van Beukering | duplicate_correction | Both exist | No Item on this Edition in current V2 | None; optional human inspection |
| 1770936121118 | copy-1770936121118-01-saygms | Het achterhuis | Anne Frank | duplicate_correction | Both exist | No Item on this Edition in current V2 | None; optional human inspection |
| 1771063725794 | copy-1771063725794-01-ljtxtd | Divide & conquer | Not supplied | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1771063770682 | copy-1771063770682-01-e50za4 | Hyperion | Dan Simmons | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1771064428453 | copy-1771064428453-01-ne32dd | Washington goes to war | David Brinkley | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1771265560621 | copy-1771265560621-extra-kzph0b | Wat wij zagen | Hanna Marleen Bervoets | duplicate_correction | Both exist | Yes; same Book has a valid Copy and Edition has an Item | None; optional human inspection |
| 1771336523170 | copy-1771336523170-01-bfn8p5 | The Waste Lands (The Dark Tower, Book 3) | Stephen King | duplicate_correction | Both exist | Yes; another source Copy has an Item on this Edition | None; optional human inspection |
| 1786129044856 | copy-1786129044856-01-pfdtbk | The secret garden | Frances Hodgson Burnett | incorrectly_registered | Both exist | No Item on this Edition in current V2 | None; optional human inspection |
| 1786710970785 | copy-1786710970785-01-lr6pja | Lisa Jewell 24 | Lisa Jewell | wishlist_correction | Both exist | No Item on this Edition in current V2 | None; optional human inspection |
| 1787945777590 | copy-1787945777590-01-ebymmj | Final Target | Nora Roberts | incorrectly_registered | Both exist | No Item on this Edition in current V2 | None; optional human inspection |

## 2. Invalid-ISBN CAT quarantines (2)

The FINAL source and accepted CAT contract identify these as invalid-only
ISBN Books. Neither produced a Work, Edition or Item. Their exact raw ISBN
strings are retained here for optional human cleanup during normal
development; no ISBN was corrected or inferred.

| V1 Book ID | Exact FINAL title | Raw `isbn` / `isbn13` | Reason | Product result | Future action |
|---|---|---|---|---|---|
| 1773342212691 | Girl, Forgotten | `9780008303616` / `9780008303616` | Invalid-only ISBN; CAT `invalid_isbn` | No Work; no Edition; no Item | Optional human cleanup |
| 1788428336633 | Heksen | `9789026112815` / `9789026112815` | Invalid-only ISBN; CAT `invalid_isbn` | No Work; no Edition; no Item | Optional human cleanup |

## 3. Ambiguous circulation quarantine (1)

- Circulation: `circulation-1780741593722-1crrv`; exact title: *Tyler Green komt nooit meer vrij*.
- V1 Book `1780741573994`; V1 Copy `copy-1780741573994-01-styu83`.
- Book-level state: borrowed, open; start `2026-06-05`
  (day); end unknown/null.
- Copy-level state: borrowed, closed; same start; end `2026-08-23`
  (day). Dates state calendar-day precision, not an exact clock time.
- Reason: `ambiguous_circulation_semantics`; final result: quarantined,
  with no invented resolution or operational V2 loan.
- Future action: human review when circulation functionality is developed.

## 4. Open legacy circulation relationships (8)

The unchanged external register has exactly eight open rows: four
`borrowed` and four `lent_out`. The CSV itself has blank `v2_item_id`
fields; the four Item IDs below are confirmed in the **current V2 ledger**
after classification repair. No private counterparty text is reproduced.
All eight remain `preserved_deferred` with reason
`circulation_product_target_deferred`; they are tracked temporarily outside
the V2 loan domain. Promote or resolve only when ExternalLoan/InternalLoan
functionality is actually developed.

| Circulation ID | Exact FINAL title | V1 Book ID | V1 Copy ID | Direction | Start (source precision) | Current V2 Item ID |
|---|---|---|---|---|---|---|
| circulation-1780488324444-0t35k | Start with Why: How Great Leaders Inspire Everyone to Take Action | 1780488283702 | copy-1780488283702-01-uznln0 | borrowed | 2026-05-25 (day) | None |
| circulation-1780488442608-xarat | Al het blauw van de hemel | 1780488422252 | copy-1780488422252-01-ujspl0 | borrowed | 2026-05-26 (day) | None |
| circulation-1786957374198-jlcb4 | Licht onder schaduw | 1786957348513 | copy-1786957348513-01-8w77of | borrowed | 2026-08-16 (day) | None |
| cr-1-borrowed-20260201-open | De zwevende wereld | 1771331258683 | copy-1771331258683-01-mihp2g | borrowed | 2026-02-01 (day) | None |
| circulation-1778484918854-daqsj | Onderstromen | 1771265795977 | copy-1771265795977-01-gc9mgt | lent_out | 2026-04-17 (day) | item-1de5236912dff88ad6739a90f674b560 |
| circulation-1780488544814-rx0sj | Briljante Bonen | 1780488516683 | copy-1780488516683-01-mt8fzu | lent_out | 2026-06-01 (day) | item-d1bd38d977e8d0404b1018c059da3710 |
| circulation-1780488599509-pl9vz | Simpel | 1780488596091 | copy-1780488596091-01-cxv778 | lent_out | 2025-01-01 (day) | item-1b0ee33f1186b2430806f2d3bdc644e0 |
| circulation-1786625130546-l5dsq | Spares | 1771331558034 | copy-1771331558034-01-3au3po | lent_out | 2026-08-13 (day) | item-c314fd9ef3636d00dac7fdb86a4aba18 |

## 5. Closed post-cutover fixes and functional acceptance

- **CLOSED:** 342 eligible Copies received Items after 341 Leesboek and
  1 Kookboek decisions. Raw categories and unsupported genres remain
  preservation evidence. The resulting total is 1,076 Items.
- **CLOSED:** Book Detail author and Series projections were fixed. Renée
  manually verified `Nora Roberts` and `MacKade Brothers · deel 1`.
- Renée manually verified that previously classification-blocked Books
  are visible; Nora Roberts visible population rose from 37 to 59;
  *The MacKade brothers: Rafe & Jared*, *Onderstromen*, *Captivated*,
  *Irish hearts* and *Abandoned in Death* are visible. Wishlist appeared
  correct and ReadingRounds are present where expected.

## 6. Development backlog; not migration blockers

- Cover runtime: FINAL cover evidence is preserved as deferred Edition
  evidence; the product contract/runtime remains undeveloped.
- Authors index, pages and navigation: product direction exists;
  implementation remains normal development.
- Series index, pages and navigation: same.
- Global personal Reading History page: ReadingRounds migrated;
  aggregation, page and navigation remain to be developed.
- ExternalLoan/InternalLoan flows: legacy circulation evidence is retained;
  the operational product model remains future development.
- Author identity consolidation: ID-less Authors were not merged by name;
  duplicate visible entities such as Nora Roberts need later curation,
  not a migration data-loss repair.
- The two invalid-ISBN Books above may receive optional human cleanup.

## 7. Source, backup and phase retention

- V2 production is now the active operational truth. V1 is retired as an
  active migration source of truth; its frozen snapshot remains protected
  for post-cutover assurance pending a separate **V1-RETIREMENT** decision.
  No `uchg` removal, source move or deletion is part of this closure.
- FINAL source archive: `/Users/renee/Migratie V1-V2/final-source/final-v1-20260923-01/data.zip`;
  protects exact source replay/audit provenance. Two encrypted retained
  DMG copies in `retention-primary/` and `retention-recovery/` protect
  independent source recovery.
- PRE-RESET SQL backup: `.local/production-collation-fix-8y0TGsVW/backups/`;
  protects the prior test-target state and accounts before bounded reset.
- PRE-CUTOVER/PRE-APPLY SQL backup: `.local/production-ready-7CP5HfpU/cutover-backups/`;
  protects the clean target before original migration.
- Two post-production, **pre-classification-repair** backups:
  `.local/post-cutover-fix-01/backups/post_apply-20260926T142331Z-ee59ab99c22f14d3a6b49014.sql.gz`
  and `.local/post-cutover-fix-01/backups/post_apply-20260926T150431Z-534d8a17c8a7c455eb0a81cd.sql.gz`;
  protect the migrated state before repair and supported rollback of
  repair attempts. The latter was the exact validated rollback backup.
  These are not backups of the final post-repair database.
- Production apply report: `.local/production-apply-DzxsWbkL/REPORT.md`;
  protects cutover execution, reconciliation and access-release evidence.
  Classification repair report: `.local/post-cutover-fix-01-rerun/REPORT.md`;
  protects repair run, verification, backup and release evidence. Private
  receipts and checksums remain at those external/ignored locations.
- Current macro phase: **normal V2 product development and daily-use
  acceptance** from current branch/repository reality; migration is closed.
  Recommended next bounded outcome: a cover-runtime contract and slice
  that renders preserved Edition cover evidence in catalog and Book Detail
  with truthful absence/failure behavior, subject to its own design and
  acceptance. This does not reopen migration or implement a feature here.

No backup, frozen source, CSV or production record was changed for this
closure. Known exceptions are accounted for: **23 erroneous Copy
exclusions, 2 invalid-ISBN CAT cases, 1 ambiguous circulation case and
8 open legacy circulation relationships**.
