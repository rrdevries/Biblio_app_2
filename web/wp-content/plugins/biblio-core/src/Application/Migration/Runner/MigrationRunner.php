<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Identity\PersonalMigrationTargetInvalid;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Throwable;

final readonly class MigrationRunner
{
    public const ARTIFACT_VERSION = 2;

    public function __construct(
        private MigrationSourcePackageFactory $packages,
        private MigrationSourceAdapterRegistry $adapters,
        private MigrationParticipantRegistry $participants,
        private MigrationTargetValidator $targets,
        private MigrationEnvironment $environment
    ) {
    }

    public function profile(string $sourceRoot, string $adapterId): MigrationArtifact
    {
        $inspection = $this->inspectSource($sourceRoot, $adapterId);

        return new MigrationArtifact(
            "source-profile",
            $inspection->package()->manifestDigest(),
            [
                "migration_artifact_version" => self::ARTIFACT_VERSION,
                "artifact_kind" => "source_profile",
                "mode" => "profile",
                "build" => $this->environment->provenance()->toArray(),
                "source" => $inspection->sourcePayload(),
                "record_count" => count($inspection->records()),
                "zero_write_confirmed" => true,
            ]
        );
    }

    public function dryRun(
        string $sourceRoot,
        string $adapterId,
        UserId $targetUserId,
        LibraryId $targetLibraryId,
        bool $requireEmpty
    ): MigrationArtifact {
        $inspection = $this->inspectSource($sourceRoot, $adapterId);
        $this->environment->assertHealthy();

        try {
            $validated = $this->targets->validate(
                $targetUserId,
                $targetLibraryId
            );
        } catch (PersonalMigrationTargetInvalid $exception) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::InvalidTarget,
                "The explicit migration target is invalid.",
                $exception
            );
        }

        if ($requireEmpty && !$validated->readiness()->isClean()) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::InvalidTarget,
                "The explicit migration target is not empty."
            );
        }

        $target = new MigrationPlanningTarget($validated);
        $plans = [];
        $unsupportedTypes = [];
        $planningErrors = [];
        $dispositions = [];
        $preservation = [];
        $quarantine = [];
        $unmatchedReferences = [];

        $participantCounts = [];
        $operationCounts = [];
        foreach ($inspection->records() as $record) {
            $participant = $this->participants->forType($record->sourceType());
            if (!$participant instanceof MigrationParticipant) {
                $unsupportedTypes[$record->sourceType()] = true;
                continue;
            }

            try {
                $plan = $participant->plan($record, $target);
            } catch (Throwable $exception) {
                $planningErrors[] = [
                    "reason_code" => $exception instanceof MigrationParticipantFailure
                        ? $exception->reasonCode()
                        : "participant_planning_error",
                    "source_type" => $record->sourceType(),
                    "source_id" => $record->sourceId(),
                ];
                continue;
            }

            $plans[] = array_merge($record->identityArray(), $plan->toArray());
            $participantCounts[$record->sourceType()] =
                ($participantCounts[$record->sourceType()] ?? 0) + 1;
            foreach ($plan->operations() as $operation) {
                $name = $operation["operation"];
                $operationCounts[$name] = ($operationCounts[$name] ?? 0) + 1;
            }
            $disposition = $plan->disposition()->value;
            $dispositions[$disposition] = ($dispositions[$disposition] ?? 0) + 1;
            if ($plan->disposition() === MigrationDisposition::PreservedDeferred) {
                $preservation[] = $record->identityArray();
            }
            if ($plan->disposition() === MigrationDisposition::Quarantined) {
                $quarantine[] = $record->identityArray();
            }
            foreach ($plan->unmatchedReferences() as $reference) {
                $unmatchedReferences[] = [
                    "source_type" => $record->sourceType(),
                    "source_id" => $record->sourceId(),
                    "reference" => $reference,
                ];
            }
        }

        $unsupported = array_keys($unsupportedTypes);
        sort($unsupported, SORT_STRING);
        ksort($dispositions, SORT_STRING);
        ksort($participantCounts, SORT_STRING);
        ksort($operationCounts, SORT_STRING);

        return new MigrationArtifact(
            "dry-run",
            $inspection->package()->manifestDigest(),
            [
                "migration_artifact_version" => self::ARTIFACT_VERSION,
                "artifact_kind" => "dry_run_plan",
                "mode" => "dry_run",
                "build" => $this->environment->provenance()->toArray(),
                "source" => $inspection->sourcePayload(),
                "target" => [
                    "target_user_id" => $target->userId(),
                    "target_library_id" => $target->libraryId(),
                    "validation" => "valid",
                    "require_empty" => $requireEmpty,
                    "cleanliness" => $validated->readiness()->status(),
                ],
                "plan" => [
                    "records" => $plans,
                    "disposition_counts" => $dispositions,
                    "unsupported_source_types" => $unsupported,
                    "preservation_candidates" => $preservation,
                    "quarantine_candidates" => $quarantine,
                    "planning_errors" => $planningErrors,
                    "unmatched_references" => $unmatchedReferences,
                ],
                "planning_reconciliation" => [
                    "applied" => false,
                    "accepted" => false,
                    "source_observations" => count($inspection->records()),
                    "planned_observations" => count($plans),
                    "participant_counts" => $participantCounts,
                    "operation_counts" => $operationCounts,
                    "unsupported_source_type_count" => count($unsupported),
                    "planning_error_count" => count($planningErrors),
                    "unmatched_reference_count" => count($unmatchedReferences),
                ],
                "zero_write_confirmed" => true,
            ]
        );
    }

    public function inspectSource(
        string $sourceRoot,
        string $adapterId
    ): MigrationSourceInspection
    {
        $package = $this->packages->build($sourceRoot);
        $adapter = $this->adapters->get($adapterId);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $adapter->sourceFamily()) !== 1) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedAdapter,
                "Source adapter family is invalid."
            );
        }
        $profile = $adapter->profile($package);
        if (!$adapter->supportsVersion($profile->sourceVersion())) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedVersion,
                "Source version is not explicitly supported by this adapter."
            );
        }

        $records = [];
        $identities = [];
        foreach ($adapter->records($package, $profile) as $record) {
            $key = $record->sourceType() . "\0" . $record->sourceId();
            if (isset($identities[$key])) {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::SourceDuplicate,
                    "Source adapter returned a duplicate logical source identity."
                );
            }
            $identities[$key] = true;
            $records[] = $record;
        }
        usort($records, static fn (MigrationSourceRecord $a, MigrationSourceRecord $b): int =>
            [$a->sourceType(), $a->sourceId(), $a->payloadHash()]
                <=> [$b->sourceType(), $b->sourceId(), $b->payloadHash()]);

        $typeCounts = [];
        foreach ($records as $record) {
            $typeCounts[$record->sourceType()] =
                ($typeCounts[$record->sourceType()] ?? 0) + 1;
        }
        ksort($typeCounts, SORT_STRING);

        return new MigrationSourceInspection(
            $package,
            $adapter,
            $profile,
            $records,
            $typeCounts
        );
    }
}
