<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogWorkContainmentMigrationParticipant,
    CatalogWorkContainmentPlan,
    CatalogWorkMigrationParticipant,
    CatalogWorkPlan
};
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourceMappingResult,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Application\Migration\Series\{
    CatalogWorkSeriesMigrationParticipant,
    CatalogWorkSeriesPlan
};
use Biblio\Core\Catalog\ContributorRole;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1ContainedWorkMapper,
    CurrentV1ContainedWorkMappingReason,
    CurrentV1ContainedWorkSourceIds,
    CurrentV1ReviewedContainedWorkContract,
    CurrentV1SeriesSourceIds,
    CurrentV1SourceAdapter
};
use PHPUnit\Framework\TestCase;

final class CurrentV1ContainedWorkMapperTest extends TestCase
{
    private const DIGEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testOneOccurrenceProducesOnlyReviewedTypedPlans(): void
    {
        $result = $this->map([
            $this->book("base", [], "Exact Series"),
            $this->book("parent", [
                $this->contained(
                    "  Exact child title  ",
                    "Observed Author",
                    "9780306406157",
                    "Exact Series",
                    "2"
                ),
                $this->contained("Child without optional evidence"),
                $this->contained(
                    "Nameless Series child",
                    seriesIndex: "4"
                ),
            ]),
        ], ["base" => "base", "parent" => "parent"]);

        self::assertCount(3, $this->records(
            $result,
            CatalogWorkMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(3, $this->records(
            $result,
            CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->records(
            $result,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->records(
            $result,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(1, $this->records(
            $result,
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE
        ));
        self::assertCount(2, $this->records(
            $result,
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame([], $this->records($result, "catalog_edition"));
        self::assertSame([], $this->records($result, "catalog_item"));

        $work = $this->record(
            $result,
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            CurrentV1ContainedWorkSourceIds::work("parent", 1)
        )->typedPlan();
        self::assertInstanceOf(CatalogWorkPlan::class, $work);
        self::assertSame("  Exact child title  ", $work->title());

        $containment = $this->record(
            $result,
            CatalogWorkContainmentMigrationParticipant::SOURCE_TYPE,
            CurrentV1ContainedWorkSourceIds::containment("parent", 1)
        )->typedPlan();
        self::assertInstanceOf(CatalogWorkContainmentPlan::class, $containment);
        self::assertSame(1, $containment->position()->value());
        self::assertSame("v1.book/parent/work", $containment->parentWorkSourceId());
        self::assertSame(
            "v1.book/parent/contained-work/1",
            $containment->childWorkSourceId()
        );

        $author = $this->records(
            $result,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE
        )[0]->typedPlan();
        self::assertInstanceOf(CatalogAuthorPlan::class, $author);
        self::assertSame("Observed Author", $author->displayName());
        $contributor = $this->records(
            $result,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE
        )[0]->typedPlan();
        self::assertInstanceOf(CatalogWorkContributorPlan::class, $contributor);
        self::assertSame(ContributorRole::Author, $contributor->role());
        self::assertSame(1, $contributor->position()->value());

        $membership = $this->records(
            $result,
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE
        )[0]->typedPlan();
        self::assertInstanceOf(CatalogWorkSeriesPlan::class, $membership);
        self::assertSame(
            CurrentV1SeriesSourceIds::series("Exact Series"),
            $membership->seriesSourceId()
        );
        self::assertSame("2", $membership->position()->value());
        self::assertInstanceOf(
            PreservedSourceEvidencePlan::class,
            $membership->promotedPriorPreservation()
        );

        $preserved = array_map(
            static fn (MigrationSourceRecord $record): object =>
                $record->typedPlan(),
            $this->records(
                $result,
                PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
            )
        );
        self::assertSame(
            ["contained_work_isbn_deferred", "contained_work_series_deferred"],
            array_values(array_map(
                static fn (PreservedSourceEvidencePlan $plan): string =>
                    $plan->reasonCode(),
                $preserved
            ))
        );
    }

    public function testOccurrenceIdentityNeverMergesSameTitleOrAuthorName(): void
    {
        $result = $this->map([
            $this->book("parent-a", [
                $this->contained("Repeated", "Same Name"),
            ]),
            $this->book("parent-b", [
                $this->contained("Repeated", "Same Name"),
            ]),
        ], ["parent-a" => "parent-a", "parent-b" => "parent-b"]);

        $works = $this->records(
            $result,
            CatalogWorkMigrationParticipant::SOURCE_TYPE
        );
        $authors = $this->records(
            $result,
            CatalogAuthorMigrationParticipant::SOURCE_TYPE
        );
        self::assertCount(2, $works);
        self::assertCount(2, $authors);
        self::assertNotSame($works[0]->sourceId(), $works[1]->sourceId());
        self::assertNotSame($authors[0]->sourceId(), $authors[1]->sourceId());
    }

    public function testSameSlotKeepsIdentityWhileChangedPayloadDiverges(): void
    {
        $first = $this->map([
            $this->book("parent", [$this->contained("First")]),
        ], ["parent" => "parent"]);
        $changed = $this->map([
            $this->book("parent", [$this->contained("Changed")]),
        ], ["parent" => "parent"]);

        $firstWork = $this->records(
            $first,
            CatalogWorkMigrationParticipant::SOURCE_TYPE
        )[0];
        $changedWork = $this->records(
            $changed,
            CatalogWorkMigrationParticipant::SOURCE_TYPE
        )[0];
        self::assertSame($firstWork->sourceId(), $changedWork->sourceId());
        self::assertNotSame($firstWork->payloadHash(), $changedWork->payloadHash());
    }

    public function testCompetingPositiveAliasListsFailClosed(): void
    {
        $result = $this->map([
            $this->book("alias-a", [$this->contained("A")]),
            $this->book("alias-b", [$this->contained("B")]),
        ], ["alias-a" => "representative", "alias-b" => "representative"]);

        self::assertSame([], $result->records());
        self::assertSame(
            1,
            $this->reasonCount(
                $result,
                CurrentV1ContainedWorkMappingReason::AliasConflict
            )
        );
    }

    public function testUnmatchedValidSeriesIsPreservedWithoutCreatingSeriesOrMembership(): void
    {
        $result = $this->map([
            $this->book("parent", [
                $this->contained(
                    "Child",
                    series: "Contained-only Series",
                    seriesIndex: "3"
                ),
            ]),
        ], ["parent" => "parent"]);

        self::assertSame([], $this->records(
            $result,
            CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame([], $this->records($result, "catalog_series"));
        $preserved = $this->records(
            $result,
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE
        );
        self::assertCount(1, $preserved);
        $plan = $preserved[0]->typedPlan();
        self::assertInstanceOf(PreservedSourceEvidencePlan::class, $plan);
        self::assertSame("contained_work_series_deferred", $plan->reasonCode());
        self::assertSame(
            1,
            $this->reasonCount(
                $result,
                CurrentV1ContainedWorkMappingReason::SeriesIdentityUnavailable
            )
        );
    }

    public function testManifestAndReviewedShapeDriftFailClosed(): void
    {
        try {
            $this->map(
                [$this->book("parent", [$this->contained("Child")])],
                ["parent" => "parent"],
                str_repeat("b", 64)
            );
            self::fail("Manifest drift must fail closed.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertStringContainsString(
                "contained-work contract",
                $failure->getMessage()
            );
        }

        $invalid = $this->book("parent", [$this->contained("Child")]);
        $payload = $invalid->payload();
        $payload["containedWorks"][0]["unexpected"] = "value";
        $this->expectException(MigrationRunnerFailure::class);
        $this->map(
            [new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "parent", $payload)],
            ["parent" => "parent"]
        );
    }

    /**
     * @param list<MigrationSourceRecord> $books
     * @param array<string,string> $representatives
     */
    private function map(
        array $books,
        array $representatives,
        string $inspectionDigest = self::DIGEST
    ): MigrationSourceMappingResult {
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], $inspectionDigest),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $books,
            []
        );
        return (new CurrentV1ContainedWorkMapper(
            new CurrentV1ReviewedContainedWorkContract(self::DIGEST)
        ))->map($inspection, $books, $representatives);
    }

    /** @param list<array<string,string>> $contained */
    private function book(
        string $id,
        array $contained,
        string $seriesName = ""
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => "Parent {$id}",
            "seriesName" => $seriesName,
            "containedWorks" => $contained,
        ]);
    }

    /** @return array{author:string,isbn:string,series:string,seriesIndex:string,title:string} */
    private function contained(
        string $title,
        string $author = "",
        string $isbn = "",
        string $series = "",
        string $seriesIndex = ""
    ): array {
        return compact("author", "isbn", "series", "seriesIndex", "title");
    }

    /** @return list<MigrationSourceRecord> */
    private function records(
        MigrationSourceMappingResult $result,
        string $sourceType
    ): array {
        return array_values(array_filter(
            $result->records(),
            static fn (MigrationSourceRecord $record): bool =>
                $record->sourceType() === $sourceType
        ));
    }

    private function record(
        MigrationSourceMappingResult $result,
        string $sourceType,
        string $sourceId
    ): MigrationSourceRecord {
        foreach ($result->records() as $record) {
            if (
                $record->sourceType() === $sourceType
                && $record->sourceId() === $sourceId
            ) {
                return $record;
            }
        }
        self::fail("Expected contained-work migration record is missing.");
    }

    private function reasonCount(
        MigrationSourceMappingResult $result,
        CurrentV1ContainedWorkMappingReason $reason
    ): int {
        return count(array_filter(
            $result->findings(),
            static fn ($finding): bool =>
                $finding->reasonCode() === $reason->value
        ));
    }
}
