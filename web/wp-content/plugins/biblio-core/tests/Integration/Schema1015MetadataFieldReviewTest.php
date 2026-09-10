<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldConfirmationState;
use Biblio\Core\Application\Metadata\MetadataFieldEvidence;
use Biblio\Core\Application\Metadata\MetadataFieldProposalState;
use Biblio\Core\Application\Metadata\MetadataFieldReview;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Catalog\IsbnType;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1015Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataFieldReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use DateTimeImmutable;
use DateTimeZone;

final class Schema1015MetadataFieldReviewTest extends PersistenceIntegrationTestCase
{
    public function testMigrationCreatesHealthyRetrySafeSchema(): void
    {
        $this->dropAdditions();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1014", false);
        $migration = new CoreSchema1015Migration($this->database, $this->tableNames);

        try {
            $migration->assertPrecondition();
            $migration->migrate();
            $migration->migrate();
            $migration->assertPrecondition();
            $migration->assertPostcondition();
            $this->migrator()->migrate();

            self::assertSame(1022, $this->migrator()->installedVersion());
            self::assertTrue($this->migrator()->health()->isHealthy());
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testUnknownPartialMigrationFailsClosed(): void
    {
        $this->dropAdditions();
        $states = $this->tableNames->metadataFieldStates();
        $this->database->query(
            "CREATE TABLE `{$states}` ("
                . "metadata_record_id VARCHAR(191) NOT NULL PRIMARY KEY"
                . ") ENGINE=InnoDB"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1014", false);

        try {
            $this->expectException(CoreSchemaMigrationException::class);
            (new CoreSchema1015Migration($this->database, $this->tableNames))
                ->assertPrecondition();
        } finally {
            $this->restoreCurrentSchema();
        }
    }

    public function testReviewAndEvidenceHistoryRoundTripPersistently(): void
    {
        $repository = new WpdbMetadataFieldReviewRepository(
            $this->database,
            $this->tableNames
        );
        $transactions = new WpdbTransactionManager($this->database);
        $recordId = new MetadataRecordId("lookup-9780441172719");
        $value = new MetadataFieldValue(["Frank Herbert", "Brian Herbert"]);
        $other = new MetadataFieldValue(["Frank Herbert"]);
        $actor = new UserId("user-1");

        $transactions->run(function () use ($repository, $recordId, $value, $other): void {
            $review = $repository->findForUpdate(
                $recordId,
                MetadataField::Contributors,
                $this->at(0)
            );
            $review->observe(
                $value,
                $this->evidence("open_library", "OL1M", 1),
                $this->at(1)
            );
            $review->observe(
                $value,
                $this->evidence("google_books", "GB1", 2),
                $this->at(2)
            );
            $review->observe(
                $other,
                $this->evidence("google_books", "GB2", 3),
                $this->at(3)
            );
            $repository->save($review);
        });

        $transactions->run(function () use ($repository, $recordId, $value, $actor): void {
            $review = $repository->findForUpdate(
                $recordId,
                MetadataField::Contributors,
                $this->at(4)
            );
            $review->confirm($value->hash(), $actor, $this->at(4));
            $repository->save($review);
        });

        $stored = $repository->find($recordId, MetadataField::Contributors);
        self::assertNotNull($stored);
        self::assertSame(
            MetadataFieldConfirmationState::UserConfirmed,
            $stored->confirmationState()
        );
        self::assertSame(
            ["Frank Herbert", "Brian Herbert"],
            $stored->canonicalValue()?->value()
        );
        self::assertCount(0, $stored->activeProposals());
        self::assertCount(2, $stored->proposals());
        self::assertCount(2, $this->proposalEvidenceFor($stored, $value->hash()));
        self::assertContains(
            MetadataFieldProposalState::Superseded,
            array_map(
                static fn ($proposal): MetadataFieldProposalState => $proposal->state(),
                $stored->proposals()
            )
        );
        self::assertSame(3, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->metadataFieldEvidence()}`"
        ));
    }

    /** @return list<MetadataFieldEvidence> */
    private function proposalEvidenceFor(MetadataFieldReview $review, string $hash): array
    {
        foreach ($review->proposals() as $proposal) {
            if ($proposal->value()->hash() === $hash) {
                return $proposal->evidence();
            }
        }
        return [];
    }

    private function evidence(string $provider, string $record, int $minute): MetadataFieldEvidence
    {
        return new MetadataFieldEvidence(
            $provider,
            $record,
            $this->at($minute),
            $this->at($minute),
            1,
            MetadataMatchMethod::ExactIsbn,
            IsbnType::Isbn13,
            "9780441172719"
        );
    }

    private function at(int $minute): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf("2026-09-06 10:%02d:00.000000", $minute),
            new DateTimeZone("UTC")
        );
    }

    private function migrator(): CoreSchemaMigrator
    {
        return new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        );
    }

    private function dropAdditions(): void
    {
        foreach (array_reverse($this->tableNames->schema1015Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function restoreCurrentSchema(): void
    {
        $this->dropAdditions();
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1014", false);
        $this->migrator()->migrate();
    }
}
