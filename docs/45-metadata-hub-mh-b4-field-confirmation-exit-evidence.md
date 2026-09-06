# 45 — Metadata Hub MH-B4 field confirmation exit evidence

Status: **GO / CLOSED**

Date: 2026-09-06

Task severity: **High**

## 1. Outcome

MH-B4 adds a persistent, provider-neutral field-review foundation. Schema
1015 stores current field confirmation state, exact content proposals and
deduplicated source evidence without connecting the capability to REST, UI,
Add Book or canonical catalog mutation.

The supported field keys are `title`, `subtitle`, `contributors`, `languages`,
`publishers`, `publication_date`, `page_count` and `format`. Values use bounded
deterministic JSON and SHA-256 content identity; ordered lists remain atomic.

## 2. Proven rules

- identical provider values share one proposal while retaining separate
  provider/source evidence;
- repeat observations of the same source retain first/last retrieval times and
  increment their count;
- matching canonical evidence is supporting only and never auto-confirms;
- rejection is content-specific, survives repeated evidence and leaves later
  different content independently proposable;
- explicit confirmation/manual correction is the only route to
  `user_confirmed`, supersedes other live proposals and preserves history;
- provider ingestion and unconfirmed seeding cannot downgrade explicit user
  decisions;
- intentionally blank persists, blocks proposals and needs an explicit reopen
  command before those proposals become active again;
- field mutations are serialized transactionally through a locked state row.

## 3. Schema transition

Repository schema moves from 1014 to 1015. The additive migration creates:

- `biblio_metadata_field_states`;
- `biblio_metadata_field_values`;
- `biblio_metadata_field_evidence`.

Composite restrictive foreign keys preserve state, value and evidence history.
Checks constrain supported keys/states, JSON validity and content hashes,
actor/time consistency, exact-ISBN provenance and positive counters/versions.
The migration is retry-safe for absent or known healthy structures and fails
closed on unknown partial structures. It changes no existing catalog or
DATA-01 row.

## 4. Verification

The final repository gate passed on 2026-09-06:

- Composer metadata and locked platform requirements: passed;
- PHP syntax: passed for all tracked plugin PHP; PHPStan: no errors;
- unit suite: 387 tests, 1,591 assertions, passed with the two existing
  non-failing PHPUnit notices;
- integration suite, including MariaDB schema 1015 and persistence round trip:
  317 tests, 3,880 assertions, passed;
- WordPress smoke: plugin active, class loaded, init hook count 1 and HTTP 200;
- manifest JSON and staged/unstaged whitespace checks: passed;
- complete gate duration: 140 seconds.

## 5. Explicit exclusions and residual boundary

No REST route, frontend, Elementor behavior, Add Book integration,
`CoreApplication` composition, Series Intelligence, provider cover flow,
DATA-01 change or V1 migration is included. `MetadataRecordId` is deliberately
opaque. MH-B5 must define and enforce authorized ownership/binding before this
foundation can be exposed; Core authorization may not be delegated to UI or
transport.

## 6. Review verdict

The independent second pass found no remaining requirement, architecture,
security or regression blocker after adding explicit protection against
unconfirmed-value downgrade and database enforcement of content hashes and
non-empty decision actors. Final verdict: **GO / CLOSED**.
