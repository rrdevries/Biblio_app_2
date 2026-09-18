<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

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
    DeterministicJson,
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourceMappingResult,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1CatalogMapper,
    CurrentV1ReadingGoalMapper,
    CurrentV1ReadingGoalMappingReason,
    CurrentV1ReviewedReadingGoalContract,
    CurrentV1SourceAdapter
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1ReadingGoalMapperTest extends TestCase
{
    private const DIGEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testReviewedGoalsBecomeOnlyRestrictedNoTargetPreservations(): void
    {
        $first = $this->goal("goal-1", false, ["targetBooks" => 12]);
        $second = $this->goal("goal-2", true, ["seriesName" => "Private Series"]);
        $result = $this->map([$first, $second]);
        $records = $this->preserved($result);

        self::assertCount(2, $records);
        foreach (["goal-1" => $first, "goal-2" => $second] as $id => $source) {
            $record = $records[CurrentV1SourceAdapter::READING_GOAL . "/" . $id];
            $plan = $record->typedPlan();
            self::assertInstanceOf(PreservedSourceEvidencePlan::class, $plan);
            self::assertSame("current_v1_reading_goal", $plan->evidenceType());
            self::assertSame(
                CurrentV1ReadingGoalMappingReason::NotCarriedForward->value,
                $plan->reasonCode()
            );
            self::assertSame(PreservedSourceEvidencePrivacy::RestrictedSource, $plan->privacy());
            self::assertSame("data/reading_goals.json", $plan->sourceFile());
            self::assertSame("goals", $plan->sourceCollection());
            self::assertSame("record", $plan->sourceField());
            self::assertSame(DeterministicJson::hash($source->payload()), $plan->evidenceSha256());
            self::assertSame([], $record->references());
            self::assertArrayNotHasKey("title", $plan->canonicalPayload());
            self::assertArrayNotHasKey("config", $plan->canonicalPayload());
        }
        self::assertSame(2, $this->reasonCount(
            $result,
            CurrentV1ReadingGoalMappingReason::NotCarriedForward
        ));
        self::assertSame(0, $this->quarantineCount($result));
    }

    public function testIdentityIsStableAndChangedRestrictedPayloadDiverges(): void
    {
        $first = $this->preserved($this->map([$this->goal("goal-1")]))[
            "v1.reading_goal/goal-1"
        ];
        $changed = $this->preserved($this->map([$this->goal(
            "goal-1",
            true,
            ["targetBooks" => 24]
        )]))["v1.reading_goal/goal-1"];

        self::assertSame($first->sourceId(), $changed->sourceId());
        self::assertNotSame($first->payloadHash(), $changed->payloadHash());
    }

    public function testMalformedSourceIsQuarantinedWithoutProductOrPreservationPlan(): void
    {
        foreach ([
            ["active" => "false"],
            ["config" => ["unexpected-list-value"]],
            ["type" => ""],
            ["unexpected" => true],
        ] as $change) {
            $result = $this->map([$this->goal("goal-1", false, [], $change)]);
            self::assertSame([], $this->preserved($result));
            self::assertSame(1, $this->quarantineCount($result));
        }
    }

    public function testManifestDriftFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("Reading Goal contract does not match");
        $this->map([$this->goal("goal-1")], str_repeat("b", 64));
    }

    /** @param list<MigrationSourceRecord> $goals */
    private function map(
        array $goals,
        string $contractDigest = self::DIGEST
    ): MigrationSourceMappingResult {
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $goals,
            []
        );
        return (new CurrentV1CatalogMapper(
            readingGoalMapper: new CurrentV1ReadingGoalMapper(
                new CurrentV1ReviewedReadingGoalContract($contractDigest)
            )
        ))->map($inspection, $this->target());
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $replace */
    private function goal(
        string $id,
        bool $active = false,
        array $config = ["targetBooks" => 12],
        array $replace = []
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(
            CurrentV1SourceAdapter::READING_GOAL,
            $id,
            array_replace([
                "active" => $active,
                "config" => $config,
                "createdAt" => "2026-01-01T10:00:00.000Z",
                "id" => $id,
                "title" => "Private goal title",
                "type" => "source-type",
                "updatedAt" => "2026-02-01T10:00:00.000Z",
            ], $replace)
        );
    }

    private function target(): MigrationPlanningTarget
    {
        return new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("target-user"),
            new LibraryId("target-library"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
    }

    /** @return array<string,MigrationSourceRecord> */
    private function preserved(MigrationSourceMappingResult $result): array
    {
        $records = [];
        foreach ($result->records() as $record) {
            if ($record->sourceType() === PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE) {
                $records[$record->sourceId()] = $record;
            }
        }
        ksort($records, SORT_STRING);
        return $records;
    }

    private function reasonCount(
        MigrationSourceMappingResult $result,
        CurrentV1ReadingGoalMappingReason $reason
    ): int {
        $count = 0;
        foreach ($result->findings() as $finding) {
            if ($finding->reasonCode() === $reason->value) {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }

    private function quarantineCount(MigrationSourceMappingResult $result): int
    {
        $count = 0;
        foreach ($result->findings() as $finding) {
            if ($finding->disposition()->value === "quarantined") {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }
}
