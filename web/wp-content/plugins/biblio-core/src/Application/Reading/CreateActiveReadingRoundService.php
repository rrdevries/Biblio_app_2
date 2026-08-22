<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundIdGenerator;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\ReadingSourceUnavailable;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use DateTimeImmutable;

final readonly class CreateActiveReadingRoundService
{
    private ReadingRoundCreation $creation;

    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        WritableReadingRoundRepository $readingRoundRepository,
        ReadingRoundIdGenerator $ids,
        private ReadingRoundClock $clock
    ) {
        $this->creation = new ReadingRoundCreation(
            $ids,
            $readingRoundRepository
        );
    }

    public function createFromLibraryItem(
        Item $item,
        Edition $edition,
        ReadingDate|DateTimeImmutable $startedOn
    ): ReadingRound {
        if (!$item->editionId()->equals($edition->id())) {
            throw new ReadingSourceUnavailable();
        }

        return $this->create(
            $edition->workId(),
            ReadingSource::libraryItem($item->id()),
            $this->normalizeDate($startedOn),
            $this->authenticatedUser->requireUserId()
        );
    }

    public function createFromExternalLoan(
        ExternalLoan $externalLoan,
        ReadingDate|DateTimeImmutable $startedOn
    ): ReadingRound {
        $authenticatedUserId = $this->authenticatedUser->requireUserId();

        if (!$authenticatedUserId->equals($externalLoan->userId())) {
            throw new ReadingSourceUnavailable();
        }

        return $this->create(
            $externalLoan->workId(),
            ReadingSource::externalLoan($externalLoan->id()),
            $this->normalizeDate($startedOn),
            $authenticatedUserId
        );
    }

    private function create(
        WorkId $sourceWorkId,
        ReadingSource $source,
        ReadingDate $startedOn,
        UserId $authenticatedUserId
    ): ReadingRound {
        return $this->creation->create(
            $authenticatedUserId,
            fn (ReadingRoundId $id): ReadingRound => ReadingRound::active(
                $id,
                $authenticatedUserId,
                $sourceWorkId,
                $source,
                $startedOn,
                $this->clock->now()
            )
        );
    }

    private function normalizeDate(
        ReadingDate|DateTimeImmutable $date
    ): ReadingDate {
        return $date instanceof ReadingDate
            ? $date
            : ReadingDate::exact(
                (int) $date->format("Y"),
                (int) $date->format("n"),
                (int) $date->format("j")
            );
    }
}
