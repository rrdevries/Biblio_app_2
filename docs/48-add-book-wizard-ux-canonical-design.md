# 48 — Add Book Wizard UX canonical design

Status: **DECISION CANONICALIZED / NOT IMPLEMENTED**

Date: 2026-09-06

## Purpose and boundary

This document records the approved UX for the single Add Book Wizard. It
implements no production code, schema, REST contract, Elementor configuration
or UI. It does not change Metadata Hub rules, Librarian governance, provider
selection/fusion, Work-match behavior, collector-detail design or Book Detail.

ADR-014 and MH-B5A/MH-B5B remain the authoritative server-side contracts. This
document supplies their user-facing wizard design only.

## 1. One wizard and its start

There is one Add Book Wizard. Metadata lookup, manual entry and existing
Edition reuse are paths within that flow; there is no parallel manual wizard.

The start supports ISBN scanning, manual ISBN entry and the explicit action
`Geen ISBN`. `Geen ISBN` skips provider lookup and goes directly to manual
Edition input. Biblio never invents an ISBN.

## 2. Local-first existing Edition paths

### One local Edition

When a canonical ISBN matches an existing Edition locally, provider lookup is
skipped. The wizard first shows a compact control card whose only question is:
`Is dit inderdaad mijn uitgave?`

The card shows cover, Edition title, author(s), language, publisher,
publication date/year, ISBN and, where useful, the `Bestaande uitgave` label.
Its primary action is `Deze uitgave gebruiken`; its secondary action is
`Klopt niet?`.

`Klopt niet?` offers two distinct routes:

- `Dit is niet mijn uitgave`: stop the normal addition flow and treat it as an
  identification/catalogue problem. Do not automatically create an Edition
  with the same ISBN.
- `De uitgave klopt, maar gegevens zijn fout`: the Item may still be added.
  Directly checked differences become user-observed evidence; central Edition
  data is not silently changed and Librarian correction governance remains
  leading.

### `local_ambiguous`

For `local_ambiguous`, show a separate choice among the existing local Editions.
Do not call a provider, choose automatically or create a new Edition. Each
choice supplies enough distinction: Edition title, language, publisher,
publication date, ISBN and, where available, cover.

If none is correct, treat that as a catalogue/review problem rather than
creating a duplicate Edition.

## 3. New ISBN metadata paths

### One usable candidate

Show one calm review step headed `Is dit de juiste uitgave?`. It presents cover,
Edition title and subtitle, author(s), language, publisher, publication date,
ISBN, page count and binding when reliably mapped. Missing values remain
quietly visible. The actions are `Ja, deze uitgave`, `Gegevens aanpassen` and
`Handmatig invoeren`.

This is not a field-by-field confirmation wizard.

### Multiple usable candidates

Show a calm comparison list of Edition cards, with no automatic winner and no
prominent provider or confidence score. Each card shows cover, title and
subtitle, language, publisher, publication date, ISBN, binding where useful,
and author(s) for recognition. Its action is `Deze uitgave`; the general action
is `Geen van deze / Handmatig invoeren`. For very similar candidates, differing
fields may receive subtle emphasis.

### Provider failure and metadata miss

Provider trouble uses calm, non-technical copy, for example:
`Boekgegevens konden tijdelijk niet worden opgehaald.` The actions are
`Opnieuw proberen` and `Handmatig invoeren`. When usable metadata from another
provider is already available, retain and show it; another provider failure
does not discard it.

The manual path is also available for a metadata miss and when no multiple
candidate fits.

### Expired review

An expired temporary metadata review never commits old metadata. Perform a new
lookup and review so the user checks current metadata again, while retaining
already entered local Item/Copy data. Ordinary UI never exposes snapshot or
token terminology.

## 4. Manual Edition route and deliberate Work linking

Manual entry uses the same Edition review step, not another wizard. If the user
previously scanned or entered an ISBN, prefill it and retain it throughout the
manual route.

Every manual Edition route optionally offers `Koppel aan bestaand werk`. It
opens a simple search/select step that searches at least title and author and
shows Work title, author(s), original language if known, relevant series and a
subtle distinguishing status when needed. Actions are `Dit werk gebruiken` and
`Geen passend werk`.

The step neither edits Work metadata nor matches automatically by title/author.
Without a deliberate link, a new provisional Work may arise under the existing
server-side contract.

## 5. Limited adjustment of directly observed Edition data

`Gegevens aanpassen` is only for concrete Edition data reasonably checkable
directly on the physical book:

- Edition title and subtitle;
- ISBN;
- language;
- publisher / imprint;
- publication year/date;
- printing/edition statement;
- binding / physical publication form;
- page count; and
- Edition-specific contributors, such as translator or illustrator.

It does not edit canonical Work title, Work identity, Work/series relations or
other platform-wide bibliographic claims. Broader corrections remain
Librarian-governed.

## 6. Item/Copy information and additional copies

After Edition selection/review, the wizard has an Item/Copy step. It may
optionally collect location, condition, acquisition data and an already
supported local display name/label. These are not required to add a book unless
an existing domain contract already requires them.

The six collector fields—signed, copy number/limitation, dust jacket,
inscription/dedication, origin/provenance and completeness/enclosures—are not a
required main-wizard step. A successful addition may offer the conceptual
follow-up `Exemplaar verder beschrijven`; that follow-up is not designed here.

When the same Edition already has one or more Items in the current Library,
adding remains allowed. Show a compact check such as `Deze uitgave staat al 2×
in je Bibliotheek.`, optionally making existing Items recognizable through
location/inventory number. The actions are `Nog een exemplaar toevoegen` and
`Annuleren`.

## 7. Conditional completion and success

There is no mandatory summary page for every path. The quick existing-Edition
path uses the control card, any extra-copy check and then directly `Boek
toevoegen`. New, manual and adjusted Edition paths receive a short final
check/summary.

`Boek toevoegen` means only: `dit is de concrete Edition die ik wil
registreren`. It does not make a Work or Edition librarian-confirmed or make
provider data platform-wide curated truth.

After success, show a calm success state rather than a dashboard page: cover,
title and `Boek toegevoegd`. Follow-up actions are `Bekijk boek` (primary),
`Exemplaar verder beschrijven` and `Nog een boek toevoegen`.

## 8. Interaction and visual principles

- Keep provider and HTTP terminology out of the user-facing flow; provider
  details never lead the choice UI.
- Preserve the fast routine-entry path; add control only where new or ambiguous
  catalogue data genuinely requires it.
- Add Book is a book/collection flow, not a bibliographic research form.
- Preserve the existing Editorial Library × Serious Utility direction.
- Apply the canonical Guided Flow form principles: visible labels, help below
  fields, whitespace grouping, explicit optionality, clear focus and textual
  error handling.

## 9. Explicit exclusions

This design creates no production implementation and does not design a
Librarian review queue/UI, collector-detail UI, Book Detail redesign, new
Metadata Hub rule, provider fusion or new Work-match heuristic.

## 10. Implementation acceptance boundary

Any later UI integration must cover the existing Edition, `local_ambiguous`,
single candidate, multiple candidates, manual/no-ISBN, provider-failure and
expired-review paths; preserve one wizard; make Work linking manual and
optional; allow additional copies with warning; make the summary conditional;
and implement the stated success/follow-up state without widening scope.

It must continue to honor the server-authorized Library Context, local-first
behavior, opaque review handoff, user-observed evidence and Librarian-governed
central metadata boundaries already fixed by ADR-014 and MH-B5B.
