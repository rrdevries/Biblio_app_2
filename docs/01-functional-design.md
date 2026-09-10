# 01 — Functional design

Status: canonical functional design for Biblio V2; release scope is classified
separately in `docs/03-scope-and-deferred.md`.

This document states the latest approved product behavior. Historical handovers can contain superseded models such as WordPress Multisite, the role `Lezer`, old Verlanglijst terminology, `Next to read`, `Gepauzeerd`, mandatory Library context for every ReadingRound, the old fixed Home model, or mockups that place Home content under `Mijn Bibliotheek`. Those historical variants are not current behavior unless explicitly retained here.

D-SCOPE-01 separates approved design and implemented foundation from release
necessity. A section in this document may therefore describe valid V2.002+
behavior or already implemented foundation without making that capability a
V2.001 blocker. V2.001 is a reliable, migratable V1 replacement for daily use;
the release matrix, primary journeys and Definition of Done in document 03 are
authoritative when version scope is at issue.

---

# 1. Purpose, status and authority

Biblio V2 is a personal reading and physical collection application in which personal reading data and shared collection data are deliberately separated.

v2.001 supports only physical books. Digital books, audiobooks, digital files, licenses and providers are out of scope.

Biblio runs as one platform. A `Bibliotheek` is an internal tenant/domain entity, not a separate WordPress site.

Historical sources remain preserved as design history. Current truth is determined by the conflict order documented in `README.md`.

Persistence choices such as CPT, CCT or custom tables are technical implementation decisions. Fase 0 established Biblio-owned custom tables as the baseline for integrity-, scope-, transaction- and concurrency-sensitive Core-data; selection remains open per domain as specified in `docs/decisions/ADR-004-fase-0-persistence-and-reading-sources.md`.

# 2. Product model

`Biblio` is the full application.

Every platform user has exactly one private platform-wide environment: `Mijn Biblio`.

`Mijn Biblio` is not a Library and owns no physical collection.

A user can initially belong to zero Libraries.

For v2.001, a user has at most one designated own/personal `Privébibliotheek`. When the user performs their first relevant reading or borrowing action and no personal Privébibliotheek exists yet, Biblio creates it automatically. The user becomes `Eigenaar` with `Directe toegang`.

The personal Privébibliotheek is not `Mijn Biblio`: private reading data remains user-owned. The same user may additionally belong to, manage or own other Privébibliotheken.

Mijn Biblio contains:
- Home;
- Leeslog;
- Leesvoorraad;
- Geleend;
- Verlanglijst;
- Hierna lezen;
- Wat zal ik lezen?;
- Leesdoelen;
- Statistieken;
- Jaaroverzicht;
- Tijdlijn;
- Mijn beoordelingen;
- Mijn notities;
- Mijn voorkeuren;
- the user's Libraries.

`Profiel` is not a separate v2.001 module.

## Library types

Biblio recognizes the concepts:
- `Privébibliotheek`;
- `Uitleenbibliotheek`.

`Privébibliotheek` is the fully supported v2.001 type. It can be personal, household or small shared collection and may have multiple members.

`Uitleenbibliotheek` is shown in `Bibliotheek maken` as a disabled future choice. It is not selectable and does not expose unfinished institutional workflows.

A v2.001 Library cannot change type.

## Scope model

### Platform-wide identity
- Work
- Edition
- Auteur
- Serie

### Library-owned
- LibraryCatalogContext
- Items/Exemplaren
- Locations
- local classifications
- Collections
- Gewenste aanwinsten
- Archive
- internal loans
- Library settings
- Library audit

### User-owned
- ReadingRounds
- external loans
- Verlanglijst
- Hierna lezen
- personal suggestion results, derived preference profile and suggestion preferences
- reading goals
- ratings/reviews
- notes
- personal insights
- Home configuration and preferences

A Library reference on user-owned data is context only and never transfers ownership to the Library.

# 3. Libraries, accounts, memberships and rights

## Platform roles

- `Super admin`
- `Admin`
- `Biblio Librarian`

Super admin has platform recovery responsibilities but does not receive automatic access to private Library/user content.

Admin is delegated through explicit platform permissions.

`Biblio Librarian` is a separate platform-wide bibliographic catalog-curation
capability. It is responsible for assessing correction proposals for shared
Work/Edition metadata. It is not automatically granted by `Admin` or `Super
admin`, and it grants neither of those roles; no additional technical override
is implied here.

`Gebruikersbeheer` is an explicit platform permission. Super admin has it; Admin only when assigned.

## Platform accounts

Only Platformbeheer creates new Mijn Biblio accounts in v2.001.

One person uses one platform-wide account across all Libraries.

D-IDENTITY-01 applies this rule explicitly to Renée and migration: her normal
personal account is the durable identity for ReadingRounds, Notes,
Ratings/Reviews, Wishlist and other user-owned data. The same account may later
receive or lose `Admin`, `Super admin` and/or `Biblio Librarian` privileges.
Those platform privileges and the account's Library memberships are
independent dimensions; no second admin persona is created and privilege
changes never transfer or rewrite personal data or Library ownership.

The local DDEV/platform admin is a separate account and is not the migration
target. MIG-02 write/apply mode always requires an explicitly supplied
`target_user_id` plus `target_library_id`; neither the current actor, first
user, an admin nor a Library display name may provide a default. The target
must be an active platform user and the active Owner with Directe toegang of
that exact designated personal Library. Any mismatch fails closed.

A newly created platform account may temporarily have no Library.

For v2.001, Biblio automatically creates the user's one designated personal Privébibliotheek on the first relevant reading or borrowing action when that Library does not yet exist. That action creates the membership as:
- Eigenaar;
- Directe toegang.

The automatically provisioned Library is named `Mijn Bibliotheek`. Library
names are required, trimmed, whitespace-normalized UTF-8 text of at most 191
characters and are not globally unique. Existing supported Libraries without
a usable name receive `Mijn Bibliotheek` through the formal F2.10 migration.

For local operator provisioning, the stable bootstrap identity is the existing
user→Library personal designation, never the non-unique display name. A rerun
reuses only an exactly matching normal WordPress account and its designation;
conflicting account, designation, membership or Library state fails without
automatic repair.

This automatic Library creation does not move personal ReadingRounds, external loans, ratings, goals or other Mijn Biblio data into Library ownership.

The same user may still create or own additional Privébibliotheken for other collection contexts where the relevant creation flow permits this.

A user cannot self-join an existing Library.

Platformbeheer initially links an existing platform user to another existing Privébibliotheek. The safe membership is:
- Beheerrol = Lid;
- Gebruikstoegang = Alleen bekijken;
- status = Actief.

The existing platform account is reused.

Invitations, email activation, acceptance, self-registration and self-join are deferred.

## Membership dimensions

### Beheerrol
- Eigenaar
- Beheerder
- Lid

Exactly one Eigenaar per Library.

### Gebruikstoegang
- Directe toegang
- Lenen
- Alleen bekijken

For a v2.001 Privébibliotheek, Eigenaar always has Directe toegang.

For any other membership the safe default is Alleen bekijken.

`Lezer` and `Kijker` are UI presets, not additional technical membership
roles. `Lezer` means `Lid` with normal participation access. `Kijker` means
`Lid` + `Alleen bekijken`.

### Meaning

`Directe toegang`
- may directly use administratively available Items;
- no loan required for normal use;
- may still receive an explicit internal loan when tracking temporary possession is useful.

`Lenen`
- may receive an internal loan;
- a Library Item becomes the user's physical reading source only after the loan exists.

`Alleen bekijken`
- may view and search the active collection;
- may browse and view permitted details in the authorized Library Context;
- cannot directly use a Library Item as personal source or receive an
  internal loan;
- cannot register ReadingRounds, add private Notes, add Ratings/Reviews, make
  loan requests or perform other personal participation actions in that
  Library Context;
- cannot mutate catalog data or perform Library management.

`Alleen bekijken` is a read-only participation boundary, not anonymous or
public access.

## Beheerder baseline and additional permissions

A `Beheerder` has baseline management rights for the shared catalog/books and physical Exemplaren of the current Bibliotheek. These baseline rights do not automatically include other management domains.

Additional permissions are explicitly assigned, for example:
- Collections;
- lending;
- Gewenste aanwinsten;
- Library defaults;
- full Library statistics;
- members/access;
- general Library settings.

Additional permissions are active only while the user is `Beheerder`.

Demotion to Lid deactivates them. Later promotion does not restore them automatically.

Use access does not automatically change with management-role changes.

## Self-escalation protection

A Beheerder cannot alter or raise their own:
- Beheerrol;
- Gebruikstoegang;
- additional permissions.

A Beheerder cannot make themselves Eigenaar, manage the Eigenaar or manage other Beheerders.

A Beheerder with `Leden/toegang beheren` may manage existing ordinary `Lid` memberships within the approved boundary: change their `Gebruikstoegang` and deactivate/reactivate the membership. This permission does not allow creation of platform accounts, initial linking of a user to a Bibliotheek, promotion to Beheerder, management of other Beheerders, or self-escalation.

Only the Eigenaar controls the Beheerder layer in v2.001.

A future `Hoofdbeheerder`/head-manager layer is deferred.

## Membership lifecycle

Eigenaar may deactivate/reactivate memberships.

A Beheerder with sufficient members/access permission may do so only for ordinary Leden.

Reactivation does not restore previous role, use access or additional permissions. Defaults are:
- Lid;
- Alleen bekijken;
- no additional management permissions.

Historical values may be shown for reference only.

A membership may be ended while internal loans are still active. The loans remain active until normal settlement.

## Ownership transfer

Ownership can be transferred to an active Beheerder.

The new Eigenaar obtains mandatory Directe toegang.

The former Eigenaar becomes Beheerder.

The transfer flow explicitly requires the former Eigenaar's new use access:
- Directe toegang;
- Lenen;
- Alleen bekijken.

Directe toegang may be preselected but must be confirmed.

## Platform account deactivation

Platform account status and Library membership status are independent.

Platform deactivation blocks login everywhere but does not rewrite:
- memberships;
- personal history;
- loans;
- goals or ratings.

Normal v2.001 account deactivation is blocked while the user owns one or more Privébibliotheken; ownership must first be transferred.

Super admin may use an explicit recovery exception.

On platform reactivation, memberships that are still Actief become reachable again. Individually deactivated memberships remain inactive.

Normal hard-delete/erasure of platform accounts is deferred.

# 4. Catalog and bibliographic data

v2.001 media type: physical `Boek`.

## Core hierarchy

### Work
Abstract content identity.

May exist without Edition or Item.

Central/platform-wide.

Includes, where applicable:
- one Work title with explicit `provisional` or `librarian_confirmed` status,
  plus known alternative titles;
- Author/Co-author relationships;
- original language(s);
- Series/content relationships;
- omnibus content relationships;
- audience where defined at Work level.

### Edition
Specific publication edition.

Central/platform-wide.

Includes:
- one required concrete Edition title;
- ISBN-10;
- ISBN-13;
- explicit `Geen ISBN`;
- main and supplemental languages;
- publisher;
- publication date with preserved precision;
- page count;
- Binding;
- dimensions;
- Uitgaveformaat;
- Editietypes;
- edition/printing data;
- physical publication characteristics;
- Edition-specific contributors;
- covers;
- external metadata identifiers.

### Item / Exemplaar
Concrete physical copy owned by one Library.

Library-owned.

Includes:
- Edition reference;
- Location;
- Condition;
- Acquisition;
- inventory number;
- copy-specific physical features;
- archive/lifecycle;
- lending state.

### Collector-local layers

Collector-local data supplements, and never replaces, platform-wide Work/Edition
bibliography. It has two separate layers.

The Library-local presentation layer controls how a shared Work/Edition record
is presented in one Library. In v2.001 it contains exactly own display name,
own sort title and short local explanation / label.

The Item/Copy collector layer describes one concrete physical copy. In addition
to existing Condition, Location and Acquisition data, v2.001 contains exactly
Signed, Copy number / limitation, Dust jacket, Inscription / dedication,
Origin / provenance and Completeness / enclosures. `Leesexemplaar` /
`verzamelkopie` is not a separate Item/Copy core field; the local
presentation-/label layer may express it.

Author(s), ISBN, language, publisher, publication date and other shared
Work/Edition metadata do not receive local overrides. A central error remains a
Biblio Librarian correction-flow matter. Specialist antiquarian cataloguing is
deferred. MH-B5 and later UI must respect these ownership boundaries; this
decision defines no MH-B5 or UI behavior. See ADR-013.

## LibraryCatalogContext

A Library-local layer connects central bibliographic identity to local catalog use.

It contains local classifications such as:
- Boeksoort;
- Genre;
- Onderwerp;
- later only explicitly designed local catalog data.

Central bibliographic facts do not become Library copies merely because the Library uses them.

## Languages

Edition:
- zero or one main language;
- zero or more supplemental languages;
- standardized code and user-facing label.

Work:
- zero or more original languages;
- no mandatory main original language.

## ISBN

Edition-level.

ISBN-10 and ISBN-13 are optional.

`Geen ISBN` explicitly states that the Edition has none.

Empty ISBN fields without that flag mean unknown/not entered.

An ISBN identifies an Edition, never a Work or physical Item. Identifier
availability is not an existence condition: the manual path supports Works,
Editions and Items without an ISBN.

## Work and Edition title authority

The title shown for a concrete catalog Item is the title of its Edition. A new
Edition always receives its own required title. When the same input creates a
new Work, that Edition title may seed the Work title, but the Work title starts
as `provisional`; creation never confirms it as canonical.

Only an explicit `Biblio Librarian` decision may mark a Work title
`librarian_confirmed`. Provider title evidence remains Edition-level evidence
and cannot automatically confirm or overwrite a Work title. Search may match
both the concrete Edition title and the Work title; normal catalog display,
alphabetical ordering and title tie-breakers use the Edition title.

`provisional` means that the shared Work record exists and is fully usable, but
has not yet been content-curated or confirmed by a Biblio Librarian. It does
not mean erroneous, suspicious, blocked or automatically queued for review.
Missing Librarian confirmation never blocks adding or using an Edition.

## Metadata Hub and acquisition

The Metadata Hub is a provider-neutral evidence and candidate boundary.
External sources are replaceable adapters; they are not canonical truth and
their response shapes do not become Core domain contracts. Confirmed local
canonical data always wins and is never silently overwritten.

The approved v2.001 build target is deliberately small:

- normalize and checksum-validate ISBN-10/ISBN-13;
- search confirmed local canonical data first;
- use one provider-neutral Hub interface;
- use Open Library as conditional open foundation;
- use Google Books only as a temporary, conditional fallback, especially for
  Dutch coverage;
- present whole-record candidates for explicit user review and correction;
- retain minimum provenance: provider, provider record ID, `retrieved_at`,
  match method, queried identifier and confirmation state;
- preserve a first-class manual/no-ISBN path;
- render an honest no-cover state without depending on a provider cover.

Independent Biblio-owned or user-supplied cover acquisition/management is
V2.002+. Existing V1 cover references/assets remain migration inventory and
may not be silently discarded.

Wikidata and BookBrainz are **NO-GO** runtime adapters for v2.001 based on the
completed benchmark, but remain future evidence/relationship candidates. Google
is not a permanent primary provider. Before commercial release its then-current
terms, storage/caching, branding/attribution, paid-use fit and replacement path
must be reassessed.

ISBN evidence resolves an Edition candidate. Work resolution requires either a
reliable explicit provider link or an already confirmed local relationship.
Title + author similarity never triggers automatic Work merge. Uncertain
resolution remains visibly `voorgesteld`/`mogelijk`, with language calibrated
to evidence strength.

Materially conflicting provider results remain reviewable whole-record
candidates in v2.001; there is no automatic multi-provider merge. After a
candidate is selected for review, its supported values may exist as independent
field proposals with provider-neutral evidence. Field-level confidence/merge
logic, record fusion, OCR, community metadata, a Metadata Graph, paid feeds and
extensive automatic Work resolution remain future scope.

### Field confirmation and provenance

Metadata proposes. Every change to platform-wide Work/Edition metadata is a
correction proposal for a `Biblio Librarian`; a submitting Library actor does
not directly determine or mutate canonical values. The field review is
explicit:

- a missing or differing provider value becomes a proposal and never silently
  replaces a canonical value;
- an identical provider value creates no active proposal or conflict, retains
  supporting evidence and does not become user-confirmed automatically;
- identical values from multiple providers share one content proposal while
  retaining separate provider/source evidence;
- rejection applies to the proposed content value, not one provider; repeated
  evidence for that rejected value remains historical and does not reactivate
  it, while a different value may be proposed later;
- a Biblio Librarian's explicit decision is required before a proposed value
  becomes canonical; it removes other current values for that field from
  active review without deleting evidence;
- a later genuinely new differing value may become a new proposal, but cannot
  overwrite an already confirmed or manually corrected value;
- ordered multiple values such as contributors are one atomic field value and
  are never partially merged;
- intentionally blank is persistent and distinct from unknown. Provider
  evidence remains non-active until an explicit authorized reopen allows
  proposals again.

The supported MH-B4 field keys follow the provider-neutral MH-B2/MH-B3
candidate contract: title, subtitle, contributors, languages, publishers,
publication date, page count and format. This foundation defines no REST/UI or
Add Book behavior and performs no automatic Work/Edition/Item mutation.

### MH-B5 Add Book integration

ADR-014 splits MH-B5 into MH-B5A, the authorized local-first metadata
lookup/review contract without catalog mutation, and MH-B5B, the later Add Book
commit integration. MH-B5A must be GO before MH-B5B starts.

Add Book first checks a scanned or entered canonical ISBN locally. An existing
Edition is reused and does not cause a provider lookup for that addition;
central metadata correction/enrichment remains separate. For a new ISBN, the
Metadata Hub supplies candidates: one usable candidate is shown for review,
multiple usable candidates require explicit concrete-Edition selection and
never receive an automatic winner. Manual entry remains available for multiple,
missing or failed provider results, and provider trouble never blocks Add Book.

Add Book review means only that the user identifies the correct concrete
Edition for the physical book being added. It neither makes a Work/Edition
`librarian_confirmed` nor treats all provider values as platform-wide curated.
New records may remain immediately usable provisional records.

Directly checked physical-book data is first-class `user-observed evidence`,
not provider evidence, `user_confirmed` canonical metadata or
`librarian_confirmed` metadata. It remains conceptually traceable to actor,
Library Context, field, observed value, observation time and source context
such as `physical_copy` / Add Book. It may cover Edition title/subtitle, ISBN,
Edition language, publisher/imprint, publication year/date,
printing/edition statement, binding/physical form, page count and
Edition-specific contributors. Provider data may not silently replace it.

When an Edition is now existing at commit, its Item may still be added even if
the observed data differs. Biblio retains that difference as user-observed
evidence and, where applicable, as a correction proposal for Biblio Librarian;
it does not overwrite shared Work/Edition data. For a new provisional Edition,
directly checked Edition data may be used on creation without making the
Edition librarian-confirmed.

MH-B5A may hold the candidates actually shown for review in a temporary
server-side snapshot bound to actor and Library Context. The client uses only
opaque `lookup_id` / `candidate_id` references. B5B reauthorizes, rechecks
Library Context and reruns local-first Edition resolution, then reconstructs
the selected candidate from that snapshot; those identifiers are never
authorization proof. A valid snapshot lets commit use precisely the reviewed
metadata without a provider refetch, so current provider availability cannot
block it. Expiry requires a fresh lookup and review, never a silent refetch.
Catalog mutation and associated evidence retention form one consistent B5B
commit; storage, TTL and transaction mechanics remain technical choices.

Provider `title`, `subtitle`, `languages`, `publishers`, `publication_date` and
`page_count` bind only to the Edition as specified in ADR-014. Clear
author/co-author contributors bind to Work; clear edition-bound roles such as
translator bind to Edition; illustrator and editor/compiler are Edition in
v2.001; unknown/untyped contributors remain evidence only. `format` has no
generic automatic mapping: only an explicit allowlist may bind an unambiguous
value to `Edition.Binding`; other values remain evidence. No Expression entity
exists in v2.001.

### Metadata conflict review

When external candidates agree sufficiently, Biblio proposes one whole record
in the normal review/correction screen. Only material conflicts require explicit
candidate selection. Two materially different records are shown compactly with
their relevant differences and actions such as `Gebruik deze uitgave` and
`Zelf invoeren`.

- Identity-critical differences — a clearly different Edition, title, Author,
  language or ISBN relation — must be reviewable before acceptance.
- Publication-characteristic differences — page count, publisher, publication
  date, Binding or format — may be visible without always blocking import.
- Intelligence/enrichment differences — Genre, subjects or Series — never block
  book import and remain separately confirmable proposal/evidence.

Provider name remains provenance/context and is not the primary selection
criterion. Manual entry and editing are always available. v2.001 has no
provider-per-field picker, automatic field merge or multi-provider field
fusion. Field confirmation is a content choice; it is not a provider choice.

## Publication date

Edition-level and precision-preserving:
- year;
- year + month;
- full date.

Biblio never fabricates a day/month.

## Covers

Release classification: independent Biblio-owned cover acquisition and
management is V2.002+. V2.001 may use truthful no-cover presentation. MIG-01
must inventory and preserve existing V1 cover references/assets; the design
rules below remain valid for later activation.

Edition may have multiple cover images and one Primary cover.

If no Primary exists, a reviewed and permitted external image may become
Primary, but Biblio never requires a provider cover.

Once a Primary exists it is never silently replaced.

Deleting the Primary does not automatically pick a new one.

## Contributors

Compact v2.001 roles:
- Auteur
- Co-auteur
- Illustrator
- Redacteur
- Samensteller
- Vertaler
- Fotograaf
- Bewerker
- Overig

When the dedicated Authors module is implemented, only Auteur/Co-auteur drive
Author detail pages in its first version.

## Condition

Optional Item value:
- Nieuwstaat
- Zeer goed
- Goed
- Redelijk
- Matig
- Slecht

Blank = not recorded.

## Inventory number

Optional Item field, unique within its Library when present.

Not platform-wide unique.

## Sets

A Set/boxset is a commercial product containing multiple separate Editions.

It is not a user-created Collection.

A Set:
- has its own product metadata;
- contains Editions, not Works;
- cannot contain nested Sets in v2.001;
- may contain an omnibus Edition;
- has a Library-specific Set possession layer;
- does not count as an extra book/Item beyond child books;
- has no ReadingRound or Rating of its own.

Set search:
- direct Set title, Set ISBN and own Set metadata can return a Set result;
- a match only through a child Work does not automatically create an extra Set result;
- contextual `Onderdeel van [Set]` may be shown.

## Omnibus

An omnibus is one physical book object and therefore:
- container Work;
- Edition;
- Item.

It contains multiple underlying Works.

One omnibus Item can be the source of distinct ReadingRounds for different contained Works.

## Central bibliographic governance

An Eigenaar or authorized Beheerder may create a new central Work/Edition during Item-add flow when no appropriate record exists.

In addition, the Eigenaar of the user's designated personal Privébibliotheek may create the minimum central bibliographic identity needed by a valid personal reading/borrowing flow, for example when registering an external loan whose Work does not yet exist. Where required, this minimal creation route may also create a missing Auteur or Serie identity.

This personal-flow authority exists because the user has a personal Privébibliotheek as authorization anchor. The resulting ReadingRound or external loan remains user-owned and an externally borrowed physical source does not become an Item of that Library.

Before creating a new central identity, Biblio first searches for an existing appropriate record to reduce duplicates.

A new Work and/or Edition may immediately arise as a platform-wide
`provisional` catalog record when no appropriate existing record exists. This
normal creation is fully usable for collection management and is not a Biblio
Librarian approval gate.

Work and Edition are platform-wide shared bibliographic entities, irrespective
of whether one or multiple Libraries currently use them. Provider evidence for
them is also platform-wide shared. Every change to their metadata is therefore
always a `Correctie voorstellen` proposal for a `Biblio Librarian`.

An Eigenaar or authorized Beheerder may submit a proposal where authorized,
with a proposed value and optional explanation, but may not directly mutate
central Work/Edition metadata. A Biblio Librarian assesses the proposal
platform-wide. This explicitly replaces the older rule that allowed direct
ordinary correction while one Library was the sole user of a central record.

Structural actions such as merge/split of Works, identity merging of Authors or major Series restructuring remain platform/bibliographic administration.

Local Boeksoort/Genre/Onderwerp remain directly managed by the Library.

### Exception-based Librarian review

Biblio Librarian is catalog curation and exception handling, not mandatory
review of every new provisional Work or Edition. A future review task may carry
one or more of these reasons:

- `correction_proposed`: an Eigenaar or authorized Beheerder explicitly
  proposes a central metadata correction;
- `ambiguous_match`: multiple plausible Work/Edition matches cannot safely be
  chosen automatically;
- `possible_duplicate`: records may describe the same Work/Edition but an
  automatic merge is not sufficiently certain;
- `identity_conflict`: evidence conflicts on Work/Edition identity metadata;
- `unresolved_work_identity`: Work relation, original Work identity or
  original/canonical Work title remains substantively uncertain;
- `structural_ambiguity`: structure is unreliable to determine automatically,
  such as translation versus another Work, omnibus/bundle, boxset or multiple
  underlying Works.

`provisional` status alone never creates review. Nor do a missing cover,
publisher or publication date; incomplete data from one provider; a new
Edition with an unambiguous canonical ISBN; multiple providers supporting the
same metadata; or a provisional Work title without concrete further
uncertainty. Queue presentation, priority, notification, task assignment,
dashboard and merge flow remain future design/implementation scope.

## Biblio Library Intelligence and Lens

`Biblio Library Intelligence` is the overarching product direction. Its first
two pillars are Biblio Lens and Series Intelligence, under the promise:
`Biblio registreert je bibliotheek niet alleen. Het begrijpt haar.`

Biblio Lens conceptually answers: `Wat betekent dit boek voor mijn bibliotheek
en leesleven?` It keeps two evidence scopes separate:

- **Library Fit:** exact Edition already present; the same Work in another
  Edition when reliably resolved; wishlist/acquisition intent; next-reading
  intent; confirmed Series/gap context; omnibus coverage; and Household
  presence without exposing private reading data;
- **Reading Fit:** other books by the same Author, personal Author history,
  genre/reading profile, and the actor's own ratings and reading history.

V2.001 does not require an active Lens feature. The implemented foundation is
ISBN/Edition identity and evidence. Later maturity may add active Edition
Intelligence, broader Work resolution, Series Intelligence, OCR/vision,
shelf/spine recognition and a Metadata Graph.

# 5. Library

A Privébibliotheek contains active physical Items belonging to that collection.

Multiple Items of the same Work are separate physical records.

## Personal overlay

In Library context the current user may see their own Work-level status:
- Niet gelezen;
- Aan het lezen;
- Uitgelezen.

This is private user data.

No Library role grants access to other users' reading status.

## Item availability

`Beschikbaar` is an administrative state:
- Item is active;
- not formally lent out;
- not otherwise administratively unavailable.

It is not a realtime physical-location guarantee.

Direct-access use without a loan does not change shared availability.

A formal loan changes shared status to `Uitgeleend`.

Ordinary members may see `Uitgeleend` but not the borrower's identity.

The borrower sees `Aan jou uitgeleend` in personal context.

Eigenaar and authorized lending Beheerders see full loan administration.

## Locations

Library-owned.

An Item has zero or one current Location.

Locations can be created, renamed, archived and reactivated by the Eigenaar or a Beheerder who may manage shared Items.

Used Locations are not destructively removed.

## Acquisition

Separate:
- `In bibliotheek sinds` — content/business date;
- `Geregistreerd op` — technical registration timestamp.

Acquisition statistics use the content date.

## Library classification

`Boeksoort`
- exactly one;
- mandatory;
- Library-controlled standard values.

`Genre`
- optional;
- multi-value;
- Library-controlled standard values.

`Onderwerp`
- optional;
- multi-value;
- no required initial standard list.

No generic `Categorie`.

Tags deferred.

Book Detail reads this classification from the current Library + Work context,
not from Edition or Item and not from the active-option list. It may show the
assigned Book Type, Genres and Subjects with their Library-local display names.
An assigned term remains visible after deactivation; deactivation only removes
it from new choices. A context-free legacy Work shows no classification.

## Search inside a Library

Strictly scoped to the current Library.

Searchable via:
- Edition title, Work title and alternative Work title;
- Auteur/Co-auteur;
- Serie;
- ISBN relevant to Library Items;
- inventory number.

Search is live while typing and starts when the canonical minimum of two
characters is reached. Ordinary text matching is partial, case-insensitive and
accent/diacritic-tolerant. Exact identifiers such as ISBN and inventory number
may be recognized as exact matches and ranked higher. A technical debounce and
stale-request cancellation/ignoring are allowed; only the newest valid query
may determine the visible result.

An omnibus can match through the title, Auteur/Co-auteur or Serie of a contained
Work. The omnibus Item remains the Library result and shows context such as
`Bevat: [titel]`; a contained Work without its own Library Item is never shown
as a virtual Item. A separately present Item for that Work remains its own
normal result.

Default search includes only active Items.

Without an active search or an explicitly selected alternate order, the
Library overview is ordered by Edition title ascending. A technical stable
tie-breaker may be added without becoming a separate user-facing sort choice.

v2.001 sort choices are:

- `Titel A–Z` — default;
- `Auteur A–Z`;
- `Serievolgorde` — available only while a Serie filter is active, with unknown
  volume numbers last.

No date, Location, Rating or other unlisted sort is available in v2.001. During
active Search the canonical relevance order remains authoritative, including
alphabetical title within equal relevance; a selected alternate sort does not
override it and becomes effective again when Search is no longer active.

Within one filter group selected values use OR. Different filter groups combine
through AND unless a filter has an explicitly documented exception. Expanded
filters apply directly without an Apply button. Active values appear above the
results as individually removable chips, together with `Alle filters wissen`.

The typed catalog-query contract supports:

- Leesstatus;
- Auteur;
- Serie;
- Locatie;
- Boeksoort;
- Genre;
- Onderwerp;
- Collecties;
- `Zonder collectie`.

For V2.001 release UI, only controls with a safe authorized source are exposed:
Leesstatus, Boeksoort, Genre, Onderwerp and `Zonder collectie` are currently
supported. Author, Series, Location and ordinary Collection controls await
additional safe Library-scoped option/read routes and are V2.002+, as are Taal,
Uitgever, Uitleenstatus, Conditie and `In bibliotheek sinds`. Their typed
backend foundation may remain without fabricating UI controls.

Search, filter and sort state is represented in the URL. Browser Back/Forward
restores the earlier query and a copied URL opens the same temporary query.
When no explicit URL query state exists, temporary session state may be used,
scoped to authenticated user + Library + module. Neither URL nor session state
silently changes a permanent preference; permanent preferences change only
through explicit settings.

Temporary `Ook in archief zoeken`:
- applies only to the current search-page session;
- does not change the personal `Archief tonen` preference;
- resets on refresh/navigation.

CAT-UI-01 activates this contract in Mijn Bibliotheek. The browser exposes the
fixed Reading status values, the existing Library-scoped active Book Type,
Genre and Subject option lists, and `Zonder collectie`, which needs no option
list. Author, Series, Location and Collection controls stay absent until an
approved Library-scoped option/read source exists; backend query support alone
is not an option source.
Search/filter/sort share one Grid/List query state. The temporary archive flag
is not written to URL or session state.

When personal `Archief tonen` or temporary `Ook in archief zoeken` is active,
active and archived Items appear in the same result list. There is no parallel
Archive result group. Archived hits are clearly marked `Archief`.

Multiple matching Items are all visible. Work may group for readability but does not deduplicate physical copies.

## Library statistics

Release classification: the active Library statistics feature is V2.002+ and
is not a V2.001 blocker. MIG-01 inventories and preserves relevant V1 source
data where necessary. The design below remains the approved future contract.

Separate `Bibliotheek → Statistieken`.

Contains only shared operational Library data, never aggregate private reading/ratings/goals.

Full Library stats available to:
- Eigenaar;
- Beheerder with sufficient explicit permission.

Current-state metrics:
- active Items;
- currently lent Items;
- currently archived Items.

Period activity can include:
- acquisitions based on `In bibliotheek sinds`;
- archived events based on content archive date;
- new outgoing internal loans;
- Gewenste aanwinsten added;
- Gewenste aanwinsten fulfilled.

No net-growth metric in v2.001.

# 6. Reading and ReadingRounds

A ReadingRound is private user-owned data.

## Active-round invariant

A new active ReadingRound has:
- exactly one user;
- exactly one Work;
- exactly one concrete physical source.

At most one active ReadingRound per user + concrete source.

Multiple active rounds for the same Work are allowed when they use different sources.

## Valid active physical source types in the retained design

D-SCOPE-01 does not make the full internal/external lending module a V2.001
requirement. MIG-01 determines whether migrated circulation needs a minimal
active source/settlement subset at cutover.

- active Library Item available through `Directe toegang`;
- Item actively internally lent to the user;
- active external loan.

No generic `Andere fysieke bron` in v2.001.

Historical closed rounds may have an unknown source if genuinely unknown.

## Start reading

From a concrete Item/loan:
- source is already known.

From Work-level:
- user must first select a currently valid concrete physical source.

A newly owned physical copy not in Biblio must first become a Library Item.

An outside physical book is first registered as an external loan.

## Lifecycle

No `Gepauzeerd`.

A round is:
- active;
- ended.

When ended:
- `Uitgelezen = ja`;
- or `Uitgelezen = nee` (`Gestopt`).

Technical creation, update and end timestamps are not reading dates. Content
dates preserve what the user knows: exact day, month + year or year only.
Unknown date parts are not filled with an artificial default.

An ended round may be corrected for an incorrectly recorded completed/stopped
outcome or content reading period. This is the same ReadingRound, not a new
round. User, Work and normal-vs-historical provenance remain unchanged; this
correction does not implicitly change the source.

## Personal Work status

Derived for current user:

1. at least one active round → `Aan het lezen`;
2. else at least one historical `Uitgelezen = ja` → `Uitgelezen`;
3. else a Personal Reading Truth `read_known_date_unknown` → `Uitgelezen`,
   with the reading date explicitly unknown;
4. else Personal Reading Truth `explicit_not_read` → `Niet gelezen`;
5. else Personal Reading Truth `unknown` → `Onbekend`;
6. else → the existing default `Niet gelezen`.

A stopped reread does not erase previous read completion.

Personal Reading Truth is normal, private V2 state for exactly one user +
Work. It is not tied to a Library, Edition or Item and has exactly three
states: `read_known_date_unknown`, `explicit_not_read` and `unknown`. It is not
a ReadingRound, carries no reading date, source or migration provenance and
never increases a round count. A `read_known_date_unknown` marker does prove a
prior Work-level read, so a later concrete round can be classified as a
reread even when it is the first persisted ReadingRound. The marker remains
after later active or completed rounds, while those concrete rounds take
precedence in the effective status.

A new `explicit_not_read` write is contradictory when an owned completed
ReadingRound already exists and fails closed. Normal V2 may use the
source-neutral write contract for a future “Mark as read — date unknown” action;
READ-MIG-01 does not add that edit UI. Work-level future features may treat
`read_known_date_unknown` as read evidence. Year/month timelines, streaks,
round-based goals and concrete reading/reread counts may use only actual
ReadingRounds and their known dates.

## Multiple copies

If Item A has an active round and Item B of the same Work does not:
- Work status is Aan het lezen;
- A is the source of a private active round;
- B may still be in Leesvoorraad.

`Jij leest dit exemplaar` is private relationship context, not a shared Item state.

## Changes in access/source lifecycle

Loss of direct access, end of membership, return or archiving never automatically ends someone else's private active ReadingRound.

The round remains linked to the recorded source and may indicate that the
source is no longer available, unless the user explicitly corrects an
incorrectly recorded source under the rules below.

Only the user ends their private ReadingRound.

## Historical registration

A completed historical read may be registered directly.

Known date precision is preserved.

When its physical source is genuinely unknown, the historical round is stored
without a source and is explicitly distinguishable from a round that followed
the normal source-backed start/end lifecycle. No pseudo source is created.

## Source correction and erroneous historical registration

The owner may explicitly correct the source of an active or ended round. A new
concrete Item or ExternalLoan must be valid and accessible under its applicable
source rules and represent the same Work. Item→Item and Item↔ExternalLoan are
therefore allowed only within that Work. A source-free historical round may
later receive such a source. A recorded concrete source may become unknown
only through an explicit correction when that source was wrong and the right
source is no longer known.

Source correction never changes Work or creates another ReadingRound. Other
corrections never change source implicitly. A wrong Work is not a source
correction.

A completely erroneous manually registered historical round may be deleted.
A round that actually followed Biblio's normal start→end lifecycle is not hard
deleted and is corrected instead. If a manual historical round has the wrong
Work, it is deleted and a new historical round is registered for the right
Work; Work is never replaced on the existing round.

## Reread classification

Per user + Work, at most one successful round can be the first completed read.

Every later successfully completed round is a reread based on chronological completion/content finish date.

Start date does not determine reread status.

Stopped rounds are neither first completion nor reread completion.

When known completion dates overlap because their precision is limited, no
technical timestamp, ID or entry order invents a historical order. The
affected classification is explicitly chronologically indeterminate until the
content dates provide enough information.

# 7. Verlanglijst, Gewenste aanwinsten and Hierna lezen

## Verlanglijst

Private, user-owned and platform-wide. Library membership, role and Item
ownership do not own or scope it.

Every active entry targets exactly one existing Work and is exactly one of:

- Work-only: the user wants the book and the Edition is irrelevant;
- Edition-specific: the user wants one exact existing Edition of that Work.

For one user + Work there is at most one Work-only entry, or zero or more
Edition-specific entries for different Editions. The two forms are mutually
exclusive for that user + Work, and the same Edition can occur only once.
Repeating the exact add is idempotent and reuses the stable entry ID.

A Work-only intent can be explicitly refined to an Edition. Refinement is one
atomic change that preserves the entry ID and creation time. An
Edition-specific intent cannot be collapsed to Work-only implicitly: that is
a conflict and requires an explicit later product flow. Removal clears active
state and preserves the entry snapshot with one of the existing history
reasons: `Vervuld`, `Verwijderd` or `Gelezen en verwijderd`.

The minimum V2.001 behavior is add Work-only, add Edition-specific, list own
entries with Work/Edition distinction, title/authors and timestamps, refine
Work-only to Edition, and remove. The current optional entry design still
retains priority (`Topwens`, `Hoog`, `Normaal`, `Laag`; default `Normaal`),
personal Goal/context and note. WISH-CORE-01 does not implement those explicitly
out-of-scope fields. Grouping and manual reorder remain V2.002+.

WISH-UI-01 realizes this minimum as `/verlanglijst/`. General discovery adds
Work-only wishes through canonical Work search. A specific Edition can be
added or selected only from Book Detail, which already holds the authorized
canonical Work/Edition identity. Reverse collapse remains an explicit conflict
and never silently merges or removes Edition wishes.

A matching Library Item, Add Book, reading action, Collection or Hierna-lezen
change never silently fulfils, removes or mutates the personal Verlanglijst.

## Gewenste aanwinsten

Release classification: the active Library-owned Gewenste-aanwinsten feature
is V2.002+. MIG-01 must inventory V1 data and classify it as mapped,
preserved/deferred or quarantined; only a migration-proven minimal exception
may enter V2.001.

Shared Library-owned list.

Maximum one per Library.

Eigenaar manages by default.

Beheerder only with explicit permission.

Lid may receive explicit view-only access, never manage in the first active
feature version.

A matching Item added to the same Library may prompt fulfilment. Execution mismatch requires confirmation; never silently fulfil.

## Hierna lezen

Private, platform-wide and user-owned in v2.001. It is one manually ordered
list of separate planned future reading moments.

Every entry has a stable server-side entry ID, owner, exactly one Work,
position, created-at and optionally one preferred reading source. Entry ID is
the only planning identity. Same-Work, different-preference and completely
identical entries are all allowed.

Preferred source is either `library_item` or `external_loan`. It is optional,
mutable and removable, and is never a reservation, claim, authorization proof
or historical provenance. Item preference requires current collection-view
access and same-Work resolution; direct-use access is rechecked only when
reading starts. ExternalLoan preference requires actor ownership and same Work.
Loss or inaccessibility of the source never removes, retargets or reorders the
entry and does not by itself change list version.

User manually manages add, remove, Undo, preferred-source change/clear and full
order. New entries append. Real mutations increment the owner list version
exactly once; semantic no-ops do not.

After successful active ReadingRound start Core consumes at most one entry in
the same transaction. Start from a specific entry consumes that entry ID.
Other starts choose the first exact live-source match in saved order, otherwise
the first entry for the Work without preference; no match is a successful
no-op. Failed starts consume nothing. Historical/source-free registration never
consumes. Manual remove offers a short-lived, one-use, owner-scoped Undo of the
same entry identity and snapshot; automatic consumption offers no Undo.

## Wat zal ik lezen?

`Wat zal ik lezen?` is a private, user-owned, platform-wide choice aid,
available from Mijn Biblio and within each authorized Library context. It is
separate from the fully manual `Hierna lezen` list and never automatically
mutates planning, reading, collection or classification source data.

Its retained functional basis, three selection engines, `Kies uit…`
orchestration, source-context-first candidate semantics, explainability and
personal exclusions are fixed in
[`docs/40-what-shall-i-read-functional-design.md`](40-what-shall-i-read-functional-design.md).

D-WR-01 defers the active feature, ranking engine, recommendation API,
preference persistence, engines and UI to V2.002+. None is a V2.001 release
blocker. The separate, manual `Hierna lezen` flow remains V2.001-MUST.

# 8. Borrowed and lent

Release classification: the full V2 lending module is V2.002+. MIG-01 must
inventory existing V1 circulation data. If active/open loans exist at cutover,
their smallest safe preservation/settlement lifecycle is a separate product
decision and may become `V2.001 MINIMUM IF MIGRATION REQUIRES`. The design
below remains authoritative for later implementation; it is not itself a
V2.001 release journey.

## External borrowing

Private/user-owned.

Represents a concrete temporary physical source from a person or organization outside Biblio's internal Library loan.

Active external loan appears in:
- Mijn Biblio → Geleend;
- Leesvoorraad when that source has no active ReadingRound.

## Internal loan

One shared transaction with two perspectives:
- Library → Uitgeleend;
- borrower → Mijn Biblio → Geleend.

Eligible recipient in the first full lending implementation for a
Privébibliotheek:
- active membership with `Lenen`;
- or active membership with `Directe toegang`.

`Alleen bekijken` cannot receive an internal loan.

The transaction is created by:
- Eigenaar;
- Beheerder with lending permission.

A `Lenen` user cannot submit a loan request or create their own internal loan
in the first full lending implementation.

Formal requests/reservations/queues/renewals/fines are deferred.

## Reading and lending stay separate

A loan never starts or ends a ReadingRound automatically.

Starting a ReadingRound never creates a loan automatically.

For a direct-access user an explicit internal loan remains optional when temporary exclusive possession needs tracking.

## Privacy

Ordinary Library members see shared state `Uitgeleend`, not borrower identity.

Borrower sees their own loan details.

Eigenaar/authorized lending Beheerder sees full transaction details.

## Access changes

Lowering use access does not end an existing internal loan.

An active loan remains valid physical access until return/settlement.

A membership may end while loans remain active.

Former member:
- still sees their active loan in Mijn Biblio;
- may continue/start reading from that specific active loan source;
- gets no access to other Library Items;
- cannot receive new internal loans.

## Not returned / given away

Special settlement routes may close the active loan and archive the Item with the appropriate reason.

# 9. Archive

Archive is lifecycle for a previously active Library Item.

Item state:
- Active;
- Archived.

Multiple archive periods may be retained historically.

Reasons:
- Verkocht
- Weggegeven
- Gedoneerd
- Verloren
- Beschadigd/afgedankt
- Niet teruggebracht

These six values are the native V2 reason taxonomy for archive actions carried
out in V2. A historical or imported archive period whose original reason has
no explicitly approved meaning-equivalent mapping keeps that original reason
as a `preserved historical` reason instead. It has no native V2 reason and is
never guessed into `anders` or another approximately matching value. The
preserved original text is required, bounded plain text; an original source
code/value may accompany it when needed. Migration provenance remains outside
the Item lifecycle in the migration ledger.

Every archive period has exactly one reason form: native V2 or preserved
historical. The reason belongs to the concrete Item and period, never to its
Work, Edition or sibling Items. Archive state remains independent: an Item can
be archived with only a preserved historical reason, while such a reason never
archives an active Item by itself.

Ordinary archiving is blocked while an internal loan is active.

Special `Niet teruggebracht` / `Weggegeven` flows settle the loan first.

Archive is not hard-delete.

Preserve:
- Item identity;
- bibliographic links;
- acquisition;
- location/condition history;
- loan history;
- Collection history;
- audit.

Archiving never deletes private ReadingRounds, ratings, notes or goals and never automatically ends a private round.

Archived Item is not an available Leesvoorraad source.

Restore reuses the same Item.

Restore closes the current archive period without deleting its reason. A later
V2 re-archive creates a new period with the then-selected native V2 reason;
the earlier preserved historical reason remains unchanged in history.

Past active Collection memberships may be offered unchecked for explicit re-add, never silently restored.

Archive/restore never silently alters a frozen Collection-reading-goal snapshot.

# 10. Collections

A Collection is a manually curated Library-owned shelf of active physical Items inside one Library.

Allowed:
- active own Library Items.

Not allowed:
- Verlanglijst;
- Gewenste aanwinsten;
- external borrowed non-owned sources;
- ReadingRounds;
- private data;
- archived Items.

An Item may belong to multiple Collections.

In the Mijn Bibliotheek Collection filter:

- multiple active Collections may be selected;
- an Item matches when it belongs to at least one selected Collection;
- matches use OR within the Collection group and AND against other groups;
- an Item appears at most once;
- `Zonder collectie` is available and is exclusive with ordinary Collection
  selections;
- only active Collections are normal filter options; archived Collections are
  not normal active options.

No functional maximum unless technical limits require one later.

Name:
- required;
- max 80;
- unique among active Collections in the same Library after simple normalization.

Description:
- optional;
- max 300.

Manual Collection order and manual Item order.

Book Detail projects only the active Collection memberships of its concrete
Item in manual Collection order. It does not infer membership from the Work,
Edition, Series or another Item. The projection is Library-scoped; archived
Collections and inactive membership history remain absent from the active
detail read. `In collecties` is read-only and is omitted when the Item has no
active memberships.

Automatic cover can use up to first four active visible Items by saved order.

`Collectie beheren` is draft-state editing:
- changes are provisional;
- `Opslaan` commits;
- `Annuleren` restores;
- removal uses selection mode;
- no second confirmation is needed merely because a draft removal exists.

Archived Collection is read-only until restored.

## Collection reading goal

Release classification: active goal creation and management is V2.002+.
Existing V1 goal data must be inventoried and preserved or quarantined through
MIG-01; this future design does not make Collection goals a V2.001 blocker.

A private completion goal can snapshot a Library Collection.

At creation:
- current Items are transformed to unique Works;
- duplicate copies/editions of the same Work become one goal item;
- snapshot freezes.

Later Collection changes do not automatically alter the goal.

`Bijwerken vanuit collectie` explicitly compares current Work set and confirms changes.

Loss of Library access does not delete the personal goal snapshot.

# 11. Authors and Series

Release classification: Author/Series identities and relations required for
V1 mapping, catalog, Search and Book Detail remain V2.001-MUST. A separate
Authors/Series index/detail module and rich Series Intelligence are V2.002+.
The broader design below remains preserved without becoming release acceptance.

Auteur and Serie are central stable identities.

Library module:
`Bibliotheek → Auteurs & Series`
- Auteurs
- Series

An Author/Series appears in a Library index only when at least one active Item in that Library represents a related Work.

Personal Verlanglijst/history/external loans/minimal central Works do not make them appear in a Library index.

Only Auteur/Co-auteur gets Author detail in the first dedicated module version.

Other contributor roles remain structured metadata.

Library-scoped detail may include:
- In deze bibliotheek;
- Gewenste aanwinsten if authorized;
- Archief.

A private `Mijn leesgeschiedenis` section may appear for the current user.

## Series Intelligence model

The former universal book-role model `hoofddeel` / `novelle` / `companion` /
`spin-off` / `omnibus` is not the foundation. Series Intelligence is
multidimensional; membership, grouping, descriptive relation, ordering,
position, lifecycle and coverage answer different questions.

### Series kind and membership level

- **Work Series** groups Works as content identities.
- **Edition/Publisher Series** groups specific Editions as a publisher or
  imprint range.
- Item is not an intrinsic Series-membership level.

The existing schema-1009 Work→Series relation is the implemented minimal Work
Series foundation. Edition/Publisher Series and the richer dimensions below do
not silently expand the v2.001 runtime or schema; they require a separately
approved implementation decision.

### Confirmation layers and rights

Series Intelligence separates personal and Biblio-wide confirmation:

- `user-confirmed`: one authenticated user accepts a proposed membership,
  position or group for that user's own Biblio context;
- `user-rejected`: one authenticated user rejects a suggestion for that own
  context; the rejection is remembered so it is not repeatedly presented;
- `canonical-confirmed`: the relationship is sufficiently verified to form
  shared Biblio Series canon.

Every authenticated user may record `user-confirmed` and `user-rejected` for
themselves. A personal confirmation may influence that user's Series view,
Lens intelligence and later personal Series goals. It never changes global
canon, shared bibliographic data, another user's view or shared Library
completeness.

Library roles grant no extra global bibliographic authority. Eigenaar,
Beheerder and Lid (the old term `Lezer`) cannot create `canonical-confirmed`
truth merely through their Library role. In v2.001, canonical confirmation can
arise only through an explicit trusted Biblio-wide management/verification
process. Multiple users, confidence, external sources or community consensus
are evidence only and never promote automatically. Shared Library metrics use
only `canonical-confirmed` memberships. Personal-only intelligence remains
visibly distinct.

The exact canonical verification-management UX is open. Automatic promotion,
community moderation, voting and reputation remain future scope.

### Group and descriptive relation

`core` versus `supplemental` is a Series-specific canonical property of the
SeriesMembership, never a personal preference:

- **core:** the Work belongs to the primary canon/set that constitutes the
  Series itself;
- **supplemental:** the Work demonstrably belongs to the Series context but
  falls outside the primary canon and has a supporting role.

The Work form does not decide the group. A `novelle`, `short story`,
`companion`, `extra` or `tussentitel` can be core or supplemental for a
particular Series; `novelle` therefore never means supplemental automatically.
Numbering does not decide group either: `2.5` can be core and an unnumbered
companion can be supplemental. Personal preferences cannot alter this canonical
classification.

When evidence is insufficient or conflicting, group remains unresolved. This
is absence of confirmation, not a third content category; Biblio does not
fabricate certainty.

Proposals follow this evidence hierarchy:

1. official publisher/Author Series list;
2. reliable bibliographic source;
3. multiple consistent external sources;
4. user confirmation;
5. heuristic.

A heuristic alone can never establish canonical core/supplemental. External
metadata remains evidence/proposal, not automatic canonical truth.

Optional descriptive labels such as `novelle`, `tussentitel`, `companion`,
`short story` or `extra` may describe a relation. They do not determine group,
order or completeness.

### Order and position

Series order is one of:

- `numbered`;
- `ordered_unnumbered`;
- `unordered`.

A Series number is never mandatory. Position is a separate value that may be
numeric, fractional, textual, contextual or absent; position never determines
`core`/`supplemental`. v2.001 uses at most one `primary confirmed order` per
Series. It drives default presentation, gap positioning and labels such as
`volgend deel`.

The architecture must permit later publication, chronological/story and
recommended order schemes without duplicating the SeriesMembership: alternate
schemes assign different positions to the same Work. Choosing an order does not
change completeness when member scope is unchanged. Biblio never invents an
alternate order without sufficient evidence and confirmation. Multi-order
runtime/UI remains future scope.

### Series relationships and lifecycle

Series may relate to Series as `subseries`, `spin-off`, `successor`, `related`
or `shared universe`. A spin-off is therefore not by default a role of a book
inside its parent Series. Related Series do not count toward parent-Series
completeness.

Series lifecycle is `ongoing`, `completed` or `unknown`. A member release state
may later distinguish `released`, `announced`, `upcoming`, `unknown` and
`cancelled`. The effect of announced/upcoming Works on current coverage is
defined under completeness below.

### Completeness and coverage

**Hoofdreeks compleet** means all `canonical-confirmed`, relevant and already
released core members are content-covered within the selected Library.
Missing supplemental members do not make the Hoofdreeks incomplete.

**Volledige serie compleet** means all `canonical-confirmed`, relevant and
already released core plus supplemental members are content-covered within the
selected Library.

Unconfirmed memberships or unresolved core/supplemental classification are not
silently counted. Use reserved language such as `Alle momenteel bevestigde
kerntitels aanwezig.` Use `compleet` only when the confirmed canon supports the
claim. Announced/upcoming Works remain separate and do not break current
completeness, for example: `Alle 7 verschenen hoofdtitels aanwezig; 1 nieuw deel
aangekondigd.`

Series Intelligence never collapses these three metrics into one percentage:

1. **ownership / Bibliotheek coverage**;
2. **reading coverage**;
3. **acquisition gaps**.

A Work is content-covered in one Library when at least one active Item of an
Edition of that Work belongs to that Library. An active Item that is currently
lent out still counts while it remains active Library possession. Archived
Items do not count. Verlanglijst and Gewenste aanwinsten do not count. A
`canonical-confirmed`, relevant, already released member without content
coverage is an acquisition gap; wishlist/acquisition status may be shown but
does not close the gap. Announced future titles are not current acquisition
gaps.

Reading coverage is private, personal and platform-wide. A Work counts as read
after at least one owned completed/`uitgelezen` ReadingRound, independently of
possession or source.

### Omnibus and Edition representation

An omnibus remains a container Work with contained Works; it is not a Series
role. It content-covers every contained Work for Library coverage. A later view
may separately present content coverage and separate-Edition possession, for
example `Hoofdreeks: 7/7 inhoudelijk gedekt` and `Losse uitgaven: 5/7 aanwezig`.
An omnibus can therefore make the Series content-complete without a separate
Edition for every Work.

### Series scopes

Series views keep three scopes separate:

1. **Bibliotheek:** ownership coverage, completeness, acquisition gaps and
   Gewenste aanwinsten for exactly one authorized Library;
2. **persoonlijk cross-Library:** content availability across all Libraries the
   user may access, such as `In jouw Bibliotheken aanwezig: 7/7`;
3. **persoonlijk platformbreed:** the user's private reading coverage.

Cross-Library availability never changes completeness or gaps for an individual
Library. Acquisition gaps exist only per individual Library. Within a Library,
the UI may combine Library coverage with the current user's clearly separated
personal reading coverage. Mijn Biblio/Home may combine cross-Library
availability with personal reading coverage. Authorization is still evaluated
per Library, and private reading data is never shared through Library metrics.

### Derived views and personal targets

`Hoofdreeks`/`Kerntitels` and `Volledige serie` are derived views, not stored
member roles. They may compare the separate ownership, reading, confirmed-gap
and upcoming views above. There is no generic `Mijn serie` object and no single
combined Series percentage.

External Series metadata is suggestion/evidence only. It never silently mutates
canonical membership or core/supplemental classification, and the provider
benchmark does not support robust automatic Series Intelligence for v2.001.

A Series reading goal is user-owned and Library-independent.

Minimal Works created for a Series goal do not create Library-index presence.

# 12. Ratings, reviews and notes

Release classification: existing V1 ratings/reviews must be migrated, retained
and readable in V2.001, and Private Notes remain V2.001-MUST. New
Rating/Review create/edit/publish/withdraw/moderation flows are V2.002+.
The V1 migration reference contains a simple per-book write UI, so MIG-01 must
map and retain that data and record the post-cutover limitation explicitly;
D-SCOPE-01 nevertheless makes Review writing/publication non-blocking for the
first cutover.

All are user-owned.

## Rating

Work-level, optionally linked to one ReadingRound.

Multiple ratings through rereads are allowed.

Maximum one unlinked Rating per user + Work.

## Review

Independent of Rating.

Optional ReadingRound link.

Maximum one unlinked Review per user + Work.

## Visibility/publication

Each Rating/Review can remain private or be published independently.

Historical and current assessments use the same source-neutral user × Work
entities. `assessed_at` is the nullable business instant at which the user
actually assessed the Work; `created_at` and `updated_at` remain technical V2
record times. A historical assessment with unknown time keeps `assessed_at`
NULL. Import time and export time never fill that unknown value. A missing
ReadingRound relation likewise remains NULL; no last, active or migration-made
round is inferred.

Book Detail may show the current owner an explicitly separate list of their own
assessments that are not visibly published in the current Library. This owner
list is Work-scoped and server-authorized. A source with an active, visible
publication in that exact Library is omitted from the owner list to prevent a
double presentation. It remains private in every other Library unless it also
has an explicit publication there.

Private contribution requires no Library context.

Publication requires:
- user is active member of one explicitly chosen Library;
- Work is represented there by at least one active Item.

No automatic publication to multiple Libraries.

The contribution remains user-owned.

## Averages

Personal average uses the user's own valid ratings.

A Library public average uses only visible ratings published to that Library.
Private unpublished Ratings never contribute to that average.

## Moderation

Eigenaar/authorized Beheerder may hide/delete a public contribution in the publication Library.

They never edit another user's content.

Moderation may store a reason.

Leaving the Library does not automatically delete historical publication context.

If no active Item of the Work remains, the publication may disappear from active-book context and later reappear when the Work returns.

## Notes

Always private.

Multiple notes per Work.

Optional ReadingRound link.

No Library role grants access to another user's notes.

# 13. Reading goals

Release classification: active Reading Goals are V2.002+. Existing V1 goal
data must be inventoried and classified by MIG-01; no silent discard is
permitted. The design below is retained for later activation.

Private/user-owned.

Types:
- count goals;
- completion goals.

## Count goals

Always time-bound:
- calendar year;
- custom period.

Default source:
`Alle gelezen boeken`.

Optional:
`Alleen boeken uit [one Library]`.

For a Library-scoped count goal, the actual ReadingRound source must be tied to that Library. Mere Work presence in that Library is insufficient.

Rereads configurable:
- include: every qualifying successful round;
- exclude: only first-ever successful completion per Work counts.

## Completion goals

Source:
- Serie;
- Collection;
- manual Works.

Variants:
- all goal books from now/again;
- only books never read before goal start.

### Serie
Library-independent central Series.

The existing Series completion goal is the personal, platform-wide reading-goal
direction. Progress comes only from completed/`uitgelezen` ReadingRounds and is
independent of possession.

The separately deferred Series collection-goal direction belongs to exactly one
Library and measures Work-level content coverage. It is not implemented merely
by documenting it.

Both goal directions may target:

- Hoofdreeks (`core`);
- Volledige serie (`core` + `supplemental`);
- a custom selection of confirmed members.

A custom selection is a personal target set and never changes Series canon.
The final Dutch UI names for these two goal types remain open.

User reviews/confirms known Works for personal goal selection and may create
missing minimal Works without promoting them to canonical-confirmed membership.

The target set freezes at creation. New canonical-confirmed members never alter
an existing goal automatically. Biblio may signal `Er zijn nieuwe bevestigde
serieleden beschikbaar.` and offer the explicit action `Doel bijwerken vanuit
serie`. Ownership and reading progress remain separate measurements; there is
no generic combined percentage.

### Collection
Based on one concrete Library Collection.

Unique Work snapshot.

Explicit update from Collection only.

### Manual
User-chosen Work set independent of possession.

## Progress

Derived from successful ReadingRounds.

No manual progress correction.

One ReadingRound may feed multiple goals.

Time-bound progress uses content completion date and preserved precision.

## Lifecycle

- Actief
- Afgerond
- Gestopt

Active progress:
- Nog niet behaald
- Behaald

Completed outcome:
- Behaald
- Niet behaald

A time-bound goal reaching target early remains active and may exceed target.

Open-ended completion goal can finish immediately when complete.

Maximum one active `Uitgelicht` goal.

Seven-day deadline signal is fixed Biblio behavior, not a user preference.

# 14. Personal insights

Release classification: active personal Stats, Jaaroverzicht and Tijdlijn are
V2.002+. MIG-01 inventories and preserves relevant V1 source data where
necessary. The design below remains valid future product design.

No standalone Profiel module.

Private/user-owned:
- Statistieken
- Jaaroverzicht
- Tijdlijn

## Statistics

Default scope:
`Alles`.

Optional one active or former Library as personal historical scope.

Read scope uses actual ReadingRound source.

Periods:
- Dit jaar
- Vorig jaar
- Afgelopen 12 maanden
- Alles
- Zelfgekozen

Primary metrics:
- Uitgelezen leesrondes;
- Unieke boeken;
- Herlezingen.

Monthly chart only assigns sufficiently precise completion dates.

Top Authors:
- Auteur/Co-auteur only;
- primary completed-round count;
- secondary unique Works.

Top Series analogous.

Personal borrowing statistics include new loan periods where current user is borrower.

Library-independent personal cards are hidden rather than shown as zero inside a specific Library personal scope when the metric has no meaningful Library mapping.

## Jaaroverzicht

Personal, one calendar year, dynamic.

Uses same personal scope principles.

May include highest-rated Works and other personal activity.

No year-over-year comparison in the first active feature version.

## Tijdlijn

Personal meaningful chronology, not Library audit.

Categories:
- Lezen
- Geleend
- Verlanglijst
- Beoordelingen
- Leesdoelen

Shared Library-management actions are excluded.

Notes never appear.

Content event date preferred; technical registration fallback.

Source corrections move the derived event rather than creating a correction event.

Deleting the user-owned source removes its derived timeline event.

Historical Library context may remain after membership loss without restoring access to protected Library records.

# 15. Biblio Home, Bibliotheek Home and search

Release classification: V2.001 requires only the shell/navigation necessary to
reach every release flow normally. Rich Biblio Home and Library Home/dashboard
behavior is V2.002+. Search inside Mijn Bibliotheek remains V2.001-MUST.

The current information architecture distinguishes three levels:

1. `Biblio Home` is the platform-level entry outside one active Library
   Context. It exposes accessible Libraries, opening and switching Libraries,
   and optionally light platform-wide personal context. It is not a catalog or
   a Library Home. Without a selected Library, the complete Library shell and
   sidebar are not presented as active.
2. `Bibliotheek Home` is the existing Home / Action Center inside one active
   Library Context. It is personal, selective, action-oriented and
   discovery-oriented; it deliberately does not enumerate the full catalog.
3. `Mijn Bibliotheek` is the complete active catalog inside that Library. It
   is the functional, calm, scannable and information-dense destination for
   all active Library Items and supported Search, Filters, Sort, Grid, List,
   Bookshelf, active filter chips, cursor/load-more, Quick View and Item
   management.

Opening a Library from Biblio Home establishes the selected target from which
Core constructs and authorizes the active Library Context. Inside that context,
`Home` and `Mijn Bibliotheek` are separate primary navigation destinations:

```text
Home                → Bibliotheek Home / Action Center
Mijn Bibliotheek    → complete active catalog
Collecties
Lezen
…
```

Older mockups or labels that put the Home modules below a `Mijn Bibliotheek`
heading are retained only as historical design context. They do not define the
current IA.

## Biblio Home

Biblio Home provides the platform-level Library entry and switcher described
above. Its platform-wide personal context stays light and does not replace the
Library-bound Action Center or catalog.

## Mijn bibliotheken

Active memberships only.

Max three directly visible; then `Alle bibliotheken (x)`.

Functionally role and use access are available, e.g.:
- Lid · Lenen
- Beheerder · Alleen bekijken
- Eigenaar · Directe toegang

The list is resolved for the authenticated actor on the server. It contains
the stable Library ID, name, type, active designated-personal marker and
server-calculated capabilities. A selected Library ID is only a target: Core
rebuilds and validates Library Context from that ID plus the authenticated
actor and never trusts page, cookie, session or form context.

Fixed action:
`Bibliotheek maken`.

Create flow visibly shows:
- Privébibliotheek — selectable;
- Uitleenbibliotheek — disabled/grey, future version.

Existing Library shows read-only `Type: Privébibliotheek`.

## Bibliotheek Home / Action Center

Bibliotheek Home is the modular start page inside the active Library Context.
It may be visually expressive and inviting, but remains an action center rather
than a heavy dashboard.

Its fixed entry/action elements are the local Search and `Home aanpassen`.
`Mijn bibliotheken` belongs to Biblio Home; Library switching from within an
active context must preserve the same server-authorized Library Context
contract rather than merging the two Home levels.

## Default Home modules

On:
1. Nu aan het lezen — Groot
2. Openstaande acties
3. Hierna lezen — Compact
4. Leesdoelen — Compact
5. Leesvoorraad — Compact
6. Geleend — Compact

Off:
- Verlanglijst
- Recente activiteit
- Statistieken
- Snelle acties

## Nu aan het lezen

Shows active ReadingRounds, not unique Works.

Different active rounds for the same Work appear separately with source context.

Big max 3. Compact may max 1.

## Openstaande acties

Only actual actionable personal signals.

Zero actions → module occupies no Home space.

Relevant loan due dates may appear from seven days before due date; overdue remains until settlement.

No personal setting for this threshold.

## Hierna lezen

Shows first max three manual entries in saved order.

No availability filter or automatic reorder. Successful active ReadingRound
start may already have transactionally consumed at most one matching entry.

## Leesdoelen

Shows the one active highlighted goal.

No random fallback.

No active goals → create-goal empty state.

Deadline warning primarily belongs to Openstaande acties.

## Leesvoorraad

Private view of concrete sources the user can use now and that have no active ReadingRound on that exact source.

Includes:
- direct-access available Library Items;
- active internal loans to user;
- active external loans.

Past reads do not exclude sources.

Same administratively available Item may appear for multiple direct-access users.

Home summarizes per source context.

## Geleend

Private, platform-wide active loans:
- internal;
- external.

Home max 3.

## Verlanglijst

Default off.

Active items max 3.

Sort:
1. priority;
2. most recently added.

## Recente activiteit

Default off.

Projection of personal Tijdlijn.

Compact max 3; optional larger max 5.

## Statistieken

Default off.

Compact projection of personal stats, default `Dit jaar`:
- completed rounds;
- unique books;
- rereads.

## Snelle acties

Default off.

Personal launcher, max four chosen actions.

No business logic of its own.

Never guesses Library context for Library-bound operations.

## Home aanpassen

Only place for Home module configuration.

Supports:
- on/off;
- manual order;
- supported size variant;
- Quick Actions choice;
- reset to default;
- save/cancel.

No duplicate Home settings under Mijn voorkeuren.

## Mijn Biblio search

Personal Biblio-wide search across accessible/relevant data.

Can find from:
- active accessible Libraries;
- personal reading history;
- Geleend;
- Verlanglijst;
- Leesvoorraad;
- accessible personal historical context.

After membership ends, the former Library's collection/archive is not searchable. Personal historical records remain searchable and may mention historical Library context.

Search fields:
- title/alternative title;
- Auteur/Co-auteur;
- Serie;
- ISBN.

No global full-text over Notes, review text, Collection descriptions or free-form management text.

## Library search

Strict current-Library scope.

Also supports inventory number.

Archive search is temporary and explicit.

## Result model

Physical sources/Items are never deduplicated away.

Work may visually group results, but every matching concrete copy/source remains visible and reachable.

Mijn Biblio may group:
- Item in Library A;
- Item in Library B;
- external loan;
- personal Work context.

Set can be its own result only through own Set metadata.

## Ranking

1. exact title/ISBN;
2. title;
3. Auteur/Co-auteur;
4. Serie;
5. other valid context-specific match.

Within equal relevance: alphabetical title.

General query minimum 2 characters; exact identifiers exempt.

## No results

Mijn Biblio:
`Geen resultaten in Mijn Biblio`.

`Boek toevoegen` only when a valid target Library exists.

`Boek toevoegen` always means adding a physical Item to a concrete Library.

If multiple valid target Libraries exist, choose target first.

Library search:
`Geen resultaten in deze bibliotheek`.

Add action only for sufficiently authorized user.

Search never silently invokes an external metadata search.

No recent-search history in v2.001.

# 16. Activity log and relationships

## Library audit

Release classification: an active Audit UI is V2.002+. Security, traceability
and integrity rules remain binding, and MIG-01 preserves relevant V1 source
data; the full UI below is not V2.001 release acceptance.

`Bibliotheek → Activiteitenlog` is a shared Library-audit function.

Visible:
- Eigenaar;
- Beheerder only for audit domains covered by their current relevant management permissions.

Ordinary Lid never sees Library audit.

Private user data never appears in Library audit.

## Central audit page and contextual views

Every Privébibliotheek has a central Activiteitenlog.

A Library Item, internal loan, Collection or membership may expose a contextual `Activiteiten`/`Geschiedenis` view when useful.

That view is a filtered projection of the same central ActivityEvents, never a duplicate log system.

## Logged events

Meaningful mutations of shared Library data, e.g.:
- Item add/edit;
- Location/Condition;
- metadata/cover actions when Library-owned or auditable;
- archive/restore;
- internal loan/return/not-returned;
- Collections and membership;
- Gewenste aanwinsten;
- role/use-access/permission changes;
- Library settings.

Personal ReadingRounds, personal ratings/reviews/notes, Verlanglijst and other private data are excluded.

## ActivityEvent rules

One meaningful action normally produces one main event with structured changes/substeps.

Independent lifecycle actions remain independent events.

Do not log:
- viewing;
- search;
- sorting/filtering;
- tab navigation.

A record has:
- one primary entity;
- zero/more related entities;
- actor;
- source;
- event key/type;
- structured changes;
- historical snapshots sufficient for readability.

Actor is visible in shared audit and historically stable.

System actions use `Biblio`.

Long/free-text values are not automatically stored in full old/new form.

Events are immutable to users.

Corrections happen through source data and may create a new event.

No functional automatic expiry in the first active Audit version.

No audit export button in the first active Audit version.

## Relationships

No separate `Koppelingen` tab in v2.001.

Existing domain relationships remain real domain relationships, including:
- Work–Edition–Item;
- Series;
- Set composition;
- Collection membership;
- loans;
- ReadingRound source;
- Archive state.

No generic duplicate Relationship records for these.

A future explicit Relationship management layer (manual link/confirm/ignore/etc.) is deferred.

Relationship visibility always respects original Library/user scope.

Derived relationship visibility alone does not generate ActivityEvents.

# 17. Settings and administration

Release classification: extensive platform, membership, delegated-permission
and Librarian administration is V2.002+. Only the smallest safe installation,
account/membership and recovery operation proven necessary by MIG-01 can be
`V2.001 MINIMUM IF MIGRATION REQUIRES`. The permission boundaries below remain
security constraints where their implementation exists.

One top-level `Instellingen`, with visible parts according to role/right:
- Mijn voorkeuren;
- Bibliotheekbeheer;
- Platformbeheer.

Empty/future settings are not shown.

## Mijn voorkeuren

`Deze bibliotheek` always identifies the current Library.

Concrete v2.001 Library-specific personal preferences:
- Standaardweergave;
- Archief tonen.

The effective `Standaardweergave` determines the initial Mijn Bibliotheek view.
Using the ordinary view switch does not silently rewrite that preference; a
permanent change follows the explicit settings save behavior below.

Home configuration is not duplicated here.

When `Wat zal ik lezen?` is implemented in V2.002+, `Algemeen` is visible when
it contains the concrete platform-wide suggestion preferences defined for that
feature: per selection engine excluded Boeksoorten and Genres. Those
preferences are private and user-owned; they are not Library defaults or
Library-managed settings.

## Library defaults

Only concrete v2.001 Library default:
`Bibliotheek → Standaardweergave`.

Fallback:
1. personal preference;
2. Library default;
3. Biblio/platform fallback.

`Archief tonen` is personal only, platform default off.

## Bibliotheekbeheer

Always scoped to one current Privébibliotheek.

No multi-Library administration dashboard.

Sections:
- Algemeen;
- Leden & toegang;
- Beheerdersrechten;
- Bibliotheekdefaults;
- Locaties;
- Metadata & classificatie;
- Supporttoegang.

Activiteitenlog remains a separate Library function.

Eigenaar sees all.

Beheerder sees only sections covered by explicit permissions.

Lid sees no Bibliotheekbeheer.

### Algemeen

Library name can be changed by:
- Eigenaar;
- Beheerder with explicit general-Library-settings permission.

Rename does not change identity/memberships/history and creates audit.

No ordinary Library hard-delete in v2.001.

No whole-Library deactivate lifecycle in v2.001.

Ownership transfer lives under Leden & toegang.

### Support access

Controlled by Eigenaar.

Default:
`Geen toegang`.

Levels:
- Geen;
- Bekijken;
- Beheren.

Does not grant access to private user-owned data.

Changes are audited.

### Metadata & classificatie

Manages local:
- Boeksoort;
- Genre;
- Onderwerp;
- supported additional Editietypes;
- explicitly designed local metadata mappings.

Does not make ISBN/language/publisher general settings.

### Locations

Part of shared Item management.

No separate micro-permission required.

## Platformbeheer

Modules in the retained full administration design:
- Gebruikers;
- Bibliotheken;
- Admins & platformrechten;
- Recovery.

Super admin sees all.

Admin sees only delegated platform modules.

### Gebruikers

Account/membership administration only.

No access to private reading content.

Can create platform account and initial safe membership when authorized.

### Bibliotheken

Administrative metadata only, e.g.:
- ID;
- name;
- type;
- Eigenaar;
- necessary technical/status info.

No automatic access to catalog/Collections/loan content.

Support content access requires explicit Supporttoegang.

### Admins & platformrechten

Only Super admin can create/deactivate Admins and assign/revoke platform
permissions in the first full administration implementation.

Admins cannot modify their own permissions or manage other Admins.

### Recovery

Exceptional integrity/access repair, primarily Super admin.

Not a normal management path.

Recovery must be traceable with:
- actor;
- reason;
- change.

Avoid opening private content where possible.

## Save behavior

Multi-field settings use explicit:
- Opslaan;
- Annuleren.

Standalone lifecycle/management actions remain explicit immediate actions rather than hiding under one global save button.

# 18. General functional rules

## Authorization

Server/domain authorization is mandatory.

UI visibility never substitutes for authorization.

Every operation checks relevant:
- user;
- scope;
- membership;
- role;
- use access;
- additional permission.

## Scope separation

Library-scoped data requires explicit Library Context.

User-owned data requires authenticated ownership.

A Library reference on private data does not change ownership.

## Derived state

Derived states should come from source records where functionally appropriate:
- personal reading status;
- Leesvoorraad;
- statistics;
- goal progress.

Technical caching/projections may exist but cannot become a second product truth.

## No unexpected mutation

Biblio may signal, derive and propose, but does not silently mutate important related data unless the behavior is explicitly designed.

Examples:
- Collection changes do not auto-change a goal snapshot;
- matching Item does not auto-fulfil personal Verlanglijst;
- use-access change does not end a ReadingRound;
- Start Reading does not create a loan.

## Historical truth

Preserve known historical values and date precision.

Do not invent unknown source/day/month.

Technical timestamps may coexist separately.

## Archive over deletion

For shared records with meaningful history prefer lifecycle/archive.

Hard-delete only where explicitly designed and safe.

## Confirmations

Risk-based.

Draft changes committed by Opslaan do not need redundant second confirmation.

Critical irreversible/authorization actions may use stronger confirmation.

## Integrity

Invalid domain transitions are blocked with a clear reason.

Never silently mutate unrelated records merely to force success.

## Concurrency

Two administrators' overlapping writes must not silently overwrite one another.

On conflict, show current state and require intentional retry/review.

Exact technical locking/versioning later.

## Atomicity

A failed compound operation leaves no half-valid domain state.

Either all required mutations succeed or the prior valid state remains.

## Forms

Only show context-relevant fields.

Hidden fields do not silently submit stale/invalid values.

## Empty states

Explain why empty and show only actions the current user may actually perform.

## Filters and sorting

Predictable module-specific default order.

Filters alter view only, not underlying data.

Temporary filters are not automatically persisted as preferences unless explicitly designed.

A temporary filter, sort or view choice does not silently change a personal
preference or Library default. Permanent defaults change only through an
explicit settings flow. Search/filter/sort state is represented in an
allowlisted reproducible URL. Browser history restores that state. When the URL
contains no explicit query state, temporary state may be remembered for the
current authenticated user + Library + module session.

## Large lists

Pagination/lazy load/Meer laden may be used without changing functional sorting/filtering/selection/authorization semantics.

## Responsive behavior

Mobile/tablet/desktop may change presentation, not functional data/actions/rights/meaning.

# 19. Sources and references

Chapter 1–18 is current functional truth.

Source traceability is maintained in `05-source-register.md`.

Historical sources are preserved and can remain partially valid.

A source is not discarded wholesale merely because one section is superseded.

Duplicate source copies do not gain authority by duplication.

New product decisions belong first in the relevant canonical chapter, then in the source register.
