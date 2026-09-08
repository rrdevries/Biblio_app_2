# 57 — BOOK-API-02 Book Detail Collection membership projection

Status: **GO / CLOSED**

Date: 2026-09-08

Task severity: **High**

## 1. Domain audit

A Collection is Library-owned and contains concrete physical Items. Membership
is many-to-many: one Item may be in several Collections and one Collection may
contain several Items. It is not an Edition- or Work-assignment and is never
derived through another Item of the same Edition or Work.

Collections have a manual Library-level `collection_position`; memberships
have a separate manual `item_position` inside their Collection. An Item's Book
Detail memberships therefore use Collection display order, with Collection ID
as the existing stable tie-breaker. The internal membership position is not a
Book Detail field.

Normal reads require an active Item, active membership and active Collection.
An archived Collection is absent from active reads while its membership history
is retained. Item archive ends active memberships with `item_archived`; Item
restore never recreates them. Ordinary removal leaves an inactive historical
period. There is no Collection concept state.

## 2. Contract and authorization

The existing authorized endpoint remains:

`GET /biblio/v1/libraries/{library_id}/items/{item_id}`

It now always adds a list:

```json
{
  "collections": [
    {
      "collection_id": "...",
      "display_name": "..."
    }
  ]
}
```

Zero memberships is `[]`. No membership position, status, description, count,
cover or route is exposed. The existing Item-detail flow authorizes the actor
and explicit Library Context and resolves the active Item before querying the
existing Collection read boundary for that exact Library and Item. Repository
joins require matching Library identity plus active Item, membership and
Collection states. Unknown and foreign Items keep the existing non-enumerating
response, with no platform-wide fallback.

## 3. Frontend and D-BOOK-01

The strict decoder requires an array of exact `collection_id`/`display_name`
objects, rejects wrong types, empty strings, extra fields and duplicate IDs,
and never coerces or silently drops malformed members.

When the list is non-empty, D-BOOK-01 shows `In collecties` after Exemplaar in
the right context column. Names are text-only because no stable Collection
detail route exists. The presentation is an open native list with a restrained
brass boundary; long names wrap. An empty list renders no heading, placeholder
or navigation item.

## 4. Regression, fixtures and scope

MariaDB integration and REST coverage prove zero, one and multiple memberships,
manual Collection order, exact IDs/names, inactive and archived exclusion,
same-Edition sibling isolation and the same Edition in two Libraries with
different membership. Classification projection and non-enumerating Item
authorization remain intact.

The guarded browser fixture owns five exact Collection/membership periods:
multiple active memberships, a same-Edition sibling membership, an archived
Collection and a removed inactive membership. Fixture guards, double cleanup,
zero residue and the non-fixture fingerprint include both Collection tables.

This slice adds no Collection mutation, editor, reorder, route, wishlist
grouping, provider behavior, description, review, schema or Elementor logic.

## 5. Verification and versions

- targeted Core/REST integration: 61 tests, 1,160 assertions;
- complete Core gate: PHP syntax, PHPStan, 410 unit tests/1,738 assertions,
  343 MariaDB integration tests/4,159 assertions, Composer metadata/platform,
  WordPress smoke, manifest and Git whitespace passed;
- Biblio UI isolated smoke and complete JavaScript suite: 214 tests passed;
- complete guarded Chromium suite: 52 tests passed after one unrelated,
  non-reproducing Private Notes focus fluctuation in the preceding run;
- responsive Collection evidence: 1440px, 1024px, 768px, 390px and the 720px
  200%-reflow-equivalent passed without horizontal overflow; and
- explicit independent second review found no architecture, authorization,
  lifecycle, privacy, accessibility or scope blocker.

Schema remains `1017`. Biblio UI is `0.9.0` for changed JS/CSS asset cache
invalidation. Screenshot evidence is ignored under
`.local/book-api-02-screenshots/after/`.
