# 79 — D-AUTHOR-REF-01 Signed Author selector handoff

Status: **GO / CLOSED** after the recorded gates and independent second review.

Date: 2026-09-11

Task severity: **High**. The read-only identity/signing/REST audit preceded
every change. Implementation and the explicit second review pass are separated.
No current or historical V1 source, snapshot or count is required.

## 1. Blocker recap

`BibliographicAuthorReference` could already represent a canonical Author, an
Open Library Author or a canonical Author with provider evidence. The top-level
REST response exposed only display/result fields, so a client had no safe way
to carry that complete trusted reference into future Works-by-Author transport.
Accepting client-composed canonical and provider IDs would permit cross-binding.

## 2. Selector design

`BibliographicAuthorSelectorCodec` carries exactly one of three forms:

- canonical: one canonical `AuthorId`;
- provider: one typed Open Library `/authors/OL…A` identity; or
- composite: canonical `AuthorId` plus the Open Library Author identity already
  present as trusted provider evidence on the server-side reference.

The fixed payload fields are version, token type, form, canonical Author ID,
provider and provider Author ID. The client contract is one opaque string; none
of the payload fields are accepted separately as authority.

## 3. Signing, versioning and expiry

The codec follows the existing Biblio search-cursor convention:
`base64url(payload).base64url(HMAC-SHA256)`. Its secret is independently derived
from WordPress `AUTH_SALT` with the `bibliographic-author-selector-v1` domain.
Version `1` and token type `author_selector` are explicit and strict.

The selector has no TTL and is not bound to the original text query. It carries
stable selected Author identity, not a mutable provider candidate snapshot.
Rotation of `AUTH_SALT` invalidates all outstanding selectors. No encryption is
needed because the payload is not secret.

## 4. Issuance rules

The existing typed top-level Author serializer is the only production issuance
point. Canonical-only and provider-only references remain their exact forms.
A composite is possible only when a trusted server-side
`BibliographicAuthorReference` already contains both canonical identity and
provider evidence; the codec never discovers, matches or enriches identity.

Current production search has no provider-to-canonical Author mapping source.
Its schema-1023 identity repository maps only Works and Editions. Therefore
current local results issue canonical-only selectors and current Open Library
results issue provider-only selectors. No name, normalized-name, shared-Work or
fuzzy bridge is introduced.

## 5. Verification and revalidation

The same codec is the single decode/verify boundary. It verifies signature,
version, token type, exact field list, form, provider allowlist, canonical ID,
Open Library Author ID and the permitted field combination before reconstructing
the exact `BibliographicAuthorReference`.

There is no mutable/revocable Author mapping to revalidate today; the signed
selector is the server-issued proof snapshot. If a later slice introduces such
a mapping, its use boundary must define current mapping revalidation before a
composite is consumed. D-AUTHOR-REF-01 adds no consumption route.

## 6. Cross-binding security

Canonical ID, provider, provider Author ID, form, token type and version are all
covered by the signature. Changing Author A to Author B, substituting another
Open Library Author, switching provider/form/type or re-encoding client JSON
without the secret fails closed. Validly signed but structurally impossible or
unsupported payloads also fail closed with one safe validation error.

## 7. REST handoff

Each Author item from
`POST /biblio/v1/me/bibliographic-searches` adds:

```json
{
  "author_selector": "..."
}
```

Existing `result_id`, `result_kind`, nullable `author_id` and `display_name`
retain their display/result meaning. The future MH-AUTHOR-API-01 accepts only
`author_selector`; `result_id` and client-composed IDs are not authority.

## 8. Compatibility

The new field is additive on the parallel MH-SEARCH-API endpoint, which has no
frontend consumer yet. Work serialization, Author/Work cursors, MH-DISC,
Wishlist, Add Book and `/me/works` are unchanged. No new route, search behavior,
provider request, materialization or UI is added.

## 9. Tests and quality gates

Final recorded evidence:

- selector/search/REST unit focus: `44 tests`, `118 assertions`;
- focused REST integration via the isolated repository test database: `3
  tests`, `74 assertions`;
- full Core unit suite: `559 tests`, `2,300 assertions`, with the same two
  existing PHPUnit notices;
- full Core integration suite: `423 tests`, `5,067 assertions`;
- Composer strict metadata, platform requirements, every PHP file's syntax,
  PHPStan, WordPress smoke, manifest JSON and whitespace: pass;
- full serial Core gate: pass in `557 seconds`;
- full Biblio UI smoke/contract suite: `251 passed`, including unchanged
  MH-DISC, Wishlist, Add Book and shared Work decoders; and
- independent second review: no blocker across identity reconstruction,
  provider allowlisting, HMAC domain separation, exact payload structure,
  cross-binding, REST compatibility, no-schema proof and scope.

One direct PHPUnit integration attempt stopped in bootstrap before executing a
test because it did not provision the required isolated `biblio_core_test`
database. The repository integration script then provisioned that database and
the focused and full integration runs passed; the bootstrap stop is not counted
as a code-test result.

## 10. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Tests use synthetic
typed references and existing deterministic search fixtures only.

## 11. Versions and schema

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.11.0`;
- Biblio UI: `0.15.1`, unchanged.

## 12. Git

The final local commit, divergence, clean working tree, safety-branch reference
and no-push status are reported after every gate and review passes.
