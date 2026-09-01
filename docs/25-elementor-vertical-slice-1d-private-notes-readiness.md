# Elementor Vertical Slice 1D — Private Notes Readiness

Date: 2026-08-31
Readiness verdict: **GO for 1D.5**

Current implementation status: **1D.4 PRIVATE NOTES UI COMPLETE — GO**

## 1. Authority, baseline and scope

This document formalizes only the readiness, existing Core capabilities,
missing deltas and binding product/architecture contracts for Private Notes on
Item detail. It introduces no product behavior.

- repository: `/Users/renee/Documents/Websites/Biblio_app_2`;
- branch at start: `main`;
- start HEAD: `6e7273840f58f9bb1d74bef2644799a227033483`;
- `origin/main` at start:
  `6e7273840f58f9bb1d74bef2644799a227033483`;
- start worktree: clean;
- baseline subject: `Close Reading history vertical slice`;
- Vertical Slices 1A, 1B and 1C: formal GO;
- documentation number 25 was free.

1D.2 started from a second clean pushed baseline:

- branch: `main`;
- start HEAD and `origin/main`:
  `4985b55065f7fe180965f4f4974243aceee4c497`;
- start worktree: clean;
- baseline subject: `Analyze Private Notes vertical slice`.

1D.3 started from a third clean pushed baseline:

- branch: `main`;
- start HEAD and `origin/main`:
  `169d51d5ba8cd84b344fd00f29361e10ddbf94de`;
- start worktree: clean;
- baseline subject: `Prepare Private Notes read boundary`.

The current checkout is the technical source of truth. Binding sources are
`docs/00-current-state.md`, the relevant parts of
`docs/01-functional-design.md`, `docs/02-architecture.md`,
`docs/03-scope-and-deferred.md`, `docs/06-testing-and-acceptance.md`, ADR-002,
ADR-003, ADR-004, ADR-005 and ADR-007, plus the F2.7 records in docs 10 and 11.

Explicit non-scope for 1D.1:

- product code, Core implementation or a new read model;
- REST routes, request parsing, serialization or error mapping;
- Biblio UI, Elementor or Crocoblock behavior;
- E2E/Playwright fixtures or scenarios;
- schema, migration, data mutation or new functionality.

## 2. Repository audit

### 2.1 Documentation and Git history

| Evidence | Existing truth |
|---|---|
| `docs/10-f2-7a-private-notes-analysis.md` | Binding F2.7a contract, decisions O1–O3, schema/application/test plan and GO for F2.7b. |
| `docs/11-f2-7b-exit-evidence.md` | Implemented Core/schema/security/concurrency evidence and formal GO for F2.7. |
| Current state §F2.7 | Concise current implemented contract. |
| Functional design §12 Notes | Always private, multiple Notes per Work, optional ReadingRound, no Library-role access. |
| Architecture §19 | Aggregate, application, persistence and render boundary. |
| Acceptance §33 | The implemented 50-case F2.7 matrix. |
| `6586803610952d8314cf57dc898fee050a83ef53` | `Document F2.7a Private Notes analysis`. |
| `f55957787e4b20b002a566c279fa0c3410c8b46c` | `Finalize F2.7a Private Notes decisions`. |
| `43675de3c80df4501c3ec42a7bcfdd55ff4c8eae` | `Implement F2.7b Private Notes core`. |
| `3f615292dd66b0e05e18145af930a2c874ad7335` | `Document F2.7b Private Notes exit`. |

### 2.2 Production Core inventory

Domain and ports already exist under `src/Notes`:

- `PrivateNote`, `PrivateNoteId`, `PrivateNoteContent` and
  `PrivateNoteVersion`;
- `PrivateNotePage` and `PrivateNotePageRequest`;
- `PrivateNoteContentPolicy` and `StrictPrivateNoteContentPolicy`;
- `PrivateNoteClock`, `PrivateNoteIdGenerator`;
- `PrivateNoteRepository` and `WritablePrivateNoteRepository`;
- typed Note failures for unavailable, stale, unavailable ReadingRound context,
  ID collision and exhausted ID collision retries.

Named application boundaries already exist under `src/Application/Notes`:

- create for Work and create for ReadingRound;
- update content;
- correct/remove ReadingRound context;
- conditional hard delete;
- get by Note ID;
- list by Work, by ReadingRound and all my Notes;
- safe content rendering.

Production wiring exists in `ProductionComposition` and `CoreApplication`.
`WpdbPrivateNoteRepository`, `OpaquePrivateNoteIdGenerator` and
`SystemPrivateNoteClock` are production adapters.

### 2.3 Persistence, schema and tests

Schema migration `1003→1004` introduced `wp_biblio_private_notes`. The current
ordered schema chain ends at 1007; later migrations preserve the Note table.
Schema health includes the Note table, columns, indexes, foreign keys and
checks.

Existing focused tests are:

- `tests/Unit/PrivateNoteTest.php`;
- `tests/Integration/PrivateNotePersistenceTest.php`;
- `tests/Integration/PrivateNoteConcurrencyTest.php`;
- `tests/Integration/Schema1004PrivateNotesTest.php`;
- Note assertions in `CoreSchemaMigrationTest`,
  `ProductionApplicationBoundaryTest`, lifecycle/health tests and cleanup.

They prove content safety, immutable identity/owner/Work/creation time,
multiplicity, optional Round context, all Round states, owner isolation,
Library-role non-bypass, semantic no-op, CAS, hard delete, Round `SET NULL`,
bounded ID retry, stable pagination, real-process concurrency and zero Library
ActivityEvent writes.

### 2.4 REST, Biblio UI and Elementor audit

At the 1D.1/1D.2 audit there were no Private Note REST routes, request parsers,
response serializers or Note-specific REST error mappings. 1D.3 has now added
the exact transport boundary recorded in §§13 and 28. The namespace remains
`biblio/v1` with cookie authentication, WordPress `wp_rest` nonce use, typed
request parsing, explicit response allowlists and safe error mapping.

There is no Private Note code in Biblio UI. Item detail currently renders, in
order, the Work heading, `Lezen`, Start/End controls, `Leesgeschiedenis`,
`Uitgave` and `Exemplaar`.

The existing native `<dialog>` pattern provides labelled modal interaction,
pending locks, field/status association, Escape handling, focus restoration,
mobile bottom-sheet layout and compact tablet/desktop presentation. It is a
valid implementation reference for Note deletion and dirty-state confirmation,
but no generic dialog component currently exists.

Elementor remains one ordinary Page/container/Shortcode/mount shell. No
Private Note business state or CRUD belongs in Elementor. Crocoblock has no
existing Note role.

## 3. F2.7 status and decisions

### 3.1 F2.7a

F2.7a was a repository and product-contract analysis. It added documentation
and manifest registration only, made no production/schema/test change, fixed
decisions O1–O3 and ended **GO for F2.7b**.

The locked decisions were:

1. **O1 content:** normalized strict safe HTML, exact fixed allowlist, no
   attributes/links/media/scripts/styles/arbitrary HTML and defense-in-depth
   render validation.
2. **O2 Round context:** zero or one optional, correctable own same-Work
   ReadingRound; every lifecycle/provenance state is eligible; multiple Notes
   may share a Round; Work remains immutable.
3. **O3 deletion:** owner-scoped version-conditional hard delete; no
   tombstone/audit; deleting an eligible linked ReadingRound preserves the
   Note and nulls only its context.

F2.7a also locked multiple Notes per User + Work. This is not an open 1D
choice.

### 3.2 F2.7b

F2.7b implemented the complete minimal Core contract, migration 1004,
persistence, composition and tests. It added no REST, UI, autosave, revisions,
media, sharing, search, Timeline or private audit history.

F2.7b ended **GO**, and F2.7 is formally closed. Open points were deliberately
deferred, not omitted accidentally:

- REST/Abilities and adapter DTOs;
- editor/UI behavior, save/delete copy and confirmation component;
- autosave, offline drafts and revisions;
- images, attachments, blocks and full-text search;
- import/export, account erasure and private mutation history.

## 4. Binding product-contract comparison

The 1D.1 recommendations for multi-Note presentation, discoverable zero state,
dirty-state behavior and accessible hard-delete confirmation are now explicitly
**LOCKED**. They are no longer implementation conditions or open design
questions. Dirty-state and delete-dialog behavior remain later UI scope and are
not implemented by 1D.2.

| Requested starting point | Repository verdict |
|---|---|
| User-owned and always private | **LOCKED EXISTING**. |
| Owner/Manager cannot access another user's Notes | **LOCKED EXISTING**. |
| Library Context grants no Note rights | **LOCKED EXISTING**. |
| Manual save; no autosave | Manual save is the requested 1D contract; autosave was explicitly deferred by F2.7. |
| Simple formatting | **LOCKED EXISTING**, exact safe HTML subset in §9. |
| Delete requires confirmation | Binding 1D UI requirement; confirmation is not a Core field or invariant. |
| Not reviews; never implicitly published | **LOCKED EXISTING**. There is no visibility/publication state. |
| Work relationship | **LOCKED EXISTING**, but each Note is a separate Work-linked aggregate. |
| No ActivityEvent/private Library audit | **LOCKED EXISTING**. |
| Server-side authorization | **LOCKED EXISTING**. |
| One Note per User + Work | **CONFLICTS WITH F2.7** if inferred. Multiple Notes are explicitly allowed. |
| Singular Work-note `PUT` resource | **CONFLICTS WITH F2.7** unless it represents a collection/member operation. |

The required correction is therefore not to redesign F2.7. Vertical Slice 1D
must preserve a Work-wide **collection of independently identified Notes**.

## 5. Current domain model

### 5.1 Aggregate and identity

The aggregate is `PrivateNote`; the value objects are `PrivateNoteId`,
`PrivateNoteContent` and `PrivateNoteVersion` plus shared `UserId`, `WorkId`
and optional `ReadingRoundId`.

The primary identity is the opaque server-issued `PrivateNoteId`, not the
User + Work pair. IDs use `private-note-` plus 128 random bits in production,
fit the binary `VARCHAR(191)` identifier contract and are never client-issued.

### 5.2 Fields and mutability

| Field | Type | Mutable? | Contract strength |
|---|---|---:|---|
| `private_note_id` | `PrivateNoteId` | No | Explicit code/docs/schema. |
| `user_id` | `UserId` | No | Explicit owner contract. |
| `work_id` | `WorkId` | No | Explicit Work-wide contract. |
| `reading_round_id` | `?ReadingRoundId` | Yes | Explicit optional context; may attach/change/remove. |
| `note_content` | `PrivateNoteContent` | Yes | Explicit safe HTML content. |
| `created_at` | UTC `DateTimeImmutable` | No | Technical persistence instant. |
| `updated_at` | UTC `DateTimeImmutable` | Yes on real Note edit | Technical persistence/CAS ordering instant. |
| `note_version` | positive integer | Yes, +1 per real edit | Explicit optimistic concurrency. |

There is no title, content-format field, privacy/visibility status, Library ID,
Item ID, Edition ID, provenance, soft-delete flag, deleted timestamp, revision
history, rendered cache, excerpt or search document.

Privacy is inherent. The only stored source is normalized safe HTML. There is
no empty persisted draft: content without visible text is invalid. “No Note”
means no row; hard-deleted means no row. Multiple rows per User + Work and per
User + ReadingRound are allowed.

## 6. Ownership and authorization

The authenticated current user may read, create, modify and delete only their
own Notes. Every named service resolves `UserId` from `AuthenticatedUser`.
No public signature accepts a caller-controlled actor or owner ID.

Reads use an exact `user_id` predicate. Mutations use a locked owner-scoped
read and owner/version-predicated write/delete inside one transaction.
Unknown, already deleted and foreign Note IDs use the same
`private_note_not_available` Core outcome.

Library Owner, Manager, member, support or platform authority has no override.
Library Context, membership and Library capability are absent from Note
services and persistence authorization.

Loss of Library access can make a particular Item-detail route unavailable;
it does not delete, transfer or otherwise mutate personal Notes. When the user
can reach another Item or Edition for the same Work, their same Work-scoped
Note collection remains the applicable collection. A Library reference on
another record never transfers Note ownership.

## 7. Work versus Item/Edition verdict

**VERDICT: A — WORK-WIDE, WITH MULTIPLE NOTES.**

Every Note has immutable User + Work scope. Item, Edition and Library IDs do
not exist in the aggregate or table. ReadingRound is optional context and does
not change the Work scope.

Consequences on Item detail:

- every accessible Item whose detail resolves the same `work_id` must load the
  same actor-owned Note collection;
- another Edition of the same Work must load that same collection;
- no Note may be copied or duplicated per Item/Edition;
- creating a new Note intentionally adds a new Note aggregate; it does not
  upsert “the Work note”;
- ordering is stable newest-updated first, not Item-specific.

## 8. Lifecycle and CRUD semantics

### 8.1 Create

A Note is created on explicit first successful Save. There is no persisted
empty draft and no autosave. `createForWork(WorkId, string)` resolves the
actor, validates/normalizes content, verifies Work existence, obtains an
opaque ID and inserts version 1 with equal creation/update timestamps.

Create is non-idempotent by domain contract because multiple Notes per Work
are legal. The UI must prevent double submit and must not automatically retry
an outcome-unknown POST.

### 8.2 Read and no-Note

`get(id)` returns the owned Note or `null`. Work-list read returns a bounded
page; no matching own rows returns an empty page. Unknown or foreign Round
list scope also returns an empty page. The existing Work-list service does not
validate Work existence and therefore naturally supports non-enumerating empty
semantics.

### 8.3 Update/manual Save

Content Save supplies Note ID, expected version and the full desired HTML
source. After normalization:

- identical desired content returns current state, even with a stale expected
  version, with no write/version/timestamp change;
- divergent content with current version performs one CAS write and increments
  version once;
- divergent stale intent throws `PrivateNoteStale` carrying safe current
  state;
- a successful response is authoritative Core state, but UI should reconcile
  ambiguous/stale outcomes by owner-scoped reread.

This is semantic no-op behavior, not last-write-wins.

### 8.4 Delete

Delete accepts Note ID and expected version, locks owned current state and
conditionally hard deletes by ID + owner + version. A stale version conflicts;
unknown, foreign, already-deleted and repeated delete are not-available. Delete
removes only the Note row and has no version/timestamp aftermath or tombstone.

Confirmation is a UI precondition only. Core deliberately has no confirmation
boolean. An outcome-unknown DELETE must be reconciled by GET and not blindly
retried.

## 9. Text and formatting contract

The format is **normalized strict safe HTML**, not plain text, Markdown,
arbitrary WordPress rich text or block JSON.

Allowed lowercase elements, with zero attributes:

- `p`, `br`;
- `strong`, `em`;
- `ul`, `ol`, `li`;
- `blockquote`.

Links, attributes, classes, styles, images, media, embeds, comments, scripts,
SVG, forms, event handlers, block markers and unknown/malformed markup are
rejected. They are not silently stripped.

Core normalizes CRLF and CR to LF; requires valid UTF-8; rejects NUL, content
without visible text and content over **65,535 UTF-8 bytes**. Plain-text-only
input is invalid because the canonical stored representation requires safe
HTML markup. Newline bytes are preserved after normalization, but the editor
must express paragraphs/line breaks through the allowed HTML representation.

`StrictPrivateNoteContentPolicy` enforces the contract on create/update and
again during repository hydration. `RenderPrivateNoteContentService` repeats
the same validation before HTML crosses the render boundary. A future REST
serializer must use render-validated content; Biblio UI may insert it as HTML
only after strict response-shape validation and must never echo raw database or
request content. Any plain-text presentation uses `textContent`.

**Formatting readiness condition:** 1D.4 must choose or implement an editor
adapter that deterministically emits exactly this subset. A generic WordPress
rich editor/contenteditable output cannot be trusted without a constrained
serializer and tests.

## 10. Persistence verdict

**VERDICT: SCHEMA READY. No schema delta is required for 1D.**

Table: `wp_biblio_private_notes` (prefix-aware through `CoreTableNames`).

Columns:

`private_note_id`, `user_id`, `work_id`, nullable `reading_round_id`,
`note_content`, `created_at`, `updated_at`, `note_version`.

Keys and constraints:

- primary key: `private_note_id`;
- index `private_notes_by_user_work`
  (`user_id`, `work_id`, `updated_at`, `private_note_id`);
- index `private_notes_by_user_round`
  (`user_id`, `reading_round_id`, `updated_at`, `private_note_id`);
- index `private_notes_by_user_updated`
  (`user_id`, `updated_at`, `private_note_id`);
- Work FK with `ON DELETE RESTRICT`;
- ReadingRound FK with `ON DELETE SET NULL`;
- `note_version >= 1` and `updated_at >= created_at` checks;
- no User + Work or User + Round uniqueness;
- no FULLTEXT index and no user FK, consistent with current Core convention.

The owner+Work index directly supports Item-detail lookup. Queries are bounded
by `PrivateNotePageRequest` (default 50, minimum 1, maximum 100), use keyset
cursor `(updated_at, private_note_id)` and fetch limit + 1. Update/delete use
locked reads and CAS. No Library scope or schema change is needed.

## 11. Existing Core capabilities and delta

| Capability | Existing? | Current signature/output | Complete for 1D? | Delta/reason |
|---|---:|---|---:|---|
| Read one | Yes | `get(PrivateNoteId): ?PrivateNote` | Yes internally | No separate member GET required for minimal 1D. |
| Read Work collection | Yes | `forWork(WorkId, ?PrivateNotePageRequest): PrivateNoteViewPage` | Yes | New minimal adapter-facing safe view boundary. |
| Create | Yes | `createForWork(WorkId, string): PrivateNote` | Yes | REST POST implemented in 1D.3. |
| Create for Round | Yes | `createForReadingRound(ReadingRoundId, string): PrivateNote` | Not needed | Keep outside minimal Item-detail 1D. |
| Update content | Yes | `update(PrivateNoteId, PrivateNoteVersion, string): PrivateNote` | Yes | REST PATCH implemented in 1D.3. |
| Correct Round | Yes | `correct(PrivateNoteId, PrivateNoteVersion, ?ReadingRoundId): PrivateNote` | Not needed | No 1D UI requirement; do not expose accidentally. |
| Delete | Yes | `delete(PrivateNoteId, PrivateNoteVersion): void` | Yes | REST DELETE implemented in 1D.3. |
| Safe render | Yes | `render(PrivateNote): string` | Yes | New projector calls it for every returned Note. |
| Pagination | Yes | Existing request plus `PrivateNoteViewCursor` result | Yes | Versioned opaque REST encoding implemented in 1D.3. |
| Owner authorization | Yes | Server actor + owner predicates | Yes | REST cookie/nonce boundary implemented in 1D.3. |
| Stale conflict | Yes | `PrivateNoteStale(current)` | Yes | REST 409 implemented; UI reread remains 1D.4. |

No domain, repository, migration or schema implementation was needed in 1D.2.
The existing list service remained valid F2.7 behavior, but it returned full
aggregates with source content and only `hasMore`. That was not a direct safe
adapter projection. 1D.2 therefore adds only the minimal view/page/cursor
projection and composition wiring described below.

## 12. Implemented adapter-facing read boundary

Current Core has a bounded owner-scoped Work page, but no REST-ready immutable
Note DTO and no transport cursor. The catalog Item-detail projection contains
no Notes and must remain unchanged. Embedding private Notes in the general
Library-scoped detail response would couple private owner data to catalog
caching, make independent failure/pagination awkward and blur Library versus
owner boundaries.

`GetMyPrivateNotesForWorkService::forWork(WorkId,
?PrivateNotePageRequest): PrivateNoteViewPage` resolves the actor through
`AuthenticatedUser`, executes the existing owner+Work repository page and
projects every aggregate through `RenderPrivateNoteContentService`.

New application/read types:

- `PrivateNoteView`: Note ID, render-validated HTML and version only;
- `PrivateNoteViewPage`: list of views plus nullable next cursor;
- `PrivateNoteViewCursor`: internal `beforeUpdatedAt` + `beforeId` continuation;
- `GetMyPrivateNotesForWorkService`: the single named adapter boundary.

Each view contains only:

- Note ID, because update/delete address an individual aggregate;
- render-validated `content_html`;
- version, because update/delete require optimistic concurrency.

The Work is supplied once to the application call. Owner/User, Work per item,
Library, Item, Edition,
ReadingRound context, technical timestamps and internal cursor keys are not
present in a Note view. The internal next cursor contains the existing ordering
keys only; 1D.3 encodes them into a versioned opaque transport token.

Note ID is necessary and safe as opaque domain identity for later PATCH/DELETE.
It grants no authority: existing member mutations independently resolve the
current actor and use owner-scoped locked reads.

Safe-render proof:

- repository hydration first applies the existing strict content policy;
- the projector then calls the existing render boundary for every Note;
- the view constructor is private and its only factory requires the existing
  render service, so raw repository or request content has no construction path;
- unsafe hydrated content fails closed as `persistence_read_failed`;
- a compromised in-memory aggregate also fails the render policy and produces
  no view;
- no second sanitizer or expanded allowlist exists.

Ordering and pagination are unchanged: `updated_at DESC`, then
`private_note_id DESC`; default page size 50, maximum 100; keyset predicate
`updated_at < cursor_time OR (updated_at = cursor_time AND private_note_id <
cursor_id)`; repository fetches `limit + 1`. The next cursor is derived from
the last returned aggregate only when another row exists. With a fixed dataset,
ties, continuation and multiple pages are deterministic without duplicates or
skips. 1D.2 adds no snapshot-isolation promise across concurrent edits.

The Work-list remains one bounded SQL query per page. Projection and rendering
are in-memory and issue no per-Note query; therefore there is no N+1.

## 13. Implemented REST contract

### 13.1 Adapter baseline

The existing owner-scoped `/me/...` convention, strict JSON parsing, explicit
allowlists, cookie authentication plus `X-WP-Nonce` and central safe error
mapping are reused unchanged. 1D.3 adds no alternative authentication or
authorization layer.

### 13.2 Rejected singular variant

The proposed singular resource:

`GET|PUT|DELETE /me/works/{work_id}/note`

is incompatible with the locked multiple-Notes contract. `PUT` could not
identify which Note to update and would incorrectly imply User + Work
uniqueness or upsert semantics.

### 13.3 Exact routes

Implemented minimal 1D routes:

```text
GET    /biblio/v1/me/works/{work_id}/private-notes
POST   /biblio/v1/me/works/{work_id}/private-notes
PATCH  /biblio/v1/me/private-notes/{private_note_id}
DELETE /biblio/v1/me/private-notes/{private_note_id}
```

`GET` is the read/zero-state collection; `POST` creates another aggregate;
`PATCH` changes only content on the addressed Note; `DELETE` removes that
Note. Round-context correction is outside minimal 1D and gets no route.

GET query:

- optional `cursor`, versioned and opaque;
- optional `limit`, Core default 50 and maximum 100;
- unknown query fields fail 400.

Requests:

```json
POST { "content": "<p>Mijn notitie</p>" }
PATCH { "content": "<p>Gewijzigd</p>", "expected_version": 1 }
DELETE { "expected_version": 2 }
```

All bodies reject unknown/missing/wrongly typed fields. No request accepts
`user_id`, owner, `library_id`, Item/Edition ID, Note ID on create,
`reading_round_id`, visibility or audit data.

Collection success allowlist:

```json
{
  "data": {
    "items": [
      {
        "private_note_id": "private-note-opaque",
        "content_html": "<p>Mijn notitie</p>",
        "version": 1
      }
    ],
    "next_cursor": null
  }
}
```

POST returns 201 with one Note object. PATCH returns 200 with the authoritative
Core result. DELETE requires the exact JSON body `{ "expected_version": N }`
and returns 204 with no body. The response never returns User/owner, Library,
Item, Edition, ReadingRound, technical timestamps or raw unvalidated content.

POST/PATCH success envelope:

```json
{
  "data": {
    "private_note_id": "private-note-opaque",
    "content_html": "<p>Mijn notitie</p>",
    "version": 1
  }
}
```

DELETE success has status 204 and no JSON envelope or deleted content.

## 14. Missing, non-enumeration and error model

For a valid or unknown well-formed Work with no matching own Notes, GET returns
200 with `items: []` and `next_cursor: null`. This matches the existing
Core Work-list behavior and reveals neither whether another user has Notes nor
their IDs. Item detail already supplies a validated accessible Work ID.

Individual PATCH/DELETE of unknown, foreign or already-deleted Notes returns
the same generic 404. Library membership and Item/source details are never
consulted or disclosed by the Note route.

| Condition | Source | HTTP contract |
|---|---|---:|
| Unauthenticated | WordPress/Core authentication | 401 existing safe error. |
| Invalid/missing nonce | WordPress REST cookie auth | 403 standard WordPress response. |
| Malformed Work/Note ID | strict request parser | 400. |
| Unknown fields/wrong body types | strict request parser | 400. |
| Malformed/non-UTF-8 JSON document | WordPress JSON transport | 400 standard `rest_invalid_json`. |
| Invalid/empty/forbidden/too-long content | `ValidationFailed` | 422 generic validation. |
| Unknown/foreign/already-deleted Note | `PrivateNoteNotAvailable` | 404 generic resource unavailable. |
| Round unavailable | existing typed failure, not exposed by minimal 1D | 404 if ever mapped. |
| Divergent stale content/delete | `PrivateNoteStale` | 409 named conflict; do not serialize raw exception. |
| Semantic/stale no-op update | Core success | 200 current safe Note. |
| Core unavailable | application provider | 503. |
| Persistence/transaction/unexpected | generic safe mapper + server log | 500. |

`RestErrorMapper` maps `PrivateNoteNotAvailable` to the existing generic 404
and `PrivateNoteStale` to `biblio_private_note_stale`/409. A 409 body does not
embed current Note state. UI must perform an authoritative GET reread and show
the conflict without automatically retrying the mutation.

**Non-enumeration verdict: PROVEN.** Collection reads reapply actor+Work scope
on every page; a cursor from another actor or Work grants no data. Unknown,
foreign and deleted member IDs have byte-for-byte equivalent public 404 data.

## 15. Item-detail integration and zero state

Recommended information hierarchy:

1. `Lezen` summary and Start/End actions;
2. `Leesgeschiedenis`;
3. `Privénotities`;
4. `Uitgave`;
5. `Exemplaar`.

This groups actor-owned reading context before bibliographic/physical metadata
without putting Notes inside `Lezen` lifecycle state. Notes load independently;
a Note failure must not replace an otherwise valid Item detail or history.

Because multiple Notes are locked, use H2 **“Privénotities”**, not a singular
resource heading. Each Note is a list/card member with its own `Bewerken` and
`Verwijderen` controls. `Notitie toevoegen` remains available after existing
Notes; it does not disappear after the first create.

Zero state recommendation: always render the discoverable section with concise
private copy and `Notitie toevoegen`. Do not hide the entire capability until
a Note exists.

Status: **LOCKED**. The wording, hierarchy, visible multi-Note presentation and
discoverable zero state are approved; implementation remains 1D.4 scope.

## 16. Edit UX contract

### 16.1 MUST for 1D

- read state: render-validated body plus per-Note `Bewerken` and
  `Verwijderen`;
- empty/add state: `Notitie toevoegen` opens a new unsaved editor;
- edit state: visibly labelled constrained editor, `Opslaan`, `Annuleren`;
- manual Save only; no autosave or persisted empty draft;
- one pending create/update per editor; disable conflicting controls and expose
  `aria-busy`;
- Save sends the full normalized desired content and current version;
- success replaces the member from authoritative response or reread;
- validation keeps user input and associates a safe error with the editor;
- 409, 404, invalid nonce or outcome uncertainty never silently overwrites or
  automatically retries;
- concurrent delete/update actions on the same Note are locked in the UI;
- cancel with clean state closes immediately and restores focus;
- cancel, in-app navigation or browser unload with dirty state warns before
  discarding input;
- after a stale conflict, preserve the user's unsaved source locally, load
  current server state and require an explicit next decision.

The app router needs an in-app dirty-state guard. `beforeunload` may be a
fallback for full page unload only; it is not an accessible in-app dialog.

### 16.2 Future enhancements

Autosave, server drafts, offline editing, revisions/diff merge, keyboard
shortcuts beyond native/editor behavior, full-screen editing, attachments,
Markdown, links and block editing remain future work.

## 17. Delete UX contract

Use the existing accessible native `<dialog>` pattern, specialized for Note
deletion. Native `window.confirm()` is not recommended because it cannot reuse
the established focus, pending, responsive, recovery and accessible naming
contract.

Minimum behavior:

- explicit title and consequence text identifying only the user's own Note;
- safe default focus on `Annuleren`, with a clearly styled destructive
  `Verwijderen` button;
- Escape and Cancel close only while idle and restore focus to the invoking
  Delete button;
- pending DELETE disables both actions, blocks dismissal and exposes busy
  state;
- success removes the member, announces success and moves focus to the next
  logical Note, section H2 or `Notitie toevoegen`;
- cancel changes nothing;
- 409/404/uncertain outcome performs an authoritative collection reread before
  declaring success or enabling another destructive attempt;
- recoverable 500/503 keeps safe status and offers explicit retry only when the
  previous outcome is known not to have succeeded.

On mobile the existing bottom-sheet dialog pattern applies. Tablet/desktop use
the compact centered dialog.

## 18. Concurrency verdict

**Optimistic concurrency is fully implemented in Core.**

For tabs A and B reading version N:

- if B saves different content first, version becomes N+1;
- A submitting different old intent receives `PrivateNoteStale`, never a
  silent last-write-wins overwrite;
- A submitting content equal to current server content is a stale semantic
  no-op success;
- update/delete races have one consistent winner;
- delete with stale version conflicts before deletion.

1D.3 exposes Note version and maps stale to 409. 1D.4 must reconcile by GET,
retain unsaved local intent and require an explicit user action. This is a
normal adapter/UI delta, not a Core blocker.

## 19. Security verdict

**CORE SECURITY READINESS: READY.**

Core, schema and REST transport are ready. Remaining conditions are editor/UI
implementation conditions:

| Risk | Required control |
|---|---|
| Stored/reflected XSS | Exact Core safe-HTML policy, render revalidation, strict DTO validation, no raw request/DB echo. |
| Rich-editor markup drift | Constrained deterministic subset serializer plus payload matrix. |
| CSRF | Cookie auth plus valid `X-WP-Nonce`; no mutation retry after nonce failure. |
| IDOR/cross-user access | Server actor only, owner predicates, generic 404. |
| Parameter tampering | Strict IDs, types, unknown-field rejection; no client user/Library authority. |
| Note-ID enumeration | Opaque IDs plus owner predicate; no foreign existence distinction. |
| Library-role escalation | No Library Context or role dependency. |
| Stale overwrite | Expected version, 409 and authoritative reread. |
| Duplicate create | Pending lock; POST is not automatically retried. |
| Destructive delete | Accessible confirmation, expected version, pending lock and reconciliation. |

No safe implementation may broaden the HTML allowlist in the browser, trust
UI visibility as authorization or map Notes through general Library audit/
catalog caches.

## 20. Activity, architecture and component ownership

Private Note create/update/delete writes no Library `ActivityEvent`. There is
no private mutation audit, Note Timeline projection or Note eventstore. This is
the intended architecture; 1D adds no audit feature.

Layer ownership:

- **Core:** Note truth, actor ownership, Work scope, content validation,
  concurrency, persistence and typed failures;
- **WordPress REST:** cookie/nonce boundary, strict parsing, dispatch, exact
  response allowlist, opaque cursor and safe error translation;
- **Biblio UI:** loading/list/editor/dirty/pending/error/dialog/focus state and
  REST interaction;
- **Elementor:** unchanged Page shell only;
- **Crocoblock:** no role and no need for 1D.

Direct Elementor, Code Snippets, JetFormBuilder or JetEngine writes would
bypass owner/content/CAS invariants and are prohibited by ADR-003/ADR-004.

## 21. Accessibility and responsive baseline

Accessibility baseline for later UI:

- one H2 `Privénotities`;
- semantic list when multiple Notes exist;
- visible editor label and instruction for supported formatting;
- native Save/Cancel/Edit/Delete buttons with ≥44 px targets;
- field error IDs wired through `aria-describedby` and `aria-invalid`;
- scoped polite status and `aria-busy` during reads/mutations;
- accessible named/ described delete and dirty-state dialogs;
- idle Escape/cancel, pending dismissal lock and opener focus return;
- keyboard-operable formatting controls and no color-only state;
- logical focus after create/update/delete/reconciliation;
- no initial passive focus movement.

Responsive baseline:

- mobile `<768`, tablet `768–1023`, desktop `>=1024`;
- one-column Note list/editor at all sizes;
- full available editor width, wrapping safe HTML and no horizontal scroll;
- action buttons remain usable and may stack/full-width on mobile;
- mobile confirmation uses the existing bottom-sheet contract;
- tablet/desktop use the compact centered dialog;
- no new design language, motion or fixed-width content dependency.

## 22. Future 1D.6 E2E matrix

The future guarded browser/API matrix must prove at least:

1. always-visible zero state and add CTA;
2. create on first manual Save, with no pre-save row;
3. reload persistence;
4. edit an existing Note;
5. manual Save only/no autosave network write;
6. clean and dirty Cancel behavior;
7. delete confirmation open;
8. delete cancel preserves Note;
9. delete success and focus recovery;
10. foreign actor cannot list/read/update/delete;
11. Library Owner/Manager has no override;
12. same Work through another Item shows the same collection;
13. another Edition of the same Work shows the same collection;
14. multiple Notes on one Work remain distinct and ordered;
15. invalid nonce on create/update/delete;
16. create/update failure preserves input and supports safe recovery;
17. delete failure/reconciliation;
18. divergent stale 409 without overwrite;
19. stale semantic no-op success;
20. double-submit protection and one POST/PATCH/DELETE maximum;
21. mobile, tablet and desktop layout/no overflow;
22. keyboard, dialog naming, focus return, status and 44 px targets;
23. exact safe-HTML allowlist plus forbidden stored-XSS payloads;
24. non-enumerating empty Work list and foreign Note member error;
25. guarded setup, double cleanup, zero residue and unchanged non-fixture
    fingerprint.

No E2E or fixture work is part of 1D.1.

## 23. Concrete 1D build plan

| Step | Exact goal/scope | Dependencies | Exit criteria | Expected executor | Main risk |
|---|---|---|---|---|---|
| 1D.1 | **Complete.** Audit and formalize existing contract/delta only. | Clean pushed 1C baseline. | This document, explicit verdict/conditions, docs gates. | Analysis/documentation. | Reopening F2.7 decisions. |
| 1D.2 | **Complete.** Minimal adapter-facing Work Note page DTO/cursor/wiring; no domain/schema change. | Locked multi-Note UI/read contract and response fields. | Owner-scoped bounded render-validated views; focused tests; schema unchanged. | Core/application. | Aggregate leakage or invented uniqueness. |
| 1D.3 | **Complete.** Four thin REST routes, parser/cursor/serializer/error mappings and integration tests. | 1D.2 boundary fixed. | Cookie/nonce, exact bodies/allowlists, empty/non-enumerating reads, 404/409/422, no rule duplication. | WordPress REST adapter. | XSS, IDOR, unsafe retry or ambiguous member semantics. |
| 1D.4 | **Complete.** Work-wide multi-Note list, zero/add/read/edit/manual-save/delete and reconciliation state on existing Item detail. | Approved UX plus 1D.3. | Multiple Notes preserved; no autosave; dirty/pending/stale/error flows tested. | Biblio UI. | Treating one Note as singleton or losing unsaved text. |
| 1D.5 | Complete responsive/accessibility pass without semantic changes. | 1D.4 stable. | Breakpoints, 320 CSS px/zoom, keyboard, labels, dialogs, focus, busy/error and 44 px controls pass. | Biblio UI QA. | Dialog/editor accessibility drift. |
| 1D.6 | Add guarded deterministic fixture/API/browser evidence. | 1D.2–1D.5 complete. | Matrix §22, double cleanup, zero residue/fingerprint, 1A–1C regression green. | Fixture/E2E. | Private data leakage or unsafe cleanup. |
| 1D.7 | Formal exit audit only. | All gates/evidence green. | Criterion matrix, exact commits, clean scope/status, GO/NO-GO, no new behavior. | Exit/documentation. | Calling partial evidence complete. |

## 24. Locked decisions and remaining engineering work

### 24.1 Product decisions

All are **LOCKED**:

1. Work-wide multi-Note collection with separately identified Notes, full
   content and per-Note edit/delete; no title or speculative context labels.
2. Always-visible `Privénotities` zero state with `Notitie toevoegen`, no empty
   row/draft.
3. Dirty-state warning with only discard or return; no implicit save/mutation.
4. Accessible native Biblio delete dialog with the approved exact copy,
   conditional hard-delete behavior and no silent stale/unavailable success.

### 24.2 Remaining engineering work

The deterministic constrained editor serializer and authoritative page-1
reconciliation are complete in 1D.4. Remaining engineering work is the
dedicated responsive/accessibility polish and acceptance pass in 1D.5,
followed by guarded 1D.6 browser evidence.

The former REST items—cursor codec, strict parsing/serialization and Note error
mapping—are complete in 1D.3. The remaining items are UI/E2E scope. Neither
requires schema, migration, new domain
rules, Library authorization, ActivityEvent or Crocoblock.

## 25. Final readiness verdict

**GO**

The four product conditions are locked and the minimal owner-scoped,
render-validated, bounded adapter projection is implemented. Existing member
read/create/update/delete services already provide the required owner, Work,
semantic no-op, stale and conditional-delete behavior. Schema 1004 remains
ready and current schema 1007 is unchanged.

There is no remaining CRUD/state/security uncertainty. **1D.5 is READY** to
polish and formally verify the implemented multi-Note UI without changing its
product semantics. A
singular Work-note, raw aggregate/content serialization, last-write-wins,
Library-role bypass or direct Elementor/Crocoblock mutation remains a contract
violation.

## 26. 1D.1 documentation and gate record

Files intended to change in 1D.1:

- this readiness document;
- `docs/00-current-state.md`, only to register 1D.1 status/verdict;
- `manifest.json`, only to register document/status.

`docs/06-testing-and-acceptance.md` is not changed: §22 is a future plan, not
new implemented acceptance evidence or a changed canonical test strategy.

No product code, Core, REST, Biblio UI, Elementor, Crocoblock, E2E/Playwright,
schema or migration file is changed by 1D.1.

Fresh 1D.1 verification:

- repository baseline: PASS — branch `main`, HEAD and `origin/main` exact at
  `6e7273840f58f9bb1d74bef2644799a227033483`, clean start worktree;
- complete Core unit suite: PASS — 242 tests, 919 assertions;
- focused real-MariaDB `PrivateNotePersistenceTest`,
  `PrivateNoteConcurrencyTest`, `Schema1004PrivateNotesTest` and
  `CoreSchemaMigrationTest`: PASS — 24 tests, 192 assertions;
- `manifest.json` validity: PASS;
- Git whitespace/diff check: PASS;
- scope audit: only this document, `docs/00-current-state.md` and
  `manifest.json` are changed;
- Playwright: deliberately not run because no product/UI/E2E code changed.

## 27. 1D.2 Core read-boundary evidence

1D.2 adds only four application/read types, their production composition and
focused tests. It changes no `PrivateNote` domain type, repository interface,
wpdb query, mutation service, content policy, schema or migration.

The implemented projection exposes exactly Note ID, render-validated safe HTML
and version per Note, plus an internal nullable next cursor at page level.
User, Work per item, Library, Item, Edition, ReadingRound, raw content,
created/updated timestamps and provenance are absent.

Existing boundaries remain authoritative:

- `GetPrivateNoteService` owner-scopes a member read and returns own or null;
- `createForWork` supports a Note without ReadingRound context;
- content update requires Note ID, desired content and expected version and
  preserves semantic/stale-identical no-op versus divergent typed stale;
- delete requires Note ID and expected version and preserves unavailable and
  stale semantics;
- neither Library Context nor ActivityEvent participates.

Focused fresh verification before the complete gate:

- changed-file PHP syntax: PASS;
- new readmodel + existing PrivateNote + composition unit selection: 16 tests,
  314 assertions, PASS;
- PrivateNote persistence/concurrency/schema/migration selection: 26 tests,
  236 assertions, PASS;
- PHPStan level 6 over production `src`: PASS, no errors;
- one SQL query per Work page, zero N+1, deterministic tie/order/cursor and
  two-page no-duplicate/no-skip behavior: PASS;
- corrupted stored HTML fails as typed `persistence_read_failed` before a view
  can be returned: PASS.

Complete canonical gate: PASS in 70 seconds — Composer metadata/platform,
complete PHP syntax, PHPStan level 6, 247 unit tests/939 assertions,
schema/migrations, WordPress smoke, manifest and whitespace. After adding the
final explicit own/unknown member-read and repeated/unknown delete assertions,
the complete real-MariaDB integration suite was rerun: 221 tests/2,049
assertions, PASS. No Playwright or Biblio UI suite is required because those
layers are unchanged.

## 28. 1D.3 Private Notes REST evidence

Status: **GO — 1D.4 READY**

1D.3 adds only WordPress REST transport code and integration evidence. The
four exact operations are:

```text
GET    /biblio/v1/me/works/{work_id}/private-notes
POST   /biblio/v1/me/works/{work_id}/private-notes
PATCH  /biblio/v1/me/private-notes/{private_note_id}
DELETE /biblio/v1/me/private-notes/{private_note_id}
```

All four require the existing cookie-authenticated actor and `X-WP-Nonce` /
`wp_rest` convention. No request accepts actor, Library, Item, Edition or
ReadingRound authority. GET accepts only optional `limit` and `cursor`; POST
accepts only `content`; PATCH accepts only `content` and `expected_version`;
DELETE accepts only `expected_version` and returns 204 without content.

`PrivateNoteCursorCodec` encodes the internal timestamp+Note-ID keyset as a
version-1 canonical URL-safe token. Decoding strictly verifies alphabet,
length, canonical base64, exact payload keys/version, exact UTC-microsecond
timestamp and typed Note ID. The cursor grants no authority: actor and Work are
resolved/reapplied for every request, including continuation requests.

Responses use an exact allowlist. A Note has only `private_note_id`,
`content_html` and `version`; a page has only `items` and `next_cursor`.
Create/update responses are projected from the authoritative Core aggregate
through `PrivateNoteView::fromPrivateNote` and the existing render service;
raw request or persistence content has no serializer path.

Malformed path/query/body, unknown fields and invalid cursors map to 400;
WordPress rejects malformed/non-UTF-8 JSON documents as standard
`rest_invalid_json`/400 before dispatch. Authentication is 401 and invalid
cookie nonce remains WordPress 403. Unknown/foreign/deleted Notes collapse to
one generic 404; stale divergent update/delete is 409; Core content validation
is safe generic 422; unavailable Core is 503; unexpected/persistence failures
are privacy-safe 500.

REST evidence proves zero/one/multiple behavior, foreign-only empty state,
Library-role non-bypass, exact tie ordering, default 50/maximum 100, keyset
continuation without duplicates/skips, cross-actor/cross-Work cursor safety,
strict injection rejection, the exact safe-HTML subset, XSS rejection,
fail-closed corrupt storage, create without Round, authoritative update,
semantic/stale-identical no-op, divergent stale 409, conditional delete and
equivalent foreign/unknown/deleted 404.

Focused verification:

- Private Notes REST selection: 11 tests/311 assertions, PASS;
- complete `RestApiTest`: 39 tests/747 assertions, PASS;
- relevant PrivateNote/readmodel unit: 16 tests/314 assertions, PASS;
- PrivateNote persistence/concurrency/schema/migration: 26 tests/237
  assertions, PASS;
- production PHP syntax and PHPStan level 6: PASS.

Complete canonical gate: PASS in 77 seconds. Composer metadata/platform,
complete PHP syntax, PHPStan level 6, 247 Core unit tests/939 assertions, 232
real-MariaDB integration tests/2,371 assertions, schema/migrations, WordPress
smoke, manifest and Git whitespace are green.

Schema remains 1007. No Core application/domain, repository, wpdb query,
schema/migration, Biblio UI, Elementor, Crocoblock, Playwright, ActivityEvent,
private audit or ReadingRound product behavior changed.

## 29. 1D.4 Private Notes UI evidence

Status: **GO — 1D.5 READY**

The existing Item detail owns the structural insertion point only. Its final
order is `Lezen`, independent `Leesgeschiedenis`, independent
`Privénotities`, `Uitgave`, `Exemplaar`. The Note controller is created only
after the detail response has passed the exact Library/Item/Work presentation
contract; its Work ID therefore comes from authoritative detail state and
never from URL or editor input. Navigation destroys/aborts the previous
controller and request revisions reject late initial, continuation or
post-mutation page-1 responses.

Private Notes use a separate minimal state: items, next cursor, initial and
pagination loading/error, one optional editor, mutation pending, refresh
warning/notice and request revision. The UI requests `limit=10` as a
presentation choice. Initial zero retains the H2 and `Notitie toevoegen`
without an empty card or persisted draft. Continuation appends exact server
order, preserves loaded Notes on failure and retries the same opaque cursor.
Every successful mutation resets state from a fresh page-1 GET.

One constrained `contenteditable` editor is active at a time. Controls cover
bold, emphasis, unordered/ordered list and blockquote; paragraph and line-break
semantics remain native to the surface. Paste always requests `text/plain`.
The single serializer normalizes browser `div`, `b` and `i`, escapes text,
rebuilds only `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `blockquote`, emits
no attributes and fails closed on unsupported nodes/tags. The same canonical
path establishes the edit baseline, so an exact semantic revert is clean.

Authoritative saved HTML is never inserted as a trusted executable subtree.
It is parsed in an inert template, checked against the identical exact
allowlist/no-attribute contract and reconstructed node-by-node inside only the
Note body. Mutation input is not rendered as saved state: POST/PATCH responses
must first pass the exact public Note allowlist and saved-HTML validation.

POST sends only serialized content; PATCH sends content and the authoritative
version; DELETE sends only the selected Note version. Pending state blocks
duplicates. No local version increment or mutation retry exists. POST/PATCH
use the returned server Note for immediate reliable state and all three
mutations then reread page 1 for ordering/cursor truth. If this GET fails, the
confirmed mutation is never repeated and only list refresh remains available.

Divergent update 409 and member 404 preserve the local editor until an explicit
refresh action; there is no overwrite, merge or retry. Delete 409/404 retains
the Note and dialog context and offers refresh only. Network, malformed-success
or internal-error ambiguity also requires GET reconciliation before another
destructive attempt. Authentication and nonce recovery reuse the existing
login/reload patterns.

Dirty state exists only when canonical current editor HTML differs from the
canonical saved/open baseline. Clean Cancel closes immediately. Dirty Cancel,
internal back/Item navigation and interceptable popstate use the native discard
dialog with only `Terug naar notitie` and `Doorgaan zonder opslaan`; discard
performs no mutation and commits the intended navigation once. `beforeunload`
is registered only during dirty state and removed on revert, close, navigation
or controller destruction.

Delete uses the existing native Biblio dialog/bottom-sheet class with exact
copy: `Privénotitie verwijderen?`, `Deze notitie wordt definitief verwijderd.
Dit kan niet ongedaan worden gemaakt.`, `Annuleren`, `Definitief verwijderen`.
Cancel receives initial focus; idle Escape/cancel restores the opener; pending
disables dismissal/actions; success focuses the resulting scoped status.

The 1D.4 accessibility/responsive baseline includes one H2, native `ul`/`li`,
visible editor label/help, toolbar/textbox semantics, associated field errors,
busy/live status, keyboard-native controls, existing 44px targets, one-column
full-width editor, wrapping toolbar/actions, no horizontal overflow and the
existing `<768px` mobile bottom-sheet dialog. The dedicated formal polish and
acceptance pass remains 1D.5 scope; no full WCAG claim is made here.

Fresh verification:

- Private Notes frontend: 29/29 PASS;
- complete Biblio UI JavaScript: 167/167 PASS;
- Start/End Reading views: 25/25 PASS;
- Reading History: 8/8 PASS;
- app runtime/detail/router: 74/74 PASS;
- JavaScript syntax, Biblio UI PHP syntax and isolated smoke: PASS;
- Private Notes REST: 11 tests/311 assertions, PASS;
- complete `RestApiTest`: 39 tests/747 assertions, PASS;
- manifest JSON and Git whitespace: PASS.

Biblio UI product code, focused frontend tests, the three required canonical
documents and manifest status are the only intended scope. Core, REST route or
server-contract code, schema/migration, Elementor/Crocoblock and Playwright are
unchanged. The existing Biblio UI `0.2.0` asset version is retained under the
repository convention established by the earlier ReadingRound end and Reading
History feature commits. **1D.5 is READY** for responsive/accessibility polish
without CRUD, state, security or product-semantic changes.
