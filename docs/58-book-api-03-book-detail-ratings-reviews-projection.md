# 58 — BOOK-API-03 Book Detail Ratings & Reviews projection

Status: **GO / CLOSED**

Date: 2026-09-08

Task severity: **High**

## 1. Domain audit

Rating and WrittenReview are separate user-owned, Work-scoped source entities.
A Rating is required to be a 1.0–5.0 half-step value. Review content is
normalized plain text of 0–5000 Unicode codepoints. Either source may exist
without the other.

Each source may optionally reference one ReadingRound owned by the same user
for the same Work. At most one Rating and one Review may use a given Round; an
owner may also have at most one unlinked Rating and one unlinked Review per
Work. Different ReadingRounds therefore preserve distinct contributions for
rereads rather than collapsing them into a latest assessment.

A ContributionPublication points to exactly one Rating or one Review and
exactly one Library. Sources remain owned by their user; the publication never
transfers ownership. Rating association shown beside a Review is the latest
visible published Rating by the same user for the same Work and matching
ReadingRound linkage in that Library.

## 2. Visibility, lifecycle and identity

The existing B7 read is the sole public source for Book Detail. It requires an
authenticated actor with current `canViewCollection` access to the explicit
Library. A contribution is returned only when its publication has active author
status and visible moderation, targets that exact Library and source Work, and
the Library still has an active Item representing the Work.

Unpublished private sources, publications in another Library, withdrawn author
publications, hidden or removed moderation states and publications whose source
was deleted are absent. Membership loss after publication does not itself
retract an otherwise valid publication; current viewer authorization and
active Item presence still apply. The public identity is the source owner's
current WordPress `display_name`. Email, login name, user ID and profile data
are not exposed.

Mixed contributions use deterministic publication ordering:
`updated_at DESC, publication_id DESC`. The public timestamp is
`published_at`; the opaque continuation cursor carries only the internal sort
boundary.

## 3. Book Detail REST contract

The existing endpoint remains:

`GET /biblio/v1/libraries/{library_id}/items/{item_id}`

It always adds:

```json
{
  "assessments": {
    "contributions": [
      {
        "type": "rating",
        "display_name": "Reader",
        "published_at": "2026-09-08T10:00:00.000000Z",
        "rating": 4.5
      },
      {
        "type": "review",
        "display_name": "Reader",
        "published_at": "2026-09-08T09:00:00.000000Z",
        "rating": 4.5,
        "review_html": "Escaped plain-text content"
      }
    ],
    "aggregate": {
      "average": 4.5,
      "voter_count": 1
    },
    "next_cursor": null
  }
}
```

The embedded projection is the first canonical B7 page of 20. Empty data is a
stable empty `contributions` list, `average: null`, `voter_count: 0` and a null
cursor. The aggregate is the existing B7 unique-user latest-published-rating
aggregate; this slice adds no new calculation. If more rows exist, the opaque
cursor is returned, but Book Detail adds no fetch control or second request.

The contract intentionally reuses the established public DTO and exposes no
review, rating, publication, ReadingRound, user or Library IDs, `is_own`,
moderation flags or provenance. This is narrower than the illustrative shape in
the task and prevents internal identity or private-state disclosure.

Item detail first performs its existing actor, explicit Library Context and
active Item authorization. It then passes the Item-derived Work and the same
Library to `GetLibraryPublicAssessmentsService`, which independently repeats
the B7 Library visibility authorization. Unknown or foreign Items keep the
existing non-enumerating failure; a shared Work creates no fallback.

## 4. Frontend and D-BOOK-01

The strict Book Detail decoder requires exact B7 object shapes. It rejects
wrong containers, extra fields, empty display identity, non-finite or non-half-
step ratings outside 1.0–5.0, malformed or impossible UTC microsecond
timestamps, inconsistent aggregate null/count semantics and malformed cursors.
There is no coercion and no client-side visibility filtering. Duplicate-ID
validation is inapplicable because the privacy-minimal canonical public DTO has
no source or publication identifier.

When contributions exist, D-BOOK-01 adds `Beoordelingen` to the quiet anchor
navigation and renders an ordered open list in the left content column. Rating
is compact, review text uses the editorial serif face, and author/date form a
subdued byline. Escaped public review content is decoded only into `textContent`
so markup remains visible literal text and cannot execute. Long content wraps,
the utility column and hero are unaffected, and an empty projection adds no
heading or placeholder. A non-null cursor produces only an informational line,
not a fake load action.

## 5. Fixtures, regression and scope

Guarded deterministic fixtures cover no assessments, multiple mixed
publications, repeated ReadingRounds by one user, own and other-user private
sources, another-Library publication, a shared Work represented in two
Libraries with different results, rating-only, review-only, withdrawn, hidden
and escaped long content. Cleanup owns publications before their sources and
ReadingRounds, verifies zero residue and retains the non-fixture fingerprint.

REST integration compares the embedded projection with the existing standalone
B7 response and proves private/internal fields absent. Browser coverage proves
Library isolation, no extra assessment request, no mutation controls, safe text
rendering, reviewless omission and responsive wrapping at 1440px, 1024px,
390px and the 720px 200%-reflow equivalent. Existing classification,
Collection, ReadingRound lifecycle/history and Private Notes coverage remains
green.

This slice adds no create/edit/publish/withdraw/moderation flow, reactions,
comments, recommendation engine, new aggregate, profile, description,
provider/cover behavior, Collection editing, schema or Elementor logic.

## 6. Verification and versions

- targeted Catalog/REST/Ratings & Reviews integration: 94 tests and 2,128
  assertions across the focused runs;
- complete Core gate: Composer metadata/platform, PHP syntax, PHPStan, 410 unit
  tests/1,738 assertions, 344 MariaDB integration tests/4,215 assertions,
  WordPress smoke, manifest and whitespace passed;
- isolated Biblio UI smoke and complete JavaScript suite: 223 tests passed;
- complete guarded Chromium suite: 53 tests passed, including assessment,
  classification, Collections, ReadingRound and Private Notes regression;
- fixtureguards, double cleanup, zero fixture residue and identical pre/post
  non-fixture fingerprint passed; and
- explicit independent second review found no architecture, authorization,
  privacy, lifecycle, accessibility, regression or scope blocker.

Schema remains `1017`. Biblio UI is `0.10.0` for changed JavaScript/CSS asset
cache invalidation. Screenshot evidence is ignored under
`.local/book-api-03-screenshots/after/`.
