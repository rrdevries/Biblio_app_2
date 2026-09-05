# Metadata v2.001 provider recommendation

## Recommendation

Adopt no provider as source of truth. If Biblio chooses to build a later
provider-independent Metadata Hub, the minimum free v2.001 evidence stack should
be:

1. Open Library exact-ISBN lookup as the open first adapter.
2. Google Books exact `isbn:` lookup as a temporary fallback only.
3. No Wikidata runtime enrichment in the first release.
4. No BookBrainz runtime adapter in the first release; preserve an architectural
   relationship-evidence seam only.
5. Always preserve provider, provider record ID, retrieval time, queried ISBN,
   field provenance and conflicts; never silently overwrite confirmed fields.

This is a benchmark recommendation, not authorization to implement the stack.

## Why this order

Open Library alone gives 57.67% hit/55.00% strict exact coverage overall, but
95.45%/90.91% for EN and useful Work links. Google fallback raises the stack to
99.67% hit and 95.33% strict exact coverage. Its incremental hit gain is 42.00
points overall and 71.60 points for NL. Wikidata and BookBrainz add zero hit
points beyond OL → Google and no series-evidence gain.

## Required safeguards before implementation

- Provider-neutral DTOs and adapters; no Google IDs or response shapes in Core.
- Exact ISBN is Edition evidence only.
- Explicit `EXACT`, `PROBABLE`, `WRONG_EDITION`, `WRONG_WORK`, `AMBIGUOUS`,
  `MISS` and `ERROR` states.
- Field-level provenance and visible conflict review; the measured full-stack
  any-conflict rate is 36.33%.
- User-confirmed/local canonical data always wins over unconfirmed evidence.
- Separate Work-link confidence from Edition match confidence.
- Series data remains a proposal. No completeness, core/supplemental or related
  series claim without stronger evidence.
- Cache and cover behavior must comply with provider terms and response headers.

## Provider verdicts

- Open Library base: **CONDITIONAL**.
- Temporary Google fallback: **CONDITIONAL**.
- Wikidata enrichment in v2.001: **NO-GO**.
- BookBrainz relationship/series adapter in v2.001: **NO-GO**.
- Total free NL stack: **CONDITIONAL**.
- Total free EN stack: **CONDITIONAL**.

## Commercialization gate

Google Books must be re-evaluated legally and operationally before any paid or
commercial Biblio release. The current API/branding terms limit storage/caching,
require attribution and links, and say the API is not a commercial-service
replacement. A later product must be able to disable or replace Google without
changing Core or canonical records.

## Minimum bar for a paid/licensed provider

A paid source justifies cost only if a contractually usable benchmark shows all
of the following on an equivalent real-data sample:

- at least 95% strict exact-Edition coverage for both NL and EN independently;
- at least 80% reliable Work links for NL without using Google as the only
  fallback;
- at least 80% NL cover availability with explicit commercial display/cache
  rights;
- at least 70% correct-series coverage and 60% correct position coverage on the
  known-series subsets;
- materially fewer publisher/date/page conflicts, with authoritative field
  provenance and edition-format semantics;
- documented rate limits or SLA, bulk/synchronization path and stable IDs;
- explicit commercial rights for storage, caching, display, covers and derived
  local canonical metadata.

These thresholds are future procurement criteria, not new Biblio product
behavior.
