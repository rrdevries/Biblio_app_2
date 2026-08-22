# 12 — F2.8a Ratings & Reviews analysis

Status: **GO**

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
- **technical choice**: implementation detail that preserves product behavior;
- **deferred**: explicitly outside F2.8b.

| Source | Binding evidence | Conflict/gap resolution |
| --- | --- | --- |
| `docs/00-current-state.md` | Ratings/Reviews are private Mijn Biblio data owned by the user | Canonical ownership boundary; current state contains no implemented F2.8 slice |
| `docs/01-functional-design.md` §12 | Separate Rating and Review, optional Round, at most one unlinked contribution per type/User/Work, independent publication, eligibility, averages and moderation | Most detailed canonical product source, completed by the definitive F2.8a decisions in §18 |
| `docs/01-functional-design.md` §14/§16 | Beoordelingen may feed personal Timeline; personal ratings/reviews are excluded from Library audit | Timeline implementation remains deferred; F2.8b writes no Library ActivityEvents for contribution/publication/moderation behavior |
| `docs/02-architecture.md`; ADR-001/003 | User-owned and Library-scoped boundaries are distinct; optional Library reference never transfers ownership | Requires separate owner-scoped source and authorized Library publication behavior |
| ADR-002/004/005 | Core owns workflows; custom tables are the proven baseline where integrity/concurrency require them; schema chain is formal and forward-only | Supports schema 1005, but does not decide product semantics |
| ADR-006 and current catalog code | Library Work presence is derived through Item → Edition → Work within explicit Library Context | Publication eligibility must reuse an explicit active-representation query, not trust a client Work/Library pair |
| ADR-007 and F2.6 evidence | ReadingRound has immutable owner/Work, owner-scoped CAS and conditional historical delete | F2.8 uses the proven owner/Work/CAS boundary and the explicit contribution-resolution contract in §8 |
| F2.7 analysis/evidence | Optional same-user/same-Work Round context, strict private content, CAS and `SET NULL` are proven for Notes | Useful technical precedent, but the Note content and deletion contracts are not inherited by Reviews |
| `docs/03-scope-and-deferred.md` | Ratings/Reviews are v2.001 scope; social/public-profile/global-feed functions are deferred | F2.8b should stop at Core contribution/publication/read projections |
| `docs/04-terminology.md` | Rating is a private user-owned Work rating; Review is an independently publishable written contribution to one Library context | Confirms separate records and one publication context per contribution |
| `docs/05-source-register.md` | Historical “Gebruikersdata, leesrondes & beoordelingen” remains useful only with later corrections | The named raw handover is not present in Git history or the current checkout; it cannot override or fill gaps in canonical docs |
| `docs/06-testing-and-acceptance.md` §2/§14 | Private isolation, one explicit Library, active Item and no moderator text edits | Binding acceptance floor, not a complete implementation contract |
| Current production/tests/schema 1004 | No Ratings/Reviews implementation exists; current Item domain is active-only and `LibraryWorkRepresentationRepository` does not yet express status explicitly | F2.8b must add a status-explicit eligibility port and must not mistake current active-only storage for a permanent archive rule |

There is no remaining conflict or product gap. The definitive F2.8a decisions
in §18 make rating scale, Round lifecycle, deletion, publication visibility,
moderation, public output, averages, Review content and event behavior
deterministic for F2.8b.

## 3. Functional contract matrix

| Subject | Status | F2.8 contract |
| --- | --- | --- |
| Ownership | Canonical | Rating and Review remain owned by their authenticated user, including after publication |
| Rating versus Review | Canonical | They are independent contribution types, not nullable fields on one Assessment record |
| Work | Canonical | Each contribution concerns one immutable Work; ordinary Rating/Review updates never move it |
| ReadingRound | Canonical | Optional, correctable same-user/same-Work context; all lifecycle states eligible; at most one contribution of each type per Round |
| Unlinked contribution | Canonical | At most one unlinked Rating and one unlinked Review per User + Work |
| Multiple reads | Canonical | Multiple Ratings/Reviews for the same Work are possible through rereads |
| Private creation | Canonical | Requires no Library membership or context |
| Independent publication | Canonical | Rating-only and Review-only publication are valid; publishing one never publishes the other |
| Publication target | Canonical | One contribution has at most one chosen Library publication context; never duplicate automatically |
| Publish eligibility | Canonical | Owner is an active member of target Library and Work has at least one active Item there at publication time |
| Use access | Canonical | No Direct/Borrow/ViewOnly restriction is stated; active membership is sufficient |
| Ownership transfer | Canonical | Publication does not transfer contribution ownership to Library |
| Moderation | Canonical | Owner/authorized Manager may hide or remove only the Library publication with a mandatory reason; source content remains owner-only |
| Membership loss | Canonical | Does not withdraw, delete or hide an already valid publication |
| Work presence loss | Canonical | Suppresses current visibility while no active Item represents Work; visibility returns automatically with active presence |
| Personal average | Canonical | Every current valid own Rating counts once, including unlinked and reread Ratings |
| Library average | Canonical | Only the most recently updated currently visible Rating per User+Work counts; ties use RatingId descending |
| Platform average | Out of F2.8b | No source requires a platform-wide public average |
| Correction/delete | Canonical | Owner-scoped version-checked hard delete removes only the contribution and its active publication relation; Work/Round remain |
| Public person/context fields | Canonical | Review projection shows display name, visible rating if present, escaped review text and publication date; no private reading/account context |
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
| Audit | Append-only Library ActivityEvents for selected shared Library mutations | F2.8b deliberately wires no Rating/Review/publication/moderation ActivityEvent |
| Rendering | Strict Note-specific safe-HTML policy | Reviews require a separate normalized plain-text/escaping policy with a 5,000-code-point limit |
| Read models | Owner-scoped Round/Note queries | My assessments, public Library contributions and averages |
| Tests | 327 tests in the current canonical gate | No Rating/Review cases |

## 5. Proposed F2.8 Core model

### 5.1 Separate source aggregates

**Consequence:** use two aggregates, not one combined record.

`Rating`:

- opaque `RatingId`;
- immutable owner `UserId`;
- immutable `WorkId`;
- optional `ReadingRoundId`;
- required `RatingValue`;
- `createdAt`, `updatedAt`;
- positive `RatingVersion`.

`WrittenReview`:

- opaque `ReviewId`;
- immutable owner `UserId`;
- immutable `WorkId`;
- optional `ReadingRoundId`;
- required `ReviewContent` value, which may contain zero code points;
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
- author publication state plus moderation state;
- required moderation reason, moderator and moderation timestamp when moderated;
- `publishedAt`, `updatedAt` and positive `PublicationVersion`;
- no ownership transfer; source owner is resolved through the referenced
  contribution rather than accepted from caller input.

This separation is the minimum model that preserves private owner reads while
supporting eligibility, withdrawal, moderation, historical Library context and
public projections. One generic `contribution_type + contribution_id` reference
would lose foreign-key integrity. A single publication table with two nullable
real FKs and an XOR check keeps the model small without an unsafe polymorphic
reference.

The publication is a relationship/lifecycle aggregate. It never contains an
independently editable copy of rating value or review text. Public reads join
to current source content; editing source never clears moderation.

## 6. Rating contract

Confirmed:

- a Rating is a required value on a Rating record, not an optional part of a
  combined Assessment;
- it concerns exactly one Work;
- private and published Ratings are the same user-owned source with an optional
  publication relation;
- independent Rating publication means a public rating without Review is valid;
- averages are derived and never stored as a second domain truth.

`RatingValue` accepts exactly 1.0 through 5.0 stars in steps of 0.5: 1.0, 1.5,
2.0, 2.5, 3.0, 3.5, 4.0, 4.5 and 5.0. Zero, finer steps, other decimals and
out-of-range values are invalid. Store an integer count of half-stars, 2
through 10, never binary floating point. The value object owns conversion and
exact equality; a database CHECK repeats the 2–10 invariant. Derived averages
may contain other decimal values.

## 7. Review content and security contract

Confirmed:

- a Review is an independent written contribution and its text may contain
  zero through 5,000 Unicode code points;
- it is independent from Rating;
- publication exposes only content that passed the Core content policy;
- server-side validation and output safety are mandatory regardless of UI;
- public rendering is a stricter XSS boundary than private input storage.

For v2.001 Review content is plain text. Normalize CRLF and CR to LF before
validation; each LF counts as one character. Measure the normalized string as
Unicode code points with UTF-8 semantics (`mb_strlen(..., 'UTF-8')`), not bytes,
UTF-16 code units or grapheme clusters. Zero code points is valid; 5,001 fails.
Reject invalid UTF-8 and NUL. Preserve line endings/paragraphs and escape the
text at every HTML output boundary. Any submitted HTML remains literal text
and is never executed as markup. There is no HTML allowlist, rich text,
attachments, images, embeds or block content.

## 8. Work and ReadingRound contract

Confirmed:

- Work is the principal identity of Rating and Review;
- ReadingRound is optional context and never owner;
- a link can only target a Round owned by the same user and concerning the
  immutable same Work;
- cross-user and cross-Work links must fail without leaking record existence;
- an unlinked contribution remains valid without a Library or Round;
- at most one unlinked contribution of each type exists per User + Work.

At most one Rating and one Review per User + ReadingRound are allowed. Active,
ended and historical own same-Work Rounds are all eligible. The owner may later
attach, change or remove context. A target Round that already has the same
contribution type yields a typed conflict; Rating and Review do not conflict
with each other.

The ReadingRound FK uses `ON DELETE RESTRICT`, not cascade or automatic
`SET NULL`. When the F2.6 historical-Round delete is otherwise legitimate and
linked Ratings/Reviews exist, the owner must explicitly choose per contribution
to hard-delete it or preserve it as unlinked. Preserve is valid only when it
does not violate the one-unlinked contribution of that type per User+Work;
otherwise deletion fails until the conflict is explicitly resolved. Unlinking
is a real contribution mutation and increments its version/update timestamp.
The complete resolution and Round deletion commit atomically. Deleting a Rating
or Review never deletes its ReadingRound.

## 9. Privacy and publication model

### 9.1 Visibility matrix

| Actor/context | Private source | Visible publication | Moderated/withdrawn publication |
| --- | --- | --- | --- |
| Owner | Full owner-scoped source | Source plus publication state | Source remains accessible; withdrawal/moderation does not delete it |
| Other user | Never | Public projection only | Never through public projection |
| Ordinary Library member | Never | Public projection in explicit Library | Never through public projection |
| Library Owner/Manager | Never by role | Same public projection | May see minimum moderation metadata only if authorized; never private source |
| Platform/support role | No implicit access | Same public projection | No private bypass |

A public Rating DTO contains exactly display name, Rating value and publication
date. A public Review DTO contains exactly display name, an associated visible
Rating when present, escaped Review text and publication date. An associated
Rating is
one with the same owner, Work and identical Round context (including both
unlinked), and it must have its own currently visible publication in the same
Library. Publishing a Review never exposes a private Rating.

The DTO excludes ReadingRound ID/context, reading dates, physical source/Item,
Private Notes, email address, other account fields and other Library
memberships. Technical source owner ID and private links never enter output.
`publication_date` is the current activation instant of that Publication; a
later source edit changes content but not that date.
The display name is resolved server-side from the current WordPress user
identity at read time and is never accepted from publication input or copied as
an independently editable contribution field.

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
7. enforce at most one current active publication relation for that
   contribution;
8. insert/update publication atomically with version/uniqueness checks.

The Work is derived from the locked contribution. The caller never supplies a
second Work or an owner. A status-explicit active-representation port replaces
the current ambiguous repository name.

### 9.3 Later eligibility loss

Publication eligibility is checked when publishing, republishing or moving.
Later membership loss preserves the publication and its visibility. Loss of
the last active Item for Work preserves the Publication record but suppresses
it from active public output and the public average. Visibility returns
automatically when the Work is again represented by an active Item, provided
the author has not withdrawn it and moderation permits visibility.

The owner may withdraw at any time; withdrawal immediately removes public
visibility without deleting source content or publication history. Republishing
rechecks current eligibility and sets a new publication date. Moving A→B is one
atomic withdraw-A/publish-B operation after target eligibility; there is never
more than one active Library publication per contribution.

## 10. Moderation contract

The Library Owner and an authorized Manager may moderate only a Publication in
that explicit Library. Ordinary members, inactive/foreign Managers and
unauthorized Managers may not. Use one explicit Manager permission such as
`contribution.moderate`; Owner authorization remains inherent.

Moderation state is separate from author publication state:

- `visible`: moderation permits output;
- `hidden`: temporarily suppresses output and can be restored by an authorized
  moderator;
- `removed`: suppresses output permanently for that contribution+Library and
  cannot be lifted by the author.

Hide and remove require a non-empty valid UTF-8 moderation reason plus
moderator/time metadata. Use plain text, safe escaping and the technical `TEXT`
capacity boundary of at most 65,535 normalized UTF-8 bytes. The reason is
visible to the contribution author and authorized moderators, never in public
output. There is no moderation queue in F2.8b.

Moderation never reads or edits private source fields beyond the public DTO and
never changes source owner, Work, Round, Rating value, Review text or source
version. The author may still edit private source content or withdraw it;
neither action clears hidden/removed state. Editing a hidden contribution does
not republish it. A moderator may restore `hidden → visible`; `removed` is
terminal for the same contribution+Library, although the owner may publish the
contribution to another eligible Library after the prior relation is inactive.

The permission is an authorization capability for the publication projection,
never permission for private source access.

## 11. ActivityEvent and Timeline contract

Definitive:

- private Rating/Review create, value/content update, Round-context correction
  and source delete are user-owned mutations and write no Library ActivityEvent;
- private source data, review text and private Rating values never leak into
  Library audit;
- personal Timeline is a derived future concern. F2.8b stores adequate source
  timestamps but adds no Timeline event store or UI.

F2.8b writes no Library ActivityEvent for publish, withdraw, move, hide, remove
or restore. The current sources do not prescribe these events, and private
Rating/Review data must not be pulled into Library audit. A future explicit
audit decision can add privacy-safe event types without changing source
ownership. Timeline implementation remains deferred.

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
| `CorrectRatingReadingRoundService` | RatingId, expected version, nullable RoundId | Owner/same-Work; all states; enforce one Rating per target Round |
| `DeleteOwnRatingService` | RatingId, expected version | Owner-scoped hard delete of source and its Publications only |
| `CreateReviewForWorkService` | WorkId, ReviewContent | Work exists; create unlinked owner Review; reject unlinked duplicate |
| `CreateReviewForReadingRoundService` | RoundId, ReviewContent | Lock owned Round; derive Work; enforce Round cardinality |
| `UpdateReviewContentService` | ReviewId, expected version, ReviewContent | Locked owner read; validate; semantic no-op; CAS real change |
| `CorrectReviewReadingRoundService` | ReviewId, expected version, nullable RoundId | Owner/same-Work; all states; enforce one Review per target Round |
| `DeleteOwnReviewService` | ReviewId, expected version | Owner-scoped hard delete of source and its Publications only |

Failure families should distinguish invalid rating/content, contribution not
available, duplicate unlinked/linked contribution, invalid Round context,
stale source, and ID-collision exhaustion without exposing foreign identity.

### 12.2 Publication writes

| Service | Input | Preconditions/result |
| --- | --- | --- |
| `PublishRatingToLibraryService` | RatingId, LibraryId | Owner source + active membership + active Work presence; create one publication |
| `PublishReviewToLibraryService` | ReviewId, LibraryId | Same; revalidate public content |
| `MoveContributionPublicationService` | PublicationId, expected version, target LibraryId | Owner only; atomically withdraw current/recheck and publish target |
| `WithdrawContributionPublicationService` | PublicationId, expected version | Owner only; retain history, remove public visibility |
| `ModerateContributionPublicationService` | PublicationId, expected version, hide/remove + reason | Explicit Library Context and moderation permission; source unchanged |
| `RestoreContributionPublicationService` | PublicationId, expected version | Authorized moderator; hidden→visible only |

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

**Technical contract, consistent with existing Core conventions:**

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
- `rating_half_units TINYINT UNSIGNED`, exactly 2 through 10;
- `created_at`, `updated_at DATETIME(6)`;
- `rating_version BIGINT UNSIGNED`;
- generated nullable `unlinked_work_id` equal to Work only when Round is null;
- unique `(user_id, unlinked_work_id)`;
- unique `(user_id, reading_round_id)`; nullable values remain governed by the
  separate generated unlinked constraint;
- owner+Work, owner+Round and owner+updated paging indexes;
- Work and Round FKs `RESTRICT`; positive version/time and half-unit range
  CHECKs.

### 14.3 `wp_biblio_reviews`

- analogous ID/User/Work/Round/time/version columns;
- `review_content TEXT NOT NULL` with `CHAR_LENGTH(review_content) <= 5000`;
- same generated unlinked uniqueness and unique owner+Round constraint;
- same owner query indexes and Work/Round `RESTRICT` FKs/CHECKs.

### 14.4 `wp_biblio_contribution_publications`

- `publication_id VARCHAR(191) ... utf8mb4_bin` primary key;
- `library_id VARCHAR(191) ... utf8mb4_bin`;
- nullable `rating_id` and `review_id`, exactly one populated;
- `author_status` (`active`/`withdrawn`) and `moderation_status`
  (`visible`/`hidden`/`removed`);
- nullable `TEXT` reason plus moderator/time fields with CHECKs that require
  them for hidden/removed and forbid them for visible;
- `published_at`, `updated_at DATETIME(6)` and positive version;
- real Library, Rating and Review FKs;
- XOR CHECK; unique source+Library keys retain one history relation per target;
- generated active source references, populated only for active and
  non-removed relations, with unique indexes enforce at most one current
  Library publication per contribution;
- Library+state+updated paging index and source lookup indexes;
- no duplicated editable Work, owner, rating value or review content;
- source FKs `ON DELETE CASCADE` remove only dependent publication history when
  the owner hard-deletes the source; Library FK is `RESTRICT`.

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
| Private data in audit | No F2.8 ActivityEvent dependency or append | Composition/application | Event count and payload storage remain unchanged |
| Hidden publication in average | Visibility predicate applied before aggregate | Query/persistence | Count/sum exclude hidden/withdrawn |

## 16. Read models and aggregates

All owner lists are owner-predicated, bounded and stably paged. Ordering is
`updated_at DESC, id DESC`, default 50 and maximum 100, consistent
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

For the personal Work aggregate every current valid owner Rating counts once,
including unlinked and distinct rereads. For the public Library+Work aggregate,
filter currently visible Rating Publications and select at most one Rating per
user using `rating.updated_at DESC, rating_id DESC`; the second key is the
deterministic tie-break. Sum original half-units and divide only at the end.
Return exact sum, contributing Rating count and—publicly—unique user count.
Presentation rounds half-up to one decimal and renders an empty result (no
average, count 0) when no Rating qualifies. No intermediate rounding or stored
average is allowed.

## 17. Numbered F2.8b acceptance matrix

### Domain and content

1. RatingId, ReviewId and PublicationId accept valid 191-byte IDs and reject
   empty, invalid UTF-8 and over-limit values.
2. Versions start positive and increment exactly once per real change.
3. All nine half-star values from 1.0 through 5.0 succeed exactly.
4. Below/above/invalid-step/manipulated Rating values fail before persistence.
5. Rating equality and scaled storage roundtrip exactly without float drift.
6. Review accepts empty, multiline and exactly 5,000-code-point normalized
   Unicode content; CRLF/CR becomes LF.
7. Invalid UTF-8, NUL and 5,001-code-point Review content fails.
8. HTML remains literal plain text and the XSS payload matrix is escaped at
   public output.
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
18. Active, ended and historical own same-Work Rounds are accepted.
19. Concurrent same-type creation/linking on one Round yields one winner and a
    typed conflict; Rating and Review coexist on the same Round.
20. Attach/change/remove context has owner/same-Work/no-op/stale behavior.
21. Round hard delete is RESTRICTed until every linked contribution has an
    explicit delete-or-unlink choice; unlinked-cardinality conflict rolls back
    and no choice ever deletes Work or unrelated data.

### Ownership and source mutations

22. Owner can get own Rating/Review; another user receives no private state.
23. Owner value/content update succeeds and increments version once.
24. Semantic current no-op and identical stale no-op do not increment version.
25. Divergent stale value/content update returns current safe conflict state.
26. Foreign user cannot update context/value/content or delete.
27. Library Owner/Manager/Member/support/platform role grants no private read.
28. Owner/version hard delete removes only source and dependent Publications,
    never Work/ReadingRound/other data.
29. Work is immutable through every Rating/Review update; no silent reassignment.
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
41. Withdrawal hides immediately; republish/move rechecks target eligibility,
    updates publication date and never creates two active targets.
42. Membership loss leaves a previously valid publication visible and preserves
    source ownership.
43. Last active Item loss suppresses output/average and later active presence
    restores it without rewriting Publication state.
44. Public Review DTO exposes only display name, separately visible associated
    Rating if present, escaped text and publication date.
45. Source correction updates public content without changing publication date
    or clearing hidden/removed moderation.
46. Source delete versus publication action is atomic with no orphan.

### Moderation and audit

47. Same-Library Owner can hide/remove with a mandatory reason and restore a
    hidden Publication.
48. Manager requires explicit moderation permission and active membership.
49. Member, inactive/foreign/unauthorized Manager cannot moderate.
50. Moderator cannot read unpublished source or edit source value/text/context.
51. Moderation reason is mandatory, escaped and visible only to author and
    authorized moderators; hidden is restorable, removed is terminal for that
    contribution+Library and no queue exists.
52. Owner withdrawal and moderator hiding remain distinguishable states/actions.
53. Private source mutations create no Library ActivityEvent.
54. Publish/withdraw/move/hide/remove/restore write no Library ActivityEvent.
55. Library ActivityEvent storage contains no F2.8 source/publication payload.
56. No Timeline storage/UI is introduced; source timestamps remain usable for
    a later derived personal Timeline.

### Reads and averages

57. Own Work, Round and My-assessment lists are owner-isolated and stably paged.
58. Public Library+Work list contains only visible publications in that Library.
59. Private, withdrawn, moderated-hidden and foreign-Library Ratings are
    excluded from public aggregate.
60. Personal aggregate counts every current valid own unlinked/reread Rating
    exactly once.
61. Public aggregate selects latest updated visible Rating per user; equal
    timestamps use RatingId descending and unique-user count is returned.
62. Exact half-unit sum has no intermediate rounding; presentation is one
    decimal and empty state has no average with count zero.
63. Review presence never changes numerical average.
64. No platform/global average, global feed, FULLTEXT or Review search appears.

### Persistence, migration, concurrency and regression

65. All three tables roundtrip IDs, Unicode, nullable Round, microseconds and
    versions on real MariaDB.
66. Unknown Work/Round/Library/source FKs fail and do not bump schema/version.
67. XOR, one-unlinked, one-per-Round, one-current-publication and
    source+Library history uniqueness CHECKs/indexes hold.
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

## 18. Definitive product decisions

There are no remaining product decisions for F2.8b.

### D1 — Rating scale

Exactly 1.0–5.0 in 0.5 steps; store half-units 2–10. Zero and finer values are
invalid. Derived averages may contain other decimals.

### D2 — ReadingRound and source deletion

One contribution of each type per own same-Work Round; active, ended and
historical are eligible; context is attachable/correctable/removable. Round
delete requires explicit delete-or-unlink resolution and uses FK `RESTRICT`.

### D3 — Work and contribution deletion

Work is immutable. Owner/version-checked hard delete removes only the source
contribution and its Publications; never Work, Round or other reading data.

### D4 — Publication lifecycle

One current Library target per contribution; publish/republish/move rechecks
eligibility. Withdrawal hides. Membership loss does not hide. Missing active
Work presence suppresses output temporarily and return restores it.

### D5 — Moderation

Authorized Library moderation can hide or remove only the Publication, always
with a reason, and never source content. Hidden is moderator-restorable;
removed is terminal for the same contribution+Library. There is no queue.

### D6 — Public Review projection

Only display name, separately visible associated Rating if present, escaped
Review text and publication date are public. Reading/account/source context is
excluded and ownership remains with the user.

### D7 — Averages

Personal: every current valid own Rating. Public: most recently updated visible
Rating per user+Work, tie RatingId descending. No intermediate rounding; show
one decimal and unique-user count; store no average.

### D8 — Review content

Plain text, zero through 5,000 Unicode code points after newline normalization,
with server validation and escaped output. No markup or rich content.

### D9 — Events

No F2.8 private, publication or moderation mutation writes a Library
ActivityEvent. Timeline implementation is deferred.

## 19. Recommended F2.8b implementation order

1. Use the definitive §18 contract without reopening product choices.
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
9. Prove that F2.8 writes no ActivityEvents and add no Timeline engine.
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

**GO**

The available repository establishes the ownership split, separate Rating and
Review contributions, optional ReadingRound context, one unlinked contribution
per type/User/Work, independent publication to one eligible Library, retained
user ownership and separate personal/public averages. It also provides a
proven technical foundation for schema 1005, owner isolation, Library Context,
transactions, CAS, opaque IDs, migrations and health.

The definitive decisions in §18 close rating value, Round cardinality/deletion,
Work/deletion, publication, moderation, public projection, averages, Review
security and ActivityEvent behavior. The Core model, schema-1005 plan,
application boundaries and 79 acceptance cases contain no conditional product
variants.

**F2.8b can start without any further product decision.** It must remain within
the documented Core scope and not expand into UI, Timeline or social features.
