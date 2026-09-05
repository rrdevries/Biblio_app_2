# Metadata benchmark independent review passes

Date: 2026-09-05
Method: explicit second-pass reviews after results generation; no separate
reviewer agent was available/authorized.

## 1. Metadata and data-quality review

PASS WITH LIMITATIONS. ISBN checksums, ISBN-10 conversion, deduplication and
invalid-ISBN exclusion are automated. Every provider has exactly N=300. The
27-record NL reference shortfall is documented and deterministically filled.
The principal limitation is local-ground-truth bias: some V1 fields may have
originated from providers. Accuracy numbers must not be generalized beyond
verifiable fields, and conflicts require human adjudication.

## 2. Biblio Domain / Work-Edition review

PASS. Exact ISBN is never used as automatic Work identity. Edition correctness
requires title/author compatibility. Work-link coverage is reported separately.
The recommendation preserves Work, Edition and Item separation and makes no
canonical overwrite or structural merge decision.

## 3. Series Intelligence review

PASS / NO-GO FOR AUTOMATION. The known-series subset is N=31 and known-position
subset N=22. The best provider, Open Library, is correct on only 19.35% of known
series and no provider produced a correct measured position. No completeness or
core/supplemental claim is made. Series evidence remains proposal-only.

## 4. Security and privacy review

PASS. The fixture excludes personal and operational user data. External calls
contain ISBNs only. The Google key is environment-only; automated scans found no
credential marker in caches or results. No source dataset, secret or temporary
BookBrainz dump is committed. No external write or account change occurred.

## 5. Product and commercialization review

PASS WITH CONDITIONS. Coverage supports a temporary OL → Google evidence path,
not a Google dependency. Google terms/branding/caching and cover rights need
legal/licensing review before commercialization. Open Library's API is not a
high-traffic backend; dumps or a licensed source are needed for scale. Wikidata
and BookBrainz do not justify first-release runtime complexity on measured gain.

## Cross-check findings corrected during review

- The initial Wikidata attempt used unhyphenated P212 values and falsely yielded
  zero hits. It was replaced by masked ISBN queries; final total hit is 4.33%.
- Six transient Google 503s were retried; all final Google rows are HTTP 200.
- BookBrainz author credits were added from the official dump before final
  metrics.
- Conflict measurement was restricted to EXACT/PROBABLE, non-empty comparable
  fields and semantic/date/page/language comparisons.
- Series combination coverage now uses the known-series denominator rather than
  all 300 records.

No residual integrity failure remains. Product suitability remains conditional,
not proven GO.
