# MIG-02-AUTH-MAP-01 — Current V1 Author mapper

Status: **GO / CLOSED**

Date: 2026-09-16

Task severity: **High**

Scope: the exact CURRENT Author entities and base Book contributor arrays are
mapped to the existing source-neutral Author and WorkContributor plans. This
slice adds no schema, provider authority, Author merge, containment, Series,
REST, UI or apply/import path.

## 1. Pre-coding audit and decision inheritance

The required A–K audit was completed before production changes against docs
00–03, 06, 86–90, 98–100, 105–107, 110, 112, 115, 117 and 122, applicable
ADRs, current code and the immutable extraction. It established that the
adapter already retains the exact stable Author and positional Book shapes;
MIG-02-AUTH-01 already owns the typed target writes; CAT already exposes exact
Book-to-Work identities and alias mappings; MIG-FND already represents mapped,
preserved and quarantined findings; and schema 1026 is sufficient. No new
product decision remained. Docs/122 was implemented without reinterpretation.

## 2. Immutable mapping contract and architecture

| Provenance fact | Exact value |
|---|---|
| Source family | `biblio-v1` |
| Adapter | `current-v1-json-29` |
| Source version | `books-29.authors-2.reading-goals-2` |
| Manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Mapping contract | `current-v1-author-map-v1` |

`CurrentV1AuthorMapper` is injected as a bounded collaborator of
`CurrentV1CatalogMapper`. Raw Author records are not routed to participants.
After CAT determines exact ready Work representatives, the collaborator emits
typed `CatalogAuthorPlan` and `CatalogWorkContributorPlan` records plus
privacy-safe findings. The source-neutral AUTH participants retain exclusive
ownership of eventual canonical writes and reconciliation.

## 3. Author identities and display names

Each active stable entity maps as `v1.author/<stable-id>` to one
provisional/observed Author plan. Every valid unreferenced stable entity is
still planned. One reviewed corporate entity is preserved rather than coerced
into a person. Source metadata remains linked evidence and creates no provider
claim.

Only current V2 whitespace validation is applied to display names. Case,
punctuation, suffixes and diacritics remain intact. Equal names never select an
existing source or V2 Author. The two case-only stable/occurrence variants use
the stable identity while the occurrence spelling remains credit evidence.

## 4. Positional contributor mapping

For this adapter and manifest only, `authorIds[i]` identifies `authors[i]` and
forms a validated prefix. A longer, shifted, missing or incompatible prefix
fails closed; no name lookup repairs it. Names after the prefix are ID-less.

Every active base occurrence:

- uses `v1.book/<book-id>/author-occurrence/<one-based-position>`;
- carries explicit role `author`;
- preserves the original one-based position without compaction; and
- depends only on `v1.book/<book-id>/work` plus its exact Author plan.

An ID-less occurrence receives its own deterministic source identity beneath
that occurrence, with a SHA-256 component bound to the V2-normalized observed
name. The identity remains Book- and position-scoped, so equal names elsewhere
cannot merge.

## 5. CAT aliases, convergence and conflicts

Both members of every duplicate-ISBN group remain source contexts and retain
their own contributor occurrence and credit/evidence identity. Exact Author,
role and position edges common to all positive alias members are planned for
each occurrence; MIG-02-AUTH-01 creates the first canonical edge and reuses it
for the second credit. Eleven pairs therefore become eleven edge convergences
without losing source trace.

Positive alias sets are compared as sets before routing. Common edges may
continue, but a differing positive remainder is quarantined as
`alias_contributor_conflict`; it is never silently unioned, shifted or selected
by record order. A member with no positive Author evidence does not erase the
other member's evidence.

## 6. Preservation, quarantine and blocked dependencies

The reviewed corporate stable entity and its base occurrence, plus the
ID-less collective occurrence, are `preserved_deferred`. The two occurrences
whose Books are already CAT-quarantined are also preserved and create neither
a dangling ID-less Author nor a WorkContributor plan. Three reviewed
placeholder/composite/malformed occurrences are quarantined without repair or
an `Unknown Author`. A stable Author can remain active independently of one
blocked Work.

All 17 non-empty contained-work Author values receive separate preservation
findings and no Author plan. They remain in the explicitly deferred containment
lane.

## 7. Exact CURRENT totals

| Result | Count |
|---|---:|
| Stable source Author entities | 257 |
| Active stable Author plans | 256 |
| Base contributor occurrences | 1,146 |
| Stable-ID occurrences | 469 |
| ID-less occurrences | 677 |
| Stable-only / ID-less-only / mixed / empty Books | 427 / 531 / 17 / 164 |
| Occurrence-scoped Author plans | 672 |
| Total `catalog_author` plans | 928 |
| Total `catalog_work_contributor` plans | 1,139 |
| Unique WorkContributor edge intents | 1,128 |
| Exact alias edge convergences | 11 |
| Preserved base occurrences | 4 |
| Quarantined base occurrences | 3 |
| Preserved contained-work Author values | 17 |

No count is encoded as runtime branching logic. The immutable CURRENT run
recomputes each total from adapter records and the reviewed exception contract.

## 8. Reconciliation and downstream compatibility

Stable and occurrence-scoped Author observations remain distinct MIG-FND
identities. Each contributor observation receives its own credit/evidence
mapping even when the canonical edge mapping is reused. Existing divergent
replay, missing dependency, identity conflict, role/position conflict and
target-existence checks remain authoritative; RECON-01 needs no special
CURRENT repair path.

An eventual apply therefore writes the existing canonical Author graph used by
Author Search and Author-to-Works reads. Series remains Work-dependent only;
Wishlist, Reading and Notes are unaffected by Author identity. No downstream
migration is performed here.

## 9. Final-SHA zero-write evidence

The guarded `biblio-v2-migration-trial` / `biblio_migration_trial` environment
is rebuilt from the exact clean local implementation commit. Its ignored
artifact records that revision, `working_tree_dirty=false`, Core `2.40.0`,
schema `1026`, the exact manifest and `zero_write_confirmed=true`.

All Biblio table counts are fingerprinted before and after the exact CURRENT
dry-run and remain identical. The source-manifest digest is unchanged; no
network, source mutation or apply/import command is used. The exact commit,
artifact checksum and table-fingerprint digest are reported in the closure
handoff after the self-contained commit and intentionally are not written back
into that commit.

## 10. Verification, versions and boundaries

Focused tests cover stable and same-name-distinct identities, occurrence
scoping, positional-prefix rejection, case-only evidence, role/position,
non-compaction, CAT alias convergence and conflict, blocked Work behavior,
terminal malformed/corporate outcomes and contained-work preservation.
Integration proves two source occurrences retain two credits/evidence rows
while sharing one canonical edge. The full Core gate, PHP syntax, PHPStan,
Composer/platform, WordPress smoke, manifest, whitespace, privacy scan and an
explicit independent second review are required and reported in the closure
handoff. Browser/E2E does not apply because REST and UI are unchanged.

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.39.0 → 2.40.0`.
- Biblio UI: `0.20.0` unchanged.
- No source file is changed or re-extracted.
- No migration apply/import is exposed or run.
- Series, contained-work Authors, Wishlist, Reading, Notes, Assessments,
  Archive, circulation backfill, Author reconciliation UI, provider enrichment
  and final cutover remain outside this slice.

## Verdict

**GO / CLOSED.** The exact CURRENT base Author and WorkContributor mapping is
complete, identity-conservative, source-traceable and zero-write in dry-run.
