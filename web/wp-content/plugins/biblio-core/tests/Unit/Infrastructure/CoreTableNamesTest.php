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
        self::assertSame("wp_biblio_libraries", $tableNames->libraries());
        self::assertSame(
            "wp_biblio_reading_rounds",
            $tableNames->readingRounds()
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
