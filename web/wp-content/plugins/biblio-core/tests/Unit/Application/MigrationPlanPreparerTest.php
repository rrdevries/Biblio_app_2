<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationBuildProvenance,
    MigrationEnvironment,
    MigrationParticipantRegistry,
    MigrationPlanPreparer,
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceAdapter,
    MigrationSourceInspection,
    MigrationSourceMapper,
    MigrationSourceMapperRegistry,
    MigrationSourceMappingResult,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class MigrationPlanPreparerTest extends TestCase
{
    public function testTerminalPreservationRejectsContradictoryPreparedSourceIdentity(): void
    {
        $digest = str_repeat("a", 64);
        $adapter = new class implements MigrationSourceAdapter {
            public function adapterId(): string { return "synthetic-exclusion"; }
            public function sourceFamily(): string { return "synthetic"; }
            public function supportsVersion(string $sourceVersion): bool
            {
                return $sourceVersion === "test-1";
            }
            public function profile(MigrationSourcePackage $package): MigrationSourceProfile
            {
                unset($package);
                return new MigrationSourceProfile("test-1", []);
            }
            public function records(
                MigrationSourcePackage $package,
                MigrationSourceProfile $profile
            ): iterable {
                unset($package, $profile);
                return [];
            }
        };
        $preservedId = "v1.copy/copy-a/erroneous-legacy-copy";
        $forbiddenId = "v1.copy/copy-a/item";
        $mapper = new class($preservedId, $forbiddenId, $digest)
            implements MigrationSourceMapper {
            public function __construct(
                private string $preservedId,
                private string $forbiddenId,
                private string $digest
            ) {
            }
            public function adapterId(): string { return "synthetic-exclusion"; }
            public function map(
                MigrationSourceInspection $inspection,
                MigrationPlanningTarget $target
            ): MigrationSourceMappingResult {
                unset($target);
                $plan = new PreservedSourceEvidencePlan(
                    $this->preservedId,
                    "synthetic_terminal_evidence",
                    "synthetic_not_carried_forward",
                    $inspection->adapter()->adapterId(),
                    $inspection->adapter()->sourceFamily(),
                    $inspection->profile()->sourceVersion(),
                    $inspection->package()->manifestDigest(),
                    "synthetic-contract",
                    "source.json",
                    "records",
                    "copy-a",
                    "record",
                    $this->digest,
                    PreservedSourceEvidencePrivacy::RestrictedSource,
                    forbiddenSourceIdentities: [[
                        "source_type" => "catalog_item",
                        "source_id" => $this->forbiddenId,
                    ]]
                );
                return new MigrationSourceMappingResult([
                    MigrationSourceRecord::typed(
                        PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                        $this->preservedId,
                        $plan
                    ),
                    new MigrationSourceRecord("catalog_item", $this->forbiddenId, []),
                ], []);
            }
        };
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], $digest),
            $adapter,
            new MigrationSourceProfile("test-1", []),
            [],
            []
        );
        $environment = new class implements MigrationEnvironment {
            public function assertHealthy(): void {}
            public function provenance(): MigrationBuildProvenance
            {
                return new MigrationBuildProvenance(
                    "v2.001",
                    1026,
                    "2.49.0",
                    str_repeat("a", 40),
                    false
                );
            }
        };
        $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("target-user"),
            new LibraryId("target-library"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));

        try {
            (new MigrationPlanPreparer(
                new MigrationParticipantRegistry([]),
                new MigrationSourceMapperRegistry([$mapper]),
                $environment
            ))->prepare($inspection, $target);
            self::fail("Contradictory prepared source identities must fail.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::PreparedPlanMismatch, $failure->reason());
        }
    }
}
