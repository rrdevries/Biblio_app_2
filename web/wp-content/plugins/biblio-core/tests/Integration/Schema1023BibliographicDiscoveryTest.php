<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\Discovery\BibliographicRecordIdGenerator;
use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshot;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationIntent;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationAuthorization;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationService;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldConfirmationState;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1023Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicLocalDiscoveryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicMetadataRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionIdentifierClaimRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataFieldReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use DateTimeImmutable;

final class Schema1023BibliographicDiscoveryTest extends PersistenceIntegrationTestCase
{
    public function testLocalTextDiscoveryMatchesTitleAndAuthorTokensWithoutProviderIdentity(): void
    {
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "local-work",
            "work_title" => "The Dispossessed",
            "work_title_status" => "librarian_confirmed",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "local-edition",
            "work_id" => "local-work",
            "edition_title" => "The Dispossessed",
            "isbn_10" => null,
            "isbn_13" => null,
            "explicitly_no_isbn" => 1,
        ]);
        $this->database->insert($this->tableNames->authors(), [
            "author_id" => "local-author",
            "display_name" => "Ursula K. Le Guin",
        ]);
        $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "local-work",
            "author_id" => "local-author",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]);
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Dispossessed Le Guin")
        );

        $results = (new WpdbBibliographicLocalDiscoveryRepository(
            $this->database,
            $this->tableNames
        ))->searchText($query);

        self::assertSame([
            BibliographicCandidateType::LocalEdition,
            BibliographicCandidateType::LocalWork,
        ], array_map(static fn ($candidate) => $candidate->type(), $results));
        self::assertSame([null, null], array_map(
            static fn ($candidate): ?string => $candidate->providerKey(),
            $results
        ));
    }

    public function testMigrationIsAdditiveHealthyAndRetrySafe(): void
    {
        foreach (array_reverse($this->tableNames->schema1023Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $evidence = $this->tableNames->metadataFieldEvidence();
        $this->database->query(
            "ALTER TABLE `{$evidence}` "
                . "DROP CONSTRAINT metadata_field_evidence_match_supported,"
                . "DROP CONSTRAINT metadata_field_evidence_query_valid,"
                . "MODIFY queried_identifier VARCHAR(13) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
                . "ADD CONSTRAINT metadata_field_evidence_match_supported CHECK (match_method='exact_isbn'),"
                . "ADD CONSTRAINT metadata_field_evidence_query_valid CHECK ((queried_identifier_type='isbn_10' AND queried_identifier REGEXP '^[0-9]{9}[0-9X]$') OR (queried_identifier_type='isbn_13' AND queried_identifier REGEXP '^97[89][0-9]{10}$'))"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1022", false);
        $migration = new CoreSchema1023Migration($this->database, $this->tableNames);

        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPrecondition();
        $migration->migrate();
        $migration->assertPostcondition();

        self::assertSame(1022, $migration->sourceVersion());
        self::assertSame(1023, $migration->targetVersion());
        self::assertTrue((new CoreSchemaHealthChecker(
            $this->database,
            $this->tableNames
        ))->inspectForVersion(1023)->isHealthy());
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1023", false);
    }

    public function testUnknownPartialDiscoverySchemaFailsClosedBeforeMutation(): void
    {
        foreach (array_reverse($this->tableNames->schema1023Additions()) as $table) {
            $this->database->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $snapshots = $this->tableNames->bibliographicDiscoverySnapshots();
        $this->database->query(
            "CREATE TABLE `{$snapshots}` (discovery_id VARCHAR(191) NOT NULL PRIMARY KEY) "
                . "ENGINE=InnoDB"
        );
        update_option(CoreSchemaMigrator::VERSION_OPTION, "1022", false);

        try {
            (new CoreSchema1023Migration($this->database, $this->tableNames))
                ->assertPrecondition();
            self::fail("Unknown partial discovery schema was accepted.");
        } catch (CoreSchemaMigrationException $exception) {
            self::assertStringContainsString(
                "unknown discovery state",
                $exception->getMessage()
            );
            self::assertSame(0, (int) $this->database->get_var(
                $this->database->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLES "
                        . "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
                    DB_NAME,
                    $this->tableNames->bibliographicDiscoveryCandidates()
                )
            ));
        } finally {
            foreach (array_reverse($this->tableNames->schema1023Additions()) as $table) {
                $this->database->query("DROP TABLE IF EXISTS `{$table}`");
            }
            (new CoreSchema1023Migration($this->database, $this->tableNames))->migrate();
            update_option(CoreSchemaMigrator::VERSION_OPTION, "1023", false);
        }
    }

    public function testSnapshotRoundTripIsActorScopedQueryScopedAndExpires(): void
    {
        $repository = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("The Dispossessed Le Guin")
        );
        $candidate = $this->editionCandidate($query);
        $id = new MetadataLookupId("lookup-33333333333333333333333333333333");
        $actor = new UserId("discovery-actor");
        $repository->save(new BibliographicDiscoverySnapshot(
            $id,
            $actor,
            $query,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $candidateId = new MetadataCandidateId($candidate->id());

        $restored = $repository->candidateForMaterialization(
            $id,
            $candidateId,
            $actor,
            new DateTimeImmutable("2026-09-10T12:29:59+00:00")
        );

        self::assertNotNull($restored);
        self::assertSame($candidate->id(), $restored->id());
        self::assertSame($query->normalizedValue(), $restored->normalizedQuery());
        self::assertNull($repository->candidateForMaterialization(
            $id,
            $candidateId,
            new UserId("other-actor"),
            new DateTimeImmutable("2026-09-10T12:29:59+00:00")
        ));
        self::assertNull($repository->candidateForMaterialization(
            $id,
            $candidateId,
            $actor,
            new DateTimeImmutable("2026-09-10T12:30:00+00:00")
        ));
    }

    public function testMaterializationIsIdempotentPreservesEvidenceAndHasNoLibrarySideEffects(): void
    {
        $actor = new UserId("materialization-actor");
        $clock = new Schema1023FixedClock();
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("The Dispossessed Le Guin")
        );
        $candidate = $this->editionCandidate($query);
        $discoveryId = new MetadataLookupId("lookup-44444444444444444444444444444444");
        $snapshots->save(new BibliographicDiscoverySnapshot(
            $discoveryId,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository($this->database, $this->tableNames);
        $reviews = new WpdbMetadataFieldReviewRepository(
            $this->database,
            $this->tableNames
        );
        $transactions = new WpdbTransactionManager($this->database);
        $service = new BibliographicMaterializationService(
            new Schema1023Actor($actor),
            new Schema1023AllowMaterialization(),
            $snapshots,
            new WpdbBibliographicProviderIdentityRepository($this->database, $this->tableNames),
            new LocalEditionResolver(
                new IsbnCanonicalizer(),
                $claims,
                $editions,
                new WpdbBibliographicMetadataRepository($this->database, $this->tableNames)
            ),
            $claims,
            $works,
            $editions,
            $reviews,
            new Schema1023Ids(),
            $clock,
            $transactions
        );
        $candidateId = new MetadataCandidateId($candidate->id());

        $first = $service->materialize(
            $discoveryId,
            $candidateId,
            BibliographicMaterializationIntent::WorkAndEdition
        );
        $second = $service->materialize(
            $discoveryId,
            $candidateId,
            BibliographicMaterializationIntent::WorkAndEdition
        );
        $editionId = $first->edition()?->id()
            ?? throw new \LogicException("Expected materialized Edition.");
        $transactions->run(function () use (
            $reviews,
            $editionId,
            $candidate,
            $actor,
            $clock
        ): void {
            $review = $reviews->findForUpdate(
                MetadataRecordId::forEdition($editionId),
                MetadataField::Title,
                $clock->now()
            );
            $review->confirm(
                (new MetadataFieldValue($candidate->title()))->hash(),
                $actor,
                $clock->now()
            );
            $reviews->save($review);
        });
        $changedCandidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "open_library",
            "/books/OL10M",
            "/works/OL123W",
            $clock->now(),
            MetadataMatchMethod::TextSearch,
            $query,
            "Changed Provider Title",
            CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780060512750")),
            null,
            ["Ursula K. Le Guin"],
            ["eng"],
            ["Harper"],
            "1974",
            400,
            "Paperback",
            0
        );
        $changedDiscovery = new MetadataLookupId(
            "lookup-43434343434343434343434343434343"
        );
        $snapshots->save(new BibliographicDiscoverySnapshot(
            $changedDiscovery,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$changedCandidate]
        ));
        $service->materialize(
            $changedDiscovery,
            new MetadataCandidateId($changedCandidate->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame($first->work()->id()->value(), $second->work()->id()->value());
        self::assertSame($first->edition()?->id()->value(), $second->edition()?->id()->value());
        self::assertTrue($second->reused());
        self::assertSame(1, $this->countRows($this->tableNames->works()));
        self::assertSame(1, $this->countRows($this->tableNames->editions()));
        self::assertSame(1, $this->countRows($this->tableNames->editionIdentifierClaims()));
        self::assertGreaterThan(0, $this->countRows($this->tableNames->metadataFieldEvidence()));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT MAX(observation_count) "
                . "FROM `{$this->tableNames->metadataFieldEvidence()}`"
        ));
        self::assertSame(0, $this->countRows($this->tableNames->items()));
        self::assertSame(0, $this->countRows($this->tableNames->libraryActivityEvents()));
        self::assertSame(0, $this->countRows($this->tableNames->libraryCatalogContexts()));
        self::assertSame(0, $this->countRows($this->tableNames->wishlistEntries()));
        $storedTitle = $reviews->find(
            MetadataRecordId::forEdition($editionId),
            MetadataField::Title
        );
        self::assertSame(
            MetadataFieldConfirmationState::UserConfirmed,
            $storedTitle?->confirmationState()
        );
        self::assertSame("The Dispossessed", $storedTitle?->canonicalValue()?->value());
    }

    public function testStableExternalWorkCanMaterializeWithoutEditionOrItem(): void
    {
        $actor = new UserId("work-only-actor");
        $clock = new Schema1023FixedClock();
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("The Left Hand of Darkness")
        );
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "open_library",
            "/works/OL456W",
            "/works/OL456W",
            $clock->now(),
            MetadataMatchMethod::TextSearch,
            $query,
            "The Left Hand of Darkness",
            null,
            null,
            ["Ursula K. Le Guin"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $discoveryId = new MetadataLookupId("lookup-55555555555555555555555555555555");
        $snapshots->save(new BibliographicDiscoverySnapshot(
            $discoveryId,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository($this->database, $this->tableNames);
        $service = new BibliographicMaterializationService(
            new Schema1023Actor($actor),
            new Schema1023AllowMaterialization(),
            $snapshots,
            new WpdbBibliographicProviderIdentityRepository($this->database, $this->tableNames),
            new LocalEditionResolver(
                new IsbnCanonicalizer(),
                $claims,
                $editions,
                new WpdbBibliographicMetadataRepository($this->database, $this->tableNames)
            ),
            $claims,
            $works,
            $editions,
            new WpdbMetadataFieldReviewRepository($this->database, $this->tableNames),
            new Schema1023Ids(),
            $clock,
            new WpdbTransactionManager($this->database)
        );

        $result = $service->materialize(
            $discoveryId,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame("materialized-work", $result->work()->id()->value());
        self::assertNull($result->edition());
        self::assertSame(1, $this->countRows($this->tableNames->works()));
        self::assertSame(0, $this->countRows($this->tableNames->editions()));
        self::assertSame(0, $this->countRows($this->tableNames->items()));
        self::assertGreaterThan(0, $this->countRows($this->tableNames->metadataFieldEvidence()));
    }

    public function testMappedEditionWithConflictingCanonicalIsbnFailsClosed(): void
    {
        $actor = new UserId("identity-conflict-actor");
        $clock = new Schema1023FixedClock();
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Conflicting Edition")
        );
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "google_books",
            "volume-conflict",
            null,
            $clock->now(),
            MetadataMatchMethod::TextSearch,
            $query,
            "Conflicting Edition",
            CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780306406157")),
            null,
            ["Synthetic Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $discoveryId = new MetadataLookupId(
            "lookup-12121212121212121212121212121212"
        );
        $snapshots->save(new BibliographicDiscoverySnapshot(
            $discoveryId,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository(
            $this->database,
            $this->tableNames
        );
        $mappedWork = new Work(new WorkId("mapped-work"), "Mapped Work");
        $isbnWork = new Work(new WorkId("isbn-work"), "ISBN Work");
        $mappedEdition = new Edition(
            new EditionId("mapped-edition"),
            $mappedWork->id(),
            "Mapped Edition"
        );
        $isbn = CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780306406157"));
        $isbnEdition = new Edition(
            new EditionId("isbn-edition"),
            $isbnWork->id(),
            "ISBN Edition",
            $isbn->metadata()
        );
        $works->add($mappedWork);
        $works->add($isbnWork);
        $editions->add($mappedEdition);
        $editions->add($isbnEdition);
        $transactions = new WpdbTransactionManager($this->database);
        $transactions->run(function () use ($claims, $isbn, $isbnEdition): void {
            $claims->claim($isbn->isbn13(), $isbnEdition->id());
        });
        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $identities->claimWork(
            "google_books", "edition", "volume-conflict", $mappedWork->id()
        );
        $identities->claimEdition(
            "google_books", "volume-conflict", $mappedEdition->id()
        );
        $service = new BibliographicMaterializationService(
            new Schema1023Actor($actor),
            new Schema1023AllowMaterialization(),
            $snapshots,
            $identities,
            new LocalEditionResolver(
                new IsbnCanonicalizer(),
                $claims,
                $editions,
                new WpdbBibliographicMetadataRepository(
                    $this->database,
                    $this->tableNames
                )
            ),
            $claims,
            $works,
            $editions,
            new WpdbMetadataFieldReviewRepository(
                $this->database,
                $this->tableNames
            ),
            new Schema1023Ids(),
            $clock,
            $transactions
        );

        try {
            $service->materialize(
                $discoveryId,
                new MetadataCandidateId($candidate->id()),
                BibliographicMaterializationIntent::WorkAndEdition
            );
            self::fail("Conflicting provider and ISBN identities were accepted.");
        } catch (ValidationException $exception) {
            self::assertStringContainsString("identities conflict", $exception->getMessage());
        }
        self::assertSame(2, $this->countRows($this->tableNames->editions()));
        self::assertSame(0, $this->countRows($this->tableNames->metadataFieldEvidence()));
    }

    public function testMappedEditionClaimsLaterProviderWorkIdentityForItsExistingWork(): void
    {
        $scenario = $this->mappedEditionWithProviderWorkScenario(
            "lookup-13131313131313131313131313131313",
            false
        );

        $result = $scenario["service"]->materialize(
            $scenario["discovery_id"],
            $scenario["candidate_id"],
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame("mapped-work", $result->work()->id()->value());
        self::assertSame("mapped-edition", $result->edition()?->id()->value());
        self::assertTrue($result->reused());
        self::assertSame(
            "mapped-work",
            $scenario["identities"]->findWork(
                "open_library",
                "work",
                "/works/OL-LATER-W"
            )?->value()
        );
        self::assertSame(
            "mapped-work",
            $scenario["identities"]->findWork(
                "open_library",
                "edition",
                "/books/OL-LATER-M"
            )?->value()
        );
        self::assertSame(1, $this->countRows($this->tableNames->works()));
        self::assertSame(1, $this->countRows($this->tableNames->editions()));
    }

    public function testMappedEditionRejectsLaterProviderWorkIdentityForDifferentWork(): void
    {
        $scenario = $this->mappedEditionWithProviderWorkScenario(
            "lookup-14141414141414141414141414141414",
            true
        );

        try {
            $scenario["service"]->materialize(
                $scenario["discovery_id"],
                $scenario["candidate_id"],
                BibliographicMaterializationIntent::WorkAndEdition
            );
            self::fail("Conflicting provider Work and mapped Edition were accepted.");
        } catch (ValidationException $exception) {
            self::assertStringContainsString("identities conflict", $exception->getMessage());
        }
        self::assertSame(
            "other-work",
            $scenario["identities"]->findWork(
                "open_library",
                "work",
                "/works/OL-LATER-W"
            )?->value()
        );
        self::assertNull($scenario["identities"]->findWork(
            "open_library",
            "edition",
            "/books/OL-LATER-M"
        ));
        self::assertSame(0, $this->countRows($this->tableNames->metadataFieldEvidence()));
    }

    /**
     * @return array{
     *   service: BibliographicMaterializationService,
     *   discovery_id: MetadataLookupId,
     *   candidate_id: MetadataCandidateId,
     *   identities: WpdbBibliographicProviderIdentityRepository
     * }
     */
    private function mappedEditionWithProviderWorkScenario(
        string $lookupId,
        bool $preclaimConflictingWork
    ): array {
        $actor = new UserId("provider-work-reuse-actor");
        $clock = new Schema1023FixedClock();
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Later Provider Work Identity")
        );
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "open_library",
            "/books/OL-LATER-M",
            "/works/OL-LATER-W",
            $clock->now(),
            MetadataMatchMethod::TextSearch,
            $query,
            "Later Provider Work Identity",
            null,
            null,
            ["Synthetic Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $discoveryId = new MetadataLookupId($lookupId);
        $snapshots->save(new BibliographicDiscoverySnapshot(
            $discoveryId,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository(
            $this->database,
            $this->tableNames
        );
        $mappedWork = new Work(new WorkId("mapped-work"), "Mapped Work");
        $mappedEdition = new Edition(
            new EditionId("mapped-edition"),
            $mappedWork->id(),
            "Mapped Edition"
        );
        $works->add($mappedWork);
        $editions->add($mappedEdition);
        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $identities->claimEdition(
            "open_library",
            "/books/OL-LATER-M",
            $mappedEdition->id()
        );
        if ($preclaimConflictingWork) {
            $otherWork = new Work(new WorkId("other-work"), "Other Work");
            $works->add($otherWork);
            $identities->claimWork(
                "open_library",
                "work",
                "/works/OL-LATER-W",
                $otherWork->id()
            );
        }
        $transactions = new WpdbTransactionManager($this->database);

        return [
            "service" => new BibliographicMaterializationService(
                new Schema1023Actor($actor),
                new Schema1023AllowMaterialization(),
                $snapshots,
                $identities,
                new LocalEditionResolver(
                    new IsbnCanonicalizer(),
                    $claims,
                    $editions,
                    new WpdbBibliographicMetadataRepository(
                        $this->database,
                        $this->tableNames
                    )
                ),
                $claims,
                $works,
                $editions,
                new WpdbMetadataFieldReviewRepository(
                    $this->database,
                    $this->tableNames
                ),
                new Schema1023Ids(),
                $clock,
                $transactions
            ),
            "discovery_id" => $discoveryId,
            "candidate_id" => new MetadataCandidateId($candidate->id()),
            "identities" => $identities,
        ];
    }

    private function editionCandidate(
        BibliographicDiscoveryQuery $query
    ): BibliographicDiscoveryCandidate {
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "open_library",
            "/books/OL10M",
            "/works/OL123W",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "The Dispossessed",
            CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780060512750")),
            null,
            ["Ursula K. Le Guin"],
            ["eng"],
            ["Harper"],
            "1974",
            400,
            "Paperback",
            1
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}

final readonly class Schema1023Actor implements AuthenticatedUser
{
    public function __construct(private UserId $actor) {}
    public function requireUserId(): UserId { return $this->actor; }
}

final readonly class Schema1023AllowMaterialization implements
    BibliographicMaterializationAuthorization
{
    public function assertAllowed(UserId $actorId): void {}
}

final class Schema1023FixedClock implements MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-10T12:00:00+00:00");
    }
}

final class Schema1023Ids implements BibliographicRecordIdGenerator
{
    public function nextWorkId(): WorkId { return new WorkId("materialized-work"); }
    public function nextEditionId(): EditionId { return new EditionId("materialized-edition"); }
}
