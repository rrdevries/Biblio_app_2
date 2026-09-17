# MIG-02-NOTE-MAP-01 — Current V1 Note mapper

Status: **GO / CLOSED**

Date: 2026-09-17

Task severity: **High**

Scope: the exact CURRENT `books[].notes[]` population is translated to the
existing source-neutral Private Note migration contract. This slice adds no
Note product semantics, schema, REST, UI, network path or apply/import command.

## 1. Pre-coding audit

The required A–L audit completed before production changes against the project
guides, canonical docs 00–03 and 06, docs 109, 110, 112, 117, 125 and 126,
applicable ADRs, current code and the immutable extraction. It established the
exact adapter Note shape, `PrivateNotePlan`, participant dependencies, target
User boundary, CAT Work identity, content policy, timestamp constraints,
nullable Round behavior, MIG-FND identity/observation rules, reconciliation
checks and schema sufficiency. The existing target and participant contract is
sufficient; no product decision remained.

## 2. Decision inheritance

Docs/126 is implemented without reinterpretation. Private Notes remain
user-owned, Work-scoped and owner-only. The explicit migration target User is
the sole owner. Absence of an explicit source Round relation remains null; it
is not permission to infer one. Source prose remains content, never a type,
Review, Reflection, label or migration metadata carrier.

## 3. Mapper architecture

The bounded flow is:

```text
CurrentV1SourceAdapter
→ CurrentV1CatalogMapper ready Book/Work state
→ CurrentV1NoteMapper
→ typed PrivateNotePlan
→ existing PrivateNoteMigrationParticipant
```

The mapper contract is
`d-mig-note-map-01.2026-09-17:35a18156490f103d4b6b610f` and rejects another
manifest before interpreting records. Mapping emits typed plans and
privacy-safe findings only; it performs no write.

## 4. Existing Note participant reuse

`PrivateNotePlan`, `PrivateNoteMigrationParticipant`,
`PrivateNoteMigrationWriter`, `StrictPrivateNoteContentPolicy`, the Note
aggregate and repositories are semantically unchanged. The participant still
declares exact dependencies and validates canonical content. The writer still
joins the caller-owned MIG-FND transaction and owns exact dependency mapping,
active target User, replay, reverse mapping and canonical target checks.

## 5. Ownership

All 17 plans use the one explicit server-validated migration target `UserId`.
The target Library remains run/dependency scope and never owns a Note. There is
no current actor, administrator, first user, source Book owner, display name or
Library Owner fallback.

## 6. Work dependency

Each Note derives only:

```text
structural parent Book ID
→ v1.book/<book-id>/work
→ exact committed catalog_work mapping
```

Title, ISBN, Edition, Item, Copy and Author matching are absent. All 17 pinned
dependencies are CAT-ready. A future missing/quarantined Work yields a
quarantine finding and no dangling plan.

## 7. ReadingRound dependency

Every CURRENT plan has `readingRoundSourceId = null`. Four parent Books have a
sibling stable Round and four have a sibling registration, but neither is a
Note reference. Work equality, single plausible Round, status and timestamp
proximity do not change the null dependency.

## 8. Body and content

The 17 bodies are valid non-empty one-line UTF-8 plaintext. The mapper performs
only the reviewed mechanical conversion: normalize CRLF/CR, escape the entire
text as UTF-8 data with `ENT_QUOTES | ENT_HTML5`, wrap it in exactly one
`<p>...</p>` and require the existing strict policy to return it unchanged.
Synthetic coverage proves safe handling of HTML-significant characters. No
prose rewrite, translation, classification, summary or migration label occurs.

Broken/empty content quarantines. Meaningful future multiline or oversized
content outside the reviewed lossless shape is preserved-deferred rather than
silently rewritten, truncated, dropped or filled with a placeholder.

## 9. Timestamps

Both source fields must exactly match millisecond UTC `Z` syntax and parse
without warning or rollover. They are passed as exact instants to the plan,
whose canonical form uses microsecond-capable UTC representation. All 17
records have equal creation/update instants; equality is valid. Missing,
invalid, unsupported or reverse chronology quarantines, and neither migration
time nor current time is available as fallback.

## 10. Privacy

Plans target ordinary private owner-only Notes and introduce no visibility or
publication field. Dry-run plans and findings expose only stable identity,
payload hash, operations, dependencies, dispositions and reason codes. Note
bodies, canonical content and source timestamps are absent from artifacts,
operator output, committed docs and failure messages.

## 11. Source identities

The adapter record remains `v1.note:<unchanged-id>`. The typed participant and
MIG-FND observation use `private_note:<the-same-id>`. No Work, content,
timestamp, hash or random value becomes source identity. The typed payload hash
binds User, Work, null Round, canonical content, exact times, initial version
and private visibility for deterministic replay.

## 12. Alias and blocked dependencies

Zero pinned Notes belong to duplicate-ISBN representatives or aliases and zero
depend on CAT-quarantined Books. The generic rule nevertheless retains every
distinct Note source ID if separate Books later converge on one Work. Equal
body/time/Work never deduplicates Notes.

## 13. CURRENT mapping totals

| Measure | Result |
|---|---:|
| Stable source Notes / unique IDs | 17 / 17 |
| Valid structural parent Books | 17 |
| CAT-resolvable Work dependencies | 17 |
| Alias-Book / CAT-blocked Notes | 0 / 0 |
| Non-empty one-line plaintext bodies | 17 |
| Malformed / oversized bodies | 0 / 0 |
| Valid created / updated instants | 17 / 17 |
| Equal / later / earlier updates | 17 / 0 / 0 |
| Active typed Private Note plans | 17 |
| Preserved / quarantined / unmatched | 0 / 0 / 0 |
| ReadingRound-linked | 0 |
| Note planning errors | 0 |

These are recomputed results, not runtime constants.

## 14. Reconciliation

The existing mapping contract requires exactly one `private_note` entity for a
mapped observation. The existing target inspector validates run User, exact
Work dependency, nullable Round dependency, owner-scoped target existence,
canonical content, both instants and version 1. Exact replay can reuse the
same target; changed payload, target type, target state, multiple mapping or
reverse source reuse fails closed.

## 15. Zero-write proof

The final CURRENT dry-run is executed only after the implementation commit is
loaded into the clean detached guarded trial. Before/after fingerprints cover
all 57 Biblio tables and must be identical. The ignored artifact and checksum
record `zero_write_confirmed=true`, 17 Note plans, no Note exceptional outcome,
no planning error and no unmatched reference. No apply/import command is run.

## 16. Trial build provenance

The trial uses project `biblio-v2-migration-trial`, database
`biblio_migration_trial`, schema 1026, Core 2.42.0, UI 0.20.0, adapter
`current-v1-json-29` and manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
The exact self-identifying implementation SHA, artifact checksum and table
fingerprint are recorded in the post-commit ignored evidence and completion
report; a commit cannot embed its own final hash without changing that hash.

## 17. Explicitly deferred

Ratings, Review, Reflections, Copy-note product modeling, Series, Wishlist,
Archive, contained works, circulation backfill, ReadingRound inference, Note
UI and every production apply/import or cutover action remain outside scope.

## 18. Tests and quality gates

Focused mapper tests cover exact source/target identity, User and Work
dependencies, null Round despite a plausible sibling, safe escaping, exact
milliseconds, equal timestamps, distinct equal-content Notes, deterministic
ordering, invalid body/time, preservation, CAT quarantine, content-lane
exclusions and manifest drift. Existing participant/replay/privacy tests and
the dry-run shell regression remain authoritative. Final acceptance runs the
full Core gate, PHP syntax, PHPStan, Composer/platform, WordPress smoke,
manifest, whitespace and privacy checks plus an independent second review.

## 19. Current V1 data rule

Only the designated archive and validated extraction are CURRENT:

- ZIP SHA-256: `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`;
- extracted manifest: `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`;
- files/bytes: 3,587 / 19,801,196; and
- adapter/source version: `current-v1-json-29` /
  `books-29.authors-2.reading-goals-2`.

The source is not re-extracted, changed or substituted and receives no output.

## 20. Schema, Core and UI versions

- Product: `v2.001` unchanged.
- Schema: `1026` unchanged.
- Biblio Core: `2.41.0 → 2.42.0`.
- Biblio UI: `0.20.0` unchanged.

## 21. Git

The slice is delivered as exactly one local commit with message
`feat: map current V1 Notes`. It is not pushed. After commit, the isolated trial
is rebuilt from that exact clean SHA. Both worktrees finish clean.

## Verdict

**GO / CLOSED.** All CURRENT stable Notes map privately and deterministically
without inferred ownership, Work, ReadingRound, time or semantics and without
running apply/import.
