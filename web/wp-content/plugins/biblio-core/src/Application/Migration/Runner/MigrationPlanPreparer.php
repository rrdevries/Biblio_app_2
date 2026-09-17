<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Throwable;

final readonly class MigrationPlanPreparer
{
    public function __construct(
        private MigrationParticipantRegistry $participants,
        private ?MigrationSourceMapperRegistry $mappers,
        private MigrationEnvironment $environment
    ) {
    }

    public function prepare(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target
    ): PreparedMigrationPlan {
        $mapper = $this->mappers?->forAdapter($inspection->adapter()->adapterId());
        $mapping = $mapper === null
            ? MigrationSourceMappingResult::passthrough($inspection->records())
            : $mapper->map($inspection, $target);

        $contracts = [];
        $safeFindings = [];
        foreach ($mapping->findings() as $finding) {
            $safe = $finding->toArray();
            $safeFindings[] = $safe;
            if (
                str_starts_with($finding->sourceId(), "mapping_contract:")
                && str_ends_with($finding->reasonCode(), "mapping_contract_applied")
            ) {
                $contracts[] = substr($finding->sourceId(), strlen("mapping_contract:"));
            }
        }
        sort($contracts, SORT_STRING);
        $contracts = array_values(array_unique($contracts));

        $prepared = [];
        $failures = [];
        foreach ($mapping->records() as $record) {
            $participant = $this->participants->forType($record->sourceType());
            if (!$participant instanceof MigrationParticipant) {
                $failures[] = new PreparedMigrationFailure($record, "unsupported_source_type");
                continue;
            }
            try {
                $plan = $participant->plan($record, $target);
            } catch (Throwable $exception) {
                $failures[] = new PreparedMigrationFailure(
                    $record,
                    $exception instanceof MigrationParticipantFailure
                        ? $exception->reasonCode()
                        : "participant_planning_error"
                );
                continue;
            }
            if ($participant instanceof PreparedMigrationContextParticipant) {
                $participant->assertPreparedContext(
                    $record,
                    $plan,
                    $inspection,
                    $contracts
                );
            }
            $prepared[] = new PreparedMigrationRecord($record, $participant, $plan);
        }

        $digestRecords = [];
        foreach ($prepared as $item) {
            $digestRecords[] = array_merge(
                $item->record()->identityArray(),
                $item->plan()->toArray()
            );
        }
        foreach ($failures as $failure) {
            $digestRecords[] = array_merge(
                $failure->record()->identityArray(),
                ["planning_failure" => $failure->reasonCode()]
            );
        }
        usort($digestRecords, static fn (array $a, array $b): int =>
            [$a["source_type"], $a["source_id"], $a["payload_hash"]]
                <=> [$b["source_type"], $b["source_id"], $b["payload_hash"]]);

        $digest = DeterministicJson::hash([
            "adapter_id" => $inspection->adapter()->adapterId(),
            "source_family" => $inspection->adapter()->sourceFamily(),
            "source_version" => $inspection->profile()->sourceVersion(),
            "manifest_digest" => $inspection->package()->manifestDigest(),
            "target_user_id" => $target->userId(),
            "target_library_id" => $target->libraryId(),
            "build" => $this->environment->provenance()->toArray(),
            "mapping_contracts" => $contracts,
            "records" => $digestRecords,
            "findings" => $safeFindings,
        ]);

        return new PreparedMigrationPlan(
            $inspection,
            $target,
            $prepared,
            $failures,
            $mapping->findings(),
            $contracts,
            $digest
        );
    }
}
