<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Catalog\Read\{BibliographicRelationshipQueryService,LibraryItemLocationQueryService};
use Biblio\Core\Application\Collections\Read\LibraryCollectionQueryService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Catalog\{AuthorId,ItemId,SeriesId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class CatalogQueryService
{
    public function __construct(
        private AuthenticatedUser $authenticatedUser,
        private LibraryContextQueryService $libraryContexts,
        private CatalogQueryRepository $repository,
        private CatalogQueryCursorCodec $cursors,
        private BibliographicRelationshipQueryService $relationships,
        private LibraryClassificationQueryService $classifications,
        private LibraryItemLocationQueryService $locations,
        private LibraryCollectionQueryService $collections,
        private GetPersonalWorkReadingStatusService $readingStatuses
    ) {
    }

    public function query(LibraryId $libraryId, CatalogQuery $query): CatalogQueryPage
    {
        $library = $this->libraryContexts->get($libraryId);
        $actorId = $this->authenticatedUser->requireUserId();
        if ($query->sort() === CatalogQuerySort::Series && $query->filters()->seriesIds() === []) {
            throw new ValidationException('Series order requires an active Series filter.');
        }
        $after = $query->cursor() === null
            ? null
            : $this->cursors->decode($query->cursor(), $query, $libraryId, $actorId);
        $page = $this->repository->page($libraryId, $actorId, $query, $after);
        $records = $page->records();
        if ($records === []) {
            return new CatalogQueryPage($library, [], null);
        }

        $workIds = $this->uniqueWorkIds($records);
        $itemIds = array_map(static fn (CatalogQueryItemRecord $record): ItemId => $record->itemId(), $records);
        $contributors = $this->relationships->contributorsForWorks($workIds);
        $authors = $this->relationships->authors($this->authorIds($contributors));
        $memberships = $this->relationships->seriesForWorks($workIds);
        $series = $this->relationships->series($this->seriesIds($memberships));
        $classifications = $this->classifications->classificationsForWorks($libraryId, $workIds);
        $locations = $this->locations->locationsForItems($libraryId, $itemIds);
        $collections = $this->collections->activeCollectionsForItems($libraryId, $itemIds);
        $statuses = $this->readingStatuses->getMany($workIds);

        $items = [];
        foreach ($records as $record) {
            $workKey = $record->workId()->value();
            $recordAuthors = [];
            foreach ($contributors[$workKey] ?? [] as $contributor) {
                $author = $authors[$contributor->authorId()->value()] ?? null;
                if ($author !== null) {
                    $recordAuthors[] = $author;
                }
            }
            $recordSeries = [];
            foreach ($memberships[$workKey] ?? [] as $membership) {
                $entity = $series[$membership->seriesId()->value()] ?? null;
                if ($entity !== null) {
                    $recordSeries[] = new CatalogQuerySeriesContext($entity, $membership->position());
                }
            }
            $items[] = new CatalogQueryItem(
                $record->itemId(),
                $record->workId(),
                $record->editionId(),
                $record->title(),
                $record->itemStatus(),
                $record->inventoryNumber(),
                $recordAuthors,
                $recordSeries,
                $locations[$record->itemId()->value()] ?? null,
                $classifications[$workKey] ?? null,
                $collections[$record->itemId()->value()] ?? [],
                $statuses[$workKey],
                $record->containedMatchTitle()
            );
        }

        $last = $records[array_key_last($records)];
        $next = $page->hasMore()
            ? $this->cursors->encode($query, $libraryId, $actorId, $last->itemId())
            : null;
        return new CatalogQueryPage($library, $items, $next);
    }

    /**
     * @param list<CatalogQueryItemRecord> $records
     * @return list<WorkId>
     */
    private function uniqueWorkIds(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $result[$record->workId()->value()] = $record->workId();
        }
        return array_values($result);
    }

    /**
     * @param array<string, list<\Biblio\Core\Catalog\WorkContributor>> $contributors
     * @return list<AuthorId>
     */
    private function authorIds(array $contributors): array
    {
        $result = [];
        foreach ($contributors as $list) {
            foreach ($list as $contributor) {
                $result[$contributor->authorId()->value()] = $contributor->authorId();
            }
        }
        return array_values($result);
    }

    /**
     * @param array<string, list<\Biblio\Core\Catalog\WorkSeriesMembership>> $memberships
     * @return list<SeriesId>
     */
    private function seriesIds(array $memberships): array
    {
        $result = [];
        foreach ($memberships as $list) {
            foreach ($list as $membership) {
                $result[$membership->seriesId()->value()] = $membership->seriesId();
            }
        }
        return array_values($result);
    }
}
