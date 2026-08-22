<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMutationLock;

/**
 * Internal context creation primitive. Authorization, transaction ownership,
 * representation checks and ActivityEvent ordering stay with the use-case.
 */
final readonly class LibraryCatalogContextInitializer
{
    public function __construct(
        private WritableLibraryCatalogContextRepository $contexts,
        private LibraryCatalogSelectionResolver $selectionResolver,
        private LibraryMutationLock $libraryLock
    ) {
    }

    public function initializeOrReuse(
        LibraryId $libraryId,
        WorkId $workId,
        ?LibraryCatalogSelection $selection
    ): LibraryCatalogContextInitializationResult {
        $this->libraryLock->acquire($libraryId);
        $existing = $this->contexts->findForUpdate($libraryId, $workId);

        if ($existing !== null) {
            if (
                $selection === null
                || $existing->hasSameClassification($selection)
            ) {
                return LibraryCatalogContextInitializationResult::reused(
                    $existing
                );
            }

            throw new LibraryCatalogContextAlreadyExists();
        }

        if ($selection === null) {
            throw new ValidationException(
                "Initial classification is required for a new Library "
                . "Catalog Context."
            );
        }

        $resolved = $this->selectionResolver->lockAndResolve(
            $libraryId,
            $selection
        );
        $resolved->assertNewSelectionsAreActive(null);
        $created = LibraryCatalogContext::create(
            $libraryId,
            $workId,
            $selection
        );
        $this->contexts->add($created);

        return LibraryCatalogContextInitializationResult::created(
            $created,
            $resolved
        );
    }
}
