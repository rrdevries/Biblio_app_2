<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\NextReading\{NextReadingList,NextReadingTarget,NextReadingTargetUnavailable};

final readonly class AddExternalLoanToNextReadingService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private GetOwnedExternalLoanService $loans,
        private NextReadingMutation $mutation
    ) {
    }

    public function add(ExternalLoanId $externalLoanId): NextReadingList
    {
        $actorId = $this->authenticatedUser->requireUserId();
        $loan = $this->loans->get($externalLoanId);
        if ($loan === null) {
            throw new NextReadingTargetUnavailable();
        }
        return $this->mutation->append(
            $actorId,
            NextReadingTarget::forExternalLoan($loan->workId(), $loan->id())
        );
    }
}
