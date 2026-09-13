# 92 — D-SEARCH-AUTH-01 Author search presentation & disambiguation

Status: **DESIGN GO / IMPLEMENTED THROUGH SEARCH-AUTH-01B**

Date: 2026-09-13

Implementation progress: SEARCH-AUTH-01A is GO/CLOSED for ranking, current
mapped deduplication and source-progress paging. SEARCH-AUTH-01B is GO/CLOSED
for local canonical linked-Work context. SEARCH-AUTH-01C and SEARCH-AUTH-UI-01
remain pending bounded slices.

Task severity: **High** because the decision crosses Author identity, search
ranking, provider normalization, REST projection, pagination and ordinary-user
presentation. This slice changes documentation only.

## 1. Purpose, authority and verdict

D-SEARCH-AUTH-01 defines the final ordinary-user Author-search presentation
model on `/zoeken/`. It refines D-SEARCH-01 without changing its strong
identity, selector, read-only search or explicit-selection boundaries.

The verdict is **DESIGN GO**:

- users see one search for Authors, not separate local/provider products;
- canonical Authors always precede external candidates;
- exact presentation matches precede broader matches inside each source tier;
- proven provider-to-canonical identity suppresses the external duplicate;
- equal or similar names never merge identity;
- external discovery is bounded and progressively disclosed;
- every `Bekijk werken` action still selects exactly one server-issued
  `author_selector`; and
- technical provenance remains available to Core but is removed from ordinary
  Author rows.

SEARCH-UI-01A/F1/F2 and SEARCH-UI-01B are closed visual/interaction baselines.
This decision does not reopen their shell, navigation, drill-down, selector or
failure contracts. It changes no PHP, JavaScript, CSS, schema, runtime record or
provider request.

## 2. Non-negotiable product boundaries

1. `ranking normalization != identity resolution`.
2. A name, normalized name, contextual Work, date or provider rank is never
   Author identity or merge evidence.
3. Only canonical Author identity, same-provider Author identity or a proven
   provider-to-canonical mapping may collapse a search presentation duplicate.
4. Search remains read-only. Browsing an external Author or their Works does
   not materialize an Author.
5. `author_selector` remains the only action authority. `result_id`,
   `author_id`, names, context and provider data remain presentation.
6. Provisional/resolved and display-name governance states remain internal.
   Ordinary search adds no provisional or warning badge.
7. The UI remains provider-independent and exposes no provider ID, selector or
   provider-specific field name.

## 3. Design-time capability audit before implementation

| Audit question | Current technical truth | Design consequence |
|---|---|---|
| Local Author result | Strong canonical reference, display name and provider-neutral `local_canonical` kind | There is no disambiguation context yet |
| Local matching | All whitespace tokens must occur as case-insensitive substrings in `display_name`; accents, punctuation and initials are not folded | Relevance classification must not silently broaden matching |
| Local order | `display_name ASC, author_id ASC`, ten results plus continuation | Exact-name priority is not implemented |
| External Author result | One Open Library `/search/authors.json` page with strong Author key and name | Provider rank is available; richer fields are currently discarded |
| External request | `q`, `limit=10`, `offset`; no Author-detail or Works request | Context may use only fields already in this response |
| Shared ordering | `(local/external kind, presentation_order, strong result_id)` | Local first exists; exact/broader tiers do not |
| Page boundary | One independent Author cursor; application page size ten; local pages precede external continuation | Filtering or mapped suppression needs a continuation independent of the final visible item |
| Mapping | Schema 1024 and the provider-identity repository support Open Library Author → canonical Author claims | Top-level Author search does not currently consume the mapping |
| Internal fields dropped before REST | Typed canonical/provider reference, provider identity, strong identity keys and `presentation_order` stay internal; persisted identity/display-name status is not loaded by Search | None may be reconstructed or exposed casually by the browser |
| REST Author projection | `result_id`, `result_kind`, nullable `author_id`, `display_name`, `author_selector` | Every new context/relevance field is a strict additive contract change |
| UI | `Alles` previews four Author rows; `Auteurs` shows all loaded rows; each row shows name, `Biblio-catalogus`/`Externe bron` and `Bekijk werken` | Source labels currently carry the only secondary text |
| Local contextual data | Existing `work_contributors` and `works` tables can batch count and title Works for a page of Authors | No per-Author read is permitted |
| Runtime proof | One resolved canonical `Stephen King`, one linked Work `It`, and Open Library claim `/authors/OL19981A` | The real mapped-duplicate and one-Work presentation cases are representable without new data |
| Presentation-only versus contract work | Copy/source-label removal, visible caps and reveal of already loaded items are UI concerns | Match class, mapped dedup/composite issuance, context fields and filter-safe continuation require Core/shared/REST contract work |

The current service attempts external Author search even when a local page has
its own continuation, then discards that external page. The implementation
must stop that waste for the Author lane: external traversal begins only after
the canonical traversal is exhausted. If the final canonical page has spare
capacity, external discovery may start in that same application response.

## 4. Market comparison

The comparison is design evidence, not product authority or a parity claim.

| Product | Author identity model | Same-name handling | User-facing disambiguation | Lesson for Biblio |
|---|---|---|---|---|
| [Open Library](https://openlibrary.org/dev/docs/api/authors) | Stable Author key | Separate Author records may share a name | Author Search can return alternate names, birth date, top Work and Work count beside the key | Carry a small human context subset from the existing request; never expose the key as normal copy |
| [LibraryThing](https://www.librarything.com/librarything_author.php) | Managed Author entries with combine/split/disambiguation tooling | [Distinct people can be divided by Works](https://www.librarything.com/a/4191/multiple-authors) | Search/list examples use book count and `Author of …`; split pages retain separate people | Context is more useful than source labels; same-name people remain separate |
| [Goodreads](https://www.goodreads.com/topic/show/19851907-two-authors-same-name) | Separate Author profiles with librarian maintenance | [True duplicate profiles may be merged](https://www.goodreads.com/topic/show/16813944-merging-authors); namesakes remain separate | Works/profile context helps choose the intended person | Cleanup is governed work, not an ordinary-search side effect |
| [CLZ](https://club.clz.com/t/improved-managing-and-editing-of-pick-list-fields/3319) | Collector-managed Author pick-list entries | Users can explicitly merge duplicate Author entries in management tooling | Collection/pick-list context supports curation | Manual cleanup belongs to later governance, not the discovery journey |

## 5. Ordinary-user mental model

The page answers:

```text
Auteurs die passen bij “stephen king”
```

It does not ask whether the user wants internal Authors or provider Authors.
The source rail may continue to explain that Search covers the Biblio catalog
and connected bibliographic sources, but individual rows do not repeat that
infrastructure distinction.

An external-only result is a normal discovery candidate, not a warning or a
lower-quality class. A canonical result receives priority because Biblio
already knows that Author identity, not because the UI teaches the word
`canonical`.

## 6. Presentation-name normalization

Core computes one Author `match_quality` for presentation only.

`exact` compares query and display name after:

1. Unicode case folding; and
2. trimming and collapsing Unicode separator/whitespace characters to one
   space.

The following are explicitly **not** part of exact comparison:

- punctuation removal or normalization;
- diacritic folding;
- initial expansion/collapse;
- token reordering;
- honorific removal;
- alias or alternate-name matching; and
- fuzzy, phonetic or transliteration matching.

`Stephen King`, `STEPHEN KING` and `stephen   king` are exact presentation
matches. `Stephen D. King`, `Stephen King-Hall`, `Jose Saramago` versus `José
Saramago`, and `Flannery OConnor` versus `Flannery O'Connor` are not exact.
They may still be broader matches under the existing local/provider search
semantics.

The implementation uses the already required `ext-mbstring` runtime and
`mb_convert_case(..., MB_CASE_FOLD, 'UTF-8')` for deterministic Unicode case
folding. This decision deliberately does not add NFC/NFKC normalization or an
`ext-intl` dependency. Tests must pin the whitespace set and the examples
above so PHP, persistence and browser environments cannot drift.

No normalized value is stored as an Author key, claim, selector input or merge
candidate.

## 7. Author ranking model

The four authoritative presentation tiers are:

1. canonical Author + exact display-name match;
2. canonical Author + broader match;
3. unmapped external Author + exact display-name match;
4. unmapped external Author + broader match.

This confirms the proposed model. Canonical breadth takes priority over an
external exact match because the product has already decided that known Biblio
Authors precede discovery candidates.

Within a canonical tier, deterministic order preserves the bounded local
source contract:

```text
original display name, canonical Author ID
```

The application carries that source position through composition rather than
re-sorting it with a second Unicode collation. Unicode case/whitespace folding
still determines exact versus broader and `name_group_id`; it does not replace
the existing stable local tie-order.

There is no popularity, Work-count, recency, resolved/provisional or
Librarian-status score.

Within an external tier, existing provider relevance/order is retained, with
provider key and strong provider Author ID only as stable internal tie-breakers.
A future multi-provider composition must define its provider traversal order in
Core; the browser may not invent it.

`match_quality` and an opaque `name_group_id` are computed by Core. The latter
is a deterministic presentation-only hash of the normalized display name, so
case/whitespace variants can share a visual cluster without the browser
normalizing names. The frontend may compose only from these server-issued
values and the existing provider-neutral result kind; it may not reimplement
normalization or ranking. `name_group_id` is not entity identity, selection
authority, deduplication evidence or a DOM/accessibility label.

## 8. Strong mapped deduplication

When an external provider Author is strongly mapped to a canonical Author that
matches the current local query:

1. the canonical row is the only visible result;
2. the external duplicate is suppressed regardless of name casing;
3. the canonical result receives a composite selector only when exactly one
   supported current provider claim is safe under the existing AUTHOR-MAT-01
   rule; and
4. the existing typed group-level provider-attempt envelope remains available
   for failure handling, while provider identity/provenance stays absent from
   each ordinary result row.

Mapping lookup must be batch-based. No external candidate may cause a separate
mapping or Author read.

Composite authority is never trusted merely because its signature is valid.
Both selector issuance and every later composite consumption must re-read the
current provider-identity mapping and prove that the exact provider Author ID
still maps to the signed canonical Author ID. A missing, removed, changed or
ambiguous claim fails closed before any external Works request; it is never
downgraded to provider-only authority. This use-time invariant is application-
owned and matches the established selected-Work composite boundary.

If the mapped canonical display name does not satisfy the existing local query
predicate, this slice does not introduce alias-result presentation. The mapped
external row is omitted rather than shown as a second person or with a
provider-observed name presented as canonical. Alternate-name discovery is a
separate future decision.

Zero provider claims leaves a canonical-only selector. Multiple supported
claims for one canonical Author also remain canonical-only until governance;
ordinary Search never chooses a preferred claim.

## 9. Same-name identities and visual clustering

Different strong identities with the same normalized display name remain
separate rows. This includes:

- two canonical Authors;
- one canonical and one unrelated external Author; and
- multiple external provider Authors that differ only by casing.

When two or more loaded results share the same server-issued `name_group_id`,
the UI may introduce the neutral label `Mogelijke auteurs met deze naam`. It is
a visual cluster, not an aggregate entity. Each child row retains its own
context, `result_id`, selector and action.

If one canonical exact row is followed by unrelated external exact rows, copy
may read `Meer mogelijke auteurs met deze naam`. No card may say or imply that
the identities are duplicates, merged, variants of one person or ordered by
confidence.

If context cannot distinguish two visible rows, every indistinguishable row
also receives the visible neutral fragment `Mogelijkheid 2 van 3` inside its
single context line; the same ordinal is included in the action's accessible
name and repeated in the focused Author header when needed. The ordinal
replaces non-distinguishing context when necessary to preserve the two-fragment
maximum. It may change as a bounded cluster reveals more candidates, and it is
not a durable Author label or identity.

## 10. Local disambiguation projection

For every returned canonical Author page, Core may batch-project:

- `linked_work_count`: the count of distinct canonical Works currently linked
  through an `author|co_author` WorkContributor edge; and
- `representative_work_title`: set only when that count is exactly one, using
  that one canonical Work title.

Presentation is:

| Local state | Context line |
|---|---|
| zero linked Works | no context line |
| exactly one Work | `Auteur van It` |
| two or more Works | `{n} werken in de catalogus` |

The copy explicitly scopes the count to the Biblio catalog. It is not a
worldwide bibliography, popularity score or provider count. It is used only as
small disambiguation context and never affects ranking.

No arbitrary representative Work is chosen for Authors with multiple Works.
Alphabetically first, earliest contributor position, newest materialization or
provider popularity would all imply meaning Biblio does not have.

Identity status and display-name status are not projected. Provisional and
resolved Authors receive the same ordinary presentation.

## 11. External disambiguation projection

Open Library's existing Author Search response can provide `top_work`,
`birth_date`, `alternate_names` and `work_count` without an Author-detail call.
The v2.001 minimum is intentionally smaller:

- `representative_work_title`: nullable, bounded, derived from a valid
  `top_work` value;
- `birth_year`: nullable integer, derived only when `birth_date` contains one
  unambiguous four-digit Gregorian year from 1000 through the current calendar
  year.

UI examples are `Auteur van The Shining` and `Geboren 1947`. Absence of a death
date never renders `1947–`, because that would incorrectly imply that the
person is known to be living. If both fields exist, they may share one concise
line: `Geboren 1947 · Auteur van The Shining`.

External `work_count` is omitted from REST, UI and ranking. Its source semantics
are not equivalent to Biblio's linked canonical Work count. Alternate names are
neither displayed nor added to Biblio matching/ranking in this slice; a
provider may continue to use its own search index internally.

All context values are nullable and provider-neutral by meaning. Invalid,
missing or ambiguous source values become `null` in their fixed response key
without failing valid sibling Author results. No Author-detail,
Works-by-Author or other enrichment call is permitted during top-level search.

## 12. Provider-neutral Author result delta

The shared Author result gains this fixed strict shape:

```text
match_quality: exact | broader
name_group_id: opaque presentation grouping token
disambiguation:
  representative_work_title: string | null
  linked_work_count: non-negative integer | null
  birth_year: four-digit integer | null
```

`name_group_id` and the `disambiguation` object with its three exact keys are
always present. An unavailable context value is `null`; unknown keys and
omitted keys fail strict REST/UI decoding.

`name_group_id` uses `author-name-` plus a lowercase SHA-256 hex digest over
the presentation-normalized name. A representative title is trimmed valid
UTF-8 of 1–512 characters. `linked_work_count` is a non-negative JSON-safe
integer. `birth_year` follows the 1000-through-current-year boundary above.

Semantics are field-specific:

- `linked_work_count` is canonical Biblio relationship data and is null for
  external candidates;
- `birth_year` is a conservative provider observation and is null for local
  Authors until a future canonical Author-date model exists; and
- `representative_work_title` may be the only linked canonical Work or a
  provider's existing representative/top Work hint. It is context, never
  identity or popularity chosen by Biblio.

REST keeps its exact allowlist and adds only these fields. Raw Open Library
field names, provider IDs, identity status and provider payloads remain absent.

## 13. `Alles` presentation

Books remain first and visually primary. The Author preview remains compact
editorial rows and shows at most **three** Authors.

Selection rules over the currently loaded Author set are:

1. tier order is authoritative;
2. if a canonical exact match exists, show it first;
3. fill the remaining preview positions with further canonical matches before
   considering an unrelated external exact match;
4. do not show external broader matches while a canonical exact match exists;
5. without a canonical exact match, show up to three ordered candidates; and
6. show at most two external candidates with the same normalized exact name.

One canonical exact match therefore suppresses most external preview noise; if
capacity remains after canonical matches, at most one unrelated external exact
same-name possibility may fill it. A mapped provider duplicate never consumes
a preview slot.

Each row shows Author name, at most one concise disambiguation line and the
existing action. `Bekijk alle auteurs` switches to the `Auteurs` tab without a
new search.

## 14. `Auteurs` presentation

The tab uses the existing group title `Auteurs`. It does not introduce
`Auteurs in Biblio`, `Externe auteurs`, `Beste match` or provider sections.

Composition follows the four tiers. Optional presentation labels are based on
the user's ambiguity, not backend provenance:

- `Mogelijke auteurs met deze naam` for two or more exact same-name strong
  identities;
- `Meer mogelijke auteurs met deze naam` when a known first result is followed
  by further exact same-name identities; and
- `Andere auteurs` before broader external matches.

Canonical rows themselves need no section badge. When only external results
exist, the first exact external Author is presented as a normal result; it is
not wrapped in warning language.

The application Author result page remains bounded to ten items. The tab
initially shows all canonical Authors in the returned local page, followed—if
that page has capacity—by at most **five external candidates**. On a mixed
final-canonical page, Core asks the Author provider for exactly the remaining
application capacity, never an always-ten page that would then be truncated.
Of exact same-name external candidates, at most **three** are initially
visible. If more exact candidates are already loaded, broader candidates wait
behind them.

Every row contains at most:

1. Author name;
2. one context line with no more than two short fragments; and
3. `Bekijk werken`.

There is no portrait requirement and no Author image/cover provider.

## 15. Progressive disclosure and pagination

There is no infinite scroll and no displayed or hidden total.

`Meer auteurs` performs one of two operations:

1. reveal up to five already loaded but presentation-hidden external
   candidates in tier order; or
2. when no hidden loaded candidates remain, request the next Author page once
   and then reveal the next bounded batch.

It never refetches page 1. Exact same-name candidates are disclosed before
broader candidates. Existing rows keep their identity, content and relative
order inside their tier. A newly loaded exact candidate may be inserted in the
exact section above already visible broader candidates; no already loaded row
changes tier or provider-relative order. Focus moves to the first newly exposed
row and the live region announces the addition. If filtering yields no visible
row but continuation remains, focus stays on `Meer auteurs`, the button remains
available and the live region says `Nog geen nieuwe auteurs; er zijn meer
resultaten beschikbaar.`

Canonical traversal is exhausted before external traversal. The external
provider offset/order remains internal. Because strong mapped suppression can
consume source rows without producing visible rows, the Author cursor must no
longer derive continuation solely from the last visible item. Its next version
must bind:

- normalized query;
- exact `authors` group;
- traversal phase `local|external`;
- consumed source position/offset; and
- cursor version/signature.

It remains opaque to REST and UI. It may legitimately continue after a page
with zero new visible Authors. Presentation limits never discard continuation.
The Work cursor and Work pagination contract are unchanged.

This requires an Author-specific, versioned source-progress page contract: an
Author page may contain zero visible items and still carry a next cursor when
the provider consumed source records that were filtered or deduplicated. The
current shared invariant that a cursor requires a last visible item must be
split, not weakened for Works. Local and external Author provider ports return
their consumed next source offset independently of the visible result list;
only the Author composer signs that progress into the opaque cursor. One user
pagination request consumes at most one provider page. If that source page
yields no visible row, REST returns the truthful next cursor and the UI keeps
`Meer auteurs` available with a polite live-region update; it does not claim a
complete miss or silently loop through extra provider requests.

The Author-only provider port therefore accepts a bounded requested limit from
1 through 10. Core requests `10 - canonical_items_in_this_response` on a mixed
final-canonical page and ten on an external-only page. The provider's next
source offset advances by exactly the source records it returned. Because the
fetched page never exceeds remaining application capacity, every valid
unmapped row fits before presentation filtering and no backlog cursor is
needed.

## 16. Author → Works action

Every action targets one exact row and sends only that row's opaque selector.
A visual cluster has no cluster-level Works action.

Accessible names use reliable visible context when available:

```text
Bekijk werken van Stephen King, auteur van It
```

For indistinguishable same-name rows, append the current visual-cluster ordinal:

```text
Bekijk werken van Stephen King, mogelijkheid 2 van 3
```

The focused Author → Works view repeats the selected Author name and available
context, but not `Biblio-catalogus`, `Externe bron`, provider IDs or status
badges. Selector, result and provider identities remain outside DOM and
accessible copy.

## 17. Source-label decision

Remove `Biblio-catalogus` and `Externe bron` from ordinary Author rows and the
focused selected-Author header. Their place is taken by human context when
available.

The existing right rail remains the single ordinary scope explanation:

```text
Zoeken in
Biblio-catalogus
Inclusief aangesloten bibliografische bronnen.
```

A source distinction may appear only in a failure/status message needed to
explain partial availability, and then as generic copy such as `Andere bronnen
konden niet volledig worden doorzocht`. No provider name is needed.

## 18. Responsive and accessibility rules

At narrow width the order is always:

1. Author name;
2. one wrapping disambiguation line; and
3. full-width or naturally wrapping touch-safe action.

Source badges, dates, count, representative Work and action may not become five
competing columns. If two context fragments do not fit, birth year precedes
representative Work for external same-name disambiguation; local one-Work title
precedes count because both are never shown together.

Rows remain semantic list items with ordered headings, visible focus and native
buttons. Same-name controls require distinguishable accessible names. Cluster
labels are programmatically associated with their rows. Loading, reveal and
provider-failure changes use the existing polite live region; errors retain
alert semantics. At 390 CSS px and 200% reflow there is no truncation,
horizontal scrolling or inaccessible hidden context.

## 19. Failure and empty states

- **Canonical results + provider failure:** render the complete available
  canonical experience. A secondary rail status reports incomplete external
  search; never show `Geen resultaten`.
- **External results + partial provider failure:** retain those results and the
  same secondary status.
- **No results + provider failure:** show a search-incomplete state with retry,
  not a definitive empty state.
- **No canonical result + valid external results:** present normal Authors with
  context where available and no warning/source badge.
- **Complete valid miss:** only then show the existing no-results state.
- **Invalid context source value:** set its fixed response field to `null`; do
  not discard an otherwise valid strongly identified Author.
- **Invalid provider Author identity/name:** retain the current page-level
  typed provider failure; do not introduce record-level skip semantics in this
  slice. The existing group-level attempt state distinguishes that failure from
  a complete miss.

A provider page that contained candidates but yields zero visible rows after
mapped suppression remains a `candidates` attempt with a next cursor. It is not
reclassified as `miss` merely because presentation emitted no row.

## 20. Performance, request and caching boundaries

Local context uses a bounded batch query for the entire Author page. Provider
mapping and any canonical Author hydration use bounded batch reads. Forbidden
patterns are one Work count/title query per Author or one mapping query per
external candidate.

External context comes from the same `/search/authors.json` response. There is
no Author-details or Works fan-out. External search is not invoked while a
canonical page still has continuation that prevents any external item from
being returned.

No new cache architecture is introduced. Nullable external context follows the
existing typed response/DTO behavior. Search remains usable when provider
configuration or availability fails.

## 21. User-scenario decisions

| Scenario | Required outcome |
|---|---|
| A. canonical exact: Stephen King → It | Tier 1 row: `Stephen King` / `Auteur van It` / `Bekijk werken` |
| B. canonical + mapped OL19981A | One canonical row; external duplicate suppressed; safe single mapping may yield composite selector |
| C. canonical exact + unrelated external same-name person | Separate rows, canonical first; external row needs available context or same-name ordinal |
| D. no canonical, one exact external | Normal first Author result with optional birth/representative Work context |
| E. no canonical, many exact external identities | Separate rows under same-name ambiguity copy; first three external exact rows visible, more progressive |
| F. Anthony Stephen King / Stephen King-Hall | Broader external tier after exact candidates; provider order retained within that tier |
| G. provider unavailable + canonical local | Successful canonical result, secondary incomplete-source status |
| H. provider unavailable + no canonical | Incomplete-search state with retry, never definitive `Geen resultaten` |
| I. two canonical Authors with same name | Both tier 1, separate selectors, visible/contextual ordinal plus matching accessible names when otherwise indistinguishable; no merge |
| J. provisional and resolved same-name canonical Authors | Same ordinary presentation and canonical ordering; status remains internal |

## 22. Minimum contract delta

### A. Ranking/composition only

- add server-owned `exact|broader` presentation classification and opaque
  normalized-name grouping token;
- implement four-tier Author composition;
- retain deterministic canonical and provider order inside tiers; and
- replace the Author cursor with source-progress continuation safe under
  filtering and mapped suppression;
- add a bounded `1..10` limit to the Author-only provider port and request only
  remaining application capacity on a mixed final-canonical page.

### B. Local Author projection

- batch-project `linked_work_count` and the sole Work title when count is one;
- batch-project supported provider Author claims for selector composition and
  mapped suppression; and
- expose no Author governance state;
- retain these additions inside the typed application result until the final
  coordinated REST/UI cutover.

### C. External provider normalization

- accept bounded `top_work` as `representative_work_title`;
- parse only an unambiguous four-digit birth year;
- classify exact/broader without altering provider matching; and
- omit alternate names and provider Work count;
- retain these additions inside the typed application result until the final
  coordinated REST/UI cutover.

### D. Shared application contract

- extend `BibliographicAuthorSearchResult` with match quality, a
  presentation-only name-group token and fixed nullable typed disambiguation
  context;
- keep strong reference and `author_selector` authority unchanged; and
- make mapped suppression and traversal continuation application-owned.

### E. REST projection

- add only `match_quality`, `name_group_id` and the fixed disambiguation object
  to the strict Author item allowlist;
- retain the existing typed group-level `provider_attempts` envelope and its
  frontend failure state, while keeping per-result provider payload, identity
  and status private; and
- retain the existing group envelope and independent Author/Work cursors;
- release this exact response delta atomically with the matching strict
  frontend decoder and presentation, never in an earlier backend-only slice.

### F. Frontend presentation

- consume server-issued tier/context fields;
- reduce `Alles` Author preview to three;
- bound initial external visibility to five and exact same-name visibility to
  three in `Auteurs`;
- remove per-row/focused source labels; and
- preserve selector opacity, drill-down and retained Page state.

## 23. Recommended implementation slices

1. **SEARCH-AUTH-01A — ranking, traversal cursor and mapped dedup.** Add Core
   match quality, four-tier composition, batched Author mapping consumption,
   safe composite issuance plus mandatory composite use-time mapping
   revalidation, and the Author-specific source-progress page/cursor contract.
   Add its bounded provider `limit` and remaining-capacity composition rule.
   Keep new presentation fields internal and preserve the current REST Author
   shape. No context/UI.
2. **SEARCH-AUTH-01B — local Author context projection.** Add the batch
   linked-Work count/sole-title read to the typed application result. Preserve
   the current REST Author shape. No provider change or UI.
3. **SEARCH-AUTH-01C — external Author context normalization.** Carry bounded
   representative Work title and conservative birth year from the existing
   Open Library Author Search request into that typed application result.
   Preserve the current REST Author shape. No extra request or UI.
4. **SEARCH-AUTH-UI-01 — final Author presentation.** Implement the approved
   REST projection, strict decoder and `Alles`/`Auteurs` limits as one atomic
   compatibility cutover, plus ambiguity clusters, progressive reveal,
   source-label removal, accessible actions and responsive hierarchy over the
   completed backend contract.

The four slices are retained because cursor/mapping identity, local batch
projection, provider normalization and UI acceptance have different failure
and regression boundaries. Combining them would make one cross-layer
mega-slice.

Later Biblio Librarian merge/split/redirect, duplicate review, preferred claim,
Author detail, biography and broad Author enrichment remain separate governance
work.

## 24. Actual V1 data and scope rule

No current or historical V1 `/data/`, MIG-01 fixture source, DATA-01 record,
snapshot, count or export is needed or authorized. This design is
source-neutral.

No implementation, schema change, runtime data change, provider call, Author
merge, Librarian dashboard, Author detail page, biography enrichment, V1
migration, Series or Collections search is part of D-SEARCH-AUTH-01.

## 25. Design acceptance

Implementation may start only in the bounded slices above. A slice must stop if
it cannot preserve strong identity, opaque selector authority, independent
cursor honesty, mapped-claim freshness, batch performance, provider-neutral
REST or the no-extra-provider-request boundary.

This document resolves the ordinary mental model, ranking, exact matching,
same-name behavior, mapped deduplication, visible limits, progressive
disclosure, context semantics, source-label policy, accessibility, responsive
priority, failure handling, performance boundaries and implementation slicing.
No product decision required by those implementation slices remains open.
