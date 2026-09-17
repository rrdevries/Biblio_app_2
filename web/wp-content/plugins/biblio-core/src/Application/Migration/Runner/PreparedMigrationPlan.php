<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

final readonly class PreparedMigrationPlan
{
    /**
     * @param list<PreparedMigrationRecord> $records
     * @param list<PreparedMigrationFailure> $failures
     * @param list<MigrationSourceMappingFinding> $findings
     * @param list<string> $mappingContracts
     */
    public function __construct(
        private MigrationSourceInspection $inspection,
        private MigrationPlanningTarget $target,
        private array $records,
        private array $failures,
        private array $findings,
        private array $mappingContracts,
        private string $planSetDigest
    ) {
    }

    public function inspection(): MigrationSourceInspection { return $this->inspection; }
    public function target(): MigrationPlanningTarget { return $this->target; }
    /** @return list<PreparedMigrationRecord> */
    public function records(): array { return $this->records; }
    /** @return list<PreparedMigrationFailure> */
    public function failures(): array { return $this->failures; }
    /** @return list<MigrationSourceMappingFinding> */
    public function findings(): array { return $this->findings; }
    /** @return list<string> */
    public function mappingContracts(): array { return $this->mappingContracts; }
    public function planSetDigest(): string { return $this->planSetDigest; }

    /** @return list<MigrationSourceRecord> */
    public function executableRecords(): array
    {
        $records = array_map(
            static fn (PreparedMigrationRecord $prepared): MigrationSourceRecord =>
                $prepared->record(),
            $this->records
        );
        array_push($records, ...array_map(
            static fn (PreparedMigrationFailure $failure): MigrationSourceRecord =>
                $failure->record(),
            $this->failures
        ));
        usort($records, static fn (MigrationSourceRecord $a, MigrationSourceRecord $b): int =>
            [$a->sourceType(), $a->sourceId(), $a->payloadHash()]
                <=> [$b->sourceType(), $b->sourceId(), $b->payloadHash()]);
        return $records;
    }

    /** @return array<string, mixed> */
    public function safeProvenance(): array
    {
        return [
            "plan_set_digest" => $this->planSetDigest,
            "adapter_id" => $this->inspection->adapter()->adapterId(),
            "source_family" => $this->inspection->adapter()->sourceFamily(),
            "source_version" => $this->inspection->profile()->sourceVersion(),
            "manifest_digest" => $this->inspection->package()->manifestDigest(),
            "target_user_id" => $this->target->userId(),
            "target_library_id" => $this->target->libraryId(),
            "mapping_contracts" => $this->mappingContracts,
            "executable_record_count" => count($this->executableRecords()),
        ];
    }
}
