# D-MIG-SERIES-MAP-01 — Current V1 Series mapping

Status: **DESIGN GO / CLOSED**

Date: 2026-09-17

Task severity: **High**

Scope: product, Series and migration-mapping design only. This document adds
no production mapper, migration participant, schema or runtime behavior and
authorizes no apply/import. V1 is source evidence, not product authority.

## 1. Decision inheritance

Authority for this decision, in order:

1. accepted V2 Series canon in the current repository;
2. implemented schema-1026 Series/domain contracts;
3. the closed CURRENT CAT Work mapping;
4. MIG-FND, RUN-01, RECON-01 and MIG-02-PRESERVE-01; and
5. the designated immutable CURRENT package as source evidence.

The audited checkout is clean `main` at
`b7202cc111158d930e9da54f099d39e1fa243778`. Product is `v2.001`, schema is
`1026`, Biblio Core is `2.44.0` and Biblio UI is `0.20.0`.

The task summary said that a Series role includes `Hoofddeel` and described
Dutch Series types. Current Git is more specific and wins: the old universal
`hoofddeel`/`novelle`/`companion`/`spin-off`/`omnibus` role list is explicitly
not the Series foundation. Canon instead separates membership state,
core/supplemental group, descriptive relation, order kind, position,
lifecycle and Series relationships. The conceptual order kinds are
`numbered`, `ordered_unnumbered` and `unordered`; none is implemented in the
current Series table or membership relation.

## 2. CURRENT source evidence

Only this designated package was used:

| Provenance | Audited value |
|---|---|
| Authoritative ZIP | `/Users/renee/Documents/Websites/Biblio_reference_archive/V1-baselines/data.zip` |
| Validated read-only extraction | `.local/migration-trial/worktree/.local/migration-source/current/source-intake-20260915/extracted/data/` |
| Local intake ZIP SHA-256 | `835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c` |
| Extracted manifest SHA-256 | `35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67` |
| Adapter | `current-v1-json-29` |
| Source version | `books-29.authors-2.reading-goals-2` |

The OS denied a fresh read of the archive outside the repository even after a
read-only permission request. The already validated local intake ZIP was
re-hashed and exactly matches the designated expected ZIP digest. The
extraction was not changed or recreated. DATA-01, fixtures and historical
exports were not used.

CURRENT contains 1,139 Books. The base-Book Series population is:

| Evidence | Count |
|---|---:|
| `series=true` | 164 |
| Series-related base Book occurrences | 164 |
| Non-empty `seriesName` | 161 |
| Exact raw names | 58 |
| Non-empty `seriesNumber` | 108 |
| Name without position | 54 |
| Position without name | 1 |
| Flag without name or position | 2 |
| Integer-looking positions | 105 |
| Decimal-looking positions | 3 |
| Zero positions | 1 |
| Negative positions | 0 |
| Syntactically invalid numeric positions | 0 |
| Year-like outliers | 2 (`2019`, `2020`) |

## 3. Existing V2 Series canon

The implemented minimal foundation consists of:

- stable binary `SeriesId` identity and a mutable display name;
- name equality that never merges identities;
- central, platform-wide `Series` records;
- unique Work + Series membership;
- `SeriesPosition::unknown()` or a normalized non-negative decimal with at
  most 14 integer and 6 fractional digits; and
- batched Work↔Series reads used by existing discovery and catalog reads.

Schema 1026 still uses the schema-1009 shape:

```text
biblio_series(series_id, display_name)
biblio_work_series(work_id, series_id, series_position NULL)
```

It has no persisted Series kind, confirmation state, role, group,
descriptive relation, order type/scheme, lifecycle or provenance column.
Rich Series Intelligence and a dedicated Series index/detail module remain
V2.002+. The minimal Work→Series relationship remains V2.001 migration data.

## 4. Source shape

All 1,139 Book rows have exactly these base fields and datatypes:

| Field | Type | Meaning proven by source shape |
|---|---|---|
| `series` | boolean | legacy flag |
| `seriesName` | string | optional raw Series label |
| `seriesNumber` | string | optional raw Series number/label |

They are singular Book-level fields. One base Book cannot represent more than
one Series occurrence through this shape. Empty is consistently `""`, not
`NULL`. All 161 populated names are 3–35 characters, valid JSON strings, have
no outer/repeated-whitespace anomaly and fit the V2 512-character limit.

`containedWorks[]` is a separate nested structure. Each child has its own
string `series` and `seriesIndex`. It must not be projected as the container
Book's Series membership.

Three exact raw Series names contain digits, across 13 occurrences. Their
digits remain part of the label; no prefix, suffix or volume number is stripped.

## 5. Series identity problem

CURRENT has no stable Series ID. The following strategies were compared:

| Strategy | Result | False merge | False split | Replay/reconciliation and downstream effect |
|---|---|---|---|---|
| A. occurrence-scoped identity | 161 Series identities for 161 named Books | Lowest | High: 30 repeated exact-name groups become duplicates | Stable per Book, but destroys shared browsing/order and leaves 103 avoidable duplicate identities to reconcile later |
| B. reviewed exact-raw-name grouping | 58 identities and 161 memberships | Non-zero: equal labels are not proof under current canon | Low within exact byte-identical labels; near variants remain split | Deterministic and useful, but requires an explicit product exception for this manifest-bound migration contract |
| C. normalized/name-cluster grouping | At least the two candidate variant pairs could collapse | Higher and not measurable from CURRENT | Lower cosmetically | Rejected: case/punctuation/linguistic normalization is not identity and no reviewed cluster contract exists |
| D. preserve only | No active Series or membership | None | No active structure | Lossless but makes CURRENT Series unusable in V2.001 despite the migration requirement |
| E. rich Series Intelligence proposal | No current executable target | Deferred | Deferred | Current schema/runtime cannot persist proposal/confirmation for Series identity or membership |

Renée explicitly selected Strategy B for this exact manifest-bound CURRENT
snapshot on 2026-09-17. Strategy A remains unselected and would conflict with
the strong repeated structure: 133 occurrences belong to 30 repeated
exact-name groups, while 28 groups are singletons. Strategy C is not permitted.
Strategy D is not needed for named base occurrences under the approved rule.

Schema 1026 cannot label a created Series or membership as provisional or
unverified. The approved rule therefore creates one migration-derived active
minimal Series identity per exact raw name without claiming rich
`canonical-confirmed` Series Intelligence.

## 6. Exact raw-name groups

The table accounts for every one of the 58 exact raw-name groups. The
16-hex labels are privacy-minimized report labels (SHA-256 prefixes), not
semantic source identities. `Compatible` means no invalid position and no
same-CAT-Work contradictory evidence; it does not turn the raw name into
identity or make decimal/year values safe positions.

| Raw-name SHA-256 prefix | Occ. | Books | CAT Works | Pos. | Distinct positions | Structural note |
|---|---:|---:|---:|---:|---|---|
| `143dc2610e2de1a7` | 20 | 20 | 20 | 20 | 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 | compatible |
| `ba881f847cc7aed7` | 15 | 15 | 15 | 4 | 1, 2, 3, 4 | compatible |
| `285ffcdb2dad0e44` | 14 | 14 | 14 | 14 | 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14 | compatible |
| `1ebfa9cc588ad9dd` | 9 | 9 | 9 | 0 | — | compatible |
| `09e854a8082b73d5` | 6 | 6 | 6 | 4 | 1, 2, 3, 4 | compatible |
| `77cbc917bf4a2e8f` | 5 | 5 | 5 | 0 | — | compatible |
| `fff3200bb642300d` | 5 | 5 | 5 | 5 | 0, 1, 2, 3, 4 | compatible |
| `af580ba0af737434` | 4 | 4 | 4 | 4 | 1, 2, 3, 4 | compatible |
| `d9cad46452fadb6e` | 4 | 4 | 4 | 4 | 1, 2, 3, 4 | compatible |
| `01ff9a6098a2f3cb` | 3 | 3 | 3 | 3 | 1, 4, 5 | gaps 2, 3 |
| `0604cd3138feed20` | 3 | 3 | 3 | 0 | — | compatible |
| `0a1a1b7723095863` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `1d9f514b16d68235` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `5afe3b1c079e9600` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `9ced86aa8bc608c8` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `b00f3628c854448a` | 3 | 3 | 3 | 0 | — | compatible |
| `d1e07d61321a154e` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `e1ac78764034848a` | 3 | 3 | 3 | 3 | 1, 2, 3 | compatible |
| `043be8b23b4ec3f1` | 2 | 2 | 2 | 0 | — | compatible |
| `0f341b6edd942c54` | 2 | 2 | 2 | 2 | 1, 2 | compatible |
| `57083d4457841f63` | 2 | 2 | 2 | 2 | 1, 2 | compatible |
| `6ca52258a43795ab` | 2 | 2 | 2 | 0 | — | compatible |
| `76d0e84432b90d49` | 2 | 2 | 2 | 2 | 2019, 2020 | outlier 2019, 2020 |
| `854664a00f89e60e` | 2 | 2 | 2 | 2 | 2, 3 | compatible |
| `ad84d09acc0ca13a` | 2 | 2 | 2 | 2 | 1, 2 | compatible |
| `bb84268fc4d3e521` | 2 | 2 | 2 | 0 | — | compatible |
| `ddc95a16ccee43a7` | 2 | 2 | 2 | 2 | 3, 6 | gaps 4, 5 |
| `ed1ef6ccfae32dad` | 2 | 2 | 2 | 0 | — | compatible |
| `f037f38881bd9bac` | 2 | 2 | 2 | 0 | — | compatible |
| `f984f4bdcde04656` | 2 | 2 | 2 | 0 | — | compatible |
| `006c0d5f39584b65` | 1 | 1 | 1 | 1 | 2 | compatible |
| `0b87a2f0d85d7c9b` | 1 | 1 | 1 | 1 | 12 | compatible |
| `114af38ea7a54fd4` | 1 | 1 | 1 | 1 | 2 | compatible |
| `22d1dfcddf7680c5` | 1 | 1 | 1 | 0 | — | compatible |
| `2b92d1850081af1b` | 1 | 1 | 1 | 0 | — | compatible |
| `307385f1e51f8346` | 1 | 1 | 1 | 0 | — | compatible |
| `364836c69ab57db4` | 1 | 1 | 1 | 0 | — | compatible |
| `4466113e995d3389` | 1 | 1 | 1 | 1 | 2 | compatible |
| `44bc7de174008527` | 1 | 1 | 1 | 1 | 2 | compatible |
| `4d4b9f739808de7f` | 1 | 1 | 1 | 1 | 1 | compatible |
| `4e3c82e2eca57361` | 1 | 1 | 1 | 1 | 2 | compatible |
| `4fa7ac82383e1dd8` | 1 | 1 | 1 | 0 | — | compatible |
| `582ec85a04120d9f` | 1 | 1 | 1 | 1 | 2 | compatible |
| `59c13e23dfb5c3f4` | 1 | 1 | 1 | 1 | 1 | compatible |
| `5a1a14e9d14937a5` | 1 | 1 | 1 | 0 | — | compatible |
| `642a677974e153e3` | 1 | 1 | 1 | 1 | 3.4 | decimal/compound evidence; position unsafe |
| `6f7bf620afe4dcf9` | 1 | 1 | 1 | 1 | 1.2 | decimal/compound evidence; position unsafe |
| `7d230e5e77f2ab2b` | 1 | 1 | 1 | 1 | 1 | compatible |
| `814b15ac65f5dbeb` | 1 | 1 | 1 | 1 | 1 | compatible |
| `8516c75f40459f9c` | 1 | 1 | 1 | 1 | 3 | compatible |
| `8be98166ea87cbd4` | 1 | 1 | 1 | 0 | — | compatible |
| `a2a405faa050a0a5` | 1 | 1 | 1 | 1 | 1 | compatible |
| `a4477a94b5126a39` | 1 | 1 | 1 | 1 | 4 | compatible |
| `ad1e0cfcd4cf3a20` | 1 | 1 | 1 | 1 | 2 | compatible |
| `c54f7043faa13079` | 1 | 1 | 1 | 1 | 3.4 | decimal/compound evidence; position unsafe |
| `cf6958f2fa2b6f60` | 1 | 1 | 1 | 1 | 1 | compatible |
| `db716cb34cc26b37` | 1 | 1 | 1 | 0 | — | compatible |
| `fd5ba19b11a0afd4` | 1 | 1 | 1 | 0 | — | compatible |

No exact group has a duplicated position. Two partial groups have ordinary
integer gaps. Gaps are evidence absence and are never compacted or renumbered.

## 7. Near-duplicate names

Comparison-only normalization found exactly two candidate pairs:

- one case-only pair, four occurrences total; and
- one apostrophe/punctuation-only pair, two occurrences total.

No outer/repeated-whitespace pair exists. Neither pair is merged. No
lowercasing, punctuation stripping, singular/plural handling, abbreviation,
author removal, numeral removal, fuzzy matching or synonym matching becomes
identity. The 58 exact raw groups therefore remain 58 unless the open exact
raw-name rule is rejected entirely.

## 8. Chosen identity contract

The closed identity contract is:

```text
same non-empty decoded seriesName UTF-8 bytes
+ same adapter/source version/manifest
+ D-MIG-SERIES-MAP-01 exact-name-v1 contract
→ one migration-derived minimal Series identity
```

This is deliberately narrower than a global name lookup. It cannot reuse or
merge a pre-existing target Series by display name. It cannot group a near
duplicate. It cannot establish provider identity or rich canonical
confirmation. It is an exception only for the exact source, manifest and
mapping contract in this document, not a general product identity rule.

## 9. Display-name contract

The exact decoded `seriesName` UTF-8 bytes are the display name. Because
members of one group are byte-identical, there is no first-record winner and
no spelling choice. No cosmetic rewrite is performed. A target display-name
divergence on replay fails closed; display-name equality never selects an
existing target identity.

## 10. Series membership

Each named base occurrence attaches only through its exact CAT dependency:

```text
catalog_work:v1.book/<book-id>/work
→ reviewed migration-derived Series source identity
```

Membership is Work-level. There is no Edition-, Item-, title-, Author- or
ad-hoc ISBN lookup. The membership occurrence remains independently traceable
even when CAT reuses the representative Work for a duplicate-ISBN alias.

## 11. Series role

CURRENT has no role field. Current V2 minimal membership has no role field.
`Hoofddeel` is not an accepted universal technical role. Therefore the mapper
must emit **no role** and must not infer core/supplemental, spin-off,
companion, contained-Series or omnibus semantics. This is resolved by current
canon and needs no product question.

## 12. Series type

CURRENT does not prove a Series order kind. `seriesNumber` presence alone must
not derive `numbered`; partial/missing numbers must not derive
`ordered_unnumbered` or `unordered`. Schema 1026 has no type/order-kind field.

The future rich order kind therefore remains unprojected and unresolved for
all 58 migration-derived identities. No separate durable observation is needed for
an inferred value that does not exist in the source.

## 13. Position semantics

The source field is `seriesNumber`, but its population proves mixed legacy
usage: ordinary integer numbers, compound decimal-looking labels and
year-based labels. It does not prove reading order, publication order or a
general display sequence.

The safe rule is component-specific:

- an ordinary non-negative integer on a named base Series occurrence may map
  exactly to current `SeriesPosition`;
- absence remains `NULL`/unknown;
- decimals and year-like labels do not map to current numeric position;
- no value establishes a Series order kind, group or completeness; and
- no value is rounded, compacted or renumbered.

## 14. Integer positions

There are 105 integer-looking source values. Of these:

- 102 belong to a named base occurrence and are safe active positions,
  including the explicit value `0`;
- one value belongs to the position-without-name occurrence and cannot become
  an active membership; and
- `2019` and `2020` are year-like contextual labels, not safe ordinal
  positions.

Ordinary mapped values range from `0` through `20`. There are no negative or
syntactically invalid values and no duplicate position inside an exact raw-name
group. The two observed gap sets remain gaps.

## 15. Decimal positions

The three values are `1.2` once and `3.4` twice. Although current
`SeriesPosition` can store those decimal strings, source structure shows
compound/multiple-Work evidence rather than a reliable fractional order: all
three parent Books are flagged as multiple-Work and two have two explicit
contained Works. Mapping them as 1.2/3.4 would silently reinterpret a compound
label as a point between ordinal volumes.

The membership therefore uses unknown position. The exact raw decimal evidence
is admitted separately as `preserved_deferred` with
reason `series_position_not_safely_mappable`. It is never rounded.

## 16. Anomalous/outlier positions

The two values `2019` and `2020` occur in one exact raw-name group. Their Book
labels independently carry the same year and no publication date is present.
This establishes year-based contextual labeling, not an ordinary ordinal
Series position. Both memberships use unknown position; the raw value is
preserved with `series_position_not_safely_mappable`.

They are valid but not currently projectable, so they are preserved rather
than quarantined.

## 17. Missing position

Fifty-four named occurrences have no position. Missing position does not
discard membership. If identity is approved, all 54 map to the exact Work and
Series with `SeriesPosition::unknown()`. No `0`, `1` or next-free value is
invented. They do not establish `unordered` or any other order kind.

Together with the five decimal/year cases, the active population has 59
memberships with unknown position.

## 18. Missing name

Three `series=true` Books have no name: two also have no number; one has number
`1`. The singular base shape is valid and non-contradictory, but it cannot
identify a Series. No title, Author or position inference is allowed.

All three are `preserved_deferred` as one occurrence each with reason
`series_name_missing`. No unnamed/“Unknown Series” entity is created. This is
not quarantine because the source shape is valid and the evidence is merely
unprojectable.

## 19. CAT aliases

Two of the 17 duplicate-ISBN groups contain Series evidence:

- one group has Series evidence only on the CAT representative and none on its
  alias; and
- one group has Series evidence only on the alias and none on its CAT
  representative.

Thus Series-bearing representatives / aliases are `1 / 1`, groups with Series
evidence are `2`, and no group contains two competing Series payloads. The
representative occurrence follows its ordinary CAT Work dependency. The
Series-bearing alias retains its own membership occurrence identity but
depends on the representative CAT Work mapping. There is one
alias-to-representative membership convergence and zero conflict.

No representative preference is applied to Series evidence and no
last-write-wins behavior exists. A future group in which representative and
alias resolve to the same Work with incompatible Series/position evidence must
fail closed.

## 20. CAT-quarantined Books

Neither of the two invalid-only CAT-quarantined Books contains base or
contained-work Series evidence. Counts are therefore:

- CAT-quarantined Books with Series evidence: `0`;
- dangling Series membership attempts: `0`; and
- Series preservation observations caused by CAT quarantine: `0`.

## 21. Contained-work boundary

CURRENT has 22 contained-work occurrences. Nineteen contain Series-related
evidence: 17 names, 19 positions, two positions without a name, six distinct
raw names, no decimals and no invalid numeric shape. Three contained Works
contain neither Series name nor position.

Contained Works have no CAT Work mappings in the current migration lane. All
19 Series-related occurrences therefore become separate
`preserved_deferred` evidence with reason
`contained_work_series_deferred`. They create no base-Book membership and no
new Work. The structural source slot is retained for future promotion.

## 22. Source identities

Deterministic identities are:

| Meaning | Source type and identity |
|---|---|
| Series entity plan | `catalog_series:v1.series/exact-raw-name-v1/<base64url-of-exact-UTF8-name>` |
| Base membership occurrence | `catalog_work_series:v1.book/<book-id>/series-membership` |
| Unmappable base position | `preserved_source_evidence:v1.book/<book-id>/series-position` |
| Missing-name base evidence | `preserved_source_evidence:v1.book/<book-id>/series-membership` |
| Contained Series evidence | `preserved_source_evidence:v1.book/<book-id>/contained-work/<one-based-slot>/series` |

For the Series entity identity, `base64url` is defined exactly as RFC 4648
section 5 over the decoded JSON string's exact UTF-8 bytes, using the URL-safe
`A-Z a-z 0-9 - _` alphabet with all trailing `=` padding omitted. There is no
Unicode normalization, case folding, whitespace normalization or
decode/re-encode beyond JSON decoding followed by UTF-8 encoding. The longest
current full source identity is 75 bytes and fits the 191-character contract.

The namespace includes the reviewed grouping-contract version and
collision-safe reversible raw bytes. A hash alone is not semantic identity.
Membership and preservation identities use stable source Book identity and an
exact structural slot, never a target DB ID or random UUID.

## 23. Typed migration contract

No source-neutral Series migration plan or participant currently exists. The
smallest later implementation needs conceptually:

### `CatalogSeriesPlan`

- migration-derived Series source identity;
- exact display name; and
- exact reviewed contract/provenance binding.

### `CatalogWorkSeriesPlan`

- exact CAT Work source dependency;
- exact Series source dependency;
- optional `SeriesPosition`;
- base membership occurrence source identity; and
- no role, type, group or confirmation claim.

The Series plan writes identity only. The membership plan writes the Work edge
only after both exact dependencies resolve. Both require source-neutral
participants/writers, replay/divergence checks, reconciliation target
inspection and caller-owned `CommitMigrationRecordService` transactions.

The five unsafe position components, three missing-name base occurrences and
19 contained-work occurrences need new explicitly admitted
`PreservedSourceEvidencePlan` type/reason/privacy triples under the closed
MIG-02-PRESERVE-01 mechanism. Their executable contract is exact:

| Evidence type | Reason | Privacy | Source field | Canonical evidence-hash envelope |
|---|---|---|---|---|
| `current_v1_series_membership_without_name` | `series_name_missing` | `ordinary_source` | `seriesName` | `source_slot`, exact boolean `series`, exact string `series_name`, exact string `series_number` |
| `current_v1_series_position` | `series_position_not_safely_mappable` | `ordinary_source` | `seriesNumber` | `source_slot`, exact string `series_name`, exact string `series_number` |
| `current_v1_contained_work_series` | `contained_work_series_deferred` | `ordinary_source` | `containedWorks` | `source_slot`, positive `one_based_slot`, exact string `series`, exact string `series_index` |

For every row, `sourceFile="data/books.json"`,
`sourceCollection="books"`, `sourceEntityId=<exact Book id>`,
`occurrenceCount=1`, adapter/family/version/manifest are the exact values in
section 2, and `mappingContract` is
`d-mig-series-map-01.2026-09-17:<first-24-manifest-hex>` after DESIGN GO.
`evidenceSha256` is `DeterministicJson::hash()` of the listed typed envelope,
with keys serialized canonically by the existing runner contract.

For contained Works, the complete `series` + `seriesIndex` pair at one
one-based structural slot is one atomic observation. It is never split into a
name observation and a position observation. The locator remains the supported
`data/books.json#books/<encoded-book-id>/containedWorks`; the semantic source
identity and hash envelope bind the exact child slot. Reordering or changing
the nested evidence changes package provenance/hash and fails closed rather
than rebinding the occurrence.

The implementation slice must register exactly these three
type+reason+privacy admissions and add an internal manifest-bound verifier for
these ordinary bibliographic source shapes. It must verify the exact locator,
Book cardinality, field/slot and evidence envelope without creating a general
raw-source escape hatch. Diagnostics alone are insufficient.

## 24. Series Intelligence / provisional state

Schema 1026 cannot persist `user-confirmed`, `user-rejected`,
`canonical-confirmed` or a generic provisional Series state. The approved
plans above therefore target only the current minimal Work→Series foundation.
They must not be described as rich canonical confirmation and cannot power
completeness, group, lifecycle or confirmed-order claims.

No provider lookup or enrichment occurs. Future Series Intelligence may use
MIG-FND provenance as evidence for explicit reconciliation, but must not
auto-promote it. Renée explicitly accepted the absence of a state field for
this migration-derived minimal relation in section 28.

Schema 1026 is sufficient only for minimal Series identity, Work membership
and safe numeric position. It is not sufficient for rich Series Intelligence.

## 25. Preservation / quarantine

Bounded preservation reasons that actually occur are:

| Evidence type | Reason | Privacy | Occurrences | Atomic boundary |
|---|---|---|---:|---|
| `current_v1_series_membership_without_name` | `series_name_missing` | `ordinary_source` | 3 | one base Book's exact Series flag/name/number fields |
| `current_v1_series_position` | `series_position_not_safely_mappable` | `ordinary_source` | 5 | one named base membership's exact raw position component |
| `current_v1_contained_work_series` | `contained_work_series_deferred` | `ordinary_source` | 19 | one contained-Work slot's exact Series name/index pair |

No current observation is malformed or contradictory under the reviewed
rules, so current quarantine count is zero. Future invalid structure,
same-Work incompatible convergence or divergent replay fails closed and may be
quarantined only under a separately exact reason.

## 26. CURRENT mapping counts

### Source facts

| Metric | Count |
|---|---:|
| Base Series-related occurrences | 164 |
| Name occurrences / exact names | 161 / 58 |
| Positioned / name without position / position without name | 108 / 54 / 1 |
| Integer / decimal / zero / negative / year-like | 105 / 3 / 1 / 0 / 2 |
| Books with a Series name | 161 |
| Books capable of multiple base Series fields | 0 |

### Identity analysis

| Metric | Count |
|---|---:|
| Exact raw-name groups | 58 |
| Repeated / singleton groups | 30 / 28 |
| Occurrences in repeated groups | 133 |
| Candidate near-duplicate pairs kept separate | 2 |
| Approved exact-name Series identities | 58 |
| Occurrence-scoped alternative identities | 161 |

### Accepted mapping result

| Metric | Count |
|---|---:|
| Active Series plans | 58 |
| Active Work↔Series membership plans | 161 |
| Memberships with mapped position | 102 |
| Memberships with unknown position | 59 |
| Duplicate-ISBN groups with Series evidence | 2 |
| Series-bearing representatives / aliases | 1 / 1 |
| Duplicate-ISBN alias convergences / conflicts | 1 / 0 |
| Base preservation observations | 8 |
| Contained-work preservation observations | 19 |
| Total durable preservation observations | 27 |
| Quarantine / conflicts / unmatched CAT dependencies | 0 / 0 / 0 |
| Total typed Series/membership/preservation plans | 246 |

The 27 preservation observations include five position components beside an
active membership. They are not counted as extra base membership occurrences.
The 246 typed-plan total is a planning count, not a claim of 246 distinct raw
source occurrences. Reconciliation must report entities, memberships and
preserved components separately rather than adding them into a fictitious
source total.

## 27. Downstream effects

- existing Work detail/discovery can show one shared raw Series label and the
  102 safe positions;
- unknown positions sort after known positions without fabricated order;
- a future Series index/detail gets 58 migration-derived identities rather
  than 161 occurrence duplicates;
- the two near-duplicate pairs remain visibly separate until explicit central
  reconciliation;
- no completeness, next-volume, core/supplemental, lifecycle or confirmed
  order claim is allowed;
- search/filtering may consume only reliable stored minimal context through
  separately approved routes; and
- future provider/Series Intelligence reconciliation starts from exact
  provenance and never from display-name lookup.

The rejected occurrence-scoped alternative would avoid false merge but make
shared browsing and ordering misleadingly fragmented. Preserve-only would
avoid both false merge and active corruption but remove otherwise explicit
CURRENT relations from daily V2.001 use.

No UI/Search change is part of this design or its implementation slice.

## 28. Product decision

**CLOSED — Renée approved the exact-name migration identity rule on
2026-09-17.**

For this exact manifest-bound CURRENT V1 migration, all occurrences whose
decoded, non-empty `seriesName` values have byte-identical UTF-8 encodings map
to one migration-derived active minimal Series identity. This creates 58
Series identities. The two near-duplicate pairs remain separate. No case,
punctuation, whitespace, fuzzy, synonym or provider merge is authorized, and
the rule does not generalize name equality into product-wide Series identity.

The decision explicitly asserts no provisional status, `canonical-confirmed`,
completeness, Series Intelligence confirmation, group/lifecycle semantics,
confirmed ordering, Series type or Series role. No product question remains.

## 29. Required implementation slice

The recommended next, separately authorizable slice is:

```text
MIG-02-SERIES-MAP-01 — Current V1 Series mapper
```

This design does not authorize that implementation. Starting it requires a
new explicit instruction from Renée.

Its bounded scope is:

- CURRENT Series source to typed `CatalogSeriesPlan` and
  `CatalogWorkSeriesPlan` equivalents;
- exact CAT Work dependencies and alias convergence;
- the approved manifest-bound identity contract;
- safe integer position and unknown position;
- admitted durable preservation for missing name, unsafe position and
  contained-work Series evidence;
- deterministic dry-run/reconciliation accounting; and
- zero-write CURRENT proof.

It excludes apply/import, provider calls, rich Series Intelligence, schema/UI/
Search changes, contained-Work creation, Work merge, Wishlist and Archive.

## 30. Acceptance criteria

DESIGN GO / CLOSED is established because all of the following are explicit:

- Renée explicitly closed the section-28 identity/state question;
- exact-name and near-duplicate behavior are explicit;
- display name is deterministic and never used for target lookup;
- each active membership depends on exact CAT Work and Series mappings;
- role is absent, not `Hoofddeel`;
- order kind/type remains unprojected;
- 102 safe integer positions map exactly and 59 positions remain unknown;
- decimals/year labels are durably preserved, never rounded or coerced;
- missing-name and contained-work evidence is durably preserved;
- all three preservation type+reason+privacy admissions, locators, exact
  atomic envelopes and deterministic hashes are explicit;
- alias convergence, CAT quarantine and unmatched dependency behavior are
  exact;
- deterministic semantic source identities are used;
- rich confirmation/completeness is not claimed;
- all 164 base and 19 contained Series-related observations are accounted;
- source/manifest and CAT provenance remain exact;
- no production mapper, schema/runtime/UI change or apply/import occurs in the
  design slice; and
- independent review finds no unresolved blocker or open product question.

The design verdict is **DESIGN GO / CLOSED**. It closes only this design and
does not authorize implementation. The later bounded implementation slice
requires a new explicit instruction. No Series mapper, apply/import, Wishlist
or Archive work starts automatically.
