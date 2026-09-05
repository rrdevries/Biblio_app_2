# Metadata benchmark summary

Status: completed
Scope: metadata provider benchmark only
Sample: 300 unique valid ISBNs (NL 162, EN 110, other/unknown 28)

## Management conclusion

No single free provider satisfies Biblio v2.001. Open Library is a useful open
base for English Editions and Work links, but its Dutch hit rate is only 27.78%.
Google Books lifts the Open Library fallback stack to 99.67% hit and 95.33%
strict exact-Edition coverage, including 99.38%/95.68% for NL, but supplies no
reliable Work or series relationship in this run and carries material
commercial-use, caching and attribution risk. Wikidata and BookBrainz add almost
no incremental hit coverage on this sample.

The free stack is therefore CONDITIONAL for both NL and EN: usable only as
provider-independent evidence with local confirmation, field provenance,
conflict handling and no Google lock-in.

## Edition-level result by provider and language

Percentages use N in the first column.

| Provider | Lang | N | Hit | Exact | Probable | Wrong edition | Wrong work | Ambiguous | Miss | Error |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Open Library | Total | 300 | 57.67% | 55.00% | 2.33% | 0.00% | 0.33% | 0.00% | 42.33% | 0.00% |
| Open Library | NL | 162 | 27.78% | 27.16% | 0.00% | 0.00% | 0.62% | 0.00% | 72.22% | 0.00% |
| Open Library | EN | 110 | 95.45% | 90.91% | 4.55% | 0.00% | 0.00% | 0.00% | 4.55% | 0.00% |
| Google Books | Total | 300 | 97.33% | 82.33% | 10.33% | 4.00% | 0.67% | 0.00% | 2.67% | 0.00% |
| Google Books | NL | 162 | 98.15% | 87.65% | 6.79% | 3.70% | 0.00% | 0.00% | 1.85% | 0.00% |
| Google Books | EN | 110 | 96.36% | 76.36% | 14.55% | 4.55% | 0.91% | 0.00% | 3.64% | 0.00% |
| Wikidata | Total | 300 | 4.33% | 4.00% | 0.33% | 0.00% | 0.00% | 0.00% | 95.67% | 0.00% |
| Wikidata | NL | 162 | 0.00% | 0.00% | 0.00% | 0.00% | 0.00% | 0.00% | 100.00% | 0.00% |
| Wikidata | EN | 110 | 10.91% | 10.00% | 0.91% | 0.00% | 0.00% | 0.00% | 89.09% | 0.00% |
| BookBrainz | Total | 300 | 3.00% | 2.00% | 1.00% | 0.00% | 0.00% | 0.00% | 97.00% | 0.00% |
| BookBrainz | NL | 162 | 0.00% | 0.00% | 0.00% | 0.00% | 0.00% | 0.00% | 100.00% | 0.00% |
| BookBrainz | EN | 110 | 7.27% | 4.55% | 2.73% | 0.00% | 0.00% | 0.00% | 92.73% | 0.00% |

## Provider field quality, total sample

Accuracy excludes missing and unverifiable local ground truth.

| Provider | Title cov/acc | Author cov/acc | Language cov/acc | Publisher cov/acc | Date cov/acc | Pages cov/acc | Cover | Work link |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Open Library | 57.67/95.95% | 44.00/99.23% | 49.33/100.00% | 56.67/97.60% | 55.00/96.36% | 41.00/95.80% | 43.67% | 57.33% |
| Google Books | 97.33/92.81% | 93.33/94.25% | 97.33/99.25% | 36.00/42.59% | 97.33/88.42% | 72.67/71.20% | 49.33% | 0.00% |
| Wikidata | 4.33/92.31% | 2.33/100.00% | 4.00/90.91% | 3.67/72.73% | 4.33/61.54% | 2.67/0.00% | 0.33% | 4.00% |
| BookBrainz | 3.00/88.89% | 2.67/71.43% | 3.00/100.00% | 2.00/83.33% | 2.67/87.50% | 2.67/50.00% | 0.00% | 3.00% |

The low Google publisher accuracy is based on only 108 provider-present values
and is strongly exposed to imprint/publisher naming differences. It must be
treated as conflict evidence, not a direct statement that Google is wrong.

## Series subbenchmark

Known series: N=31; locally known positions: N=22.

| Provider | Series present on known subset | Correct series | Position present | Correct position |
|---|---:|---:|---:|---:|
| Open Library | 32.26% | 19.35% | 0.00% | 0.00% |
| Google Books | 0.00% | 0.00% | 0.00% | 0.00% |
| Wikidata | 0.00% | 0.00% | 0.00% | 0.00% |
| BookBrainz | 0.00% | 0.00% | 0.00% | 0.00% |

No provider supported a reliable core/supplemental or related-series conclusion.
External series data remains proposal/evidence only.

## Provider combinations

| Combination | Lang | Hit | Exact Edition | Work link | Cover | Series evidence (known subset) | Any conflict |
|---|---|---:|---:|---:|---:|---:|---:|
| Open Library | Total | 57.67% | 55.00% | 57.33% | 43.67% | 19.35% | 0.00% |
| Google Books | Total | 97.33% | 82.33% | 0.00% | 49.33% | 0.00% | 0.00% |
| OL → Google | Total | 99.67% | 95.33% | 57.33% | 63.00% | 19.35% | 35.00% |
| OL + Wikidata | Total | 57.67% | 55.00% | 57.33% | 43.67% | 19.35% | 3.33% |
| OL → Google + Wikidata | Total | 99.67% | 95.33% | 57.33% | 63.00% | 19.35% | 35.67% |
| Full free stack | Total | 99.67% | 95.33% | 57.33% | 63.00% | 19.35% | 36.33% |
| Full free stack | NL | 99.38% | 95.68% | 27.16% | 37.04% | 26.67% (N=15) | 9.88% |
| Full free stack | EN | 100.00% | 96.36% | 95.45% | 95.45% | 13.33% (N=15) | 74.55% |

Google adds 42.00 percentage points hit coverage over Open Library overall,
71.60 points for NL and 4.55 points for EN. Wikidata and BookBrainz add 0.00
points hit coverage over OL → Google.

The high EN any-conflict rate is dominated by publisher/imprint and page-count
disagreement. In the full stack's comparable rows, conflict rates were title
10.90%, author 8.47%, publisher 63.54%, publication year 20.95%, pages 55.06%
and language 0.00%. These are field-level evidence collisions, not adjudicated
provider errors.

## Performance and operations

| Provider | Mean | Median | Benchmark errors | Access notes |
|---|---:|---:|---:|---|
| Open Library | 2,699.72 ms/batch-attributed record | 2,702.56 ms | 0.00% | 8 batched calls; official default 1 rps, identified 3 rps; dumps for bulk/high traffic |
| Google Books | 696.59 ms | 820.15 ms | 0.00% final | Key required; six transient 503s recovered with retry/backoff |
| Wikidata | 224.06 ms/batch-attributed record | 211.56 ms | 0.00% | 6 SPARQL batches; public WDQS has 60-second processing limits |
| BookBrainz | N/A | N/A | 0.00% | Weekly local dump; REST API alpha and no documented exact ISBN search |

## Verdicts

| Decision | Verdict | Evidence |
|---|---|---|
| A. Open Library as open base | CONDITIONAL | Strong EN and Work links; unacceptable NL standalone coverage |
| B. Google Books as temporary free fallback | CONDITIONAL | Major coverage gain; lower strict EN exactness, no Work/series link, commercial/caching/attribution review required |
| C. Wikidata enrichment/evidence | NO-GO | 4.33% total, 0% NL, no incremental hit/series gain |
| D. BookBrainz relationship/series evidence | NO-GO | 3.00% total, 0% NL, no series evidence in known subset |
| E. Total free stack for NL | CONDITIONAL | 99.38% hit and 95.68% exact, but only 27.16% Work, 37.04% cover and 26.67% known-series evidence |
| F. Total free stack for EN | CONDITIONAL | 100% hit and 96.36% exact, but 13.33% known-series evidence and Google/licensing/conflict dependence |

## Unsolved problem

The free stack does not deliver reliable Dutch Work identity, commercial-safe
metadata persistence, authoritative edition conflict resolution or usable
series order. It cannot support silent canonicalization.
