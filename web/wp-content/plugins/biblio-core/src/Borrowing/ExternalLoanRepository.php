<?php

declare(strict_types=1);

namespace Biblio\Core\Borrowing;

use Biblio\Core\Identity\UserId;

interface ExternalLoanRepository
{
    public function add(ExternalLoan $externalLoan): void;

    public function findForUser(
        ExternalLoanId $externalLoanId,
        UserId $userId
    ): ?ExternalLoan;
}
