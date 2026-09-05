# Metadata benchmark methodology

Status: completed research benchmark, not a product implementation
Benchmark date: 2026-09-05
Benchmark version: 1
Random seed: `20260905`

## Purpose and fixed product boundaries

This benchmark measures official, free metadata sources as evidence for Biblio
v2.001. No provider is treated as source of truth. ISBN identifies an Edition;
Work, Edition and Item remain distinct. Provider data never overwrites local or
user-confirmed canonical data. No Metadata Hub, adapter, schema, WordPress or UI
code was implemented.

## Baseline

The user explicitly designated `Biblio_v507m_actuele-data.zip` as the current
V1 dataset. SHA-256 and machine-readable counts are in
`dataset-and-sample-report.json`.

| Measure | Count |
|---|---:|
| Package version | 507.0.13 |
| Dataset schema | 29 |
| Book records | 1,138 |
| Possession/copy records | 1,105 |
| Records with a valid ISBN-13 | 901 |
| Records with only a valid ISBN-10 | 85 |
| Records without ISBN | 150 |
| Records with an invalid ISBN | 2 |
| Unique valid canonical ISBN-13 values | 969 |
| Wishlist items | 41 |

The ZIP was opened read-only. The source dataset was not extracted into the
repository and was never modified.

## ISBN normalization and exclusions

Whitespace, punctuation and hyphens are removed before validation. ISBN-10 and
ISBN-13 checksums are validated. Valid ISBN-10 values are converted to their
canonical ISBN-13 equivalent for deduplication; an ISBN-10 representation is
retained where a 978 conversion exists. Invalid ISBNs are excluded from provider
miss denominators. Multiple local records with the same canonical ISBN are
collapsed to one candidate and the richest bibliographic reference record is
used.

## Sampling

The fixture contains exactly 300 unique valid ISBNs and only necessary
bibliographic reference fields. Selection within each stratum is deterministic
with seed `20260905`.

| Stratum | N |
|---|---:|
| Dutch fiction | 60 |
| Dutch knowledge/non-fiction | 40 |
| Dutch juvenile/picture/comic | 30 |
| Dutch cook/travel/reference | 27 |
| English fiction | 60 |
| English non-fiction/knowledge | 30 |
| Older ISBN-10/older editions | 30 |
| Difficult/edge cases | 20 |
| Proportional shortfall fill | 3 |

The Dutch cook/travel/reference source pool contained only 27 eligible unique
valid ISBNs. Three remaining positions were deterministically filled from unused
NL/EN candidates. Final local language labels are NL 162, EN 110, unknown 27 and
other 1. Unknown-language older/edge records are included in total metrics but
not in NL or EN denominators.

The known-series subset contains 31 records; 22 have a local series position.

## Privacy minimization

The fixture contains no local record IDs, notes, ratings, reviews, reading
history, acquisition data, lending data, personal names, copy notes or wishlist
details. Official APIs received only sample ISBNs. The Google key was read from
`GOOGLE_BOOKS_API_KEY`; it was not printed, logged, cached or written to files.

## Providers and official access paths

- Open Library: batched official Books API with `jscmd=details`, 40 ISBNs per
  request. Responses were cached. The official service asks clients to cache,
  avoid hundreds of single-record calls and use dumps for bulk/high-traffic use.
- Google Books: official Volumes API, exact `isbn:` query, up to 10 results,
  authenticated by the environment API key. Each ISBN was queried separately;
  transient 503 responses were retried with backoff.
- Wikidata: official WDQS SPARQL endpoint in batches of 50. P212 values were
  queried in officially masked/hyphenated ISBN form using `isbnlib==3.10.14`.
  Work (`P629`), author, publisher, language, date, page, image and qualified
  series statements were requested.
- BookBrainz: official weekly PostgreSQL dump dated 2026-08-31, because the
  alpha REST search endpoint documents alias search but no exact identifier
  lookup. Only matching ISBN identifiers and linked current Edition data were
  extracted. The 133,585,318-byte compressed dump stayed in `/private/tmp` and
  was not committed.
- Library of Congress: not run. It was optional; no extra coverage claim is made.

## Edition classification

- `EXACT`: returned exact ISBN plus compatible title and author evidence.
- `PROBABLE`: exact ISBN, but reference/provider metadata is incomplete or
  divergent enough that exact-edition certainty is reduced.
- `WRONG_EDITION`: candidate resembles the Work but does not return the queried
  ISBN.
- `WRONG_WORK`: candidate conflicts strongly with title/author reference.
- `MISS`: no record.
- `AMBIGUOUS`: multiple candidates without a reliable automatic choice.
- `ERROR`: provider request failed; errors are not counted as misses.

Title comparison is Unicode/case/diacritic normalized. Author comparison
requires a compatible contributor name. Exact ISBN alone is not automatically
scored as correct.

## Field assessment and denominators

Fields use `CORRECT`, `INCORRECT`, `MISSING` and `UNVERIFIABLE`. Coverage is
provider presence over the relevant sample. Accuracy is calculated only where
local reference ground truth exists and is verifiable; missing local ground
truth never counts as a provider error. Series coverage and correctness use the
31-record known-series subset, while position metrics use the 22-record subset.

Provider conflict rates compare only non-empty values from records classified
EXACT or PROBABLE. Text values use semantic-normalized thresholds; dates compare
year, pages compare integers and languages compare normalized codes. A conflict
is evidence requiring adjudication, not proof that either provider is wrong.

## Reproducibility

Commands and dependencies are in `../README.md` and `../requirements.txt`.
Raw response caches are local under `cache/` and intentionally Git-ignored,
especially because Google terms restrict permanent/cached copies. Normalized
CSV rows and the machine-readable JSON summary are the durable evidence.

## Limitations

- V1 bibliographic values are a reference, not absolute truth. Some were likely
  originally provider-enriched; this can bias field accuracy toward the source
  from which they came.
- Automated comparison cannot independently adjudicate every imprint, format,
  reprint or page-count conflict. Divergent cases are review candidates.
- The sample represents this one current Biblio V1 dataset, not the whole Dutch
  or English book market.
- Server location and Google content availability can affect cover/result data.
- BookBrainz dump performance is not comparable to HTTP latency.
- This is not a legal opinion. Unclear commercial terms require legal/licensing
  review.

## Official documentation consulted

- https://openlibrary.org/developers/api
- https://openlibrary.org/developers/licensing
- https://developers.google.com/books/docs/v1/using
- https://developers.google.com/books/docs/v1/reference/volumes/list
- https://developers.google.com/books/branding
- https://developers.google.com/terms/
- https://www.wikidata.org/wiki/Help:Data_access
- https://www.mediawiki.org/wiki/Wikidata_Query_Service/User_Manual
- https://bookbrainz.org/develop
- https://bookbrainz.org/licensing
- https://api.bookbrainz.org/1/docs/
