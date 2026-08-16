<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Borrowing;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanRepository;
use Biblio\Core\Identity\UserId;

final readonly class GetOwnedExternalLoanService
{
    public function __construct(
        private ExternalLoanRepository $externalLoanRepository
    ) {
    }

    public function get(
        UserId $authenticatedUserId,
        ExternalLoanId $externalLoanId
    ): ?ExternalLoan {
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
