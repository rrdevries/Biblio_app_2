# 30 — B7 Ratings & Reviews public read foundation

Status: **GO / CLOSED**

Date: 2026-09-03
Begin-HEAD: `e7fb103b8439e9289c115c7b1010cb5f3dfcdc43`
Capability: B7 — Ratings/Reviews Library-public read boundary

## 1. Scope and binding audience

B7 resolves the former Ratings/Reviews audience question. A publication to a
Library is not internet-open. Public assessment reads require an authenticated
WordPress actor for whom Biblio Core `canViewCollection` permits access to the
explicit Library.

`GetLibraryPublicAssessmentsService` calls the existing
`LibraryContextQueryService::get()` before repository access. That service
derives the actor from `AuthenticatedUser`, resolves Library plus membership
through the actor-scoped context repository and applies the canonical
`LibraryAuthorizationPolicy::canViewCollection`. The REST controller and
serializer contain no membership, role or capability decisions. Missing,
inactive and foreign Library contexts share the existing non-enumerating 404.

## 2. Personal average correction

Previously `AssessmentQueryService::ownAverage()` reused
`findForUserAndWork()`, whose presentation list is capped at 100 rows. The
average could therefore omit valid reread Ratings.

The service now calls the dedicated repository operation
`aggregateForUserAndWork()`. MariaDB computes `COUNT(*)` and the exact sum of
stored half-units under the owner + Work predicate. It counts private and
published source Ratings alike and excludes other actors and Works. The
existing `ratings_by_user_work` index supports the predicate; no new index,
schema or migration is needed.

Regression evidence covers empty, one, multiple and 101 own Ratings, including
one published Rating, plus foreign-actor and other-Work exclusions. The former
list still demonstrably returns only 100 while the aggregate returns 101.

## 3. Public readmodel and paging

The new application read repository returns a mixed list of visible Rating and
Review publications for one Library + Work. The list intentionally retains
multiple visible historical contributions from the same author. Rating and
Review remain independent: either can be public while the other remains
private, and a Review exposes an associated score only when the matching
Rating has its own currently visible publication in the same Library.

List filters retain the F2.8 contract:

- active author publication state;
- visible moderation state;
- exact Library and Work;
- at least one active Item representing the Work in that Library.

Ordering is `publication.updated_at DESC, publication_id DESC`. Keyset paging
uses `limit + 1`, an opaque versioned cursor, default 20 and maximum 50. The
cursor is transport-only; source owner and private ReadingRound context never
enter the response.

The aggregate remains deliberately different from the list. It selects only
the most recently updated currently visible Rating per User + Work, with
`rating_id DESC` as tie-breaker, then sums integer half-units. The canonical
3/5 stars from user A plus 4 stars from user B scenario returns 4.5 and two
voters, not 4.0 and three rows.

## 4. REST contract and privacy

One route is added:

`GET /biblio/v1/libraries/{library_id}/works/{work_id}/assessments`

The allowlisted response contains:

- `library_id` and `work_id`;
- `contributions`: type, current display name, publication time, public Rating
  and/or escaped Review HTML according to contribution type;
- `aggregate`: final one-decimal average or null plus voter count;
- `next_cursor`.

It excludes user IDs, source IDs, publication IDs, ReadingRound IDs and dates,
Item/source data, memberships, moderation reasons, provenance, private source
content and technical update/version fields. Cookie authentication, the
standard `X-WP-Nonce`, typed identifiers, exact query allowlisting and central
response/error handling are reused from F2.12.

Assessment unavailable failures now map to the shared non-enumerating 404.
Duplicate and stale source/publication failures map to 409. Publication
ineligibility maps to 422 without disclosing a distinct missing/foreign target.

## 5. Regression evidence

The focused and complete tests prove:

- 101-row personal aggregate and actor/Work isolation;
- authentication, nonce and `canViewCollection` access;
- identical missing/inactive/foreign Library failures;
- allowlisted DTOs and escaped Review output;
- stable multi-page traversal without omitted or repeated normal-state rows;
- multiple public reread Ratings in the list but one selected aggregate vote;
- withdraw/hidden/removed/source-delete fallback to the older valid Rating;
- author membership loss without automatic publication withdrawal;
- active Work representation suppression and restoration;
- public Rating/private Review and public Review/private Rating independence;
- hidden Review isolation and cross-Library isolation;
- deterministic Rating-ID tie-breaking;
- central assessment error mapping.

The complete gate passes PHP syntax, PHPStan level 6, Composer metadata and
platform requirements, unit 252/252 (977 assertions), real-MariaDB integration
253/253 (2,969 assertions), WordPress smoke, manifest JSON and Git whitespace.

## 6. Non-scope

B7 adds no schema 1009, migration, Rating/Review owner write route, owner UI,
public UI, moderation-management UI, Elementor/Crocoblock change, Timeline,
ActivityEvent or permanent local testdata. Existing C7 manual data is not
altered.

## 7. Verdict

**GO. B7 is closed and the repository is ready for the separately scoped full
Ratings & Reviews UI build.**
