<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\NextReading\{NextReadingEntry,NextReadingList,NextReadingTargetType};

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
        $target = $entry->target();
        $work = $this->works->find($target->workId());
        if ($work === null) {
            throw new PersistenceException("Stored Next Reading Work is unavailable.");
        }
        $sourceId = $target->itemIdSnapshot()?->value()
            ?? $target->externalLoanIdSnapshot()?->value();

        return new NextReadingEntryView(
            $entry->id(),
            $work->id()->value(),
            $work->title(),
            $target->type(),
            $sourceId,
            $target->libraryIdSnapshot()?->value(),
            $this->status($entry),
            $entry->position()->value(),
            $entry->createdAt()
        );
    }

    private function status(NextReadingEntry $entry): NextReadingSourceStatus
    {
        $target = $entry->target();
        if ($target->type() === NextReadingTargetType::Work) {
            return NextReadingSourceStatus::Live;
        }
        if ($target->type() === NextReadingTargetType::LibraryItem) {
            if ($target->liveItemId() === null) {
                return NextReadingSourceStatus::Missing;
            }
            $accessible = $this->items->get(
                $target->libraryIdSnapshot(),
                $target->liveItemId()
            );
            if ($accessible === null) {
                return NextReadingSourceStatus::Inaccessible;
            }
            return $accessible->canUseAsDirectSource()
                ? NextReadingSourceStatus::Live
                : NextReadingSourceStatus::Unavailable;
        }
        if ($target->liveExternalLoanId() === null) {
            return NextReadingSourceStatus::Missing;
        }
        $loan = $this->loans->get($target->liveExternalLoanId());
        if ($loan === null) {
            return NextReadingSourceStatus::Missing;
        }
        return NextReadingSourceStatus::Live;
    }
}
