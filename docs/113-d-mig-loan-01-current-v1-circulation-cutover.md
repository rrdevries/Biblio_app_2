# D-MIG-LOAN-01 — Current V1 circulation cutover contract

Status: **DESIGN GO — OPTION D: DEFERRED CIRCULATION PROMOTION**

Date: 2026-09-15

Task severity: **High**

Scope: product/domain/migration design only. No production code, schema,
migration/import, REST, UI or source mutation is authorized by this document.

## 1. Decision inheritance

Decision precedence for this slice is accepted V2 borrowing/circulation
product truth, current implemented V2 contracts, closed current design, and
only then the CURRENT V1 snapshot as mapping/cutover evidence. V1 does not
authorize a V2 product model.

| Topic | Existing V2 decision | Current implementation | CURRENT source evidence | Gap | Decision needed? |
|---|---|---|---|---|---|
| ExternalLoan | Private, user-owned, concrete temporary physical source from outside Biblio; intentionally Work-linked, not a Library Item | Active-only aggregate/table with user, Work, `borrowed_at` datetime and optional due datetime; owner read and ReadingRound start exist; no public create/list/return/close/history lifecycle | Five Copy-level `borrowed` IDs; four are also Book-level; all five Copies say `ownershipStatus=borrowed`; the four Books say `ownershipStatus=borrowed`, `collectionStatus=borrowed` and `lending.mode=borrowed_in` | Source day precision cannot be stored without inventing a time; no counterparty field; no close/history state; no cutover UI | No for CURRENT rehearsal; future promotion uses the then-approved loan model |
| Internal Library lending | Library-owned transaction around one physical Item, with Library `Uitgeleend` and borrower `Geleend` perspectives; first full implementation requires an eligible identified member | Authorization predicate only; no InternalLoan aggregate, table, repository, lifecycle, application service, read model or UI | Four Copy-level open `lent_out` IDs on owned Copies; three are also Book-level with `collectionStatus=lent` and `lending.mode=lent_out` | No truthful operational target; no identified recipient | No for CURRENT rehearsal; future promotion uses the then-approved loan model |
| Counterparty identity | Internal borrower rules require an eligible V2 user/member; an external source may be a person or organization, but current ExternalLoan persistence has no counterparty field | No circulation counterparty identity or migrated display-label target | Every circulation ID has non-empty free text; four distinct values; no user, member or Library ID | Name matching would fabricate identity; a future non-identifying legacy label needs its own approval | No for preservation; future product use is deliberately deferred |
| Active versus closed lifecycle | Active loans remain actionable until return/settlement; full history is retained; loan and ReadingRound lifecycle are separate | ExternalLoan can only be active; InternalLoan is absent | Eight IDs are unambiguously open across available representations; one shared `borrowed` ID has Book open versus Copy closed | No closed ExternalLoan target, no InternalLoan target, and one contradictory state | No for CURRENT rehearsal: retain open meaning and quarantine conflict |
| Archive guards | Ordinary Item archive is blocked by active internal lending; special not-returned/given-away routes settle first; archive never silently ends private ReadingRounds | Item archive lifecycle exists, but its InternalLoan guard is vacuously satisfied because InternalLoan does not exist | No archived Copy has any circulation record | Future operational lending must activate the guard atomically | No for CURRENT preservation; future operational promotion must reapply the invariant |
| User ownership | ExternalLoan and ReadingRound remain user-owned; Library roles do not transfer or bypass ownership | Authenticated owner-scoped reads and starts exist; migration target must be explicit | V1 has no user ID | Only explicit `target_user_id` may establish migrated user ownership | No; already closed |
| Library ownership | Item, internal loan, archive and Library audit are Library-owned/scoped | Item and archive exist; InternalLoan does not | `lent_out` refers to an owned physical Copy | Exact target Item and explicit `target_library_id` are required; migration privilege is not ownership | No; already closed |
| Work versus Item linkage | ExternalLoan remains a distinct Work-linked physical source; internal lending is tied to one Library Item | ExternalLoan→Work and Item→Edition→Work exist; no InternalLoan link exists | `borrowed` has Copy occurrences but source ownership/lending state says the copy is external; `lent_out` is an owned Copy | Neither type may be flattened into the other's target merely because that target exists | No; boundary is closed, implementation is not |
| Close/return behavior | Returning a loan does not start/end a ReadingRound; internal return removes current physical access without deleting history | ReadingRound finish/stop exists; no loan return/settlement service exists | Open records have day-precision start; the conflicting ID alone has a day-precision Copy end | Operational migration needs a separate idempotent loan settlement lifecycle | No for CURRENT preservation; decide only with future operational promotion |
| History retention | Historical truth and known date precision must survive; deferred product data may be `preserved_deferred`; ambiguity is quarantined | MIG-FND can preserve bounded source evidence or quarantine it, but this is not user-visible product history | One Copy-level closed occurrence conflicts with an open Book occurrence; all other IDs are open | No current product history target for either loan type | No for CURRENT evidence retention; user-visible history belongs to the future loan model |

Closed decisions are not reopened here: ownership, authorization, ExternalLoan
Work linkage, internal Item linkage, ReadingRound independence, archive
invariant, explicit migration targets and the prohibition on name identity all
remain binding.

## 2. CURRENT source evidence

The sole CURRENT source is the immutable SOURCE-01 extraction of:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

Snapshot anchor SHA-256:
`835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`.
The source was inspected through the read-only extraction recorded in
MIG-02-SOURCE-01. No historical MIG-01/DATA-01 count or old ZIP is current
authority.

The table below exposes stable structural IDs and shapes only. Private
counterparty and circulation-note values are omitted.

| Source circulation ID | Type | Book presence/state | Copy presence/state | Start precision | End precision | Counterparty shape | Linked Book | Linked Copy | Archived Item conflict |
|---|---|---|---|---|---|---|---|---|---|
| `circulation-1778484918854-daqsj` | `lent_out` | yes / open | yes / open | day | none | free text | `1771265795977` | `copy-1771265795977-01-gc9mgt` | no |
| `circulation-1780488324444-0t35k` | `borrowed` | yes / open | yes / open | day | none | free text | `1780488283702` | `copy-1780488283702-01-uznln0` | no |
| `circulation-1780488442608-xarat` | `borrowed` | yes / open | yes / open | day | none | free text | `1780488422252` | `copy-1780488422252-01-ujspl0` | no |
| `circulation-1780488544814-rx0sj` | `lent_out` | yes / open | yes / open | day | none | free text | `1780488516683` | `copy-1780488516683-01-mt8fzu` | no |
| `circulation-1780488599509-pl9vz` | `lent_out` | yes / open | yes / open | day | none | free text | `1780488596091` | `copy-1780488596091-01-cxv778` | no |
| `circulation-1780741593722-1crrv` | `borrowed` | yes / open | yes / closed | day | Book none; Copy day | free text | `1780741573994` | `copy-1780741573994-01-styu83` | no |
| `circulation-1786625130546-l5dsq` | `lent_out` | no | yes / open | day | none | free text | `1771331558034` | `copy-1771331558034-01-3au3po` | no |
| `circulation-1786957374198-jlcb4` | `borrowed` | no | yes / open | day | none | free text | `1786957348513` | `copy-1786957348513-01-8w77of` | no |
| `cr-1-borrowed-20260201-open` | `borrowed` | yes / open | yes / open | day | none | free text | `1771331258683` | `copy-1771331258683-01-mihp2g` | no |

Unique-ID accounting is therefore:

- four unambiguously open `borrowed` IDs;
- four unambiguously open `lent_out` IDs;
- one shared `borrowed` ID with contradictory open/closed state;
- zero unambiguous closed IDs once both representations are respected;
- zero archive/circulation conflicts.

At representation level this remains exactly SOURCE-01's profile: Copy has
five `borrowed`, four `lent_out`, eight open and one closed; Book has four
`borrowed`, three `lent_out` and seven open.

## 3. Book/Copy relationship

Classification: **D — source does not prove precedence**.

Seven IDs occur under both Book and Copy. Six payload pairs are byte-meaning
equivalent. Two further IDs occur only under Copy. That shape is compatible
with Copy being canonical and Book being a denormalized summary, but it does
not prove it. It is also compatible with incomplete synchronization or two
write paths. No schema marker, authority field or source rule designates
either representation as canonical.

The migration adapter correctly retains one stable source observation per
circulation ID with separate `book_occurrences` and `copy_occurrences`. The
cutover contract must keep both occurrences; it must not select one by
convenience.

## 4. `borrowed` semantics

CURRENT evidence strongly supports the product meaning “the source owner has
borrowed this physical book from a person or organization outside their own
Library”:

- all five Copy occurrences have `ownershipStatus=borrowed`;
- all four corresponding Book occurrences have
  `ownershipStatus=borrowed`, `collectionStatus=borrowed` and
  `lending.mode=borrowed_in`;
- every occurrence has a free-text counterparty and none has a V2 identity.

V1 physically records all five on a Copy and additionally summarizes four on
the Book. That does not change V2's already accepted boundary: an external
loan is a distinct user-owned source linked to Work, not a Library-owned Item.
The source Copy ID remains migration evidence and must not be turned into an
Item merely to preserve it.

The current ExternalLoan target is insufficient for cutover continuity:

- it accepts only `active`;
- it cannot close/return or retain ended history;
- it requires a full datetime while the source proves only a calendar day;
- it cannot retain a counterparty label;
- it has no normal create/list/return product journey.

Consequently an open V1 `borrowed` record is not yet operationally closable in
V2. A closed `borrowed` record has no product-history target. Neither may be
written into the current table by inventing a time, dropping source fields or
leaving an immortal active record.

## 5. `lent_out` semantics

CURRENT evidence strongly supports “one owned physical Copy is lent out to
another party”:

- all four occurrences are attached to an owned Copy;
- the three corresponding Books say `collectionStatus=lent` and
  `lending.mode=lent_out`;
- all four are unambiguously open;
- every counterparty is free text without a V2 identity.

The truthful V2 linkage is therefore target Library Item, not Work and not
ExternalLoan. V2 has accepted future InternalLoan behavior, but no current
aggregate, persistence, lifecycle or UI. The first full InternalLoan model
also expects an eligible identified V2 member, which free text cannot supply.

An operationally retained `lent_out` record would require an approved minimal
internal active-loan capability: stable identity, exact Library and Item,
active state, date precision, a deliberately non-identity legacy counterparty
representation or explicit human-resolved member ID, owner/manager read,
return/settlement, Item availability, archive guard, audit/privacy rules,
idempotent migration and a small actionable UI. This is a new product/schema
lifecycle and is not created in this design slice.

There is no current historical target for closed `lent_out` evidence.

## 6. Counterparty identity

All nine IDs have a non-empty free-text counterparty. Free text is source
evidence, not a V2 `UserId`, membership or Library identity. The migration
must never name-match WordPress users or Library members, assign a name to the
target user, or create hidden members.

Three policy shapes are coherent:

1. preserve the free-text value only in restricted MIG-FND evidence;
2. after a product decision, expose it as a bounded migration-preserved display
   label that explicitly carries no identity or authorization meaning;
3. require explicit human resolution to a real target user/membership before
   any operational internal-loan creation.

Shape 1 is chosen for CURRENT preservation. Shapes 2 and 3 remain possible
future product choices only after explicit approval. Shape 2 is new product
meaning; shape 3 adds manual reconciliation and must fail closed when
unresolved. There is no canonical basis for choosing either automatically.

## 7. Open circulation

Open circulation must not disappear. For the CURRENT rehearsal, all eight
unambiguously open IDs remain explicitly accounted for without pretending the
current V2 loan models can operate them.

Option D now explicitly approves preservation for CURRENT rehearsal handling:
all eight IDs remain open in meaning while product promotion is deferred. An
open record cannot become closed merely because V2 lacks a target, and
preservation is never presented as return/settlement functionality.

Final production-cutover treatment is intentionally not selected from the old
A/B/C options. It is reassessed from a fresh explicitly designated export and
becomes a separate transition decision only if operationally open circulation
still exists without a usable V2 loan model.

## 8. Closed circulation

The CURRENT source has no unambiguous closed unique circulation ID. The sole
Copy-level closed record is open at Book level and is handled as conflict, not
as settled history.

If a later corrected/final export contains closed circulation, current V2.001
has no complete loan-history target for either source type. Its full source
payload, both structural links, known date precision, private fields and
snapshot provenance can be retained losslessly as `preserved_deferred` in
MIG-FND pending a future authorized lending-history slice.

That retention is not active product functionality: the user cannot see,
search, return or edit it through V2.001 product UI, and it does not affect
Item availability. Reconciliation and authorized migration operators can
prove that the source record survived and can later be processed.

## 9. Conflicting end state

`circulation-1780741593722-1crrv` is a source-integrity contradiction:

- Book occurrence: `borrowed`, open;
- Copy occurrence: same ID/type/start/counterparty/notes, but closed with a
  day-precision end;
- Book current state remains `ownershipStatus=borrowed`,
  `collectionStatus=borrowed`, `lending.mode=borrowed_in`;
- Copy current state is `status=owned`, `ownershipStatus=owned`;
- Book and Copy have the same parent update timestamp, so recency does not
  establish authority;
- neither is archived.

The record is not a resolvable reviewed mapping. The current rehearsal must
classify the one source observation as **`quarantined`**, retain both
occurrences, and write no operational loan. `preserved_deferred` would hide a
known contradiction inside an apparently coherent historical disposition.

No correction is required for CURRENT rehearsal accounting. Before this record
can ever be promoted to product state, or accepted as coherent in a later final
cutover, it must remain quarantined unless a future source correction or
explicit reviewed mapping decision resolves the contradiction. The CURRENT
rehearsal snapshot remains immutable.

## 10. Rehearsal handling

Option D fixes the bounded CURRENT rehearsal contract:

- enumerate all nine stable circulation IDs exactly once;
- retain both Book/Copy occurrences and their source references;
- classify the eight unambiguously open IDs as `preserved_deferred` with an
  explicit deferred-circulation-promotion reason;
- classify the contradictory ID as `quarantined` with a fixed end-state
  conflict reason;
- create no ExternalLoan, InternalLoan, Item availability or user/member
  identity;
- expose no private counterparty or note value in ordinary artifacts/output;
- reconcile `8 preserved_deferred + 1 quarantined = 9` with zero unexplained
  loss.

These are valid explicit migration dispositions. Circulation therefore does
not block the rehearsal once its separately authorized preservation/quarantine
participant is present. Preservation means source truth retained and product
translation deliberately deferred; it never means settled, returned,
unimportant or lost.

This design is not permission to apply the CURRENT snapshot or to start a
circulation implementation automatically.

## 11. Final-cutover contract

Option D closes CURRENT rehearsal design but deliberately does not declare a
future production cutover ready. Before the ultimate production cutover:

1. generate and explicitly designate a fresh final V1 export;
2. run the exact current source profile against that immutable snapshot;
3. assess its actual circulation population and conflicts;
4. if operationally open circulation remains while V2 still has no usable
   loan model, stop and make a separate explicit cutover decision.

Possible later outcomes include promotion into an implemented loan model,
explicitly accepted temporary operational administration outside Biblio, or
another consciously chosen transition measure. Option D neither chooses one
now nor requires open V1 records to be artificially closed merely to make the
migration fit the current V2 schema.

## 12. Preservation policy

MIG-FND preservation is the chosen target for truthful non-operational
circulation evidence in the CURRENT rehearsal. Each stable circulation ID must
retain losslessly, in restricted bounded evidence and where present:

- source snapshot and stable source ID;
- both Book and Copy occurrences when present;
- exact raw `borrowed`/`lent_out` type and explicit open/closed state;
- exact start/end value and precision;
- counterparty and circulation notes as private evidence;
- source Book/Copy references and payload hash;
- disposition reason, conflict information and future-processing state.

Ordinary profile, dry-run and reconciliation output exposes only IDs, counts,
shapes, hashes, dispositions and reason codes. Preservation neither grants
ownership nor changes Item availability and is not a user-facing loan/history
feature. The preserved source state remains future-promotable and the original
evidence is never removed by later promotion.

## 13. Archive interaction

The CURRENT snapshot has no archive/circulation conflict. The invariant for
any operational implementation remains:

> A Library Item with active internal lending cannot be ordinarily archived.
> Special not-returned/given-away handling settles the loan and archives the
> Item in one coherent transaction.

Preserved circulation evidence does not activate the operational archive
guard. Any future migrated active InternalLoan must activate the existing
guard contract and must not silently close private ReadingRounds.

## 14. Ownership and linkage

| Source meaning | Operational owner/scope | Target linkage | Migration boundary |
|---|---|---|---|
| External `borrowed` | Explicit target user; user-owned | Distinct ExternalLoan→Work source; never Item | Source Copy remains evidence only; no current-user/admin/name fallback |
| Internal `lent_out` | Explicit target Library transaction around its Item; borrower perspective only after explicit valid identity | InternalLoan→exact Library Item | Exact target Library and CAT Item mapping required; no Work-level flattening |
| Deferred circulation preservation | MIG-FND evidence under the explicit migration run/target | Source observation and references, no operational product link | Open remains explicitly open in evidence; migration authority never becomes product ownership |

## 15. Options considered

Options A (settlement-first), B (borrowed-only continuity) and C (full minimal
continuity) were reviewed and explicitly not selected. The chosen fourth
contract is:

| Chosen option | Product behavior | Data retained | Operational continuity | Implementation/schema cost | Migration complexity | Risk | Final-cutover implication |
|---|---|---|---|---|---|---|---|
| **D. Deferred circulation promotion** | V2.001 builds no temporary/partial loan model solely for CURRENT migration; no source record is changed or declared closed | All source facts stay lossless and traceable; eight open IDs are preserved and one conflict quarantined | None in V2.001 from these records; open meaning remains explicit evidence | No loan product/schema/UI change; a separate preservation/quarantine participant is still needed | Bounded CURRENT accounting now; later promotion is a separate replay-safe backfill | V2.001 cannot operate these loans; final cutover may still be blocked and must be reassessed | Re-profile a fresh final export; if open records remain without a usable model, make a new explicit cutover decision |

## 16. Team review

### Product

The user must not lose source truth about books that remain out. Option D
retains that truth without claiming a V2 return path. It does not by itself
prove production-cutover continuity.

### Library operations

An owned Copy with open `lent_out` cannot silently become an operational V2
loan. Preservation also does not alter Item availability. Before production
cutover, continued operational administration must therefore be addressed by
the separate fresh-export cutover decision if open circulation remains.

### Metadata and data integrity

No free-text counterparty becomes identity. Book/Copy precedence remains
unknown. The contradictory record stays quarantined with both representations.
Every ID reconciles exactly once.

### UX

V2.001 exposes no misleading partial loan interface. Preservation is not
presented as visible or actionable product state. Any future promotion surface
must be designed with the then-approved loan model.

### Engineering

Option D keeps V2.001 bounded without weakening domain truth or introducing an
immortal ExternalLoan, pseudo-InternalLoan or fabricated datetime/identity. The
preservation participant is migration infrastructure, not a hidden loan model.

### Migration

The CURRENT rehearsal passes circulation accounting with eight
`preserved_deferred`, one `quarantined`, zero product loans and zero unexplained
drop. Final production cutover remains a later checkpoint against a fresh
explicit export.

## 17. Chosen/recommended contract

**Chosen: Option D — deferred circulation promotion.**

CURRENT source truth is preserved without product translation. Eight
unambiguously open IDs remain explicitly open in preservation evidence. The
Book/Copy conflict remains quarantined. Zero operational V2 loan records are
created, no source record is mutated or deemed closed, and no counterparty is
linked to identity.

The circulation evidence remains eligible for a later explicit
`MIG-LOAN-BACKFILL-01`-style slice after the real V2 loan model exists. That
backfill must use the same source identity, be replay-safe and auditable,
retain the original migration evidence, make no identity assumptions and have
its own tests and reconciliation.

The design verdict is **DESIGN GO**.

## 18. Product decisions still requiring Renée

None for CURRENT rehearsal circulation handling. A fresh final export that
still contains operationally open circulation without a usable V2 loan model
will require a separate explicit production-cutover decision. That future
checkpoint does not reopen Option D for the CURRENT snapshot.

## 19. Required implementation slices

No implementation is authorized automatically by this DESIGN GO. The smallest
separately authorized CURRENT rehearsal implementation would be:

1. a source-neutral circulation preservation/quarantine participant that uses
   the existing one-observation-per-ID payload and never creates loan product
   state;
2. CURRENT adapter mapping rules for the approved dispositions;
3. dry-run/reconciliation acceptance for exact circulation accounting and
   privacy-safe output;
4. promotion-readiness evidence proving every preserved source field needed by
   a later loan backfill remains available.

No ExternalLoan or InternalLoan migration participant is required.

After the real V2 loan model is approved and implemented, a separate explicit
`MIG-LOAN-BACKFILL-01` may read the preserved evidence and promote eligible
records to active ExternalLoan, active InternalLoan, closed loan history or
other then-approved circulation-history targets. It must not delete the
original evidence.

## 20. Acceptance criteria

### Design exit

- Option D is recorded as chosen in canonical scope, acceptance and deferred
  decision documentation.
- Every CURRENT source ID has one unambiguous rehearsal disposition.
- Future promotion remains possible without deleting or rewriting original
  evidence.
- Final production-cutover readiness remains explicitly separate.
- Status is `DESIGN GO`; one docs-only commit closes the design.

### CURRENT rehearsal acceptance

- Only snapshot SHA-256
  `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`
  is used as CURRENT evidence.
- All nine circulation IDs are enumerated exactly once with both occurrences
  retained where present.
- Eight unambiguous open IDs are `preserved_deferred` and remain explicitly
  open in evidence; the contradictory ID is `quarantined`; total is nine with
  no unexplained drop.
- No product loan, Item state, user/member or Library ownership is written.
- No private counterparty/note value appears in docs or ordinary output.
- Open evidence is never rewritten as returned, completed or historically
  closed solely because no V2 product target exists.
- Preservation retains stable ID, type, state, dates/precision, Book/Copy
  references and representations, private counterparty, hashes/provenance and
  conflict information where present.
- The snapshot and read-only extraction are unchanged.
- Circulation accounting does not block the rehearsal once the separately
  approved participant proves these dispositions.

### Future promotion acceptance

- Promotion uses the same stable source identity and only the then-approved
  loan model.
- It is replay-safe, auditable and independently reconciled.
- It makes no name/identity assumption and retains the original evidence.
- It has separate tests and is not inferred from this design GO.

### Final production-cutover checkpoint

- A fresh, explicitly designated immutable final V1 export is profiled.
- Actual open/closed/conflict circulation is reassessed from that export.
- If operationally open records remain without a usable V2 loan model, final
  cutover stays blocked pending a separate explicit transition decision.
- No open V1 record is artificially closed merely to make migration possible.

## Current V1 data rule

Only the explicitly designated CURRENT snapshot and SOURCE-01 read-only
extraction/profile are authority for this design. Historical MIG-01, DATA-01,
old ZIPs and old counts are not current truth. The CURRENT snapshot was not
modified.

## Schema/Core/UI impact

This design change has **none**. Product remains `v2.001`; schema remains
`1026`; Biblio Core remains `2.34.0`; Biblio UI remains `0.20.0`.

Future promotion describes conditional later impact only. No code, schema,
migration/import, REST or UI work is included.

## Git

This `DESIGN GO` is delivered in exactly one local docs-only commit after
targeted validation and independent review. Nothing is pushed to
`origin/main`.
