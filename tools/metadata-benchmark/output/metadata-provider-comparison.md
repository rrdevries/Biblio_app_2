# Metadata provider comparison

## Open Library

Strengths: open access, explicit Edition and Work identifiers, excellent EN hit
(95.45%) and exact-Edition performance (90.91%), high accuracy where fields are
present. Weaknesses: NL hit 27.78%, 43.67% total cover coverage, and no series
position. Work links are the main differentiator at 57.33% total and 95.45% EN.

Operationally, the benchmark used eight cached batch calls. Official guidance
sets 1 request/second by default and 3/second for identified clients, discourages
hundreds of single-record calls and recommends monthly dumps for bulk or
high-traffic use. The Internet Archive asserts no new rights in the database but
warns that contributed material can have pre-existing rights. Commercial use
therefore still requires a content/licensing review.

## Google Books

Strengths: highest individual hit rate (97.33%), especially NL (98.15%); strong
title, author, language and date availability; 49.33% total and 90.00% EN cover
coverage. Weaknesses: strict exact-Edition rate is 82.33% total/76.36% EN;
4.00% wrong-edition and 0.67% wrong-work outcomes; no usable Work or series
relationship was returned. Publisher and page values conflict frequently with
other evidence/local reference.

The final keyed run completed 300/300 with HTTP 200; six transient 503s were
recovered. The earlier anonymous/keyless preflight returned HTTP 429 with zero
project quota and is excluded from scores.

Google requires an API credential for public data. Its API terms restrict
permanent copies and caching beyond allowed cache headers, and branding rules
require attribution and prominent Google links when displaying results. Google
also states the API is not intended to replace commercial services. Before any
paid/commercial Biblio release, use, persistence, caching, covers, attribution
and fallback behavior require legal/licensing review. Biblio must not depend on
Google-specific data models.

## Wikidata

Strengths: CC0 structured data, entity links and Work relationship potential;
12 exact/probable Edition hits carried 12 Work links. Weaknesses: only 4.33%
total hit, 0% NL and 10.91% EN; no series result on the 31 known-series records;
no incremental hit or Work coverage over OL → Google in this sample.

P212 must be queried in ISBN registration-group masked form. The public WDQS has
a 60-second processing budget per client per 60 seconds, a 60-second query
deadline and a compliant User-Agent requirement. Dumps are available; public
WDQS is not an SLA-backed commercial service.

## BookBrainz

Strengths: open bibliographic entity model, explicit Edition/Edition Group/Work
and relationship structures, weekly official dumps, core data under CC0.
BookBrainz is an open bibliographic project, not a direct Biblio consumer
competitor. Weaknesses: 3.00% total hit, 0% NL, no known-series evidence and no
incremental stack coverage in this sample.

The REST service is alpha and its documented search matches aliases rather than
offering an exact identifier parameter, so this benchmark used the official
weekly dump. Core data is CC0; other public data is CC BY-SA and BookBrainz asks
users to seek clarification where licensing is unclear.

## Future/partnership candidates not benchmarked

CB/Bureau ISBN, NBD Biblion, OCLC/WorldCat commercial APIs and ISBNdb were not
queried because no already-authorized free machine-readable access was in
scope. They remain future licensed-provider or partnership candidates. No HTML
scraping was used.
