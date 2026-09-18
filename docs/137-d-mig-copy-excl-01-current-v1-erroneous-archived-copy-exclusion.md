# D-MIG-COPY-EXCL-01 — Current V1 erroneous archived Copy exclusion contract

Status: **DESIGN GO — correction contract closed; implementation not started**

Date: 2026-09-18

Task severity: **High**

Scope: manifest-bound product and migration-design correction only. This
document adds no mapper, participant, schema, product write, Archive behavior,
REST, UI or apply/import path. V1 is source evidence, not product authority.

## 1. Product decision

Renée explicitly decided that all 23 CURRENT V1 Copies carrying the reviewed
legacy archive/correction state were erroneous registrations.

```text
DEZE 23 COPIES MOGEN GEEN V2 ITEM WORDEN.
```

For V2.001 this is a terminal Copy-eligibility decision. Each of the 23 source
Copies produces:

- no `CatalogItemPlan`;
- no V2 Item;
- no Archive lifecycle, reason or `archived_at` value;
- no restore/reactivation path;
- no Item-local details state or row;
- no fulfillment, Wishlist, circulation or Reading mutation; and
- no user-visible V2 record.

The raw values `duplicate_correction`, `incorrectly_registered` and
`wishlist_correction` are evidence that the source rows were corrections of
wrong registrations. They are not V2 Archive reasons and do not authorize an
Archive mapper.

## 2. Authority and corrections

This decision has precedence over older CURRENT assumptions that every stable
owned Copy would eventually become an Item and that the 23 archived Copies
would later receive ordinary V2 Archive handling.

The following earlier statements remain useful as historical baseline evidence
but are superseded for these 23 exact Copy records:

- doc 112's `archive wiring plan` classification;
- doc 115's one-Item-per-Copy default and downstream Archive expectation;
- docs 120–121 treating one archived non-empty Item-local state and 22 archived
  reviewed absences as future Item candidates;
- docs 121 and 130 counting ten of these Copies inside 744 complete Item plans;
  and
- docs 134–135 saying the two Wishlist-overlap Copies retain independent CAT
  Item/Archive lanes.

The correction does not invalidate the prior mapper evidence. It changes the
authorized future result. Until a separately authorized implementation closes,
the current mapper can still produce the old 744-plan dry-run baseline and must
not be used for apply/import.

## 3. Immutable CURRENT boundary

Only the designated CURRENT package was used:

| Provenance | Audited value |
|---|---|
| Authoritative ZIP | `/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip` |
| Validated read-only extraction | `.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/` |
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Source version | `books-29.authors-2.reading-goals-2` |

The audited checkout was clean `main` at
`a4cf2c6ae098ef11d96844aa1b255609d203c071`. Product is `v2.001`, schema is
`1026`, Biblio Core is `2.47.0` and Biblio UI is `0.20.0`.

The decision is bound to the exact manifest plus the reviewed set of 23 stable
Copy IDs and their exact `DeterministicJson` payload hashes. The sorted
`[{source_id,payload_hash}]` exclusion-set digest is:

```text
c1d54e96fd3330fa329a397fcc9f69c6556e6d4cd299c153be9c059145c9353e
```

The definitive reviewed mapping-contract identity is:

```text
d-mig-copy-excl-01.2026-09-18:set:c1d54e96fd3330fa329a397f
```

It is not a generic rule that every future source row with `archived=true`
must be discarded. A changed manifest, changed row, changed population or
additional archived Copy requires a new review and must fail closed under this
contract.

## 4. Source population

The designated source contains 1,106 Copies. Exactly 23 distinct Copies on 23
distinct parent Books have the complete reviewed legacy correction shape:

| Source fact | Count |
|---|---:|
| `archived=true` | 23 |
| non-empty `archivedAt` | 23 |
| `status=disposed` | 23 |
| `ownershipStatus=none` | 23 |
| `archiveReason=duplicate_correction` | 20 |
| `archiveReason=incorrectly_registered` | 2 |
| `archiveReason=wishlist_correction` | 1 |

Two parent Books have one additional non-archived sibling Copy. The exclusion
is Copy-scoped: those two sibling Copies remain independently accounted and are
not excluded merely because they share a Book. Both currently remain blocked
by their own classification state and create no complete Item plan.

The 23 rows also contain the normal three source-number fields each. Within
this population, one Copy has an acquisition object, one has a non-empty
private Copy note and one has a non-empty exemplar-photo slot. No row has a
disposal object. These facts reinforce that the complete Copy row must be
covered by restricted preservation rather than reduced to the three raw reason
labels.

## 5. Recomputed current intersections

The audit recomputed the CURRENT mapper predicates from the immutable source;
it did not subtract 23 from an existing total.

| Current pre-correction state of the 23 | Count |
|---|---:|
| CAT-eligible parent Book/Copy path | **23** |
| classification-ready | **10** |
| classification-blocked | **13** |
| Item-local-ready | **23** |
| non-empty Item-local state | **1** |
| reviewed Item-local absence | **22** |
| external-borrowed/non-Item | **0** |
| currently present in the 744 complete Item plans | **10** |

The 13 classification blockers split into six source `review`/`no_signal`
blocks and seven converged-classification conflicts. Item-local readiness is
not eligibility authority: after this decision, all 23 are terminal non-Items
regardless of those previously computed states.

## 6. CAT identity and alias audit

All 23 parent Books are CAT-ready. None is under invalid-ISBN, conflicting-ISBN,
missing-title, duplicate-ISBN Work conflict or another CAT quarantine.

| CAT relationship of excluded Copy's parent Book | Count |
|---|---:|
| duplicate-ISBN alias | **15** |
| unique/non-repeated CAT identity | **8** |
| duplicate-ISBN representative | **0** |
| CAT quarantine | **0** |

The Copy exclusion changes none of those Work/Edition identities. All 1,137
Work and 1,137 Edition source plans remain in the reviewed CAT stream. The 15
alias source identities continue to resolve through their exact committed
representative mappings. No alias is promoted, deleted or reparented because
its erroneous Copy disappears from Item eligibility.

## 7. Recomputed Item accounting

The complete Item-plan intersection was rebuilt from the same four independent
predicates: CAT-ready parent, reviewed classification, Item-local eligibility
and this new terminal Copy-exclusion contract.

| Item accounting | Current mapper baseline | After this decision |
|---|---:|---:|
| total source Copies | 1,106 | 1,106 |
| terminal erroneous Copy exclusions | 0 | **23** |
| CAT-eligible non-excluded Copy candidates | 1,104 | **1,081** |
| classification-ready non-excluded candidates | 748 | **738** |
| external-borrowed among those classification-ready candidates | 4 | 4 |
| complete `CatalogItemPlan` records | **744** | **734** |
| classification-blocked, Item-local-ready, non-excluded Copies | 355 | **342** |
| upstream CAT-quarantined, Item-local-ready Copies | 2 | 2 |
| external-borrowed/non-Item Copies | 5 | 5 |

The new Item total is **734**, because exactly ten—not 23—of the erroneous
Copies currently satisfy every other Item dependency. The other 13 were
already classification-blocked but now receive a terminal product disposition
instead of remaining promotion candidates.

The complete population closes exactly:

```text
734 complete Item plans
+ 342 classification-blocked Item-local-ready Copies
+   2 upstream CAT-quarantined Copies
+   5 external-borrowed non-Items
+  23 erroneous legacy Copy exclusions
= 1,106 source Copies
```

## 8. Item-local correction

The exclusion must be evaluated before an Item-local state can enter a
`CatalogItemPlan`. It must not be encoded as reviewed-null details, a fake
Condition, an archive flag or a classification blocker.

After exclusion, the normal Item-local candidate population is:

| Item-local result for non-excluded Copies | Count |
|---|---:|
| non-empty typed state | **71** |
| reviewed absence | **1,007** |
| external-borrowed terminal non-Item | **5** |
| erroneous Copy terminal non-Item | **23** |

The two CAT-quarantined Copies are included in the 71/1,007 source-semantic
totals but still cannot produce Item plans. Among complete plans, the one
excluded non-empty state lowers the expected Item-local details operation count
from 27 to **26**. None of the 23 creates or clears an Item-local row.

## 9. Circulation overlap

There is no circulation overlap:

- zero of the 23 Copy rows contains a circulation round;
- zero of their 23 parent Books contains a circulation round; and
- the existing eight preserved plus one quarantined circulation observations
  are unchanged.

The exclusion creates no loan, return, settlement, availability or circulation
history effect. No circulation payload is copied into the exclusion evidence.

## 10. Wishlist overlap

Exactly two CURRENT Wishlist rows reference parent Books that also contain one
of the 23 erroneous Copies. Zero Wishlist row has a `fulfilledCopyId` pointing
to any of the 23 Copies.

Both existing Wishlist plans remain Work-only and active. They keep their exact
target User, Work dependency and historical timestamps. The raw reason
`wishlist_correction` does not remove, fulfill, narrow or otherwise mutate a
Wishlist entry. The prior Item/Archive-lane statement for these two Copies is
superseded: the Wishlist plans remain; the erroneous Copies produce no Item or
Archive plan.

## 11. Reading overlap

Reading evidence is Book/Work-scoped in this snapshot; there is no exact
Copy-to-Reading relation for any of the 23 Copies.

Their 23 parent Books contain:

- one stable ReadingRound;
- 16 reading registrations;
- Reading History evidence on all 23;
- 17 `finished`, four `unread` and two `unknown` current status values; and
- no exact reference from that evidence to the erroneous Copy.

The current prepared stream contains one ReadingRound plan and nine Personal
Reading Truth plans whose exact CAT Work dependency is sourced from ten of
these parent Books. Those ten plans remain unchanged. The exclusion removes no
Work, ReadingRound or Personal Reading Truth plan, invents no replacement
source and never ends or alters private reading history.

## 12. Other downstream boundaries

| Domain | Result |
|---|---|
| Work/Edition CAT | unchanged; parent bibliography survives |
| classification evidence | unchanged at Book level; no context write exists without an Item plan |
| Authors/Series/Notes/Assessments | unchanged Work-level lanes |
| Archive | zero plans, periods, reasons, timestamps or restore behavior |
| fulfillment/acquisition | zero product effect |
| Collection | zero membership or lifecycle effect |
| Wishlist | two Work-only plans remain; zero Copy fulfillment relation |
| Circulation | zero overlap and zero effect |
| Reading | ten Work-level plans remain; zero Copy relation or effect |
| user-visible product | zero records for the 23 source Copies |

## 13. Terminal eligibility outcome

The future bounded implementation must introduce one explicit CURRENT-only
terminal eligibility outcome for these exact records. It must be evaluated
before normal Item dependencies are combined and must short-circuit Item
planning independently of classification and Item-local readiness.

The outcome means only:

```text
source Copy was an erroneous legacy registration
product target is intentionally absent in V2.001
source evidence remains durably accounted
```

It does not mean archived, disposed, external-borrowed, invalid, quarantined or
temporarily blocked. It must not reuse
`NOT_LIBRARY_ITEM_EXTERNAL_BORROWED`, because the evidence and future product
expectation are different.

## 14. Durable no-target preservation

Each excluded Copy must emit exactly one typed
`PreservedSourceEvidencePlan` through the existing MIG-02-PRESERVE-01 contract.
The plan has no dependency, operation or target mapping.

The closed design is:

```text
source type:       preserved_source_evidence
source identity:   v1.copy/<stable-copy-id>/erroneous-legacy-copy
evidence type:     current_v1_erroneous_legacy_copy
reason:            erroneous_legacy_copy_not_carried_forward_v2
privacy:           restricted_source
locator:           data/books.json#copies/<encoded-copy-id>/record
evidence hash:     deterministic hash of the complete Copy row
dependencies:      none
product mappings:  none
```

The reason string follows the existing bounded snake-case convention and the
established `reading_goal_not_carried_forward_v2` form. It expresses Renée's
explicit product decision; it is not derived from a raw legacy reason.

The full Copy row is the atomic evidence envelope. That retains archive flags,
legacy timestamps/reasons, source numbers and the occasional acquisition,
private note or photo reference without exposing those values in ordinary
artifacts, logs, errors or documentation. No separate fake archive-period
identity is created because the source has none.

The existing generic preservation status may remain
`awaiting_future_processing` as a technical evidence-retention state. It does
not mean normal promotion is expected. There is no approved promotion target,
backfill or user-visible record. Any future reversal would require a new
explicit product decision, new reviewed mapping contract and exact replay-safe
transition; it is not part of this contract.

## 15. Replay and reconciliation

The preservation observation is terminal for current V2.001 reconciliation:

- the exact 23 reviewed source identities must each resolve to one equivalent
  no-target preservation observation;
- exact replay reuses equivalent evidence;
- changed payload, manifest, contract, reason, privacy, locator or evidence
  hash fails closed;
- any Item mapping for one of the 23 is a reconciliation error;
- any Copy-scoped archive, details, fulfillment, Wishlist, circulation or
  Reading mapping from an exclusion observation is a reconciliation error;
  the separately valid Work-level Wishlist and Reading mappings remain; and
- zero unexplained source Copy remains.

For the ten Copies that currently have a `CatalogItemPlan`, the exclusion
observation replaces participant-owned `copy_auxiliary_evidence_preserved`.
For the other 13, it promotes the existing mapper-only auxiliary finding into
the first durable admitted observation. Their source numbers, note/photo slots
and legacy archive fields are covered once by the complete-row hash. None of
the 23 may also emit `copy_auxiliary_evidence_preserved` or an archive-specific
preserved observation.

### Prior Item mapping guard

Generic preservation replay alone is insufficient because an older Item uses
another source identity:

```text
catalog_item:v1.copy/<stable-copy-id>/item
```

Before a corrected plan can be accepted or a run can begin, apply preflight
must search all scope-compatible committed observations for the exact target
User, target Library, source family and each of those 23 legacy `catalog_item`
identities. The search must include running, interrupted, failed and completed
owning runs and must not hide a match behind a payload-hash or run-status
prefilter.

Any committed Item/context/details mapping for one of those identities is a
hard conflict. The implementation must fail before creating a new run or
preservation observation; it must not delete, archive, detach or otherwise
repair the target automatically. Reconciliation must perform the same negative
proof. Resolution of an already-written product target would require an
explicit operator/product correction outside this slice.

## 16. Expected prepared-stream delta

This is design acceptance evidence, not an implemented or accepted trial. If
no other mapping lane changes, the exact CURRENT recomputation requires:

| Prepared-stream measure | Current baseline | Corrected expectation |
|---|---:|---:|
| `catalog_item` plans | 744 | **734** |
| `create_or_reuse_item` operations | 744 | **734** |
| `initialize_or_reuse_classification` operations | 744 | **734** |
| `record_item_local_details` operations | 27 | **26** |
| `preserved_source_evidence` plans | 75 | **98** |
| total executable records | 6,579 | **6,592** |

The net total is plus 13 because ten current Item observations disappear and
23 terminal no-target preservation observations enter. These numbers must be
recomputed from the source and produced plans during implementation; they are
not runtime constants.

## 17. Required future implementation slice

The only correct next technical slice is:

**MIG-02-COPY-EXCL-01 — Current V1 erroneous archived Copy exclusion**

It must:

1. bind the exact manifest and reviewed 23-ID/payload-hash set;
2. emit an explicit terminal Copy eligibility before Item-local/CAT Item plan
   composition;
3. suppress every Item, classification-context-for-that-Item and Item-local
   product operation for those Copies;
4. emit exactly 23 admitted restricted no-target preservation plans;
5. keep Work/Edition, alias, Wishlist and Reading plans unchanged;
6. retain zero Archive/circulation/fulfillment behavior;
7. update restricted-source recovery for the exact Copy-row locator;
8. add the cross-run prior-Item guard and make apply preflight, replay and
   reconciliation reject any Copy-scoped target mapping for the 23;
9. recompute all counts and prove a final-SHA zero-write dry-run; and
10. expose no apply/import authorization.

It is explicitly not an Archive mapper.

## 18. Acceptance criteria for the future slice

- Exactly 23 reviewed source Copies receive the terminal erroneous-registration
  outcome and no other Copy does.
- All 23 produce zero `CatalogItemPlan`, Item, Archive state, details row,
  fulfillment, Wishlist, circulation and Reading mutation.
- Exactly 734 complete Item plans remain after source recomputation.
- The 13 previously classification-blocked erroneous Copies are no longer
  future normal Item-promotion candidates.
- The two non-archived sibling Copies remain independently accounted.
- Fifteen alias and eight unique parent CAT relations remain unchanged; zero
  excluded Copy parent is CAT-quarantined.
- Two Work-only Wishlist plans and ten Work-level Reading plans remain.
- Exactly 23 restricted no-target preservation plans use reason
  `erroneous_legacy_copy_not_carried_forward_v2` and zero target mappings.
- All scope-compatible prior runs are checked for the 23 old `catalog_item`
  identities; any committed target mapping fails before a new run write.
- Raw legacy reason/time/private content is absent from ordinary artifacts,
  output, docs and errors.
- Exact replay is idempotent; any divergent evidence or forbidden target fails
  closed.
- Profile and dry-run are zero-write; no apply/import runs.

## 19. Boundary and verdict

No production code, schema, REST, UI, Archive mapper, source mutation,
apply/import or trial write is authorized by this document. Product remains
`v2.001`; schema remains `1026`; Core and UI versions remain unchanged.

**DESIGN GO.** The 23 CURRENT erroneous archived Copies are terminally excluded
from V2 Item eligibility. Their only authorized migration result is durable,
restricted, reconciliation-safe no-target source evidence. Implementation is a
separate bounded slice and has not started.
