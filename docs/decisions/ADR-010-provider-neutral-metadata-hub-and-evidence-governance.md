# ADR-010 — Provider-neutral Metadata Hub and evidence governance

Status: Accepted

Scope: Biblio v2.001 metadata acquisition and canonical bibliographic truth

## Context

Biblio needs practical ISBN-assisted metadata acquisition without making one
external provider the owner of bibliographic truth. The completed benchmark in
commit `924e3ae657ff6139bc649563c8b4ca1dbbe14a50` shows that no tested free
provider is sufficient on its own. Open Library is useful for English Edition
and Work evidence but weak for Dutch ISBN coverage. Google Books materially
improves Edition coverage, while its exactness, conflicts and commercial-use
conditions prevent it from becoming a permanent Core dependency. Wikidata and
BookBrainz added insufficient runtime value for v2.001 in this sample.

External records may disagree with each other or with confirmed local data.
Moreover, an ISBN identifies Edition evidence, not a Work or physical Item.
Treating a provider response as canonical or automatically merging a title and
author match would therefore collapse Biblio's Work → Edition → Item model and
could silently corrupt confirmed metadata.

## Decision

### Boundary and truth

Biblio uses a provider-neutral `Metadata Hub` application boundary. Providers
are replaceable adapters that return evidence and whole-record candidates.
Provider response shapes and identifiers do not enter Core domain contracts.

Confirmed Biblio-local canonical data wins over unconfirmed external evidence.
The Metadata Hub never silently overwrites confirmed fields and never makes an
external provider a precondition for Core data integrity. Secrets and API keys
are operational configuration and are never stored as provenance.

### v2.001 implementation scope

The approved v2.001 build target is limited to:

- ISBN normalization and checksum validation;
- local canonical lookup before external lookup;
- one provider-neutral Hub interface;
- an Open Library adapter as the open first source;
- Google Books as a temporary, conditional fallback;
- whole-record candidate presentation with user review and correction;
- minimum provenance: provider, provider record ID, `retrieved_at`, match
  method, queried identifier and confirmation state;
- a first-class manual/no-ISBN path;
- Biblio-owned or user-supplied covers, without provider-cover dependency.

This ADR approves that target contract; it does not implement it. Field-level
evidence storage, confidence scoring, merge policy and record fusion remain
future work.

### Identity and matching

An ISBN lookup yields Edition evidence. Work resolution is accepted only from a
reliable explicit provider link or an already confirmed local relationship.
Title + author similarity is never sufficient for automatic Work merge.
Uncertain matches remain visibly proposed or maybe; UX language must reflect
the actual evidence strength.

When providers materially conflict, v2.001 presents a whole-record candidate
for review. It does not perform a general multi-provider field-level merge.

Absence of ISBN is a normal bibliographic state, not an existence failure. A
Work, Edition and Item may be created through the manual path without ISBN.

### Provider verdicts and release gate

- Open Library foundation: **CONDITIONAL**.
- Google Books temporary fallback, especially for Dutch coverage:
  **CONDITIONAL**.
- Wikidata runtime enrichment in v2.001: **NO-GO**.
- BookBrainz runtime adapter in v2.001: **NO-GO**.

Wikidata and BookBrainz remain future evidence/relationship candidates; the
NO-GO verdict is not a permanent rejection.

Before a commercial Biblio release that uses Google Books, current terms must
be reassessed for permitted use, storage, caching, branding/attribution and
paid use, and a disable/replace path must be proven. The precise commercial
provider after Google remains open. The Hub must allow replacement without
changing Core or already confirmed canonical records.

### Series evidence

External series data is suggestion/evidence only. It never silently mutates
canonical Series membership, group, order, position, lifecycle or completeness.
The benchmark does not support automatic Series Intelligence for v2.001.

## Alternatives considered

### One fixed primary provider as source of truth

Rejected because benchmark coverage and quality vary materially by language,
provider conditions can change, and provider lock-in would leak into Core.

### Automatic title + author Work matching

Rejected because similarity is insufficient evidence for identity and false
merges are harder to repair than explicit unresolved candidates.

### General field-level provider fusion in v2.001

Rejected because the benchmark measured material conflicts and v2.001 has no
approved field-level provenance, confidence or adjudication model.

### ISBN-required creation

Rejected because legitimate books and Editions can have no ISBN or an unknown
identifier. Identifier availability cannot determine bibliographic existence.

### Provider covers as a runtime dependency

Rejected because cover availability and usage conditions vary. Biblio must
support its own cover path independently.

## Consequences

Positive:

- canonical truth and provider evidence remain separate;
- providers can be disabled or replaced without rewriting Core contracts;
- Work, Edition and Item identity remain explicit;
- manual entry and no-ISBN books remain fully supported;
- conflict and uncertainty stay visible to users.

Costs and constraints:

- ingestion needs a review-oriented candidate flow rather than one-click silent
  import;
- Google requires a commercial-release reassessment;
- richer provenance, fusion and automatic resolution require later decisions;
- Series Intelligence cannot be inferred automatically from this provider
  stack.

## Evidence and related canon

The benchmark is input for this decision, not a universal market measurement.
Its sample contained 300 unique valid ISBNs from the designated Biblio V1
dataset. Product-relevant measurements were:

| Evidence | Total | NL | EN |
|---|---:|---:|---:|
| Open Library hit / exact Edition | 57.67% / 55.00% | 27.78% / 27.16% | 95.45% / 90.91% |
| Google Books hit / exact Edition | 97.33% / 82.33% | 98.15% / 87.65% | 96.36% / 76.36% |
| Open Library → Google hit / exact Edition | 99.67% / 95.33% | — | — |
| Full free stack hit / exact / Work link / cover | — | 99.38% / 95.68% / 27.16% / 37.04% | 100.00% / 96.36% / 95.45% / 95.45% |

The Open Library → Google stack had a 35.00% provider-conflict rate. Series
evidence was 19.35% within the small locally known Series subset (`N=31`), not
a general market percentage. Detailed evidence is in
`tools/metadata-benchmark/output/metadata-benchmark-summary.md` and the related
benchmark artifacts.

The product behavior is defined in `docs/01-functional-design.md`; scope and
open questions are maintained in `docs/03-scope-and-deferred.md` and
`docs/26-future-roadmap-decisions.md`.
