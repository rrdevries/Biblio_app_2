<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\NextReading\{NextReadingEntry,NextReadingList,PreferredReadingSourceType};

final readonly class NextReadingProjector
{
    public function __construct(
        private WorkRepository $works,
        private GetAccessibleLibraryItemService $items,
        private GetOwnedExternalLoanService $loans
    ) {
    }

    public function project(NextReadingList $list): NextReadingListView
    {
        return new NextReadingListView(
            $list->version(),
            array_map($this->entry(...), $list->entries())
        );
    }

    private function entry(NextReadingEntry $entry): NextReadingEntryView
    {
        $work = $this->works->find($entry->workId());
        if ($work === null) {
            throw new PersistenceException("Stored Next Reading Work is unavailable.");
        }
        return new NextReadingEntryView(
            $entry->id(),
            $work->id()->value(),
            $work->title(),
            $this->preferredSource($entry),
            $entry->position()->value(),
            $entry->createdAt()
        );
    }

    private function preferredSource(NextReadingEntry $entry): PreferredReadingSourceView
    {
        $source = $entry->preferredSource();
        if ($source === null) {
            return new PreferredReadingSourceView(
                null,
                PreferredReadingSourceState::None,
                "Geen voorkeursbron"
            );
        }
        if ($source->type() === PreferredReadingSourceType::LibraryItem) {
            if ($source->liveItemId() === null) {
                return $this->unavailable($source->type());
            }
            $accessible = $this->items->get(
                $source->libraryIdSnapshot(),
                $source->liveItemId()
            );
            if ($accessible === null || !$accessible->canUseAsDirectSource()) {
                return $this->unavailable($source->type());
            }
            return new PreferredReadingSourceView(
                $source->type(),
                PreferredReadingSourceState::Available,
                "Bibliotheekexemplaar"
            );
        }
        if ($source->liveExternalLoanId() === null) {
            return $this->unavailable($source->type());
        }
        $loan = $this->loans->get($source->liveExternalLoanId());
        if ($loan === null) {
            return $this->unavailable($source->type());
        }
        return new PreferredReadingSourceView(
            $source->type(),
            PreferredReadingSourceState::Available,
            "Externe lening"
        );
    }

    private function unavailable(
        PreferredReadingSourceType $type
    ): PreferredReadingSourceView {
        return new PreferredReadingSourceView(
            $type,
            PreferredReadingSourceState::Unavailable,
            "Voorkeursbron niet beschikbaar"
        );
    }
}
