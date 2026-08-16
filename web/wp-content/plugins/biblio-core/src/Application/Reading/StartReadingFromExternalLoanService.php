<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingSource;
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
        UserId $authenticatedUserId,
        ExternalLoanId $externalLoanId,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $externalLoan = $this->getOwnedExternalLoan->get(
            $authenticatedUserId,
            $externalLoanId
        );

        if (
            $externalLoan === null
            || $externalLoan->status() !== ExternalLoanStatus::Active
        ) {
            throw new ReadingSourceUnavailable();
        }

        return $this->createReadingRound->create(
            $authenticatedUserId,
            $externalLoan->workId(),
            ReadingSource::externalLoan($externalLoan->id()),
            $startedAt
        );
    }
}
