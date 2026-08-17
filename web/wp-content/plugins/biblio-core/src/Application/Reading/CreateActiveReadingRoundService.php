<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use DateTimeImmutable;

final readonly class CreateActiveReadingRoundService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private WritableReadingRoundRepository $readingRoundRepository
    ) {
    }

    public function createFromLibraryItem(
        Item $item,
        Edition $edition,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        if (!$item->editionId()->equals($edition->id())) {
            throw new ReadingSourceUnavailable();
        }

        return $this->create(
            $edition->workId(),
            ReadingSource::libraryItem($item->id()),
            $startedAt,
            $this->authenticatedUser->requireUserId()
        );
    }

    public function createFromExternalLoan(
        ExternalLoan $externalLoan,
        DateTimeImmutable $startedAt
    ): ReadingRound {
        $authenticatedUserId = $this->authenticatedUser->requireUserId();

        if (!$authenticatedUserId->equals($externalLoan->userId())) {
            throw new ReadingSourceUnavailable();
        }

        return $this->create(
            $externalLoan->workId(),
            ReadingSource::externalLoan($externalLoan->id()),
            $startedAt,
            $authenticatedUserId
        );
    }

    private function create(
        WorkId $sourceWorkId,
        ReadingSource $source,
        DateTimeImmutable $startedAt,
        UserId $authenticatedUserId
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
