# MIG-02-SOURCE-01 — Current V1 source intake and profile

Status: **PROFILE GO — MAPPING/DESIGN SLICES REQUIRED**

Date: 2026-09-15

Task severity: **High**

Scope: exact CURRENT source intake, immutable structural inspection, the
smallest exact production adapter and zero-write profile evidence only. No
mapping participant, dry-run, apply/import, ledger write, product write,
cleanup, schema change, provider call or UI change is included.

## 1. CURRENT source designation

Renée explicitly designated exactly:

```text
/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip
```

That ZIP is CURRENT V1 truth for this rehearsal. No DATA-01, historical
MIG-01 fixture or other `/data/` tree was substituted.

| Provenance | Value |
|---|---|
| Finder byte-copy SHA-256 (snapshot anchor) | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Original ZIP size | 14,657,042 bytes |
| Original mode/owner | `-rw-r--r--`, uid `501`, gid `20` |
| Original modified | `2026-09-15T17:52:49+02:00` |
| Original created | `2026-09-15T17:52:48+02:00` |
| Extraction root | `.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Adapter source family | `biblio-v1` |
| Adapter source version | `books-29.authors-2.reading-goals-2` |
| Extracted files / bytes | 3,587 / 19,801,196 |

macOS privacy controls denied terminal byte access to the archive directory.
Finder made an unmodified byte copy into the dedicated ignored source-intake
root. The copy retained exact size, mode, owner, creation and modification
metadata; its SHA-256 is the recorded ZIP identity. Finder did not extract,
recompress or overwrite the designated original. The original remained
unchanged. This is a provenance limitation, not an inferred or historical
hash.

Before extraction, central-directory validation proved 3,590 ZIP members,
13,479,018 compressed bytes, 19,801,196 uncompressed bytes, zero absolute or
traversal paths, zero backslash paths and zero symlinks. `unzip -t` passed.
Extraction used a new empty destination, after which all source files were
made read-only. Per-file path, size and SHA-256 are retained only in the
ignored RUN-01 artifact; generated output is outside the source tree.

## 2. Source structure

The archive has two top-level directories: `data/` and macOS filesystem
metadata under `__MACOSX/`. `data/` contains 20 reviewed top-level JSON files,
`cover-cache/` and six historical `migration_reports/*.json` files.

| Tree | Files | Bytes |
|---|---:|---:|
| `data/` | 1,829 | 19,428,299 |
| `data/cover-cache/` | 1,803 | 12,550,373 |
| `data/migration_reports/` | 6 | 355,416 |
| `__MACOSX/` | 1,758 | 372,897 |

Observed extensions are 1,818 JSON, 1,417 JPG, 341 IMG, 4 WEBP, 2 PNG and 2
GIF, plus AppleDouble metadata members. All 928 non-AppleDouble JSON files
parse successfully; none is unreadable or malformed JSON.

The exact 20 reviewed `data/*.json` members and top-level populations are:

| File | Root/version marker | Top-level population |
|---|---|---:|
| `authors.json` | object, schema 2 | authors 257 |
| `book_enrich_cache.json` | object, schema 1 | entries 8 |
| `book_types.json` | array | 7 |
| `books.json` | object, schema 29 | books 1,139; copies 1,106; wishlist 41 |
| `cache.json` | object, schema 1 | entries 403 |
| `carriers.json` | array | 3 |
| `categories.json` | array | 14 |
| `genres.json` | array | 44 |
| `home_prefs.json` | object, schema 1 | widget order 5; hidden 0 |
| `next_to_read.json` | object, no version marker | items 0 |
| `reading_goals.json` | object, schema 2 | goals 2 |
| `recommendations_ignored.json` | object, schema 1 | ignored IDs 0 |
| `recommendations_prefs.json` | object, schema 2 | ignored 0; shown 313 |
| `releases.json` | object, schema 1 | authors 428; merged diff 294 |
| `releases_dismissed.json` | object, version 1 | dismissed 21 |
| `releases_excluded.json` | array | 0 |
| `releases_merged.json` | array | 0 |
| `releases_prefs.json` | object, schema 1 | tracked Author IDs 0 |
| `taxonomy_aliases.json` | object, version 2 | rules 7 |
| `taxonomy_review_queue.json` | array, no version marker | 446 |

The primary explicit markers are:

- `data/books.json`: `schemaVersion=29`, exact root keys `books`, `copies`,
  `wishlistItems` and `schemaVersion`;
- `data/authors.json`: `schemaVersion=2`, exact root keys `authors` and
  `schemaVersion`;
- `data/reading_goals.json`: `schemaVersion=2`, exact root keys `goals` and
  `schemaVersion`.

Auxiliary structures have their observed explicit `schemaVersion` or `version`
markers checked as well. The adapter claims no other version. An absent file,
unexpected path, changed root/record/nested field, unsupported version, wrong
primary shape, malformed reviewed/cache/report JSON, symlink or byte change fails closed as
`unsupported_source_structure` or the existing more specific package failure.

## 3. Source identities

| Source category | Observed identity |
|---|---|
| Book aggregate | stable `books[].id` |
| Physical copy | stable `copies[].id`, with `bookId` |
| Author authority | stable `authors[].id` |
| Contributor occurrence | ordered array position; no independent ID |
| ReadingRound | stable nested `readingRounds[].id`, parent Book structural link |
| Book Note | stable nested `notes[].id`, parent Book structural link |
| Rating / Review / Reflection | no independent stable ID |
| Wishlist entry | stable `wishlistItems[].id`, with `bookId` |
| Series occurrence | name/position fields only; no stable Series ID |
| Collection | no records observed |
| Circulation round | stable nested `circulationRounds[].id`; the same IDs occur in Book and Copy representations |
| Archive period/state | embedded Copy state; no archive-period ID |
| Classification assignment | ordered raw strings; no assignment ID |
| Location | blank strings only; no Location ID |
| Item-local | Copy ID is the containing identity; embedded values have no independent IDs |
| Reading goal | stable `goals[].id` |

No ID is synthesized for ID-less source concepts. The adapter emits stable raw
source records only for Books, Copies, Authors, Wishlist entries,
ReadingRounds, Notes, circulation rounds and Reading Goals. It makes no V2
plan and does not identify a V1 Book as a Work or Edition.

## 4. Population counts

| Source category | Count |
|---|---:|
| Book records | 1,139 |
| Physical Copy records | 1,106 |
| Author records | 257 |
| Contributor/name occurrences | 1,146 |
| Wishlist entries | 41 |
| ReadingRounds | 53 |
| Read registrations | 395 |
| Stable Book Notes | 17 |
| Copy-note occurrences | 3 |
| Ratings greater than zero | 15 |
| Reviews | 1 |
| Reflections | 5 |
| Circulation round identities | 9 |
| Archived Copies | 23 |
| Series-name occurrences | 161 |
| Contained-work occurrences | 22 |
| Classification assignments | 2,305 |
| Classification definitions | 68 |
| Taxonomy review-queue records | 446 |
| Reading Goals | 2 |
| Collections | 0 |

The exact production profile enumerates 2,624 stable source records and 200
privacy-safe findings. No count above is inherited from the historical MIG-01
snapshot.

## 5. Referential integrity

- All 1,106 Copy `bookId` references resolve; zero are missing or orphaned.
- All 41 Wishlist `bookId` references resolve; zero are missing or orphaned.
- All five non-empty `variantOfBookId` references resolve.
- All 469 explicit Author-ID occurrences resolve to 243 existing Author IDs;
  zero are missing.
- All used category and genre strings have exact source-definition entries.
- Each stable ReadingRound and Note has a structural parent Book.
- Circulation has nine unique stable IDs. Seven are represented both under the
  Book and its Copy. Six shared payloads are equal; one shared ID diverges on
  `endDate` (Book-level open, Copy-level closed). This is an explicit source
  ambiguity. Neither representation receives precedence.
- No duplicate Book, Copy, Wishlist, Author, ReadingRound, Note, circulation
  or Reading Goal IDs were observed.
- One Copy acquisition object has no `type`; it is reported as a malformed
  value, not repaired. No primary stable record lacks its required source ID.

There are no source Collection membership or Location-ID references to test.
Archive state is embedded on its Copy and all observed archive/loan conflicts
are reported in their own sections.

## 6. Catalog / Edition / Item findings

The source has 1,139 Book records and 1,106 physical Copies. Five Books carry
`variantOfBookId`; 22 contained works are ID-less child payloads. These shapes
do not establish V2 Work/Edition identity or cardinality.

| Raw field/value | Count |
|---|---:|
| non-empty `isbn` | 989 |
| empty `isbn` | 150 |
| non-empty `isbn10` | 325 |
| non-empty `isbn13` | 904 |
| explicit no-ISBN marker | 0 |
| Copy numbers | 1,106 non-empty, 1,106 distinct |
| carrier `Fysiek boek` | 1,139 |
| binding empty / `hardcover` / `softcover` | 1,113 / 9 / 17 |
| edition format `standaard` / `special edition` / `omnibus` | 1,129 / 9 / 1 |
| title non-empty / subtitle non-empty | 1,139 / 0 |
| publisher non-empty | 724 |
| publication date/year string / empty | 940 / 199 |
| page count non-empty | 659 |
| language empty | 378 |
| language `English` / `Dutch` / `nl` / `NL` / `en` | 423 / 70 / 216 / 11 / 31 |
| language other explicit raw values | `Estonian` 1; `German / Deutsch` 2; `Nederlands` 2; `Spanish / español` 3; `de` 1; `el` 1 |

Empty ISBN is unknown/not entered and is not converted to V2 explicit
`Geen ISBN`. Canonical ISBN resolution, Edition grouping, title/publication
mapping and contained-work treatment all require reviewed planning.

## 7. Author findings

There are 257 stable Author IDs, zero duplicate ID groups and zero exact
duplicate display-name groups. The 469 Book-level Author-ID occurrences reuse
65 IDs across multiple Books; the maximum occurrence count for one ID is 52.
Contributor ordering is present through array order. No contributor role field
is present.

There are 531 Books with one or more author-name strings but no Author ID and
548 Books where the name and ID array cardinalities differ. Those occurrences
remain ID-less. Equal or similar names cannot establish identity, merge an
Author, supply a missing ID or assign a contributor role.

## 8. Reading findings

| Raw source fact | Values/counts |
|---|---|
| `readMarker` | `yes` 448; `no` 330; `unknown` 361 |
| `readStatus` | `finished` 443; `reading` 3; `stopped` 2; `unread` 330; `unknown` 361 |
| stable ReadingRounds | 53: finished-like 48; stopped-like 2; active-like 3 |
| round start precision | day 52; month 1 |
| finish precision | day 47; month 1 |
| stop precision | day 2 |
| read registrations | `unknown_date` 392; `partial_finish` 3 |
| registration partial precision | month 3 |
| `readHistory` events | 1,655: `added` 1,139; status 516 |
| historical status events | `finished` 438; `reading` 72; `stopped` 2; `paused` 1; `unread` 3; null 1,139 |

All 53 rounds belong to different Books; this source population contains no
explicit reread/multiple-round example. Pauses arrays are empty. The sole
paused-like value occurs in audit history, not as a current round lifecycle.
No stable round is undated. The 392 `unknown_date` registrations are ID-less.

These are source facts only. Active/completed/stopped mapping, source binding,
ReadingRound versus Personal Reading Truth, audit-history authority and the
ID-less registration strategy require reviewed mapping/product decisions.

## 9. Notes / Assessments findings

The source distinguishes five shapes:

- 17 private-looking Book Notes with stable IDs and non-empty creation/update
  timestamps;
- three non-empty Copy note strings without IDs;
- one Review object with text/date but no ID;
- five generic reflection strings without IDs;
- 15 positive ratings: `1` once, `3` once, `4` seven times and `5` six times.

The other 1,124 rating slots contain `0`. No explicit assessment publication,
privacy, owner ID or ReadingRound reference is present. `reviewed=true` occurs
zero times. Structural parent Book is not proof of Work mapping or owner.
Content is never used to infer Note versus Review. The stable Note participant
exists, but source-field/time approval is still required; the ID-less
assessment shapes require their own design.

## 10. Item-local / Acquisition / Condition findings

Copy condition is empty in all 1,106 records. No condition term exists to map,
so no condition mapping population or new decision is created.

Acquisition is present on 68 Book records and 77 Copy records; one Copy object
lacks a type. The more concrete Copy profile is:

| Raw Copy acquisition type | Count |
|---|---:|
| `borrowed` | 5 |
| `bought` | 26 |
| `received` | 45 |
| missing type | 1 |

Thirty-six Copy acquisition dates are present: day 20, month 13 and year 3;
41 are absent. `source` is non-empty 53 times with 16 distinct private values,
which are omitted from artifacts/docs. No amount, price or currency field
exists. `bought` and `received` are explicit source distinctions and are
candidates for reviewed `Zelf aangeschaft` / `Gekregen` mapping; `borrowed`
must not be coerced into acquisition ownership.

At Book level the explicit types are `borrowed` 4, `bought` 22 and `received`
42. Twenty-seven dates are present (day 11, month 13, year 3) and 41 are
absent. Book `source` is non-empty 45 times across 14 distinct private values.
Book-versus-Copy acquisition precedence is not selected here.

All Copy records have a unique `copyNumber`, `legacyBookNumber` and
`sourceBookNumber`. Exactly one Copy `exemplarPhotos` slot is non-empty and
three Copy note strings exist; one Copy has a disposal object. The source has
no `signed`, `signedBy`, price, currency, dust-jacket, inscription, limitation
or completeness/enclosure field. Book-level `specialFeatures` has 11 values on
nine Books and Book-level `provenance` is non-empty once; neither is silently
treated as an Item-local fact. Central provenance/publication facts must not be
smuggled into Item-local fields. Item-local target structure exists in schema
1025/1026, but every populated value mapping remains reviewed.

## 11. Series findings

`series=true` occurs 164 times and `seriesName` is non-empty 161 times, across
58 exact raw names. There are no stable Series IDs. Positions are non-empty
108 times; 54 named occurrences lack a position and one position lacks a
name. Raw positions include integers, decimal-looking strings and the values
`2019` and `2020`; no semantic normalization is applied.

The nonzero population means **MIG-02-SER-01 is required**, but its first work
must design stable identity and occurrence mapping. Series names are not merge
keys.

## 12. Collections findings

No Collection record, stable Collection ID, membership, order or archived-copy
relationship exists in this source. **MIG-02-COLL-01 is not required for this
snapshot.** No zero-population work is manufactured.

## 13. Wishlist findings

All 41 Wishlist entries have stable IDs, are active, use raw type `edition`,
and reference existing Book IDs. `fulfilledCopyId` is empty in all cases.
Thirty-nine have empty desired carrier; two state `Fysiek boek`. Timestamps are
present. No explicit owner ID is stored.

Current V2 Wishlist is private user-owned/platform-wide. The source `edition`
label does not prove a V2 Edition mapping, and ownership must come only from
the explicit migration target. A reviewed Wishlist adapter/participant plan is
required; no Library-owned assumption is allowed.

## 14. Archive findings

There are 23 archived Copies and 23 archive timestamps. Raw reasons are
`duplicate_correction` 20, `incorrectly_registered` 2 and
`wishlist_correction` 1. One disposal object occurs. No restored-period
history or archive-period ID is present.

No archived Copy has a circulation record or open circulation round, and no
Wishlist entry references an archived fulfilled Copy. Current V2 supports
preserved historical reasons; exact reason/state/period mapping still requires
reviewed archive wiring. Raw reason values are not mapped to `Anders`.

## 15. Loans / circulation findings

The source contains nine unique stable circulation IDs. Seven appear in both
Book- and Copy-level representations. At Copy level there are five `borrowed`
and four `lent_out` rounds: eight are open (four of each type) and one
`borrowed` round is closed/historical. At Book level all seven occurrences are
open: four `borrowed` and three `lent_out`. One shared `borrowed` ID is open at
Book level but closed at Copy level; no authoritative current/closed meaning is
chosen. It remains `PRODUCT_DECISION_REQUIRED` and preservation-required.

Each round has a free-text counterparty. Four distinct private counterparty
values are observed and omitted; there is no borrower/user/Library ID. Book
state separately uses `lending.mode` values `borrowed_in` 4, `lent_out` 3 and
`none` 1,132, plus `collectionStatus` `borrowed` 4, `lent` 3 and `owned` 1,132.
No archive conflict is observed.

The evidence is nonzero and includes current open state, so
**D-MIG-LOAN-01 REQUIRED**. This profile does not decide external versus
internal target semantics, counterparty identity, ownership, settlement or
cutover treatment.

## 16. Classification / Location findings

Raw Book Type values are `Leesboek` 713, `Kennisboek` 249, `Kookboek` 63,
`Jeugdboek` 50, `Stripboek` 25, `Kinderboek` 24 and `Studieboek` 15.

Raw used category values are `Fictie` 823, `Non-fictie` 308,
`Reizen & Cultuur` 47, `Kind & Jeugd` 29, `Beeldverhaal` 17, `Educatie` 16,
`Hobby, Sport & Creatief` 13, `Koken & Voeding` 10 and `Naslag & Kennis` 1.

The 28 used genre values/counts are: `Baby & peuter` 9, `Bakken` 6,
`Biografie` 35, `Fantasy` 119, `Filosofie` 24, `Geschiedenis` 69,
`Graphic novel` 8, `Historische fictie` 8, `Hobby & creatief` 13,
`Jeugdfictie` 4, `Kunst & cultuur` 8, `Literaire fictie` 249, `Manga` 8,
`Memoires` 12, `Mens & maatschappij` 32, `Misdaad` 44, `Politiek` 45,
`Poëzie` 1, `Prentenboek` 9, `Psychologie` 5, `Recepten` 8, `Reizen` 40,
`Romantiek` 81, `Sciencefiction` 115, `Sport` 13, `Strips` 16,
`Thriller` 46 and `Young adult` 14. The source also contains 14 category
definitions, 44 genre definitions and 446 taxonomy-review entries. No target
term is created and no raw value becomes `Anders`.

There is no explicit Subject/Topic collection and no classification parent or
hierarchy key. Categories, genres and Book Types are flat source definitions;
their names do not establish the V2 taxonomy type or target identity.

All 1,106 Copy `location` values and all 780 present Book `location` fields are
empty. There is no Location record, ID, populated string, usage or invalid
reference. No Location slice is required for this population.

## 17. Mapping decision matrix

| Source concept | Count | Existing V2 target | Status | Exact? | Human review? | Preserve? | Participant exists? | Next slice |
|---|---:|---|---|---|---|---|---|---|
| Book/Copy catalog identities | 1,139/1,106 | Work/Edition/Item | REVIEW_MAPPING | No | Yes | Yes | CAT exists | catalog mapping plan |
| non-empty ISBN | 989 | Edition ISBN | REVIEW_MAPPING | No | Yes | Yes | CAT exists | catalog mapping plan |
| empty ISBN without marker | 150 | unknown ISBN / explicit no-ISBN distinction | QUARANTINE_CANDIDATE | No | Yes | Yes | CAT exists | catalog mapping plan |
| stable Authors | 257 | canonical Author | REVIEW_MAPPING | No | Yes | Yes | AUTH exists | Author mapping plan |
| ID-less contributor names | 531 Books | WorkContributor | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | AUTH cannot identify them yet | Author identity design |
| stable ReadingRounds | 53 | ReadingRound | REVIEW_MAPPING | No | Yes | Yes | READ exists | reading mapping plan |
| unknown-date registrations | 392 | Personal Reading Truth candidate | REVIEW_MAPPING | No | Yes | Yes | source-neutral target exists | reading mapping plan |
| stable Notes | 17 | Private Note | REVIEW_MAPPING | No | Yes | Yes | NOTE exists | Note mapping plan |
| ID-less ratings/review/reflections | 21 | Rating/Review/Note candidates | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | assessment target exists; source identity absent | assessment design |
| `bought` / `received` acquisition | 26/45 Copies | Item acquisition method | REVIEW_MAPPING | No | Yes | Yes | CAT Item-local path exists | Item-local mapping plan |
| `borrowed` acquisition / missing type | 5/1 | no exact acquisition meaning | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | No source mapping | loan/Item-local decision |
| condition | 0 populated | Item condition | EXACT_TARGET | Yes target; zero data | No | No | CAT Item-local path exists | none |
| Series names/positions | 161/108 | Series + membership | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | No | MIG-02-SER-01 |
| Collections | 0 | Collection | EXACT_TARGET | Yes target; zero data | No | No | no migration participant | none |
| Wishlist | 41 | personal Wishlist | REVIEW_MAPPING | No | Yes | Yes | No production participant | Wishlist mapping/participant plan |
| archive state/reasons | 23 | Item archive periods + preserved reason | REVIEW_MAPPING | No | Yes | Yes | target exists | archive wiring plan |
| circulation | 9 IDs; Copy 8 open/1 closed; Book 7 open; 1 end-state conflict | external/internal loan candidates | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | No approved participant | D-MIG-LOAN-01 |
| classifications | 2,305 assignments | Library classification context | REVIEW_MAPPING | No | Yes | Yes | CAT target path exists | classification mapping plan |
| location | 0 populated | Library Location | EXACT_TARGET | Yes target; zero data | No | No | CAT target path exists | none |
| contained works | 22 | Work/Edition candidates | PRODUCT_DECISION_REQUIRED | No | Yes | Yes | CAT needs stable plans | catalog structure design |
| Reading Goals | 2 | deferred Reading Goal design | PRESERVE_DEFERRED | No | Yes | Yes | No | preservation plan |
| caches/reports/preferences/release state | auxiliary | no active migration target approved | PRESERVE_DEFERRED | No | Yes | Yes | No | preservation plan |

`EXACT_TARGET` above means the V2 target contract exists, not that a populated
source value was mapped or written. No `REVIEW_MAPPING` has been silently
upgraded.

## 18. Required conditional participants/slices

Required before a truthful dry-run:

- reviewed catalog mapping from V1 Book/Copy to the existing CAT plans;
- reviewed Author occurrence/identity mapping, including an explicit strategy
  for ID-less names;
- reading mapping for rounds and ID-less registrations;
- Note and assessment source mapping/identity decisions;
- Item-local acquisition mapping;
- Wishlist source mapping plus a production participant (none is registered);
- archive source wiring;
- classification mapping to pre-existing exact Library terms;
- preservation accounting for two deferred Reading Goals;
- **MIG-02-SER-01**, because Series population is nonzero;
- **D-MIG-LOAN-01**, before any circulation participant is designed.

MIG-02-COLL-01, a Location mapping slice and a Condition value-mapping slice
are not required for this zero-population snapshot.

## 19. Product decisions required

Only these source-proven questions are unresolved:

1. treatment of eight Copy-level open and one closed circulation rounds versus
   seven Book-level open occurrences, including the one shared ID with
   contradictory end state, external/internal meaning, free-text counterparty
   and cutover settlement;
2. identity and role handling for contributor occurrences without Author IDs;
3. stable Series identity and anomalous/missing positions without name merge;
4. source identities and Note/Review/Rating classification for ID-less
   assessments, reflections and Copy notes;
5. target identity/cardinality for ID-less contained works;
6. treatment of `borrowed`/missing-type acquisition and Book-versus-Copy
   acquisition precedence.

No new V2 field is proposed from source evidence.

## 20. Profile / zero-write evidence

The production command used is exactly:

```text
wp biblio migration profile --source-root=<ignored CURRENT extraction> --source-adapter=current-v1-json-29 --output-dir=<ignored trial artifacts>
```

It ran against the isolated `biblio-v2-migration-trial` project and explicit
`biblio_migration_trial` database. Before/after row counts for every
`wp_biblio_*` table are byte-identical; MIG-FND runs, observations, outcomes,
mappings, preservations and quarantines remain zero. Product table counts are
unchanged. Only the ignored version-2 JSON artifact and its SHA-256 sidecar
were written. No dry-run was run because reviewed mappings/participants are
intentionally incomplete. No apply/import command exists or was invoked.

## 21. Privacy handling

The actual ZIP, extracted files, per-file inventory, artifact and checksum stay
under ignored local trial roots. No V1 row, title, person, note, review,
counterparty, acquisition-source value, cache filename or payload is committed.
Tests use synthetic structural data. Docs and operator evidence contain only
paths, hashes, field names, bounded enums, aggregate counts and redacted
relationships. Private free-text distinctness is calculated through hashes and
reported only as a count.

## 22. Tests / quality gates

Acceptance includes synthetic tests for supported version, deterministic
records, stable IDs, duplicate protection through the runner, malformed record
accounting without invented identity, unknown path/field/version fail-closed,
references, package manifest binding, production CLI registration, artifact
privacy and all-Core-table zero-write proof. The targeted unit/integration
suites, PHP syntax, PHPStan, full Core suite, WordPress smoke, Composer/platform
and Git/manifest/whitespace checks must all be green before closure. An
independent final diff review is mandatory for this High slice.

## 23. Schema/Core/UI versions

- Product: `v2.001`
- Schema: `1026` (unchanged)
- Biblio Core: `2.34.0`
- Biblio UI: `0.20.0` (unchanged)
- WordPress trial runtime: `7.0.2`

## 24. Git

- Branch: `main`
- Start HEAD: `09bc8c0541d5e4c243f63e937021f4c9c8a523e0`
- Start divergence: 45 ahead / 0 behind `origin/main`
- Scope: adapter, production registration, failure reason, synthetic tests,
  Core version and canonical/closure docs only
- Actual source and generated artifacts: ignored, never staged
- One local closure commit is required; its final SHA is reported in the task
  handoff because a commit cannot contain its own hash
- Push: not authorized and not performed

## 25. Recommended next bounded slice

**D-MIG-LOAN-01 — decide CURRENT open/historical circulation handling.**

This is the single next slice because eight Copy-level open loan/circulation
records plus one contradictory Book-level/Copy-level end state are a cutover
blocker. No truthful circulation participant or complete dry-run can be defined
before the product decision. It must remain a decision/mapping slice; it does
not authorize apply/import.

**Verdict: PROFILE GO — MAPPING/DESIGN SLICES REQUIRED. STOP before dry-run or
apply/import.**
