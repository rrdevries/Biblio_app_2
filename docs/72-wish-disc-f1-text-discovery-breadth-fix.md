# WISH-DISC-F1 — text discovery local-first breadth fix

Status: **TECHNICAL GO / HUMAN QA RECHECK PENDING** after deterministic Core,
REST, UI and guarded browser verification plus independent review.

## Corrected contract

The generic MH-DISC text path now always collects local title/author matches
and then executes the existing conditional Open Library → Google Books chain.
Usable provider candidates are appended after all local candidates. The exact
local canonical Edition short-circuit remains exclusive to ISBN discovery.

An external Work is removed only when an existing provider-to-canonical Work
mapping proves that the same Work is already present as a local result. An
external Edition is removed only when its provider Edition mapping or canonical
ISBN proves the same displayed local Edition. Title, subtitle, contributor or
other fuzzy similarity is never used for identity or deduplication. Provider
ordering remains presentation-only and the fallback/winner policy is unchanged.

Only external candidates are stored in the actor-scoped discovery snapshot.
The response may therefore contain local and external results together while
the snapshot remains a replay boundary for external candidates only.

## Wishlist and failure independence

Bibliographic discovery has no Wishlist repository or membership input. The
integration regression materializes one external Work, creates a Work-only
Wishlist entry, repeats the same broad text query and proves that the canonical
local Work plus the other external candidate remain visible.

When the provider chain fails or misses, local text results remain a successful
`results` response and the typed provider attempts retain partial-failure
evidence. The strict client accepts mixed text results only in local-first order
and the Wishlist status tells the user, without a provider name, when external
expansion was not fully available. No local result becomes a false zero-result.

## Unchanged boundaries

ISBN exact lookup, Add Book, provider fallback and relevance semantics,
Work/Edition identity, Wishlist cardinality, materialization, Items and Library
possession behavior are unchanged. No schema or current/historical V1 data is
involved.

Schema remains `1023`. Biblio Core advances to `2.5.2`; Biblio UI advances to
`0.15.1`.

## Verification

The recorded gates cover:

- Core unit regressions for post-materialization breadth, retained local
  results on provider failure, no title-only dedup and unchanged ISBN
  short-circuit;
- a real schema-1023 discovery → materialization → Wishlist write → repeated
  discovery integration path, with no Item creation;
- strict decoding of mixed local-first results and partial provider failure,
  including rejection of external-before-local ordering;
- Wishlist provider-neutral partial-failure copy and a guarded browser scenario
  with existing Wishlist membership plus local/external results;
- full Core, REST, Metadata Hub, Add Book, Wishlist and frontend regressions,
  PHP syntax, PHPStan, Composer/platform, WordPress smoke, manifest, whitespace,
  guarded fixture cleanup/fingerprint and independent review.

No reviewer blocker was found. Renée's original `harry potter` reproduction
remains the normal-account human QA recheck.
