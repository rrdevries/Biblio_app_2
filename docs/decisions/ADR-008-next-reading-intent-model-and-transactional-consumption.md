# ADR-008 — Next Reading intent model and transactional consumption

Status: Accepted

Scope: Biblio V2 v2.001 / corrected Next Reading contract before C7

## Context

The original F2.9 contract modeled Work, Library Item and ExternalLoan as
equivalent targets. Target content contributed to entry identity, content
duplicates were forbidden, targets were immutable, ReadingRound start did not
mutate Next Reading and manual removal had no server-side Undo.

That contract was valid for the completed F2.9 implementation, but was later
explicitly revised before C7 REST and UI. The current product contract separates
a future reading intention from a preferred concrete reading source.

## Decision

### Intent and identity

Hierna lezen is one private, platform-wide, user-owned, manually ordered list
of separate future reading intentions.

Each entry:

- has one stable server-issued entry ID;
- refers to exactly one mandatory Work;
- uses that entry ID as its sole planning identity;
- may occur alongside any number of entries for the same Work;
- may be fully identical in content to another entry.

There is no content uniqueness rule.

### Preferred reading source

An entry may have zero or one mutable preferred concrete reading source. The
closed source types are:

- `library_item`;
- `external_loan`.

A preferred source is context for an intention. It is not a reservation,
claim, availability guarantee, authorization proof or historical ReadingRound
provenance. It may be set, changed or cleared without replacing the entry.

Loss, deletion, inaccessibility or unusability of a preferred source does not
delete or retarget the entry. Presentation layers receive only safe human
context or a generic unavailable state; protected source metadata does not
leak.

ReadingRound remains the historical truth for the concrete source actually
used.

### Transactional consumption

A successful active ReadingRound start consumes at most one matching Next
Reading entry.

Starting from a specific Next Reading entry consumes exactly that entry ID
after owner, Work and chosen concrete source validation. A start initiated
elsewhere selects from the current locked list in this order:

1. the first entry in current list order with the same Work and exact live
   actual source;
2. otherwise the first entry in current list order with the same Work and no
   preferred source;
3. otherwise no entry.

Duplicate entries are therefore handled deterministically by current list
order. Snapshot-only source identities are not live matches. Historical or
source-free ReadingRound registration consumes nothing, and a failed start
consumes nothing.

ReadingRound creation and required Next Reading consumption share one
transactional application boundary. This orchestration does not belong in
REST, UI, a database trigger or a public event listener.

### Concurrency

Add, manual remove and Undo, reorder, preferred-source mutation and automatic
consumption share the same owner-scoped persistent list-state lock. Manual
mutations use optimistic list-version concurrency where required by their
contract. Automatic consumption selects from current locked state. One
successful ReadingRound start can consume no more than one entry.

### Undo

Manual removal is immediate and can create a temporary, server-side,
owner-scoped one-time Undo capability. Undo restores the same entry identity
and its stored planning state; it is not a permanent soft-delete model.

The current 30-second lifetime is a centrally configurable technical default,
not an immutable architectural duration. Automatic ReadingRound consumption
does not create an Undo record or token.

### Privacy, authorization and activity

Next Reading remains private user-owned data. Library roles provide no override
over another user's entries or Undo state. Referencing a Library Item does not
transfer ownership and a preferred-source reference does not grant source
access.

Current authorization is revalidated where source visibility or use matters.
Unknown, foreign and inaccessible entry/source/Undo cases remain
non-enumerating, and inaccessible source metadata is not exposed to
presentation layers.

Next Reading mutations and automatic consumption do not write a Library
`ActivityEvent`.

## Alternatives considered

### Work-only planning without a preferred source

Not selected because it cannot preserve the optional concrete-source context
while keeping Work as the stable intention anchor.

### Work, Library Item and ExternalLoan as equivalent targets

Superseded because it combines planning identity with a source that may change
or disappear.

### One unique entry per Work

Not selected because separate planned readings and rereads of the same Work
must remain independently representable.

### Uniqueness on Work plus preferred source

Not selected because preferred-source content is not entry identity and fully
identical intentions are valid.

### Preferred source as reservation or binding source

Not selected because it would conflate preference, authorization and actual
ReadingRound provenance.

### Remove an entry when its preferred source disappears

Not selected because source lifecycle must not destroy or silently change the
reading intention.

### Create ReadingRound and consume Next Reading separately or asynchronously

Not selected because a partial failure could leave historical reading state and
planning state inconsistent.

### Client-only Undo by adding a new entry

Not selected because it cannot reliably restore the same server identity,
ordering context and source snapshot, and cannot provide owner-scoped one-time
semantics.

## Consequences

Positive consequences:

- reading intention and reading source are separate concepts;
- planned rereads are naturally representable;
- a preferred source can be chosen or changed later;
- source loss does not destroy planning;
- ReadingRound remains historical source truth;
- C7 can use a Work-first add flow.

Costs and complexity:

- persistence is more complex than the original target model;
- ReadingRound start requires cross-aggregate transactional orchestration;
- list mutations and consumption require stricter concurrency coordination;
- manual Undo requires temporary server-side persistence;
- the old F2.9 schema and services required a data-safe migration.

## Supersession

F2.9a and F2.9b remain historical evidence for the contract accepted and built
at that time. Their equivalent-target model, content uniqueness, immutable
target, non-consuming ReadingRound behavior and absence of server-side Undo are
not current truth.

This ADR and
[`docs/28-next-reading-contract-correction.md`](../28-next-reading-contract-correction.md)
together form the current normative architecture and contract basis for Next
Reading. C7 REST and UI are not implemented by this decision.

## References

- [Next Reading contract correction](../28-next-reading-contract-correction.md)
- [F2.9a Next Reading analysis — historical](../14-f2-9a-next-reading-analysis.md)
- [F2.9b Next Reading exit evidence — historical](../15-f2-9b-exit-evidence.md)
- [ADR-003 — Biblio Core owns domain logic](ADR-003-biblio-core-owns-domain-logic.md)
- [ADR-004 — Fase 0 persistencebaseline en concrete ReadingRound-bronnen](ADR-004-fase-0-persistence-and-reading-sources.md)
- [ADR-007 — F2.6 ReadingRound lifecycle en historische waarheid](ADR-007-f2-6-reading-round-lifecycle-and-historical-truth.md)
