# D-AUTHOR-MAT-01 — canonical Author materialization

Status: **DESIGN GO / IMPLEMENTATION NOT STARTED**.

Date: 2026-09-13.

Task severity: **High**. This identity-, schema-, concurrency- and
cross-materialization design followed a read-only audit of the current code,
schema-1023 runtime and canonical documents. It changes no production code,
schema or runtime data.

## 1. Verdict and current gap

D-AUTHOR-MAT-01 is **GO**. The implementation contract is closed for the
Author identity lifecycle, strong provider reuse, name-only retry identity,
ordered Work relationships, promotion, failure handling, transactions,
concurrency, Search consumption and future reconciliation.

The live development runtime confirms the gap that motivated this design:

| Record | Count |
|---|---:|
| Works | 9 |
| Editions | 7 |
| Items | 4 |
| Authors | 0 |
| Work contributors | 0 |

Add Book and generic bibliographic materialization can create or reuse Works
and Editions, but contributor names stop at metadata evidence. Neither path
creates canonical Authors or `WorkContributor` edges. Consequently existing
local `text -> Authors` and `Author -> Works` reads have nothing to return.
This is not a SEARCH-UI-01B defect.

## 2. Authority and scope

This design applies the fixed product decisions supplied with
D-AUTHOR-MAT-01, `AGENTS.md`, the current project guide/product compass/current
phase, current Git and schema, ADR-010 through ADR-012 and ADR-014, and the
closed Author/Search/materialization documents 34, 69 and 73 through 85.

It defines contracts only. It does not implement code or schema, write runtime
data, change Search UI, build Author detail or Librarian UI, perform a merge,
write migration data, use V1 data, research new providers or invent a Google
Author identity.

## 3. Current Author and schema audit

Current schema `1023` and code provide:

- `biblio_authors(author_id, display_name)`, keyed only by the stable opaque
  `author_id`; `display_name` is indexed but deliberately not unique;
- an `Author` entity with only `AuthorId` and a non-empty UTF-8 display name of
  at most 512 characters;
- `AuthorRepository::find()`, `findMany()`, `contributorsForWorks()` and
  `workIdsForAuthors()`;
- `WritableAuthorRepository::save()` and `addContributor()`; the current
  `save()` is an upsert that can overwrite `display_name`, so routine
  materialization must not reuse that mutation contract unchanged;
- `biblio_work_contributors` with `work_id`, `author_id`, the closed role
  `author|co_author` and a positive `contributor_position`;
- primary uniqueness on `(work_id, author_id)`, unique order on
  `(work_id, contributor_position)`, a reverse Author index and restrictive
  foreign keys; and
- a generic `biblio_bibliographic_provider_identities` table whose current
  shape and repository support only Work and Edition targets.

The current relationship foundation already preserves explicit order and does
not require exactly one primary Author. Its two unique constraints are the
right canonical edge invariants and remain unchanged.

The generic provider-identity model is reusable after a minimal Author
extension. A provider-specific Author mapping table would duplicate its
provider-scoped claim semantics and is rejected.

## 4. Current contributor evidence audit

The current source paths are asymmetric:

- Open Library ISBN details contain ordered `authors[]` objects with both
  `name` and `/authors/OL...A` keys in the existing contract fixture. The
  normalizer currently retains only names.
- Open Library Work Search currently requests only `key,title,author_name`.
  The official Search API also exposes aligned `author_key` values, so the
  existing request can safely ask for that field without a second request.
- Open Library Works-by-Author returns Author keys that prove the selected
  Author relation, but not display names. It must not render or materialize a
  provider key as a name.
- Open Library Editions `contributors` are free-form Edition contributor
  records. They do not prove `author|co_author` Work roles and remain
  evidence-only. Edition candidates inherited from a Work Search result may
  separately carry that Work's typed Authors.
- Google Books `volumeInfo.authors[]` is an ordered list of names. It contains
  no strong provider person identity for Biblio.
- Add Book stores selected-provider and user-observed contributor lists through
  `AddBookCommitEvidenceWriter`; generic materialization stores the same kind
  of list through `MetadataFieldReview`. Both are atomic list evidence, not
  individual Author identity or contributor-credit records.
- The current manual Add Book field is labelled and typed as generic
  `contributors`, not as role-bearing Authors. It does not by itself qualify
  as an `author|co_author` credit.

No implementation may recover strong identity by name-searching Open Library,
invent a Google Author ID, or reinterpret Edition-specific contributors as
Work Authors.

Relevant official Open Library contracts at design time are the
[Search API](https://openlibrary.org/dev/docs/api/search),
[Authors API](https://openlibrary.org/dev/docs/api/authors) and
[Books API](https://openlibrary.org/dev/docs/api/books). Production adapters
remain bounded, validated and fixture-tested rather than trusting arbitrary
provider payloads.

## 5. Canonical Author identity model

An Author is a platform-shared first-class bibliographic person identity. It
has no Library owner and no user owner. `AuthorId` remains the canonical stable
identity; provider IDs and names are evidence about it, never replacements for
it.

Exactly two identity states exist:

### `provisional`

Biblio has a local Author for a valid Work contributor credit, but has not yet
established enough identity authority for reuse beyond that credit. This is a
normal usable state. It does not mean error, suspicion, duplicate or review
required.

### `resolved`

Biblio has enough explicit authority to reuse the canonical Author beyond its
originating credit. That authority is either a safely attached strong provider
Author claim or an audited canonical identity decision. `resolved` does not
claim legal or real-world verification.

There are no `ambiguous`, `duplicate`, `rejected` or `merged` identity states.
Ambiguity/conflict belongs to credit materialization and review evidence;
retirement/redirect belongs to a future merge lifecycle.

State transitions are monotonic in normal materialization:

```text
provisional --strong proof or governed decision--> resolved
resolved ----------------------------------------> resolved
```

No normal materialization demotes a resolved Author.

## 6. Author display name and observed names

`display_name` is presentation metadata behind the stable `AuthorId`; equality
never proves identity and it remains non-unique. A valid observed contributor
name may seed a new Author's display name.

Routine materialization is insert-only for Author identity and never calls an
unconditional name-upsert. It may observe another source name on the credit,
but cannot silently replace the canonical display name. A separate explicit
governance operation may correct the display name under the Biblio Librarian
boundary.

The proposed `display_name_status` is deliberately small:

- `observed`: seeded from a valid credit or provider observation;
- `librarian_confirmed`: explicitly adjudicated canonical presentation.

Identity resolution does not automatically confirm a display name. Alternate
source spellings remain credit evidence. This slice adds no general Author
alias model; a future alias/read model may project that preserved evidence
without changing identity.

## 7. Typed contributor-credit input

The shared materializer accepts only a typed `AuthorContributorCredit`:

- exact canonical `WorkId`;
- role `author|co_author`;
- positive reliable source order;
- valid observed display name;
- stable provenance identity; and
- optional typed strong provider Author identity.

For provider arrays semantically named `authors`, every entry may safely use
role `author`; array order becomes `contributor_position`. The adapter does not
invent a primary/co-author distinction. `co_author` is used only when the
source explicitly supplies that canonical meaning. The existing no-exactly-one-
primary rule remains intact.

If the source does not prove an Author role or reliable order, the value stays
metadata evidence-only. Translators, illustrators, editors and generic Edition
contributors never enter this boundary automatically.

## 8. Name-only contributor identity

The selected contract is an **evidence-scoped Work-credit identity**. It is
neither a global normalized-name mapping nor a provider-wide person inference.

For a valid name-only credit, Core computes:

```text
credit_key = SHA-256(
  "author-credit-v1" NUL
  work_id NUL
  role NUL
  decimal_position NUL
  normalized_observed_name NUL
  source_credit_identity
)
```

`normalized_observed_name` performs only deterministic UTF-8 whitespace trim
and collapse. It preserves case, accents, punctuation and all other code
points. There is no case-folding, transliteration, punctuation stripping,
token similarity, phonetic rule or fuzzy comparison.

`source_credit_identity` is mandatory, stable and derived by Core rather than
accepted from a client as identity authority:

- provider evidence uses provider key, typed source entity, stable provider
  Work/Edition record ID and the source contributor slot;
- a future typed user observation uses its opaque server-issued observation or
  idempotency ID; and
- migration evidence uses the MIG-FND source observation ID.

A query string, retrieval time, Item ID, transient candidate ID or contributor
name alone is not a stable source-credit identity. The current generic manual
`contributors[]` input has no typed source identity or role and therefore stays
evidence-only until a separate contract supplies both.

This yields the required behavior:

- an exact replay of the same source/Work/role/position/name credit reuses the
  same credit and provisional Author;
- an independently observed source credit at the same Work/role/position/name
  has another key; it never reuses or merges a provisional Author merely
  because the visible values match;
- the same name on another Work has another key and creates another
  provisional Author;
- the same name twice at different positions on one Work remains two distinct
  credits;
- a changed name, role or position is not silently treated as the old credit;
  it meets the occupied edge as an explicit conflict; and
- provenance scopes retry identity but is not person identity. Independent
  source credits may be reconciled only by a shared strong provider Author
  claim or explicit governance, never by their matching names.

On first materialization, the credit and one new opaque `AuthorId` are created,
the Author is `provisional`, and one WorkContributor edge is linked. The
credit row is the durable retry anchor; a random Author ID alone is not the
idempotency mechanism.

## 9. Strong provider Author identity

Only a provider field explicitly defined and validated as an Author identity
may enter the strong path. In the current provider set this is an Open Library
`/authors/OL...A` key. Google name strings never do.

Within the caller-owned transaction, the algorithm is:

1. derive the deterministic credit/evidence identities and find/lock an
   existing credit when present, without inserting an unlinked normal credit;
2. look up `(provider, source_entity_type=author, provider_author_id,
   target_type=author)`;
3. when found, require the mapped Author and use it;
4. when absent, create an opaque canonical Author as `resolved`, then insert
   the immutable provider claim;
5. require any already-linked exact credit to name that same Author; otherwise
   retain the new evidence as an `identity_conflict` without reassignment; and
6. atomically insert/reuse the linked credit, preserve its evidence and link
   the exact ordered WorkContributor edge.

There is no transient persisted `pending` credit. A new normal strong credit is
first written only when its Author is known, so the schema's linked/unresolved
shape is true at every commit. An expected unique race escapes for the complete
outer-transaction retry in section 20.

The provider claim is immutable in ordinary materialization. Claiming the same
key for the same Author is idempotent. Claiming it for another Author fails
closed as `identity_conflict`.

Two concurrent creators may each tentatively allocate an Author ID, but the
unique provider claim chooses one winner. The transaction owner rolls back the
losing complete attempt and retries from lookup, yielding exactly one canonical
Author and no orphan.

## 10. Provider-specific normalization

### Open Library

- ISBN details: accept only validated `{key,name}` Author objects; each valid
  pair is a strong ordered Author credit.
- Work Search: request `author_name,author_key`; pair only validated values at
  the same array position. A valid name without a safely pairable key becomes
  name-only. A key is never displayed as a fallback name.
- Editions inherited from that Work Search candidate retain the Work's typed
  Author credits separately from Edition contributor strings.
- Works-by-Author keys remain selection/membership evidence. This design adds
  no detail request or Author-name enrichment fan-out.
- A missing/malformed key does not invalidate a valid name-only credit.

### Google Books

Each valid `volumeInfo.authors[]` string is a name-only Work Author credit in
the given order. Role is `author` for every entry because Google asserts
authorship but not Biblio's primary/co-author distinction. No Google provider
Author claim is written.

### Generic/manual contributor input

The current free-form Add Book `contributors` list remains evidence-only
because it does not establish Work Author role. A future typed manual Author
input may call the same materializer, but adding that UI/REST shape is not part
of this design slice.

## 11. WorkContributor contract

The canonical edge remains:

```text
WorkId + AuthorId + (author|co_author) + positive position
```

The existing database invariants remain authoritative:

- `(work_id, author_id)` prevents one canonical Author appearing twice on a
  Work; and
- `(work_id, contributor_position)` ensures one canonical credit at each
  display position.

The position is source-faithful and never alphabetically reordered. Exact edge
replay is success. An occupied position with another Author, or the same
Author at another position, is not silently rewritten; the new evidence is
retained as an unresolved credit and receives an actual review reason where
appropriate.

`Work + Author + role` alone is insufficient because it loses display order;
adding position to an identity key also does not replace the two existing
constraints. The current schema already has the correct edge model.

## 12. Credit evidence and review separation

Individual credit identity/provenance needs a durable representation because
the existing atomic contributor-list proposal cannot anchor retries or strong
Author IDs. The proposed credit tables preserve that evidence without making
names globally unique.

A credit has materialization status `linked|unresolved`. This is not an Author
identity state. Review state is orthogonal: a linked credit may remain linked
to its existing Author while conflicting new evidence is reviewed. A nullable
review reason is recorded only for a genuine issue:

- `identity_conflict`: one strong provider claim points at incompatible
  canonical Authors;
- `ambiguous_match`: the evidence supports more than one explicit canonical
  target and Core cannot choose;
- `structural_ambiguity`: role/order/edge structure conflicts and cannot be
  safely applied; or
- `possible_duplicate`: used only when concrete additional evidence suggests
  duplicate Authors, never merely because names match.

Normal provisional creation creates no review reason or queue record. Two
same-name provisional Authors alone are normal and create no
`possible_duplicate` signal.

## 13. Promotion from provisional to resolved

A provisional Author is promoted in place only when proof binds a strong
identity to that exact existing credit or an authorized governance decision
names that exact Author. Name equality is never proof.

For a strong provider identity later proven to belong to the same credit by
the exact `credit_key` or a governed decision:

1. lock the credit, linked Author and relevant provider claim;
2. require the credit still links to that Author;
3. if the claim is absent, claim it for that Author and change only
   `identity_status` to `resolved`;
4. if it already maps to that Author, make the replay idempotent and promote if
   still provisional; and
5. if it maps to a different Author, preserve both, write
   `identity_conflict`, and do not promote, merge, reassign or overwrite.

Promotion preserves `AuthorId`, display name, WorkContributor edges and all
credit evidence. Provider names remain observations and cannot overwrite a
Librarian-confirmed display name.

## 14. Two provisional Authors later proven to be one person

No merge is implemented in the first materialization slices, but current
choices preserve a safe future contract:

- Biblio Librarian supplies an explicit `survivor_author_id`; the system does
  not automatically choose oldest, first, most-used or provider-backed;
- expected versions and the affected Author IDs, claims, credits and edges are
  locked in one transaction;
- compatible provider claims and credit evidence move to the survivor;
- WorkContributor edges are reassigned only when all Work/position conflicts
  have an explicit resolution; otherwise the whole merge fails closed;
- the retired Author is retained as a tombstone/redirect to the survivor, so
  stored IDs and external references do not silently break; and
- an immutable audit record retains actor, reason, source/target IDs and time.

A later merge slice may add redirect/audit tables. They are intentionally not
part of schema 1024 because no merge write is authorized here. Authors with
active WorkContributor edges are never hard-deleted.

Two equal-name but different people remain distinct indefinitely. There is no
unique or normalized-name constraint.

## 15. Explicit user confirmation and governance

Canonical Authors are platform-shared. A normal authenticated user, Eigenaar
or Beheerder may later submit an explicit identity assertion such as “this
credit is Author X”, with source credit, actor and time preserved. That
assertion is stronger than an inferred name match but is still a proposal for
shared canonical mutation.

Only the explicit Biblio Librarian capability may approve the final
promotion, reassignment or merge. WordPress Admin/Super Admin and Library
membership do not implicitly grant it. No client-visible selector, Library ID
or contributor name is authorization evidence.

## 16. Shared application boundary

There is one internal Core boundary, conceptually
`CanonicalAuthorMaterializer::materializeForWork(WorkId, credits)`. It:

- assumes the caller already authorized its enclosing use case;
- accepts typed validated credits, not provider payloads or REST bodies;
- owns identity, claim, credit and WorkContributor rules;
- does not own a transaction and must run inside the caller's transaction;
- performs no network calls; and
- returns per-credit typed outcomes rather than throwing for expected soft
  ambiguity.

Add Book, generic bibliographic materialization and later MIG-02 must reuse
this boundary. Controllers, UI and provider adapters do not write Authors or
edges directly.

## 17. Add Book integration

Author materialization belongs in the existing
`AddLibraryItemTransactionParticipant` phase, composed with
`AddBookCommitEvidenceWriter`. The participant runs after the final Work and
Edition identity— including an ISBN race winner—has been established, but
inside the same transaction as Work/Edition/Item, provider/user evidence and
Library catalog context.

This is option A: one transaction for every write derived from the accepted
Add Book commit. It avoids an avoidable post-commit window in which a valid
typed Author credit is lost.

Expected metadata limitations remain soft:

- provider unavailability has already been handled before commit and triggers
  no new request;
- a valid name-only credit creates a provisional Author;
- missing/invalid/non-Author contributor values remain evidence-only;
- an edge/identity conflict is durably unresolved with evidence and does not
  roll back an otherwise valid Item; and
- an unexpected persistence failure, broken FK or transaction failure is hard
  and rolls back the complete commit rather than claiming success with
  unpersisted evidence.

Expected Author uniqueness races also escape the participant as the typed race
signals defined below. `AddBookCommitService`, outside
`AddLibraryItemService`'s transaction, retries the complete commit once with
the same command/idempotency identity and preallocated canonical IDs. It never
retries only the participant or commits an Item before Author-race recovery.

This preserves basic Add Book independence from providers without accepting
half-written identity state.

## 18. Generic bibliographic materialization integration

`BibliographicMaterializationService` invokes the same shared boundary inside
its existing Work/optional Edition/provider-claim/evidence transaction, after
the final canonical Work is known and before returning. Work-only and
Work+Edition intents behave identically for Work Authors.

Its existing outer transaction retry is extended to the typed Author race
signals below. A retry always reruns the whole materialization transaction with
the same materialization intent, never only the Author substep.

The exact actor-scoped candidate snapshot must retain typed Author-credit
evidence internally. Public discovery response copy may remain its existing
provider-neutral contributor-name presentation; clients do not compose
identity or Author claims.

Mapped Work/Edition reuse revalidates all newly supplied Author claims and
credits before return, just as existing reuse paths revalidate provider Work,
Edition and ISBN claims. No Item, Library ownership, Wishlist state or Search-
specific write is added.

## 19. Failure, idempotency and consistency

The invariants are:

1. same strong provider Author replay returns one Author and one claim;
2. same source-scoped name-only `credit_key` replay returns one provisional
   Author;
3. same canonical edge replay succeeds without a duplicate;
4. another Work credit with the same name never reuses that provisional
   Author automatically;
5. one provider Author key cannot point to two canonical Authors;
6. one Work position cannot silently move between Authors;
7. evidence is recorded before a soft unresolved outcome is returned; and
8. every hard failure rolls back all writes in the enclosing transaction.

A Work may intentionally exist without an Author edge when it had no valid
typed Author credit or when a genuine conflict stayed unresolved. This is a
truthful incomplete bibliographic state, not corruption. A valid linked credit
cannot commit without its Author and edge.

There is no background retry requirement for normal materialization. Replaying
the same accepted source candidate or a later governed correction is the
recovery mechanism; the durable source-scoped credit/evidence key makes that
retry safe.

## 20. Concurrency contract

Implementations must lock/read in deterministic order and rely on database
uniqueness, not preflight checks alone. The shared materializer may surface
only the expected typed `AuthorProviderClaimRace` or
`AuthorContributorCreditRace`/`AuthorContributorPositionRace` signals for
unique-constraint races. It does not catch those signals and continue inside a
possibly failed caller transaction.

The enclosing transaction owner lets the signal escape so the complete attempt
rolls back, then retries the **whole enclosing transaction once** from fresh
state with the same request/command identity. On that retry:

- same provider Author: reload the unique provider claim and reuse its resolved
  Author;
- same source-scoped name-only credit: reload the unique `credit_key` and its
  linked provisional Author;
- two independent same-name Work credits: different keys and different
  Authors, unless they compete for the same occupied Work position;
- competing claims to different Authors: immutable unique claim fails closed,
  records `identity_conflict`, and never reassigns;
- competing Work positions: existing `(work_id, contributor_position)`
  uniqueness prevents silent replacement. When the fresh retry finds another
  credit already occupying the position, it records the losing independent
  credit/evidence as `unresolved` without allocating an Author and lets the
  enclosing product commit continue; and
- repeated evidence: unique evidence identity increments observation history
  rather than duplicating rows.

The retry is bounded to one complete retry for those exact race classes.
Persistent races and unknown persistence errors remain hard failures and are
never converted to success. This contract guarantees that neither Add Book nor
generic materialization can leave an orphan provisional Author or a partially
committed enclosing aggregate.

## 21. Proposed schema 1024 delta

Schema `1023` remains current. The first implementation slice may propose one
linear, retry-safe and partial-shape-fail-closed migration to `1024`.

### `biblio_authors`

Add:

- `identity_status VARCHAR(16) NOT NULL DEFAULT 'provisional'` with check
  `provisional|resolved`;
- `display_name_status VARCHAR(32) NOT NULL DEFAULT 'observed'` with check
  `observed|librarian_confirmed`; and
- `author_version BIGINT UNSIGNED NOT NULL DEFAULT 1` for governed optimistic
  writes.

Do not add name uniqueness. Do not invent creation/update timestamps for
legacy rows.

### `biblio_bibliographic_provider_identities`

Add nullable `author_id`, an Author foreign key and an Author lookup index;
allow `source_entity_type='author'` and `target_type='author'`; extend the
target check so exactly one of Work, Edition or Author is populated; and close
the source/target matrix to exactly `author -> author`, `work -> work`, and
`edition -> work|edition`. An Author source can therefore never claim a Work or
Edition target, and a Work/Edition source can never claim an Author target. The
existing primary key remains the immutable provider claim key.

### New `biblio_author_contributor_credits`

Minimum columns:

- opaque `credit_id` primary key;
- unique binary `credit_key CHAR(64)`;
- `work_id` foreign key;
- `contributor_role`, positive `contributor_position`;
- `observed_display_name` and `normalized_name_hash CHAR(64)`;
- nullable `author_id` foreign key;
- `materialization_status` checked to `linked|unresolved`;
- nullable `review_reason` checked to the four Author-applicable reasons; and
- real `created_at`, `updated_at` and positive `credit_version` for new credit
  history/concurrency.

Checks require `linked` iff `author_id` is present and `unresolved` iff no
Author is linked. `review_reason` remains independently nullable, so later
conflicting evidence can be attached to an already linked credit without
discarding its existing truthful edge. A newly unresolved valid credit requires
an actual reason. Index `(work_id, contributor_position, credit_id)` supports
conflict inspection.

### New `biblio_author_credit_evidence`

Minimum columns:

- `credit_id` foreign key and deterministic `evidence_id CHAR(64)` as the
  composite primary key;
- source kind `provider|user_observation|migration`;
- nullable provider/source-record/strong-provider-Author fields with exact
  shape checks;
- observed display name, role and source position;
- first/last observed time and positive observation count.

Provider evidence identity includes provider, source entity/record, role,
source position, exact observed name and optional strong Author ID. An exact
retry increments its observation history; a changed value is another evidence
row and cannot silently change the credit or Author. User and migration
evidence uses their existing stable observation identity plus the exact credit
value. No name is a provider claim.

## 22. Existing Authors and migration path

Every pre-1024 Author is backfilled to `identity_status=provisional`,
`display_name_status=observed`, `author_version=1`. That is conservative:
current storage proves a stable Biblio ID and name, but not a strong external
person identity or Librarian confirmation. Existing names/IDs/edges remain
unchanged and no two rows are merged.

Where an environment has pre-existing Author evidence outside these tables,
later explicit reconciliation may promote it. The schema migration itself does
not infer that evidence.

## 23. Search implications

Existing local Search already reads `biblio_authors` and
`biblio_work_contributors`. Newly linked Authors therefore become visible in
`text -> Authors`, Work projections and `Author -> Works` without any Search-
owned write or UI change. Identity status is not added to ordinary responses.

The provider-claim implementation must also add a bounded read-only mapping
projection:

- a mapped external Author result becomes a trusted canonical-plus-provider
  reference and is deduplicated by claim, never by name;
- a canonical local Author may carry exactly one supported current provider
  claim into its signed selector; zero claims stays canonical-only, while
  multiple competing supported claims stay canonical-only until governance;
  and
- selector consumption revalidates that the mutable provider claim still maps
  to the signed canonical Author before using the external lane.

This fulfills D-AUTHOR-REF-01's stated future revalidation requirement. Search
does not materialize Authors, choose among claims or display provisional
status.

## 24. Visibility, security and deletion

Ordinary UI shows the Author display name. It receives no default provisional
badge and cannot infer status from a selector. Reliable disambiguation context
may be added later only when it helps prevent a wrong choice. A future
Librarian/admin read model may expose identity state, claims, evidence and
actual review reasons.

Materialization is a server-side platform-bibliographic function. Its enclosing
use case performs authentication/authorization; neither Library membership nor
user ownership determines Author identity. Governance writes require the
separate Biblio Librarian capability.

An Author with active WorkContributor, claim or credit references is not hard-
deleted. Library Archive semantics never apply to Authors. Retirement and
redirect are future merge-governance concerns.

## 25. V1 migration implication and actual-data rule

The target can later accept MIG-02 Author identities, typed roles, source order
and stable migration-observation evidence through the same shared boundary.
This design does not decide V1 matching, cross-source reuse or whether a
particular V1 Author has enough evidence to start resolved.

No current or historical V1 `/data/`, MIG-01 fixture source, DATA-01 record,
snapshot count or prior export was read or used for this design. A future
source-dependent migration design/run must ask Renée for a newly designated
current export and pin its hash.

## 26. Required implementation tests

Future implementation must cover at least:

1. strong Open Library Author creates one resolved Author and claim;
2. replay reuses that Author;
3. concurrent same Open Library Author yields one Author and no orphan;
4. name-only credit creates a provisional Author;
5. exact same-source name-only credit retry reuses it;
6. an independent same-value credit on the same Work does not reuse or merge
   it, and an occupied position becomes unresolved without an orphan;
7. same name on another Work does not reuse it;
8. ordered WorkContributor creation and exact replay;
9. `co_author` is preserved only from explicit role evidence;
10. source order is preserved and never alphabetized;
11. Google Author strings stay provisional and create no provider claim;
12. Open Library aligned Author keys are strong; malformed/misaligned keys
    safely degrade only valid names to name-only;
13. Edition contributor strings never become Work Authors;
14. promotion keeps Author ID/name/edges and adds the claim;
15. name match alone cannot promote;
16. conflicting provider claim fails closed and retains evidence;
17. occupied Work position is not overwritten;
18. malformed/empty/non-Author credit creates no Author or edge;
19. Add Book provider failure makes no Author call and does not block manual
    base commit;
20. expected soft Author conflict preserves evidence while Item/Work/Edition
    commit remains consistent;
21. both Add Book and generic materialization roll back and retry their complete
    transaction once for each typed Author race, with no partial Item or orphan;
22. a persistent typed race and any unknown persistence failure roll back the
    enclosing commit;
23. generic Work-only and Work+Edition materialization share the same Author
    behavior;
24. Search sees the canonical Author and linked Works;
25. mapped Search result uses current strong identity and rejects stale
    composite mapping;
26. ordinary REST/UI has no provisional badge/status dependency;
27. provider observations cannot overwrite a Librarian-confirmed name;
28. the provider-identity source/target matrix rejects every cross-type shape;
29. schema upgrade preserves existing Authors/edges as provisional without
    name merge or invented time; and
30. all four specified concurrency scenarios are enforced by constraints and
    bounded race recovery.

## 27. Recommended implementation slices

### AUTHOR-MAT-01A — identity, credit and claim persistence

Implement schema 1024, the two Author states, insert-only/update-separated
repository contracts, Author provider-claim support, contributor-credit
tables, opaque IDs and migration/health/concurrency foundations. No provider or
consumer integration.

### AUTHOR-MAT-01B — strong Open Library Author materialization

Add typed Author-credit objects, retain Open Library ISBN/Search Author keys,
implement the shared strong-identity materializer including in-place promotion
and claim-race recovery. No generic/Add Book consumer cutover.

### AUTHOR-MAT-01C — name-only provisional materialization

Implement exact evidence-scoped Work-credit keys, Google name-only Authors,
ordered edges, soft unresolved outcomes and retry/concurrency behavior. Keep
generic Edition contributors and untyped manual contributor input
evidence-only.

### AUTHOR-MAT-01D — generic materialization and Search consumption

Invoke the shared boundary inside generic bibliographic materialization;
project/revalidate Author claims in the existing Search read boundaries; prove
`Search -> Author -> Works` without Search-owned writes. No UI change.

### AUTHOR-MAT-01E — Add Book transactional integration

Compose the shared materializer with the existing Add Book transaction
participant, preserve soft/hard failure policy, and prove existing Edition,
new Edition, new Work and ISBN-race-winner paths. No manual Author UI redesign.

Future merge/redirect UI, Librarian dashboard and MIG-02 writes remain separate
explicit slices.

## 28. SEARCH-UI-01B acceptance relation

SEARCH-UI-01B remains technically GO. Its successful human
`Author -> Works -> Work -> Editions` flow is blocked only by the absence of
representative canonical Author relationships in the current runtime.

After AUTHOR-MAT-01A through the first production consumer integration and
source-neutral representative local data are ready, Renée should repeat only
the recorded Search drill-down/back-flow acceptance. No Search UI change is
authorized unless that QA exposes an actual UI defect.

## 29. Design exit

No technical Author-identity decision is deferred into implementation.
Implementation must follow the exact strong-claim, evidence-scoped Work-credit,
edge, promotion, failure, transaction, concurrency, visibility and governance
contracts above. Any newly discovered provider shape that cannot supply the
required typed input remains evidence-only and is reported rather than silently
reinterpreted.
