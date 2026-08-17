<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use DateTimeImmutable;

final readonly class StartReadingFromExternalLoanService
{
    public function __construct(
        private GetOwnedExternalLoanService $getOwnedExternalLoan,
        private CreateActiveReadingRoundService $createReadingRound
    ) {
    }

    public function start(
        ExternalLoanId $externalLoanId,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $externalLoan = $this->getOwnedExternalLoan->get(
            $externalLoanId
        );

        if (
            $externalLoan === null
            || $externalLoan->status() !== ExternalLoanStatus::Active
        ) {
            throw new ReadingSourceUnavailable();
        }

        return $this->createReadingRound->createFromExternalLoan(
            $externalLoan,
            $startedAt
        );
    }
}
