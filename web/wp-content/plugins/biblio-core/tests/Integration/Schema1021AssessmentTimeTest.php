<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1021Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;

final class Schema1021AssessmentTimeTest extends PersistenceIntegrationTestCase
{
    protected function tearDown(): void
    {
        if (!$this->hasColumn($this->tableNames->ratings(), "assessed_at")) {
            $migration = new CoreSchema1021Migration(
                $this->database,
                $this->tableNames
            );
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->assertPostcondition();
        }

        parent::tearDown();
    }

    public function testUpgradePreservesNativeAssessmentTimesAndAllowsUnknown(): void
    {
        $this->restoreSchema1020();
        $this->seedWork();
        self::assertSame(1, $this->database->insert(
            $this->tableNames->ratings(),
            [
                "rating_id" => "rating-before-1021",
                "user_id" => "501",
                "work_id" => "work-assessment-1021",
                "reading_round_id" => null,
                "rating_half_units" => 9,
                "created_at" => "2026-09-10 08:00:00.123456",
                "updated_at" => "2026-09-10 08:00:00.123456",
                "rating_version" => 1,
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->reviews(),
            [
                "review_id" => "review-before-1021",
                "user_id" => "501",
                "work_id" => "work-assessment-1021",
                "reading_round_id" => null,
                "review_content" => "Synthetic review",
                "created_at" => "2026-09-10 08:01:00.654321",
                "updated_at" => "2026-09-10 08:01:00.654321",
                "review_version" => 1,
            ]
        ), $this->database->last_error);

        $migration = new CoreSchema1021Migration(
            $this->database,
            $this->tableNames
        );
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();
        // Simulate an interruption after both ALTER statements but before the
        // pre-1021 backfill completed. A retry must finish that backfill.
        self::assertSame(1, $this->database->update(
            $this->tableNames->reviews(),
            ["assessed_at" => null],
            ["review_id" => "review-before-1021"]
        ), $this->database->last_error);
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(
            "2026-09-10 08:00:00.123456",
            $this->database->get_var(
                "SELECT assessed_at FROM `{$this->tableNames->ratings()}` "
                    . "WHERE rating_id='rating-before-1021'"
            )
        );
        self::assertSame(
            "2026-09-10 08:01:00.654321",
            $this->database->get_var(
                "SELECT assessed_at FROM `{$this->tableNames->reviews()}` "
                    . "WHERE review_id='review-before-1021'"
            )
        );
        self::assertSame(1, $this->database->insert(
            $this->tableNames->ratings(),
            [
                "rating_id" => "rating-unknown-time",
                "user_id" => "501",
                "work_id" => "work-assessment-1021",
                "reading_round_id" => "round-distinct-1021",
                "rating_half_units" => 8,
                "assessed_at" => null,
                "created_at" => "2026-09-10 12:00:00.000000",
                "updated_at" => "2026-09-10 12:00:00.000000",
                "rating_version" => 1,
            ]
        ), $this->database->last_error);
        self::assertNull($this->database->get_var(
            "SELECT assessed_at FROM `{$this->tableNames->ratings()}` "
                . "WHERE rating_id='rating-unknown-time'"
        ));
        self::assertSame(1020, $migration->sourceVersion());
        self::assertSame(1021, $migration->targetVersion());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1021)->isHealthy());
    }

    public function testUnknownPartialStateFailsClosed(): void
    {
        $this->restoreSchema1020();
        $ratings = $this->tableNames->ratings();
        $this->database->query(
            "ALTER TABLE `{$ratings}` ADD assessed_at DATETIME(6) NULL "
                . "AFTER rating_half_units"
        );

        try {
            (new CoreSchema1021Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Unknown partial assessment-time state was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown assessment-time state",
                $exception->getMessage()
            );
        } finally {
            $this->database->query(
                "ALTER TABLE `{$ratings}` DROP COLUMN assessed_at"
            );
            (new CoreSchema1021Migration($this->database, $this->tableNames))
                ->migrate();
        }
    }

    private function restoreSchema1020(): void
    {
        foreach ([$this->tableNames->ratings(), $this->tableNames->reviews()] as $table) {
            if ($this->hasColumn($table, "assessed_at")) {
                $this->database->query(
                    "ALTER TABLE `{$table}` DROP COLUMN assessed_at"
                );
            }
        }
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1020", false);
    }

    private function seedWork(): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            [
                "work_id" => "work-assessment-1021",
                "work_title" => "Synthetic assessment Work",
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->readingRounds(),
            [
                "reading_round_id" => "round-distinct-1021",
                "user_id" => "501",
                "work_id" => "work-assessment-1021",
                "item_id" => null,
                "external_loan_id" => null,
                "started_at" => null,
                "round_outcome" => "completed",
                "provenance" => "historical_manual",
                "reading_finished_year" => 2025,
                "created_at" => "2026-09-10 07:00:00.000000",
                "updated_at" => "2026-09-10 07:00:00.000000",
                "ended_at" => "2026-09-10 07:00:00.000000",
                "round_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
            DB_NAME,
            $table,
            $column
        )) === 1;
    }
}
