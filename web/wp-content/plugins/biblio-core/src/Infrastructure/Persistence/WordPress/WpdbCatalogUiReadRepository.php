<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Catalog\Read\CatalogItemReadRecord;
use Biblio\Core\Application\Catalog\Read\CatalogItemReadRecordPage;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewCursor;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Read\CatalogUiReadRepository;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Throwable;
use wpdb;

final readonly class WpdbCatalogUiReadRepository implements CatalogUiReadRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function activeOverview(
        LibraryId $libraryId,
        UserId $actorId,
        CatalogOverviewPageSize $pageSize,
        ?CatalogOverviewCursor $cursor
    ): CatalogItemReadRecordPage {
        $where = "i.library_id = %s AND i.item_status = 'active'";
        $parameters = [
            $actorId->value(),
            $actorId->value(),
            $libraryId->value(),
        ];

        if ($cursor !== null) {
            $where .= " AND (w.work_title > %s OR "
                . "(w.work_title = %s AND i.item_id > %s))";
            $parameters[] = $cursor->workTitle();
            $parameters[] = $cursor->workTitle();
            $parameters[] = $cursor->itemId()->value();
        }

        $parameters[] = $pageSize->value() + 1;
        $rows = $this->database->get_results($this->database->prepare(
            $this->selectSql(true) . " WHERE {$where} "
            . "ORDER BY w.work_title ASC, i.item_id ASC LIMIT %d",
            ...$parameters
        ));
        $records = array_map($this->hydrate(...), $rows);
        $hasMore = count($records) > $pageSize->value();

        if ($hasMore) {
            $records = array_slice($records, 0, $pageSize->value());
        }

        $last = $records === [] ? null : $records[array_key_last($records)];

        return new CatalogItemReadRecordPage(
            $records,
            $hasMore && $last !== null
                ? new CatalogOverviewCursor($last->title(), $last->itemId())
                : null
        );
    }

    public function activeDetail(
        LibraryId $libraryId,
        ItemId $itemId,
        UserId $actorId
    ): ?CatalogItemReadRecord {
        $row = $this->database->get_row($this->database->prepare(
            $this->selectSql(false)
            . " WHERE i.library_id = %s AND i.item_id = %s "
            . "AND i.item_status = 'active'",
            $actorId->value(),
            $actorId->value(),
            $libraryId->value(),
            $itemId->value()
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    private function selectSql(bool $overview): string
    {
        $items = $this->tableNames->items();
        $editions = $this->tableNames->editions();
        $works = $this->tableNames->works();
        $rounds = $this->tableNames->readingRounds();

        $itemIndex = $overview ? " FORCE INDEX (items_by_library)" : "";

        return "SELECT i.item_id, i.edition_id, i.item_status, "
            . "w.work_id, w.work_title, "
            . "COALESCE(rs.active_rounds, 0) AS active_rounds, "
            . "COALESCE(rs.completed_rounds, 0) AS completed_rounds, "
            . "COALESCE(rs.stopped_rounds, 0) AS stopped_rounds, "
            . "COALESCE(rs.historical_completed_rounds, 0) "
            . "AS historical_completed_rounds, "
            . "CASE WHEN sr.reading_round_id IS NULL THEN 0 ELSE 1 END "
            . "AS active_round_for_item "
            . "FROM `{$items}` i{$itemIndex} "
            . "INNER JOIN `{$editions}` e ON e.edition_id = i.edition_id "
            . "INNER JOIN `{$works}` w ON w.work_id = e.work_id "
            . "LEFT JOIN (SELECT work_id, "
            . "SUM(round_outcome IS NULL) AS active_rounds, "
            . "SUM(round_outcome = 'completed') AS completed_rounds, "
            . "SUM(round_outcome = 'stopped') AS stopped_rounds, "
            . "SUM(round_outcome = 'completed' "
            . "AND provenance = 'historical_manual') "
            . "AS historical_completed_rounds "
            . "FROM `{$rounds}` WHERE user_id = %s GROUP BY work_id) rs "
            . "ON rs.work_id = w.work_id "
            . "LEFT JOIN `{$rounds}` sr "
            . "ON sr.user_id = %s AND sr.item_id = i.item_id "
            . "AND sr.round_outcome IS NULL";
    }

    private function hydrate(object $row): CatalogItemReadRecord
    {
        try {
            return new CatalogItemReadRecord(
                new ItemId((string) $row->item_id),
                new WorkId((string) $row->work_id),
                new EditionId((string) $row->edition_id),
                (string) $row->work_title,
                ItemStatus::from((string) $row->item_status),
                (int) $row->active_rounds,
                (int) $row->completed_rounds,
                (int) $row->stopped_rounds,
                (int) $row->historical_completed_rounds,
                (int) $row->active_round_for_item === 1
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Catalog UI projection data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
