# 01 — Functional design

Status: canonical functional design for Biblio V2 v2.001.

This document states the latest approved product behavior. Historical handovers can contain superseded models such as WordPress Multisite, the role `Lezer`, `Wishlist`, `Next to read`, `Gepauzeerd`, mandatory Library context for every ReadingRound, and the old fixed Home model. Those historical variants are not current behavior unless explicitly retained here.

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

Super admin has platform recovery responsibilities but does not receive automatic access to private Library/user content.

Admin is delegated through explicit platform permissions.

`Gebruikersbeheer` is an explicit platform permission. Super admin has it; Admin only when assigned.

## Platform accounts

Only Platformbeheer creates new Mijn Biblio accounts in v2.001.

One person uses one platform-wide account across all Libraries.

A newly created platform account may temporarily have no Library.

For v2.001, Biblio automatically creates the user's one designated personal Privébibliotheek on the first relevant reading or borrowing action when that Library does not yet exist. That action creates the membership as:
- Eigenaar;
- Directe toegang.

The automatically provisioned Library is named `Mijn Bibliotheek`. Library
names are required, trimmed, whitespace-normalized UTF-8 text of at most 191
characters and are not globally unique. Existing supported Libraries without
a usable name receive `Mijn Bibliotheek` through the formal F2.10 migration.

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
- cannot directly use a Library Item as personal source;
- cannot receive an internal loan.

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
- title and known alternative titles;
- Author/Co-author relationships;
- original language(s);
- Series/content relationships;
- omnibus content relationships;
- audience where defined at Work level.

### Edition
Specific publication edition.

Central/platform-wide.

Includes:
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
- completeness/deviations;
- archive/lifecycle;
- lending state.

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

## Publication date

Edition-level and precision-preserving:
- year;
- year + month;
- full date.

Biblio never fabricates a day/month.

## Covers

Edition may have multiple cover images and one Primary cover.

If no Primary exists, a reliable external image may become Primary.

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

Only Auteur/Co-auteur drive Author detail pages in v2.001.

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

When a central Work/Edition is used by only one Library, an authorized administrator of that Library may directly correct ordinary bibliographic fields.

Once the central record is used by multiple Libraries:
- no one Library owns it;
- a Library administrator uses `Correctie voorstellen`;
- proposal contains proposed value and optional explanation;
- Platformbeheer can approve/reject in a lightweight workflow.

Structural actions such as merge/split of Works, identity merging of Authors or major Series restructuring remain platform/bibliographic administration.

Direct central changes receive central bibliographic audit.

Local Boeksoort/Genre/Onderwerp remain directly managed by the Library.

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

## Search inside a Library

Strictly scoped to the current Library.

Searchable via:
- Work title / alternative title;
- Auteur/Co-auteur;
- Serie;
- ISBN relevant to Library Items;
- inventory number.

Default search includes only active Items.

Temporary `Ook in archief zoeken`:
- applies only to the current search-page session;
- does not change the personal `Archief tonen` preference;
- resets on refresh/navigation.

Archived hits are clearly marked.

Multiple matching Items are all visible. Work may group for readability but does not deduplicate physical copies.

## Library statistics

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

## Valid active physical sources in v2.001

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
3. else → `Niet gelezen`.

A stopped reread does not erase previous read completion.

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

Private, user-owned, platform-wide.

Exactly one personal list per user.

Entry may contain:
- Work;
- desired execution;
- optional Edition/ISBN;
- priority;
- personal Goal/context;
- note.

Priority:
- Topwens
- Hoog
- Normaal
- Laag

Default: Normaal.

History reasons:
- Vervuld
- Verwijderd
- Gelezen en verwijderd

A matching Library Item never silently fulfils the personal Verlanglijst.

## Gewenste aanwinsten

Shared Library-owned list.

Maximum one per Library.

Eigenaar manages by default.

Beheerder only with explicit permission.

Lid may receive explicit view-only access, never manage in v2.001.

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

# 8. Borrowed and lent

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

Eligible recipient in a v2.001 Privébibliotheek:
- active membership with `Lenen`;
- or active membership with `Directe toegang`.

`Alleen bekijken` cannot receive an internal loan.

The transaction is created by:
- Eigenaar;
- Beheerder with lending permission.

A `Lenen` user cannot submit a loan request or create their own internal loan in v2.001.

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

No functional maximum unless technical limits require one later.

Name:
- required;
- max 80;
- unique among active Collections in the same Library after simple normalization.

Description:
- optional;
- max 300.

Manual Collection order and manual Item order.

Automatic cover can use up to first four active visible Items by saved order.

`Collectie beheren` is draft-state editing:
- changes are provisional;
- `Opslaan` commits;
- `Annuleren` restores;
- removal uses selection mode;
- no second confirmation is needed merely because a draft removal exists.

Archived Collection is read-only until restored.

## Collection reading goal

A private completion goal can snapshot a Library Collection.

At creation:
- current Items are transformed to unique Works;
- duplicate copies/editions of the same Work become one goal item;
- snapshot freezes.

Later Collection changes do not automatically alter the goal.

`Bijwerken vanuit collectie` explicitly compares current Work set and confirms changes.

Loss of Library access does not delete the personal goal snapshot.

# 11. Authors and Series

Auteur and Serie are central stable identities.

Library module:
`Bibliotheek → Auteurs & Series`
- Auteurs
- Series

An Author/Series appears in a Library index only when at least one active Item in that Library represents a related Work.

Personal wishlist/history/external loans/minimal central Works do not make them appear in a Library index.

Only Auteur/Co-auteur gets Author detail in v2.001.

Other contributor roles remain structured metadata.

Library-scoped detail may include:
- In deze bibliotheek;
- Gewenste aanwinsten if authorized;
- Archief.

A private `Mijn leesgeschiedenis` section may appear for the current user.

Series combines loose Works and omnibus representation without falsely claiming external completeness.

A Series reading goal is user-owned and Library-independent.

Minimal Works created for a Series goal do not create Library-index presence.

# 12. Ratings, reviews and notes

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

Private contribution requires no Library context.

Publication requires:
- user is active member of one explicitly chosen Library;
- Work is represented there by at least one active Item.

No automatic publication to multiple Libraries.

The contribution remains user-owned.

## Averages

Personal average uses the user's own valid ratings.

A Library public average uses only visible ratings published to that Library.

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

User reviews/confirms known Works and may create missing minimal Works.

Snapshot freezes. No automatic Series sync.

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

No year-over-year comparison in v2.001.

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

# 15. Home and search

Home is the modular start page inside Mijn Biblio.

## Fixed elements

Always:
- Zoeken;
- Mijn bibliotheken;
- Home aanpassen.

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

No functional automatic expiry in v2.001.

No audit export button in v2.001.

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

Home configuration is not duplicated here.

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

v2.001 modules:
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

Only Super admin can create/deactivate Admins and assign/revoke platform permissions in v2.001.

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
