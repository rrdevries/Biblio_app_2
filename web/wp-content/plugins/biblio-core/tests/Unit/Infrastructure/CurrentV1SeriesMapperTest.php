<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{PersonalMigrationTarget,PersonalMigrationTargetReadiness};
use Biblio\Core\Application\Migration\Preservation\{PreservedSourceEvidenceMigrationParticipant,PreservedSourceEvidencePlan,PreservedSourceEvidencePrivacy};
use Biblio\Core\Application\Migration\Runner\{MigrationPlanningTarget,MigrationRunnerFailure,MigrationSourceInspection,MigrationSourceMappingResult,MigrationSourcePackage,MigrationSourceProfile,MigrationSourceRecord};
use Biblio\Core\Application\Migration\Series\{CatalogSeriesMigrationParticipant,CatalogSeriesPlan,CatalogWorkSeriesMigrationParticipant,CatalogWorkSeriesPlan};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{CurrentV1CatalogMapper,CurrentV1CatalogSourceIds,CurrentV1ReviewedSeriesContract,CurrentV1SeriesMapper,CurrentV1SeriesMappingReason,CurrentV1SeriesSourceIds,CurrentV1SourceAdapter};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1SeriesMapperTest extends TestCase
{
    private const DIGEST = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testIdentityUsesExactUnnormalizedUtf8BytesOnly(): void
    {
        $result = $this->map([
            $this->book("same-a", "Shared Series", "1"),
            $this->book("same-b", "Shared Series", "2"),
            $this->book("case", "shared Series", ""),
            $this->book("punctuation", "Shared Series'", ""),
            $this->book("space", "Shared  Series", ""),
        ]);
        $series = $this->plans($result, CatalogSeriesMigrationParticipant::SOURCE_TYPE);
        $memberships = $this->plans($result, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);

        self::assertCount(4, $series);
        self::assertCount(5, $memberships);
        $sharedId = CurrentV1SeriesSourceIds::series("Shared Series");
        self::assertSame(
            "v1.series/exact-raw-name-v1/U2hhcmVkIFNlcmllcw",
            $sharedId
        );
        self::assertInstanceOf(CatalogSeriesPlan::class, $series[$sharedId]);
        self::assertSame("Shared Series", $series[$sharedId]->displayName());
        foreach (["same-a", "same-b"] as $bookId) {
            $membership = $memberships[CurrentV1SeriesSourceIds::membership($bookId)];
            self::assertInstanceOf(CatalogWorkSeriesPlan::class, $membership);
            self::assertSame($sharedId, $membership->seriesSourceId());
            self::assertSame(CurrentV1CatalogSourceIds::work($bookId), $membership->workSourceId());
        }
        self::assertNotSame(
            CurrentV1SeriesSourceIds::series("Shared Series"),
            CurrentV1SeriesSourceIds::series("shared Series")
        );
        self::assertNotSame(
            CurrentV1SeriesSourceIds::series("Shared Series"),
            CurrentV1SeriesSourceIds::series("Shared Series'")
        );
        self::assertNotSame(
            CurrentV1SeriesSourceIds::series("Shared Series"),
            CurrentV1SeriesSourceIds::series("Shared  Series")
        );
    }

    public function testPositionsRemainExactOrUnknownAndUnsafeEvidenceIsExecutable(): void
    {
        $result = $this->map([
            $this->book("zero", "Zero Series", "0"),
            $this->book("gap", "Zero Series", "3"),
            $this->book("missing", "Unknown Position", ""),
            $this->book("decimal", "Compound Position", "1.2"),
            $this->book("year", "Year Position", "2020"),
            $this->book("nameless", "", "1", true),
            $this->book("contained", "", "", false, [[
                "author" => "",
                "isbn" => "",
                "series" => "Contained Series",
                "seriesIndex" => "4",
                "title" => "Contained",
            ]]),
        ]);
        $memberships = $this->plans($result, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);
        $preserved = $this->plans($result, PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE);

        self::assertSame("0", $memberships[CurrentV1SeriesSourceIds::membership("zero")]->position()->value());
        self::assertSame("3", $memberships[CurrentV1SeriesSourceIds::membership("gap")]->position()->value());
        foreach (["missing", "decimal", "year"] as $bookId) {
            self::assertNull($memberships[CurrentV1SeriesSourceIds::membership($bookId)]->position()->value());
        }
        self::assertCount(4, $preserved);
        self::assertSame(
            "series_position_not_safely_mappable",
            $preserved[CurrentV1SeriesSourceIds::unsafePosition("decimal")]->reasonCode()
        );
        self::assertSame(
            "series_name_missing",
            $preserved[CurrentV1SeriesSourceIds::membership("nameless")]->reasonCode()
        );
        self::assertSame(
            "contained_work_series_deferred",
            $preserved[CurrentV1SeriesSourceIds::contained("contained", 1)]->reasonCode()
        );
        foreach ($preserved as $plan) {
            self::assertInstanceOf(PreservedSourceEvidencePlan::class, $plan);
            self::assertSame(PreservedSourceEvidencePrivacy::OrdinarySource, $plan->privacy());
            self::assertSame("data/books.json", $plan->sourceFile());
            self::assertSame("books", $plan->sourceCollection());
        }
    }

    public function testAliasKeepsExactBookDependencyAndCompatibleEdgesConverge(): void
    {
        $result = $this->map([
            $this->book("alias-a", "Alias Series", "2", true, [], "9780306406157", "Shared Work"),
            $this->book("alias-b", "Alias Series", "2", true, [], "9780306406157", "Shared Work"),
        ]);
        $memberships = $this->plans($result, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);

        self::assertCount(2, $memberships);
        foreach (["alias-a", "alias-b"] as $bookId) {
            self::assertSame(
                CurrentV1CatalogSourceIds::work($bookId),
                $memberships[CurrentV1SeriesSourceIds::membership($bookId)]->workSourceId()
            );
        }
        self::assertSame(0, $this->reasonCount($result, CurrentV1SeriesMappingReason::ConvergenceConflict));
    }

    public function testAliasIncompatibleSeriesOrPositivePositionsFailClosed(): void
    {
        foreach ([
            [["Conflict Series", "1"], ["Other Series", "1"]],
            [["Conflict Series", "1"], ["Conflict Series", "2"]],
        ] as $evidence) {
            $result = $this->map([
                $this->book("alias-a", $evidence[0][0], $evidence[0][1], true, [], "9780306406157", "Shared Work"),
                $this->book("alias-b", $evidence[1][0], $evidence[1][1], true, [], "9780306406157", "Shared Work"),
            ]);
            self::assertSame([], $this->plans($result, CatalogSeriesMigrationParticipant::SOURCE_TYPE));
            self::assertSame([], $this->plans($result, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE));
            self::assertSame(2, $this->reasonCount($result, CurrentV1SeriesMappingReason::ConvergenceConflict));
        }
    }

    public function testManifestDriftFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("Series contract does not match");
        $this->map([$this->book("book-a", "Series", "1")], str_repeat("b", 64));
    }

    public function testReplayIdentityIsStableWhileNameAndPositionChangesDivergeCorrectly(): void
    {
        $first = $this->map([$this->book("stable", "Stable Series", "1")]);
        $same = $this->map([$this->book("stable", "Stable Series", "1")]);
        $positionChanged = $this->map([$this->book("stable", "Stable Series", "2")]);
        $nameChanged = $this->map([$this->book("stable", "Stable series", "1")]);

        $firstMembership = $this->record($first, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);
        $sameMembership = $this->record($same, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);
        $changedMembership = $this->record($positionChanged, CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE);
        self::assertSame($firstMembership->sourceId(), $sameMembership->sourceId());
        self::assertSame($firstMembership->payloadHash(), $sameMembership->payloadHash());
        self::assertSame($firstMembership->sourceId(), $changedMembership->sourceId());
        self::assertNotSame($firstMembership->payloadHash(), $changedMembership->payloadHash());
        self::assertNotSame(
            $this->record($first, CatalogSeriesMigrationParticipant::SOURCE_TYPE)->sourceId(),
            $this->record($nameChanged, CatalogSeriesMigrationParticipant::SOURCE_TYPE)->sourceId()
        );
    }

    /** @param list<MigrationSourceRecord> $books */
    private function map(array $books, string $contractDigest = self::DIGEST): MigrationSourceMappingResult
    {
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $books,
            []
        );
        return (new CurrentV1CatalogMapper(
            seriesMapper: new CurrentV1SeriesMapper(new CurrentV1ReviewedSeriesContract($contractDigest))
        ))->map($inspection, $this->target());
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

    /** @param list<array<string,mixed>> $containedWorks */
    private function book(string $id, string $seriesName, string $seriesNumber, bool $series = true, array $containedWorks = [], string $isbn = "", ?string $title = null): MigrationSourceRecord
    {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => $title ?? "Synthetic Series Work {$id}",
            "isbn" => $isbn,
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => $containedWorks,
            "series" => $series,
            "seriesName" => $seriesName,
            "seriesNumber" => $seriesNumber,
        ]);
    }

    /** @return array<string,object> */
    private function plans(MigrationSourceMappingResult $result, string $sourceType): array
    {
        $plans = [];
        foreach ($result->records() as $record) {
            if ($record->sourceType() === $sourceType) {
                $plans[$record->sourceId()] = $record->typedPlan();
            }
        }
        ksort($plans, SORT_STRING);
        return $plans;
    }

    private function reasonCount(MigrationSourceMappingResult $result, CurrentV1SeriesMappingReason $reason): int
    {
        $count = 0;
        foreach ($result->findings() as $finding) {
            if ($finding->reasonCode() === $reason->value) {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }

    private function record(MigrationSourceMappingResult $result, string $sourceType): MigrationSourceRecord
    {
        foreach ($result->records() as $record) {
            if ($record->sourceType() === $sourceType) {
                return $record;
            }
        }
        self::fail("Expected migration record is missing.");
    }
}
