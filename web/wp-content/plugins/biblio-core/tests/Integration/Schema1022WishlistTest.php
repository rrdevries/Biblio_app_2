<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1022Migration,CoreSchemaHealthChecker,CoreSchemaMigrationException};

final class Schema1022WishlistTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        foreach (array_reverse($this->tableNames->schema1022Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $migration = new CoreSchema1022Migration($this->database, $this->tableNames);
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        parent::tearDown();
    }

    public function testMigrationIsAdditiveHealthyAndRetrySafe(): void
    {
        foreach (array_reverse($this->tableNames->schema1022Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $migration = new CoreSchema1022Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1021, $migration->sourceVersion());
        self::assertSame(1022, $migration->targetVersion());
        self::assertCount(3, $this->tableNames->schema1022Additions());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1022)->isHealthy());
    }

    public function testPartialUnknownWishlistStateFailsClosed(): void
    {
        foreach (array_reverse($this->tableNames->schema1022Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $states = $this->tableNames->wishlistWorkStates();
        $this->database->query(
            "CREATE TABLE `{$states}` (user_id VARCHAR(191) NOT NULL PRIMARY KEY) ENGINE=InnoDB"
        );

        $this->expectException(CoreSchemaMigrationException::class);
        $this->expectExceptionMessage("unknown Wishlist state");
        (new CoreSchema1022Migration($this->database, $this->tableNames))
            ->assertPrecondition();
    }

    public function testDatabaseEnforcesExclusiveShapeAndDuplicateRules(): void
    {
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $states = $this->tableNames->wishlistWorkStates();
        $entries = $this->tableNames->wishlistEntries();
        self::assertSame(1, $this->database->insert($works, [
            "work_id" => "wish-schema-work",
            "work_title" => "Wishlist schema Work",
        ]));
        self::assertSame(1, $this->database->insert($editions, [
            "edition_id" => "wish-schema-edition-a",
            "work_id" => "wish-schema-work",
            "edition_title" => "Edition A",
        ]));
        self::assertSame(1, $this->database->insert($editions, [
            "edition_id" => "wish-schema-edition-b",
            "work_id" => "wish-schema-work",
            "edition_title" => "Edition B",
        ]));
        self::assertSame(1, $this->database->insert($states, [
            "user_id" => "schema-user",
            "work_id" => "wish-schema-work",
            "target_type" => "edition_specific",
            "created_at" => "2026-09-10 10:00:00.000000",
            "updated_at" => "2026-09-10 10:00:00.000000",
        ]));
        self::assertSame(1, $this->insertEntry("entry-a", "edition_specific", "wish-schema-edition-a"));
        self::assertSame(1, $this->insertEntry("entry-b", "edition_specific", "wish-schema-edition-b"));

        $this->database->suppress_errors(true);
        try {
            self::assertFalse($this->insertEntry("entry-duplicate", "edition_specific", "wish-schema-edition-a"));
            self::assertFalse($this->insertEntry("entry-mixed", "work_only", null));
            self::assertFalse($this->insertEntry("entry-invalid-shape", "edition_specific", null));
        } finally {
            $this->database->suppress_errors(false);
        }
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$entries}`"
        ));
    }

    public function testHealthRejectsEditionThatBelongsToAnotherWork(): void
    {
        $works = $this->tableNames->works();
        $editions = $this->tableNames->editions();
        $states = $this->tableNames->wishlistWorkStates();
        self::assertSame(1, $this->database->insert($works, [
            "work_id" => "wish-health-a",
            "work_title" => "Work A",
        ]));
        self::assertSame(1, $this->database->insert($works, [
            "work_id" => "wish-health-b",
            "work_title" => "Work B",
        ]));
        self::assertSame(1, $this->database->insert($editions, [
            "edition_id" => "wish-health-edition",
            "work_id" => "wish-health-b",
            "edition_title" => "Wrong Work Edition",
        ]));
        self::assertSame(1, $this->database->insert($states, [
            "user_id" => "health-user",
            "work_id" => "wish-health-a",
            "target_type" => "edition_specific",
            "created_at" => "2026-09-10 10:00:00.000000",
            "updated_at" => "2026-09-10 10:00:00.000000",
        ]));
        self::assertSame(1, $this->database->insert(
            $this->tableNames->wishlistEntries(),
            [
                "wishlist_entry_id" => "health-entry",
                "user_id" => "health-user",
                "work_id" => "wish-health-a",
                "target_type" => "edition_specific",
                "edition_id" => "wish-health-edition",
                "created_at" => "2026-09-10 10:00:00.000000",
                "updated_at" => "2026-09-10 10:00:00.000000",
            ]
        ));

        $health = (new CoreSchemaHealthChecker($this->database, $this->tableNames))
            ->inspectForVersion(1022);
        self::assertFalse($health->isHealthy());
        self::assertStringContainsString(
            "Wishlist Edition does not belong to its Work",
            $health->summary()
        );
    }

    private function insertEntry(
        string $entryId,
        string $targetType,
        ?string $editionId
    ): int|false {
        return $this->database->insert($this->tableNames->wishlistEntries(), [
            "wishlist_entry_id" => $entryId,
            "user_id" => "schema-user",
            "work_id" => "wish-schema-work",
            "target_type" => $targetType,
            "edition_id" => $editionId,
            "created_at" => "2026-09-10 10:00:00.000000",
            "updated_at" => "2026-09-10 10:00:00.000000",
        ]);
    }
}
