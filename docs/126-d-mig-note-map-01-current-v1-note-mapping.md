# D-MIG-NOTE-MAP-01 — Current V1 Note mapping contract

Status: **DESIGN GO — mapping contract closed; mapper implementation not started**

Date: 2026-09-17

Task severity: **High**

Scope: product, Notes and migration-mapping design only. This document adds no
production mapper, participant, schema, Core behavior, migration write, REST or
UI. V1 is source evidence, not product authority.

## 1. Decision inheritance

The authority order is:

1. accepted V2.001 Private Note and ownership canon;
2. the current `PrivateNote`, content policy and persistence implementation;
3. MIG-FND-01, MIG-02-RUN-01, MIG-02-CAT-01, MIG-02-READ-01,
   MIG-02-NOTE-01 and MIG-02-RECON-01;
4. the closed CURRENT CAT and Reading mapping/mapper contracts;
5. current Git and schema; and
6. the immutable CURRENT V1 source as reviewed mapping evidence.

The audited baseline is:

- branch `main`;
- HEAD `d26b033280bb704413adb49743a92f82b9433da0`;
- product `v2.001`;
- schema `1026`;
- Biblio Core `2.41.0`; and
- Biblio UI `0.20.0`.

The existing V2 Note decision is sufficient. A Note is private user-owned data,
has one immutable Work, may have a same-owner/same-Work ReadingRound context,
uses canonical safe HTML and stores required technical UTC `created_at` and
`updated_at`. It has no Library owner, publication state, Item/Edition link,
independent historical business time or migration metadata in product content.

The migration target decision is also sufficient. MIG-02 requires an explicit,
server-validated `target_user_id` and `target_library_id`. The normal personal
target User owns all imported private Notes; the Library is an authorization
and run-scope dependency, not the Note owner. There is no actor, admin,
first-user, display-name or Library-owner fallback.

No product question remains and no settled Note behavior is reopened.

## 2. CURRENT source evidence

The sole authoritative snapshot is:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

The only source data inspected for this decision was the existing read-only
extraction:

```text
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/
```

| Provenance fact | Exact value |
|---|---|
| ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Files / bytes | 3,587 / 19,801,196 |
| Adapter | `current-v1-json-29` |
| Source family | `biblio-v1` |
| Source version | `books-29.authors-2.reading-goals-2` |

The production `FilesystemMigrationSourcePackageFactory` recomputed the exact
manifest digest, file count and byte count read-only. Direct terminal access to
the designated original ZIP remains subject to the macOS permission condition
recorded by SOURCE-01. No re-extraction, source mutation, substitute dataset,
DATA-01/MIG-01 input, provider call or output below the source root was used.

## 3. Note source shape

All 17 stable Notes are nested only at:

```text
data/books.json#books/*/notes/*
```

Every Note has exactly the reviewed keys:

```text
createdAt, id, text, updatedAt
```

The structural parent is one stable `books[].id`; the Note itself contains no
Book, Copy, Item, Edition, ReadingRound, reading registration, user, owner,
privacy, publication, type, title or heading field. The adapter emits the raw
record as `v1.note` with the unchanged stable Note ID, payload
`{book_id, record}` and an exact `v1.book:<book-id>` reference.

All 17 Note IDs are non-empty, globally unique 20-character strings. Every ID
has the exact reviewed shape `^[0-9]{13}_[a-z0-9]{6}$`; zero IDs have another
shape. The 13-digit prefix and six-character suffix are retained as opaque
source identity and are not interpreted as time, sequence or semantics. All 17
structural parent Book IDs are non-empty and distinct, so each current parent
has one Note. Every parent Book exists by construction; there are zero
unmatched Note→Book references.

The source keeps these Notes structurally separate from:

- three scalar Copy-note occurrences;
- one nested Review;
- five scalar Reflections; and
- 15 positive Ratings.

All 17 records share the same Note structure. No explicit source type field
distinguishes a Review, Reflection, Copy note or reading-session note. Content
meaning was not inspected or classified. Therefore all 17 are structurally
generic Book Notes and remain Note candidates; none is flattened into the
Assessment or Item-local lanes.

Eight Note parent Books happen to carry separate reading evidence: four have a
stable ReadingRound sibling and four have a read-registration sibling. All 17
parents also happen to have a Copy, including two parents with multiple Copies.
These are sibling/cardinality facts, not Note references, and prove neither a
Round nor a Copy relation.

## 4. Ownership

The CURRENT package has no account/User population and no Note-level owner
field. A structural scan of all reviewed top-level JSON found no user, owner,
privacy, public or visibility identity field; the unrelated Book/Copy
`ownershipStatus` describes possession state and cannot own a personal Note.

The existing migration governance settles the missing source owner without a
new product decision: every Note plan uses only the explicit migration target
`UserId`. Planning requires the plan User to equal the validated target User;
apply requires the same active User on the MIG-FND run. `target_library_id`
remains mandatory for run and dependency isolation but never becomes Note
ownership.

## 5. Work dependency

For each Note, the mapper derives exactly one Work source dependency from the
structural parent Book:

```text
books[].id
→ CurrentV1CatalogSourceIds::work(book_id)
→ v1.book/<book-id>/work
→ exact committed catalog_work → work mapping
```

No title, ISBN, Edition, Item, Copy or Author lookup is allowed. The existing
`PrivateNoteMigrationWriter` already requires exactly one committed Work
mapping in the same source family and exact target User+Library run scope and
checks that the mapped Work still exists.

All 17 CURRENT parent Books are CAT-ready. Zero Notes depend on either of the
two invalid-only CAT-quarantined Books. There are therefore 17 resolvable Work
dependencies and zero CAT-blocked Notes.

## 6. ReadingRound dependency

The Note record has no direct or indirect structural ReadingRound reference.
Its parent Book relation is a Work dependency only. The four sibling stable
Rounds and four sibling read registrations do not establish which reading
occurrence, if any, a Note concerned. Timestamp proximity, Work equality,
single-round presence, reading status, Copy availability and cardinality are
all prohibited inference.

Every CURRENT Note plan therefore has:

```text
readingRoundSourceId = null
```

The participant creates a valid Work-only Note. No ReadingRound dependency is
declared, inferred or created. A later owner-authorized normal V2 context
correction may attach a same-owner/same-Work Round, but migration does not
predict that later user choice.

## 7. Note semantics

The `notes[]` container and identical four-field record shape are the only
semantic discriminator used. Prose is never inspected to decide Review,
Reflection, provenance, Condition, ReadingRound or publication meaning.

Consequently:

- 17 structurally generic private Work Notes enter Note planning;
- zero Copy notes enter Note planning;
- zero Reviews, Reflections or Ratings enter Note planning; and
- the later Assessment mapping lane remains independent.

Different stable Note IDs remain different Notes even if future records happen
to share Work, body or timestamps. No content-, time- or Work-based merge is
permitted.

## 8. Body/content handling

All 17 `text` values are strings with visible non-whitespace content. They are
valid UTF-8, contain no CRLF, CR or LF, contain no HTML-shaped markup or entity
shape and contain no reviewed Markdown heading, list or emphasis shape. The
largest raw body is 227 UTF-8 bytes. The exact CURRENT content class is
therefore one-line plaintext, not HTML, Markdown or mixed content.

`StrictPrivateNoteContentPolicy` requires canonical safe HTML rather than raw
plaintext. The mapper must perform only this deterministic representation
conversion:

1. require a string, valid UTF-8, no NUL and visible text;
2. normalize CRLF/CR to LF, although the CURRENT population has none;
3. HTML-escape the full text as data with `ENT_QUOTES | ENT_HTML5`, UTF-8 and
   double-encoding enabled; and
4. wrap the escaped one-line text in exactly one `<p>...</p>` container.

No source prose is rewritten, corrected, translated, summarized or interpreted.
The wrapper expresses only the V2 storage envelope; it does not infer headings,
lists, emphasis or paragraphs. All 17 resulting values pass the current strict
policy unchanged; the largest canonical value is 234 bytes. The policy still
enforces visible text, valid UTF-8, the 65,535-byte bound and only `p`, `br`,
`strong`, `em`, `ul`, `ol`, `li`, `blockquote` without attributes.

For this pinned population there is no empty, whitespace-only, invalid-type,
malformed-HTML or oversized body. A future reviewed source with meaningful,
valid content that cannot be represented without semantic loss must be
`preserved_deferred`; broken/invalid evidence must be quarantined. Neither path
may inject placeholder text or silently strip content.

## 9. Created/updated timestamps

Every Note has a non-empty string `createdAt` and `updatedAt`. All 34 values
strictly parse as:

```text
YYYY-MM-DDTHH:MM:SS.mmmZ
```

The `Z` supplies an explicit UTC offset and the source carries millisecond
precision. Field placement and names in the reviewed Note schema establish
technical Note creation/update semantics; no Book, migration or current time
is substituted.

All 17 records have `updatedAt == createdAt`. This is internally consistent,
not missing time and not evidence of a later edit. The mapper parses each exact
instant as UTC and supplies it to `PrivateNotePlan::createdAt()` and
`updatedAt()`. V2 persists the same instant at microsecond-capable precision;
zero-filled sub-millisecond digits are storage representation, not invented
source precision. `updated_at < created_at`, invalid shape/range or missing
time must quarantine rather than repair.

The Note target has no independent `noted_at`. The CURRENT source does not
require one; both mandatory technical instants are exact and usable.

## 10. Privacy

All planned targets are ordinary V2 Private Notes: owner-only, Work-scoped and
unpublished. No new privacy field is introduced because the target has no
visibility/publication state. No source absence is interpreted as public,
shared, Library-visible or ReadingRound-visible.

Dry-run, mapping findings, reconciliation output, CLI output and failures must
remain content-free and timestamp-free. Raw source JSON never enters the Note
body. Migration metadata remains solely in MIG-FND observations, mappings and
outcomes and is never prefixed or suffixed to user-visible Note content.

## 11. Source identity

The stable V1 Note ID remains unchanged throughout mapping. It is an opaque
20-character token with reviewed syntax `^[0-9]{13}_[a-z0-9]{6}$`; the mapper
validates that syntax but derives no timestamp or other meaning from its
components. The two explicit logical identities are:

| Stage | Source type | Source ID |
|---|---|---|
| Faithful adapter record | `v1.note` | unchanged `notes[].id` |
| Typed participant record / MIG-FND observation | `private_note` | the same unchanged `notes[].id` |

The mapper finding links the raw record to the planned typed identity. It does
not construct an ID from Work, timestamp, body, title, hash or a random value.
The typed record's canonical payload and hash bind target User, Work dependency,
NULL Round, canonical content, exact timestamps, initial version and private
visibility for deterministic replay.

## 12. Alias handling

CAT's approved representative/alias mappings are the only Work-convergence
authority. A Note under an alias Book would retain its own stable Note identity
and depend on that Book's own `v1.book/<book-id>/work` source identity; CAT then
resolves representative and alias Work mappings to the same actual Work. Notes
would not be merged merely because their Works converge.

In this exact population:

- zero Note parent Books belong to any duplicate-ISBN group;
- zero Notes are on duplicate-ISBN representatives; and
- zero Notes are on duplicate-ISBN aliases.

The CURRENT result therefore needs no Note-level convergence finding and
creates no duplicate presentation caused by CAT aliasing.

## 13. CAT-quarantined and unmatched references

All 17 structural Note→Book references are valid and all 17 Book→Work source
dependencies are CAT-ready. Zero Notes refer to CAT-quarantined Books and zero
references are unmatched.

The implementation contract still fails closed: a Note whose Book has no
ready CAT Work identity produces no active `PrivateNotePlan`, creates no Work
to save the Note and is accounted as CAT-blocked quarantine with its source
evidence retained. A missing/malformed structural Book reference is broken
evidence and is quarantined. Title or ISBN matching is never a fallback.

## 14. Typed Note contract

The CURRENT mapper can feed the existing source-neutral participant unchanged.
For each accepted Note it emits a typed record with:

```text
source type                 private_note
source ID                   unchanged V1 Note ID
PrivateNotePlan.targetUserId
PrivateNotePlan.workSourceId
PrivateNotePlan.content
PrivateNotePlan.createdAt
PrivateNotePlan.updatedAt
PrivateNotePlan.readingRoundSourceId = null
```

The Work source ID is `v1.book/<parent-book-id>/work`. `PrivateNotePlan` owns
the canonical payload and payload hash; no extra mapper field or participant
expansion is required.

`PrivateNoteMigrationParticipant` already validates target equality and
canonical content and declares the exact Work dependency. Its writer already
validates active ownership, exact committed dependency mapping, replay,
reverse mapping, canonical state and transaction boundaries. The optional
Round path remains unused for CURRENT Notes.

The future implementation should therefore add only a manifest-bound
CURRENT-specific Note mapper collaborator, its mapping reasons/tests and
composition into `CurrentV1CatalogMapper`. It must not change the participant,
writer, schema or Note domain.

## 15. Preservation/quarantine

Every one of the 17 stable Note records reconciles into exactly one typed active
plan. There is no unsupported meaningful Note fact in the four-field source
shape after the body envelope and exact timestamp mapping.

The disposition rules are:

- active plan: valid stable ID, structural parent with ready CAT Work, exact
  canonicalizable body and both valid ordered UTC timestamps;
- `preserved_deferred`: meaningful valid content or semantics that cannot be
  represented without loss under the approved Note target; and
- quarantined: malformed ID/reference/body/time evidence, contradictory time,
  or a CAT-blocked Work dependency.

No CURRENT record enters either exceptional path. Copy notes remain the three
already-accounted Item-local preserved-deferred occurrences and are not counted
again. Ratings, Review and Reflections remain for Assessment mapping.

## 16. CURRENT counts

| Measure | Exact CURRENT result |
|---|---:|
| Stable Notes | 17 |
| Valid exact-shape / unique Note IDs | 17 / 17 |
| Note ID shape | 20 characters; `^[0-9]{13}_[a-z0-9]{6}$` |
| Valid structural Book references | 17 |
| Distinct parent Books | 17 |
| Duplicate-ISBN group-member Notes | 0 |
| Duplicate-ISBN alias Notes | 0 |
| CAT-quarantined Book Notes | 0 |
| Non-empty string bodies | 17 |
| Empty / whitespace / invalid-type bodies | 0 / 0 / 0 |
| Plaintext / HTML / mixed bodies | 17 / 0 / 0 |
| Malformed / oversized bodies | 0 / 0 |
| Bodies passing reviewed plaintext→safe-HTML conversion | 17 |
| Populated / valid `createdAt` | 17 / 17 |
| Populated / valid `updatedAt` | 17 / 17 |
| Equal / later / earlier update instants | 17 / 0 / 0 |
| Timestamp shape | 17 millisecond-precision UTC `Z` |
| Explicit ReadingRound references | 0 |
| Explicit owner references | 0 |
| Active Note plans | 17 |
| Preserved-deferred Notes | 0 |
| Quarantined Notes | 0 |
| CAT-blocked dependencies | 0 |
| Unmatched references | 0 |

These counts are audit results from the pinned manifest and must be recomputed
by implementation/tests rather than hardcoded in mapper behavior.

## 17. Downstream effects

- **Work detail:** the 17 imported records appear as ordinary owner Notes on
  the mapped Works through existing owner-scoped reads. No migration label or
  provenance appears in content.
- **Ownership/privacy:** only the explicit target User can read or mutate them;
  Library roles and platform privileges grant no cross-user access.
- **ReadingRound:** every imported Note initially remains Work-only. Later
  linking is an explicit normal owner action subject to same-owner/same-Work
  validation, not a migration inference.
- **Assessment:** Rating, Review and Reflection migration remains separate and
  cannot consume or duplicate these Notes.
- **Search/privacy:** this slice adds no public, Library or general-search
  projection. Existing Note reads remain server-authorized and owner-scoped.
- **Replay/reconciliation:** each stable source ID maps to one Note; a second
  exact run reuses it, while changed payload/canonical state fails closed.

No UI, REST or product-read change is required.

## 18. Product questions

None.

The existing explicit target-User contract settles ownership; the source
structure settles the Note/Assessment/Copy-note boundary; and absence of an
explicit Round relationship settles the nullable Round dependency. The design
verdict is therefore **DESIGN GO**.

## 19. Required implementation slice

Recommended follow-up, not started by this decision:

```text
MIG-02-NOTE-MAP-01 — Current V1 Note mapper
```

Bounded scope:

- add a manifest-bound `CurrentV1NoteMapper` (or equivalently bounded CURRENT
  collaborator) between faithful adapter records and existing typed plans;
- consume only `v1.note` plus exact parent Book/CAT Work state;
- emit 17 `private_note` typed records for this pinned source;
- use the explicit planning target User;
- emit NULL ReadingRound dependencies;
- perform the reviewed plaintext→canonical-safe-HTML conversion;
- parse exact UTC creation/update instants;
- emit privacy-safe preservation/quarantine/mapping findings;
- compose it into the existing CURRENT mapper and zero-write dry-run; and
- add focused deterministic, privacy, CAT-block, replay and reconciliation
  evidence.

Excluded: participant/domain changes, Assessment mapping, Copy-note handling,
schema/Core/UI versions, apply/import, production apply command, UI work,
provider/network use and final cutover.

## 20. Acceptance criteria

MIG-02-NOTE-MAP-01 is acceptable only when:

1. the reviewed manifest digest is mandatory and byte/source drift fails
   closed;
2. all 17 stable Notes are recomputed and exactly accounted;
3. each accepted source ID remains unchanged and unique;
4. only the explicit validated target User becomes owner;
5. every plan depends only on the parent Book's exact CAT Work source identity;
6. CAT-blocked/unmatched Notes create no dangling target or invented Work;
7. all CURRENT Round dependencies are NULL and sibling/timestamp/cardinality
   inference is absent;
8. only `books[].notes[]` enters Note planning; Copy notes, Ratings, Review and
   Reflections remain excluded;
9. plaintext is escaped and placed in one canonical `<p>` envelope without
   prose rewriting or metadata labels;
10. all 17 canonical bodies pass `StrictPrivateNoteContentPolicy` unchanged;
11. both source UTC instants round-trip exactly, equality is accepted and no
    migration/current time is substituted;
12. distinct source IDs remain distinct even after future Work convergence;
13. the existing participant and writer are reused unchanged;
14. dry-run and reconciliation expose no body, raw payload, private timestamp
    or user-visible migration metadata;
15. the expected result is 17 active plans, zero preserved, zero quarantined,
    zero CAT-blocked and zero unmatched;
16. focused mapper/participant/reconciliation tests, privacy scan,
    documentation validation and `git diff --check` pass; and
17. an independent final review finds no product, metadata, UX, engineering,
    migration, privacy or regression blocker.

This DESIGN GO authorizes only the separate mapper implementation slice. It
does not authorize implementation now, Assessment mapping or any apply/import.
