# 73 — D-SEARCH-01 Shared Bibliographic Search | Contract & UX model

Status: **DECISION CANONICALIZED / AUTHOR-WORK + WORKS-BY-AUTHOR + LAZY EDITION APPLICATION CONTRACTS IMPLEMENTED**

Date: 2026-09-11

Task severity: **High** because this decision crosses shared bibliographic
identity, provider capabilities, consumer UX and performance boundaries. This
slice changes documentation only: no production code, REST, schema, provider,
Wishlist or Add Book behavior is changed.

## 1. Purpose and authority

D-SEARCH-01 defines one reusable bibliographic discovery model for Wishlist,
Add Book, later Gewenste aanwinsten and other future consumers. Consumers own
only what happens after an explicit selection; they do not own provider search,
ranking, deduplication or bibliographic identity rules.

The current Work → Edition → Item model, canonical ISBN rules, Metadata Hub
evidence governance, provisional catalog rules and server-side authorization
remain authoritative. D-SEARCH-01 adds no current V1 dependency and uses no V1
snapshot, count or record.

MH-SEARCH-01A and MH-SEARCH-01B implement the parallel application-level text
boundary through local canonical plus Open Library Author/Work search.
MH-AUTHOR-01 implements pageable canonical/Open Library Works for exactly one
selected strong Author reference.
MH-EDITION-01 implements lazy pageable local/Open Library Editions for exactly
one selected Work. REST/UI reachability, Google Edition leads and consumer
cutover remain separate; therefore this document's complete progressive target
is only partially implemented.

## 2. Search principles

1. Biblio has one shared bibliographic discovery foundation, not separate
   Wishlist, Add Book or acquisition search engines.
2. Default discovery uses one labelled field: `Zoek op titel, auteur of ISBN`.
3. Core recognizes a checksum-valid ISBN server-side. Every other valid value
   is one general bibliographic text query; Biblio does not classify it as a
   title query or author query.
4. Text discovery is Author- and Work-first. Concrete Editions are discovered
   lazily after an explicit Work selection.
5. ISBN is the deliberate exception: it expresses concrete Edition intent and
   therefore goes directly to Edition discovery.
6. Provider identity, provider grouping and provider rank are infrastructure,
   not the primary user-facing model.
7. Every selection is explicit. Ranking never selects, confirms, merges or
   materializes an entity.

Work-first text discovery reduces result noise, respects bibliographic
hierarchy, prevents initial Edition fan-out and shortens the critical latency
path. It also keeps multiple Editions out of the first result page until a user
actually needs them. Work-first never hides Authors: Author is a first-class
discovery result type with its own continuation.

## 3. Default one-field search

The default screen shows one primary input and no preceding type selector.

```text
Zoek op titel, auteur of ISBN
```

Core returns the classified `query_type` as `isbn` or `text`. A query such as
`Stephen King` or `Rowling` may yield both strong Author results and relevant
Work results. The application never needs to decide that the input "was an
author query". A malformed or checksum-invalid ISBN-looking value is not
silently repaired into an ISBN: it is an ordinary general text query unless the
general text validation itself fails.

## 4. Optional advanced search

`Geavanceerd zoeken` is an optional, visually secondary layer. It does not
replace or dominate the one-field default and this decision does not implement
it.

The semantic split is:

| Field | Work discovery | Edition discovery | Preferred role |
|---|---|---|---|
| Titel | yes | only within a selected Work or explicit Edition search | field-specific query when needed |
| Auteur | yes | inherited context is display-only; Edition-specific contributors remain Edition data | field-specific Work query |
| ISBN | no | yes | direct identifier input, still server-classified |
| Taal | preference signal only | yes | Edition result filter, default `Alle talen` |
| Publicatiejaar | no | yes | Edition result filter/range rather than required starting input |
| Uitgever | no | yes | Edition result filter rather than required starting input |

Series can later be a Work-level filter only when reliable Series identity and
the consumer scope justify it; it is not added to the approved advanced-search
minimum by this decision. There is no hard Work-language filter: a Work may
have Editions in multiple languages and the current persisted Work model has no
single main-language property.

## 5. Text discovery

Conceptually:

```text
text query
  -> Author result page + Work result page
  -> explicit Author or Work selection
  -> Author: pageable Works by that Author
  -> Work: use Work, or discover pageable Editions for exactly that Work
```

The first screen groups entity types visibly:

```text
Auteurs
[Author results]

Boeken
[Work results]
```

Authors, Works and Editions are never mixed into one visually
indistinguishable list. Results are not grouped as Open Library, Google Books
or another provider.

### Author results

An Author result requires strong Author identity: a canonical Author ID, a
stable provider Author ID, or a proven provider-to-canonical mapping. A name
string alone may support a Work result but does not become a durable Author
entity.

Minimum presentation is display name plus only reliable distinguishing context
that a later contract explicitly allowlists. The primary continuation is
`Bekijk werken` or a semantic equivalent. It opens a separately pageable list
of Works by that selected Author and retains the visible fact that the user
selected an Author match. Biblio does not silently replace a strong Author
match with an arbitrary first ten Works.

### Work results

A Work result represents abstract content. It shows at minimum:

- Work title;
- ordered Author(s);
- reliable Series context when available.

It does not present ISBN, publisher, binding, publication date/year or other
Edition metadata as Work properties. A consumer may either use the Work-level
identity directly or ask the shared discovery foundation for its Editions.

## 6. Work to Edition drill-down

Edition discovery starts only after a user explicitly chooses one Work, except
for a valid ISBN. It queries local canonical Editions first and may extend with
provider results through provider-neutral capability ports.

Each Edition remains a separate result. Presentation may include, only when
available:

- concrete Edition title and subtitle;
- language;
- publisher/imprint;
- precision-preserving publication date/year;
- canonical ISBN;
- binding/physical format;
- Edition-specific contributors;
- other already allowlisted concrete publication fields.

Missing metadata stays missing. No best Edition, default Edition or automatic
winner is inferred. `Alle talen` is the default language-filter state; a real
Edition-language filter may later narrow this result page.

## 7. ISBN flow

A valid ISBN expresses concrete publication intent:

```text
ISBN
  -> exact local canonical Edition when present
  -> otherwise external Edition lookup
  -> one or more concrete Edition candidates
  -> explicit Edition selection where ambiguous
```

There is no mandatory intermediate Work list. Reliable Work context may be
shown. ISBN remains Edition identity and never identifies a physical Item.
Exact local reuse, ambiguity, provider failure, snapshot review and strong
identity rules remain governed by the existing Metadata Hub and Add Book
contracts.

## 8. Google Books Edition leads

Google Books exposes stable Volume records but no provider capability
equivalent to Biblio's Author and Work entities. A Volume therefore cannot be
presented as a reliable Work merely because its title and author resemble one.

A strong Google Volume stays out of the initial Author/Work result set. Only
after the user explicitly activates a secondary action such as `Zoek ook losse
uitgaven` may it enter a separately labelled `Edition lead` group. The UI must
label it as a concrete possible publication without a confirmed Work relation;
it is never placed under `Boeken` as though it were a Work. An Edition lead:

- requires stable provider publication identity and the existing safe
  Edition-materialization evidence;
- supports only concrete-Edition continuation;
- does not offer Work-only intent unless a reliable underlying Work identity
  is independently known;
- never clusters with title/author lookalikes or claims provider Work identity;
- may create a new provisional Biblio Work as the structural parent only after
  the user explicitly selects the Edition, without merging it to an existing
  Work by similarity.

This is a bounded compatibility path, not an exception to Work-first ranking.
Consumers may omit Edition leads when their flow cannot use concrete Edition
intent safely.

## 9. Pagination

Author results, Work results, Works-by-Author and Editions-by-Work each have an
independent page boundary. An initial page may contain ten results, but ten is
never represented as the complete set when continuation exists.

Conceptually every page contains `items` plus an opaque continuation or an
explicit `has_more`/next-page capability. A total is optional and must not be
fabricated. User-facing continuations may read `Meer resultaten`, `Meer
werken` and `Meer uitgaven`. Exact cursor format, limits, expiry and transport
belong to the implementation slice and must follow existing query conventions.

## 10. Ranking and deduplication

Local canonical results may appear predictably before external results. Within
one entity group, provider relevance may affect presentation order. It does
not produce confidence, canonical status, a merge candidate or an automatic
selection. Provider result number one is never Biblio's winner.

Deduplication is allowed only on strong identity:

- canonical Author, Work or Edition ID;
- canonical ISBN for the same concrete Edition;
- stable same-provider entity ID;
- proven provider-to-canonical identity.

Title-only, author-plus-title, similarity score, provider rank and language
preference are never deduplication or merge evidence. Ambiguous results may
remain side by side.

## 11. Language semantics

Language has two separate roles:

- during Work discovery it is a user preference/relevance signal. It neither
  becomes a single Work property nor excludes Works without matching Edition
  evidence;
- during Edition discovery it is concrete Edition metadata and may be an
  explicit filter. The default is `Alle talen`.

Future user-language preferences may influence ranking and presentation, but
cannot become identity or a hidden hard filter.

## 12. Provider-neutral capabilities

The future application boundary models capabilities rather than pretending
every provider supports the same entities:

- `search_authors`;
- `search_works`;
- `list_works_by_author`;
- `discover_editions_for_work`;
- `lookup_edition_by_isbn`;
- optional `search_edition_leads`.

Open Library currently maps naturally to Author search, Works-by-Author, Work
search, Editions-by-Work and Edition/ISBN lookup. Its official APIs expose
stable Author, Work and Edition identifiers and pagination. Google Books maps
naturally to ISBN lookup and optional Volume-shaped Edition leads, not to
reliable Author or Work entities.

Provider adapters may implement only a subset. Application contracts stay
typed and provider-neutral; no fictive Work or Author identity is created to
make capability matrices symmetrical.

Capability evidence was rechecked on 2026-09-11 against the official Open
Library [Search API](https://openlibrary.org/dev/docs/api/search),
[Authors API](https://openlibrary.org/dev/docs/api/authors) and
[RESTful Work/Edition endpoints](https://openlibrary.org/dev/docs/restful_api),
and the official Google Books
[Volumes list](https://developers.google.com/books/docs/v1/reference/volumes/list)
documentation. Those external APIs remain adapter evidence rather than Biblio
contract authority.

## 13. Conceptual shared contract

The contract below is a product/application shape, not a REST schema:

```text
SearchRequest
  query: string

SearchResult
  query: string
  query_type: isbn | text
  state: loading | partial | results | no_results | failure

TextSearchResult
  authors: Page<AuthorResult>
  works: Page<WorkResult>

AuthorResult
  author_identity
  display_name
  distinguishing_context?
  action: list_works

WorkResult
  work_identity
  title
  authors[]
  series_context?
  actions: use_work | discover_editions

AuthorWorksRequest
  selected_author_identity
  continuation?

EditionDiscoveryRequest
  selected_work_identity
  continuation?
  language_filter?: all | language_code

EditionResult
  edition_identity_or_candidate
  reliable_work_context?
  concrete_publication_fields
  action: use_edition

IsbnSearchResult
  editions: Page<EditionResult>
  reliable_work_context?

EditionLeadSearchRequest
  initiated_by: explicit_user_action
  query: string
  continuation?

EditionLeadSearchResult
  edition_leads: Page<EditionLeadResult>
  allowed_intent: use_edition
```

Identity and available actions are explicit typed fields. Clients do not infer
them from labels, missing metadata or provider shape. Materialization and each
consumer mutation remain separately authorized application operations unless a
later accepted contract explicitly changes that boundary.

D-AUTHOR-REF-01 makes the selected-Author handoff concrete at REST: every
top-level Author result includes one opaque signed `author_selector`. It
represents exactly a canonical Author, an Open Library provider Author, or a
canonical Author with server-proven Open Library evidence. The client never
constructs or combines those identities, and `result_id` remains presentation
identity rather than selection authority. MH-AUTHOR-API-01 accepts only this
selector.

D-WORK-REF-01 supplies the corresponding selected-Work authority on every Work
from top-level search and selected-Author Works: one opaque signed
`work_selector`, issued directly from the typed Work reference. Canonical,
Open Library provider and trusted composite forms remain distinct. A composite
is accepted only while its exact current provider-to-canonical Work mapping is
still present; no display/result field can reconstruct this authority. The
separate MH-EDITION-API-01 must accept only this selector.

## 14. Consumer behavior

### Wishlist

After a Work selection the user chooses `Uitgave maakt niet uit` or `Kies
specifieke uitgave`. The second choice starts lazy Edition discovery and yields
an Edition-specific Wishlist entry after explicit Edition selection.

ISBN goes directly to concrete Edition intent. `Uitgave maakt niet uit` may
also be offered only when reliable Work identity is known. An unlinked Edition
lead supports Edition-specific intent only. D-SEARCH-01 changes no Wishlist
persistence, cardinality, refinement or authorization rule.

### Add Book

ISBN continues directly to the existing Edition review path. Text discovery
normally proceeds through Author/Work selection and lazy Edition discovery;
Add Book must finish at a concrete Edition because every physical Item belongs
to one Edition. A safely usable Edition lead may enter the concrete Edition
review path without pretending to be a Work. The existing Item creation,
Library Context, manual/no-ISBN, evidence and transaction contracts remain
unchanged.

### Future consumers

Gewenste aanwinsten and other consumers reuse the same search, entity and
continuation contracts. They define only allowed post-selection intents and
their own authorization/persistence behavior. Library-owned Gewenste
aanwinsten never inherits personal Wishlist ownership.

## 15. Full-page progressive UX

Rich discovery uses its own screen inside the Biblio App Shell. It is not
confined to the current narrow Wishlist dialog. The screen supports:

```text
search
  -> grouped results
  -> Author and pageable Works, or Work
  -> optional pageable Editions
  -> consumer-specific continuation
```

This is a progressive guided flow, not a rigid wizard that always requires
Next/Back. Selection changes the relevant context and exposes the next useful
choice. Browser/back behavior, focus restoration and URL state are specified
in SEARCH-UI-01. Dialogs remain appropriate for small confirmation, conflict
and refinement actions.

## 16. Loading and performance

The UI gives immediate, textual loading feedback. Local results may become
usable before external expansion completes; the screen must make continued
search visible, for example `Boeken zoeken…`, `Ook buiten Biblio zoeken…` or
`Uitgaven ophalen…`. A provider failure cannot remove already available local
results or turn them into a false empty state.

The critical performance path is:

```text
Author/Work discovery
  -> explicit Author-to-Works or direct Work selection
  -> Edition discovery for exactly one selected Work
```

Text search never performs an initial Edition fan-out over multiple Works.
Transport may deliver staged states through separate requests or another
explicit implementation contract; the UI must not pretend a request is
complete while external expansion is still active. SEARCH-PERF-01 is justified
only after this structural latency reduction is implemented and measured.

## 17. Current implementation delta

The current MH-DISC-01 production route remains valid for its closed slice, but
does not consume the parallel search contracts yet:

- local text discovery emits Work and Edition candidates in one fixed, flat
  result set;
- Open Library searches at most three Works and eagerly requests up to four
  Editions for every returned Work;
- Google Books returns at most ten Volume-shaped Edition candidates;
- text orchestration stops after the first provider with candidates;
- its response has no Author result type or independent Author/Work/Edition
  pagination;
- the Wishlist consumer renders the flat result set in a modal with immediate
  Work-only/Edition-specific actions;
- current Wishlist, Add Book and `/me/works` clients use exact response-field
  allowlists, so an additive JSON change can still be breaking;
- a current Google Volume with contributors can advertise Work-only
  materialization even though it has no provider Work identity; the new model
  must remove that capability from the Edition-lead lane.

Later slices must replace these behaviors deliberately and preserve MH-DISC-01
snapshot, materialization, evidence, authorization and strong-identity rules.
The existing `/me/works` route remains a separate compatible current Work-only
consumer contract until migration is explicitly implemented.

## 18. Recommended bounded implementation slices

1. **MH-SEARCH-01A/01B — shared paged Author/Work search:** implemented as a
   parallel contract with local canonical and Open Library results, independent
   continuations, strong identity and no consumer changes.
2. **MH-AUTHOR-01 — Works by selected Author:** implemented as a parallel
   pageable local/Open Library boundary from the retained strong Author
   reference.
3. **MH-EDITION-01 — lazy Editions-by-Work:** implemented as the selected-Work
   Edition contract without initial Edition fan-out.
4. **MH-GBOOK-01 — Volume Edition leads:** isolate the secondary Google Volume
   path and prove that it never claims Work identity or Work-only capability.
5. **WISH-DISC-F2 — external discovery runtime reliability:** harden and
   measure external staged loading, partial failure and retry independently of
   the UX migration; do not expand product semantics in this slice.
6. **SEARCH-UI-01 — shared full-page discovery:** build the App Shell screen,
   grouped entity results, progressive navigation, loading, pagination,
   accessibility and responsive behavior over the completed shared contracts.
7. **WISH-FLOW-01 — Wishlist consumer migration:** connect Work-only and lazy
   Edition-specific intent to the shared screen without changing Wishlist
   persistence.
8. **ADD-SEARCH-01 — Add Book consumer integration:** reuse the shared
   Author/Work/Edition selection before the existing Edition/Item flow while
   preserving the fast ISBN and manual paths.
9. **SEARCH-PERF-01 — measured optimization only:** start only when production-
   like measurements after lazy loading show a remaining problem.

Gewenste-aanwinsten integration follows later as its own consumer slice. None
of these slices should combine shared search, multiple provider rewrites, both
consumer migrations and performance work.

## 19. Open decisions

No product decision blocks the shared model. Exact page sizes, cursor format,
URL state, staged transport, Edition-lead disclosure copy and which reliable
Author distinguishing fields are allowlisted belong to their bounded
implementation/design slices. They may not weaken the identity, grouping,
language or no-fan-out rules above.

## 20. Outside scope and verdict

D-SEARCH-01 itself added no runtime. MH-SEARCH-01B, MH-AUTHOR-01 and
MH-EDITION-01 now supply bounded local/Open Library Author/Work search,
Works-by-Author and lazy Editions-by-Work application boundaries without REST,
schema, frontend, filter, advanced search, fuzzy search, index,
Elasticsearch/OpenSearch, ML ranking, recommendation, Wishlist redesign, Add
Book redesign or global app search.

Verdict: **GO**. The shared one-field, Author/Work-first, ISBN-to-Edition,
lazy-Edition model is coherent with Biblio's hierarchy, provider abstraction,
consumer boundaries and performance goals. The Author/Work search,
Works-by-Author and lazy Edition application paths are implemented; remaining
behavior stays in the bounded follow-up slices above.
