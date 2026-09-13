<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\Discovery\BibliographicRecordIdGenerator;
use Biblio\Core\Application\Metadata\Author\CanonicalAuthorMaterializer;
use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRace;
use Biblio\Core\Application\Metadata\Author\AuthorCreditProviderSourceType;
use Biblio\Core\Application\Metadata\Author\OpenLibraryAuthorId;
use Biblio\Core\Application\Metadata\Discovery\BibliographicAuthorCredit;
use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryService;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshot;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderDiscoveryResult;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextDiscoveryProvider;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationIntent;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationAuthorization;
use Biblio\Core\Application\Metadata\Discovery\BibliographicMaterializationService;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\CandidateClassifier;
use Biblio\Core\Application\Metadata\FirstSufficientMetadataLookupService;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldConfirmationState;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupIdGenerator;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataProvider;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Application\Metadata\ProviderLookupResult;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorReference;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery;
use Biblio\Core\Catalog\Author;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\AuthorVersion;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\ContributorPosition;
use Biblio\Core\Catalog\ContributorRole;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkContributor;
use Biblio\Core\Catalog\WritableAuthorRepository;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchema1023Migration;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrationRegistry;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaMigrator;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicLocalDiscoveryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicMetadataRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicAuthorWorkSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicSearchProvider;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbAuthorContributorCreditRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbAuthorRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionIdentifierClaimRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataFieldReviewRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\WordPress\OpaqueCanonicalAuthorMaterializationIdGenerator;
use DateTimeImmutable;

final class Schema1023BibliographicDiscoveryTest extends PersistenceIntegrationTestCase
{
    public function testGenericStrongAuthorMaterializationIsIdempotentSharedAndSearchReadable(): void
    {
        $actor = new UserId("author-mat-strong-actor");
        $clock = new Schema1023FixedClock();
        $ids = new Schema1023SequenceIds();
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Octavia Butler Kindred")
        );
        $candidate = $this->authorCandidate(
            $query,
            "/works/OL100W",
            "Kindred",
            [
                $this->strongAuthor("Octavia E. Butler", 1, "/works/OL100W", "OL100A"),
                $this->strongAuthor(
                    "Second Author",
                    2,
                    "/works/OL100W",
                    "OL200A",
                    ContributorRole::CoAuthor
                ),
            ]
        );
        $discoveryId = new MetadataLookupId("lookup-00000000000000000000000000000011");
        $this->saveAuthorSnapshot($discoveryId, $actor, $query, $candidate, $clock);
        $service = $this->genericMaterializer($actor, $clock, $ids);

        $first = $service->materialize(
            $discoveryId,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkOnly
        );
        $second = $service->materialize(
            $discoveryId,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame($first->work()->id()->value(), $second->work()->id()->value());
        self::assertSame(2, $this->countRows($this->tableNames->authors()));
        self::assertSame(2, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(2, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE source_entity_type='author' AND target_type='author'"
        ));
        self::assertSame([1, 2], array_map(
            static fn (object $row): int => (int) $row->contributor_position,
            $this->database->get_results(
                "SELECT contributor_position FROM `{$this->tableNames->workContributors()}` "
                    . "ORDER BY contributor_position"
            )
        ));
        self::assertSame(["author", "co_author"], array_map(
            static fn (object $row): string => (string) $row->contributor_role,
            $this->database->get_results(
                "SELECT contributor_role FROM `{$this->tableNames->workContributors()}` "
                    . "ORDER BY contributor_position"
            )
        ));

        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $octaviaId = $identities->findAuthor("open_library", "/authors/OL100A")
            ?? throw new \LogicException("Expected Open Library Author claim.");
        $countsBeforeSearch = [
            $this->countRows($this->tableNames->authors()),
            $this->countRows($this->tableNames->workContributors()),
            $this->countRows($this->tableNames->authorContributorCredits()),
        ];
        $authorSearch = (new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchAuthors(new BibliographicTextSearchQuery("Octavia Butler"));
        self::assertSame($octaviaId->value(), $authorSearch->items()[0]
            ->reference()->authorId()?->value());
        $works = (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical($octaviaId),
            0,
            10
        );
        self::assertSame($first->work()->id()->value(), $works->items()[0]
            ->reference()->workId()?->value());
        self::assertSame($countsBeforeSearch, [
            $this->countRows($this->tableNames->authors()),
            $this->countRows($this->tableNames->workContributors()),
            $this->countRows($this->tableNames->authorContributorCredits()),
        ]);

        $queryTwo = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Parable Octavia")
        );
        $candidateTwo = $this->authorCandidate(
            $queryTwo,
            "/works/OL101W",
            "Parable of the Sower",
            [$this->strongAuthor("Octavia Butler", 1, "/works/OL101W", "OL100A")]
        );
        $discoveryTwo = new MetadataLookupId("lookup-00000000000000000000000000000012");
        $this->saveAuthorSnapshot($discoveryTwo, $actor, $queryTwo, $candidateTwo, $clock);
        $service->materialize(
            $discoveryTwo,
            new MetadataCandidateId($candidateTwo->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame(2, $this->countRows($this->tableNames->authors()));
        self::assertSame(3, $this->countRows($this->tableNames->workContributors()));
    }

    public function testGoogleNameOnlyMaterializationStaysProvisionalAndSourceScoped(): void
    {
        $actor = new UserId("author-mat-google-actor");
        $clock = new Schema1023FixedClock();
        $ids = new Schema1023SequenceIds();
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Peter King first")
        );
        $candidate = $this->googleEditionAuthorCandidate(
            $query,
            "google-volume-one",
            "First Work",
            "Peter King"
        );
        $discoveryId = new MetadataLookupId("lookup-00000000000000000000000000000021");
        $this->saveAuthorSnapshot($discoveryId, $actor, $query, $candidate, $clock);
        $service = $this->genericMaterializer($actor, $clock, $ids);

        $first = $service->materialize(
            $discoveryId,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );
        $replay = $service->materialize(
            $discoveryId,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );
        self::assertSame($first->work()->id()->value(), $replay->work()->id()->value());

        $queryTwo = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Peter King second")
        );
        $candidateTwo = $this->googleEditionAuthorCandidate(
            $queryTwo,
            "google-volume-two",
            "Second Work",
            "Peter King"
        );
        $discoveryTwo = new MetadataLookupId("lookup-00000000000000000000000000000022");
        $this->saveAuthorSnapshot($discoveryTwo, $actor, $queryTwo, $candidateTwo, $clock);
        $service->materialize(
            $discoveryTwo,
            new MetadataCandidateId($candidateTwo->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame(2, $this->countRows($this->tableNames->authors()));
        self::assertSame(2, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(2, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->countRows($this->tableNames->editions()));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE source_entity_type='author' OR target_type='author'"
        ));
        self::assertSame(["provisional", "provisional"], array_map(
            static fn (object $row): string => (string) $row->identity_status,
            $this->database->get_results(
                "SELECT identity_status FROM `{$this->tableNames->authors()}` "
                    . "ORDER BY author_id"
            )
        ));
    }

    public function testExactCreditPromotesAndNameOnlyReplayNeverDowngrades(): void
    {
        $actor = new UserId("author-mat-promotion-actor");
        $clock = new Schema1023FixedClock();
        $ids = new Schema1023SequenceIds();
        $service = $this->genericMaterializer($actor, $clock, $ids);

        $query = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("promotion one"));
        $nameOnly = $this->authorCandidate(
            $query,
            "/works/OL300W",
            "Promotion Work",
            [$this->nameOnlyAuthor("Exact Credit", 1, "/works/OL300W")]
        );
        $lookup = new MetadataLookupId("lookup-00000000000000000000000000000031");
        $this->saveAuthorSnapshot($lookup, $actor, $query, $nameOnly, $clock);
        $service->materialize(
            $lookup,
            new MetadataCandidateId($nameOnly->id()),
            BibliographicMaterializationIntent::WorkOnly
        );
        $originalAuthorId = (string) $this->database->get_var(
            "SELECT author_id FROM `{$this->tableNames->authors()}`"
        );

        $queryStrong = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("promotion two"));
        $strong = $this->authorCandidate(
            $queryStrong,
            "/works/OL300W",
            "Promotion Work",
            [$this->strongAuthor("Exact Credit", 1, "/works/OL300W", "OL300A")]
        );
        $lookupStrong = new MetadataLookupId("lookup-00000000000000000000000000000032");
        $this->saveAuthorSnapshot($lookupStrong, $actor, $queryStrong, $strong, $clock);
        $service->materialize(
            $lookupStrong,
            new MetadataCandidateId($strong->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        $queryReplay = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("promotion three"));
        $nameReplay = $this->authorCandidate(
            $queryReplay,
            "/works/OL300W",
            "Promotion Work",
            [$this->nameOnlyAuthor("Exact Credit", 1, "/works/OL300W")]
        );
        $lookupReplay = new MetadataLookupId("lookup-00000000000000000000000000000033");
        $this->saveAuthorSnapshot($lookupReplay, $actor, $queryReplay, $nameReplay, $clock);
        $service->materialize(
            $lookupReplay,
            new MetadataCandidateId($nameReplay->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        $author = $this->database->get_row(
            "SELECT author_id,identity_status FROM `{$this->tableNames->authors()}`"
        );
        self::assertSame($originalAuthorId, (string) $author->author_id);
        self::assertSame("resolved", (string) $author->identity_status);
        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(1, $this->countRows($this->tableNames->authorContributorCredits()));
    }

    public function testPositionConflictKeepsTruthfulUnresolvedEvidenceWithoutPartialAuthorGraph(): void
    {
        $actor = new UserId("author-mat-conflict-actor");
        $clock = new Schema1023FixedClock();
        $ids = new Schema1023SequenceIds();
        $service = $this->genericMaterializer($actor, $clock, $ids);

        $query = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("conflict work"));
        $workCandidate = $this->authorCandidate(
            $query,
            "/works/OL400W",
            "Conflict Work",
            [$this->strongAuthor("First Author", 1, "/works/OL400W", "OL400A")]
        );
        $lookup = new MetadataLookupId("lookup-00000000000000000000000000000041");
        $this->saveAuthorSnapshot($lookup, $actor, $query, $workCandidate, $clock);
        $first = $service->materialize(
            $lookup,
            new MetadataCandidateId($workCandidate->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        $editionQuery = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("conflict edition")
        );
        $credit = new BibliographicAuthorCredit(
            "Other Author",
            ContributorRole::Author,
            new ContributorPosition(1),
            AuthorCreditProviderSourceType::Edition,
            "/books/OL400M"
        );
        $editionCandidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "open_library",
            "/books/OL400M",
            "/works/OL400W",
            $clock->now(),
            MetadataMatchMethod::TextSearch,
            $editionQuery,
            "Conflict Edition",
            null,
            null,
            ["Other Author"],
            [],
            [],
            null,
            null,
            null,
            0,
            [$credit]
        );
        $editionLookup = new MetadataLookupId("lookup-00000000000000000000000000000042");
        $this->saveAuthorSnapshot(
            $editionLookup,
            $actor,
            $editionQuery,
            $editionCandidate,
            $clock
        );
        $second = $service->materialize(
            $editionLookup,
            new MetadataCandidateId($editionCandidate->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );

        self::assertSame($first->work()->id()->value(), $second->work()->id()->value());
        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(2, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}` "
                . "WHERE materialization_status='unresolved' "
                . "AND review_reason='structural_ambiguity'"
        ));
        self::assertSame(1, $this->countRows($this->tableNames->editions()));
    }

    public function testStrongClaimConflictKeepsExistingGraphsAndCreatesNoOrphan(): void
    {
        $actor = new UserId("author-mat-identity-conflict-actor");
        $clock = new Schema1023FixedClock();
        $service = $this->genericMaterializer(
            $actor,
            $clock,
            new Schema1023SequenceIds()
        );

        $firstQuery = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("identity conflict first")
        );
        $first = $this->authorCandidate(
            $firstQuery,
            "/works/OL500W",
            "First Identity Work",
            [$this->nameOnlyAuthor("First Credit", 1, "/works/OL500W")]
        );
        $firstLookup = new MetadataLookupId("lookup-00000000000000000000000000000061");
        $this->saveAuthorSnapshot($firstLookup, $actor, $firstQuery, $first, $clock);
        $service->materialize(
            $firstLookup,
            new MetadataCandidateId($first->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        $secondQuery = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("identity conflict second")
        );
        $second = $this->authorCandidate(
            $secondQuery,
            "/works/OL501W",
            "Second Identity Work",
            [$this->strongAuthor("Claimed Author", 1, "/works/OL501W", "OL501A")]
        );
        $secondLookup = new MetadataLookupId("lookup-00000000000000000000000000000062");
        $this->saveAuthorSnapshot($secondLookup, $actor, $secondQuery, $second, $clock);
        $service->materialize(
            $secondLookup,
            new MetadataCandidateId($second->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        $conflictQuery = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("identity conflict proof")
        );
        $conflict = $this->authorCandidate(
            $conflictQuery,
            "/works/OL500W",
            "First Identity Work",
            [$this->strongAuthor("First Credit", 1, "/works/OL500W", "OL501A")]
        );
        $conflictLookup = new MetadataLookupId("lookup-00000000000000000000000000000063");
        $this->saveAuthorSnapshot(
            $conflictLookup,
            $actor,
            $conflictQuery,
            $conflict,
            $clock
        );
        $service->materialize(
            $conflictLookup,
            new MetadataCandidateId($conflict->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame(2, $this->countRows($this->tableNames->works()));
        self::assertSame(2, $this->countRows($this->tableNames->authors()));
        self::assertSame(2, $this->countRows($this->tableNames->workContributors()));
        self::assertSame(2, $this->countRows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}` "
                . "WHERE review_reason='identity_conflict'"
        ));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE source_entity_type='author' AND provider_record_id='/authors/OL501A'"
        ));
    }

    public function testHardAuthorFailureRollsBackAndTypedRaceRetriesWholeMaterialization(): void
    {
        $actor = new UserId("author-mat-atomicity-actor");
        $clock = new Schema1023FixedClock();
        $query = BibliographicDiscoveryQuery::text(new BibliographicTextQuery("atomicity work"));
        $candidate = $this->authorCandidate(
            $query,
            "/works/OL600W",
            "Atomicity Work",
            [$this->nameOnlyAuthor("Atomic Author", 1, "/works/OL600W")]
        );
        $lookup = new MetadataLookupId("lookup-00000000000000000000000000000051");
        $this->saveAuthorSnapshot($lookup, $actor, $query, $candidate, $clock);
        $realAuthors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $failing = new Schema1023FailingAuthorRepository(
            $realAuthors,
            1,
            new PersistenceException("Injected Author persistence failure.")
        );
        $service = $this->genericMaterializer(
            $actor,
            $clock,
            new Schema1023SequenceIds(),
            $failing
        );

        try {
            $service->materialize(
                $lookup,
                new MetadataCandidateId($candidate->id()),
                BibliographicMaterializationIntent::WorkOnly
            );
            self::fail("Expected hard Author persistence failure.");
        } catch (PersistenceException $exception) {
            self::assertSame("Injected Author persistence failure.", $exception->getMessage());
        }
        self::assertSame(0, $this->countRows($this->tableNames->works()));
        self::assertSame(0, $this->countRows($this->tableNames->authors()));
        self::assertSame(0, $this->countRows($this->tableNames->bibliographicProviderIdentities()));

        $ids = new Schema1023SequenceIds();
        $racing = new Schema1023FailingAuthorRepository(
            $realAuthors,
            1,
            new AuthorContributorCreditRace()
        );
        $retryingService = $this->genericMaterializer($actor, $clock, $ids, $racing);
        $result = $retryingService->materialize(
            $lookup,
            new MetadataCandidateId($candidate->id()),
            BibliographicMaterializationIntent::WorkOnly
        );

        self::assertSame(2, $ids->workCalls());
        self::assertSame("materialized-work-2", $result->work()->id()->value());
        self::assertSame(1, $this->countRows($this->tableNames->works()));
        self::assertSame(1, $this->countRows($this->tableNames->authors()));
        self::assertSame(1, $this->countRows($this->tableNames->workContributors()));
    }

    public function testTextDiscoveryRetainsBreadthAfterMaterializationAndWishlistAdd(): void
    {
        $actor = new UserId("breadth-actor");
        $clock = new Schema1023FixedClock();
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("harry potter")
        );
        $first = $this->workCandidate(
            $query,
            "/works/HP-ONE",
            "Harry Potter One",
            0
        );
        $second = $this->workCandidate(
            $query,
            "/works/HP-TWO",
            "Harry Potter Two",
            1
        );
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository(
            $this->database,
            $this->tableNames
        );
        $metadata = new WpdbBibliographicMetadataRepository(
            $this->database,
            $this->tableNames
        );
        $localEditions = new LocalEditionResolver(
            new IsbnCanonicalizer(), $claims, $editions, $metadata
        );
        $transactions = new WpdbTransactionManager($this->database);
        $discovery = new BibliographicDiscoveryService(
            new Schema1023Actor($actor),
            new IsbnCanonicalizer(),
            new WpdbBibliographicLocalDiscoveryRepository(
                $this->database,
                $this->tableNames
            ),
            $localEditions,
            $works,
            new FirstSufficientMetadataLookupService(
                new CandidateClassifier(),
                new Schema1023IsbnProvider("isbn-primary"),
                new Schema1023IsbnProvider("isbn-fallback")
            ),
            new Schema1023TextProvider(
                "open_library",
                BibliographicProviderDiscoveryResult::candidates([$first, $second])
            ),
            new Schema1023TextProvider(
                "google_books",
                BibliographicProviderDiscoveryResult::miss()
            ),
            $identities,
            $snapshots,
            new Schema1023LookupIds(),
            $clock,
            $transactions
        );

        $initial = $discovery->discover("harry potter");
        self::assertCount(2, $initial->candidates());
        $discoveryId = $initial->discoveryId()
            ?? throw new \LogicException("Expected external discovery snapshot.");
        $materializer = new BibliographicMaterializationService(
            new Schema1023Actor($actor),
            new Schema1023AllowMaterialization(),
            $snapshots,
            $identities,
            $localEditions,
            $claims,
            $works,
            $editions,
            new WpdbMetadataFieldReviewRepository($this->database, $this->tableNames),
            $this->authorMaterializer($clock),
            new Schema1023Ids(),
            $clock,
            $transactions
        );
        $materialized = $materializer->materialize(
            $discoveryId,
            new MetadataCandidateId($first->id()),
            BibliographicMaterializationIntent::WorkOnly
        );
        self::assertSame(1, $this->database->insert(
            $this->tableNames->wishlistWorkStates(),
            [
                "user_id" => $actor->value(),
                "work_id" => $materialized->work()->id()->value(),
                "target_type" => "work_only",
                "created_at" => "2026-09-10 12:00:00.000000",
                "updated_at" => "2026-09-10 12:00:00.000000",
            ]
        ));
        self::assertSame(1, $this->database->insert(
            $this->tableNames->wishlistEntries(),
            [
                "wishlist_entry_id" => "breadth-wishlist-entry",
                "user_id" => $actor->value(),
                "work_id" => $materialized->work()->id()->value(),
                "target_type" => "work_only",
                "edition_id" => null,
                "created_at" => "2026-09-10 12:00:00.000000",
                "updated_at" => "2026-09-10 12:00:00.000000",
            ]
        ));

        $repeated = $discovery->discover("harry potter");

        self::assertSame([
            BibliographicCandidateType::LocalWork,
            BibliographicCandidateType::ExternalWork,
        ], array_map(static fn ($candidate) => $candidate->type(), $repeated->candidates()));
        self::assertSame(
            $materialized->work()->id()->value(),
            $repeated->candidates()[0]->workId()?->value()
        );
        self::assertSame(
            "/works/HP-TWO",
            $repeated->candidates()[1]->providerRecordId()
        );
        self::assertSame(1, $this->countRows($this->tableNames->wishlistEntries()));
        self::assertSame(0, $this->countRows($this->tableNames->items()));
    }

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
        self::assertSame([
            ["Ursula K. Le Guin"],
            ["Ursula K. Le Guin"],
        ], array_map(static fn ($candidate): array => $candidate->contributors(), $results));

        $this->database->insert($this->tableNames->works(), [
            "work_id" => "bounded-contributors-work",
            "work_title" => "Bounded contributors",
            "work_title_status" => "librarian_confirmed",
        ]);
        for ($position = 1; $position <= 34; $position++) {
            $authorId = sprintf("bounded-author-%02d", $position);
            $this->database->insert($this->tableNames->authors(), [
                "author_id" => $authorId,
                "display_name" => sprintf("Contributor %02d", $position),
            ]);
            $this->database->insert($this->tableNames->workContributors(), [
                "work_id" => "bounded-contributors-work",
                "author_id" => $authorId,
                "contributor_role" => "author",
                "contributor_position" => $position,
            ]);
        }

        $bounded = (new WpdbBibliographicLocalDiscoveryRepository(
            $this->database,
            $this->tableNames
        ))->searchText(BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Bounded contributors")
        ));
        self::assertCount(1, $bounded);
        self::assertCount(32, $bounded[0]->contributors());
        self::assertSame("Contributor 01", $bounded[0]->contributors()[0]);
        self::assertSame("Contributor 32", $bounded[0]->contributors()[31]);
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
        $this->migrateCurrentSchema();
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
            $this->migrateCurrentSchema();
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
            $this->authorMaterializer($clock),
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
            $this->authorMaterializer($clock),
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
            $this->authorMaterializer($clock),
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
                $this->authorMaterializer($clock),
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

    private function workCandidate(
        BibliographicDiscoveryQuery $query,
        string $providerWorkId,
        string $title,
        int $order
    ): BibliographicDiscoveryCandidate {
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "open_library",
            $providerWorkId,
            $providerWorkId,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            $title,
            null,
            null,
            ["Synthetic Author"],
            [],
            [],
            null,
            null,
            null,
            $order
        );
    }

    /** @param list<BibliographicAuthorCredit> $credits */
    private function authorCandidate(
        BibliographicDiscoveryQuery $query,
        string $workKey,
        string $title,
        array $credits
    ): BibliographicDiscoveryCandidate {
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "open_library",
            $workKey,
            $workKey,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            $title,
            null,
            null,
            array_map(
                static fn (BibliographicAuthorCredit $credit): string =>
                    $credit->observedDisplayName(),
                $credits
            ),
            [],
            [],
            null,
            null,
            null,
            0,
            $credits
        );
    }

    private function googleEditionAuthorCandidate(
        BibliographicDiscoveryQuery $query,
        string $recordId,
        string $title,
        string $name
    ): BibliographicDiscoveryCandidate {
        $credit = new BibliographicAuthorCredit(
            $name,
            ContributorRole::Author,
            new ContributorPosition(1),
            AuthorCreditProviderSourceType::Edition,
            $recordId
        );
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "google_books",
            $recordId,
            null,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            $title,
            null,
            null,
            [$name],
            [],
            [],
            null,
            null,
            null,
            0,
            [$credit]
        );
    }

    private function strongAuthor(
        string $name,
        int $position,
        string $sourceRecordId,
        string $authorId,
        ContributorRole $role = ContributorRole::Author
    ): BibliographicAuthorCredit {
        return new BibliographicAuthorCredit(
            $name,
            $role,
            new ContributorPosition($position),
            AuthorCreditProviderSourceType::Work,
            $sourceRecordId,
            new OpenLibraryAuthorId($authorId)
        );
    }

    private function nameOnlyAuthor(
        string $name,
        int $position,
        string $sourceRecordId
    ): BibliographicAuthorCredit {
        return new BibliographicAuthorCredit(
            $name,
            ContributorRole::Author,
            new ContributorPosition($position),
            AuthorCreditProviderSourceType::Work,
            $sourceRecordId
        );
    }

    private function saveAuthorSnapshot(
        MetadataLookupId $id,
        UserId $actor,
        BibliographicDiscoveryQuery $query,
        BibliographicDiscoveryCandidate $candidate,
        MetadataClock $clock
    ): void {
        (new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new BibliographicDiscoverySnapshot(
            $id,
            $actor,
            $query,
            $clock->now(),
            new DateTimeImmutable("2026-09-10T12:30:00+00:00"),
            [$candidate]
        ));
    }

    private function genericMaterializer(
        UserId $actor,
        MetadataClock $clock,
        BibliographicRecordIdGenerator $ids,
        ?WritableAuthorRepository $authors = null
    ): BibliographicMaterializationService {
        $snapshots = new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $identities = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $isbnClaims = new WpdbEditionIdentifierClaimRepository(
            $this->database,
            $this->tableNames
        );
        $authors ??= new WpdbAuthorRepository($this->database, $this->tableNames);
        $authorMaterializer = new CanonicalAuthorMaterializer(
            $authors,
            $identities,
            new WpdbAuthorContributorCreditRepository(
                $this->database,
                $this->tableNames
            ),
            new OpaqueCanonicalAuthorMaterializationIdGenerator(),
            $clock
        );
        return new BibliographicMaterializationService(
            new Schema1023Actor($actor),
            new Schema1023AllowMaterialization(),
            $snapshots,
            $identities,
            new LocalEditionResolver(
                new IsbnCanonicalizer(),
                $isbnClaims,
                $editions,
                new WpdbBibliographicMetadataRepository(
                    $this->database,
                    $this->tableNames
                )
            ),
            $isbnClaims,
            $works,
            $editions,
            new WpdbMetadataFieldReviewRepository(
                $this->database,
                $this->tableNames
            ),
            $authorMaterializer,
            $ids,
            $clock,
            new WpdbTransactionManager($this->database)
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function authorMaterializer(
        MetadataClock $clock
    ): CanonicalAuthorMaterializer {
        return new CanonicalAuthorMaterializer(
            new WpdbAuthorRepository($this->database, $this->tableNames),
            new WpdbBibliographicProviderIdentityRepository(
                $this->database,
                $this->tableNames
            ),
            new WpdbAuthorContributorCreditRepository(
                $this->database,
                $this->tableNames
            ),
            new OpaqueCanonicalAuthorMaterializationIdGenerator(),
            $clock
        );
    }

    private function migrateCurrentSchema(): void
    {
        (new CoreSchemaMigrator(
            $this->database,
            $this->tableNames,
            CoreSchemaMigrationRegistry::production(
                $this->database,
                $this->tableNames
            )->migrations()
        ))->migrate();
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

final class Schema1023SequenceIds implements BibliographicRecordIdGenerator
{
    private int $workCalls = 0;
    private int $editionCalls = 0;

    public function nextWorkId(): WorkId
    {
        return new WorkId("materialized-work-" . ++$this->workCalls);
    }

    public function nextEditionId(): EditionId
    {
        return new EditionId("materialized-edition-" . ++$this->editionCalls);
    }

    public function workCalls(): int { return $this->workCalls; }
}

final class Schema1023FailingAuthorRepository implements WritableAuthorRepository
{
    public function __construct(
        private readonly WritableAuthorRepository $inner,
        private int $failuresRemaining,
        private readonly \Throwable $failure
    ) {}

    public function find(AuthorId $authorId): ?Author
    {
        return $this->inner->find($authorId);
    }

    public function findMany(array $authorIds): array
    {
        return $this->inner->findMany($authorIds);
    }

    public function contributorsForWorks(array $workIds): array
    {
        return $this->inner->contributorsForWorks($workIds);
    }

    public function workIdsForAuthors(array $authorIds): array
    {
        return $this->inner->workIdsForAuthors($authorIds);
    }

    public function add(Author $author): void
    {
        if ($this->failuresRemaining > 0) {
            --$this->failuresRemaining;
            throw $this->failure;
        }
        $this->inner->add($author);
    }

    public function replaceIfVersionMatches(
        Author $replacement,
        AuthorVersion $expectedVersion
    ): bool {
        return $this->inner->replaceIfVersionMatches($replacement, $expectedVersion);
    }

    public function addContributor(WorkContributor $contributor): void
    {
        $this->inner->addContributor($contributor);
    }
}

final class Schema1023LookupIds implements MetadataLookupIdGenerator
{
    private int $next = 1;

    public function next(): MetadataLookupId
    {
        return new MetadataLookupId(sprintf("lookup-%032x", $this->next++));
    }
}

final readonly class Schema1023TextProvider implements BibliographicTextDiscoveryProvider
{
    public function __construct(
        private string $providerKey,
        private BibliographicProviderDiscoveryResult $result
    ) {}

    public function key(): string { return $this->providerKey; }

    public function search(
        BibliographicTextQuery $query,
        BibliographicDiscoveryQuery $identity
    ): BibliographicProviderDiscoveryResult {
        return $this->result;
    }
}

final readonly class Schema1023IsbnProvider implements MetadataProvider
{
    public function __construct(private string $providerKey) {}
    public function key(): string { return $this->providerKey; }
    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        return ProviderLookupResult::miss();
    }
}
