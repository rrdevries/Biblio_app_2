# 81 — D-WORK-REF-01 Signed Work selector handoff

Status: **GO / CLOSED**

Post-closure note: MH-EDITION-API-01 now uses this codec as the sole
selected-Work authority for its authenticated Work-to-Editions REST route.
Verification, including current composite mapping revalidation, completes
before the existing Edition-search application service is invoked; see
`docs/82-mh-edition-api-01-selected-work-editions-rest.md`.

Date: 2026-09-12

Task severity: **High**. The read-only identity, mapping, signing, REST and
composition audit preceded every change. Primary implementation and independent
review are separated. No current or historical V1 source is required.

## 1. Blocker recap

`BibliographicWorkReference` already retained canonical-only, provider-only or
canonical plus provider Work identity. The REST projections exposed only
presentation fields and, on Author-to-Works, client-readable provider data.
Neither path supplied a safe authority that a client could return to future
Work-to-Editions transport. Reconstructing a composite from client fields would
permit canonical Work A to be combined with provider Work B.

## 2. Selector design

`BibliographicWorkSelectorCodec` carries exactly one form:

- canonical: `v`, `type`, `form`, `work_id`;
- provider: `v`, `type`, `form`, `provider`, `provider_work_id`; or
- composite: `v`, `type`, `form`, `work_id`, `provider`,
  `provider_work_id`.

The version is `1`, the token type is `work_selector`, and the forms are
`canonical|provider|composite`. Only Open Library Work identity matching
`/works/OL…W` is currently supported. Unknown, missing, additional or reordered
fields fail closed. No title, Author, ISBN, query, Library, Edition or client
evidence enters the payload.

## 3. Signing, versioning and expiry

The opaque token is:

```text
base64url(payload).base64url(HMAC-SHA256)
```

The signing secret is derived from WordPress `AUTH_SALT` with the Work-specific
domain `bibliographic-work-selector-v1`; it is separate from Author-selector and
search-cursor contexts. The payload is not encrypted because identity is not
secret. The selector is query-independent and has no time-based expiry because
it represents stable bibliographic identity. `AUTH_SALT` rotation invalidates
all outstanding selectors, while current mapping revalidation protects the
mutable composite edge.

## 4. Issuance

The same codec instance issues `work_selector` directly from the typed
`BibliographicWorkReference` retained by each `BibliographicWorkSearchResult`.
It is used at exactly two production serializers:

- every Work from `POST /biblio/v1/me/bibliographic-searches`; and
- every Work from `POST /biblio/v1/me/bibliographic-author-works`.

No serializer reconstructs identity from `result_id`, `work_id`,
`provider_identity`, title or Author presentation. Current production local
results are canonical-only and current Open Library results provider-only. A
composite can be issued only when the typed server reference already contains
both identities and the exact current mapping confirms them.

## 5. Verification

The codec is the shared decode/verify boundary from the opaque token back to a
trusted `BibliographicWorkReference`. It verifies maximum length, token shape,
base64url encoding, HMAC signature, version, token type, form-specific exact
keys, value types, canonical Work ID, provider allowlist and Open Library Work
ID before reconstruction.

Canonical-only verifies without a provider lookup. Provider-only verifies
without requiring a canonical mapping. There is no downgrade or alternative
identity interpretation on failure.

## 6. Composite mapping revalidation

Composite issuance and every composite decode call the existing exact read:

```text
findWork(provider, "work", provider_work_id)
```

The result must equal the signed canonical `WorkId`. This uses the existing
schema-1023 `BibliographicProviderIdentityRepository`; no repository or mapping
write is added. Core currently exposes claim-only/idempotent mapping writes and
conflicts rather than a public revoke/remap operation. The underlying row is
still current storage truth, and re-reading it protects deleted, changed and
future lifecycle-mutated edges.

Healthy schema 1023 cannot represent two canonical targets for one exact
provider Work identity because
`(provider_key, source_entity_type, provider_record_id, target_type)` is the
primary key. An artificial ambiguity test would require violating the accepted
schema; missing and changed mapping behavior is tested instead.

## 7. Stale selector behavior

A signed composite succeeds while the exact mapping remains current. If the
mapping is removed or points at another canonical Work, verification rejects
the selector. It never silently returns canonical-only/provider-only, remaps to
the new target or creates a mapping. The user must search and select again.

## 8. Cross-binding security

Canonical Work ID, provider, provider Work ID, form, token type and version are
covered by the HMAC. Tampering or re-encoding without the Work-specific secret
fails signature verification. Even a correctly signed composite is rejected
when its current mapping does not prove the exact canonical/provider pair.

`result_id` remains deterministic presentation identity. Nullable `work_id`
and Author-to-Works `provider_identity` remain visible metadata. None is an
authority source or accepted request shape.

## 9. REST handoff

The existing Work projection receives one additive field:

```json
{
  "work_selector": "..."
}
```

No request consumes it in this slice and no route is added. MH-EDITION-API-01
may now resume as a separate slice and must accept only `work_selector` before
delegating the reconstructed typed reference to MH-EDITION-01.

## 10. Compatibility

Top-level Author results and `author_selector` are unchanged. Author/Work search
cursors and selected-Author Works cursors are unchanged. The existing
Author-to-Works `provider_identity` remains present. MH-SEARCH, MH-AUTHOR,
MH-EDITION, MH-DISC, Wishlist, Add Book, `/me/works` and Hierna lezen behavior
is not changed. No provider request, search, materialization, Work/Edition/Item,
Library, Wishlist or other product mutation is introduced.

## 11. Tests and quality gates

Recorded evidence on the final implementation:

- focused mapping-revalidation integration: `1` test, `9` assertions;
- focused REST issuance integration: `7` tests, `183` assertions;
- full Core gate: PHP syntax clean, PHPStan clean, Composer metadata/platform
  requirements clean, `595` unit tests with `2387` assertions and `426`
  integration tests with `5164` assertions, WordPress smoke green, manifest
  valid and whitespace clean; the two unit-suite notices are pre-existing;
- after adding the review-requested explicit unsigned-composite case, the full
  unit suite was repeated: `595` tests, `2388` assertions, same two notices;
- Biblio UI isolated smoke: `251` tests passed, `0` failed, including Wishlist
  and Add Book strict-contract coverage; and
- independent code/security and documentation closure reviews: GO, no blocker
  or remaining finding.

## 12. Actual V1 data rule

No current or historical V1 `/data/`, MIG-01 snapshot, DATA-01 record/count or
earlier export was read, copied, interpreted or mutated. Tests use synthetic
typed references, schema-1023 fixtures and deterministic provider fixtures.

## 13. Versions and schema

- product: `v2.001`;
- schema: `1023`, unchanged;
- Biblio Core: `2.13.0`;
- Biblio UI: `0.15.1`, unchanged.

There is no selector table, server session, token registry or schema migration.

## 14. Git

The exact local commit, branch divergence, clean working tree, safety-branch
status and no-push status are recorded in the completion report after commit.
