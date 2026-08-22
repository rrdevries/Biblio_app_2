# 11 — F2.7b Private Notes exit evidence

Status: **GO**

Scope: Biblio V2 v2.001, complete Biblio Core implementation of the definitive
F2.7a Private Notes contract. No UI, REST, autosave, attachments, media, public
Notes, Timeline, search or unrelated domain was added.

## Git trace

- branch: `main`;
- implementation baseline: `f55957787e4b20b002a566c279fa0c3410c8b46c`;
- final repository HEAD: the commit containing this evidence and the
  implementation; its immutable hash is recorded in the final report because a
  commit cannot embed its own hash.

## Implemented Core contract

`PrivateNote` owns opaque identity, authenticated `UserId`, immutable `WorkId`,
optional `ReadingRoundId`, safe content, technical UTC creation/update instants
and a positive optimistic version. Multiple Notes per Work and per Round are
allowed. ReadingRound is optional context only and never owner.

Creation has separate `createForWork` and `createForReadingRound` entry points.
The Round path locks the owner-scoped Round and derives Work. Content update,
Round attach/change/remove and hard delete are separate services. Mutations
lock the owner-scoped Note inside one transaction, implement semantic no-op,
expected-version checking and conditional CAS/delete. Work never changes.

Get-by-ID, by-Work, by-ReadingRound and Mijn-notities projectors always include
the authenticated owner predicate. Listings use default 50, maximum 100 and
stable `updated_at DESC, private_note_id DESC` cursor pagination. Unknown and
foreign identifiers do not disclose existence. Library membership, Manager,
Owner, support or platform authority is not consulted and provides no bypass.

Only translated primary-key collisions are retried, at most three times after
the first Note-ID issuance. Create accepts neither actor nor Note ID from the
caller. Production Core exposes named application services and rendering, not
the repository, generator, content policy or generic writer.

## Content security

The server-side content policy normalizes CRLF/CR to LF, requires valid UTF-8,
rejects NUL, content without visible text and content beyond 65,535 UTF-8
bytes. It accepts only properly nested lowercase `p`, `br`, `strong`, `em`,
`ul`, `ol`, `li` and `blockquote`; no element accepts attributes.

Links, styles, classes, images, embeds, scripts, event handlers, unknown tags,
comments, malformed markup, block markers and plain-text-only input fail
validation without silent stripping. The render service applies the exact same
policy again. Consequently persisted content cannot cross the render boundary
as unchecked executable markup. Domain/application code remains independent of
WordPress presentation APIs while following the Core server-side validation
and defense-in-depth boundary.

## Schema 1003→1004

Formal baseline remains 1000 and the contiguous production chain now ends at
1004. Migration 1003→1004 creates only `wp_biblio_private_notes` with:

- binary `VARCHAR(191)` Note, User, Work and nullable Round identifiers;
- `TEXT` safe source content;
- `DATETIME(6)` creation/update timestamps;
- positive `BIGINT UNSIGNED` Note version;
- primary key and owner+Work, owner+Round and owner+updated indexes;
- Work FK `ON DELETE RESTRICT`;
- optional ReadingRound FK `ON DELETE SET NULL`;
- positive-version and update-not-before-create checks.

Application and wpdb repository both validate same-owner/same-Work Round
context. Migration accepts an absent table or an already fully healthy known
table, fails closed on unknown partial DDL, verifies the complete 1004
postcondition before the version bump and requires no data backfill.

Legitimate F2.6 hard delete of `historical_manual` ReadingRound truth preserves
linked Notes and nulls only their context. Owner, Work, content, timestamps and
Note version remain unchanged. Denied deletion leaves the link unchanged.
Deleting a Note never cascades to Work, Round or any other reading data.

## Privacy, events and concurrency

Every read and write resolves the actor server-side and queries by owner.
Cross-user and cross-Work Round relationships fail at application boundaries;
the repository repeats the relationship check before insert/CAS and real FKs
provide final existence integrity.

Independent PHP processes prove divergent update winner/stale behavior, equal
update convergence with one version increment and update-versus-delete with one
consistent outcome. No Note service has an ActivityEvent dependency, all tested
Note mutations keep the Library event count unchanged, and no Timeline or
private event engine exists.

## Evidence against the F2.7a 50-case matrix

| Cases | Evidence |
| --- | --- |
| 1–9 domain/values/content/ID | `PrivateNoteTest`, strict policy, render service and opaque generator assertions |
| 10–18 create/multiplicity/Round states | `PrivateNotePersistenceTest` covers Work-only plus active, ended and historical contexts |
| 19–27 authorization/mutations/delete/composition | owner/cross-user/cross-Work/CAS/delete and `ProductionApplicationBoundaryTest` |
| 28–36 persistence/lists/FKs/collisions | real-MariaDB roundtrip, bounded pages, defense-in-depth, multiplicity and collision tests |
| 37–40 concurrency/Round delete | `PrivateNoteConcurrencyTest` plus historical Round `SET NULL` integration |
| 41–46 migration/health/regression | fresh chain in `CoreSchemaMigrationTest`, dedicated `Schema1004PrivateNotesTest` and all earlier suites |
| 47 content/XSS | forbidden-payload matrix and render-boundary revalidation |
| 48 no global search | schema/composition contain no FULLTEXT index or Note search service |
| 49 ReadingRound regressions | complete ReadingRound unit, persistence, concurrency, lifecycle and derived-read suites |
| 50 canonical gate | complete gate result below |

## Canonical verification

Final canonical gate on 2026-08-22:

- `./scripts/test-biblio-core-all.sh`: PASS in 45 seconds;
- Composer metadata and locked platform requirements: PASS;
- PHP syntax over production and tests: PASS;
- PHPStan level 6 over production `src`: PASS, no errors;
- unit: 190 tests, 679 assertions;
- isolated real-MariaDB integration: 137 tests, 995 assertions;
- total: 327 tests, 1,674 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- manifest JSON and Git whitespace: PASS;
- the gate left the visible repository diff unchanged.

## Deviations and residual risks

There is no product-contract deviation. The implementation deliberately
rejects forbidden markup instead of silently stripping it, as specified by
F2.7a. UI/editor behavior, autosave, REST/Abilities, revisions, account erasure,
private audit/history, media, sharing and full-text search remain deferred.

## Final verdict

**GO** — O1–O3 and the complete minimal F2.7a Core contract are implemented,
schema 1004 and its reverse ReadingRound delete behavior are proven, strict
owner privacy and safe rendering hold, concurrency tests prevent lost updates,
all relevant acceptance cases pass and the complete existing regression gate
remains green. F2.7 can be closed.
