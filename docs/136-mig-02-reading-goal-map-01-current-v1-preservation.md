# MIG-02-READING-GOAL-MAP-01 — CURRENT V1 Reading Goal preservation

Status: **IMPLEMENTED — FINAL ACCEPTANCE REQUIRES THE POST-COMMIT EXACT-SHA
ZERO-WRITE TRIAL; PRODUCTION APPLY NOT AUTHORIZED**

Date: 2026-09-18

Task severity: **High**

## Product decision

Renée explicitly decided that the two Reading Goals in the designated CURRENT
V1 source are not migrated to a V2.001 product target. This is a product
decision, not a technical fallback. V2.001 gains no Reading Goal domain,
schema, participant/writer, REST or UI capability, and the records are not
converted to ReadingRound, Personal Reading Truth, Note or another V2 entity.

The stable source observations must still be explicit and reconciliation-safe.
They are therefore admitted through the existing durable no-target
preservation contract with reason `reading_goal_not_carried_forward_v2`.

## Source and contract

The contract is limited to adapter `current-v1-json-29`, source version
`books-29.authors-2.reading-goals-2` and manifest
`35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67`.
The immutable designated extraction contains two unique stable goal IDs with
the reviewed seven-field row shape. The source file hash is
`dde0de005030c6142713a38b4b4eff8d3874802436b2e71a965782870228412d`.
No source bytes were changed or substituted.

## Mapping

For each structurally valid row, `CurrentV1ReadingGoalMapper` emits one typed
record:

```text
source type:       preserved_source_evidence
source identity:   v1.reading_goal/<stable-id>
evidence type:     current_v1_reading_goal
reason:            reading_goal_not_carried_forward_v2
privacy:           restricted_source
locator:           data/reading_goals.json#goals/<encoded-id>/record
evidence hash:     deterministic hash of the complete raw row
dependencies:      none
product mappings:  none
```

The mapper does not inspect goal meaning, calculate progress or reinterpret
type, active state, configuration or timestamps. It validates only the closed
row structure and stable identity. A malformed record is quarantined rather
than preserved or repaired.

## Privacy and recovery

The prepared plan and MIG-FND descriptor contain only stable identity,
provenance, locator, reason, privacy class and hashes. Restricted row contents
remain solely in the immutable CURRENT package. The internal resolver accepts
only the reviewed file/collection/identity/field contract and verifies the
complete row hash without returning or logging source content.

## Reconciliation and replay

The generic `PreservedSourceEvidenceMigrationParticipant` creates one source
observation and one preservation row with zero target mappings. Dry-run, apply
preflight and reconciliation use the same prepared record. Exact replay reuses
equivalent committed evidence; any changed row, provenance, contract, reason,
locator or privacy class fails closed under the existing MIG-FND rules.

## CURRENT accounting

| Result | Count |
|---|---:|
| stable `v1.reading_goal` source records | 2 |
| `current_v1_reading_goal` preservation plans | 2 |
| Reading Goal product plans/records | 0 |
| dependencies / target mappings | 0 / 0 |
| quarantine | 0 |
| unsupported `v1.reading_goal` after composition | 0 |

## Boundary

Product remains `v2.001`; schema remains `1026`; Biblio Core becomes `2.47.0`;
Biblio UI remains `0.20.0`. This slice authorizes no CURRENT apply/import and
adds no product target, schema, participant/writer, REST or UI behavior.

Focused unit coverage validates exact preservation identity, complete-row hash,
restricted privacy, zero dependencies, payload divergence, structural
quarantine, manifest drift and restricted-source recovery.

The full Core gate passed PHP syntax, PHPStan, Composer/platform, 794 unit tests
with 3,399 assertions, 598 integration tests with 6,758 assertions, WordPress
smoke with active plugin and HTTP 200, manifest JSON and whitespace. The two
pre-existing unit-suite notices remain unrelated to this slice.

A supporting working-tree CURRENT dry-run produced 6,579 prepared records,
including exactly two Reading Goal preservation plans, with zero unsupported
source types, planning errors or unmatched references. Its checksum verified,
11 restricted goal values produced zero artifact hits, and a repeated run after
local Core-version synchronization left the stable normal database data,
schema and Biblio-count fingerprints unchanged. Because build provenance
correctly records `working_tree_dirty=true`, this evidence is not the final
clean exact-SHA trial. Final closure still requires that post-commit isolated
trial and its unchanged trial/normal fingerprints.
