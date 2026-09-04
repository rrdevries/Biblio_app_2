<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CoreTableNamesTest extends TestCase
{
    public function testSafeWordPressPrefixBuildsAllCoreTableNames(): void
    {
        $tableNames = new CoreTableNames("wp_");

        self::assertCount(8, $tableNames->all());
        self::assertCount(7, $tableNames->schema1001Additions());
        self::assertCount(15, $tableNames->schema1001());
        self::assertCount(16, $tableNames->schema1004());
        self::assertCount(19, $tableNames->schema1005());
        self::assertCount(21, $tableNames->schema1006());
        self::assertCount(22, $tableNames->schema1008());
        self::assertCount(4, $tableNames->schema1009Additions());
        self::assertCount(26, $tableNames->schema1009());
        self::assertCount(2, $tableNames->schema1010Additions());
        self::assertCount(28, $tableNames->schema1010());
        self::assertCount(1, $tableNames->schema1011Additions());
        self::assertCount(29, $tableNames->schema1011());
        self::assertCount(1, $tableNames->schema1012Additions());
        self::assertCount(30, $tableNames->schema1012());
        self::assertCount(2, $tableNames->schema1013Additions());
        self::assertCount(32, $tableNames->schema1013());
        self::assertSame("wp_biblio_libraries", $tableNames->libraries());
        self::assertSame(
            "wp_biblio_reading_rounds",
            $tableNames->readingRounds()
        );
        self::assertSame("wp_biblio_private_notes", $tableNames->privateNotes());
        self::assertSame("wp_biblio_ratings", $tableNames->ratings());
        self::assertSame("wp_biblio_reviews", $tableNames->reviews());
        self::assertSame(
            "wp_biblio_contribution_publications",
            $tableNames->contributionPublications()
        );
        self::assertSame(
            "wp_biblio_next_reading_undo",
            $tableNames->nextReadingUndo()
        );
        self::assertSame(
            "wp_biblio_library_catalog_contexts",
            $tableNames->libraryCatalogContexts()
        );
        self::assertSame(
            "wp_biblio_library_activity_events",
            $tableNames->libraryActivityEvents()
        );
        self::assertSame("wp_biblio_authors", $tableNames->authors());
        self::assertSame("wp_biblio_work_contributors", $tableNames->workContributors());
        self::assertSame("wp_biblio_series", $tableNames->series());
        self::assertSame("wp_biblio_work_series", $tableNames->workSeries());
        self::assertSame("wp_biblio_locations", $tableNames->locations());
        self::assertSame("wp_biblio_collections", $tableNames->collections());
        self::assertSame(
            "wp_biblio_collection_memberships",
            $tableNames->collectionMemberships()
        );
    }

    public function testUnsafePrefixIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoreTableNames("unsafe-prefix-");
    }

    public function testTableNameBeyondMariaDbLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CoreTableNames(str_repeat("p", 50));
    }
}
