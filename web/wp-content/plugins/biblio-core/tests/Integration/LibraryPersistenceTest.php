<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Catalog\Classification\ClassificationSeedEvolution;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use RuntimeException;

final readonly class FailingLibraryCreationSeedEvolution implements
    ClassificationSeedEvolution
{
    public function __construct(private ClassificationSeedEvolution $inner)
    {
    }

    public function evolve(LibraryId $libraryId): void
    {
        $this->inner->evolve($libraryId);

        throw new RuntimeException("Forced Library seed-bootstrap failure.");
    }

    public function isConverged(LibraryId $libraryId): bool
    {
        return $this->inner->isConverged($libraryId);
    }

    public function ambiguities(LibraryId $libraryId): array
    {
        return $this->inner->ambiguities($libraryId);
    }
}

final class LibraryPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testLibraryCanBeStoredAndReadBack(): void
    {
        $repository = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
        $library = Library::privateLibrary(new LibraryId("library-a"));

        $repository->add($library);
        $stored = $repository->find($library->id());

        self::assertNotNull($stored);
        self::assertSame("library-a", $stored->id()->value());
        self::assertSame(LibraryType::PrivateLibrary, $stored->type());
        self::assertSame(LibraryStatus::Active, $stored->status());
    }

    public function testUnknownLibraryReturnsNoEntity(): void
    {
        $repository = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );

        self::assertNull($repository->find(new LibraryId("missing-library")));
    }

    public function testLibraryAndInitialOwnerAreCreatedAtomically(): void
    {
        $libraryRepository = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
        $membershipRepository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $service = new CreateLibraryService(
            $libraryRepository,
            $membershipRepository,
            $this->classificationSeedEvolution(),
            new WpdbTransactionManager($this->database)
        );
        $library = Library::privateLibrary(new LibraryId("library-a"));
        $ownerId = new UserId("owner-a");

        $service->create($library, $ownerId);

        self::assertNotNull($libraryRepository->find($library->id()));
        self::assertNotNull($membershipRepository->findFor(
            $library->id(),
            $ownerId
        ));
        self::assertSame(9, $this->libraryTableCount(
            $this->tableNames->libraryBookTypes(),
            $library->id()
        ));
        self::assertSame(12, $this->libraryTableCount(
            $this->tableNames->libraryGenres(),
            $library->id()
        ));
        self::assertSame(0, $this->libraryTableCount(
            $this->tableNames->librarySubjects(),
            $library->id()
        ));
        self::assertSame(0, $this->libraryTableCount(
            $this->tableNames->libraryActivityEvents(),
            $library->id()
        ));
    }

    public function testSeedFailureRollsBackLibraryOwnerAndPartialSeeds(): void
    {
        $libraryRepository = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
        $membershipRepository = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $service = new CreateLibraryService(
            $libraryRepository,
            $membershipRepository,
            new FailingLibraryCreationSeedEvolution(
                $this->classificationSeedEvolution()
            ),
            new WpdbTransactionManager($this->database)
        );
        $library = Library::privateLibrary(new LibraryId("library-failure"));
        $ownerId = new UserId("owner-failure");

        try {
            $service->create($library, $ownerId);
            self::fail("Seed-bootstrap failure did not fail Library creation.");
        } catch (RuntimeException $exception) {
            self::assertSame(
                "Forced Library seed-bootstrap failure.",
                $exception->getMessage()
            );
        }

        self::assertNull($libraryRepository->find($library->id()));
        self::assertNull($membershipRepository->findFor(
            $library->id(),
            $ownerId
        ));
        self::assertSame(0, $this->libraryTableCount(
            $this->tableNames->libraryBookTypes(),
            $library->id()
        ));
        self::assertSame(0, $this->libraryTableCount(
            $this->tableNames->libraryGenres(),
            $library->id()
        ));
    }

    private function libraryTableCount(
        string $table,
        LibraryId $libraryId
    ): int {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE library_id = %s",
            $libraryId->value()
        ));
    }
}
