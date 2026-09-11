# MH-DISC-01 — provider-neutral bibliographic discovery foundation

Status: **GO / CLOSED** after the recorded gates and independent review.

Post-closure note: WISH-DISC-01 now consumes this foundation in the personal
Wishlist UI without changing the MH-DISC-01 contracts; see `docs/70`.

## Scope and source rule

MH-DISC-01 adds backend Metadata Hub foundation. It does not add Wishlist UI,
Wishlist mutations, Library possession, V1 migration or a bibliographic editor.
Only current V2 code/runtime and deterministic synthetic/provider fixtures were
used. No MIG-01 snapshot, `.local/fixture-source`, DATA-01 copy, earlier
`/data/`, old count or old concrete V1 record was treated as current truth; no
current V1 `/data/` was needed.

## Approved product rules

1. One provider-neutral text query may return both Work and Edition candidates;
   an external Edition may be visible without ISBN, but durable materialization
   requires stable provider publication identity plus either a stable provider
   Work link, contributors or a canonical ISBN. A title alone is insufficient.
2. Open Library search does not make one provider-selected edition Biblio's
   winner. A bounded search loads up to three provider-ranked Works and up to
   four concrete Editions per Work, preserving each candidate.
3. Provider order is transient `presentation_order` only. It never means
   canonical confidence, selection, merge or recommendation.
4. Work identity is conservative and provider-scoped. A stable provider Work
   ID can create/reuse a provisional Work for that provider; there is no
   cross-provider or title/author fuzzy merge. Edition-only evidence may create
   a new provisional Work without matching an existing Work by text.
5. The client supplies one query string and no provider selector or title/author
   mode. Core classifies valid ISBN-10/13 server-side; every other valid query is
   normalized UTF-8 text for title/author/free bibliographic adapter search.

## Architecture

`BibliographicDiscoveryQuery` is the typed `isbn|text` boundary.
`BibliographicDiscoveryService` resolves the authenticated actor, classifies
input and searches canonical local identity first. An exact local canonical
Edition short-circuits ISBN discovery. Text discovery always continues through
conditional Open Library then Google Books, combines provider results after
local candidates and deduplicates only a proven provider mapping or canonical
ISBN against the same displayed local entity. Local text matching uses literal
normalized query tokens across Work title, Edition title and existing Author
display names; title similarity is neither fuzzy search nor an identity rule.

The result discriminator is one of `local_work`, `local_edition`,
`external_work_candidate` or `external_edition_candidate`. Every candidate
publishes `can_add_work_only` and `can_add_edition_specific`; clients do not
infer actions from missing fields. Multiple Editions remain separate and may
carry only source-supported title, contributors, language, publisher,
publication date, ISBN, format and page count.

Open Library search returns Work candidates and performs a bounded Editions
follow-up for each returned Work. Google Books returns Volume-shaped Edition
candidates. Both adapters map only allowlisted values and retain provider
position solely as presentation order. Normal miss, provider failure,
configuration failure and malformed response remain distinct typed states. A
failed or malformed Open Library Editions follow-up fails the provider attempt
closed and permits the normal fallback; it is never hidden as a complete
Work-only result. When external expansion fails, any local text results remain
usable and the provider attempts preserve that partial-failure evidence.

## Snapshots and REST

External results are stored in separate schema-1023 discovery snapshot tables.
The opaque `discovery_id`, exact hashed candidate JSON, opaque `candidate_id`,
actor, query type/identity, provider evidence, creation time and 30-minute
expiry are durable for review replay. A materialization read requires the same
actor and an unexpired row; expiry requires a new discovery and never triggers
a silent provider refetch. Replaying the same retrieval event is evidence-
idempotent and does not fabricate another provider observation. Local
candidates need no snapshot.

The authenticated contracts are:

- `POST /biblio/v1/me/bibliographic-discoveries` with exactly `query`;
- `POST /biblio/v1/me/bibliographic-discoveries/{discovery_id}/materializations`
  with exactly `candidate_id` and `intent` (`work_only|work_and_edition`).

Responses expose typed status/results, presentation data, capabilities and
allowlisted provider evidence. They never expose provider secrets, raw payloads,
DB rows, actor IDs or Library data.

## Materialization and evidence

`BibliographicMaterializationService` reconstructs the exact actor-scoped
snapshot candidate and runs one transaction. Core first requires the actor to
be the active Eigenaar of their designated personal Privébibliotheek; the
client supplies no Library ID and no implicit current Library is used. The
service rechecks provider identity and canonical ISBN claims before every reuse,
reuses a known Edition/Work only through strong identity, and otherwise creates
provisional Work plus optional Edition. Provider identity keys are scoped by
provider and source entity type. Unique DB claims plus a single safe retry make
the race loser reuse the winner.

The service never creates Item, inventory number, Location, Condition,
Acquisition, Collection membership, ReadingRound, circulation state,
LibraryCatalogContext, Library activity or Wishlist state. A later consumer
failure cannot roll back this already completed platform-bibliographic record;
a consumer must call materialization first and then perform its own separate
authorized mutation.

The existing field-review engine stores text-search evidence for Work or
Edition records. Provider observations remain proposals/supporting evidence;
they cannot overwrite user-confirmed or intentionally blank canonical fields.
CAT-T1 remains intact: concrete publication title evidence is Edition-owned;
new Work titles are provisional, never provider-confirmed. Contributors reuse
the existing atomic field proposal and create no new author ontology or fuzzy
merge.

## Schema and compatibility

Core schema advances linearly from `1022` to `1023`:

- `biblio_bibliographic_discoveries`;
- `biblio_bibliographic_discovery_candidates`;
- `biblio_bibliographic_provider_identities`;
- additive widening of field-evidence `queried_identifier` to bounded UTF-8
  text and allowlisting `text_search` / `text` beside exact ISBN evidence.

The migration is fresh-install-safe, retry-safe and partial-shape fail-closed.
Existing Library/ISBN-bound Add Book snapshot tables and their rows are not
changed. Add Book remains the stricter Library-authorized ISBN/Item consumer,
using its existing endpoints, snapshot, commit and manual/no-ISBN contracts.

Versions at closure: Biblio Core `2.5.0`, schema `1023`, Biblio UI `0.14.0`.

## Verification

Deterministic tests cover typed query classification, combined local
title/author search, ISBN-only local short-circuiting, local-first text breadth
before and after materialization/Wishlist membership, strong-identity-only
deduplication, provider fallback/status with retained local results,
Open Library Work plus multiple Editions, Google Volume candidates,
ISBN-less capability rules, actor isolation, expiry, exact replay, Work-only
and Work+Edition materialization, provider evidence, no Item/Library/Wishlist
side effects, duplicate replay, same provider Work/Edition races and canonical
ISBN race reuse. The full existing Core, Add Book, Metadata Hub, Wishlist,
schema, REST and WordPress gates remain required for the recorded GO verdict.

## Wishlist handoff and completion

WISH-DISC-01 now consumes generic discovery results, asks the user to choose a
capability/intent, materializes the selected candidate, and then calls the
existing Wishlist API with the returned canonical Work and optional Edition
IDs. It must not infer a winner, merge candidates, reuse an expired snapshot,
or embed provider logic. Wishlist grouping, recommendation and fuzzy search
remain outside MH-DISC-01 and WISH-DISC-01.
