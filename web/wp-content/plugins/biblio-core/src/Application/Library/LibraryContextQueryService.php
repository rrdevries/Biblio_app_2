<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryId;

final readonly class LibraryContextQueryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ActorLibraryContextRepository $repository,
        private LibraryAuthorizationPolicy $authorizationPolicy
    ) {
    }

    /** @return list<LibraryContextView> */
    public function myLibraries(): array
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $views = [];

        foreach ($this->repository->listForActor($actorId) as $record) {
            $view = $this->projectAvailable($record, $actorId);

            if ($view !== null) {
                $views[] = $view;
            }
        }

        return $views;
    }

    public function get(LibraryId $libraryId): LibraryContextView
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $record = $this->repository->findForActor($libraryId, $actorId);
        $view = $record === null
            ? null
            : $this->projectAvailable($record, $actorId, $libraryId);

        if ($view === null) {
            throw new AuthorizationException(
                "Library context is not available to the authenticated user."
            );
        }

        return $view;
    }

    private function projectAvailable(
        ActorLibraryContext $record,
        UserId $actorId,
        ?LibraryId $expectedLibraryId = null
    ): ?LibraryContextView {
        $library = $record->library();
        $membership = $record->membership();

        if (
            !$membership->userId()->equals($actorId)
            || (
                $expectedLibraryId !== null
                && !$library->id()->equals($expectedLibraryId)
            )
        ) {
            return null;
        }

        $context = new LibraryContext(
            $library->id(),
            $actorId
        );

        if (
            !$this->authorizationPolicy->canViewCollection(
                $context,
                $membership
            )
        ) {
            return null;
        }

        return new LibraryContextView(
            $library->id(),
            $library->name(),
            $library->type(),
            $library->status(),
            $record->isDesignatedPersonal(),
            new LibraryCapabilities(
                true,
                $this->authorizationPolicy->canAddCatalogItem(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canModifyLibraryCatalogContext(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canManageClassificationTerms(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canPublishContribution(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canModerateContribution(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canUseItemDirectly(
                    $context,
                    $membership
                ),
                $this->authorizationPolicy->canReceiveInternalLoan(
                    $context,
                    $membership
                )
            )
        );
    }
}
