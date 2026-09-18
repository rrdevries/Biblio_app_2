<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Application\Migration\{
    MappingDisposition,
    MigrationDisposition,
    MigrationEvidence,
    MigrationLedgerObservation,
    MigrationLedgerRepository,
    MigrationRun
};
use Biblio\Core\Application\Migration\Catalog\CatalogItemPlan;
use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan;
use Biblio\Core\Application\Migration\Runner\{
    CrossRunMigrationReplayParticipant,
    PreparedMigrationPreflightParticipant,
    MigrationSourceInspection,
    PreparedMigrationPlan,
    PreparedMigrationRecord,
    MigrationSourceRecord
};
use Biblio\Core\Exception\ValidationException;

final readonly class MigrationReconciliationService
{
    /** @var list<string> */
    private const DEPENDENCY_REASONS = [
        "unresolved_required_reference",
        "missing_required_target_reference",
        "missing_work_mapping",
        "missing_source_mapping",
        "missing_reading_round_mapping",
    ];

    public function __construct(
        private MigrationLedgerRepository $ledger,
        private MigrationMappingContractRegistry $contracts,
        private MigrationTargetInspector $targets
    ) {
    }

    /** @param array<string, int> $execution */
    public function reconcile(
        MigrationRun $run,
        PreparedMigrationPlan $prepared,
        array $execution = []
    ): MigrationReconciliationReport {
        $inspection = $prepared->inspection();
        $this->assertProvenance($run, $inspection);
        $this->assertPreparedPreflight($prepared);
        $snapshot = $this->ledger->snapshot($run->id());
        $authoritativeRun = $snapshot->run();
        $this->assertRunAuthority($run, $authoritativeRun);

        $expected = [];
        $preparedByKey = [];
        foreach ($prepared->records() as $item) {
            $record = $item->record();
            $expected[$this->recordKey($record)] = $record;
            $preparedByKey[$this->recordKey($record)] = $item;
        }
        foreach ($prepared->failures() as $failure) {
            $record = $failure->record();
            $expected[$this->recordKey($record)] = $record;
        }
        $stored = [];
        $observationScopeAnomalies = [];
        $scopeAnomalyObservations = [];
        foreach ($snapshot->observations() as $observation) {
            if (
                $observation->sourceFamily() !== $authoritativeRun->sourceFamily()
                || $observation->sourceSnapshot()
                    !== $authoritativeRun->sourceSnapshot()
            ) {
                $observationScopeAnomalies[] = [
                    "source_type" => $observation->sourceType(),
                    "source_id" => $observation->sourceId(),
                    "payload_hash" => $observation->payloadHash(),
                    "reason_code" => "observation_run_scope_mismatch",
                ];
                $scopeAnomalyObservations[] = $observation;
                continue;
            }
            $stored[$observation->identityKey()] = $observation;
        }

        $dispositions = array_fill_keys(array_map(
            static fn (MigrationDisposition $case): string => $case->value,
            MigrationDisposition::cases()
        ), 0);
        $sourceTypes = [];
        $preparedTypeCounts = [];
        foreach ($expected as $record) {
            $preparedTypeCounts[$record->sourceType()] =
                ($preparedTypeCounts[$record->sourceType()] ?? 0) + 1;
        }
        ksort($preparedTypeCounts, SORT_STRING);
        foreach ($preparedTypeCounts as $sourceType => $count) {
            $sourceTypes[$sourceType] = [
                "enumerated" => $count,
                "observed" => 0,
                "uncommitted" => 0,
                "mapping_edges" => 0,
                "disposition_counts" => [],
            ];
        }

        $uncommitted = 0;
        $unexplained = [];
        $unexpected = $observationScopeAnomalies;
        $reasonCounts = [];
        $failed = 0;
        $retryable = 0;
        $unresolved = 0;
        $preservationByType = [];
        $quarantineByType = [];
        $preservedRecords = [];
        $quarantinedRecords = [];
        $mappingCounts = [
            "total" => 0,
            "created" => 0,
            "reused" => 0,
            "entity" => ["total" => 0, "created" => 0, "reused" => 0],
            "relation" => ["total" => 0, "created" => 0, "reused" => 0],
            "unclassified" => ["total" => 0, "created" => 0, "reused" => 0],
        ];
        $broken = [];
        $unsupported = [];

        foreach ($snapshot->mappingAnomalies() as $anomaly) {
            ++$mappingCounts["total"];
            ++$mappingCounts[$anomaly->disposition()->value];
            ++$mappingCounts["unclassified"]["total"];
            ++$mappingCounts["unclassified"][$anomaly->disposition()->value];
            $broken[] = [
                "source_type" => "migration_ledger",
                "source_id" => $anomaly->observationId(),
                "target_type" => $anomaly->targetType(),
                "target_id" => $anomaly->targetId(),
                "reason_code" => "mapping_observation_scope_mismatch",
            ];
        }
        foreach ($scopeAnomalyObservations as $observation) {
            foreach ($observation->mappings() as $mapping) {
                ++$mappingCounts["total"];
                ++$mappingCounts[$mapping->disposition()->value];
                ++$mappingCounts["unclassified"]["total"];
                ++$mappingCounts["unclassified"][$mapping->disposition()->value];
                $broken[] = [
                    "source_type" => $observation->sourceType(),
                    "source_id" => $observation->sourceId(),
                    "target_type" => $mapping->targetType(),
                    "target_id" => $mapping->targetId(),
                    "reason_code" => "observation_run_scope_mismatch",
                ];
            }
        }

        foreach ($expected as $key => $record) {
            $observation = $stored[$key] ?? null;
            $preparedItem = $preparedByKey[$key] ?? null;
            if (
                !$observation instanceof MigrationLedgerObservation
                && $preparedItem instanceof PreparedMigrationRecord
                && $this->hasEquivalentPrior($preparedItem, $prepared)
            ) {
                $sourceType = $record->sourceType();
                ++$sourceTypes[$sourceType]["observed"];
                ++$dispositions[MigrationDisposition::PreservedDeferred->value];
                $sourceTypes[$sourceType]["disposition_counts"][
                    MigrationDisposition::PreservedDeferred->value
                ] = ($sourceTypes[$sourceType]["disposition_counts"][
                    MigrationDisposition::PreservedDeferred->value
                ] ?? 0) + 1;
                $reason = $preparedItem->plan()->reasonCode();
                if (is_string($reason)) {
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
                $preservationByType[$sourceType] =
                    ($preservationByType[$sourceType] ?? 0) + 1;
                $preservedRecords[] = [
                    "source_type" => $record->sourceType(),
                    "source_id" => $record->sourceId(),
                    "reason_code" => $reason,
                    "reused_prior" => true,
                ];
                if ($this->contracts->forSourceType($sourceType) === null) {
                    $unsupported[$sourceType] = true;
                }
                continue;
            }
            if (
                !$observation instanceof MigrationLedgerObservation
                || !hash_equals($record->payloadHash(), $observation->payloadHash())
            ) {
                $unexplained[] = $record->identityArray();
                continue;
            }
            $sourceType = $record->sourceType();
            ++$sourceTypes[$sourceType]["observed"];
            unset($stored[$key]);

            $contract = $this->contracts->forSourceType($sourceType);
            if ($contract === null) {
                $unsupported[$sourceType] = true;
            }
            $disposition = $observation->disposition();
            if ($disposition === null) {
                ++$uncommitted;
                ++$sourceTypes[$sourceType]["uncommitted"];
            } else {
                ++$dispositions[$disposition->value];
                $sourceTypes[$sourceType]["disposition_counts"][$disposition->value] =
                    ($sourceTypes[$sourceType]["disposition_counts"][$disposition->value] ?? 0) + 1;
                if ($observation->reasonCode() !== null) {
                    $reasonCounts[$observation->reasonCode()] =
                        ($reasonCounts[$observation->reasonCode()] ?? 0) + 1;
                }
                if ($disposition === MigrationDisposition::Failed) {
                    ++$failed;
                    if ($observation->retryable()) {
                        ++$retryable;
                    }
                    if (in_array($observation->reasonCode(), self::DEPENDENCY_REASONS, true)) {
                        ++$unresolved;
                    }
                }
                if ($disposition === MigrationDisposition::PreservedDeferred) {
                    $preservationByType[$sourceType] = ($preservationByType[$sourceType] ?? 0) + 1;
                    $preservedRecords[] = $this->accountedRecord($observation);
                }
                if ($disposition === MigrationDisposition::Quarantined) {
                    $quarantineByType[$sourceType] = ($quarantineByType[$sourceType] ?? 0) + 1;
                    $quarantinedRecords[] = $this->accountedRecord($observation);
                }
            }

            $this->accountMappings(
                $authoritativeRun,
                $record,
                $observation,
                $contract,
                $snapshot,
                $mappingCounts,
                $sourceTypes[$sourceType]["mapping_edges"],
                $broken
            );
        }

        foreach ($stored as $observation) {
            $unexpected[] = [
                "source_type" => $observation->sourceType(),
                "source_id" => $observation->sourceId(),
                "payload_hash" => $observation->payloadHash(),
            ];
        }

        foreach ($sourceTypes as &$counts) {
            ksort($counts["disposition_counts"], SORT_STRING);
        }
        unset($counts);
        $unsupportedTypes = array_keys($unsupported);
        sort($unsupportedTypes, SORT_STRING);
        ksort($sourceTypes, SORT_STRING);
        ksort($dispositions, SORT_STRING);
        ksort($reasonCounts, SORT_STRING);
        ksort($preservationByType, SORT_STRING);
        ksort($quarantineByType, SORT_STRING);
        $sortAccountedRecords = static function (array $left, array $right): int {
            return [$left["source_type"], $left["source_id"]]
                <=> [$right["source_type"], $right["source_id"]];
        };
        usort($preservedRecords, $sortAccountedRecords);
        usort($quarantinedRecords, $sortAccountedRecords);

        $sourceTotal = count($expected);
        $sourceEquationExact = array_sum($dispositions) + $uncommitted
            + count($unexplained) === $sourceTotal;
        $profile = $inspection->sourcePayload();
        $findingTotal = array_sum($profile["finding_counts"]);
        [$categoryAccounting, $categoryStrategyErrors, $unassignedSourceTypes] =
            $this->categoryAccounting($inspection);
        $strategiesComplete = $unsupportedTypes === []
            && $profile["unknown_categories"] === []
            && $findingTotal === 0
            && $categoryStrategyErrors === [];
        $flags = [
            "source_accounting_exact" => $sourceEquationExact && $unexpected === [],
            "source_strategies_complete" => $strategiesComplete,
            "zero_uncommitted" => $uncommitted === 0,
            "zero_unexplained_drops" => $unexplained === [],
            "zero_unexpected_observations" => $unexpected === [],
            "zero_failed" => $failed === 0,
            "zero_unresolved_required_references" => $unresolved === 0,
            "zero_broken_committed_mappings" => $broken === [],
        ];
        $accepted = !in_array(false, $flags, true);

        $payload = [
            "accepted" => $accepted,
            "acceptance_flags" => $flags,
            "source" => [
                "enumerated_observations" => $sourceTotal,
                "observed" => $sourceTotal - count($unexplained),
                "category_counts" => $profile["category_counts"],
                "category_accounting" => $categoryAccounting,
                "category_strategy_errors" => $categoryStrategyErrors,
                "unassigned_source_types" => $unassignedSourceTypes,
                "source_type_counts" => $preparedTypeCounts,
                "raw_source_observation_count" => count($inspection->records()),
                "unknown_categories" => $profile["unknown_categories"],
                "finding_counts" => $profile["finding_counts"],
                "unexplained_observations" => $unexplained,
                "unexpected_observations" => $unexpected,
            ],
            "by_participant" => $sourceTypes,
            "disposition_counts" => $dispositions,
            "mapping_counts" => $mappingCounts,
            "preservation" => [
                "total" => $dispositions[MigrationDisposition::PreservedDeferred->value],
                "by_source_type" => $preservationByType,
                "records" => $preservedRecords,
            ],
            "quarantine" => [
                "total" => $dispositions[MigrationDisposition::Quarantined->value],
                "by_source_type" => $quarantineByType,
                "records" => $quarantinedRecords,
            ],
            "failed_count" => $failed,
            "retryable_failure_count" => $retryable,
            "unresolved_dependency_count" => $unresolved,
            "uncommitted_count" => $uncommitted,
            "unexplained_drop_count" => count($unexplained),
            "unexpected_observation_count" => count($unexpected),
            "broken_target_count" => count($broken),
            "mapping_anomaly_count" => count($snapshot->mappingAnomalies()),
            "observation_scope_anomaly_count" => count($scopeAnomalyObservations),
            "broken_targets" => $broken,
            "unsupported_source_types" => $unsupportedTypes,
            "reason_code_summaries" => $reasonCounts,
            "execution" => $this->executionCounts($execution),
        ];

        return new MigrationReconciliationReport(
            $accepted,
            $sourceTotal,
            $dispositions,
            $mappingCounts,
            $uncommitted,
            count($unexplained),
            $unresolved,
            count($broken),
            $flags,
            $reasonCounts,
            $payload
        );
    }

    private function assertProvenance(
        MigrationRun $run,
        MigrationSourceInspection $inspection
    ): void {
        if (
            $run->sourceFamily() !== $inspection->adapter()->sourceFamily()
            || $run->sourceSnapshot() !== $inspection->package()->manifestDigest()
            || $run->sourceFingerprint() !== $inspection->package()->manifestDigest()
            || $run->sourceVersion() !== $inspection->profile()->sourceVersion()
        ) {
            throw new ValidationException(
                "Migration run and immutable source package provenance disagree."
            );
        }
    }

    private function assertRunAuthority(
        MigrationRun $supplied,
        MigrationRun $stored
    ): void {
        if (
            $supplied->id() !== $stored->id()
            || $supplied->sourceFamily() !== $stored->sourceFamily()
            || $supplied->sourceSnapshot() !== $stored->sourceSnapshot()
            || $supplied->sourceFingerprint() !== $stored->sourceFingerprint()
            || $supplied->sourceVersion() !== $stored->sourceVersion()
            || $supplied->migratorVersion() !== $stored->migratorVersion()
            || $supplied->mode() !== $stored->mode()
            || !$supplied->targetUserId()->equals($stored->targetUserId())
            || !$supplied->targetLibraryId()->equals($stored->targetLibraryId())
        ) {
            throw new ValidationException(
                "Migration reconciliation run does not match authoritative ledger scope."
            );
        }
    }

    /**
     * @param array<string, mixed> $mappingCounts
     * @param list<array<string, string>> $broken
     */
    private function accountMappings(
        MigrationRun $run,
        MigrationSourceRecord $record,
        MigrationLedgerObservation $observation,
        ?MigrationMappingContract $contract,
        \Biblio\Core\Application\Migration\MigrationLedgerSnapshot $snapshot,
        array &$mappingCounts,
        int &$participantEdges,
        array &$broken
    ): void {
        $typedPlan = $record->typedPlan();
        $expectedPreservation = $typedPlan instanceof CatalogItemPlan
            ? $typedPlan->preservation()
            : null;
        if ($typedPlan instanceof PreservedSourceEvidencePlan) {
            $preservationStatus = $observation->preservationStatus();
            if (
                $observation->disposition() !== MigrationDisposition::PreservedDeferred
                || $observation->reasonCode() !== $typedPlan->reasonCode()
                || $observation->preservationReason() !== $typedPlan->reasonCode()
                || !in_array(
                    $preservationStatus,
                    ["awaiting_future_processing", "processed"],
                    true
                )
                || !$this->ledger->preservationMatches(
                    $run->id(),
                    $observation->id(),
                    $typedPlan->reasonCode(),
                    (string) $preservationStatus,
                    MigrationEvidence::canonicalJson(
                        $typedPlan->evidenceDescriptor()
                    ),
                    $typedPlan->locator()
                )
                || $observation->mappings() !== []
            ) {
                $broken[] = $this->broken(
                    $observation,
                    "migration_preservation",
                    "",
                    "preservation_outcome_mismatch"
                );
            }
        }
        if (
            $expectedPreservation !== null
            && (
                $observation->disposition()
                    !== MigrationDisposition::PreservedDeferred
                || $observation->reasonCode()
                    !== $expectedPreservation->reason()
            )
        ) {
            $broken[] = $this->broken(
                $observation,
                "migration_outcome",
                "",
                "preservation_outcome_mismatch"
            );
        }

        $typeCounts = [];
        foreach ($observation->mappings() as $mapping) {
            ++$mappingCounts["total"];
            ++$participantEdges;
            ++$mappingCounts[$mapping->disposition()->value];
            $typeCounts[$mapping->targetType()] =
                ($typeCounts[$mapping->targetType()] ?? 0) + 1;
            $rule = $contract?->rule($mapping->targetType());
            if ($rule === null) {
                $broken[] = $this->broken($observation, $mapping->targetType(), $mapping->targetId(), "unexpected_target_type");
                continue;
            }
            $kind = $rule->kind()->value;
            ++$mappingCounts[$kind]["total"];
            ++$mappingCounts[$kind][$mapping->disposition()->value];
            if (!$this->targets->exists(
                $run,
                $record,
                $observation,
                $mapping,
                $snapshot
            )) {
                $broken[] = $this->broken($observation, $mapping->targetType(), $mapping->targetId(), "mapped_target_missing_or_invalid");
            }
        }

        if ($contract !== null) {
            $requiresMappings = in_array(
                $observation->disposition(),
                [MigrationDisposition::Mapped, MigrationDisposition::Transformed],
                true
            ) || $expectedPreservation !== null;
            foreach ($contract->rules() as $rule) {
                $count = $typeCounts[$rule->targetType()] ?? 0;
                if ($requiresMappings && $rule->required() && $count === 0) {
                    $broken[] = $this->broken(
                        $observation,
                        $rule->targetType(),
                        "",
                        "required_target_mapping_missing"
                    );
                }
                if ($rule->maximum() !== null && $count > $rule->maximum()) {
                    $broken[] = $this->broken(
                        $observation,
                        $rule->targetType(),
                        "",
                        "target_mapping_cardinality_exceeded"
                    );
                }
            }
        }
    }

    /** @return array<string, string> */
    private function broken(
        MigrationLedgerObservation $observation,
        string $targetType,
        string $targetId,
        string $reason
    ): array {
        return [
            "source_type" => $observation->sourceType(),
            "source_id" => $observation->sourceId(),
            "target_type" => $targetType,
            "target_id" => $targetId,
            "reason_code" => $reason,
        ];
    }

    /** @return array{source_type:string,source_id:string,reason_code:?string} */
    private function accountedRecord(
        MigrationLedgerObservation $observation
    ): array {
        return [
            "source_type" => $observation->sourceType(),
            "source_id" => $observation->sourceId(),
            "reason_code" => $observation->reasonCode(),
        ];
    }

    /**
     * @param array<string, int> $execution
     * @return array<string, int>
     */
    private function executionCounts(array $execution): array
    {
        $defaults = [
            "committed_observations" => 0,
            "skipped_committed_observations" => 0,
            "skipped_terminal_observations" => 0,
            "created_targets" => 0,
            "reused_targets" => 0,
            "reused_prior_preservations" => 0,
        ];
        $counts = array_intersect_key($execution, $defaults) + $defaults;
        ksort($counts, SORT_STRING);
        return $counts;
    }

    private function recordKey(MigrationSourceRecord $record): string
    {
        return $record->sourceType() . "\0" . $record->sourceId();
    }

    private function hasEquivalentPrior(
        PreparedMigrationRecord $preparedRecord,
        PreparedMigrationPlan $prepared
    ): bool {
        $participant = $preparedRecord->participant();
        return $participant instanceof CrossRunMigrationReplayParticipant
            && $participant->hasEquivalentPrior(
                $preparedRecord->record(),
                $preparedRecord->plan(),
                $prepared->target()
            );
    }

    private function assertPreparedPreflight(PreparedMigrationPlan $prepared): void
    {
        foreach ($prepared->records() as $item) {
            $participant = $item->participant();
            if ($participant instanceof PreparedMigrationPreflightParticipant) {
                $participant->assertPreparedPreflight(
                    $item->record(),
                    $item->plan(),
                    $prepared->target()
                );
            }
        }
    }

    /**
     * @return array{
     *   array<string,array{expected:int,source_observations:int,non_observations:int,non_observation_reason:?string,accounted:int,exact:bool}>,
     *   list<string>,
     *   list<string>
     * }
     */
    private function categoryAccounting(
        MigrationSourceInspection $inspection
    ): array {
        $categoryCounts = $inspection->profile()->categoryCounts();
        $typeCounts = $inspection->typeCounts();
        $accounting = [];
        $errors = [];
        $assignedTypes = [];

        foreach ($inspection->profile()->categoryStrategies() as $strategy) {
            $category = $strategy->category();
            if (isset($accounting[$category])) {
                $errors[] = "duplicate_category_strategy:" . $category;
                continue;
            }
            if (!array_key_exists($category, $categoryCounts)) {
                $errors[] = "strategy_for_unknown_category:" . $category;
                continue;
            }
            $observations = 0;
            foreach ($strategy->sourceTypes() as $sourceType) {
                if (isset($assignedTypes[$sourceType])) {
                    $errors[] = "source_type_has_multiple_categories:" . $sourceType;
                } else {
                    $assignedTypes[$sourceType] = $category;
                }
                $observations += $typeCounts[$sourceType] ?? 0;
            }
            $accounted = $observations + $strategy->nonObservationCount();
            $exact = $accounted === $categoryCounts[$category];
            if (!$exact) {
                $errors[] = "category_total_mismatch:" . $category;
            }
            $accounting[$category] = [
                "expected" => $categoryCounts[$category],
                "source_observations" => $observations,
                "non_observations" => $strategy->nonObservationCount(),
                "non_observation_reason" => $strategy->nonObservationReason(),
                "accounted" => $accounted,
                "exact" => $exact,
            ];
        }

        foreach (array_keys($categoryCounts) as $category) {
            if (!isset($accounting[$category])) {
                $errors[] = "missing_category_strategy:" . $category;
            }
        }
        $unassigned = [];
        foreach (array_keys($typeCounts) as $sourceType) {
            if (!isset($assignedTypes[$sourceType])) {
                $unassigned[] = $sourceType;
                $errors[] = "unassigned_source_type:" . $sourceType;
            }
        }

        ksort($accounting, SORT_STRING);
        sort($errors, SORT_STRING);
        $errors = array_values(array_unique($errors));
        sort($unassigned, SORT_STRING);
        return [$accounting, $errors, $unassigned];
    }
}
