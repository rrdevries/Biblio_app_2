<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, PreparedMigrationPlan};

final class RehearsalPlanEvidence
{
    /**
     * @param array<string,mixed> $build
     * @return array<string,mixed>
     */
    public static function describe(PreparedMigrationPlan $prepared, array $build): array
    {
        RehearsalContract::require($prepared->failures() === [], "planning_failure");
        $inspection = $prepared->inspection();
        RehearsalContract::require(
            array_diff($inspection->profile()->unknownCategories(), [
                "auxiliary_caches", "home_preferences", "migration_reports", "recommendation_state",
                "release_tracking", "taxonomy_aliases", "taxonomy_review_queue",
            ]) === [],
            "source_profile_failure"
        );
        foreach ($inspection->profile()->findings() as $finding) {
            RehearsalContract::require($finding->toArray()["reason_code"] !== "malformed_record", "malformed_source_record");
        }
        $keys = [];
        $quarantine = [];
        $operations = [];
        $neutralRecords = [];
        $registry = [];
        foreach ($prepared->findings() as $finding) {
            // A mapper-only conflict cannot disappear simply because no executable
            // record was produced. Typed participant quarantine is handled below.
            RehearsalContract::require(!in_array($finding->disposition(), [MigrationDisposition::Failed, MigrationDisposition::Quarantined], true), "unresolved_mapping_finding");
        }
        foreach ($prepared->records() as $item) {
            $record = $item->record();
            $plan = $item->plan();
            $key = $record->sourceType() . "\0" . $record->sourceId();
            RehearsalContract::require(!isset($keys[$key]), "duplicate_prepared_identity");
            RehearsalContract::require($plan->unmatchedReferences() === [], "unmatched_reference");
            RehearsalContract::require(!in_array($plan->disposition(), [MigrationDisposition::Failed, MigrationDisposition::IntentionallyDropped], true), "unaccepted_disposition");
            $keys[$key] = $plan;
            $registry[$record->sourceType()] = get_class($item->participant());
            if ($plan->disposition() === MigrationDisposition::Quarantined) {
                RehearsalContract::require($plan->operations() === [], "quarantine_has_target");
                $quarantine[] = ["source_type" => $record->sourceType(), "source_id" => $record->sourceId(), "payload_hash" => $record->payloadHash(),
                    "reason_code" => $plan->reasonCode(),
                    "evidence_hash" => $record->payloadHash(),
                    "no_target" => true,
                ];
            }
            foreach ($plan->operations() as $operation) {
                $type = $operation["target_type"] ?? $operation["operation"];
                $operations[$type] = ($operations[$type] ?? 0) + 1;
            }
            $neutralRecords[] = [
                "source_type" => $record->sourceType(),
                "source_id" => $record->sourceId(),
                "payload_hash" => $record->typedPlan() === null ? $record->payloadHash()
                    : DeterministicJson::hash(self::neutral($record->typedPlan()->canonicalPayload())),
                "plan" => self::neutral($plan->toArray()),
            ];
        }
        // Validate the entire dependency graph before BeginMigrationRunService.
        $pending = $keys;
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $key => $plan) {
                $blocked = false;
                foreach ($plan->dependencies() as $dependency) {
                    $dependencyKey = $dependency["source_type"] . "\0" . $dependency["source_id"];
                    RehearsalContract::require(isset($keys[$dependencyKey]), "missing_dependency");
                    RehearsalContract::require(in_array($keys[$dependencyKey]->disposition(), [MigrationDisposition::Mapped, MigrationDisposition::Transformed], true), "dependency_has_no_target");
                    $blocked = $blocked || isset($pending[$dependencyKey]);
                }
                if (!$blocked) {
                    unset($pending[$key]);
                    $progress = true;
                }
            }
            RehearsalContract::require($progress, "dependency_cycle");
        }
        usort($neutralRecords, static fn (array $a, array $b): int => [$a["source_type"], $a["source_id"]] <=> [$b["source_type"], $b["source_id"]]);
        usort($quarantine, static fn (array $a, array $b): int => [$a["source_type"], $a["source_id"]] <=> [$b["source_type"], $b["source_id"]]);
        ksort($registry, SORT_STRING);
        ksort($operations, SORT_STRING);
        return [
            "plan_set_digest" => $prepared->planSetDigest(),
            "target_neutral_plan_digest" => DeterministicJson::hash([
                "source" => $inspection->sourcePayload(),
                "mapping_contracts" => $prepared->mappingContracts(),
                "records" => $neutralRecords,
                "registry" => $registry,
                "registry_digest" => $prepared->registryDigest(),
                "bundle_digest" => $prepared->finalPopulationBundleDigest(),
                "reviewed_profile" => $prepared->profileReview()?->toArray(),
                "build" => $build,
                "mapping_findings" => array_map(static fn ($finding): array => $finding->toArray(), $prepared->findings()),
            ]),
            "registry_digest" => $prepared->registryDigest(),
            "reviewed_source_profile" => $prepared->profileReview()?->toArray(),
            "mapping_contracts" => $prepared->mappingContracts(),
            "quarantine" => $quarantine,
            "expected_operations" => $operations,
            "executable_records" => count($prepared->records()),
        ];
    }

    /** Replace typed ownership fields only; never value-match unrelated strings. */
    private static function neutral(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = match ($key) {
                "target_user_id", "user_id", "owner_user_id" => "<target-user>",
                "target_library_id", "library_id" => "<target-library>",
                default => self::neutral($item),
            };
        }
        return $result;
    }
}
