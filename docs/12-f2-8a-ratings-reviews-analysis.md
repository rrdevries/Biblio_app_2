# 12 — F2.8a Ratings & Reviews analysis

Status: **GO WITH CONDITIONS**

Scope: Biblio V2 v2.001, documentation-only analysis for F2.8b. This document
does not implement production code, tests, migrations, schema, UI, REST,
Timeline, global search or moderation infrastructure.

## 1. Baseline and repository state

- branch: `main`;
- analysis baseline: `3f615292dd66b0e05e18145af930a2c874ad7335`;
- F2.6 ReadingRounds: implemented and closed with GO;
- F2.7 Private Notes: implemented and closed with GO;
- formal schema baseline: 1000;
- current schema: 1004, with 16 Biblio-owned Core tables;
- repository was clean and equal to `origin/main` at analysis start.

No Rating, Review or contribution-publication domain class, application
service, repository, table, migration or test exists at this baseline. The
existing Core does provide the authenticated actor boundary, Library Context,
membership authorization, active Work representation, Work and ReadingRound
repositories, transactions, optimistic-CAS patterns, opaque ID generators,
schema migrations/health and append-only Library ActivityEvents.

## 2. Source and conflict matrix

The labels in this document mean:

- **canonical**: current approved product behavior;
- **consequence**: architecture required to preserve a canonical rule;
- **recommendation**: technical choice that does not invent product behavior;
- **open**: a product choice needed before F2.8b can implement safely;
- **deferred**: explicitly outside F2.8b.

| Source | Binding evidence | Conflict/gap resolution |
| --- | --- | --- |
| `docs/00-current-state.md` | Ratings/Reviews are private Mijn Biblio data owned by the user | Canonical ownership boundary; current state contains no implemented F2.8 slice |
| `docs/01-functional-design.md` §12 | Separate Rating and Review, optional Round, at most one unlinked contribution per type/User/Work, independent publication, eligibility, averages and moderation | Most detailed canonical product source; undefined details remain open rather than inferred |
| `docs/01-functional-design.md` §14/§16 | Beoordelingen may feed personal Timeline; personal ratings/reviews are excluded from Library audit | Timeline implementation remains deferred; publication/moderation audit is genuinely ambiguous |
| `docs/02-architecture.md`; ADR-001/003 | User-owned and Library-scoped boundaries are distinct; optional Library reference never transfers ownership | Requires separate owner-scoped source and authorized Library publication behavior |
| ADR-002/004/005 | Core owns workflows; custom tables are the proven baseline where integrity/concurrency require them; schema chain is formal and forward-only | Supports schema 1005, but does not decide product semantics |
| ADR-006 and current catalog code | Library Work presence is derived through Item → Edition → Work within explicit Library Context | Publication eligibility must reuse an explicit active-representation query, not trust a client Work/Library pair |
| ADR-007 and F2.6 evidence | ReadingRound has immutable owner/Work, owner-scoped CAS and conditional historical delete | Proven pattern only; it does not decide Rating/Review Round lifecycle automatically |
| F2.7 analysis/evidence | Optional same-user/same-Work Round context, strict private content, CAS and `SET NULL` are proven for Notes | Useful technical precedent, but the Note content and deletion contracts are not inherited by Reviews |
| `docs/03-scope-and-deferred.md` | Ratings/Reviews are v2.001 scope; social/public-profile/global-feed functions are deferred | F2.8b should stop at Core contribution/publication/read projections |
| `docs/04-terminology.md` | Rating is a private user-owned Work rating; Review is an independently publishable written contribution to one Library context | Confirms separate records and one publication context per contribution |
| `docs/05-source-register.md` | Historical “Gebruikersdata, leesrondes & beoordelingen” remains useful only with later corrections | The named raw handover is not present in Git history or the current checkout; it cannot override or fill gaps in canonical docs |
| `docs/06-testing-and-acceptance.md` §2/§14 | Private isolation, one explicit Library, active Item and no moderator text edits | Binding acceptance floor, not a complete implementation contract |
| Current production/tests/schema 1004 | No Ratings/Reviews implementation exists; current Item domain is active-only and `LibraryWorkRepresentationRepository` does not yet express status explicitly | F2.8b must add a status-explicit eligibility port and must not mistake current active-only storage for a permanent archive rule |

There is no direct contradiction between available canonical sources. There
are under-specified areas. In particular, “may disappear” when Work presence
ends is not a deterministic visibility rule, and “hide/delete” does not define
a moderation lifecycle. Those phrases cannot be converted into code without a
product decision.

## 3. Functional contract matrix

| Subject | Status | F2.8 contract |
| --- | --- | --- |
| Ownership | Canonical | Rating and Review remain owned by their authenticated user, including after publication |
| Rating versus Review | Canonical | They are independent contribution types, not nullable fields on one Assessment record |
| Work | Canonical + recommendation | Each contribution concerns one Work; use immutable Work identity and explicit delete/recreate for a wrong Work, subject to O3 |
| ReadingRound | Canonical + open | Optional context; same-user/same-Work is mandatory, but cardinality, eligible states, correction and delete interaction require O2 |
| Unlinked contribution | Canonical | At most one unlinked Rating and one unlinked Review per User + Work |
| Multiple reads | Canonical | Multiple Ratings/Reviews for the same Work are possible through rereads |
| Private creation | Canonical | Requires no Library membership or context |
| Independent publication | Canonical | Rating-only and Review-only publication are valid; publishing one never publishes the other |
| Publication target | Canonical | One contribution has at most one chosen Library publication context; never duplicate automatically |
| Publish eligibility | Canonical | Owner is an active member of target Library and Work has at least one active Item there at publication time |
| Use access | Canonical | No Direct/Borrow/ViewOnly restriction is stated; active membership is sufficient |
| Ownership transfer | Canonical | Publication does not transfer contribution ownership to Library |
| Moderation | Canonical + open | Owner/authorized Manager may affect public visibility but never edit source content; exact lifecycle needs O5 |
| Membership loss | Canonical + open | Publication context is not automatically deleted; continuing public visibility needs O4 |
| Work presence loss | Open | Source says publication may disappear and later reappear; O4 must replace “may” with a deterministic rule |
| Personal average | Canonical + open | Uses owner’s valid Ratings; set membership, weighting and rounding need O7 |
| Library average | Canonical + open | Uses only visible Ratings published to that Library; weighting and eligibility-at-read need O7 |
| Platform average | Out of F2.8b | No source requires a platform-wide public average |
| Correction/delete | Open | Owner may correct own content, but identity correction, hard/soft delete and published-source interaction need O3/O4 |
| Public person/context fields | Open | Display name, dates and ReadingRound context are not approved for public output; O6 is required |
| Timeline | Canonical but deferred | “Beoordelingen” is a future personal derived Timeline category; F2.8b adds no Timeline engine |
| Global search/feed | Deferred | No full-text Review search, global feed, profiles, comments, likes or recommendations |

## 4. Gap analysis of current code

| Area | Existing capability | F2.8b gap |
| --- | --- | --- |
| Domain | Work, user, Library, ReadingRound, Note, versions and persisted time constraints | Rating, WrittenReview and ContributionPublication aggregates/value objects/failures |
| Identity | Server-side opaque ReadingRound/Note IDs with bounded collision retry | Type-specific RatingId, ReviewId and PublicationId generators |
| Authentication | Production actor resolved from WordPress server-side | Named owner-scoped Rating/Review services |
| Ownership | Owner predicates and non-enumerating reads proven for Rounds/Notes | Equivalent separate source repositories and projections |
| Library authorization | Active membership and explicit Library Context; role/permission policy | `canPublishContribution` and `canModerateContribution`; moderation permission constant not defined |
| Work presence | `findRepresentedWork(LibraryId, WorkId)` joins Item→Edition→Work | Method does not state active-only semantics; F2.8b needs `hasActiveWorkRepresentation` behavior |
| Persistence | Healthy ordered schema through 1004 | No contribution/publication tables or 1005 migration/health |
| Concurrency | Transactions, row locks, CAS and independent-process tests | Contribution/publication CAS and eligibility-race tests |
| Audit | Append-only Library ActivityEvents for shared Library mutations | Publication/withdraw/moderation event policy unresolved by sources |
| Rendering | Strict Note-specific safe-HTML policy | Review format and public render boundary undefined; Note policy must not be reused blindly |
| Read models | Owner-scoped Round/Note queries | My assessments, public Library contributions and averages |
| Tests | 327 tests in the current canonical gate | No Rating/Review cases |

## 5. Proposed F2.8 Core model

### 5.1 Separate source aggregates

**Consequence:** use two aggregates, not one combined record.

`Rating`:

- opaque `RatingId`;
- immutable owner `UserId`;
- immutable `WorkId` (recommended; O3 confirms correction behavior);
- optional `ReadingRoundId`;
- required `RatingValue`;
- `createdAt`, `updatedAt`;
- positive `RatingVersion`.

`WrittenReview`:

- opaque `ReviewId`;
- immutable owner `UserId`;
- immutable `WorkId` (recommended; O3 confirms correction behavior);
- optional `ReadingRoundId`;
- required non-empty `ReviewContent`;
- `createdAt`, `updatedAt`;
- positive `ReviewVersion`.

Their independence is observable product behavior: a Rating can exist and be
published without a Review; a Review can exist and be published without a
Rating; their Round links and lifecycles need not match.

### 5.2 Separate publication aggregate

**Consequence:** publication is a separate `ContributionPublication`, not a
`library_id` or `is_public` field on Rating/Review.

It contains:

- opaque `ContributionPublicationId`;
- exactly one source reference: Rating XOR Review;
- one `LibraryId`;
- visibility/moderation state selected by O4/O5;
- optional moderation reason only if O5 retains it;
- `publishedAt`, `updatedAt` and positive `PublicationVersion`;
- no ownership transfer; source owner is resolved through the referenced
  contribution rather than accepted from caller input.

This separation is the minimum model that preserves private owner reads while
supporting eligibility, withdrawal, moderation, historical Library context and
public projections. One generic `contribution_type + contribution_id` reference
would lose foreign-key integrity. A single publication table with two nullable
real FKs and an XOR check keeps the model small without an unsafe polymorphic
reference.

The publication is a relationship/lifecycle aggregate. It must never contain
an independently editable copy of rating value or review text. Public reads
join to the current source contribution unless O4/O5 deliberately requires a
moderated snapshot/history model.

## 6. Rating contract

Confirmed:

- a Rating is a required value on a Rating record, not an optional part of a
  combined Assessment;
- it concerns exactly one Work;
- private and published Ratings are the same user-owned source with an optional
  publication relation;
- independent Rating publication means a public rating without Review is valid;
- averages are derived and never stored as a second domain truth.

Open O1 must define scale, minimum, maximum and step. Until then F2.8b cannot
define `RatingValue`, validation, database CHECK, display or aggregation.

After O1, store a scaled integer, never binary floating point. Examples only:
whole 1–5 stars can store 1–5; half-stars can store 2–10 half-units. The value
object owns range/step validation, exact equality and conversion for output.
The database repeats the range/step rule through a CHECK.

## 7. Review content and security contract

Confirmed:

- a Review is a written contribution and therefore requires non-empty content;
- it is independent from Rating;
- publication exposes only content that passed the Core content policy;
- server-side validation and output safety are mandatory regardless of UI;
- public rendering is a stricter XSS boundary than private input storage.

Open O8 must choose plain text or a precise limited-markup allowlist and a
maximum normalized UTF-8 byte length. The Private Note allowlist is not a
Ratings/Reviews requirement and is not inherited.

Recommended default for v2.001 is normalized plain text with output escaping,
because no source requires formatted Reviews. If limited HTML is selected,
F2.8b needs an explicit tag/attribute/protocol grammar, rejection versus
stripping behavior, and revalidation at the public render boundary. In both
variants reject invalid UTF-8, NUL, empty/whitespace-only and over-limit input.
No images, embeds, attachments, scripts or rich content blocks are justified.

## 8. Work and ReadingRound contract

Confirmed:

- Work is the principal identity of Rating and Review;
- ReadingRound is optional context and never owner;
- a link can only target a Round owned by the same user and concerning the
  immutable same Work;
- cross-user and cross-Work links must fail without leaking record existence;
- an unlinked contribution remains valid without a Library or Round;
- at most one unlinked contribution of each type exists per User + Work.

Open O2 must decide:

- maximum one or multiple Ratings/Reviews of the same type per ReadingRound;
- whether active, ended and historical Rounds are all eligible;
- whether a link can later be attached, changed and removed;
- whether legitimate F2.6 hard delete nulls the link or blocks deletion.

**Recommendation:** one Rating and one Review per User + ReadingRound; all own
same-Work Round states eligible; attach/change/remove allowed; legitimate Round
delete uses `ON DELETE SET NULL` without changing contribution value/content,
timestamps or version. This matches “multiple through rereads”, preserves the
contribution when context disappears and avoids making ReadingRound its owner.
It remains a recommendation until O2 is decided.

## 9. Privacy and publication model

### 9.1 Visibility matrix

| Actor/context | Private source | Visible publication | Moderated/withdrawn publication |
| --- | --- | --- | --- |
| Owner | Full owner-scoped source | Source plus publication state | Source remains accessible; publication state according to O4/O5 |
| Other user | Never | Public projection only | Never through public projection |
| Ordinary Library member | Never | Public projection in explicit Library | Never through public projection |
| Library Owner/Manager | Never by role | Same public projection | May see minimum moderation metadata only if authorized; never private source |
| Platform/support role | No implicit access | Same public projection | No private bypass |

Public output may include the published rating value or review text because
that is the purpose of publication. Display name, ReadingRound context and date
semantics are not established and remain O6. Source owner ID, private links,
other contributions and private aggregates never enter the public DTO.

### 9.2 Eligibility at publication time

The `PublishRatingToLibrary`/`PublishReviewToLibrary` transaction must:

1. resolve authenticated actor server-side;
2. lock/load the source by ID + owner;
3. build Library Context from trusted actor and target Library;
4. require an active membership; any management role/use-access is otherwise
   acceptable under the current product contract;
5. require at least one active Item in that Library whose Edition represents
   the immutable Work;
6. revalidate Rating/Review content;
7. enforce one publication relation for that contribution;
8. insert/update publication atomically with version/uniqueness checks.

The Work is derived from the locked contribution. The caller never supplies a
second Work or an owner. A status-explicit active-representation port replaces
the current ambiguous repository name.

### 9.3 Later eligibility loss

Canonical behavior preserves publication context after membership loss and
never changes source ownership. It does not clearly decide continued public
visibility. Likewise, Work-presence loss “may” hide and later show the
publication. O4 must choose whether visibility is:

- derived continuously from current membership and active Work presence;
- retained after valid publication until owner withdrawal/moderation;
- hybrid: membership loss retains visibility, active Work absence temporarily
  suppresses it and reactivation restores it.

The third option most closely matches the existing wording, but is not yet a
binding decision.

## 10. Moderation contract

Confirmed:

- Library Owner and an authorized Manager may moderate only a publication in
  that explicit Library;
- ordinary members, inactive/foreign Managers and unauthorized Managers may not;
- moderation never reads or edits private source fields beyond what is already
  publicly projected;
- moderation never changes Rating/Review owner, Work, Round, value or text;
- a reason may be stored, but requiredness and visibility are unspecified.

O5 must replace “hide/delete” with exact transitions. The minimal recommended
model is `visible → moderated_hidden`, with a reason and moderator/time
metadata, plus an explicit restore decision. Hard-deleting the publication
would lose the stated optional reason and obscures moderation history. A
withdrawal by the source owner is a separate operation and must not impersonate
a moderation action.

Introduce one explicit additional Manager permission such as
`contribution.moderate`; Owner authorization remains inherent. This is an
architectural implementation of “authorized Beheerder”, not permission for
private source access.

## 11. ActivityEvent and Timeline contract

Definitive:

- private Rating/Review create, value/content update, Round-context correction
  and source delete are user-owned mutations and write no Library ActivityEvent;
- private source data, review text and private Rating values never leak into
  Library audit;
- personal Timeline is a derived future concern. F2.8b stores adequate source
  timestamps but adds no Timeline event store or UI.

Open O9:

- the canonical audit chapter excludes personal ratings/reviews, while publish,
  withdrawal and moderation mutate a shared Library projection;
- decide whether these publication lifecycle actions create Library
  ActivityEvents and which roles can see them.

Recommendation: record publish/withdraw/moderate/restore as Library
ActivityEvents containing publication ID, contribution type, Work ID, state
transition and actor snapshot, but never source text, private Round, private
Rating value or unrelated owner data. The source contribution remains excluded.

## 12. Application service contracts

All public methods derive actor from `AuthenticatedUser`, accept typed target
IDs/value objects and return typed state or typed non-enumerating failures.
There is no generic unrestricted update service.

### 12.1 Owner contribution writes

| Service | Input | Preconditions/result |
| --- | --- | --- |
| `CreateRatingForWorkService` | WorkId, RatingValue | Work exists; create unlinked owner Rating; reject unlinked duplicate |
| `CreateRatingForReadingRoundService` | RoundId, RatingValue | Lock owned Round; derive Work; enforce Round cardinality |
| `UpdateRatingValueService` | RatingId, expected version, RatingValue | Locked owner read; semantic no-op; CAS real change |
| `CorrectRatingReadingRoundService` | RatingId, expected version, nullable RoundId | O2; owner/same-Work; no implicit Work change |
| `DeleteOwnRatingService` | RatingId, expected version | O3/O4; conditionally remove only own source and its publication effect |
| `CreateReviewForWorkService` | WorkId, ReviewContent | Work exists; create unlinked owner Review; reject unlinked duplicate |
| `CreateReviewForReadingRoundService` | RoundId, ReviewContent | Lock owned Round; derive Work; enforce Round cardinality |
| `UpdateReviewContentService` | ReviewId, expected version, ReviewContent | Locked owner read; validate; semantic no-op; CAS real change |
| `CorrectReviewReadingRoundService` | ReviewId, expected version, nullable RoundId | O2; owner/same-Work; no implicit Work change |
| `DeleteOwnReviewService` | ReviewId, expected version | O3/O4; conditionally remove only own source and its publication effect |

Failure families should distinguish invalid rating/content, contribution not
available, duplicate unlinked/linked contribution, invalid Round context,
stale source, and ID-collision exhaustion without exposing foreign identity.

### 12.2 Publication writes

| Service | Input | Preconditions/result |
| --- | --- | --- |
| `PublishRatingToLibraryService` | RatingId, LibraryId | Owner source + active membership + active Work presence; create one publication |
| `PublishReviewToLibraryService` | ReviewId, LibraryId | Same; revalidate public content |
| `MoveContributionPublicationService` | PublicationId, expected version, target LibraryId | Only if O4 permits; re-run target eligibility atomically |
| `WithdrawContributionPublicationService` | PublicationId, expected version | Owner only; apply O4 withdrawal state/delete |
| `ModerateContributionPublicationService` | PublicationId, expected version, action/reason | Explicit Library Context and moderation permission; source unchanged |
| `RestoreContributionPublicationService` | PublicationId, expected version | Only if O5 permits and actor/action rules are fixed |

Publication failures distinguish unavailable source/publication, membership
ineligible, Work not actively represented, already published, stale
publication and moderation forbidden. Foreign IDs must not disclose source
ownership; public publication lookup may return only the public DTO.

### 12.3 Reads

- `GetOwnRatingService`, `GetOwnReviewService`;
- `ListOwnRatingsForWorkService`, `ListOwnReviewsForWorkService`;
- `ListOwnRatingsForReadingRoundService`, `ListOwnReviewsForReadingRoundService`;
- paged `ListMyRatingsService`, `ListMyReviewsService` or one typed
  `ListMyAssessmentsService` projection;
- `ListPublicRatingsForLibraryWorkService`;
- `ListPublicReviewsForLibraryWorkService`;
- `GetOwnRatingAverageForWorkService`;
- `GetPublicLibraryRatingAverageForWorkService`.

An aggregate read may combine DTOs, but it does not merge Rating and Review
write aggregates or authorize through UI visibility.

## 13. Concurrency and ID contract

**Recommendation, consistent with existing Core conventions:**

- separate `RatingVersion`, `ReviewVersion`, `PublicationVersion` start at 1;
- every real mutable change increments exactly once;
- identical current or stale intent is a semantic no-op only when the requested
  complete state already holds;
- divergent stale intent returns a typed conflict with current safe state;
- create uniqueness is enforced in MariaDB and translated to typed duplicate
  failures;
- source update and publication lifecycle locks are independent unless one
  transaction must validate both; publish/move lock source before publication;
- publication versus source deletion is serialized so no orphan/half-state can
  commit;
- publish eligibility uses the shared Library mutation lock before checking
  membership/active Work presence, and future membership/Item eligibility
  mutations must take that same lock;
- all three IDs are opaque, issued server-side and bounded to current 191-byte
  persistent identifier constraints;
- only translated primary-key collisions receive at most three retries after
  the first issuance; other failures are never retried blindly.

Required real concurrency proofs include divergent/equal source updates,
publish versus withdraw, duplicate publish, publish versus source delete,
publish versus membership/Work-presence loss and moderator versus owner action.

## 14. Persistence and migration plan

### 14.1 Decision

Use schema **1005** with three Biblio-owned InnoDB tables. Ownership, optional
Round FKs, one-unlinked uniqueness, publication XOR, Library isolation,
transactions and CAS are hard relational concerns fitting ADR-004. JetEngine
CCT/CPT would add no net benefit at this Core boundary.

### 14.2 `wp_biblio_ratings`

- `rating_id VARCHAR(191) ... utf8mb4_bin` primary key;
- `user_id VARCHAR(191) ... utf8mb4_bin`;
- `work_id VARCHAR(191) ... utf8mb4_bin`;
- `reading_round_id VARCHAR(191) ... utf8mb4_bin NULL`;
- `rating_value` scaled integer selected after O1;
- `created_at`, `updated_at DATETIME(6)`;
- `rating_version BIGINT UNSIGNED`;
- generated nullable `unlinked_work_id` equal to Work only when Round is null;
- unique `(user_id, unlinked_work_id)`;
- optionally unique `(user_id, reading_round_id)` if O2 chooses one per Round;
- owner+Work, owner+Round and owner+updated paging indexes;
- Work FK `RESTRICT`; Round FK according to O2; positive version/time and
  rating-range CHECKs.

### 14.3 `wp_biblio_reviews`

- analogous ID/User/Work/Round/time/version columns;
- `review_content TEXT` or bounded alternative selected after O8;
- same generated unlinked uniqueness and conditional Round uniqueness;
- same owner query indexes and FKs/CHECKs.

### 14.4 `wp_biblio_contribution_publications`

- `publication_id VARCHAR(191) ... utf8mb4_bin` primary key;
- `library_id VARCHAR(191) ... utf8mb4_bin`;
- nullable `rating_id` and `review_id`, exactly one populated;
- state/reason/moderator fields determined by O4/O5;
- `published_at`, `updated_at DATETIME(6)` and positive version;
- real Library, Rating and Review FKs;
- XOR CHECK; unique nullable Rating and Review references enforce at most one
  publication context per contribution;
- Library+state+updated paging index and source lookup indexes;
- no duplicated editable Work, owner, rating value or review content;
- source-delete FK action and publication tombstone behavior depend on O3/O4.

Public Library-by-Work queries join the publication to its source and use
indexed Library/state plus source Work indexes. Do not add FULLTEXT or store
averages. If profiling later proves the join insufficient, introduce a
rebuildable projection rather than denormalized mutable truth in F2.8b.

### 14.5 Migration guarantees

`CoreSchema1005Migration` must:

1. require healthy 1004;
2. create all three tables in dependency order;
3. accept a fully absent target or a fully known healthy retry state;
4. fail closed on unknown partial/shape-drift state;
5. verify complete 1005 health before version bump;
6. preserve the immutable 1000–1004 chain and all data;
7. support fresh install through every ordered migration;
8. add table/column/index/FK/CHECK health and cleanup in dependency order;
9. require no backfill because schema 1004 contains no Rating/Review data.

MariaDB DDL is not transactionally rolled back as a unit. Retry logic must
therefore recognize each exact already-created healthy target table while
rejecting an unexplained partial shape.

## 15. Threat and invariant matrix

| Threat | Protection | Primary layer | Required evidence |
| --- | --- | --- | --- |
| Other user reads private source by ID | Actor-derived owner predicate; foreign = unavailable | Application/repository | Existing foreign ID leaks no data |
| Other user updates/deletes source | Locked ID+owner read and conditional CAS/delete | Application/persistence | Row/version unchanged |
| Manager reads private source | Moderation loads public projection/publication, never owner repository unrestricted | Composition/application | Manager sees no unpublished fields |
| Manipulated Work on create | Round path derives Work; Work path verifies existence | Application | No arbitrary Work+Round pair |
| Cross-user Round | Owner-scoped Round lookup | Application/repository | Non-enumerating rejection |
| Cross-Work Round | Compare immutable Work; persistence rechecks | Application/persistence | No link/version change |
| Invalid rating | Value object plus database CHECK | Domain/database | Boundary/range/step matrix |
| Review XSS | Fixed content policy plus output revalidation/escaping | Domain/rendering | Stored and reflected payload matrix |
| Publish foreign contribution | Locked owner source | Application/repository | No publication or existence disclosure |
| Publish into foreign/inactive Library | Explicit Library Context and active membership | Application | All roles/statuses/foreign Library matrix |
| Publish where Work absent | Active representation check from source Work | Application/query | Archived/foreign/unknown Item cases |
| Duplicate/cross-Library publish | Unique source publication plus named move | Database/application | Concurrent double publish one winner |
| Modify/withdraw another publication | Resolve source owner; conditional version write | Application/persistence | State unchanged |
| Unauthorized moderation | Owner or explicit Manager permission in same Library | Authorization/application | Member/inactive/foreign/permission matrix |
| Moderation edits source | No source writer dependency/input | Composition/domain | Content/value/version unchanged |
| Stale update | Locked read, expected version and CAS | Application/repository | Winner/stale and equal no-op cases |
| ID enumeration | Opaque IDs plus scope predicates | Generator/repository | Guessing grants no authority |
| Publish eligibility race | Shared Library lock and same-transaction recheck | Application/persistence | Independent-process race |
| Source delete/publication race | Lock order and FK/transaction | Application/database | No orphan or half-state |
| Private data in audit | Publication event allowlist excludes content/private Round/value | Activity builder | Payload assertions |
| Hidden publication in average | Visibility predicate applied before aggregate | Query/persistence | Count/sum exclude hidden/withdrawn |

## 16. Read models and aggregates

All owner lists are owner-predicated, bounded and stably paged. Recommended
ordering is `updated_at DESC, id DESC`, default 50 and maximum 100, consistent
with Private Notes. Public lists are scoped by explicit Library + Work and
must never fall back to platform-wide data.

Required DTOs:

- own Rating/Review detail with private Round link and publication state;
- own Rating/Review lists for Work;
- own Rating/Review lists for ReadingRound;
- paged `Mijn beoordelingen`, with a typed contribution discriminator;
- public Rating DTO for one Library+Work;
- public Review DTO for one Library+Work;
- `RatingAggregate { count, exactSum/scaledSum, average? }` for owner+Work;
- the same aggregate for visible publications in Library+Work.

Confirmed inclusion rules:

- source records that have been legitimately deleted do not count;
- personal aggregate only reads the authenticated owner’s valid Ratings;
- Library aggregate only reads currently visible Rating publications in that
  Library;
- Reviews never affect a numerical average;
- private or merely represented-but-unpublished Ratings never affect public
  average;
- no stored average, platform-wide average or global deduplication service.

O7 must decide whether every reread Rating counts equally or one Rating per
user is selected, and must define rounding/display and empty state. Core should
return exact scaled sum + count and, once decided, a deterministic decimal;
presentation formatting must not become a second aggregation rule.

## 17. Numbered F2.8b acceptance matrix

### Domain and content

1. RatingId, ReviewId and PublicationId accept valid 191-byte IDs and reject
   empty, invalid UTF-8 and over-limit values.
2. Versions start positive and increment exactly once per real change.
3. O1 minimum/maximum/step Rating values succeed at every boundary.
4. Below/above/invalid-step/manipulated Rating values fail before persistence.
5. Rating equality and scaled storage roundtrip exactly without float drift.
6. Review accepts valid normalized Unicode content at O8 boundary.
7. Empty, invalid UTF-8, NUL and over-limit Review content fails.
8. O8 XSS payload matrix cannot cross public render output.
9. Rating and Review aggregates cannot mutate owner or Work.
10. Rating and Review remain independent and do not create each other.

### Create, identity and ReadingRound

11. Unlinked Rating creation succeeds for existing Work.
12. A second unlinked Rating for same User+Work is rejected, including races.
13. Unlinked Review has the equivalent uniqueness contract independently.
14. Same Work may have multiple contributions through distinct rereads.
15. Round creation path derives Work from owned Round.
16. Foreign/unknown Round is non-enumerating unavailable.
17. Same-owner cross-Work Round is rejected without mutation.
18. Active/ended/historical Round acceptance follows O2 exactly.
19. Per-Round Rating/Review cardinality follows O2 under concurrency.
20. Attach/change/remove context follows O2 with no-op/stale behavior.
21. Legitimate ReadingRound deletion follows O2 without deleting Work or
    unrelated contribution data.

### Ownership and source mutations

22. Owner can get own Rating/Review; another user receives no private state.
23. Owner value/content update succeeds and increments version once.
24. Semantic current no-op and identical stale no-op do not increment version.
25. Divergent stale value/content update returns current safe conflict state.
26. Foreign user cannot update context/value/content or delete.
27. Library Owner/Manager/Member/support/platform role grants no private read.
28. Source deletion follows O3 and never deletes Work/ReadingRound/other data.
29. Wrong-Work correction follows O3 without silent identity reassignment.
30. Production signatures accept no UserId or caller-supplied create ID.

### Publication and visibility

31. Owner publishes own Rating without Review to one eligible Library.
32. Owner publishes own Review without Rating to one eligible Library.
33. Private source remains private and user-owned after publication.
34. Unpublished source never appears in any public projection.
35. Publication requires active membership for Owner/Manager/Member alike.
36. Inactive, absent and foreign-Library membership is rejected.
37. Publication requires at least one active same-Library Item for source Work.
38. Item for another Work/Library or archived-only presence is rejected.
39. Contribution cannot be simultaneously published to two Libraries.
40. Concurrent duplicate/cross-Library publish yields one consistent winner.
41. Move and withdrawal follow O4 and recheck target eligibility.
42. Membership loss visibility follows O4 while preserving source ownership.
43. Last active Item removal and later return follow O4 deterministically.
44. Public DTO exposes exactly O6-approved fields and no private Round/owner ID.
45. Source content correction effect on public projection follows O4/O5.
46. Source delete versus publication action is atomic with no orphan.

### Moderation and audit

47. Same-Library Owner can perform each O5-approved moderation action.
48. Manager requires explicit moderation permission and active membership.
49. Member, inactive/foreign/unauthorized Manager cannot moderate.
50. Moderator cannot read unpublished source or edit source value/text/context.
51. Reason requiredness, visibility and restore behavior follow O5.
52. Owner withdrawal and moderator hiding remain distinguishable states/actions.
53. Private source mutations create no Library ActivityEvent.
54. Publish/withdraw/moderate/restore events follow O9 exactly.
55. Any publication event contains no review text, private Round or private
    Rating value and is scoped to one Library.
56. No Timeline storage/UI is introduced; source timestamps remain usable for
    a later derived personal Timeline.

### Reads and averages

57. Own Work, Round and My-assessment lists are owner-isolated and stably paged.
58. Public Library+Work list contains only visible publications in that Library.
59. Private, withdrawn, moderated-hidden and foreign-Library Ratings are
    excluded from public aggregate.
60. Personal aggregate includes exactly O7-selected own valid Ratings.
61. Multiple reread weighting/deduplication follows O7.
62. Exact sum/count, decimal/rounding and empty state follow O7.
63. Review presence never changes numerical average.
64. No platform/global average, global feed, FULLTEXT or Review search appears.

### Persistence, migration, concurrency and regression

65. All three tables roundtrip IDs, Unicode, nullable Round, microseconds and
    versions on real MariaDB.
66. Unknown Work/Round/Library/source FKs fail and do not bump schema/version.
67. XOR and one-unlinked/one-publication uniqueness CHECKs/indexes hold.
68. Repository defense rejects owner/Work-inconsistent Round relationships.
69. Divergent/equal parallel source updates prove winner/stale/no-op behavior.
70. Publish-versus-withdraw/delete/moderate races produce one valid final state.
71. Eligibility-change race cannot publish from an invalid serialized state.
72. Primary-key collision retries only translated collisions and exhausts after
    three retries beyond the first attempt for each aggregate type.
73. Fresh schema reaches healthy 1005 through the full formal chain.
74. Healthy 1004 upgrades to 1005 without changing existing rows.
75. Known DDL-before-version retry converges; unknown partial DDL fails closed.
76. Health detects every target table/column/index/FK/CHECK drift.
77. Existing schema 1000–1004, F2.5, ReadingRound and Private Note suites remain
    green, including Round hard-delete `SET NULL` behavior.
78. Production composition exposes only named services/read models, not
    repositories, generators, content policies or generic writers.
79. PHPStan level 6, full unit/integration suite, WordPress smoke, manifest and
    Git whitespace all pass.

## 18. Open product decisions

These are genuine blockers for a no-guess F2.8b implementation.

### O1 — Rating scale

Decide minimum, maximum and increment: for example whole 1–5, half 0.5–5, or
another scale. Confirm whether zero means a rating or only “not rated”.

### O2 — ReadingRound cardinality and lifecycle

Decide maximum per contribution type per Round, eligible active/ended/
historical states, later attach/change/remove, and behavior when a legitimate
historical Round is deleted.

### O3 — Work correction and contribution deletion

Confirm immutable Work plus delete/recreate for wrong Work; hard versus soft
delete; expected-version requirement; and the atomic effect on an existing
publication. No source permits deletion to affect Work, Round or other reads.

### O4 — Publication lifecycle and continuing visibility

Confirm owner withdrawal, A→B move semantics, whether move is atomic, what
happens after membership loss, what happens while the Work has no active Item,
whether later active presence automatically restores visibility, and whether
editing published source content updates public output immediately.

### O5 — Moderation lifecycle

Define hide versus delete, restore, moderator permission, reason requiredness,
who may see the reason, author notification only if in scope, and what happens
after the owner edits a moderated contribution. Moderation may never mutate the
source contribution.

### O6 — Public projection fields

Decide whether public output shows display name or another author label,
publication/update date and any ReadingRound context. Do not expose these by
default without approval.

### O7 — Average semantics

Decide whether every published reread Rating counts or one per user, arithmetic
mean/rounding precision, display rule and empty state. Apply the same explicit
logic separately to personal and Library aggregates where intended.

### O8 — Review content

Choose plain text or an exact safe-markup subset, normalized maximum byte
length and reject-versus-strip behavior. Public rendering must revalidate/
escape according to that fixed format.

### O9 — Publication ActivityEvents

Decide whether publish, withdraw, moderate and restore enter Library audit,
which roles see them and the minimal privacy-safe payload. Private Rating/
Review mutations remain excluded; Timeline implementation remains deferred.

## 19. Recommended F2.8b implementation order

1. Resolve O1–O9 and merge the decisions into this contract, schema plan and
   test matrix; only then change verdict to GO.
2. Add separate Rating/Review/Publication values, aggregates, failures, clocks
   and generators with domain tests.
3. Add the fixed Review content policy and public render boundary tests.
4. Implement schema 1005 tables, ordered migration, exact retry/health and
   real-MariaDB migration tests.
5. Add owner-scoped Rating/Review repositories, unlinked/Round uniqueness,
   paging and persistence defense.
6. Add named create/update/context/delete services with CAS and collision tests.
7. Add explicit publish eligibility and moderation authorization policies,
   publication repository and Library-lock transaction boundary.
8. Add private/public read models and exact average projectors.
9. Add publication events only as decided by O9; add no Timeline engine.
10. Run targeted domain/security/concurrency tests, all F2.5/F2.6/F2.7
    regressions and the complete canonical gate.
11. Update current state, architecture, acceptance and exit evidence only for
    behavior proven in production composition and real persistence.

The implementation can be split into logical commits: resolved contract;
domain/content; schema 1005; source persistence/application; publication and
authorization; projections/averages/events; concurrency/security; exit docs.

## 20. Validation and exit verdict

### Canonical verification

The complete existing gate was run after this documentation-only analysis on
2026-08-22:

- `./scripts/test-biblio-core-all.sh`: PASS in 55 seconds;
- Composer metadata and locked platform requirements: PASS;
- PHP syntax over production and tests: PASS;
- PHPStan level 6 over production `src`: PASS, no errors;
- unit: 190 tests, 679 assertions;
- isolated real-MariaDB integration: 137 tests, 994 assertions;
- total: 327 tests, 1,673 assertions;
- WordPress smoke: plugin active, Core loaded, init hook once, HTTP 200;
- manifest JSON and staged/unstaged Git whitespace: PASS;
- the gate left the visible documentation-only repository scope unchanged.

The integration assertion total can vary by one between valid winners in the
existing parallel Private Note update-versus-delete test; test count and all
required outcomes remain deterministic and green.

### Exit verdict

**GO WITH CONDITIONS**

The available repository establishes the ownership split, separate Rating and
Review contributions, optional ReadingRound context, one unlinked contribution
per type/User/Work, independent publication to one eligible Library, retained
user ownership and separate personal/public averages. It also provides a
proven technical foundation for schema 1005, owner isolation, Library Context,
transactions, CAS, opaque IDs, migrations and health.

F2.8b cannot yet start without product or architecture guesses. O1–O9 affect
domain value objects, uniqueness, foreign-key behavior, publication state,
moderation, privacy-safe output, aggregate queries, content security and audit.
After those decisions are supplied, this document can be finalized to GO and
F2.8b can proceed without expanding into UI, Timeline or social functionality.
