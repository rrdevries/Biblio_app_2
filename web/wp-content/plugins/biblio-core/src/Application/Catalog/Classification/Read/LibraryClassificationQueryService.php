<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification\Read;

use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\Classification\{LibraryBookType,LibraryCatalogSelection,LibraryClassificationReadRepository,LibraryGenre,LibrarySubject};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryClassificationQueryService
{
    public const MAXIMUM_BATCH_SIZE = 100;

    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private LibraryClassificationReadRepository $classifications
    ) {
    }

    /** @return list<LibraryBookType> */
    public function activeBookTypes(LibraryId $libraryId): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->classifications->activeBookTypes($libraryId);
    }

    /** @return list<LibraryGenre> */
    public function activeGenres(LibraryId $libraryId): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->classifications->activeGenres($libraryId);
    }

    /** @return list<LibrarySubject> */
    public function activeSubjects(LibraryId $libraryId): array
    {
        $this->libraryContexts->get($libraryId);
        return $this->classifications->activeSubjects($libraryId);
    }

    /**
     * @param list<WorkId> $workIds
     * @return array<string, LibraryCatalogSelection|null>
     */
    public function classificationsForWorks(LibraryId $libraryId, array $workIds): array
    {
        $this->libraryContexts->get($libraryId);
        $this->assertWorkBatch($workIds);
        return $this->classifications->classificationsForWorks($libraryId, $workIds);
    }

    /** @param array<mixed> $workIds */
    private function assertWorkBatch(array $workIds): void
    {
        if (count($workIds) > self::MAXIMUM_BATCH_SIZE) {
            throw new ValidationException('Classification read batches may contain at most 100 Works.');
        }
        foreach ($workIds as $workId) {
            if (!$workId instanceof WorkId) {
                throw new ValidationException('Classification read batches must contain only Work IDs.');
            }
        }
    }
}
