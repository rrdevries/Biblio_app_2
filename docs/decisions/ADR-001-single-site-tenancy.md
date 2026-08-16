# ADR-001 — Single-site tenancy

Status: Accepted

## Decision

Biblio V2 runs in one WordPress site.

`Bibliotheek` is an internal tenant/domain entity.

WordPress Multisite is not the tenancy model.

## Consequences

- one platform account can belong to zero/one/multiple Libraries;
- Library Context must be explicit for Library-scoped operations;
- tenant isolation is a Biblio Core responsibility;
- a platform account/Mijn Biblio can initially exist without Library membership;
- v2.001 auto-creates one designated personal Privébibliotheek on the first relevant reading/borrowing action if absent;
- that personal Library is an authorization/collection anchor and does not own the user's private Mijn Biblio data;
- user-owned records are not forced beneath a Library tenant;
- a Library reference on user-owned data is optional context where functionally relevant;
- WordPress roles alone cannot represent Biblio Library authorization.

## Supersedes

Earlier Multisite design assumptions.
