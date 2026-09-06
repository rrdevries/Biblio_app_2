# ADR-012 — Provisional catalog and Librarian-review governance

Status: Accepted

Scope: Work/Edition creation state and functional reasons for future Biblio
Librarian review

## Context

ADR-010 and ADR-011 establish that Work and Edition metadata are platform-wide
and that a Library actor proposes, rather than directly mutates, a central
correction. CAT-T1 additionally makes a Work title explicitly `provisional` or
`librarian_confirmed`. That status must not turn normal catalog creation into a
manual approval gate or imply that every provisional record needs review.

## Decision

A new Work and/or Edition may be created immediately as a platform-wide
`provisional` catalog record when no appropriate existing record is available.
It is usable for normal collection management without prior Biblio Librarian
approval. `provisional` means that the shared bibliographic record exists and
is usable but has not yet been content-curated or confirmed by a Biblio
Librarian. It does not mean erroneous, suspicious, blocked or automatically
queued for review.

Creation of a new record and a later change to existing central metadata are
different actions. The latter remains a correction proposal: an authorized
Eigenaar or Beheerder may propose it, but a Biblio Librarian assesses it and no
Library directly mutates platform-wide Work/Edition metadata, regardless of how
many Libraries use the record.

Biblio Librarian review is exception-based catalog curation, not a required
approval step for every new book. A future review task may have one or more of
these closed functional reasons:

- `correction_proposed`: an Eigenaar or authorized Beheerder explicitly
  proposes a central metadata correction;
- `ambiguous_match`: multiple plausible Work or Edition matches exist and
  Biblio cannot safely choose automatically;
- `possible_duplicate`: records appear to describe the same Work or Edition,
  but automatic merging is not sufficiently certain;
- `identity_conflict`: evidence conflicts on metadata important to Work or
  Edition identity;
- `unresolved_work_identity`: Work relation, original Work identity or
  original/canonical Work title remains substantively uncertain;
- `structural_ambiguity`: bibliographic structure cannot be reliably
  determined automatically, for example translation versus another Work,
  omnibus/bundle, boxset or multiple underlying Works.

Status `provisional` alone is never a reason. Neither are a missing cover,
publisher or publication date; incomplete metadata from one provider; a new
Edition with an unambiguous canonical ISBN; multiple providers supporting the
same metadata; or a provisional Work title without concrete further
uncertainty.

CAT-T1 title semantics remain unchanged: an Edition owns its concrete title; a
Work title may be `provisional` or `librarian_confirmed`; an Edition title may
seed a provisional Work title but never automatically becomes the canonical
Work title; and missing Librarian confirmation does not block adding or using
the Edition.

## Boundaries

This decision defines functional need only. It adds no review queue UI,
priority algorithm, notification, automatic task assignment, dashboard,
merge-flow, schema, role, REST/UI, MH-B5 work or automatic catalog merge.

Collector-local overrides remain an open future design question: which
platform-wide Work/Edition data may a Library present or supplement locally for
collectors, and which collector-specific data belongs at Item/Copy level.
This ADR defines no answer or model.

## Consequences

Normal creation remains usable and non-blocking while genuine uncertainty and
requested corrections have stable future review reasons. A future queue must
preserve these reasons without inferring a task from provisional status alone.

ADR-010 and ADR-011 continue to govern provider neutrality, field evidence and
central correction-proposal authority; CAT-T1 continues to govern title state
and display semantics.
