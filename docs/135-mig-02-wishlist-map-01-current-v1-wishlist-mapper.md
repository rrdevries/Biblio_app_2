# MIG-02-WISHLIST-MAP-01 — Current V1 Wishlist mapper

Status: **IMPLEMENTED — FINAL ACCEPTANCE REQUIRES THE POST-COMMIT EXACT-SHA
ZERO-WRITE TRIAL; PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-18

Task severity: **High**

Scope: manifest-bound CURRENT Wishlist mapping to the existing private V2
Wishlist plus one durable auxiliary preservation observation per source row.
This slice adds no schema, REST, UI, provider, Archive, Reading Goal or
production apply/import behavior.

## 1. Pre-coding audit

The implementation started from clean `main` at expected HEAD
`6b47bc931d7221f6df3b405aac9a0cdabdde8b2d`. Product was `v2.001`, schema
`1026`, Core `2.45.0` and UI `0.20.0`. The adapter already emitted one stable
`v1.wishlist_item` for each reviewed record, but no participant owned that
source type. The last clean trial consequently reported Wishlist and Reading
Goals as the two unsupported stable lanes.

Schema 1026 already contains `wishlist_work_states`, `wishlist_entries` and
`wishlist_entry_history`. Their constraints enforce one Work-only entry or
multiple distinct Edition-specific entries per User+Work, never both. No
schema change was necessary.

## 2. Decision inheritance

This slice implements docs/134 without reinterpretation. For only the exact
manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`,
all reviewed Wishlist rows are Work-only. Raw `type=edition` remains source
evidence and is not an Edition-specific product instruction.

The designated local intake ZIP recomputes SHA-256
`835013ae8d08085f89f61963e32bae38309faaf4e8334dfbbf00ba74beeae70c`.
No source file was re-extracted, edited or substituted.

## 3. Mapper architecture

`CurrentV1WishlistMapper` is composed inside the existing CURRENT post-adapter
mapping stage. It consumes adapter-validated Wishlist and Book records after
CAT has established exact Work source identities. It emits only:

```text
v1.wishlist_item/<stable-id>
  -> WishlistPlan(target User, exact CAT Work, work_only, active, timestamps)

v1.wishlist_item/<stable-id>/auxiliary
  -> PreservedSourceEvidencePlan(no product target)
```

The mapper performs no product or MIG-FND write. New manifests, raw types,
lifecycles, fulfillment or structural shapes fail closed.

## 4. Wishlist plan and participant

`WishlistPlan` is source-neutral and contains the explicit target User, exact
CAT Work source dependency, fixed Work-only/active shape, null Edition
dependency, both historical instants, mapping contract and complete
adapter/source/manifest provenance. Its canonical payload is the replay hash.

`WishlistMigrationParticipant` validates the target User and prepared
provenance, declares exactly one `catalog_work` dependency and delegates the
product mutation to `WishlistMigrationWriter`. It exposes no REST/UI route.

## 5. Historical timestamp writer

The normal `WishlistRecorder` deliberately uses `WishlistClock::now()` and
constructs an entry with equal creation/update instants. It remains unchanged.

The migration-only `HistoricalWishlistRecorder` joins the caller-owned
transaction, validates the active User and existing Work, uses the same
Wishlist entity, repository locks, cardinality constraints and ID collision
policy, but accepts both approved historical instants. The writer verifies
those exact instants on create and replay; current time is never substituted.

## 6. Ownership and Library boundary

Every active plan uses only the server-validated migration target User. There
is no actor, administrator, first-User, Library-owner or membership fallback.
The target Library remains run/CAT context and never owns or scopes the
Wishlist Entry.

## 7. Work-only mapping

All active plans use specificity `work_only`. Raw `type=edition`, available
CAT Edition identity, ISBN, Book publication fields, group key, carrier and
Copy evidence do not create or narrow an Edition target.

## 8. CAT dependencies

The only dependency is the exact source mapping:

```text
catalog_work:v1.book/<exact-book-id>/work
```

No title, ISBN, Author, Series, Edition, Item or provider lookup exists.

## 9. Active and fulfillment boundary

The reviewed `status=active` shape becomes presence in active
`wishlist_entries`. Empty `fulfilledCopyId` and `fulfilledAt` create no Item
dependency, acquisition event or fulfillment history. Archived Copies do not
fulfill, remove or alter Wishlist intent.

## 10. Cardinality and convergence

The historical recorder reuses the same User+Work lock and existing unique
constraints. Edition-specific state blocks a Work-only import. Exact Work-only
replay reuses one target. Multiple source observations may converge only when
their complete canonical plan payloads are equal; incompatible reverse source
payloads, changed source payloads or target mutation fail closed. There is no
last-write-wins path.

## 11. Auxiliary preservation

Each raw record produces exactly one restricted
`current_v1_wishlist_auxiliary` preservation plan with reason
`wishlist_auxiliary_evidence_preserved`. The exact hash envelope contains raw
type, title group key and desired carrier, including the empty carrier value.
The artifact contains only the type/reason/privacy/locator/provenance/hash
descriptor, never those raw values.

`CurrentV1RestrictedSourceEvidenceResolver` accepts only the exact
`data/books.json#wishlistItems/<encoded-id>/auxiliaryEvidence` locator,
reconstructs the reviewed four-key envelope and verifies its hash without
returning or logging content.

## 12. Prepared-stream integration

Wishlist and preservation participants are registered in the production
composition. Both records enter the shared `MigrationPlanPreparer`; the active
plan additionally verifies that its contract, adapter, source family/version
and manifest belong to the prepared context. Dry-run, apply preflight and
reconciliation therefore consume one equivalent typed stream.

## 13. CURRENT mapping totals

The zero-write standalone mapper audit recomputed these values from the
designated extraction rather than runtime acceptance constants:

| Result | Count |
|---|---:|
| stable source Wishlist records | 41 |
| unique IDs / unique Book IDs | 41 / 41 |
| raw `type=edition` / active | 41 / 41 |
| fulfilled Copy/time | 0 / 0 |
| Work-only Wishlist plans | 41 |
| Edition-specific plans | 0 |
| auxiliary preservation plans | 41 |
| Wishlist-lane prepared observations | 82 |
| quarantine / conflict / unmatched dependency | 0 / 0 / 0 |

The mapper contains no hardcoded acceptance total; the manifest and exact
structure bind this population.

## 14. Global dry-run effect

The exact final-SHA trial must show that `v1.wishlist_item` disappears from
`unsupported_source_types`. `v1.reading_goal` remains unsupported and is not
changed by this slice. Other reviewed quarantines/deferred lanes remain
separate.

## 15. Reconciliation

`CurrentMigrationMappingContracts` classifies `wishlist_entry` as one required
entity mapping. `CoreMigrationTargetInspector` resolves the exact CAT Work and
checks owner, Work, Work-only target form, null Edition and both timestamps.
The focused reconciliation regression proves that a changed stored timestamp
is a broken target.

## 16. Privacy

Tests use synthetic content. Plans, findings, exceptions and artifacts expose
only stable IDs, counts, hashes, closed reason codes and typed dependencies.
Actual wished titles, author snapshots, group keys, carrier values and source
timestamps are not written into committed evidence or ordinary CLI output.

## 17. Zero-write proof

The implementation performs no write during profile or dry-run. Final
acceptance requires the guarded isolated CURRENT dry-run on the exact one-commit
SHA, with identical before/after fingerprints for all 57 Biblio tables and an
unchanged normal-environment fingerprint. No MIG-FND or product row may be
created.

## 18. Trial build provenance

The final trial is required to be detached and clean at the implementation
commit, project `biblio-v2-migration-trial`, database
`biblio_migration_trial`, schema 1026, Core 2.46.0, UI 0.20.0, explicit
subscriber target User/Library, adapter `current-v1-json-29` and the exact
manifest above. The post-commit SHA and ignored artifact path are necessarily
reported in the completion report rather than self-referenced by this commit.

## 19. Tests and quality gates

Focused unit coverage proves Work-only mapping, absence of Edition/product
auxiliary fields, exact timestamps, stable identities, independent active and
preservation divergence, invalid lifecycle/fulfillment/chronology, manifest
drift and restricted-source verification. Integration coverage proves owner
and Work resolution, historical creation, current-time exclusion, exact
replay, equivalent convergence, reverse conflict, mixed-mode cardinality,
unchanged interactive clock behavior and target inspection.

Focused validation is green: the unit wrapper ran 789 tests with 3,360
assertions and the Wishlist participant integration ran five tests with 25
assertions. The final full Core gate then passed in 496 seconds: PHP syntax,
PHPStan, Composer/platform, 789 unit tests with 3,360 assertions, 598
integration tests with 6,758 assertions, WordPress smoke with active plugin and
HTTP 200, manifest JSON and whitespace. The two pre-existing unit-suite notices
remain unrelated to Wishlist behavior.

The independent second review found no blocker against docs/134, ownership,
CAT Work-only dependency, historical time, fulfillment, cardinality, replay,
privacy, schema or deferred-scope boundaries. Final acceptance still requires
the post-commit exact-SHA isolated privacy-safe zero-write trial described
above.

## 20. Current V1 data rule

Only the designated ZIP and its validated read-only extraction are CURRENT.
V1 is source evidence, not product authority. Fixtures, DATA-01, historical
exports, network providers and source mutation were not used as CURRENT truth.

## 21. Schema and versions

Product remains `v2.001`; schema remains `1026`; Biblio Core becomes
`2.46.0`; Biblio UI remains `0.20.0`. There is no REST/UI feature change.

## 22. Git and boundary

This slice ends with exactly one local implementation commit and no push.
Archive, Reading Goals, Edition-specific refinement, Series grouping,
fulfillment/acquisition backfill, loan backfill and production apply/import
remain explicitly outside scope.
