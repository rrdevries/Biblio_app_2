<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextAlreadyExists;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\WritableLibraryCatalogContextRepository;
use Biblio\Core\Catalog\LibraryWorkRepresentationRepository;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMutationLock;

final readonly class CreateLibraryCatalogContextService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryAccessService $access,
        private LibraryWorkRepresentationRepository $representedWorks,
        private WritableLibraryCatalogContextRepository $contexts,
        private LibraryCatalogSelectionResolver $selectionResolver,
        private LibraryMutationLock $libraryLock,
        private LibraryCatalogContextActivity $activity,
        private ActivityEventAppender $activityEvents,
        private TransactionManager $transactions
    ) {
    }

    public function createForRepresentedWork(
        LibraryId $libraryId,
        WorkId $workId,
        LibraryCatalogSelection $selection
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
            $selection
        ): LibraryCatalogContext {
            $this->libraryLock->acquire($libraryId);
            $work = $this->representedWorks->findRepresentedWork(
                $libraryId,
                $workId
            );

            if ($work === null) {
                throw new ValidationException(
                    "Work is not represented by an Item in this Library."
                );
            }

            $existing = $this->contexts->findForUpdate($libraryId, $workId);

            if ($existing !== null) {
                if ($existing->hasSameClassification($selection)) {
                    return $existing;
                }

                throw new LibraryCatalogContextAlreadyExists();
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
            $this->activityEvents->append($this->activity->created(
                $actorId,
                $libraryId,
                $work,
                $resolved
            ));

            return $created;
        });
    }
}
