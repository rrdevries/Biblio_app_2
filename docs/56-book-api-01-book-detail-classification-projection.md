# 56 — BOOK-API-01 Book Detail classification projection

Status: **GO / CLOSED**

Date: 2026-09-08

Task severity: **High**

## 1. Domain audit

The canonical classification assignment is `LibraryCatalogContext`, uniquely
anchored to `Library + Work`. It is neither Edition- nor Item-owned. One
context contains exactly one Library-owned Book Type and duplicate-free,
unordered sets of zero or more Library-owned Genres and Subjects. Multiple
Items and Editions of the same Work share that context inside one Library; the
same central Work may have a different context in another Library.

Assignments are stored in the Library Catalog Context table and the separate
Genre/Subject junction tables. Terms live in their Library-owned Book Type,
Genre and Subject tables. The pre-existing `classificationsForWorks()` read
returned assignment IDs, while the separate `classification-options` route
returned only active terms available for new Add Book selection. Composing
those paths would have lost assigned inactive terms, so BOOK-API-01 adds a
dedicated assigned-term read to the existing classification query boundary.

Assigned terms remain readable after deactivation; inactive terms are merely
unavailable for new links. Book Type has one value. Genre and Subject output
uses the canonical alphabetical ordering by normalized name with the stable
Library-local ID as tie-breaker. A pre-F2.5 Work may still have no context and
therefore projects three empty lists.

## 2. Contract and authorization

The existing authorized endpoint remains:

`GET /biblio/v1/libraries/{library_id}/items/{item_id}`

It now adds:

```json
{
  "classification": {
    "book_types": [
      { "book_type_id": "...", "display_name": "Leesboek" }
    ],
    "genres": [
      { "genre_id": "...", "display_name": "Roman" }
    ],
    "subjects": [
      { "subject_id": "...", "display_name": "..." }
    ]
  }
}
```

All three keys are always present and use lists; no context is represented by
three empty lists. No status, seed key, normalized name, provider field or
database detail is exposed.

The existing Item detail flow first resolves the authenticated actor and
explicit Library Context and then selects the active Item with both Library
and Item predicates. Only after that succeeds does Core query the assignment
for that same Library and the Item-derived Work. Every assignment and term
join contains the Library predicate. Unknown and cross-Library Items retain
the same non-enumerating response, and there is no cross-Library or
platform-wide fallback.

## 3. Frontend and D-BOOK-01

The strict Book Detail decoder accepts only the exact classification object
and exact type-specific ID/display-name term objects. It accepts empty lists
and rejects wrong container types, non-string IDs/names, additional term
fields and more than one Book Type. Existing top-level unknown-field policy is
unchanged.

The assigned Book Type replaces the generic physical-book chip in the compact
hero; when no Book Type is assigned, the existing `Boek` form chip remains.
Genres and Subjects appear once in the right-hand `Boekdetails` utility group.
Empty categories produce no labels or placeholder. The restrained existing
chip language, wrapping metadata and D-BOOK-01 responsive composition are
reused.

## 4. Regression and scope

The `classification-options` endpoint and Add Book request/decoder remain
unchanged and continue to expose/select only active options. BOOK-API-01 adds
no mutation, schema, provider mapping, term governance, search/filter,
description, cover, Collection, Review, Librarian or Elementor behavior.

Deterministic integration coverage proves empty classification, one and
multiple assignments, stable ordering, retained inactive assignments, exact
IDs/labels, and one shared Work/Edition projected differently in two
Libraries. REST coverage proves the allowlist and Library isolation. Frontend
coverage proves strict decoding, empty rendering, multiple/long values and the
hero/utility placement. The guarded browser matrix covers 1440px, 1024px,
390px and the 720px 200%-reflow equivalent without horizontal overflow.

## 5. Version and verification

Schema remains `1017`; no migration or DDL changed. Biblio UI is `0.8.0` for
asset cache invalidation.

The final verification record is the completion report for the local
BOOK-API-01 commit. Ignored browser evidence is stored under
`.local/book-api-01-screenshots/after/` and is not a production asset.
