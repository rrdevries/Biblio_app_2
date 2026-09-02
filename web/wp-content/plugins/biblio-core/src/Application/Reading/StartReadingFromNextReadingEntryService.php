<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Reading;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\NextReadingEntryId;
use Biblio\Core\Reading\{ReadingDate,ReadingRound};
use DateTimeImmutable;

final readonly class StartReadingFromNextReadingEntryService
{
    public function __construct(
        private StartReadingFromLibraryItemService $items,
        private StartReadingFromExternalLoanService $externalLoans
    ) {
    }

    public function withLibraryItem(
        NextReadingEntryId $entryId,
        LibraryId $libraryId,
        ItemId $itemId,
        ReadingDate|DateTimeImmutable $startedOn
    ): ReadingRound {
        return $this->items->startForNextReadingEntry(
            $entryId,
            $libraryId,
            $itemId,
            $startedOn
        );
    }

    public function withExternalLoan(
        NextReadingEntryId $entryId,
        ExternalLoanId $externalLoanId,
        ReadingDate|DateTimeImmutable $startedOn
    ): ReadingRound {
        return $this->externalLoans->startForNextReadingEntry(
            $entryId,
            $externalLoanId,
            $startedOn
        );
    }
}
