<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Library\LibraryContext;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryMembershipRepository;

final readonly class LibraryAccessService
{
    public function __construct(
        private LibraryMembershipRepository $repository,
        private LibraryAuthorizationPolicy $authorizationPolicy
    ) {
    }

    public function canAddCatalogItem(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canAddCatalogItem(
            $context,
            $assignment
        );
    }

    public function canInitializeCatalogContextDuringItemAdd(
        LibraryContext $context
    ): bool {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy
            ->canInitializeCatalogContextDuringItemAdd(
                $context,
                $assignment
            );
    }

    public function canModifyLibraryCatalogContext(
        LibraryContext $context
    ): bool {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canModifyLibraryCatalogContext(
            $context,
            $assignment
        );
    }

    public function canManageClassificationTerms(
        LibraryContext $context
    ): bool {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canManageClassificationTerms(
            $context,
            $assignment
        );
    }

    public function canViewCollection(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canViewCollection(
            $context,
            $assignment
        );
    }

    public function canPublishContribution(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);
        return $assignment !== null
            && $this->authorizationPolicy->canPublishContribution($context, $assignment);
    }

    public function canModerateContribution(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);
        return $assignment !== null
            && $this->authorizationPolicy->canModerateContribution($context, $assignment);
    }

    public function canUseItemDirectly(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canUseItemDirectly(
            $context,
            $assignment
        );
    }

    public function canReceiveInternalLoan(LibraryContext $context): bool
    {
        $assignment = $this->findMembership($context);

        if ($assignment === null) {
            return false;
        }

        return $this->authorizationPolicy->canReceiveInternalLoan(
            $context,
            $assignment
        );
    }

    private function findMembership(
        LibraryContext $context
    ): ?LibraryMembershipAssignment {
        return $this->repository->findFor(
            $context->libraryId(),
            $context->userId()
        );
    }
}
