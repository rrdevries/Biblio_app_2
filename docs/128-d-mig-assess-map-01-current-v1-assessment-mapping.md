# D-MIG-ASSESS-MAP-01 — Current V1 assessment mapping

Status: **DESIGN GO**

Date: 2026-09-17
Scope: reviewed CURRENT V1 mapping for Rating, Review and Reflection. This is a
docs/design decision only: no production mapper, participant, schema change,
apply/import or source mutation is included.

## 1. Decision inheritance

The authority order for this decision is:

1. the accepted V2.001 Rating/WrittenReview contract in the functional design,
   current-state documentation and ASSESS-MIG-01;
2. the implemented schema 1026 domain, persistence and owner-read boundaries;
3. MIG-FND, CAT, READ and reconciliation contracts;
4. the immutable CURRENT V1 source evidence described in §2.

The following inherited decisions are unchanged:

- Rating and WrittenReview are separate user-owned Work sources;
- each may optionally link to an exact same-owner/same-Work ReadingRound;
- at most one unlinked source of each type exists per user + Work, and at most
  one of each type exists per user + ReadingRound;
- historical assessment time is nullable and never filled with import time;
- `HistoricalAssessmentRecorder` creates private product sources and cannot
  create a Library publication;
- explicit migration target User and Library Context are validated without an
  actor/admin/owner fallback;
- Work resolution uses the exact committed CAT source mapping, never title,
  ISBN or Edition lookup;
- the closed Private Note contract does not accept Rating, Review or Reflection;
- assessment evidence does not alter ReadingRound outcome, Personal Reading
  Truth or reread state; and
- preservation/quarantine and replay evidence belong to MIG-FND, while the
  typed product source remains normal V2 product data.

The earlier MIG-01 mapping decision already defined the legacy Rating value as
the same 1–5 star meaning and the target representation as V2 half-units. This
design revalidates that rule against the designated CURRENT schema-29 source;
it does not inherit old snapshot counts as current evidence.

This design explicitly supersedes one historical MIG-01 disposition: that
table quarantined Reflections while their Note-versus-Review meaning was open.
The current task establishes that valid Reflection evidence without an exact
V2.001 target is preserved deferred, not quarantined and not semantically
flattened. No other historical source count is promoted to CURRENT truth.

## 2. CURRENT source evidence

Only this designated source is authoritative:

```text
ZIP: /Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
validated extraction:
/Users/renee/Documents/Websites/Biblio_app_2/.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/
ZIP SHA-256:
835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c
manifest:
35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67
adapter: current-v1-json-29
family/version: biblio-v1 / books-29.authors-2.reading-goals-2
```

The read-only validation found exactly 3,587 manifest files, with zero missing
or extra paths, zero size mismatches and zero file-hash mismatches. A fresh
deterministic digest over that validated manifest equals the declared digest.
The intake copy of `data.zip` has the required ZIP SHA-256. The extraction was
not recreated or modified.

`data/books.json` is schema 29, contains 1,139 Books and has SHA-256
`b38ef882d5da30d17a9cee6991344a55dbb7166f05fb0b2ca5f4e5b2ebfb16c3`.
All 20 assessment parent Books have a stable non-empty source `id`.
The assessment audit read structure and privacy-safe aggregates only. No Review
or Reflection body or exact private timestamp is recorded in this document,
logs or committed fixtures.

## 3. Existing V2 assessment canon

V2.001 has first-class `Rating` and `WrittenReview` aggregates. Both are owned
by one User, concern one immutable Work, may have a nullable ReadingRound and
may exist without a Library or publication. A separate
`ContributionPublication` is required for Library-visible output.

V2 Rating accepts exactly 1.0 through 5.0 stars in half-star steps and stores
2 through 10 half-units. `RatingValue` owns that exact representation.

V2 Review is normalized plain text of zero through 5,000 Unicode code points.
Invalid UTF-8 and NUL are rejected, CRLF/CR normalize to LF and HTML stays
literal text. The CURRENT Review is valid under this policy: it is non-empty,
76 Unicode code points, contains no NUL or CR and is within the target limit.

Schema 1021 added nullable `assessed_at` to both product tables; schema 1026
retains that contract. `created_at` and `updated_at` are V2 technical record
times, not source assessment time. The current schema already supports every
accepted active target in this design.

V2.001 has no first-class Reflection aggregate, table, source-neutral recorder,
owner projection or publication model. The canonical product vocabulary names
Rating, WrittenReview and Private Note separately; Reflection is not an alias
for any of them.

## 4. Rating source shape

Every Book has one scalar `rating` field. All 1,139 values are numbers and, in
the designated snapshot, integers:

| Value | Count | Meaning for enumeration |
|---:|---:|---|
| 0 | 1,124 | empty slot / no Rating observation |
| 1 | 1 | positive Rating observation |
| 3 | 1 | positive Rating observation |
| 4 | 7 | positive Rating observation |
| 5 | 6 | positive Rating observation |

There are no missing, null, negative or fractional values. The reviewed CURRENT
adapter likewise enumerates only integer values greater than zero as Rating
occurrences. Thus there are 15 Rating observations on 15 distinct Books.
Values 4 and 5 repeat across Books, but value equality is never occurrence
identity or deduplication evidence.

The scalar has no nested ID, timestamp, ReadingRound reference, visibility or
publication flag. The source contains no Rating history. It proves one current
slot per Book, but cannot recover a value that may have been overwritten before
the snapshot.

Book-level `updatedAt`, `reviewedAt`, reading status and nested ReadingRounds
are not structurally part of the Rating slot and must not be reused as Rating
metadata.

## 5. Rating target and scale

Each positive CURRENT Rating is an `EXACT_TARGET` for one private, unpublished
V2 Rating, subject to its exact CAT Work dependency.

The semantic scale is the inherited 1–5 star scale and is compatible with the
implemented V2 1.0–5.0 scale. The mapper passes the source star value to
`RatingValue::fromStars`; the resulting half-unit integer is V2's canonical
storage representation, not a new 0–100, 1–10 or otherwise normalized score.
Zero is never mapped to a Rating.

No Rating gains a ReadingRound, publication or assessment timestamp. For all
15 plans:

```text
target_user_id = explicit validated migration target User
target_work = exact dependency v1.book/<book-id>/work
target_reading_round = null
rating_stars = exact positive source integer
assessed_at = null
publication = none
```

## 6. Rating identity and cardinality

The schema-29 structure proves exactly one Rating slot per source Book. Its
deterministic occurrence identity is therefore:

```text
v1.book/<book-id>/rating
```

The numeric value is payload evidence and never part of identity. A changed
value under the same immutable source identity is divergent replay and fails
closed; it is not a second Rating and does not silently update a committed
target.

All CURRENT Ratings are unlinked. The target permits exactly one unlinked
Rating per user + Work. The CAT alias audit finds no pair of CURRENT Ratings
that converges to the same Work, so all 15 satisfy target cardinality.

## 7. Review source shape

Every Book has a `reviews` array. The length distribution is:

| Array length | Books |
|---:|---:|
| 0 | 1,138 |
| 1 | 1 |

The sole occurrence has exactly two fields:

```text
text: string
date: string
```

`text` is present and valid under the V2 Review content policy. `date` is a
valid explicit UTC ISO-8601 instant and is not after the source snapshot. The
occurrence has no standalone ID, ReadingRound reference, visibility or
publication flag. It is nested directly under its parent Book and is distinct
from that Book's `notes[]` and singular `reflection` fields.

The designated package contains data, not an authoritative V1 presentation
contract, and its Review structure carries no public/private state. Whether V1
ever presented this Review to another user is therefore not proven. No public
state is inferred from the field name.

The array shape can represent more than one occurrence even though CURRENT has
only one. This design therefore does not claim a global one-Review-per-Book V1
invariant beyond the pinned manifest.

## 8. Review target and privacy

The sole CURRENT Review is an `EXACT_TARGET` for one private, unpublished V2
WrittenReview. Existing ASSESS-MIG-01 canon expressly permits this default when
historical source data has no publication evidence; no new privacy default is
invented here.

```text
target_user_id = explicit validated migration target User
target_work = exact dependency v1.book/<book-id>/work
target_reading_round = null
content = exact source text through ReviewContent policy
assessed_at = exact source review.date UTC instant
publication = none
```

The mapping does not rewrite, summarize, translate, label or copy the content
into Private Note. `ReviewContent` may perform only its already accepted line
ending normalization and validation. Technical V2 creation/update time may be
the recorder's transaction time, but it is never presented or stored as the
historical assessment time.

## 9. Review identity

The Review array has no occurrence ID. For this immutable manifest, JSON array
order is byte-stable and occurrence identity is otherwise absent. The source
identity is therefore the parent Book plus one-based array position:

```text
v1.book/<book-id>/review/1
```

Body text, date and target ID are excluded from identity. The complete payload
hash remains replay evidence. A future source manifest with multiple Reviews,
reordered entries or divergent payload requires a fresh reviewed mapping and
must not be collapsed into the current identity.

## 10. Reflection source shape

Every Book has one scalar string field named `reflection`. Five Books have a
non-empty value; the other 1,134 slots are empty. Each non-empty value is one
Book-level occurrence. There is no standalone ID, timestamp, subtype,
ReadingRound relation, visibility/publication field or order beyond its single
structural slot.

Privacy-safe hashing proves that the five populated bodies are mutually
distinct, but that fact has no semantic or identity effect.

The deterministic preservation identity is:

```text
v1.book/<book-id>/reflection
```

The body is restricted private evidence. Content is not inspected to decide
sentiment, Note/Review equivalence, quotation, summary or any other semantic
class.

## 11. Reflection target and disposition

V2.001 has no accepted first-class Reflection target, and this slice does not
create one merely for five records. Under the explicit preservation rule for
valid evidence without an exact active target, all five Reflections are:

```text
PRESERVE_DEFERRED
reason: reflection_target_not_available
```

They are not quarantined because their source shape is valid and
non-contradictory. They are not transformed into Private Note, WrittenReview
or a ReadingRound comment. Restricted preservation retains Book identity,
structural slot, exact body and payload hash for possible later promotion,
without placing body text in dry-run artifacts, logs or exceptions.

## 12. ReadingRound dependency

The 21 observations contain zero explicit ReadingRound references. Fourteen of
their 20 parent Books happen to contain at least one ReadingRound, but that is
descriptive overlap only and creates no relationship.

No assessment plan depends on a Round. The mapper must not choose a Round from
count, order, date proximity, completed state or Book parentage. READ mapping,
ReadingRound outcome and Personal Reading Truth remain unchanged.

## 13. Work and User dependency

Every active Rating or Review plan requires:

- the explicit validated `target_user_id` from the migration target;
- the exact CAT source mapping `v1.book/<book-id>/work`; and
- the same immutable source manifest and payload evidence.

No title, ISBN, Edition, display name, current actor, administrator, Library
Owner or first-user lookup is permitted. The explicit `target_library_id`
remains part of run validation and CAT context; it neither owns these personal
sources nor creates a publication.

If the exact Work dependency is absent, foreign, broken or quarantined, the
assessment cannot create a dangling product row. It is accounted as preserved
deferred or quarantined according to the dependency failure, with no fabricated
Work.

## 14. Timestamps

| Source type | Source assessment time | V2 mapping |
|---|---|---|
| Rating | none | `assessed_at = NULL` |
| Review | one explicit UTC `date` | exact instant to `assessed_at` |
| Reflection | none | retained only in restricted preservation; no invented time |

Book update time, ReadingRound time, export time, migration time and current
time are never substituted for assessment time. Technical V2 record time stays
separate under the existing historical recorder contract.

## 15. Alias and conflict handling

CAT currently has 17 duplicate-ISBN groups containing 34 Books. Exactly one of
the 20 assessment parent Books is in such a group: it is a CAT representative
and carries one Rating with value 4. No other member of that group carries a
Rating, Review or Reflection. Therefore CURRENT produces no converged
assessment group, duplicate target or cardinality conflict.

The implementation contract remains fail-closed for changed source evidence:

- preserve each occurrence's own source identity even when Work converges;
- never choose the CAT representative as an assessment winner;
- two unlinked Ratings or Reviews converging on one user + Work are a conflict,
  not last-write-wins;
- equal values or bodies do not make separate occurrences duplicates; and
- no content, value or timestamp equality may authorize convergence.

## 16. CAT-quarantined evidence

CAT has two quarantined source Books because of invalid ISBN evidence. Neither
contains a positive Rating, Review or non-empty Reflection. Therefore:

```text
assessment observations on CAT-quarantined Books: 0
unmatched assessment Work dependencies: 0
```

The later mapper must still enforce this dependency dynamically and account a
future affected occurrence without creating a dangling relation.

## 17. Typed migration contract

No registered source-neutral `MigrationParticipant` currently accepts Rating,
WrittenReview, Reflection or a generic Assessment plan.
`HistoricalAssessmentRecorder` is an existing non-transaction-owning product
writer for private Rating and WrittenReview, but it is not itself the runner's
typed plan/participant boundary.

The smallest later source-neutral extension is two distinct plan types and two
participants, composed through the existing recorder inside
`CommitMigrationRecordService`:

```text
HistoricalRatingPlan
- source identity and payload hash
- explicit target User
- exact Work source dependency / resolved WorkId
- optional ReadingRound source dependency / resolved ReadingRoundId
- RatingValue
- nullable assessed_at

HistoricalWrittenReviewPlan
- source identity and payload hash
- explicit target User
- exact Work source dependency / resolved WorkId
- optional ReadingRound source dependency / resolved ReadingRoundId
- ReviewContent
- nullable assessed_at
```

The CURRENT-specific `CurrentV1AssessmentMapper` belongs between adapter
enumeration and those source-neutral plans. It creates no generic
`AssessmentPlan`. Reflection yields preservation evidence only and needs no
product participant.

## 18. Preservation and quarantine

Valid source evidence without an exact target is `preserved_deferred`.
Malformed values, impossible timestamps, broken dependencies, alias
cardinality conflicts, divergent replay and contradictory state quarantine or
fail closed under existing MIG-FND rules.

Preservation and dry-run output may contain allowlisted source identities,
payload hashes, counts, target dependency identities and reason codes. They
must not contain Review/Reflection bodies or their exact timestamps. Exceptions
and logs follow the same prohibition.

There is no silent drop and no `INTENTIONALLY_DROPPED_WITH_REASON` outcome in
this design.

## 19. CURRENT counts

### Rating

| Measure | Count/result |
|---|---:|
| total active observations | 15 |
| populated positive values | 15 |
| unique parent Books | 15 |
| distribution | 1×1, 3×1, 4×7, 5×6 |
| source timestamps | 0 |
| explicit Round references | 0 |
| observations in CAT alias groups | 1 |
| observations on CAT-quarantined Books | 0 |

### Review

| Measure | Count/result |
|---|---:|
| observations | 1 |
| body present and target-valid | 1 |
| source timestamp present and valid | 1 |
| visibility/publication state | absent |
| explicit Round references | 0 |
| observations in CAT alias groups | 0 |
| observations on CAT-quarantined Books | 0 |

### Reflection

| Measure | Count/result |
|---|---:|
| observations | 5 |
| unique parent Books | 5 |
| bodies present | 5 |
| source timestamps | 0 |
| explicit Round references | 0 |
| standalone IDs / array positions | 0 / not applicable; singular slot |
| observations in CAT alias groups | 0 |
| observations on CAT-quarantined Books | 0 |

### Structural overlap

| Combination per Book | Books |
|---|---:|
| Rating only | 14 |
| Review only | 0 |
| Reflection only | 5 |
| Rating + Review | 1 |
| Rating + Reflection | 0 |
| Review + Reflection | 0 |
| Rating + Review + Reflection | 0 |
| none | 1,119 |

The single Rating + Review overlap is only shared Book parentage. CURRENT has
no composite assessment object or relation joining the two occurrences.

### Result

| Outcome | Count |
|---|---:|
| active Rating plans | 15 |
| active WrittenReview plans | 1 |
| active Reflection plans | 0 |
| preserved_deferred | 5 |
| quarantined | 0 |
| alias/cardinality conflicts | 0 |
| unmatched Work/Round dependencies | 0 |
| total accounted observations | 21 |

## 20. Downstream effects

After the separately authorized implementation and later apply/import, the
target owner can read 15 private Ratings and one private WrittenReview through
the existing owner-only Work projection. The Rating and Review on the same Book
remain separate sources. None is visible in a Library, contributes to a public
aggregate or receives mutation/publication UI merely through migration.

The five Reflections are not displayed as Notes or Reviews. They remain
restricted migration preservation data until an explicit future product target
and promotion contract exist.

No assessment changes Item, classification, archive, circulation, Wishlist,
ReadingRound, Personal Reading Truth, reread state or public contribution
state.

## 21. Product questions

None. Existing canon resolves private/unpublished historical Rating/Review,
the 1–5 Rating meaning and unknown assessment time. The explicit default for a
valid Reflection without a V2.001 target is `PRESERVE_DEFERRED`. CURRENT has no
alias conflict requiring a tie-break decision.

## 22. Required implementation slice

Recommended bounded follow-up after this DESIGN GO:

```text
MIG-02-ASSESS-MAP-01 — Current V1 assessment mapper
```

Scope:

- add separate source-neutral HistoricalRatingPlan and
  HistoricalWrittenReviewPlan participants around the existing recorder;
- add `CurrentV1AssessmentMapper` for the exact adapter/version/manifest;
- derive the three reviewed structural source-identity forms;
- require exact target User and CAT Work dependencies;
- emit 15 Rating plans, one WrittenReview plan and five privacy-safe
  `preserved_deferred` Reflection outcomes;
- fail closed on changed scale/shape, invalid content/time, missing Work,
  alias cardinality conflict and divergent replay;
- integrate exact 21-observation reconciliation; and
- prove a deterministic zero-write dry-run without source text leakage.

Excluded: apply/import, Reflection product model, publication, Note routing,
ReadingRound inference, schema/REST/UI changes, Series, Wishlist and Archive.

## 23. Acceptance criteria

The implementation slice is acceptable only when:

1. it is bound to adapter `current-v1-json-29`, the exact source family/version
   and manifest in §2;
2. all 15 Ratings map as private unlinked V2 Ratings with exact star values and
   `assessed_at = NULL`;
3. the one Review maps as a private unlinked WrittenReview with exact content
   and exact source `date` as `assessed_at`;
4. all five Reflections are restricted `preserved_deferred` evidence and never
   Private Notes or WrittenReviews;
5. source identities use Book slot/array position only, never content, value,
   timestamp or target ID;
6. exact target User and CAT Work dependencies are mandatory and no fallback
   lookup exists;
7. no ReadingRound, publication, technical/content timestamp or reading truth
   is inferred;
8. alias convergence and target cardinality are checked before planning and
   fail closed on conflict;
9. all 21 observations reconcile exactly with zero unexplained, unmatched or
   silently dropped records;
10. dry-run/artifacts/logs/errors contain no Review or Reflection body or exact
    private timestamp;
11. profile and dry-run perform zero product and MIG-FND writes; and
12. focused tests, manifest/checksum validation, privacy scan, diff validation
    and independent review pass.

## 24. Current V1 data rule

This decision is valid only for the designated ZIP, validated read-only
extraction, adapter, source family/version and manifest in §2. Do not
re-extract, substitute DATA-01/MIG-01/another export, write into the source
root or use provider/network enrichment. A changed byte set, field shape,
scale signal or source relationship requires fresh review.

## 25. Schema, Core, UI and Git impact

This design changes documentation only.

- product: `v2.001` unchanged;
- schema: `1026` unchanged;
- Biblio Core: `2.42.0` unchanged;
- Biblio UI: `0.20.0` unchanged;
- source: read-only and unchanged;
- migration apply/import: not run;
- network/provider calls: none.

The baseline was clean at `f8cbc25f6e5118075f6746ac08b7b8692fc24f34`.
Exactly one local docs commit is permitted after validation and independent
review. Nothing is pushed without Renée's explicit authorization.
