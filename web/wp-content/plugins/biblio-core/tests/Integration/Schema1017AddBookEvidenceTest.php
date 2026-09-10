<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\{MetadataCandidate,MetadataCandidateId,MetadataLookupId,MetadataLookupSnapshot,MetadataMatchMethod};
use Biblio\Core\Catalog\{CanonicalIsbnIdentity,Isbn13};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\{CoreSchema1017Migration,CoreSchemaMigrationRegistry,CoreSchemaMigrator};
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataLookupSnapshotRepository;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;

final class Schema1017AddBookEvidenceTest extends PersistenceIntegrationTestCase
{
    public function testMigrationIsHealthyAndRetrySafe(): void
    {
        foreach (array_reverse($this->tableNames->schema1017Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->setHistoricalSchemaVersion(1016);
        $migration = new CoreSchema1017Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertTrue($this->migrator()->healthForVersion(1017)->isHealthy());
        self::assertSame(1016, $migration->sourceVersion());
        self::assertSame(1017, $migration->targetVersion());
        $this->setHistoricalSchemaVersion(1017);
    }

    public function testSnapshotRoundTripEnforcesActorLibraryAndExpiry(): void
    {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => "library-snapshot-roundtrip",
            "library_name" => "Snapshot",
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $repository = new WpdbMetadataLookupSnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $identity = CanonicalIsbnIdentity::fromIsbn(
            new Isbn13("9780306406157")
        );
        $candidate = new MetadataCandidate(
            "open_library",
            "OL-roundtrip",
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            "Roundtrip",
            null,
            [],
            ["eng"],
            [],
            null,
            null,
            null,
            null
        );
        $lookupId = new MetadataLookupId(
            "lookup-22222222222222222222222222222222"
        );
        $actorId = new UserId("actor-roundtrip");
        $libraryId = new LibraryId("library-snapshot-roundtrip");
        $repository->save(new MetadataLookupSnapshot(
            $lookupId,
            $actorId,
            $libraryId,
            $identity,
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            new DateTimeImmutable("2026-09-06T10:30:00+00:00"),
            [$candidate]
        ));
        $candidateId = MetadataCandidateId::fromCandidate($candidate);

        $restored = $repository->candidateForCommit(
            $lookupId,
            $candidateId,
            $actorId,
            $libraryId,
            new DateTimeImmutable("2026-09-06T10:29:59+00:00")
        );
        self::assertNotNull($restored);
        self::assertSame("OL-roundtrip", $restored->providerRecordId());
        self::assertNull($repository->candidateForCommit(
            $lookupId,
            $candidateId,
            new UserId("other-actor"),
            $libraryId,
            new DateTimeImmutable("2026-09-06T10:29:59+00:00")
        ));
        self::assertNull($repository->candidateForCommit(
            $lookupId,
            $candidateId,
            $actorId,
            $libraryId,
            new DateTimeImmutable("2026-09-06T10:30:00+00:00")
        ));
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
}
