<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use DateTimeImmutable;

final readonly class CreateActiveReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $readingRoundRepository
    ) {
    }

    public function create(
        WorkId $sourceWorkId,
        ReadingSource $source,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $authenticatedUserId = $this->authenticatedUser->requireUserId();
        $readingRound = ReadingRound::active(
            new ReadingRoundId("reading-round-" . bin2hex(random_bytes(16))),
            $authenticatedUserId,
            $sourceWorkId,
            $source,
            $startedAt
        );

        $this->readingRoundRepository->addForUser(
            $authenticatedUserId,
            $readingRound
        );

        return $readingRound;
    }
}
