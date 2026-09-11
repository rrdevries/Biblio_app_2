# 80 — MH-AUTHOR-API-01 Selected Author Works REST transport

Status: **GO / CLOSED** after the recorded gates and independent review.

Date: 2026-09-11

Task severity: **High**. The signed-selector prerequisite was verified before
implementation. Primary implementation and independent review are separated.

## 1. Endpoint and authority

Authenticated `POST /biblio/v1/me/bibliographic-author-works` accepts exactly
one required server-issued `author_selector` and optional nullable opaque
`cursor`. The D-AUTHOR-REF-01 codec verifies signature, version, token type,
form, provider allowlist and exact payload shape before reconstructing the
trusted `BibliographicAuthorReference`.

Raw canonical/provider Author IDs, display name, provider, actor and Library
identity are rejected. The client cannot compose a canonical/provider bridge.

## 2. Application and authentication

The controller calls only the existing authenticated
`BibliographicAuthorWorkSearchService`. WordPress/Biblio resolves the actor
server-side; anonymous access returns the standard 401. Bibliographic Works are
shared data, so no Library Context or private Library read is involved.

## 3. Response

The standard `data` envelope contains only `items`, `next_cursor` and typed
`provider_attempts`. Each Work contains result identity/kind, nullable
canonical `work_id`, nullable typed `provider_identity`, title, Authors and
reliable Series context. There is no Edition ID, ISBN, publisher, publication
date, language, binding, page count, private data or raw provider payload.

## 4. Pagination and composition

The existing MH-AUTHOR cursor remains bound to the full trusted Author
reference, `local|external` lane and next offset. It is never exposed as a raw
offset. Canonical-only, provider-only and server-proven composite selectors
retain the existing local-first order, strong-identity deduplication and exact
provider lane. A cursor issued for a different Author/reference fails closed.

## 5. Failures and security

Malformed JSON/UTF-8, missing/wrong-type/unknown fields, bad selectors and bad
cursors map to the existing safe 400 policy. Provider miss, configuration,
malformed, timeout, network/HTTP and rate-limit evidence remains typed. A
composite external failure is a usable response and retains local Works.
Selector/cursor payloads, signing keys and provider secrets are never returned.

## 6. Compatibility and deferred work

Top-level MH-SEARCH retains its existing shape and signed Author selector.
MH-EDITION, MH-DISC, Wishlist, Add Book, Hierna lezen and `/me/works` are
unchanged. No UI or consumer is cut over. Work-to-Editions REST remains
`MH-EDITION-API-01`.

## 7. Verification

Focused tests prove all selector forms, external and composite results,
composite partial failure, valid continuation, cross-binding rejection, strict
transport, typed serialization, Edition/private-data exclusion and route/auth
behavior. Recorded evidence:

- focused REST/application unit tests: `31 tests`, `78 assertions`;
- selected route integration including real deterministic Open Library
  transport: pass;
- full unit suite: `578 tests`, `2,336 assertions`, with the same two existing
  PHPUnit notices;
- full integration suite: `425 tests`, `5,145 assertions`;
- Composer strict metadata/platform, every PHP file's syntax, PHPStan,
  WordPress smoke, manifest JSON and whitespace: pass;
- full serial Core gate: pass in `415 seconds`; and
- independent second review: GO, no remaining blocker after adding direct
  provider-reference mismatch plus malformed/tampered cursor regression proof.

One earlier full run became invalid when a concurrent DDEV/test reset removed
the isolated test schema mid-suite. No result from that collided run is counted;
the subsequent exclusive clean run above provisioned the schema and passed.

## 8. Data, schema and versions

No current or historical V1 source, snapshot or count was read or changed.
Tests use synthetic canonical data and deterministic Open Library fixtures.
Schema remains `1023`; Biblio Core is `2.12.0`; Biblio UI remains `0.15.1`.

## 9. Git

The final local commit, divergence, clean working tree, safety-branch status
and no-push status are reported after every gate and review passes.
