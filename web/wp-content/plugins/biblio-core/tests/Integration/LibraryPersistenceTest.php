<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;

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
    }
}
