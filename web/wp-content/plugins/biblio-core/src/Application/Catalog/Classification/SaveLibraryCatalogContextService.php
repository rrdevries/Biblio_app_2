<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextStale;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;

final readonly class SaveLibraryCatalogContextService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $access,
        private WorkRepository $works,
        private WritableLibraryCatalogContextRepository $contexts,
        private LibraryCatalogSelectionResolver $selectionResolver,
        private LibraryCatalogContextActivity $activity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactions
    ) {
    }

    public function save(
        LibraryId $libraryId,
        WorkId $workId,
        LibraryCatalogContextVersion $expectedVersion,
        LibraryCatalogSelection $selection,
        bool $confirmBookTypeChange
    ): LibraryCatalogContext {
        $actorId = $this->authenticatedUser->requireUserId();
        $authorizationContext = new LibraryContext($libraryId, $actorId);

        if (!$this->access->canModifyLibraryCatalogContext(
            $authorizationContext
        )) {
            throw new AuthorizationException(
                "Classification management is not permitted for this Library."
            );
        }

        return $this->transactions->run(function () use (
            $actorId,
            $libraryId,
            $workId,
            $expectedVersion,
            $selection,
            $confirmBookTypeChange
        ): LibraryCatalogContext {
            $current = $this->contexts->findForUpdate($libraryId, $workId);

            if ($current === null) {
                throw new ValidationException(
                    "Library Catalog Context does not exist."
                );
            }

            if ($current->hasSameClassification($selection)) {
                return $current;
            }

            if (!$current->version()->equals($expectedVersion)) {
                throw new LibraryCatalogContextStale($current);
            }

            if (
                !$current->classification()->bookTypeId()
                    ->equals($selection->bookTypeId())
                && !$confirmBookTypeChange
            ) {
                throw new ValidationException(
                    "Changing Library Book Type requires explicit confirmation."
                );
            }

            $oldResolved = $this->selectionResolver->lockAndResolve(
                $libraryId,
                $current->classification()
            );
            $newResolved = $this->selectionResolver->lockAndResolve(
                $libraryId,
                $selection
            );
            $newResolved->assertNewSelectionsAreActive($oldResolved);
            $replacement = $current->reclassify($selection);

            if (!$this->contexts->replaceIfVersionMatches(
                $replacement,
                $expectedVersion
            )) {
                $latest = $this->contexts->findForUpdate($libraryId, $workId);

                if (
                    $latest !== null
                    && $latest->hasSameClassification($selection)
                ) {
                    return $latest;
                }

                throw new LibraryCatalogContextStale($latest ?? $current);
            }

            $work = $this->works->find($workId);

            if ($work === null) {
                throw new ValidationException("Work does not exist.");
            }

            $this->activityEvents->append($this->activity->updated(
                $actorId,
                $libraryId,
                $work,
                $oldResolved,
                $newResolved
            ));

            return $replacement;
        });
    }
}
