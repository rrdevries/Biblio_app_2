# 04 — Terminology

Canonical Dutch product terminology for v2.001.

## Biblio
The full application/platform.

## Mijn Biblio
One private platform-wide environment per user. Not a Library and owns no physical collection.

## Bibliotheek
Internal collection/tenant domain entity inside Biblio.

## Privébibliotheek
v2.001 Library type for personal, household or small shared physical collections.

## Eigen/persoonlijke Privébibliotheek
For v2.001, at most one designated Privébibliotheek per user that acts as the user's own collection/authorization anchor. It is auto-created when the user performs the first relevant reading or borrowing action and none exists yet. The user is Eigenaar with Directe toegang. It is distinct from Mijn Biblio and does not own private ReadingRounds or external loans.

## Uitleenbibliotheek
Future institutional lending-oriented Library type. Visible but disabled in v2.001 creation flow.

## Platformrol
Platform-level role: `Super admin`, `Admin` or `Biblio Librarian`.

`Biblio Librarian` is the separate content/capability role for platform-wide
bibliographic catalog curation, including assessment of Work/Edition metadata
correction proposals. It is not automatically implied by `Admin` or `Super
admin`, and does not imply either role.

## Beheerrol
Administrative membership role:
- Eigenaar
- Beheerder
- Lid

`Lezer` is not a technical core role: it is the UI preset `Lid` with normal
participation access.

## Gebruikstoegang
Physical-use level on a Library membership:
- Directe toegang
- Lenen
- Alleen bekijken

Independent from Beheerrol.

## Directe toegang
May directly use an administratively available Item without an internal loan.

## Lenen
May use a Library Item only after an internal loan is created.

## Alleen bekijken
Broad read-only participation boundary. May search, browse and view permitted
shared Library content in an authorized Library Context, but may not directly
use or receive an internal loan, register ReadingRounds, add private Notes or
Ratings/Reviews, submit loan requests, perform other personal participation
actions, mutate catalog data or perform Library management. It is not anonymous
or public access.

## Kijker
UI preset, not a technical core role: `Lid` + `Alleen bekijken`.

## Work
Platform-wide identity of the intellectual/content work.

Its title is explicitly either `provisional` or `librarian_confirmed`. A title
seeded while creating a first Edition is provisional until a `Biblio Librarian`
confirms it. A provisional Work is a usable shared catalog record; this status
does not itself mean erroneous, blocked or subject to review.

Dutch UI wording may use `Werk` where appropriate; canonical data concept remains Work.

## Edition
Platform-wide identity of a publication edition.

Owns one required concrete Edition title used for Item display and catalog
ordering. Provider title evidence belongs here and does not automatically
canonicalize the related Work title.

Dutch UI: `Uitgave`.

## Item / Exemplaar
Concrete physical copy belonging to exactly one Library.

UI term: `Exemplaar`.

## Bibliotheek-lokale presentatielaag
Library-scoped supplementary presentation of a shared Work/Edition record in
one Library. In v2.001 it has exactly own display name, own sort title and
short local explanation / label. It never replaces central bibliography.

## Item/Copy collector-laag
Library-owned supplementary data about one concrete Item/Copy. In v2.001 it
has exactly Signed, Copy number / limitation, Dust jacket,
Inscription / dedication, Origin / provenance and Completeness / enclosures,
beside existing Condition, Location and Acquisition data. It never replaces
central Work/Edition bibliography.

## LibraryCatalogContext
Library-local catalog context attached to central Work/Edition identity, including local Boeksoort/Genre/Onderwerp and later explicitly designed local metadata.

## Auteur
Central identity used for Auteur/Co-auteur relationships.

## Serie
Central Series identity.

Current implementation has a minimal Work→Series relationship. Series
Intelligence distinguishes Work Series from Edition/Publisher Series and keeps
membership state, group, descriptive relation, order, position, lifecycle and
derived coverage separate. `Hoofddeel`, `novelle`, `companion`, `spin-off` and
`omnibus` are not one universal technical role set.

## SeriesMembership group
Series-specific canonical property:

- `core`: the Work belongs to the primary canon/set that constitutes the Series;
- `supplemental`: the Work demonstrably belongs to the Series context outside
  that primary canon.

Insufficient evidence leaves the property unresolved; it creates no third group.

## user-confirmed / user-rejected
Private decision by one authenticated user about a Series suggestion for that
own context. It never changes shared canon or Library completeness.

## canonical-confirmed
Biblio-wide verified Series truth created only through a trusted central
verification process. Library roles do not grant this authority.

## Bibliotheekdekking
Work-level content coverage inside exactly one Library, derived from active
Items and contained Works. It is separate from reading progress.

## Leesdekking
Private platform-wide Work-level coverage derived from the user's completed
ReadingRounds and `read_known_date_unknown` Personal Reading Truth,
independently of possession or source. A Reading Truth marker does not count as
a concrete or dated ReadingRound.

## Personal Reading Truth
Private, source-neutral product truth for exactly one user + Work when there is
not enough reliable information for a concrete ReadingRound. The exact states
are `read_known_date_unknown`, `explicit_not_read` and `unknown`. It is not
Library-, Edition-, Item- or migration-owned and stores no reading date.

## Wishlist Entry / Verlanglijstitem
Private, platform-wide intent owned by exactly one user and targeting exactly
one Work. It is either Work-only, meaning the Edition is irrelevant, or
Edition-specific for one exact Edition of that Work. These target forms are
mutually exclusive per user + Work. It is not Library-owned, Item ownership,
Gewenste aanwinst, Collection membership or Hierna-lezen entry.

## Acquisition gap
A canonical-confirmed, relevant and already released Series member that is not
content-covered in one specific Library. Wishes do not close the gap.

## Series collection-goal direction
Working domain label for a future personal goal linked to one Library that
measures Work-level content coverage. It remains separate from reading coverage;
the final Dutch UI name is open.

## Series reading-goal direction
Working domain label for the personal platform-wide goal that measures
completed ReadingRounds. It remains separate from possession; the final Dutch
UI name is open.

## Metadata Hub
Provider-neutral boundary that turns external metadata into reviewable evidence
and whole-record candidates. Providers are replaceable adapters and are not
canonical truth.

## Biblio Library Intelligence
Overarching product direction for understanding what books mean within a
Library and reading life. Its first two pillars are Biblio Lens and Series
Intelligence.

## Biblio Lens
Conceptual answer to `Wat betekent dit boek voor mijn bibliotheek en
leesleven?`, while keeping Library Fit and private Reading Fit separate.

## Set / boxset
Commercial multi-book product containing multiple separate Editions.

Not a Collection and not an omnibus.

## Set-exemplaar
Library possession context of a Set.

Not an extra book/Item count above child copies.

## Omnibus / bundel
One physical book object containing multiple Works. Modeled as container Work → Edition → Item.

## Leesronde
Private user's concrete reading occurrence of one Work using one physical source.

## Concrete fysieke leesbron
The physical source attached to an active ReadingRound:
- direct-access Library Item;
- internally lent Item;
- active external loan.

## Mijn leesstatus
Private user × Work derived state:
- Niet gelezen
- Aan het lezen
- Uitgelezen

Never an inherent status of Work or Item.

## Leesvoorraad
Private view of concrete sources the user may use now and that have no active ReadingRound on that exact source.

Not synonymous with "unread books".

## Verlanglijst
Private platform-wide personal possession/acquisition wish list.

Supersedes the former generic terminology.

## Gewenste aanwinsten
Shared Library-owned list of books/editions the Library wants to acquire.

## Hierna lezen
Private platform-wide user-owned ordered list of separate future reading
intentions. Every entry has its own stable identity and one Work, optionally
with a mutable preferred Library Item or ExternalLoan source. Content duplicates
are allowed. A successful active ReadingRound start transactionally consumes at
most one deterministic matching entry; manual removal supports short-lived
owner-scoped Undo.

Supersedes `Next to read`.

## Wat zal ik lezen?
Private platform-wide personal choice aid that selects on Work level from
source-context-first candidates the current user can actually read, then
deduplicates to Works. It has three engines: `Voor mij
gekozen`, `Herontdek` and `Verras me`; `Kies uit…` is their scope/filter layer.
It is not a smart or automatically managed form of `Hierna lezen`.

## Geleend
Mijn Biblio perspective of active/historical loans where the current user is borrower:
- internal;
- external.

## Uitgeleend
Library perspective of internal outgoing loans of its Items.

## Collection
Library-owned hand-curated shelf/group of active Items.

UI term: `Collectie`.

## Archief
Lifecycle state/view for previously active Library Items.

## Rating / beoordeling
Private user-owned Work rating, optionally linked to a ReadingRound.

## Review / recensie
Private user-owned written review, independently publishable to one Library context when eligible.

## Notitie
Always-private user note.

## Leesdoel
Private user-owned reading goal.

## Activiteitenlog
Library audit log of shared Library mutations, visible only to authorized management.

## Tijdlijn
Private meaningful personal chronology. Not Library audit.

## Bibliotheekdefault
Shared soft fallback for one Library. In v2.001 only `Bibliotheek → Standaardweergave`.

## Archief tonen
Personal preference per Library, default off. Not a Library default.

## Supporttoegang
Explicit Library-controlled support access:
- Geen
- Bekijken
- Beheren

Never grants access to private user-owned data.

## Correctie voorstellen
Proposal to a `Biblio Librarian` to change platform-wide Work/Edition metadata.
It is required for every such change, whether one or multiple Libraries use the
record. An authorized Eigenaar or Beheerder may submit it, but does not directly
mutate the central metadata.

## Librarian-reviewreason
Functional reason why a future Biblio Librarian review is required. One record
may have multiple reasons: `correction_proposed`, `ambiguous_match`,
`possible_duplicate`, `identity_conflict`, `unresolved_work_identity` or
`structural_ambiguity`.

`provisional` is not a Librarian-reviewreason. Missing ordinary enrichment,
incomplete single-provider data, an Edition with an unambiguous canonical ISBN,
corroborating providers and a provisional Work title without concrete further
uncertainty are not reasons either.

## Superseded terms/concepts

Not current v2.001:
- WordPress Multisite as tenant model;
- Library role `Lezer`;
- former generic wish-list terminology;
- `Next to read`;
- `Profiel` as standalone personal environment;
- `Gepauzeerd` ReadingRound state;
- `Wil ik lezen`;
- media form/drager model for digital media in v2.001.
