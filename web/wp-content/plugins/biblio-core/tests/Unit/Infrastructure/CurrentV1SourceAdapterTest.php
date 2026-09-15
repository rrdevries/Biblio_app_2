<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Circulation\CirculationPlan;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use PHPUnit\Framework\TestCase;

final class CurrentV1SourceAdapterTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function testExactReviewedStructureProfilesAndEnumeratesDeterministically(): void
    {
        $root = $this->source();
        $package = (new FilesystemMigrationSourcePackageFactory())->build($root);
        $adapter = new CurrentV1SourceAdapter();
        $profile = $adapter->profile($package);
        $first = iterator_to_array($adapter->records($package, $profile));
        $second = iterator_to_array($adapter->records($package, $profile));

        self::assertSame(CurrentV1SourceAdapter::SOURCE_VERSION, $profile->sourceVersion());
        self::assertSame(1, $profile->categoryCounts()["book_records"]);
        self::assertSame(1, $profile->categoryCounts()["copy_records"]);
        self::assertSame(1, $profile->categoryCounts()["circulation_round_records"]);
        self::assertSame(1, $profile->categoryCounts()["contributor_occurrences"]);
        self::assertCount(8, $first);
        self::assertSame(
            array_map(static fn ($record): string => $record->payloadHash(), $first),
            array_map(static fn ($record): string => $record->payloadHash(), $second)
        );

        $records = [];
        $typeCounts = [];
        foreach ($first as $record) {
            $records[$record->sourceType() . ":" . $record->sourceId()] = $record;
            $typeCounts[$record->sourceType()] = ($typeCounts[$record->sourceType()] ?? 0) + 1;
        }
        self::assertSame(
            [CurrentV1SourceAdapter::BOOK . ":book-1"],
            $records[CurrentV1SourceAdapter::COPY . ":copy-1"]->references()
        );
        self::assertSame(
            [CurrentV1SourceAdapter::AUTHOR . ":author-1"],
            $records[CurrentV1SourceAdapter::BOOK . ":book-1"]->references()
        );
        self::assertCount(
            2,
            array_merge(
                $records[CurrentV1SourceAdapter::CIRCULATION_ROUND . ":loan-1"]
                    ->payload()["copy_occurrences"],
                $records[CurrentV1SourceAdapter::CIRCULATION_ROUND . ":loan-1"]
                    ->payload()["book_occurrences"]
            )
        );
        $circulationPlan = $records[
            CurrentV1SourceAdapter::CIRCULATION_ROUND . ":loan-1"
        ]->typedPlan();
        self::assertInstanceOf(CirculationPlan::class, $circulationPlan);
        self::assertTrue($circulationPlan->hasMaterialLifecycleConflict());

        $payload = (new MigrationSourceInspection(
            $package,
            $adapter,
            $profile,
            $first,
            $typeCounts
        ))->sourcePayload();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Private Author", $json);
        self::assertStringNotContainsString("private note body", $json);
        self::assertStringNotContainsString("Private Counterparty", $json);
        self::assertStringContainsString("D-MIG-LOAN-01 required", $json);
        self::assertStringContainsString("divergent_groups=1", $json);
        self::assertStringContainsString("no source precedence applied", $json);
        self::assertStringContainsString("no name merge permitted", $json);
    }

    public function testMalformedStableRecordIsReportedAndAccountedWithoutInventedId(): void
    {
        $root = $this->source(authors: [["displayName" => "Private ID-less Author"]]);
        $package = (new FilesystemMigrationSourcePackageFactory())->build($root);
        $adapter = new CurrentV1SourceAdapter();
        $profile = $adapter->profile($package);
        $records = iterator_to_array($adapter->records($package, $profile));

        self::assertSame(1, $profile->categoryCounts()["author_records"]);
        $strategy = array_values(array_filter(
            $profile->categoryStrategies(),
            static fn ($candidate): bool => $candidate->category() === "author_records"
        ))[0];
        self::assertSame(1, $strategy->nonObservationCount());
        self::assertSame("malformed_record", $strategy->nonObservationReason());
        self::assertCount(1, array_filter(
            $profile->findings(),
            static fn ($finding): bool => $finding->toArray()["reason_code"]
                === "malformed_record"
        ));
        self::assertCount(0, array_filter(
            $records,
            static fn ($record): bool => $record->sourceType() === CurrentV1SourceAdapter::AUTHOR
        ));
    }

    public function testVersionUnknownPathAndUnreviewedRecordFieldFailClosed(): void
    {
        $factory = new FilesystemMigrationSourcePackageFactory();
        $adapter = new CurrentV1SourceAdapter();

        $wrongVersion = $this->source(booksSchema: 30);
        $this->assertUnsupported(static fn () => $adapter->profile($factory->build($wrongVersion)));

        $unknownPath = $this->source();
        file_put_contents($unknownPath . "/data/unreviewed.json", "[]");
        $this->assertUnsupported(static fn () => $adapter->profile($factory->build($unknownPath)));

        $unknownField = $this->source(extraBookField: true);
        $this->assertUnsupported(static fn () => $adapter->profile($factory->build($unknownField)));

        $unknownNestedField = $this->source(extraAcquisitionField: true);
        $this->assertUnsupported(
            static fn () => $adapter->profile($factory->build($unknownNestedField))
        );

        $malformedAuxiliaryJson = $this->source();
        mkdir($malformedAuxiliaryJson . "/data/cover-cache", 0700, true);
        file_put_contents(
            $malformedAuxiliaryJson . "/data/cover-cache/structural-cache.json",
            "{"
        );
        $this->assertUnsupported(
            static fn () => $adapter->profile($factory->build($malformedAuxiliaryJson))
        );
    }

    public function testManifestBindingDetectsSourceBytesChangedAfterProfile(): void
    {
        $root = $this->source();
        $package = (new FilesystemMigrationSourcePackageFactory())->build($root);
        $adapter = new CurrentV1SourceAdapter();
        $profile = $adapter->profile($package);
        file_put_contents($root . "/data/authors.json", json_encode([
            "schemaVersion" => 2,
            "authors" => [],
        ], JSON_THROW_ON_ERROR));

        try {
            iterator_to_array($adapter->records($package, $profile));
            self::fail("Changed source bytes must fail closed.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceChanged, $failure->reason());
        }
    }

    /** @param callable():mixed $operation */
    private function assertUnsupported(callable $operation): void
    {
        try {
            $operation();
            self::fail("Unreviewed CURRENT V1 structure must fail closed.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::UnsupportedStructure, $failure->reason());
        }
    }

    /**
     * @param list<array<string, mixed>>|null $authors
     */
    private function source(
        int $booksSchema = 29,
        ?array $authors = null,
        bool $extraBookField = false,
        bool $extraAcquisitionField = false
    ): string {
        $root = $this->directory();
        mkdir($root . "/data", 0700, true);

        $book = [
            "id" => "book-1",
            "title" => "Synthetic Book",
            "authors" => ["Private Author"],
            "authorIds" => ["author-1"],
            "bookType" => "Leesboek",
            "carrier" => "Fysiek boek",
            "binding" => "",
            "editionFormat" => "standaard",
            "collectionStatus" => "owned",
            "ownershipStatus" => "owned",
            "readMarker" => "yes",
            "readStatus" => "finished",
            "readingRounds" => [[
                "id" => "round-1",
                "startedAt" => "2020-01-02",
                "finishedAt" => "2020-01-03",
                "pauses" => [],
            ]],
            "notes" => [[
                "id" => "note-1",
                "text" => "private note body",
                "createdAt" => "2020-01-02T00:00:00Z",
                "updatedAt" => "2020-01-03T00:00:00Z",
            ]],
            "circulationRounds" => [[
                "id" => "loan-1",
                "type" => "borrowed",
                "counterparty" => "Private Counterparty",
                "startDate" => ["value" => "2020-01-02", "precision" => "day"],
                "endDate" => null,
                "notes" => "private circulation note",
            ]],
            "containedWorks" => [],
            "categories" => [],
            "genres" => [],
            "seriesName" => "",
            "rating" => 4,
            "acquisition" => [
                "type" => "bought",
                "source" => "Private Shop",
                "date" => ["value" => "2020", "precision" => "year"],
            ],
            "variantOfBookId" => null,
        ];
        if ($extraBookField) {
            $book["unreviewed"] = true;
        }
        if ($extraAcquisitionField) {
            $book["acquisition"]["unreviewed"] = true;
        }
        $copyCirculation = $book["circulationRounds"];
        $copyCirculation[0]["endDate"] = [
            "value" => "2020-01-04",
            "precision" => "day",
        ];
        $copy = [
            "id" => "copy-1",
            "bookId" => "book-1",
            "status" => "owned",
            "ownershipStatus" => "owned",
            "condition" => "",
            "archived" => false,
            "archiveReason" => "",
            "notes" => "",
            "acquisition" => [
                "type" => "bought",
                "source" => "Private Shop",
                "date" => ["value" => "2020", "precision" => "year"],
            ],
            "circulationRounds" => $copyCirculation,
        ];
        $wishlist = [[
            "id" => "wish-1",
            "bookId" => "book-1",
            "fulfilledCopyId" => "",
            "status" => "active",
            "type" => "edition",
        ]];

        $this->writeJson($root, "books.json", [
            "schemaVersion" => $booksSchema,
            "books" => [$book],
            "copies" => [$copy],
            "wishlistItems" => $wishlist,
        ]);
        $this->writeJson($root, "authors.json", [
            "schemaVersion" => 2,
            "authors" => $authors ?? [[
                "id" => "author-1",
                "displayName" => "Private Author",
            ]],
        ]);
        $this->writeJson($root, "reading_goals.json", [
            "schemaVersion" => 2,
            "goals" => [["id" => "goal-1", "title" => "Synthetic Goal"]],
        ]);

        foreach ([
            "book_types.json" => [],
            "carriers.json" => [],
            "categories.json" => [],
            "genres.json" => [],
            "releases_excluded.json" => [],
            "releases_merged.json" => [],
            "taxonomy_review_queue.json" => [],
        ] as $file => $payload) {
            $this->writeJson($root, $file, $payload);
        }
        foreach ([
            "book_enrich_cache.json" => [1, ["entries"]],
            "cache.json" => [1, ["entries"]],
            "home_prefs.json" => [1, ["hiddenWidgets", "widgetOrder"]],
            "recommendations_ignored.json" => [1, ["ignoredBookIds"]],
            "recommendations_prefs.json" => [2, ["ignored", "shown"]],
            "releases.json" => [1, ["byAuthor", "lastCheckedAt", "lastRun", "mergedDiff"]],
            "releases_prefs.json" => [1, ["trackedAuthorIds"]],
        ] as $file => [$version, $fields]) {
            $payload = ["schemaVersion" => $version];
            foreach ($fields as $field) {
                $payload[$field] = [];
            }
            $this->writeJson($root, $file, $payload);
        }
        $this->writeJson($root, "next_to_read.json", ["items" => []]);
        $this->writeJson($root, "releases_dismissed.json", [
            "version" => 1,
            "dismissed" => [],
        ]);
        $this->writeJson($root, "taxonomy_aliases.json", [
            "version" => 2,
            "rules" => [],
        ]);

        return $root;
    }

    /** @param mixed $payload */
    private function writeJson(string $root, string $file, mixed $payload): void
    {
        file_put_contents(
            $root . "/data/" . $file,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . "/biblio-current-v1-" . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        $this->temporaryDirectories[] = $path;
        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $path = $directory . "/" . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
