<?php

declare(strict_types=1);

namespace Biblio\Core\Application;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Catalog\AddLibraryItemService;
use Biblio\Core\Application\Catalog\Classification\CreateLibraryCatalogContextService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryBookTypesService;
use Biblio\Core\Application\Catalog\Classification\ManageLibraryGenresService;
use Biblio\Core\Application\Catalog\Classification\ManageLibrarySubjectsService;
use Biblio\Core\Application\Catalog\Classification\SaveLibraryCatalogContextService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Reading\GetOwnedReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromExternalLoanService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;

/**
 * The deliberately small adapter-facing boundary of the production Core.
 *
 * Concrete repositories and lower-level mutation primitives are composition
 * details and are intentionally not exposed here.
 */
final readonly class CoreApplication
{
    public function __construct(
        private EnsurePersonalPrivateLibraryService $personalLibraries,
        private AddLibraryItemService $libraryItemCreation,
        private GetAccessibleLibraryItemService $accessibleLibraryItems,
        private GetOwnedExternalLoanService $ownedExternalLoans,
        private GetOwnedReadingRoundService $ownedReadingRounds,
        private StartReadingFromLibraryItemService $libraryItemReading,
        private StartReadingFromExternalLoanService $externalLoanReading,
        private CreateLibraryCatalogContextService $catalogContextCreation,
        private SaveLibraryCatalogContextService $catalogContextManagement,
        private ManageLibraryBookTypesService $bookTypeManagement,
        private ManageLibraryGenresService $genreManagement,
        private ManageLibrarySubjectsService $subjectManagement
    ) {
    }

    public function libraryItemCreation(): AddLibraryItemService
    {
        return $this->libraryItemCreation;
    }

    public function personalLibraries(): EnsurePersonalPrivateLibraryService
    {
        return $this->personalLibraries;
    }

    public function accessibleLibraryItems(): GetAccessibleLibraryItemService
    {
        return $this->accessibleLibraryItems;
    }

    public function ownedExternalLoans(): GetOwnedExternalLoanService
    {
        return $this->ownedExternalLoans;
    }

    public function ownedReadingRounds(): GetOwnedReadingRoundService
    {
        return $this->ownedReadingRounds;
    }

    public function libraryItemReading(): StartReadingFromLibraryItemService
    {
        return $this->libraryItemReading;
    }

    public function externalLoanReading(): StartReadingFromExternalLoanService
    {
        return $this->externalLoanReading;
    }

    public function catalogContextCreation(): CreateLibraryCatalogContextService
    {
        return $this->catalogContextCreation;
    }

    public function catalogContextManagement(): SaveLibraryCatalogContextService
    {
        return $this->catalogContextManagement;
    }

    public function bookTypeManagement(): ManageLibraryBookTypesService
    {
        return $this->bookTypeManagement;
    }

    public function genreManagement(): ManageLibraryGenresService
    {
        return $this->genreManagement;
    }

    public function subjectManagement(): ManageLibrarySubjectsService
    {
        return $this->subjectManagement;
    }
}
