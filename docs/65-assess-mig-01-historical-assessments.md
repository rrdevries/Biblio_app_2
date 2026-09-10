# ASSESS-MIG-01 — Historical assessments

Status: **GO / CLOSED** after the gates and independent review recorded below.

Date: 2026-09-10
Scope: source-neutral private Rating/WrittenReview preservation, owner read and
historical assessment-time truth. No V1 parser or data import.

## 1. Domain audit

`Rating` and `WrittenReview` are separate user-owned sources keyed to one Work.
They have independent lifecycle, validation and publication. Each may link to
one ReadingRound, but only when that Round belongs to the same user and Work;
an unlinked source is valid and rereads can retain distinct linked sources.

`ContributionPublication` is a separate record for exactly one Library and
exactly one Rating or WrittenReview. It never transfers source ownership.
Before this slice, B7/BOOK-API-03 projected only active+visible publications;
owner repositories existed for application mutations/lists, but Book Detail
had no owner-readable route for an unpublished migrated source.

Before schema 1021, `created_at` and `updated_at` were technical timestamps and
normal creation supplied the same current instant that also represented when
the current assessment was made. There was no separate nullable business-time
field, so unknown historical time could not be represented truthfully.

## 2. Historical assessment-time model

Schema 1021 adds nullable `assessed_at DATETIME(6)` to both source tables.

- `assessed_at`: known user assessment instant, or NULL when unknown;
- `created_at`: technical V2 record/import instant;
- `updated_at`: technical last-change instant;
- `published_at`: unchanged publication instant on the separate publication.

Normal V2 creation sets `assessed_at`, `created_at` and `updated_at` to the same
current Core clock instant. Historical factories accept a known instant or
NULL independently from technical record time. Updates preserve `assessed_at`.
No import/export/run time is presented as a historical assessment time.
An assessment instant later than its technical creation instant fails closed
as an impossible chronology.

For pre-1021 supported native V2 rows, the linear migration copies `created_at`
to `assessed_at`: before this historical boundary, supported creation assigned
both meanings together. The additive migration recognizes source/target shape,
finishes the backfill after an interrupted two-column ALTER, rejects a partial
one-column state, and permits new NULL business times after version 1021.

## 3. Private owner read and Book Detail

`GetOwnAssessmentsForWorkService` is the single owner boundary. It resolves the
authenticated actor, reauthorizes the explicit Library Context and queries only
that owner plus the Item-derived Work. The DTO allowlist contains assessment
type, type-specific value/content, optional business time and only a boolean
that indicates a ReadingRound relation. It exposes no user, source, round,
publication or moderation ID and no technical timestamp.

Multiple owner sources use a stable technical ordering only; the UI never
labels that order as historical chronology and displays only `assessed_at` as
the assessment date.

Book Detail serializes this list as `assessments.own_not_visible`, separate from
the established public `contributions`, `aggregate` and `next_cursor`. The UI
labels it `Alleen voor jou`, marks it not visible in this Library, and renders
`Beoordelingsdatum onbekend` for NULL. It adds no write/edit/publish controls.

## 4. Publication and aggregate isolation

The historical recorder has no publication repository or Library fallback, so
recording cannot create a publication. The owner read excludes a source only
when that exact Rating/Review ID has an active+visible publication in the exact
current Library. This prevents a duplicate public/private presentation without
content-based matching. A publication in another Library does not globalize
the source. The existing B7 route and Library aggregate SQL are unchanged;
unpublished private Ratings cannot contribute.

## 5. ReadingRound semantics

The relation remains nullable. Historical recording never selects the latest
or active Round and never creates a Round. A supplied target ID is locked and
must match the exact owner and Work; foreign-owner and other-Work relations
fail with the existing non-enumerating assessment-unavailable failures.
ReadingRound history, reread behavior and PersonalReadingTruth are unchanged.

## 6. Migration boundary and MIG-FND

`HistoricalAssessmentRecorder` is source-neutral and does not own a transaction.
Inside `CommitMigrationRecordService` it validates an active explicit owner,
existing Work and optional exact Round, then writes private product data. MIG-FND
owns source observation, product write, target mapping and disposition in one
transaction. Synthetic integration proves commit, trace, rollback without a
false mapping, committed retry without another product write, and quarantine
for unrepresentable assessment input. No `/data/` parser or import command was
added.

## 7. Privacy and authorization

Private text is selected by authenticated owner ID only. Library membership or
management role never grants another user's private read. Library Context is
still mandatory because Book Detail is Library-scoped, but it does not replace
owner authorization. Exceptions/logs contain no assessment content. Public
cross-Library and visibility rules remain unchanged.

## 8. Verification evidence

Synthetic guarded fixtures cover known/unknown time for both contribution
types, rating-only/review-only/both, linked/unlinked Round, wrong-owner/work
Round rejection, owner/cross-user isolation, same Work across Libraries,
private aggregate exclusion, own-publication dedup, strict REST decoding,
technical-time non-presentation, schema migration and MIG-FND behavior.

Final green gate results:

- Core unit: 430 tests, 1822 assertions;
- isolated MariaDB integration: 378 tests, 4520 assertions;
- frontend unit: 238 tests;
- guarded Chromium E2E: 56 tests, including private/public Book Detail
  separation, known/unknown assessment time, cross-Library projection,
  idempotent cleanup and unchanged non-fixture fingerprint;
- PHP source/test and E2E-fixture syntax, PHPStan, Composer/platform,
  WordPress Core/UI smoke and Elementor clean import: passed;
- manifest JSON, JavaScript syntax and `git diff --check`: passed;
- independent second diff review: no blocker.

## 9. Actual V1 data rule

No MIG-01 ZIP/snapshot, `.local/fixture-source`, DATA-01, earlier `/data/` copy,
old assessment record or old count was used as current V1 truth. Current V1
data was not needed. The only current-safe claim retained is: MIG-01 showed
that historical assessments without reliable assessment time and without
publication can occur. Any future source-dependent analysis or import requires
a current `/data/` explicitly designated by Renée and pinned by its MIG-FND run.

## 10. Versions and non-scope

- schema: `1021`;
- Biblio Core: `2.2.0`;
- Biblio UI: `0.13.0`;
- product: unchanged `v2.001`.

Outside scope remain the V1 parser/import, current source analysis, rating or
review editor, publish/withdraw/moderation UI, aggregate redesign and every
other domain named in the slice brief.
