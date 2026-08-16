# ADR-003 — Biblio Core owns domain logic

Status: Accepted

## Decision

All business rules, scope resolution, authorization, lifecycle integrity and transactional application behavior belong to Biblio Core.

## Scope rules

Library-scoped:
- explicit Library Context;
- membership/role/permission authorization.

User-owned:
- authenticated user;
- ownership authorization.

An optional Library reference on user-owned data does not replace ownership.

## Examples

Core decides:
- whether a member may receive/use an Item;
- whether an active ReadingRound source is valid;
- whether an Item can be archived;
- whether an internal loan transition is valid;
- whether a Library manager may mutate a record;
- whether central metadata may be directly corrected or must become a proposal.

Elementor/JetEngine may render forms/listings but may not redefine these rules.

## Security

UI visibility is never sufficient authorization.

Direct request/API attempts must enforce the same rules.
