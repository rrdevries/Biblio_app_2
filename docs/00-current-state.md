# 00 — Current state

Status: canonical working state for Biblio V2 / v2.001.

## Product model

Biblio is one application. Every platform user has one private, platform-wide environment: `Mijn Biblio`.

A `Bibliotheek` is an internal tenant/domain entity inside the same WordPress site. It is not a WordPress Multisite site.

A user can initially belong to zero Libraries.

For v2.001 we assume every user has at most one designated own/personal `Privébibliotheek`. If the user performs their first relevant reading or borrowing action and does not yet have that personal Library, Biblio creates it automatically. The user becomes `Eigenaar` with `Directe toegang`.

This personal Privébibliotheek is an ownership/authorization anchor for the user's own collection context; it does not own private ReadingRounds, external loans or other Mijn Biblio data.

The same user can additionally belong to, manage or own other Privébibliotheken.

v2.001 supports only physical books.

## Scope layers

### Platform

Owns:
- Biblio user accounts;
- central bibliographic identity;
- platform administration and recovery.

### Bibliotheek

Owns:
- physical collection and Items;
- local catalog context;
- locations;
- collections;
- desired acquisitions;
- archive;
- internal lending;
- library settings;
- library audit.

### Mijn Biblio / user

Owns private:
- ReadingRounds;
- Reading inventory view (`Leesvoorraad`);
- external borrowing;
- reading goals;
- wishlist (`Verlanglijst`);
- `Hierna lezen`;
- ratings/reviews;
- notes;
- personal statistics/year overview/timeline;
- Home configuration and personal preferences.

## Library types

Conceptually:
- `Privébibliotheek`
- `Uitleenbibliotheek`

Only `Privébibliotheek` can be selected in v2.001. `Uitleenbibliotheek` is shown disabled as future functionality.

A v2.001 Library cannot change type.

## Membership

Membership has two independent dimensions.

### Beheerrol
- Eigenaar
- Beheerder
- Lid

### Gebruikstoegang
- Directe toegang
- Lenen
- Alleen bekijken

For a v2.001 Privébibliotheek the Eigenaar always has `Directe toegang`.

A non-owner membership defaults to:
- `Lid`
- `Alleen bekijken`
- `Actief`

A `Beheerder` has baseline rights to manage the shared catalog/books and physical Exemplaren in the current Bibliotheek. Other management domains require explicit additional permissions.

Additional management permissions are explicit and only active while the user is a `Beheerder`.

## Accounts

Only Platform `Super admin`, or an `Admin` with explicit platform `Gebruikersbeheer`, creates platform-wide Mijn Biblio accounts in v2.001.

A newly created account may temporarily have no Library.

When that user first performs a relevant reading or borrowing action, Biblio automatically creates the user's one designated personal Privébibliotheek if it does not yet exist. The user becomes:
- Eigenaar
- Directe toegang

A user may still create or own additional Privébibliotheken for other shared collections where the product flow permits this.

Adding that user to another existing Library initially happens through Platformbeheer. The existing platform account is reused.

## Bibliographic model

Platform-wide identity:
- Work
- Edition
- Auteur
- Serie

Library-local:
- LibraryCatalogContext
- Item/Exemplaar
- local Boeksoort / Genre / Onderwerp
- acquisition, location, condition, archive and lending state

User-owned:
- ReadingRound and all private reading activity.

Central identity does not mean unrestricted editing. When a central record is used by multiple Libraries, ordinary Library administrators propose corrections instead of directly changing the shared record.

## Reading

A new active ReadingRound always has:
- one user;
- one Work;
- exactly one concrete physical source.

There is at most one active ReadingRound per user + concrete source.

Multiple simultaneous ReadingRounds for the same Work are allowed when they use different physical sources.

Valid active v2.001 sources:
- Library Item available through `Directe toegang`;
- Item currently internally lent to the user;
- active external loan.

Historical closed ReadingRounds may have an unknown source if it is genuinely no longer known.

`Mijn leesstatus` is user × Work:
- at least one active round → Aan het lezen;
- else at least one completed successful round → Uitgelezen;
- else → Niet gelezen.

## Leesvoorraad

`Leesvoorraad` is a user-specific view of concrete physical sources the user may use now and that do not have an active ReadingRound on that exact source.

Past reading history does not remove a source from inventory.

An administratively available Item may simultaneously appear in multiple direct-access users' inventories because direct physical use does not create a checkout automatically.

## Hierna lezen

A fully manual private list in v2.001.

Entries can be:
- a Work;
- a specific concrete Item/source.

No automatic removal, availability rule, lending rule or ReadingRound rule modifies the list.

## Lending

`Directe toegang` allows direct use without a loan, but an explicit internal loan may still be recorded.

`Lenen` requires an internal loan before the Library Item becomes the user's physical reading source.

`Alleen bekijken` allows collection viewing/searching but neither direct use nor receiving an internal loan.

No loan request/reservation workflow in v2.001.

## Home

Home is the modular start page inside Mijn Biblio.

Fixed:
- Zoeken
- Mijn bibliotheken
- Home aanpassen

Default modules on:
1. Nu aan het lezen
2. Openstaande acties
3. Hierna lezen
4. Leesdoelen
5. Leesvoorraad
6. Geleend

Default off:
- Verlanglijst
- Recente activiteit
- Statistieken
- Snelle acties

## Audit

`Bibliotheek → Activiteitenlog` is a Library audit function for Eigenaar and authorized Beheerders, not a general member feature.

Personal meaningful history belongs in `Mijn Biblio → Tijdlijn`.

## Technical architecture

One WordPress site with:
- WordPress infrastructure;
- Biblio Core;
- Crocoblock/JetEngine where suitable;
- Elementor Pro for presentation;
- custom PHP/JS where integrity or interaction requires it.

Persistence mapping remains open until the technical spike.
