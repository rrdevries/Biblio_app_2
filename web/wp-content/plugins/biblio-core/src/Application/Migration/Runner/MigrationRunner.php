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
    public const ARTIFACT_VERSION = 1;

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
        [$package, $adapter, $profile, $records, $typeCounts] =
            $this->inspect($sourceRoot, $adapterId);

        return new MigrationArtifact(
            "source-profile",
            $package->manifestDigest(),
            [
                "migration_artifact_version" => self::ARTIFACT_VERSION,
                "artifact_kind" => "source_profile",
                "build" => $this->environment->provenance()->toArray(),
                "source" => $this->sourcePayload(
                    $package,
                    $adapter,
                    $profile,
                    $typeCounts
                ),
                "record_count" => count($records),
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
        [$package, $adapter, $profile, $records, $typeCounts] =
            $this->inspect($sourceRoot, $adapterId);
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

        foreach ($records as $record) {
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

        return new MigrationArtifact(
            "dry-run",
            $package->manifestDigest(),
            [
                "migration_artifact_version" => self::ARTIFACT_VERSION,
                "artifact_kind" => "dry_run_plan",
                "mode" => "dry_run",
                "build" => $this->environment->provenance()->toArray(),
                "source" => $this->sourcePayload(
                    $package,
                    $adapter,
                    $profile,
                    $typeCounts
                ),
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
                "zero_write_confirmed" => true,
            ]
        );
    }

    /**
     * @return array{MigrationSourcePackage,MigrationSourceAdapter,MigrationSourceProfile,list<MigrationSourceRecord>,array<string,int>}
     */
    private function inspect(string $sourceRoot, string $adapterId): array
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

        return [$package, $adapter, $profile, $records, $typeCounts];
    }

    /**
     * @param array<string, int> $typeCounts
     * @return array<string, mixed>
     */
    private function sourcePayload(
        MigrationSourcePackage $package,
        MigrationSourceAdapter $adapter,
        MigrationSourceProfile $profile,
        array $typeCounts
    ): array {
        $findings = array_map(
            static fn (MigrationSourceFinding $finding): array => $finding->toArray(),
            $profile->findings()
        );
        $findingCounts = [
            "malformed_record" => 0,
            "unreadable_record" => 0,
        ];
        foreach ($findings as $finding) {
            $reason = $finding["reason_code"];
            $findingCounts[$reason] = ($findingCounts[$reason] ?? 0) + 1;
        }
        ksort($findingCounts, SORT_STRING);

        return [
            "package" => $package->toArray(),
            "adapter_id" => $adapter->adapterId(),
            "source_family" => $adapter->sourceFamily(),
            "source_version" => $profile->sourceVersion(),
            "category_counts" => $profile->categoryCounts(),
            "source_type_counts" => $typeCounts,
            "unknown_categories" => $profile->unknownCategories(),
            "finding_counts" => $findingCounts,
            "findings" => $findings,
        ];
    }
}
