# ADR-013 — Collector-local overrides

Status: Accepted

Scope: v2.001 collector-local presentation and Item/Copy collector data

## Context

Work and Edition are shared platform-wide bibliographic identities. A Library
may nevertheless need to make a shared record recognizable in its own
collection and describe characteristics of a concrete physical copy. Without a
closed boundary, local collector information could be mistaken for, or replace,
shared bibliographic truth.

## Decision

### Truth and ownership boundary

The governing distinction is:

- central: bibliographic truth for shared Work/Edition metadata;
- Library-local: how one Library presents a shared record in its own
  collection;
- Item/Copy: characteristics of one concrete physical copy.

Local collector data supplements central bibliographic metadata; it never
replaces it. Work and Edition remain platform-wide shared. The Library-local
presentation layer is Library-scoped. Item/Copy collector data is
Library-owned. Personal reading data remains user-owned.

Central bibliographic corrections, including errors in author(s), ISBN,
language, publisher, publication date or other Work/Edition metadata, remain
subject to the existing Biblio Librarian correction flow. A Library does not
directly overwrite shared metadata through a collector-local value.

### Two collector layers

Collector-local data consists of two distinct layers:

1. a Library-local presentation layer; and
2. an Item/Copy collector layer.

Neither layer is platform-wide Work/Edition metadata. The decision defines
their functional ownership and fields, not a persistence shape, API, UI or
form flow.

### Library-local presentation layer in v2.001

Only these three local presentation fields are supported in v2.001:

- own display name;
- own sort title;
- short local explanation / label.

They let a Library determine how a shared Work/Edition record is recognizable
and useful in that Library. For example, the central title `The Hobbit` may be
presented locally as `The Hobbit — Folio Society`. The central title remains
unchanged.

`Leesexemplaar` / `verzamelkopie` is not a separate Item/Copy core field in
v2.001. Where useful, it can be expressed through this local
presentation-/label layer.

### Item/Copy collector layer in v2.001

In addition to already existing Item/Copy data such as condition, location and
acquisition data, exactly these six extra collector fields are supported in
v2.001:

- **Signed:** records whether this copy is signed and may, where appropriate,
  leave room for who signed it.
- **Copy number / limitation:** records the number of this concrete copy, for
  example `123/500`; it is Item data and distinct from Edition-wide print-run
  information.
- **Dust jacket:** records whether this copy's dust jacket is present, missing
  or not applicable.
- **Inscription / dedication:** records whether this copy contains a personal
  inscription or dedication; a short supplementary note may functionally be
  supported later.
- **Origin / provenance:** records the known origin of this concrete copy, for
  example a previous owner, estate, auction or other special origin.
- **Completeness / enclosures:** records whether parts belonging to this copy
  are present, for example a map, poster, slipcase, supplement or missing
  component.

Existing condition, location and acquisition data is not redefined by this
decision.

### Deferred specialist collector data

The following is not designed as separate structured data in v2.001:

- paper or watermark details;
- foxing;
- extensive restoration data;
- bookbinder;
- ex-libris as its own entity;
- extensive provenance chains;
- certificates;
- signature authentication;
- other specialist antiquarian cataloguing.

The model may later be extended without being unnecessarily blocked, but this
decision creates none of these fields.

### MH-B5 boundary

MH-B5 and later UI work must respect these ownership boundaries. MH-B5 is not
designed or implemented by this decision.

## Boundaries

This ADR adds no production code, schema, migration, REST contract, UI,
form design, Biblio Librarian workflow, central metadata override, specialist
rare-books module or automatic collector classification.

## Consequences

Future implementation must keep the two local layers distinct from each other
and from shared Work/Edition bibliography. It must also preserve the existing
Library Context/authorization boundary for Library-scoped data and the existing
user-ownership boundary for personal reading data.

ADR-006 continues to govern the existing LibraryCatalogContext classification
layer. ADR-010, ADR-011 and ADR-012 continue to govern shared metadata evidence,
central correction proposals and Biblio Librarian assessment.
