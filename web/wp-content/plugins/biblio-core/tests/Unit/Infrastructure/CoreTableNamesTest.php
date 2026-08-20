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
        self::assertSame("wp_biblio_libraries", $tableNames->libraries());
        self::assertSame(
            "wp_biblio_reading_rounds",
            $tableNames->readingRounds()
        );
        self::assertSame(
            "wp_biblio_library_catalog_contexts",
            $tableNames->libraryCatalogContexts()
        );
        self::assertSame(
            "wp_biblio_library_activity_events",
            $tableNames->libraryActivityEvents()
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
