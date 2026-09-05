# 43 — Metadata Hub technical readiness and implementation design

Status: **GO WITH CONDITIONS — READY WITH CONDITIONS FOR METADATA HUB
IMPLEMENTATION**.

Date: 2026-09-05.

Task severity: **High**.

This is analysis and implementation design only. It adds no runtime behavior,
schema migration, provider adapter, REST route, frontend or Series Intelligence.

## 1. Outcome and fixed boundary

The repository has enough foundations to start a small provider-neutral
Metadata Hub, but schema 1013 and the current transport cannot finish it. The
safe build path is:

1. normalize one ISBN into a typed canonical ISBN-13 plus optional ISBN-10
   alias;
2. authorize one explicit Library Context and search canonical local Editions
   before any cache or provider;
3. use replaceable provider adapters behind one Hub port;
4. call Open Library first and Google Books only after a miss, insufficient
   result, ambiguity or provider failure;
5. return one reviewable whole-record proposal or an explicit choice, never a
   silently accepted or field-fused record;
6. require an explicit confirmation command before a canonical write;
7. create/reuse Work, Edition and Item atomically and persist only minimum
   provenance, without a raw provider-response blob;
8. keep manual and no-ISBN creation available when both providers fail.

The implementation verdict is conditional on all of the following:

- schema 1014 (or the then-next number) must add the two small tables in
  section 8; a migration is not part of this document;
- a pre-migration duplicate report must classify every existing canonical
  ISBN-13 collision; ambiguous rows are not auto-merged or deleted;
- the first canonical write set must remain the currently representable set in
  section 7. Rich Edition fields need their own typed persistence foundation
  before a UI may claim that it saves them;
- the Open Library contact identifier must be operationally configured;
- `GOOGLE_BOOKS_API_KEY` may enable only the optional Google adapter, and the
  commercial-release terms/licensing gate remains open;
- provider-cover download or canonical cover storage remains excluded until
  cover ownership, persistence and reuse rights are separately approved.

These conditions do not authorize a title/author Work merge, rich metadata
schema, provider-specific Core models, field-level fusion, Series runtime,
Wikidata, BookBrainz, OCR or a Metadata Graph.

## 2. Sources and repository baseline

Authority order used for this design:

1. `docs/01-functional-design.md` section 4 and the latest decisions;
2. ADR-010;
3. `AGENTS.md`, current state, architecture, scope/deferred, terminology and
   testing/acceptance;
4. current Core, REST, UI, persistence and tests;
5. `tools/metadata-benchmark/` as measured evidence, not runtime or product
   authority.

Inspection started on `main` at
`14e6dcab4f56ded8f754906f49556aee45a1c3dc`, equal to `origin/main`, with a
clean worktree. Relevant earlier commits are benchmark `924e3ae`, decision lock
`bdd5714` and refinement `14e6dca`.

A read-only local WordPress database check confirmed installed schema 1013 and
one Edition row with no ISBN values and no duplicate ISBN-10/ISBN-13 group. This
is evidence for the current development database only. The duplicate/equivalence
report must be repeated against every actual migration target immediately before
an approved schema change.

The benchmark supports the adapter order but not blind trust. Open Library
measured 57.67% hit / 55.00% exact Edition overall and 27.78% / 27.16% for NL.
Google measured 97.33% / 82.33% overall. Open Library followed by Google
measured 99.67% hit / 95.33% exact Edition, with 35.00% provider conflict.

Operational guidance was rechecked on 2026-09-05. Open Library asks clients to
cache, identify application/contact and stay within 1 request/second by default
or 3/second when identified; its API is not a high-traffic third-party backend.
Google documents `isbn:` search and requires an API key or OAuth identification
for public requests. Result display remains subject to current attribution,
linking, caching and branding rules. Recheck these changing rules at build and
release time:

- <https://openlibrary.org/developers/api>
- <https://openlibrary.org/dev/docs/api/books>
- <https://developers.google.com/books/docs/v1/using>
- <https://developers.google.com/books/branding>

## 3. Exact current integration map

### 3.1 Entry, application and REST

| Concern | Current evidence | Hub consequence |
|---|---|---|
| Boot | `biblio-core.php` boots `Biblio\Core\Plugin`; `Plugin::initialize()` boots `ProductionComposition` on `init` and emits `biblio_core_initialized` | Compose Hub only in `ProductionComposition`, never Elementor/snippets |
| Application | `CoreApplication` exposes named services including `libraryItemCreation()` and `bibliographicMetadata()` | Add named lookup and confirmation services; expose no provider/repository |
| REST | `RestApi` registers `RestController` on `rest_api_init` under `biblio/v1` | Extend this adapter; no second REST framework |
| Authentication | cookie + `X-WP-Nonce`; no actor request field | derive actor server-side and authorize before cache/network |
| Permission | `AddLibraryItemService::authorize()` requires both item-add and context-initialization rights | reuse the Core policy; lookup cannot become a public quota proxy |

There is no current Hub hook/service and no direct Open Library or Google call
in production. `tools/metadata-benchmark/benchmark.py` is deliberately isolated
research tooling and must not be imported by runtime.

### 3.2 ISBN and local lookup

| Concern | Current evidence | Gap |
|---|---|---|
| Values | `Isbn10`, `Isbn13`, `IsbnType` | hyphen/whitespace normalization and checksum exist; shared parser, conversion, canonical key and typed errors do not |
| State | `EditionIsbnMetadata::unknown()`, `withoutIsbn()`, `identified()` | paired ISBN-10/13 equivalence is not enforced |
| Edition | `Edition` stores ID, Work ID and ISBN state | no rich Edition facts, external ID, provenance or cover |
| Persistence | `WpdbEditionRepository` maps `isbn_10`, `isbn_13`, `explicitly_no_isbn` in Editions | DB checks shape/state, not checksum/equivalence |
| Query | `BibliographicMetadataRepository::editionsForIsbns()` plus wpdb adapter | exact per-column batch lookup; caller must supply both aliases |
| Indexes | schema 1010 `editions_by_isbn10` / `editions_by_isbn13` | non-unique; tests deliberately persist two Editions with one ISBN |
| Catalog search | `WpdbCatalogQueryRepository` ranks exact stored ISBN | Library read, not central creation/deduplication |

`SearchMetadataFoundationTest` covers normalization/checksum.
`SearchMetadataPersistenceTest` covers tri-state/batch access and proves duplicate
ISBN Editions are currently valid. `Schema1010SearchMetadataTest` covers
migration health/retry/preservation.

### 3.3 Work, Edition and Item creation

`Application\Catalog\AddLibraryItemService` is the only production catalog-add
service:

- `addForExistingEdition()` reuses an Edition and Work;
- `addWithNewEditionForExistingWork()` creates an Edition with unknown ISBN;
- `addWithNewWorkAndEdition()` creates a Work title and Edition with unknown
  ISBN.

All authorize actor/Library, initialize `LibraryCatalogContext` when needed and
write the Item in a shared transaction. Composition and Core expose this named
service; unit, MariaDB and separate-process tests cover it.

The service accepts no ISBN metadata, authors, provider evidence or cover. REST
does not call it and has no catalog-add route. Thus there is no end-user ISBN
input, manual catalog-add screen or end-user no-ISBN flow. The domain/repository
can represent no ISBN, but only direct Core/test code uses it.

`biblio-ui` consumes catalog reads. It can render a known `cover_reference`, but
`CatalogUiReadService` deliberately emits cover, ISBN, language, publisher and
date as typed `unknown`. That is compatibility shape, not persistence.

Current metadata-adjacent routes are read-only:

- `GET /biblio/v1/libraries/{library_id}/items` uses the legacy active overview;
- `GET /biblio/v1/libraries/{library_id}/catalog` uses typed
  `CatalogQueryService` and can match stored ISBN;
- `GET /biblio/v1/libraries/{library_id}/items/{item_id}` returns the detail
  projection, whose unsupported rich metadata remains `unknown`.

No current controller, hook or UI path accepts provider metadata or calls
`AddLibraryItemService`.

### 3.4 HTTP, cache, config, errors and seams

- Production Core has no metadata HTTP wrapper or `wp_remote_*` provider call.
- The only transient adapter is `WpTransientLifecycleStateStore` for schema
  lifecycle; there is no metadata cache.
- There is no production provider config abstraction. `GOOGLE_BOOKS_API_KEY`
  occurs only in the isolated benchmark and is omitted from its output/cache.
- `RestErrorMapper` allowlists Core errors and logs unexpected exception
  messages. Raw provider exceptions/URLs must be sanitized before reaching it.
- Existing constructor-injected repositories, clocks, transaction doubles and
  real REST/MariaDB harnesses are reusable test patterns. HTTP, cache, sleeper,
  backoff and config must be similarly injected.

## 4. Target boundary and dependency direction

    REST/UI adapter
      -> MetadataHubLookupService / ConfirmMetadataCandidateService
        -> typed ISBN parser and candidate classifier
        -> local Edition lookup + Library authorization
        -> MetadataProvider ports
             <- OpenLibraryAdapter / GoogleBooksAdapter
                  <- ProviderHttpClient + cache + rate/backoff policy
        -> catalog creation + identifier claim + provenance repositories
          -> wpdb adapters

Core may know internal candidate, evidence, provider key, match method and
confirmation state. It may not know an Open Library JSON key, Google Volume
model, URL/query layout, API key, HTTP status or branding response shape.

Recommended design components (not created in this slice):

- `Application\Metadata\MetadataHubLookupService`;
- `Application\Metadata\ConfirmMetadataCandidateService`;
- `Application\Metadata\MetadataProvider`, `MetadataCandidate`,
  `CandidateClassifier` and `ProviderFallbackPolicy`;
- `Catalog\IsbnInputParser` / `CanonicalIsbn`;
- `Catalog\EditionIdentifierClaimRepository`;
- `Application\Metadata\MetadataProvenanceRepository`;
- Open Library/Google infrastructure adapters;
- WordPress HTTP, configuration, cache and wpdb adapters.

Only the two named application services become `CoreApplication` accessors.

## 5. ISBN contract and local-first algorithm

### 5.1 Shared typed parser

Extend, do not duplicate, current ISBN values. One parser must:

- trim and remove only allowed hyphen and Unicode whitespace separators;
- uppercase the ISBN-10 `X`; reject all other characters;
- distinguish length and run current checksums;
- accept ISBN-13 only with current Bookland prefixes `978`/`979`;
- convert every valid ISBN-10 to canonical ISBN-13;
- derive an ISBN-10 alias from `978` where valid, never from `979`;
- expose input type, optional ISBN-10 and canonical ISBN-13;
- emit `empty`, `invalid_character`, `invalid_length`, `invalid_checksum` or
  `unsupported_prefix`.

`Isbn10`/`Isbn13` remain primitives. The parser owns conversion/lookup keys.
`EditionIsbnMetadata::identified()` must reject a non-equivalent pair. No
adapter, controller or repository reimplements this logic.

### 5.2 Exact sequence

After authentication and Library authorization:

1. parse once to canonical ISBN-13 plus optional ISBN-10 alias;
2. query the identifier-claim repository by canonical ISBN-13;
3. without a claim, query existing ISBN columns for both aliases in one batch
   and de-duplicate by Edition ID;
4. one distinct Edition is `local_exact`; return it and its Work with zero cache
   or provider calls;
5. multiple Editions are `local_ambiguous`; return reconciliation/choices and
   do not ask a provider to decide which confirmed local row wins;
6. zero Editions permits provider cache/provider lookup.

Initial lookup is not by provider or Edition ID. Those become relevant only
after explicit selection. A local row cannot auto-win when aliases resolve to
different Editions, multiple Editions share a key, stored pairs conflict, or a
claim disagrees with legacy columns.

Provider record aliases never create separate Editions by themselves: every
accepted provider record must claim the same canonical ISBN-13 key, while its
provider record ID remains provenance. Existing
`editionsForIsbns()`, `CatalogRecordAlreadyExists`, the transaction manager and
`addForExistingEdition()` are the reusable duplicate/recovery primitives; none
of them alone currently enforces ISBN uniqueness.

### 5.3 Race-safe duplicate prevention and no-ISBN

Find-then-insert plus non-unique indexes cannot prevent concurrent duplicate
Editions. The identifier-claim table in section 8 supplies one unique canonical
ISBN serialization point.

Confirmation repeats local lookup, then creates Work/Edition, claims ISBN and
creates Item/provenance in one transaction. If a concurrent claim wins, the
loser's provisional writes roll back; it re-reads the winner and may retry via
`addForExistingEdition()`. Retry is bounded and re-authorized. Pre-existing
ambiguous groups remain blocked for explicit repair.

Manual creation preserves:

- unknown: ISBN null, `explicitly_no_isbn = 0`;
- explicit no ISBN: ISBN null, `explicitly_no_isbn = 1`.

Neither creates a claim or needs a provider. Later ISBN correction is separate.

## 6. Minimal provider-neutral contract

Conceptual port:

    MetadataProvider::lookup(CanonicalIsbn $isbn): ProviderLookupResult

The closed result union is `candidates`, `miss`, `unavailable`, `rate_limited`,
`configuration_error` or `invalid_response`. Transport exceptions/payloads do
not cross the adapter; provider order is orchestration, not interface behavior.

Minimal whole-record DTO:

| Group | Field | v2.001 use |
|---|---|---|
| Evidence | provider key, record ID, retrieved-at, match method, queried ISBN | provenance/classification; provider record is not canonical identity |
| Edition identity | returned canonical ISBN-13 and optional ISBN-10 | mandatory exactness evidence |
| Review identity | title, subtitle, ordered contributor names, language code(s) | conflict review; title required for strong candidate |
| Publication | publisher/imprint, precision-preserving date, positive pages, binding/format | optional whole-record comparison; absence never fails |
| Work signal | optional explicit provider Work key with `explicit_link` | evidence only, never heuristic merge |
| Cover | `none` or adapter-owned preview reference plus rights/attribution state | optional preview, never required/silently persisted |

No raw JSON, credentialed URL, score, canonical Work ID, Series,
category/subject enrichment or field provenance map is included. Adapters
discard Series/categories in this minimum. Provider name may be secondary
context, never the primary choice label. Record ID remains in signed evidence
and durable provenance unless later UX explicitly needs it.

## 7. Candidate classification, orchestration and write set

### 7.1 Sufficient candidate

A provider candidate is `strong` only when:

- returned identifier normalizes to the queried canonical ISBN-13;
- all returned aliases are equivalent;
- provider record ID and valid non-empty title exist;
- one exact Edition record exists, or multiple records have the same identity
  without identity-critical differences;
- any user review seed has no material title/contributor/language contradiction.

`Strong` means one normal review proposal, never persistence without confirm.
A hit without returned exact ISBN is insufficient even if title/author resemble.

### 7.2 Exact fallback

1. `local_exact` stops external work.
2. Otherwise call Open Library.
3. One strong Open Library candidate is the sole review proposal. Do not call
   Google merely because language is NL: measured NL weakness is chiefly miss,
   while unconditional dual lookup adds latency/quota/conflicts.
4. Call Google after Open Library miss, unavailable, malformed, rate-limited,
   non-exact, ambiguous or identity-conflicting result.
5. Retain usable insufficient Open Library evidence while evaluating Google.
6. One strong Google exact result becomes proposal if OL had no usable result.
7. With both usable, compare whole records. Material identity differences yield
   `choice_required`; sufficiently agreeing records choose one complete record
   by deterministic provider order/completeness, never fields from both.
8. Both miss yields `not_found`; no usable result plus failure yields
   `provider_unavailable`. Both keep manual entry.

Google is fallback for coverage/insufficient evidence, not an unconditional
validator or Core dependency.

### 7.3 Public states

| State | Meaning and path |
|---|---|
| `local_exact` | one local Edition; review summary then add Item/context |
| `local_ambiguous` | inconsistent/multiple local rows; explicit existing-Edition choice or repair; no provider adjudication |
| `review` | one strong external whole record; review/correct, confirm or manual |
| `choice_required` | material identity conflict; compare whole records, `Gebruik deze uitgave` or manual |
| `not_found` | no usable exact result; manual/no-ISBN |
| `provider_unavailable` | providers failed/disabled; explicit retry or manual/no-ISBN |

Identity differences (ISBN relation, Edition, title, contributor, language) are
blocking. Publication differences (publisher/date/pages/binding) are disclosed
but need not block when identity agrees. Enrichment is absent and never blocks.
No field picker or fusion is permitted.

### 7.4 Minimal canonical write set

Repository reality safely supports:

- existing/newly reviewed `Work.work_title`;
- explicit existing Work relation or a new Work identity;
- equivalent normalized Edition ISBN-10/ISBN-13 state;
- Item and optional existing Library context initialization;
- accepted-candidate provenance.

The DTO may carry richer fields to distinguish candidates. Schema 1013 cannot
persist those Edition facts and Author schema has no safe provider-name identity
resolution. MH-B must label them review-only unless a separately approved typed
foundation exists before MH-B5. It must not claim unpersisted corrections were
saved. If the first UI must save them, that is a separate schema/domain slice;
provider JSON is never a shortcut.

A provider's `title` is Edition evidence, not automatically a canonical Work
title. For a new Work the confirmation contract exposes a separately labelled
`work_title`, prefilled at most as an editable proposal and explicitly confirmed
by the user. It is never copied into Work merely because the provider returned
it.

## 8. Provenance and schema decision

**SCHEMA CHANGE REQUIRED: YES.**

Schema 1013 has no provenance persistence and no race-safe ISBN-equivalence
key. Options, transients and postmeta are not responsible canonical storage for
integrity-sensitive Core data. Full provider responses are unnecessary and
unacceptable canonical blobs.

The minimal next migration adds exactly two Core-owned InnoDB tables. Final SQL
belongs to the build slice.

### 8.1 `biblio_edition_identifier_claims`

| Column | Shape | Rule |
|---|---|---|
| `canonical_isbn_13` | binary/case-stable `CHAR(13)` | primary key; validated 978/979 ISBN-13 |
| `edition_id` | current opaque ID shape | unique, restrictive FK to Edition |

This is a concurrency/alias key, not second metadata truth. Edition still owns
ISBN metadata; repositories verify claim and Edition agree. Existing rows are
resolved lazily after legacy-column lookup, so no mandatory mass backfill is
needed. Existing duplicate groups cannot be claimed until repaired.

### 8.2 `biblio_edition_metadata_provenance`

| Column | Shape | Rule |
|---|---|---|
| `provenance_id` | opaque ID | primary key |
| `edition_id` | current opaque ID shape | restrictive FK to Edition |
| `provider_key` | bounded internal string | adapter key, not response class |
| `provider_record_id` | bounded non-empty string | no credentialed URL |
| `retrieved_at` | UTC `DATETIME(6)` | evidence time |
| `match_method` | bounded enum-backed string | e.g. `exact_isbn` |
| `queried_identifier_type` | enum-backed string | `isbn_10` / `isbn_13` |
| `queried_identifier` | bounded normalized string | user-used identifier, no separators |
| `confirmation_state` | enum-backed string | `accepted_unchanged` / `accepted_corrected` |

Use an idempotency uniqueness key over Edition, provider, provider record and
queried identifier. Persist provenance only when an external candidate is
accepted, in the same transaction as canonical creation. Local reuse/manual
records need no invented provider. Rejected/cached candidates are not durable
provenance in v2.001.

No response blob, field evidence, confidence score, key, source URL, Series
hint or cover binary belongs here.

Migration follows current rules: explicit 1013→1014 registration, known
absent/complete retry, pre/post health, version bump only after success,
restrictive FKs, preservation tests and no mutation of existing rows.

## 9. Work-resolution guardrails

1. `local_exact` uses its already confirmed Work relation.
2. A user may explicitly select an existing Work ID; Core verifies existence
   and asks confirmation before attaching a new Edition.
3. An explicit provider Work key is evidence only. Current schema has no
   confirmed external-Work mapping, so it cannot auto-resolve a local Work.
4. Without confirmed local relation or explicit user selection, create a new
   Work identity from the separately reviewed and confirmed Work title.
5. Never call fuzzy title/author matching during confirmation and never use
   `WpdbNextReadingDiscoveryRepository` for identity; it discovers readable
   sources, not canonical sameness.

This prefers a repairable duplicate Work over an irreversible false merge. A
future confirmed external-identifier registry can improve reuse but is not
smuggled into this minimum.

## 10. Provider clients, retries and errors

### 10.1 Shared HTTP seam

Add a narrow infrastructure `ProviderHttpClient` using WordPress HTTP. Allowlist
HTTPS, host, path, method and query fields; bound redirects, response bytes and
JSON depth. Redirects are disabled or limited to the same provider host.
Adapters never accept a UI/provider arbitrary URL, preventing SSRF through
cover/link values.

Inject client, clock, sleeper/backoff and config. Normal CI uses fake providers
or fixture-backed fake HTTP and never requires networking or live credentials.

### 10.2 Open Library

- Use one user-triggered exact ISBN Books API request; no scraping, fuzzy
  search, dump or bulk harvesting.
- Validate returned Edition/Work keys and ISBN; do not trust response-key
  placement as exactness by itself.
- Require application/contact config; send identifying User-Agent and current
  documented contact/email form.
- Limit at or below 1 request/second unless identified 3/second operation is
  deliberately configured.
- Use 4-second timeout, at most two attempts total, bounded `Retry-After`, and
  retry only 429/transient 5xx/network failure.
- A reliable Edition→Work key becomes `explicit_link` evidence only.

### 10.3 Google Books

- Use `GET /books/v1/volumes` with `q=isbn:<canonical ISBN-13>`, bounded
  `maxResults` and public-data API identification.
- Read `GOOGLE_BOOKS_API_KEY` once in infrastructure config and pass a secret
  value object to the adapter, never through Core.
- Missing key disables only Google and maps to sanitized unavailability;
  Open Library/manual/no-ISBN stay usable.
- Normalize every Volume and retain only candidates whose industry identifier
  maps exactly to the query; never take the first result merely by order.
- Multiple exact volumes with identity differences are conflicting.
- Use 4-second timeout and at most two attempts for 429, 500, 502, 503, 504 or
  network failure. Honor bounded `Retry-After`; do not retry auth/ordinary 4xx.
- Quota/key/provider messages become internal categories, never REST text.

The benchmark's three delayed retries suited research completion, not an
interactive request. The two-provider pipeline should be bounded to roughly
nine seconds before manual recovery.

### 10.4 Error behavior

| Internal category | Fallback | Public behavior | Logging |
|---|---|---|---|
| `invalid_identifier` | none | 422 validation; input/manual remain | category only |
| `not_found` | next provider, then manual | state, not exception | provider/duration/hashed key |
| `provider_unavailable` | next provider, then manual | generic unavailable/retry/manual | sanitized category/status/attempt |
| `provider_rate_limited` | next provider; later retry | generic unavailable/retry/manual | bounded retry-after |
| `provider_auth_error` | disable Google for request | generic unavailable/manual; separate admin diagnostic | config category only |
| `invalid_response` | next provider | generic unavailable/manual if no candidate | schema category/size, no payload |
| `ambiguous` | next provider or review | choice/manual, not 500 | no full record |
| `conflict` | review | choice/manual, not 500 | field-category names only |

Never log full URL, query with `key`, payload, API key, REST stack trace, cover
query or candidate token. Log only correlation ID, provider, attempt, duration,
result and one-way ISBN hash where needed.

## 11. Cache design

Add `MetadataProviderCache` with WordPress transient and no-op adapters. Always
authorize and query local canonical data before cache.

    biblio:mh:v1:<provider-key>:<sha256(canonical-isbn-13)>

Cache one adapter-normalized provider result, never a fused record, key or raw
request URL. Bound entry size and candidate count.

- Open Library positive exact: at most 24 hours; miss: 15 minutes.
- Transient errors: no cache, except bounded rate-limit suppression until
  `Retry-After`.
- Google: obey current response cache headers and reviewed terms. With no
  explicit permitted TTL, use no shared cross-request cache. Never copy OL TTL.
- Confirmation repeats local lookup/token validation. Cache is evidence speed,
  never write authority.
- After canonical write local-first makes stale provider cache irrelevant;
  best-effort deletion is optional, not correctness.
- Contract/provider version in key handles invalidation/replacement.

Negative cache cannot hide a new local Edition because local precedes cache.
This is not a metadata warehouse.

## 12. API and UI boundary

**API CHANGE REQUIRED: YES.**

No route accepts ISBN lookup or catalog creation. Preserve current GET contracts
and add:

1. `POST /biblio/v1/libraries/{library_id}/metadata-lookups` with JSON
   `{ "identifier": "..." }`;
2. a POST variant on existing `/biblio/v1/libraries/{library_id}/items` for
   explicit `existing_edition`, `candidate` or `manual` confirmation. Existing
   GET remains unchanged.

Both require cookie/nonce and Core item-add authorization. Lookup authorizes
before provider work; confirmation re-authorizes and repeats local/concurrency
checks. UI capabilities are never authority.

Minimal response in existing `{ "data": ... }` envelope:

    {
      "status": "local_exact|local_ambiguous|review|choice_required|not_found|provider_unavailable",
      "identifier": {
        "input_type": "isbn_10|isbn_13",
        "isbn_10": "string|null",
        "isbn_13": "string"
      },
      "local_matches": [],
      "candidates": [],
      "manual_available": true,
      "retry_available": true
    }

Each candidate has an opaque actor/Library/ISBN/expiry-bound signed token,
match strength, allowlisted review fields, Work-signal state and material
difference field names. No raw payload or credential. Reuse the application
HMAC pattern with `wp_salt('auth')`-derived, purpose/version-separated secret,
not an unsigned presentation cursor. Tokens are short-lived/single-purpose and
validated on confirm; replay never bypasses auth/local-first.

Confirmation posts mode, token if applicable, explicit Work resolution
(`existing` plus Work ID or `new`), reviewed allowlisted values and optional
existing classification input. Prefer server-generated opaque IDs. Corrections
are untrusted input and pass the same validation as manual input.

Status policy:

- invalid syntax/identifier: 400 transport or 422 Core validation;
- miss/ambiguity/provider unavailable: 200 explicit state because manual is a
  valid outcome;
- inaccessible Library: existing non-enumerating 404;
- stale/expired/tampered token or concurrent identity conflict: 409;
- Core unavailable: existing 503.

Frontend knows only Hub states/candidates. It has no provider branching, ISBN
logic, provider URL, secret, dedupe or Work-merge logic.

## 13. Security, secrets and graceful degradation

- `GOOGLE_BOOKS_API_KEY` is environment-only: never committed, sent to JS,
  stored in options/cache/tokens/provenance, fixture or exposed in errors.
- Add one infrastructure configuration factory. Tests construct config directly
  rather than mutating environment.
- Logging receives pre-sanitized events that never contained key/raw URL.
- Bound payload size/depth/strings/candidates/redirect host and cover schemes.
- Rate-limit lookup per actor plus globally per provider after authorization.
- Tokens bind actor, Library, canonical ISBN, provider record digest,
  issued/expiry and purpose; they convey evidence, not permission.
- Missing key, quota, provider/cache failure and misses all preserve manual and
  explicit no-ISBN creation.
- Google commercial release stays blocked until current terms, caching,
  branding/paid-use and disable/replace path are reviewed.

## 14. Covers and Series hints

### Covers

Current Core has no cover persistence. Read models expose unknown. Therefore:

- absence never changes candidate strength/add success;
- adapters may give a bounded review preview signal only;
- do not download, sideload, permanently cache or mark Primary in MH-B without
  provider-specific licensing/storage review;
- do not server-fetch arbitrary browser URLs;
- later Biblio-owned/user-uploaded cover and Primary model stays independent
  and wins over provider preview;
- current Open Library guidance recommends direct cover URLs/no crawling;
  Google display has separate attribution/link rules. Neither is assumed to
  grant permanent reuse.

Provider cover persistence is **LICENSING REVIEW REQUIRED**, not a metadata-add
blocker because cover is optional.

### Series

**SERIES HINTS IN V2.001 HUB: NO.**

Raw records may contain Series-like text, but the benchmark does not establish
reliable runtime value and canonical Series is multidimensional. Passing hints
now creates an unused contract and mutation risk. Adapters discard Series and
categories. A later versioned evidence slice may add hints but never silently
creates/canonizes Series, order, group or completeness.

## 15. Backward compatibility and migration posture

- Existing Work, Edition and Item rows remain valid and unchanged.
- Unknown and explicit-no-ISBN remain distinct.
- There is **no mandatory metadata or provenance backfill**.
- Claims start empty and populate lazily after one unambiguous legacy match or
  new accepted Edition.
- Before migration, report equivalent ISBN alias groups mapping to multiple
  Editions. Migration never merges/deletes/chooses a winner.
- Legacy/manual provenance may be absent; absence is unknown origin, not lower
  canonical authority.
- Existing REST GET routes/fields and `AddLibraryItemService` callers remain
  compatible.
- Confirmation composes catalog primitives rather than silently changing them.
- Confirmed local values are never overwritten. Provider-driven update of an
  existing Edition needs a future explicit correction/proposal flow.

## 16. Test matrix

### Unit

- separators, trim, `x`, valid/invalid ISBN-10/13 and all error reasons;
- 10→13, 978 reverse alias, 979 without false ISBN-10;
- equivalent-pair invariant;
- OL/Google fixture normalization with bounded/malformed values;
- strong/ambiguous/conflict/miss and difference categories;
- complete fallback table, including no language-only Google call;
- error/retry/backoff with injected clock/sleeper;
- no title/author/fuzzy Work merge;
- token purpose/actor/Library/expiry/tamper;
- secret-safe log event construction.

### MariaDB/application integration

- local ISBN-13 hit skips cache and providers;
- ISBN-10/formatted aliases resolve one Edition; 979 remains 13-only;
- multiple legacy matches return `local_ambiguous` and zero provider calls;
- OL exact; OL insufficient/miss/error flows to Google;
- Google exact/multiple/miss/unavailable/missing-key;
- two-provider conflict returns whole records, no fusion;
- confirmation rechecks local data and never overwrites;
- accepted candidate writes Edition/Item/claim/provenance atomically;
- corrected/unchanged provenance; local/manual invent none;
- begin/operation/commit/rollback failure semantics;
- concurrent same-ISBN adds in same/different Libraries yield one Edition claim
  and valid Items with loser rollback/reuse;
- existing duplicates are reported/blocked, never merged;
- manual unknown/no-ISBN works with providers disabled;
- schema health/retry/partial failure/FKs/uniqueness/legacy preservation.

### REST/security

- real `WP_REST_Server` covers nonce/auth, Library permission and
  non-enumeration before provider calls;
- strict body/response allowlists and bounded candidate counts;
- every public state serializes exactly;
- tampered/expired/cross-actor/cross-Library token failures;
- key/raw URLs/payload absent from REST/logs/errors/fixtures;
- rate, timeout, redirect, response size and JSON-depth bounds;
- existing `/items` GET and all routes unchanged.

### UI/E2E and optional smoke

- review, choice, correction, retry, manual and no-ISBN paths;
- no duplicate loading; explicit retry; failure never auto-posts;
- keyboard/focus/status, compact differences and mobile no-overflow;
- one confirmation mutation; refresh/retry cannot duplicate;
- deterministic CI uses fixtures only;
- live contract smoke is opt-in, non-deterministic, needs contact/key and never
  writes canonical data.

Do not import the benchmark cache wholesale as fixtures. Select small sanitized,
license-reviewed representative shapes and synthetic edge cases.

## 17. Implementation slices

### MH-B1 — ISBN identity, schema 1014 and local-first

- **Goal:** shared parser/converter, equivalent ISBN state, claim/provenance
  schema, local-first service and race-safe claim port.
- **Components:** ISBN types/parser; Metadata contracts; table names,
  repositories, migrator/health/composition.
- **Schema:** 1013→1014 with section 8 tables; no rich columns/backfill.
- **Tests:** ISBN unit matrix, migration/persistence, legacy ambiguity,
  separate-process same/cross-Library claim races.
- **Dependency:** read-only duplicate report before migration approval.
- **Exit:** local exact makes zero provider calls; ambiguity is safe; one key
  cannot be claimed by two Editions.
- **Severity:** High.

### MH-B2 — Open Library adapter

- **Goal:** low-volume exact-ISBN adapter and neutral mapping.
- **Components:** HTTP/config/rate/cache ports, adapter, sanitized fixtures.
- **Schema:** none beyond B1.
- **Tests:** exact/miss/mismatch/multiple/malformed/429/5xx/timeout, explicit
  Work link evidence, contact and bounds.
- **Dependency:** contact configuration and current-guidance recheck.
- **Exit:** only normalized result/DTO crosses inward; bounded operation.
- **Severity:** Medium.

### MH-B3 — conditional Google fallback

- **Goal:** exact Google adapter plus replaceable fallback policy.
- **Components:** adapter/config, orchestration, Google cache and sanitized
  telemetry.
- **Schema:** none.
- **Tests:** missing key, exact/multiple/mismatch, quota/auth/429/5xx/timeout,
  fallback table and no leakage.
- **Dependency:** B2; key only for optional smoke; current display/terms review.
- **Exit:** Google disables/replaces without Core change; manual remains.
- **Severity:** High due to secret/quota/external-policy risk.

### MH-B4 — whole-record confirmation and provenance

- **Goal:** classification, signed evidence, explicit Work resolution and one
  transactional confirmation service.
- **Components:** classifier/differences/token; confirmation; catalog creation
  adaptation; claim/provenance repositories.
- **Schema:** use B1, no later migration.
- **Tests:** conflict/no-fusion, provenance states, atomic failure, concurrent
  reuse, no overwrite or heuristic merge.
- **Dependency:** B1–B3 and narrow write set.
- **Exit:** external writes have confirmation/provenance; manual/local remain
  provider-independent.
- **Severity:** High.

### MH-B5 — REST and review/manual UI

- **Goal:** lookup/confirmation endpoints and accessible review, conflict,
  retry, manual and no-ISBN flows.
- **Components:** parser/serializer/error/controller/Core accessors; biblio-ui
  state/view/E2E; no Elementor domain logic.
- **Schema:** none beyond B1.
- **Tests:** REST/security and UI/E2E matrix.
- **Dependencies:** B1–B4; truthful durable-field scope; display/cover licensing.
  If rich fields are required, their separate foundation precedes B5.
- **Exit:** no provider/auth logic in frontend; manual recovery always; one
  confirm creates at most one Edition and requested Item.
- **Severity:** High.

Do not combine B1 with networking. B2/B3 deserve separate review because Open
Library operations and Google secret/commercial risks differ. B4 must not write
before B1 integrity closes.

## 18. Risks and open blockers

| Risk/blocker | Required action |
|---|---|
| Existing duplicate ISBN rows | Block affected auto-claim; run read-only equivalence report, repair only in authorized data task |
| Rich fields lack persistence | If B5 promises rich save, first approve typed Edition metadata foundation; otherwise label narrow write honestly |
| No safe Author identity from names | Do not auto-create/reuse/merge Authors from provider names; future resolution design |
| No end-user manual/no-ISBN flow | B5 must ship it with lookup; providers cannot be sole add path |
| Google terms/key/quota | Keep conditional; prove disable/replace; recheck current rules |
| Cover rights/storage | Licensing review; absence cannot block add |
| Open Library traffic suitability | User-triggered, identified, cached, limited; revisit if traffic grows |
| Token/cache expiry | Revalidate; expiry offers retry/manual, never auto-repeats mutation |
| Provider drift | Fixtures plus opt-in smoke; no live CI |
| Work duplication/false merge | Prefer new Work when unresolved; never fuzzy auto-merge |

## 19. Independent review record

1. **Product Guardian:** local truth, review, manual/no-ISBN, conditional Google
   and optional covers remain intact.
2. **Domain Architecture:** ISBN stays Edition evidence; Work/Item stay separate;
   provider ports point inward.
3. **Metadata/Data Quality:** exact ISBN/equivalence mandatory; ambiguity blocks;
   no overwrite, fusion or blob.
4. **API/Integration:** current API cannot carry flow; two additive operations
   use existing auth/envelope and signed evidence.
5. **Security:** authorize before network; no key/raw payload; SSRF, bounds,
   rate and token controls explicit.
6. **Testability:** network/time/cache/backoff/config injected; fixture-only CI;
   MariaDB concurrency/failure coverage.
7. **Scope/versioning:** no runtime/schema change here; rich shortcuts, provider
   covers, Series hints, Wikidata, BookBrainz, Lens, OCR and Graph excluded.

Review verdict: **GO WITH CONDITIONS**. MH-B1 may be proposed after the
read-only duplicate report and explicit build approval. Full B5 remains
conditional on honest canonical field scope and provider display/licensing
gates.
