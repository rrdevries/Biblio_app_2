<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Borrowing;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanRepository;

final readonly class GetOwnedExternalLoanService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private ExternalLoanRepository $externalLoanRepository
    ) {
    }

    public function get(ExternalLoanId $externalLoanId): ?ExternalLoan
    {
        $authenticatedUserId = $this->authenticatedUser->requireUserId();
        $externalLoan = $this->externalLoanRepository->findForUser(
            $externalLoanId,
            $authenticatedUserId
        );

        if (
            $externalLoan === null
            || !$authenticatedUserId->equals($externalLoan->userId())
        ) {
            return null;
        }

        return $externalLoan;
    }
}
