<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealth;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthWarning;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use PHPUnit\Framework\TestCase;

final class RegistryProbeMigration implements CoreSchemaMigration
{
    public function __construct(
        private readonly int $source,
        private readonly int $target
    ) {
    }

    public function sourceVersion(): int
    {
        return $this->source;
    }

    public function targetVersion(): int
    {
        return $this->target;
    }

    public function assertPrecondition(): void
    {
    }

    public function migrate(): void
    {
    }

    public function assertPostcondition(): void
    {
    }
}

final class CoreSchemaInfrastructureTest extends TestCase
{
    public function testWarningsAreReportableWithoutBlockingHealth(): void
    {
        $warning = new CoreSchemaHealthWarning(
            "structured_health_probe",
            "A non-blocking condition was detected.",
            [
                "library_id" => "library-1",
                "candidate_ids" => ["genre-1", "genre-2"],
            ]
        );
        $health = new CoreSchemaHealth([], [$warning]);

        self::assertTrue($health->isHealthy());
        self::assertSame([], $health->errors());
        self::assertSame([], $health->issues());
        self::assertSame([$warning], $health->warnings());
        self::assertSame("library-1", $health->warnings()[0]->context()["library_id"]);
        self::assertStringContainsString($warning->code(), $health->summary());
    }

    public function testBlockingErrorsRemainBlockingWhenWarningsExist(): void
    {
        $warning = new CoreSchemaHealthWarning(
            "non_blocking_probe",
            "Non-blocking diagnostic."
        );
        $health = new CoreSchemaHealth(["Missing required table"], [$warning]);

        self::assertFalse($health->isHealthy());
        self::assertSame(["Missing required table"], $health->errors());
        self::assertSame(["Missing required table"], $health->issues());
        self::assertSame([$warning], $health->warnings());
        self::assertSame("Missing required table", $health->summary());
    }

    public function testExplicitRegistryOffersMigrationsInDeclaredOrder(): void
    {
        $first = new RegistryProbeMigration(1000, 1001);
        $second = new RegistryProbeMigration(1001, 1002);
        $registry = CoreSchemaMigrationRegistry::explicit($first, $second);

        self::assertSame([$first, $second], $registry->migrations());
    }
}
