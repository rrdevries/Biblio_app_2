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

## Beheerrol
Administrative membership role:
- Eigenaar
- Beheerder
- Lid

Supersedes the old Library role name `Lezer`.

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
May view/search Library collection, but cannot directly use or receive an internal loan.

## Work
Platform-wide identity of the intellectual/content work.

Dutch UI wording may use `Werk` where appropriate; canonical data concept remains Work.

## Edition
Platform-wide identity of a publication edition.

Dutch UI: `Uitgave`.

## Item / Exemplaar
Concrete physical copy belonging to exactly one Library.

UI term: `Exemplaar`.

## LibraryCatalogContext
Library-local catalog context attached to central Work/Edition identity, including local Boeksoort/Genre/Onderwerp and later explicitly designed local metadata.

## Auteur
Central identity used for Auteur/Co-auteur relationships.

## Serie
Central Series identity.

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

Supersedes old generic `Wishlist` terminology.

## Gewenste aanwinsten
Shared Library-owned list of books/editions the Library wants to acquire.

## Hierna lezen
Private platform-wide manual list of Work entries and/or specific source entries.

Supersedes `Next to read`.

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
Lightweight proposal to Platformbeheer to change central bibliographic metadata once the record is shared by multiple Libraries.

## Superseded terms/concepts

Not current v2.001:
- WordPress Multisite as tenant model;
- Library role `Lezer`;
- generic `Wishlist`;
- `Next to read`;
- `Profiel` as standalone personal environment;
- `Gepauzeerd` ReadingRound state;
- `Wil ik lezen`;
- media form/drager model for digital media in v2.001.
