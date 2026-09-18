<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord
};

/** Preserves reviewed CURRENT Reading Goals without a V2 product projection. */
final readonly class CurrentV1ReadingGoalMapper
{
    /** @var list<string> */
    private const FIELDS = [
        "active", "config", "createdAt", "id", "title", "type", "updatedAt",
    ];

    public function __construct(
        private CurrentV1ReviewedReadingGoalContract $contract =
            new CurrentV1ReviewedReadingGoalContract()
    ) {
    }

    /** @param array<string, MigrationSourceRecord> $goals */
    public function map(
        MigrationSourceInspection $inspection,
        array $goals
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Reading Goal contract does not match the source manifest."
            );
        }

        $records = [];
        $findings = [];
        foreach ($goals as $goal) {
            $payload = $goal->payload();
            if (($payload["id"] ?? null) !== $goal->sourceId()) {
                $findings[] = $this->finding(
                    $goal,
                    CurrentV1ReadingGoalMappingReason::InvalidSourceIdentity,
                    MigrationDisposition::Quarantined
                );
                continue;
            }
            if (!$this->validStructure($goal, $payload)) {
                $findings[] = $this->finding(
                    $goal,
                    CurrentV1ReadingGoalMappingReason::InvalidSourceStructure,
                    MigrationDisposition::Quarantined
                );
                continue;
            }

            $sourceIdentity = CurrentV1SourceAdapter::READING_GOAL . "/"
                . $goal->sourceId();
            $preserved = MigrationSourceRecord::typed(
                PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                $sourceIdentity,
                new PreservedSourceEvidencePlan(
                    $sourceIdentity,
                    "current_v1_reading_goal",
                    CurrentV1ReadingGoalMappingReason::NotCarriedForward->value,
                    $inspection->adapter()->adapterId(),
                    $inspection->adapter()->sourceFamily(),
                    $inspection->profile()->sourceVersion(),
                    $inspection->package()->manifestDigest(),
                    $this->contract->identity(),
                    "data/reading_goals.json",
                    "goals",
                    $goal->sourceId(),
                    "record",
                    DeterministicJson::hash($payload),
                    PreservedSourceEvidencePrivacy::RestrictedSource
                )
            );
            $records[] = $preserved;
            $findings[] = $this->finding(
                $goal,
                CurrentV1ReadingGoalMappingReason::NotCarriedForward,
                MigrationDisposition::PreservedDeferred,
                [[
                    "source_type" => $preserved->sourceType(),
                    "source_id" => $preserved->sourceId(),
                ]]
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.reading_goal_preservation",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1ReadingGoalMappingReason::MappingContractApplied->value
        );

        return new MigrationSourceMappingResult($records, $findings);
    }

    /** @param array<string, mixed> $payload */
    private function validStructure(
        MigrationSourceRecord $goal,
        array $payload
    ): bool {
        $keys = array_keys($payload);
        $expected = self::FIELDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        $config = $payload["config"] ?? null;

        return $keys === $expected
            && $goal->references() === []
            && is_string($payload["id"] ?? null)
            && $payload["id"] !== ""
            && is_string($payload["type"] ?? null)
            && $payload["type"] !== ""
            && is_string($payload["title"] ?? null)
            && is_bool($payload["active"] ?? null)
            && is_string($payload["createdAt"] ?? null)
            && is_string($payload["updatedAt"] ?? null)
            && is_array($config)
            && ($config === [] || !array_is_list($config));
    }

    /** @param list<array{source_type:string,source_id:string}> $planned */
    private function finding(
        MigrationSourceRecord $goal,
        CurrentV1ReadingGoalMappingReason $reason,
        MigrationDisposition $disposition,
        array $planned = []
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $goal->sourceType(),
            $goal->sourceId(),
            $disposition,
            $reason->value,
            $planned
        );
    }
}
