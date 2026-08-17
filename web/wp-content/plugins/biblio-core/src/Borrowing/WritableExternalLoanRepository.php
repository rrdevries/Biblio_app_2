<?php

declare(strict_types=1);

namespace Biblio\Core\Borrowing;

interface WritableExternalLoanRepository
{
    public function add(ExternalLoan $externalLoan): void;
}
