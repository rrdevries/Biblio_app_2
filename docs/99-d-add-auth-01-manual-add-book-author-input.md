# D-ADD-AUTH-01 — Manual Add Book Author input

Status: **DESIGN GO / IMPLEMENTED THROUGH ADD-AUTH-01B; HUMAN ACCEPTANCE PENDING**

Date: 2026-09-14

Task severity: **High**. This is a product, UX and identity-sensitive domain
design. The slice changes documentation only; it adds no production code,
schema, runtime data or provider behavior.

## 1. Problem statement

Provider-backed Add Book already preserves typed Work-Author evidence and
materializes canonical Authors through the shared
`CanonicalAuthorMaterializer`. Manual Add Book has only the free-text
`contributors` observation. That value is Edition-level, untyped evidence and
cannot safely create an Author or `WorkContributor` edge.

The missing capability is a natural optional input for one or more Authors
when manual Add Book really creates a new Work. It must create the existing
provisional Author identity, preserve entered order and remain separate from
other Edition contributors, without name matching or a second identity model.

This design accepts the proposed repeatable-row UX with one correction:
ordinary manual rows all assert the existing canonical role `author`.
Position 2 and later are not converted to `co_author`, because current 01D/01E
semantics never infer a primary/co-author distinction from array order.

## 2. Current-state audit

The current technical paths are:

- `AddBookCommitRequest` contains identifier, selection, Edition-level
  observations, classification and Item input. It has no typed Authors.
- The strict REST body accepts `identifier`, `selection`, `observed_fields`,
  `classification` and `item`. `observed_fields.contributors` is an ordered
  list of Edition/user-observed evidence.
- Manual selection either omits `work_id`, creating a new provisional Work and
  Edition, or explicitly names an existing Work, creating only a new Edition
  and Item beneath it.
- Existing-Edition selection and local ISBN resolution create only a new Item.
  A late canonical-ISBN race can also turn an apparent new-Work attempt into
  reuse of the winning existing Edition and its Work.
- `AddBookCommitService` preallocates Work, Edition and Item IDs once and reuses
  them for its single complete-operation retry.
- `AddBookCommitEvidenceWriter` is already the transaction participant. It
  receives the definitive Work/Edition/Item and invokes the shared Author
  materializer for reviewed provider candidates only.
- `CanonicalAuthorMaterializer` owns strong and name-only Author identity,
  exact credit replay, provisional creation, evidence, ordered
  `WorkContributor` edges and typed race behavior. It joins the caller-owned
  transaction and performs no network request.
- Schema 1024 already supports `user_observation` Author-credit evidence and a
  Core-derived user-observation source identity. Names are non-unique.
- `WorkContributor` supports only `author|co_author` plus a positive ordered
  position. Current Open Library and Google author arrays use `author` for
  every row; `co_author` is used only when a source explicitly proves it.
- The manual wizard has one combined Edition form, optional existing-Work
  search, durable in-memory draft state across Back/Forward, and current
  Guided Flow form, focus, live-region and responsive conventions. It has no
  repeatable-row or reorder component today.
- Existing Work discovery already returns ordered canonical Authors, so a
  selected Work can show them read-only without a new endpoint. Local existing
  Edition cards already receive ordered canonical Authors.

The external Current Phase snapshot was last reviewed before the closed
AUTHOR-MAT-01A–01E sequence. Current Git, `docs/00-current-state.md` and the
01A–01E closure documents are the later authority. They confirm product
`v2.001`, schema `1024`, Biblio Core `2.24.0` and Biblio UI `0.18.1`.

## 3. Product principles and four-perspective review

- Ordinary users see `Auteur` and `Auteurs`, never provisional/resolved,
  claims, credits, provider identity or contributor-edge terms.
- Author input is optional. Empty means unknown, unavailable or anonymous; it
  never creates `Onbekend`, `Anoniem` or another placeholder identity.
- Typed Authors describe the Work. Translators, illustrators, editors and
  compilers remain separate Edition observations in this slice.
- Shared Work metadata is not owned by the current Library. Add Book may create
  the initial graph for a new Work, but may not correct an existing Work.
- Manual names are observations, never global identity keys. Name equality
  causes no lookup, reuse, merge, promotion or warning queue.
- Author creation occurs only in the final Add Book transaction. Editing the
  form never creates catalog state.

| Perspective | Assessment | Result in this design |
|---|---|---|
| Product | Repeatable optional names are familiar and keep manual fallback useful. | Show a simple ordered `Auteur(s)` group only for a new Work. |
| UX | Users need manageable multiple names and a clear distinction from other contributors. | Use rows, add/remove and keyboard-operable up/down controls; label the free-text field `Overige bijdragers`. |
| Metadata | Authors are Work-level, order matters and equal names do not establish identity. | Preserve final row order, create evidence-scoped provisional Authors and never match by name. |
| Engineering | The change should reuse 01C/01E, remain additive and preserve rollback/retry. | Add a user-observation credit adapter to the shared materializer and run it in the existing participant; schema 1024 is sufficient. |

The only material tension is the proposed first=`author`, later=`co_author`
rule. It is intuitive if the role meant “primary versus additional”, but 01D
and 01E explicitly do not derive that meaning from order. This design resolves
the conflict in favor of current canonical metadata semantics: every ordinary
manual Author row uses `author`; position alone preserves presentation order.
A future source or governance flow may supply `co_author` only when it actually
has that typed evidence.

## 4. New Work UX

Typed Author input appears only while the manual flow has no selected existing
Work and therefore intends to create a new Work. The manual form keeps the
existing wizard and uses whitespace grouping, not another step or modal.

Preferred visible hierarchy:

```text
Over het werk
  Titel
  Auteur(s) (optioneel)
    [ George C. Clark Jr. ] [omhoog] [omlaag] [verwijder]
    [ J. Bibb Cain        ] [omhoog] [omlaag] [verwijder]
    + Auteur toevoegen

Over de uitgave
  ISBN, ondertitel, taal, uitgever, publicatiedatum, druk,
  bindwijze, pagina's en Overige bijdragers (optioneel)

Exemplaar
  blijft de bestaande volgende wizardstap
```

`Titel` is the existing manual title input. Its placement and shorter label do
not change CAT-T1 authority: Core still treats it as the concrete Edition title
and only as the seed of the new provisional Work title. It does not become a
librarian-confirmed Work title.

The group initially shows one empty row for discoverability. The user may
remove it and commit zero Authors. A new row is inserted after the current last
row and receives focus. Filled rows are submitted in their visible order.

## 5. Existing Work UX

After deliberate `Koppel aan bestaand werk` selection:

- remove the editable Author rows from the active form;
- show a compact read-only Work context with title and the ordered canonical
  Authors already returned by Work discovery, when present;
- add the short clarification `Auteurs van dit werk worden hier niet
  aangepast.`; and
- submit no manual Authors.

If the selected Work has zero Authors, show no placeholder and do not expose an
exceptional Author editor. The user can still add the Edition and Item.
Correction of missing or wrong shared Authors belongs to the separate Biblio
Librarian workflow.

The UI retains the user's unsubmitted Author-row draft while an existing Work
is selected. Removing the Work link restores that draft. Retention is only
form convenience: hidden rows never cross the request boundary and never
mutate the selected Work.

The server independently enforces the same boundary. Non-empty manual Authors
are valid only for `selection.type=manual` without `work_id`. A client cannot
turn the hidden UI into permission to rewrite an existing Work.

## 6. Existing Edition UX

Existing-Edition and local-ambiguous selection show canonical Authors only as
existing read-only recognition context when available. No manual Author input
appears in the normal Item path or the existing-Edition correction path.

Adding the Item must not create, replace, reorder or delete Author relations.
This also applies when an apparent manual new-Work request resolves local-first
or loses an ISBN race to an existing Edition: the definitive existing Work
wins and all manual Author inputs are ignored by the transaction participant.
The Item addition remains valid; turning the observed names into a correction
proposal is deferred rather than inferred here.

## 7. Multiple Author behavior

- Zero, one or at most 32 non-empty rows are accepted. The bound matches the
  existing typed Author-credit/candidate bound and is not a person-name rule.
- Visible row order is authoritative. Core derives contiguous one-based
  positions after empty rows are removed. Authors are never alphabetized.
- `Auteur toevoegen` appends one empty row. `Verwijder` removes exactly that
  row. Focus moves to the next row, otherwise the previous row, otherwise the
  add action.
- Reordering uses explicit up/down buttons. First-row Up and last-row Down are
  disabled. Dragging may not be the only interaction and is unnecessary for
  v2.001.
- Reordering moves the row and its stable local UI key together, retains focus
  on the moved row's control and announces the new position.
- Empty/whitespace-only rows are not Authors. They are dropped before final
  validation, and remaining rows are compacted without gaps.
- Equal conservatively normalized names within one Work remain separate rows,
  credits, provisional Authors and edges. The UI shows a non-blocking warning
  so an accidental duplicate is easier to catch, but never removes or merges a
  row.

## 8. Work Author versus Edition contributor distinction

`Auteur(s)` is a structured Work-level input. Each accepted row can create one
provisional Author plus one ordered Work relation.

The current `contributors` field remains the Edition-level, untyped,
user-observed list. In the manual new-Work form its visible label becomes
`Overige bijdragers (optioneel)` with examples such as translator,
illustrator, editor and compiler. It remains the same additive backend field;
no contributor string is parsed, migrated or promoted to Author.

Provider-backed review is not redesigned. Its private typed Author evidence
continues through AUTHOR-MAT-01E, and it receives no redundant editable Author
rows. Existing candidate-adjust behavior for contributor observations remains
unchanged by this slice.

## 9. Identity semantics

Each manually typed name is exact name-only user-observation evidence. It
creates a provisional Author with an observed display name through the shared
01C materialization algorithm. It creates no provider claim and performs no
Open Library, Google Books, Wikidata, BookBrainz or other person lookup.

Normalization scopes the credit but does not normalize person identity. There
is no query over existing Authors by display name, no autocomplete, no global
mapping and no fuzzy or case-insensitive reuse. The same name on another Work,
or from another independent observation, remains an independent Author unless
later strong evidence or explicit Librarian governance safely resolves it.

The ordinary user never supplies `author_id`, identity status, provider ID,
claim, selector or reconciliation decision. Manual creation is always
`provisional`; later strong promotion and reconciliation remain compatible
because the existing credit/evidence and stable `AuthorId` model is reused.

## 10. Role and order semantics

The final compacted row order becomes positive `ContributorPosition` 1..n.
Every row uses `ContributorRole::Author`.

This deliberately rejects deriving `co_author` from position 2+. In 01D and
01E, an array that asserts authorship maps every valid entry to `author` and
uses position for order. `co_author` is retained in the canonical model for a
source that explicitly supplies that role; this manual UI supplies no such
distinction and exposes no role selector.

Order changes before commit change only the final submitted positions. After
commit, this Add Book slice offers no Author editor or reorder mutation.

## 11. Input normalization and validation

The browser may normalize for immediate feedback, but Core repeats and owns
all validation:

- require a JSON list with at most 32 exact row objects;
- accept only one string field, `display_name`, per row;
- trim leading/trailing Unicode whitespace and collapse internal Unicode
  whitespace/separators to one space;
- discard rows that are empty after that normalization;
- require valid UTF-8 and 1..512 Unicode characters for every remaining name,
  matching the existing Author/credit domain limit; and
- preserve case, diacritics, initials, apostrophes, hyphens, particles,
  suffixes and all meaningful punctuation.

There is no person-name grammar, transliteration, punctuation stripping,
case-folding, surname inversion or automatic conversion of `Clark, George C.`.
Duplicate warning comparison uses the exact whitespace-normalized,
case-sensitive value only and has no identity meaning.

## 12. API and command contract

The smallest public addition is one optional top-level `authors` array:

```json
{
  "selection": { "type": "manual" },
  "authors": [
    { "display_name": "George C. Clark Jr." },
    { "display_name": "J. Bibb Cain" }
  ]
}
```

Array order is the user's ordering input, so an explicit `position` field is
redundant and could contradict the array. Core derives positions 1..n. The
browser also sends no role because it cannot truthfully assert a
primary/co-author distinction.

`authors` is not placed in `observed_fields`: that object remains Edition
evidence. The new request member becomes a typed list on
`AddBookCommitRequest`, distinct from `AddBookObservedMetadata`.

Backward compatibility is additive:

- a missing `authors` member means an empty list;
- an explicit empty list is also accepted;
- existing `observed_fields.contributors` remains accepted and unchanged;
- syntactically valid `authors` remains additive on every Add Book request,
  but Core materializes it only for manual selection whose definitive Work and
  Edition are both the preallocated new identities; existing Work, existing
  Edition, local-first, candidate and ISBN-racewinner paths ignore it for
  Author mutation; and
- the success response remains exactly unchanged and exposes no Author IDs.

Internally, one immutable `ManualAuthorCredit`-style value implements the
existing `AuthorMaterializationCredit` contract with user-observation evidence.
The name-only materializer must accept that shared interface or delegate to one
private shared algorithm; it must not create a parallel manual Author service.

## 13. Transaction and idempotency design

After request normalization and before the first transactional attempt, Core
builds an immutable manual Author attempt plan. For each non-empty final row it
allocates one opaque server-side manual observation ID exactly once. The plan,
the current preallocated Item/Work/Edition IDs, final role, final position and
normalized name are reused unchanged if the existing complete Add Book retry
runs.

The existing credit key remains authoritative:

```text
WorkId + role + source position + normalized display name
+ sourceIdentity(userObservation(manual observation ID))
```

The observation ID is never supplied by the DOM or browser and is never
regenerated inside the transaction participant. A local row key may stabilize
focus and form state, but never becomes identity authority. An independent new
HTTP submission is a new observation; matching names do not turn it into the
old identity.

The participant receives the intended preallocated new `WorkId` and only runs
manual credits when the definitive Work equals that ID and the definitive
Edition is new. If local-first resolution or an ISBN race yields an existing
Work/Edition, manual credits are a no-op. Provider credits keep their existing
01E behavior against the definitive Work.

Work, Edition, Item, classification, manual Edition evidence, manual Author,
credit evidence and `WorkContributor` edges share the existing Add Library Item
transaction. A hard Author persistence/validation/invariant failure rolls back
the complete Add Book attempt. A non-materialized semantic outcome for a
manual new Work also fails closed and rolls back; a newly created Work cannot
truthfully succeed while an explicitly entered valid Author silently fails to
attach. Zero Authors is a successful no-op.

Exact replay of the same attempt plan reuses its credit, Author and edge. The
four existing typed Author race signals still cause at most one full-operation
retry with the same plan. Unknown or persistent failures escape without a
partial Work, Edition, Item or Author graph.

## 14. Accessibility and responsive behavior

- Wrap the rows in a `fieldset` with legend `Auteur(s) (optioneel)`.
- Give every input a persistent visible label, `Auteur 1`, `Auteur 2`, and so
  on; numbering follows current visual order.
- Give remove and move buttons row-specific accessible names. Do not rely on
  an icon, position, color or drag gesture alone.
- Associate an inline validation message with only its input through
  `aria-describedby` and `aria-invalid`; move focus to the first blocking
  invalid row on submit.
- Announce add, remove and reorder outcomes through the existing polite live
  region without replacing the user's focus.
- Keep every control at least the current 44px control minimum.
- At desktop/tablet widths the input and compact row controls may share a
  wrapping grid. At 390px and 200% reflow, the input occupies the full row and
  controls wrap below it. No fixed row width, horizontal scrolling or clipped
  accessible label is allowed.
- Back, Forward and a validation retry retain row values, order and local row
  keys. Nothing is persisted before final commit.

## 15. Final Dutch UI copy

| Purpose | Copy |
|---|---|
| Section heading | `Over het werk` |
| Group legend | `Auteur(s) (optioneel)` |
| Row label | `Auteur 1`, `Auteur 2`, ... |
| Helper | `Voeg auteurs toe in de volgorde waarin ze bij dit werk horen.` |
| Add action | `Auteur toevoegen` |
| Remove accessible label | `Verwijder auteur 2: J. Bibb Cain` |
| Empty-row status | `Deze lege rij wordt niet opgeslagen.` |
| Invalid name | `Vul een geldige auteursnaam van maximaal 512 tekens in.` |
| Duplicate warning | `Deze naam staat meer dan één keer. Controleer of dat klopt.` |
| Existing Work note | `Auteurs van dit werk worden hier niet aangepast.` |
| Edition contributor label | `Overige bijdragers (optioneel)` |
| Edition contributor helper | `Bijvoorbeeld vertaler, illustrator, redacteur of samensteller.` |
| Reorder announcement | `Auteur verplaatst naar positie 1 van 2.` |

For an empty row without other invalid content, the status is non-blocking and
the row is collapsed on continue. The duplicate message is also non-blocking.

## 16. Edge cases

- **Unknown or anonymous Author:** submit zero Authors; create no placeholder.
- **Only empty rows:** normalize to an empty list; Add Book remains valid.
- **Same exact name twice on one Work:** warn, preserve both rows and create
  independent provisional Authors at distinct positions.
- **Same name on different Works:** create independent credits and Authors.
- **Name edited before commit:** only the final normalized value is in the
  attempt plan; no obsolete Author exists.
- **Row deleted or reordered:** compact and number the remaining final rows;
  no historical position exists before commit.
- **Back/Forward or validation retry:** restore exact local draft/order; create
  nothing until commit.
- **Selected existing Work with no Authors:** show no placeholder/editor and
  add the Edition/Item without changing the Work.
- **Existing Edition or ISBN race winner:** add only the Item and related
  existing flow data; ignore manual Author materialization.
- **Provider candidate:** retain 01E typed evidence and show no manual Author
  rows.
- **Hard Author write failure:** roll back the complete Add Book transaction.
- **More than 32 or overlong/invalid UTF-8 names:** reject before persistence
  with row-specific UI feedback where possible.

## 17. Explicitly deferred

- Author search, picker or autocomplete;
- provider/person lookup from a manual Author row;
- name-based reuse, deduplication, matching, promotion or merge;
- Author aliases, profiles, detail pages or Search changes;
- Biblio Librarian correction, reconciliation or Work proposal UI;
- Author editing on an existing Work or Edition;
- contributor roles beyond the current `author|co_author` domain;
- an ordinary-user role selector or inferred `co_author` rule;
- structured translator/editor/illustrator entities;
- parsing or migration of existing `contributors` strings;
- current-runtime backfill, V1/historical migration, Series or Collections;
- provider-backed Add Book redesign; and
- extra Author IDs or controls on the success response/screen.

## 18. Implementation slicing recommendation

Use exactly two additive slices. The split is safe because requests without
`authors` remain valid and the UI slice can land only after the backend
contract is available.

### ADD-AUTH-01A — typed manual Author Core/REST integration — implemented

- Add the optional typed manual Author request list and strict parser rules.
- Add user-observation manual credits over the existing shared 01C
  materialization algorithm; derive role/position in Core.
- Allocate and retain manual observation IDs outside the retry attempt.
- Gate materialization on the definitive newly created Work/Edition.
- Reuse `AddBookCommitEvidenceWriter`, the existing transaction and the four
  complete-operation race retries.
- Keep response, schema, providers and existing `contributors` unchanged.
- Add unit, REST, transaction, rollback, idempotency, race-winner, Search and
  Author-to-Works integration coverage.

### ADD-AUTH-01B — repeatable Author UI — implemented

- Add repeatable rows and retained wizard state only in manual-new-Work mode.
- Add add/remove/up/down, duplicate warning, accessible validation/focus/live
  announcements and 390px/200% reflow.
- Show selected Work Authors read-only and conditionally rename the manual
  contributor field to `Overige bijdragers`.
- Extend the frontend request builder/strict tests and guarded E2E coverage.
- Keep provider-backed, existing-Work, existing-Edition, success and Search
  behavior otherwise unchanged.

No third schema, provider or correction slice is required for this feature.

## 19. Acceptance criteria

| Case | Acceptance evidence |
|---|---|
| A. One manual Author | Manual new Work creates one provisional/observed Author, one user-observation credit/evidence and one `author` edge at position 1. |
| B. Two manual Authors | Both names and their entered order survive; two independent provisional Authors and `author` edges occupy positions 1 and 2. |
| C. Zero Authors | Missing, empty array or only blank rows commits the otherwise valid manual book and creates no Author state. |
| D. Same name on different Works | Independent manual observations create independent Authors/credits; no name lookup or merge occurs. |
| E. Exact replay | The same immutable attempt plan reuses the exact credit, Author and edge during the one full-operation retry, with no duplicate graph. |
| F. Existing Work | Manual existing-Work/new-Edition UI sends no Authors; direct syntactically valid input is accepted for compatibility but ignored for Author mutation, and the Work Author graph is unchanged, including when it starts empty. |
| G. Existing Edition | Item addition, local-first reuse and ISBN race-winner paths leave the Work Author graph unchanged. |
| H. Other contributors | `contributors` remains separate Edition evidence and never creates an Author or changes typed Author rows. |
| I. Search | Existing local Author Search finds the newly materialized manual Author without a Search write or new endpoint. |
| J. Author to Works | Existing Author-to-Works returns the exact manual Work through its `WorkContributor` edge. |
| K. Rollback | Hard Author persistence or impossible manual materialization failure leaves no Work, Edition, Item, classification, observation, Author, credit or edge from the attempt. |
| L. Mobile/reflow | At 390px and 200% reflow, rows and controls have no horizontal overflow, clipping or unusable target. |
| M. Keyboard only | A user can add, edit, move and remove every row, understand validation/warnings and commit without drag or pointer input. |

Design exit is satisfied: appearance by path, zero/one/multiple behavior,
ordering, canonical role semantics, provisional identity, no name matching,
the contributor distinction, public contract, transaction/retry behavior,
accessible responsive interaction and the two implementation boundaries are
all closed. Verdict: **DESIGN GO**.

Schema remains `1024`; Biblio Core remains `2.25.0`; ADD-AUTH-01B advances
Biblio UI to `0.19.0`. The repeatable Author UI is technically implemented;
Renée's human visual/interaction acceptance remains pending. Exact closure
evidence is in `docs/101-add-auth-01b-manual-author-ui.md`.
