<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Assessments\Read\{PublicAssessmentKind,PublicAssessmentPage,PublicAssessmentView};
use Biblio\Core\Application\Catalog\Read\CatalogActiveReadingRoundView;
use Biblio\Core\Application\Catalog\Read\CatalogItemCapabilities;
use Biblio\Core\Application\Catalog\Read\CatalogItemCardView;
use Biblio\Core\Application\Catalog\Read\CatalogItemDetailView;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewView;
use Biblio\Core\Application\Catalog\Read\CatalogReadingSummary;
use Biblio\Core\Application\Catalog\Read\CatalogTextListValue;
use Biblio\Core\Application\Catalog\Read\CatalogTextValue;
use Biblio\Core\Application\Catalog\Query\{CatalogQueryItem,CatalogQueryPage};
use Biblio\Core\Application\Catalog\Query\CatalogQuerySeriesContext;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Application\Notes\Read\PrivateNoteView;
use Biblio\Core\Application\Notes\Read\PrivateNoteViewPage;
use Biblio\Core\Application\NextReading\{NextReadingEntryView,NextReadingListView,NextReadingRemoval,PreferredReadingSourceState,PreferredReadingSourceView};
use Biblio\Core\Application\NextReading\Read\{NextReadingSourceOptionView,NextReadingWorkPage,NextReadingWorkView};
use Biblio\Core\Application\Reading\History\ReadingHistoryEntry;
use Biblio\Core\Application\Reading\History\ReadingHistoryPage;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Author;
use Biblio\Core\Catalog\Classification\{LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;
use LogicException;
use DateTimeZone;

final readonly class RestResponseSerializer
{
    public function __construct(
        private CatalogCursorCodec $cursors,
        private ReadingHistoryCursorCodec $historyCursors,
        private PrivateNoteCursorCodec $privateNoteCursors,
        private ?NextReadingWorkCursorCodec $nextReadingWorkCursors = null,
        private ?PublicAssessmentCursorCodec $publicAssessmentCursors = null
    ) {
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
    public function catalogQuery(CatalogQueryPage $page): array
    {
        return [
            "library" => $this->library($page->library()),
            "items" => array_map($this->catalogQueryItem(...), $page->items()),
            "next_cursor" => $page->nextCursor()?->opaqueValue(),
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
            "active_reading_round" => $this->activeReadingRound(
                $detail->activeReadingRound()
            ),
            "capabilities" => $this->detailCapabilities($detail->capabilities()),
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
    public function endedReadingRound(ReadingRound $round): array
    {
        $outcome = $round->outcome();
        $finishedOn = $round->period()->finishedOn();

        if ($outcome === null || $finishedOn === null) {
            throw new LogicException("An ended Reading Round response was expected.");
        }

        return [
            "reading_round_id" => $round->id()->value(),
            "lifecycle" => $round->lifecycle()->value,
            "outcome" => $outcome->value,
            "finished_on" => $this->readingDate($finishedOn),
            "version" => $round->version()->value(),
        ];
    }

    /** @return array<string, mixed> */
    public function readingHistory(ReadingHistoryPage $page): array
    {
        return [
            "items" => array_map(
                $this->readingHistoryEntry(...),
                $page->entries()
            ),
            "next_cursor" => $page->nextCursor() === null
                ? null
                : $this->historyCursors->encode($page->nextCursor()),
        ];
    }

    /** @return array<string, mixed> */
    public function publicAssessments(
        LibraryId $libraryId,
        WorkId $workId,
        PublicAssessmentPage $page
    ): array {
        $aggregate = $page->aggregate();

        return [
            "library_id" => $libraryId->value(),
            "work_id" => $workId->value(),
            "contributions" => array_map(
                $this->publicAssessment(...),
                $page->contributions()
            ),
            "aggregate" => [
                "average" => $aggregate->average(),
                "voter_count" => $aggregate->uniqueUsers(),
            ],
            "next_cursor" => $page->nextCursor() === null
                ? null
                : $this->publicAssessmentCursorCodec()->encode(
                    $page->nextCursor()
                ),
        ];
    }

    /** @return array{items: list<array<string, int|string>>, next_cursor: ?string} */
    public function privateNotes(PrivateNoteViewPage $page): array
    {
        return [
            "items" => array_map($this->privateNote(...), $page->notes()),
            "next_cursor" => $page->nextCursor() === null
                ? null
                : $this->privateNoteCursors->encode($page->nextCursor()),
        ];
    }

    /** @return array{private_note_id: string, content_html: string, version: int} */
    public function privateNote(PrivateNoteView $note): array
    {
        return [
            "private_note_id" => $note->id()->value(),
            "content_html" => $note->contentHtml(),
            "version" => $note->version()->value(),
        ];
    }

    /** @return array{list_version: int, entries: list<array<string, mixed>>} */
    public function nextReadingList(NextReadingListView $list): array
    {
        return [
            "list_version" => $list->version()->value(),
            "entries" => array_map($this->nextReadingEntry(...), $list->entries()),
        ];
    }

    /** @return array{list: array{list_version: int, entries: list<array<string, mixed>>}, undo: array{token: string, expires_at: string}} */
    public function nextReadingRemoval(
        NextReadingListView $list,
        NextReadingRemoval $removal
    ): array {
        return [
            "list" => $this->nextReadingList($list),
            "undo" => [
                "token" => $removal->undoToken()->value(),
                "expires_at" => $removal->undoExpiresAt()->format("Y-m-d\\TH:i:s.u\\Z"),
            ],
        ];
    }

    /** @return array{items: list<array{work_id: string, title: string}>, next_cursor: ?string} */
    public function nextReadingWorks(NextReadingWorkPage $page): array
    {
        return [
            "items" => array_map(
                static fn (NextReadingWorkView $work): array => [
                    "work_id" => $work->workId()->value(),
                    "title" => $work->title(),
                ],
                $page->works()
            ),
            "next_cursor" => $page->nextCursor() === null
                ? null
                : $this->nextReadingWorkCursorCodec()->encode(
                    $page->nextCursor()
                ),
        ];
    }

    /** @param list<NextReadingSourceOptionView> $options
     * @return array{items: list<array<string, mixed>>}
     */
    public function nextReadingSourceOptions(array $options): array
    {
        return [
            "items" => array_map($this->nextReadingSourceOption(...), $options),
        ];
    }

    /** @return array<string, mixed> */
    private function readingHistoryEntry(ReadingHistoryEntry $entry): array
    {
        return [
            "outcome" => $entry->outcome()->value,
            "started_on" => $this->readingDate($entry->startedOn()),
            "finished_on" => $this->readingDate($entry->finishedOn()),
            "source_type" => $entry->sourceType()->value,
            "historical_registration" => $entry->historicalRegistration(),
        ];
    }

    /** @return array<string, mixed> */
    private function publicAssessment(PublicAssessmentView $assessment): array
    {
        $base = [
            "type" => $assessment->kind()->value,
            "display_name" => $assessment->displayName(),
            "published_at" => $assessment->publishedAt()
                ->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\\TH:i:s.u\\Z"),
        ];

        if ($assessment->kind() === PublicAssessmentKind::Rating) {
            return [
                ...$base,
                "rating" => $assessment->rating()?->stars(),
            ];
        }

        return [
            ...$base,
            "rating" => $assessment->rating()?->stars(),
            "review_html" => $assessment->escapedReviewText(),
        ];
    }

    /** @return array<string, mixed> */
    private function nextReadingEntry(NextReadingEntryView $entry): array
    {
        return [
            "entry_id" => $entry->id()->value(),
            "position" => $entry->position(),
            "work" => [
                "work_id" => $entry->workId(),
                "title" => $entry->workTitle(),
            ],
            "preferred_source" => $this->nextReadingPreferredSource(
                $entry->preferredSource()
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function nextReadingPreferredSource(
        PreferredReadingSourceView $source
    ): array {
        if ($source->state() === PreferredReadingSourceState::None) {
            return [
                "state" => "none",
                "label" => "Geen voorkeursbron",
            ];
        }

        if ($source->state() === PreferredReadingSourceState::Unavailable) {
            return [
                "state" => "unavailable",
                "label" => "Voorkeursbron niet beschikbaar",
            ];
        }

        return [
            "state" => "available",
            "type" => $source->type()?->value,
            "label" => $source->label(),
        ];
    }

    /** @return array<string, mixed> */
    private function nextReadingSourceOption(
        NextReadingSourceOptionView $option
    ): array {
        if ($option->type()->value === "library_item") {
            return [
                "type" => "library_item",
                "library_id" => $option->libraryId()?->value(),
                "item_id" => $option->itemId()?->value(),
                "label" => $option->label(),
            ];
        }

        return [
            "type" => "external_loan",
            "external_loan_id" => $option->externalLoanId()?->value(),
            "label" => $option->label(),
        ];
    }

    private function nextReadingWorkCursorCodec(): NextReadingWorkCursorCodec
    {
        return $this->nextReadingWorkCursors ?? new NextReadingWorkCursorCodec();
    }

    private function publicAssessmentCursorCodec(): PublicAssessmentCursorCodec
    {
        return $this->publicAssessmentCursors
            ?? new PublicAssessmentCursorCodec();
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

    /** @return array<string, mixed> */
    private function catalogQueryItem(CatalogQueryItem $item): array
    {
        $classification = $item->classification();
        $location = $item->location();

        return [
            "item_id" => $item->itemId()->value(),
            "work_id" => $item->workId()->value(),
            "edition_id" => $item->editionId()->value(),
            "title" => $item->title(),
            "item_status" => $item->itemStatus()->value,
            "inventory_number" => $item->inventoryNumber(),
            "authors" => array_map(
                static fn (Author $author): array => [
                    "author_id" => $author->id()->value(),
                    "display_name" => $author->displayName(),
                ],
                $item->authors()
            ),
            "series" => array_map(
                static fn (CatalogQuerySeriesContext $context): array => [
                    "series_id" => $context->series()->id()->value(),
                    "display_name" => $context->series()->displayName(),
                    "position" => $context->position()->value(),
                ],
                $item->series()
            ),
            "location" => $location === null ? null : [
                "location_id" => $location->id()->value(),
                "display_name" => $location->displayName(),
            ],
            "classification" => $classification === null ? null : [
                "book_type_id" => $classification->bookTypeId()->value(),
                "genre_ids" => array_map(
                    static fn (LibraryGenreId $id): string => $id->value(),
                    $classification->genreIds()
                ),
                "subject_ids" => array_map(
                    static fn (LibrarySubjectId $id): string => $id->value(),
                    $classification->subjectIds()
                ),
            ],
            "collection_ids" => array_map(
                static fn (CollectionId $id): string => $id->value(),
                $item->collectionIds()
            ),
            "reading_status" => $item->readingStatus()->value,
            "contained_match_title" => $item->containedMatchTitle(),
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

    /** @return array{view_item: bool, start_reading: bool, end_reading: bool} */
    private function detailCapabilities(
        CatalogItemCapabilities $capabilities
    ): array {
        return [
            "view_item" => $capabilities->canViewItem(),
            "start_reading" => $capabilities->canStartReading(),
            "end_reading" => $capabilities->canEndReading(),
        ];
    }

    /**
     * @return null|array{
     *     reading_round_id: string,
     *     version: int,
     *     started_on: null|array{year: int, month: ?int, day: ?int}
     * }
     */
    private function activeReadingRound(
        ?CatalogActiveReadingRoundView $round
    ): ?array {
        return $round === null ? null : [
            "reading_round_id" => $round->readingRoundId()->value(),
            "version" => $round->version()->value(),
            "started_on" => $this->readingDate($round->startedOn()),
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
