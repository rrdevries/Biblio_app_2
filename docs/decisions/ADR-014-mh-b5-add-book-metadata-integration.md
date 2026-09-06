# ADR-014 — MH-B5 Add Book metadata integration

Status: Accepted

Scope: MH-B5 decision delta for authorized metadata lookup/review and Add Book
commit integration

## Context

MH-B1 through MH-B4 establish canonical ISBN identity, provider-neutral
acquisition, first-sufficient fallback, and persistent provider field-review
evidence. CAT-T1 fixes Work/Edition title authority; ADR-012 permits usable
provisional catalog creation; ADR-013 separates central bibliography from
Library-local and Item/Copy collector data. The remaining user-facing Add Book
integration must preserve all of those boundaries without turning provider
availability or Librarian review into a normal collection-management gate.

## Decision

### MH-B5 split and dependency

MH-B5 is two separate technical slices:

1. **MH-B5A — Authorized metadata lookup & review contract:** authorized
   local-first read/lookup/review contract over the Metadata Hub, with no
   catalog mutation.
2. **MH-B5B — Add Book commit integration:** commits a selected or manually
   entered Edition by reusing an existing record or creating provisional
   Work/Edition records, then creates the Item in Library Context with the
   required transaction and integrity rules.

MH-B5A must be GO before MH-B5B starts. This decision does not define either
slice's REST shape, schema, UI detail or implementation.

### Add Book acquisition flow

The flow is local-first and ISBN-led:

1. The user scans or enters an ISBN.
2. Biblio checks the canonical ISBN locally first.
3. When an existing Edition is found, Biblio reuses its Work/Edition and skips
   provider lookup for this addition. Correcting or enriching that central
   metadata remains a separate catalog action.
4. For a new ISBN, Biblio consults the Metadata Hub.
5. One usable candidate is shown to the user for review. Multiple usable
   candidates require the user to select one concrete Edition; Biblio chooses
   no automatic winner. Manual input remains available in both cases.
6. With no usable metadata, manual input remains available. With a provider
   failure, retry and manual input remain available.

Provider problems never block Add Book. The manual/no-ISBN path remains
first-class.

### Meaning of Add Book review and record creation

By confirming a candidate or manual entry in Add Book, the user confirms only:
`this is the correct concrete Edition for the book I am adding`.

That confirmation does not mark a Work or Edition `librarian_confirmed`, does
not make every provider value platform-wide curated, and does not bypass the
Biblio Librarian correction path for existing central metadata. A missing
appropriate record may create immediately usable provisional Work/Edition
records. Librarian review normally does not block adding.

CAT-T1 remains unchanged: an Edition owns its concrete title and concrete
Item/Edition display uses that title. A new Work may use its first Edition title
as a provisional seed, but an Edition title never automatically becomes a
canonical or `librarian_confirmed` Work title.

### User-observed Edition evidence

Data the user directly checks against the physical book may be retained as
strong user-observed/user-verified Edition evidence without prior Librarian
review. In v2.001 this is limited to:

- Edition title and subtitle;
- ISBN;
- language of this Edition;
- publisher / imprint;
- publication year/date;
- printing / edition statement;
- binding / physical publication form;
- page count;
- Edition-specific contributors such as translator or illustrator.

This evidence does not itself make the Edition `librarian_confirmed`. Provider
data may not silently replace directly checked user data.

### Metadata Hub to catalog bindings

The following bindings are fixed for Add Book integration:

| Provider field | Binding |
|---|---|
| `title` | Edition-title evidence; never automatic Work title |
| `subtitle` | Edition subtitle; never automatic canonical Work-title part |
| `languages` | Edition |
| `publishers` | Edition |
| `publication_date` | Edition |
| `page_count` | Edition |
| `contributors` | clear author/co-author to Work; clear edition-bound roles such as translator to Edition; illustrator and editor/compiler to Edition in v2.001; unknown/untyped contributor remains evidence only |
| `format` | no generic automatic mapping; only an explicit allowlist of unambiguous bindings may map to `Edition.Binding`; every other value remains evidence until a Biblio mapping exists |

There is no separate Expression entity in v2.001. Future Expression modeling
must not be unnecessarily blocked by these bindings.

### Item, collector data and authorization

The ordinary wizard may optionally collect location, condition, acquisition
data and a local display name / label. The six ADR-013 collector fields —
signed, copy number / limitation, dust jacket, inscription / dedication, origin
/ provenance, and completeness / enclosures — are not required main-flow
fields. After successful addition, an optional conceptual follow-up may be
`Exemplaar verder beschrijven`; its UI is not designed here.

Both MH-B5A and MH-B5B use the existing explicit Library Context and server-side
authorization. `Alleen bekijken` is read-only and cannot use Add Book. This
decision creates no capability matrix.

### Librarian governance

New Work/Edition records may be provisional without prior Librarian review.
Existing central Work/Edition metadata is never directly changed by a Library;
corrections follow Biblio Librarian governance. Provisional status alone is not
a Librarian-review trigger.

## Boundaries

This decision adds no production code, schema, migration, REST contract,
reviewqueue, Librarian UI, Add Book UI detail, provider merge/fusion, automatic
Work-title heuristic, Expression entity, collector-field persistence or
specialist rare-books feature.

## Consequences

MH-B5A can expose an authorized, local-first review contract without catalog
mutation. After it is GO, MH-B5B can use an explicit confirmed/manual Edition
choice to create or reuse the minimum central identity and add the Library Item
transactionally, while provider outages and ordinary Librarian governance do
not prevent a normal addition.

ADR-010 and ADR-011 continue to govern provider evidence and field review;
CAT-T1 governs title authority; ADR-012 governs provisional creation and
Librarian review; ADR-013 governs collector-local ownership.
