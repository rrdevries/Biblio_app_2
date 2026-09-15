# MIG-02-AUTH-01 — Canonical Author migration participants

Status: **GO / CLOSED**

Date: 2026-09-15

Task severity: **High**

Scope: source-neutral Author identity and Work-contributor migration
planning/apply only. No current V1 adapter, V1 field/role mapping, provider
authority mapping, apply CLI, schema or UI is included.

## 1. Pre-coding audit and decision inheritance

The audit used the current checkout and canonical Author, MIG-FND, RUN-01 and
CAT-01 contracts before production changes. `Author` is a platform-wide stable
identity; display-name equality is presentation evidence and never person
identity. Migration-created Authors without strong authority remain
`provisional` with an `observed` display name. Only `author|co_author` are
Work-level roles, and positive source positions remain exact.

Schema 1025 was sufficient. Schema 1024 already provides non-unique Author
names, migration-scoped contributor credit/evidence, and the exact
WorkContributor uniqueness boundaries. MIG-FND already provides target-scoped
logical mappings, changed-payload rejection and the outer transaction. No
provenance had to be mislabeled as `user_observation` or provider evidence.

## 2. Participant architecture

Two separate source-neutral participants are registered:

| Source type | Typed plan | Primary responsibility |
|---|---|---|
| `catalog_author` | `CatalogAuthorPlan` | stable source Author → canonical Author |
| `catalog_work_contributor` | `CatalogWorkContributorPlan` | one source occurrence → credit/evidence + WorkContributor |

The split prevents one Author occurrence on each Work from becoming a new
person identity. Plans contain only already-reviewed V2 facts and expose only
allowlisted operations/dependencies in dry-run artifacts.

## 3. Author identity plan and provisional semantics

`CatalogAuthorPlan` accepts the already-chosen display name and optionally an
explicitly approved canonical `AuthorId`. It applies the existing UTF-8
whitespace trim/collapse while preserving case, punctuation and diacritics.
It cannot request resolved identity, Librarian-confirmed presentation or a
provider claim.

A new stable source Author creates one opaque Author with
`identity_status=provisional`, `display_name_status=observed` and initial
version. Exact prior mapping or explicit approved target reuse requires the
Author still to exist and never rewrites its name/status. No Author lookup by
name exists.

## 4. Contributor occurrence and Work dependency

`CatalogWorkContributorPlan` contains exact source Author and Work references,
the already-approved `ContributorRole`, positive `ContributorPosition`, and
observed display name. Planning declares both `catalog_author` and
`catalog_work` dependencies. Apply resolves only committed mappings in the
same MIG-FND target user+Library and source-family scope, then verifies both
canonical records still exist. It performs no title/name lookup.

## 5. Roles, ordering and same-name isolation

The typed enum admits only `author` and `co_author`; no role is derived from
position. Source position is neither sorted nor renumbered. The current unique
Work+Author and Work+position boundaries remain authoritative. Exact existing
relationships may converge; an occupied position or the same Author elsewhere
on that Work fails closed without overwrite, shifting or collapse.

Different stable source Authors named alike create distinct canonical Authors.
A pre-existing same-name Author is ignored unless its exact ID is explicitly
approved in the typed plan.

## 6. Credit and migration evidence

The shared `CanonicalAuthorMaterializer` gains one narrow
`materializeMigrationAuthor()` path. Unlike its provider/name-only paths it
accepts the exact already-mapped Author, never creates or promotes an Author,
and never reads/writes a provider claim. It reuses the canonical credit-key,
evidence and edge conflict rules.

The credit uses the exact MIG-FND observation ID as its Core-derived source
identity. `AuthorCreditEvidence::migration()` records truthful migration
provenance. No raw source payload enters product tables.

## 7. Migration mappings and reconciliation support

Mappings are distinct:

- source Author identity → `author`;
- source contributor occurrence → `author_contributor_credit` using the real
  credit ID; and
- the same occurrence → `work_contributor` using a deterministic opaque
  relation key derived from Work, Author, role and position.

Every mapping reports `created|reused` truthfully. Author mapping alone is
never treated as proof that an occurrence committed.

## 8. Replay and changed payload

Within one run, MIG-FND rejects a changed duplicate source observation. Across
runs, exact source Author payload reuses the mapped Author. Exact contributor
replay first validates and reuses the prior credit and edge mappings because a
new run has a new MIG-FND observation ID; it does not create a second product
credit merely to restate the same logical occurrence. Replay also requires the
mapped credit's truthful migration evidence for the original mapped MIG-FND
observation. Any changed payload, unexpected target type, missing target,
incomplete mapping, divergent credit/evidence or missing edge fails closed.

## 9. Transaction and rollback

The writer opens no transaction and performs no retry. Author creation plus
its mapping/outcome, and contributor credit/evidence/edge plus their
mappings/outcome, run only inside `CommitMigrationRecordService`. Typed
identity/position conflicts are translated to participant failures so the
materializer's tentative unresolved evidence cannot commit. Injected Author,
credit, evidence, edge and late outcome failures leave no partial observation
graph or false mapping.

## 10. Search read proof and provider boundary

Existing local Author Search finds the migrated Author, and the existing
Author-to-Works provider returns both linked Works in canonical title order.
Before/after table counts prove those reads write nothing. Production Author
migration has no HTTP/provider client dependency and ordinary migration
creates zero provider claims.

## 11. Dry-run integration

The production RUN-01 registry now includes both Author participants beside
CAT. A synthetic typed adapter proves deterministic plans, explicit dual
dependencies, payload-free artifacts and zero changes to every Core/MIG-FND
table. Production still registers no current V1 adapter and exposes no apply
command.

## 12. Explicitly deferred and actual V1 rule

No current V1 parser/export mapping, ID-less Author grouping, source role
mapping, provider authority mapping, merge/reconciliation, Librarian UI,
Edition contributor system, Series, ReadingRounds, Notes, Wishlist, loans,
archive, full reconciliation, trial or cutover is included.

No `/data/`, `.local/fixture-source`, MIG-01, DATA-01, historical ZIP or current
V1 export was read or requested. Tests use synthetic typed plans only. A later
explicitly designated current export remains the sole source for V1 inventory
and reviewed source-to-target mapping.

## 13. Verification

- focused Author participant integration: 8 tests / 85 assertions;
- focused shared Author materializer integration: 30 tests / 177 assertions;
- focused RUN-01 Author/CAT dry-run integration: 3 tests / 25 assertions;
- production PHPStan: green;
- complete unit suite: 691 tests / 2750 assertions / 2 PHPUnit notices;
- complete integration suite: 534 tests / 6127 assertions;
- complete Core gate: green in 523 seconds, including syntax,
  Composer/platform, WordPress smoke, manifest and whitespace; and
- two independent read-only review passes: no remaining blocker.

No browser/E2E or human UI acceptance is required because no frontend changed.

## 14. Versions and Git

- Product: `v2.001` unchanged.
- Schema: `1025` unchanged.
- Biblio Core: `2.29.0 -> 2.30.0`.
- Biblio UI: `0.20.0` unchanged.

Start HEAD: `cfc9fa3ad6e453d3a5852989fe730b4f3273e033` on clean local
`main`, initially 40 commits ahead of `origin/main`. Closure uses exactly one
local implementation commit. Nothing is pushed.
