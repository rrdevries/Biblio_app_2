# ADR-011 — Field-level metadata confirmation and provenance

Status: Accepted

Scope: Metadata Hub MH-B4 persistence and decision rules

## Context

ADR-010 established provider-neutral whole-record acquisition and deferred a
general field-level merge model. MH-B2 and MH-B3 now return bounded neutral
candidates, but schema 1014 can retain only accepted Edition-level provenance.
It cannot represent durable field proposals, multiple sources for one value,
content rejection or intentionally blank state.

MH-B4 supplies the persistent proposal/evidence foundation. For platform-wide
Work/Edition metadata, every future canonical change remains a correction
proposal assessed by `Biblio Librarian`; submission by a Library actor is not a
direct canonical mutation. This amends only ADR-010's earlier field-evidence
deferral; it does not authorize automatic fusion or a provider-per-field UI.

## Decision

Field review is keyed by an opaque Biblio metadata-record identity plus one
closed provider-neutral field key. Values use bounded deterministic JSON and
exact type-aware content hashes. Ordered multiple values are atomic.

Provider ingestion never mutates canonical state. An identical value supplies
supporting evidence without automatic confirmation. A differing value remains
an independent proposal/conflict. Multiple providers supporting identical
content share one value and retain separate source evidence.

The supported `title` field represents Edition-level title evidence. Its review
state is not Work-title governance: confirming or ingesting it cannot mark a
Work title `librarian_confirmed` or overwrite a Work title.

Rejection applies to content across providers and is not undone by repeated
evidence. A future authorized binding must route confirmation or manual
correction for Work/Edition metadata as a proposal to Biblio Librarian; the
persisted field-review foundation itself grants no actor that authority. The
decision supersedes other active values without deleting their evidence. Later
genuinely new content may be proposed but never overwrites confirmed content.

Intentionally blank is a first-class persistent state, distinct from unknown.
Provider evidence is retained but inactive while that state holds. Only an
explicit reopen action permits proposals again.

Schema 1015 uses separate state, content-value and evidence tables. Evidence is
deduplicated by record, field, value and source identity while retaining
first/last retrieval and observation count. All mutations serialize on a locked
field row inside one transaction.

## Boundaries

This decision adds no REST/UI/Add Book integration, provider priority,
confidence scoring, semantic equality, partial list merge, automatic canonical
catalog mutation, Series Intelligence, cover runtime, DATA-01 change or V1
migration. Authorization and binding an opaque review record to catalog creation
belong to a later explicitly approved integration slice. That slice must
preserve the Biblio Librarian proposal/assessment boundary and may not grant
direct central metadata mutation through Library roles.

## Consequences

Provider evidence and user decisions remain historically inspectable and
provider replacement cannot change canonical truth. The cost is three additive
tables and an explicit integration step before the foundation is user-facing.

ADR-010 continues to govern provider neutrality, ISBN/Edition evidence,
whole-record acquisition and provider conflict presentation. ADR-011 governs
field-level persistence and confirmation when those candidates enter review.
