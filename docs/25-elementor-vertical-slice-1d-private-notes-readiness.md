# Elementor Vertical Slice 1D — Private Notes Readiness

Date: 2026-08-31
Readiness verdict: **GO for 1D.3**

Current implementation status: **1D.2 CORE READ BOUNDARY COMPLETE — GO**

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

There are no Private Note REST routes, Note request parsers, Note response
serializers or Note-specific REST error mappings. The current REST namespace
is `biblio/v1`; it has cookie authentication, WordPress `wp_rest` nonce use,
typed request parsing, explicit response allowlists and safe error mapping.

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
| Read one | Yes | `get(PrivateNoteId): ?PrivateNote` | Yes internally | REST adapter/allowlist absent. |
| Read Work collection | Yes | `forWork(WorkId, ?PrivateNotePageRequest): PrivateNoteViewPage` | Yes | New minimal adapter-facing safe view boundary. |
| Create | Yes | `createForWork(WorkId, string): PrivateNote` | Yes | REST POST absent. |
| Create for Round | Yes | `createForReadingRound(ReadingRoundId, string): PrivateNote` | Not needed | Keep outside minimal Item-detail 1D. |
| Update content | Yes | `update(PrivateNoteId, PrivateNoteVersion, string): PrivateNote` | Yes | REST PATCH absent. |
| Correct Round | Yes | `correct(PrivateNoteId, PrivateNoteVersion, ?ReadingRoundId): PrivateNote` | Not needed | No 1D UI requirement; do not expose accidentally. |
| Delete | Yes | `delete(PrivateNoteId, PrivateNoteVersion): void` | Yes | REST DELETE absent. |
| Safe render | Yes | `render(PrivateNote): string` | Yes | New projector calls it for every returned Note. |
| Pagination | Yes | Existing request plus `PrivateNoteViewCursor` result | Yes for Core | REST opaque encoding remains adapter scope. |
| Owner authorization | Yes | Server actor + owner predicates | Yes | REST auth/nonce still required. |
| Stale conflict | Yes | `PrivateNoteStale(current)` | Yes | REST 409 mapping + reread behavior absent. |

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
keys only so 1D.3 can encode them into a versioned opaque transport token.

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

## 13. REST delta and contract recommendation

### 13.1 Existing routes

No Private Note REST route exists. The current repository convention supports
owner-scoped `/me/...` resources, strict JSON parsing, explicit allowlists,
cookie authentication plus `X-WP-Nonce`, and safe 400/401/403/404/409/422/
500/503 behavior.

### 13.2 Rejected singular variant

The proposed singular resource:

`GET|PUT|DELETE /me/works/{work_id}/note`

is incompatible with the locked multiple-Notes contract. `PUT` could not
identify which Note to update and would incorrectly imply User + Work
uniqueness or upsert semantics.

### 13.3 Minimal consistent routes

Recommended minimal 1D routes:

```text
GET    /biblio/v1/me/works/{work_id}/notes
POST   /biblio/v1/me/works/{work_id}/notes
PATCH  /biblio/v1/me/notes/{private_note_id}
DELETE /biblio/v1/me/notes/{private_note_id}
```

`GET` is the read/zero-state collection; `POST` creates another aggregate;
`PATCH` changes only content on the addressed Note; `DELETE` removes that
Note. Round-context correction is outside minimal 1D and gets no route.

GET query:

- optional `cursor`, versioned and opaque;
- optional `limit`, recommended UI default 10, maximum no higher than the
  existing Core maximum 100;
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

Recommended success allowlists:

```json
{
  "data": {
    "notes": [
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

POST returns 201 with one Note object. PATCH returns 200 with one Note object.
DELETE returns 204 with no body, or the repository's established 200 envelope
if consistency review forbids 204; choose once in 1D.3 and test exactly. The
response never returns User/owner, Library, Item, Edition, ReadingRound,
technical timestamps or raw unvalidated content.

## 14. Missing, non-enumeration and error model

For a valid or unknown well-formed Work with no matching own Notes, GET should
return 200 with `notes: []` and `next_cursor: null`. This matches the existing
Core Work-list behavior and reveals neither whether another user has Notes nor
their IDs. Item detail already supplies a validated accessible Work ID.

Individual PATCH/DELETE of unknown, foreign or already-deleted Notes returns
the same generic 404. Library membership and Item/source details are never
consulted or disclosed by the Note route.

| Condition | Existing/future source | HTTP recommendation |
|---|---|---:|
| Unauthenticated | WordPress/Core authentication | 401 existing safe error. |
| Invalid/missing nonce | WordPress REST cookie auth | 403 standard WordPress response. |
| Malformed Work/Note ID | strict request parser | 400. |
| Unknown fields/wrong body types | strict request parser | 400. |
| Invalid/empty/forbidden/too-long content | `ValidationFailed` | 422 generic validation. |
| Unknown/foreign/already-deleted Note | `PrivateNoteNotAvailable` | 404 generic resource unavailable. |
| Round unavailable | existing typed failure, not exposed by minimal 1D | 404 if ever mapped. |
| Divergent stale content/delete | `PrivateNoteStale` | 409 named conflict; do not serialize raw exception. |
| Semantic/stale no-op update | Core success | 200 current safe Note. |
| Core unavailable | application provider | 503. |
| Persistence/transaction/unexpected | generic safe mapper + server log | 500. |

The current `RestErrorMapper` does not map the Note-specific unavailable or
stale reasons; adding these mappings is a required 1D.3 engineering delta.
Following the proven 1B pattern, a 409 body need not embed current state. UI
must perform an authoritative GET reread and show the conflict without
automatically retrying the mutation.

**Non-enumeration verdict: READY**, provided all list and member operations
retain owner predicates and the response/error allowlists above.

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

1D.3 must expose Note version and map stale to 409. 1D.4 must reconcile by GET,
retain unsaved local intent and require an explicit user action. This is a
normal adapter/UI delta, not a Core blocker.

## 19. Security verdict

**CORE SECURITY READINESS: READY.**

Core and schema are ready. The conditions are transport/editor implementation
conditions:

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
| 1D.3 | Add the four thin REST routes, parser/cursor/serializer/error mappings and integration tests. | 1D.2 boundary fixed. | Cookie/nonce, exact bodies/allowlists, empty/non-enumerating reads, 404/409/422, no rule duplication. | WordPress REST adapter. | XSS, IDOR, unsafe retry or ambiguous member semantics. |
| 1D.4 | Add Work-wide multi-Note list, zero/add/read/edit/manual-save/delete and reconciliation state to existing Item detail. | Approved UX plus 1D.3. | Multiple Notes preserved; no autosave; dirty/pending/stale/error flows tested. | Biblio UI. | Treating one Note as singleton or losing unsaved text. |
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

1. Add the 1D.3 versioned opaque transport cursor codec around the implemented
   internal cursor.
2. Add strict Note request parsing and exact Note response serialization around
   the implemented allowlist.
3. Map `PrivateNoteNotAvailable` to generic 404 and `PrivateNoteStale` to 409;
   retain generic safe 500 behavior. Blocks 1D.3.
4. Implement/test a deterministic editor serializer for the exact safe HTML
   subset. Blocks 1D.4/1D.6.
5. Add authoritative collection reread and no-auto-retry behavior for stale,
   nonce and ambiguous mutation outcomes. Blocks 1D.4/1D.6.

Items 1–3 are normal 1D.3 REST-adapter scope, not Core conditions or blockers.
Items 4–5 are later UI/E2E scope. None requires schema, migration, new domain
rules, Library authorization, ActivityEvent or Crocoblock.

## 25. Final readiness verdict

**GO**

The four product conditions are locked and the minimal owner-scoped,
render-validated, bounded adapter projection is implemented. Existing member
read/create/update/delete services already provide the required owner, Work,
semantic no-op, stale and conditional-delete behavior. Schema 1004 remains
ready and current schema 1007 is unchanged.

There is no remaining Core/domain/schema uncertainty. **1D.3 is READY** to add
only the thin REST adapter, cursor codec, strict parser/serializer and safe
error mappings. A singular Work-note, raw aggregate/content serialization,
last-write-wins, Library-role bypass or direct Elementor/Crocoblock mutation
remains a contract violation.

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
