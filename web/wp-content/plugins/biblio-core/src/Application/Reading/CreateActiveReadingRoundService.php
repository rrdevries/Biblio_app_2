<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundRepository;
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;

final readonly class CreateActiveReadingRoundService
{
    public function __construct(
        private ReadingRoundRepository $readingRoundRepository
    ) {
    }

    public function create(
        UserId $authenticatedUserId,
        WorkId $sourceWorkId,
        ReadingSource $source,
        DateTimeImmutable $startedAt
    ): ReadingRound {
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
