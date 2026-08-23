<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Catalog\Read\CatalogItemCapabilities;
use Biblio\Core\Application\Catalog\Read\CatalogItemCardView;
use Biblio\Core\Application\Catalog\Read\CatalogItemDetailView;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewView;
use Biblio\Core\Application\Catalog\Read\CatalogReadingSummary;
use Biblio\Core\Application\Catalog\Read\CatalogTextListValue;
use Biblio\Core\Application\Catalog\Read\CatalogTextValue;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;

final readonly class RestResponseSerializer
{
    public function __construct(private CatalogCursorCodec $cursors)
    {
    }

    /**
     * @param list<LibraryContextView> $libraries
     * @return array{libraries: list<array<string, mixed>>}
     */
    public function libraries(array $libraries): array
    {
        return [
            "libraries" => array_map($this->library(...), $libraries),
        ];
    }

    /** @return array<string, mixed> */
    public function overview(CatalogOverviewView $overview): array
    {
        return [
            "library" => $this->library($overview->library()),
            "items" => array_map($this->card(...), $overview->items()),
            "next_cursor" => $overview->nextCursor() === null
                ? null
                : $this->cursors->encode($overview->nextCursor()),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(CatalogItemDetailView $detail): array
    {
        return [
            "library" => $this->library($detail->library()),
            "item_id" => $detail->itemId()->value(),
            "work_id" => $detail->workId()->value(),
            "edition_id" => $detail->editionId()->value(),
            "title" => $detail->title(),
            "authors" => $this->textList($detail->authors()),
            "cover_reference" => $this->text($detail->coverReference()),
            "isbn" => $this->text($detail->isbn()),
            "language" => $this->text($detail->language()),
            "publisher" => $this->text($detail->publisher()),
            "publication_date" => $this->text($detail->publicationDate()),
            "series" => $this->text($detail->series()),
            "form" => $this->text($detail->form()),
            "location" => $this->text($detail->location()),
            "condition" => $this->text($detail->condition()),
            "acquisition" => $this->text($detail->acquisition()),
            "availability" => $this->text($detail->availability()),
            "item_status" => $detail->itemStatus()->value,
            "reading" => $this->readingSummary($detail->reading()),
            "capabilities" => $this->itemCapabilities($detail->capabilities()),
        ];
    }

    /** @return array<string, mixed> */
    public function startedReadingRound(ReadingRound $round): array
    {
        $source = $round->source();

        return [
            "reading_round_id" => $round->id()->value(),
            "work_id" => $round->workId()->value(),
            "source" => [
                "type" => "library_item",
                "item_id" => $source?->itemId()?->value(),
            ],
            "lifecycle" => $round->lifecycle()->value,
            "started_on" => $this->readingDate($round->period()->startedOn()),
            "version" => $round->version()->value(),
        ];
    }

    /** @return array<string, mixed> */
    private function library(LibraryContextView $library): array
    {
        $capabilities = $library->capabilities();

        return [
            "library_id" => $library->libraryId()->value(),
            "name" => $library->name()->value(),
            "type" => $library->type()->value,
            "status" => $library->status()->value,
            "designated_personal" => $library->isDesignatedPersonal(),
            "capabilities" => [
                "view_collection" => $capabilities->canViewCollection(),
                "add_catalog_item" => $capabilities->canAddCatalogItem(),
                "modify_catalog_context" => $capabilities->canModifyCatalogContext(),
                "manage_classification_terms" =>
                    $capabilities->canManageClassificationTerms(),
                "publish_contribution" => $capabilities->canPublishContribution(),
                "moderate_contribution" => $capabilities->canModerateContribution(),
                "use_item_directly" => $capabilities->canUseItemDirectly(),
                "receive_internal_loan" => $capabilities->canReceiveInternalLoan(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function card(CatalogItemCardView $item): array
    {
        return [
            "item_id" => $item->itemId()->value(),
            "work_id" => $item->workId()->value(),
            "edition_id" => $item->editionId()->value(),
            "title" => $item->title(),
            "authors" => $this->textList($item->authors()),
            "cover_reference" => $this->text($item->coverReference()),
            "form" => $this->text($item->form()),
            "location_or_source" => $this->text($item->locationOrSource()),
            "reading_status" => $item->readingStatus()->value,
            "item_status" => $item->itemStatus()->value,
            "capabilities" => $this->itemCapabilities($item->capabilities()),
        ];
    }

    /** @return array{state: string, value: ?string} */
    private function text(CatalogTextValue $text): array
    {
        return [
            "state" => $text->state()->value,
            "value" => $text->value(),
        ];
    }

    /** @return array{state: string, values: list<string>} */
    private function textList(CatalogTextListValue $text): array
    {
        return [
            "state" => $text->state()->value,
            "values" => $text->values(),
        ];
    }

    /** @return array{view_item: bool, start_reading: bool} */
    private function itemCapabilities(CatalogItemCapabilities $capabilities): array
    {
        return [
            "view_item" => $capabilities->canViewItem(),
            "start_reading" => $capabilities->canStartReading(),
        ];
    }

    /** @return array<string, int|string> */
    private function readingSummary(CatalogReadingSummary $reading): array
    {
        return [
            "status" => $reading->status()->value,
            "active_rounds" => $reading->activeRounds(),
            "completed_rounds" => $reading->completedRounds(),
            "stopped_rounds" => $reading->stoppedRounds(),
            "historical_completed_rounds" => $reading->historicalCompletedRounds(),
        ];
    }

    /** @return null|array{year: int, month: ?int, day: ?int} */
    private function readingDate(?ReadingDate $date): ?array
    {
        return $date === null ? null : [
            "year" => $date->yearValue(),
            "month" => $date->monthValue(),
            "day" => $date->dayValue(),
        ];
    }
}
