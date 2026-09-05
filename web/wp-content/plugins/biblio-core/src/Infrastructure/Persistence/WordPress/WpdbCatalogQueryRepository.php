<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Catalog\Query\{CatalogArchiveScope,CatalogQuery,CatalogQueryItemRecord,CatalogQueryRecordPage,CatalogQueryRepository,CatalogQuerySort};
use Biblio\Core\Catalog\{EditionId,ItemId,ItemStatus,WorkId};
use Biblio\Core\Exception\{FailureReason,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Throwable;
use wpdb;

final readonly class WpdbCatalogQueryRepository implements CatalogQueryRepository
{
    private const SEARCH_COLLATION = 'utf8mb4_unicode_ci';

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
    }

    public function page(
        LibraryId $libraryId,
        UserId $actorId,
        CatalogQuery $query,
        ?ItemId $afterItemId
    ): CatalogQueryRecordPage {
        [$baseSql, $baseParameters] = $this->baseQuery($libraryId, $actorId, $query);
        $afterSql = '';
        $afterParameters = [];
        if ($afterItemId !== null) {
            $anchor = $this->anchor($baseSql, $baseParameters, $afterItemId);
            [$afterSql, $afterParameters] = $this->afterPredicate($query, $anchor);
        }
        $limit = $query->pageSize()->value() + 1;
        $sql = "SELECT * FROM ({$baseSql}) catalog {$afterSql} "
            . $this->orderBy($query) . ' LIMIT %d';
        $rows = $this->database->get_results($this->database->prepare(
            $sql,
            ...[...$baseParameters, ...$afterParameters, $limit]
        ));
        try {
            $records = array_map($this->hydrate(...), $rows);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                'Stored catalog query data is invalid.',
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
        $hasMore = count($records) > $query->pageSize()->value();
        if ($hasMore) {
            $records = array_slice($records, 0, $query->pageSize()->value());
        }
        return new CatalogQueryRecordPage($records, $hasMore);
    }

    /** @param list<string|int> $parameters */
    private function anchor(string $baseSql, array $parameters, ItemId $itemId): object
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM ({$baseSql}) catalog WHERE item_id=%s LIMIT 1",
            ...[...$parameters, $itemId->value()]
        ));
        if ($row === null) {
            throw new ValidationException('Catalog cursor anchor is no longer valid for this query.');
        }
        return $row;
    }

    /** @return array{string,list<string|int>} */
    private function baseQuery(LibraryId $libraryId, UserId $actorId, CatalogQuery $query): array
    {
        $i = $this->tables->items();
        $e = $this->tables->editions();
        $w = $this->tables->works();
        $wc = $this->tables->workContributors();
        $a = $this->tables->authors();
        $ws = $this->tables->workSeries();
        $s = $this->tables->series();

        $selectParameters = [];
        $seriesRestriction = '';
        if ($query->sort() === CatalogQuerySort::Series && $query->search() === null) {
            $seriesIds = $this->idValues($query->filters()->seriesIds());
            $seriesRestriction = ' AND ws_sort.series_id IN (' . $this->placeholders(count($seriesIds)) . ')';
            array_push($selectParameters, ...$seriesIds, ...$seriesIds);
        }
        $authorSort = 'NULL';
        if ($query->sort() === CatalogQuerySort::Author && $query->search() === null) {
            $authorSort = "(SELECT a_sort.display_name FROM `{$wc}` wc_sort "
                . "INNER JOIN `{$a}` a_sort ON a_sort.author_id=wc_sort.author_id "
                . 'WHERE wc_sort.work_id=w.work_id ORDER BY wc_sort.contributor_position,a_sort.author_id LIMIT 1)';
        }
        $seriesNameSort = 'NULL';
        $seriesPositionSort = 'NULL';
        if ($query->sort() === CatalogQuerySort::Series && $query->search() === null) {
            $seriesNameSort = "(SELECT s_sort.display_name FROM `{$ws}` ws_sort "
                . "INNER JOIN `{$s}` s_sort ON s_sort.series_id=ws_sort.series_id "
                . "WHERE ws_sort.work_id=w.work_id{$seriesRestriction} "
                . 'ORDER BY s_sort.display_name,ws_sort.series_position IS NULL,ws_sort.series_position,s_sort.series_id LIMIT 1)';
            $seriesPositionSort = "(SELECT ws_sort.series_position FROM `{$ws}` ws_sort "
                . "INNER JOIN `{$s}` s_sort ON s_sort.series_id=ws_sort.series_id "
                . "WHERE ws_sort.work_id=w.work_id{$seriesRestriction} "
                . 'ORDER BY s_sort.display_name,ws_sort.series_position IS NULL,ws_sort.series_position,s_sort.series_id LIMIT 1)';
        }

        $relevance = '0';
        $containedTitle = 'NULL';
        if ($query->search() !== null) {
            [$groups, $groupParameters] = $this->searchGroups($query->search()->value());
            $relevance = 'CASE '
                . "WHEN ({$groups[0]}) THEN 1 "
                . "WHEN ({$groups[1]}) THEN 2 "
                . "WHEN ({$groups[2]}) THEN 3 "
                . "WHEN ({$groups[3]}) THEN 4 ELSE 5 END";
            array_push($selectParameters, ...array_slice($groupParameters, 0, 14));
            [$containedTitle, $containedParameters] = $this->containedMatchTitle($query->search()->value());
            array_push($selectParameters, ...$containedParameters);
        }

        $where = ['i.library_id=%s'];
        $whereParameters = [$libraryId->value()];
        $where[] = $query->archiveScope() === CatalogArchiveScope::ActiveOnly
            ? "i.item_status='active'"
            : "i.item_status IN ('active','archived')";
        $this->appendFilters($where, $whereParameters, $libraryId, $actorId, $query);
        if ($query->search() !== null) {
            [$groups, $groupParameters] = $this->searchGroups($query->search()->value());
            $where[] = '(' . implode(' OR ', array_map(static fn (string $sql): string => "({$sql})", $groups)) . ')';
            array_push($whereParameters, ...$groupParameters);
        }

        $sql = 'SELECT i.item_id,i.edition_id,i.item_status,i.inventory_number,'
            . 'e.work_id,w.work_title,'
            . "{$relevance} AS relevance_rank,"
            . "{$authorSort} AS sort_author,"
            . "{$seriesNameSort} AS sort_series_name,"
            . "{$seriesPositionSort} AS sort_series_position,"
            . "{$containedTitle} AS contained_match_title "
            . "FROM `{$i}` i FORCE INDEX (items_by_library_status_location) "
            . "INNER JOIN `{$e}` e ON e.edition_id=i.edition_id "
            . "INNER JOIN `{$w}` w ON w.work_id=e.work_id "
            . 'WHERE ' . implode(' AND ', $where);
        return [$sql, [...$selectParameters, ...$whereParameters]];
    }

    /**
     * @param list<string> $where
     * @param list<string|int> $parameters
     */
    private function appendFilters(
        array &$where,
        array &$parameters,
        LibraryId $libraryId,
        UserId $actorId,
        CatalogQuery $query
    ): void
    {
        $filters = $query->filters();
        $this->appendInFilter($where, $parameters, $filters->authorIds(),
            "w.work_id IN (SELECT wc_f.work_id FROM `{$this->tables->workContributors()}` wc_f WHERE wc_f.author_id IN (%s))");
        $this->appendInFilter($where, $parameters, $filters->seriesIds(),
            "w.work_id IN (SELECT ws_f.work_id FROM `{$this->tables->workSeries()}` ws_f WHERE ws_f.series_id IN (%s))");
        if ($filters->locationIds() !== []) {
            $values = $this->idValues($filters->locationIds());
            $where[] = 'i.location_id IN (' . $this->placeholders(count($values)) . ')';
            array_push($parameters, ...$values);
        }
        $this->appendClassificationFilters($where, $parameters, $libraryId, $query);
        $collectionFilter = $filters->collections();
        $activeMembership = "SELECT cm_f.item_id FROM `{$this->tables->collectionMemberships()}` cm_f "
            . "INNER JOIN `{$this->tables->collections()}` c_f ON c_f.library_id=cm_f.library_id AND c_f.collection_id=cm_f.collection_id "
            . "WHERE cm_f.library_id=%s AND cm_f.membership_status='active' AND c_f.collection_status='active'";
        if ($collectionFilter->collectionIds() !== []) {
            $values = $this->idValues($collectionFilter->collectionIds());
            $where[] = "i.item_id IN ({$activeMembership} AND cm_f.collection_id IN (" . $this->placeholders(count($values)) . '))';
            array_push($parameters, $libraryId->value(), ...$values);
        } elseif ($collectionFilter->isWithoutCollection()) {
            $where[] = "i.item_id NOT IN ({$activeMembership})";
            $parameters[] = $libraryId->value();
        }
        if ($filters->readingStatuses() !== []) {
            $predicates = [];
            foreach ($filters->readingStatuses() as $status) {
                $active = "EXISTS (SELECT 1 FROM `{$this->tables->readingRounds()}` rr_a WHERE rr_a.user_id=%s AND rr_a.work_id=w.work_id AND rr_a.round_outcome IS NULL)";
                $completed = "EXISTS (SELECT 1 FROM `{$this->tables->readingRounds()}` rr_c WHERE rr_c.user_id=%s AND rr_c.work_id=w.work_id AND rr_c.round_outcome='completed')";
                if ($status === PersonalWorkReadingStatus::Reading) {
                    $predicates[] = $active;
                    $parameters[] = $actorId->value();
                } elseif ($status === PersonalWorkReadingStatus::Read) {
                    $predicates[] = "NOT {$active} AND {$completed}";
                    array_push($parameters, $actorId->value(), $actorId->value());
                } else {
                    $predicates[] = "NOT {$active} AND NOT {$completed}";
                    array_push($parameters, $actorId->value(), $actorId->value());
                }
            }
            $where[] = '(' . implode(' OR ', array_map(static fn (string $sql): string => "({$sql})", $predicates)) . ')';
        }
    }

    /**
     * @param list<string> $where
     * @param list<string|int> $parameters
     */
    private function appendClassificationFilters(
        array &$where,
        array &$parameters,
        LibraryId $libraryId,
        CatalogQuery $query
    ): void
    {
        $filters = $query->filters();
        if ($filters->bookTypeIds() !== []) {
            $values = $this->idValues($filters->bookTypeIds());
            $where[] = "w.work_id IN (SELECT lcc_f.work_id FROM `{$this->tables->libraryCatalogContexts()}` lcc_f "
                . "INNER JOIN `{$this->tables->libraryBookTypes()}` bt_f ON bt_f.library_id=lcc_f.library_id AND bt_f.book_type_id=lcc_f.book_type_id AND bt_f.term_status='active' "
                . 'WHERE lcc_f.library_id=%s AND lcc_f.book_type_id IN ('
                . $this->placeholders(count($values)) . '))';
            array_push($parameters, $libraryId->value(), ...$values);
        }
        if ($filters->genreIds() !== []) {
            $values = $this->idValues($filters->genreIds());
            $where[] = "w.work_id IN (SELECT g_f.work_id FROM `{$this->tables->libraryCatalogContextGenres()}` g_f "
                . "INNER JOIN `{$this->tables->libraryGenres()}` gt_f ON gt_f.library_id=g_f.library_id AND gt_f.genre_id=g_f.genre_id AND gt_f.term_status='active' "
                . 'WHERE g_f.library_id=%s AND g_f.genre_id IN ('
                . $this->placeholders(count($values)) . '))';
            array_push($parameters, $libraryId->value(), ...$values);
        }
        if ($filters->subjectIds() !== []) {
            $values = $this->idValues($filters->subjectIds());
            $where[] = "w.work_id IN (SELECT s_f.work_id FROM `{$this->tables->libraryCatalogContextSubjects()}` s_f "
                . "INNER JOIN `{$this->tables->librarySubjects()}` st_f ON st_f.library_id=s_f.library_id AND st_f.subject_id=s_f.subject_id AND st_f.term_status='active' "
                . 'WHERE s_f.library_id=%s AND s_f.subject_id IN ('
                . $this->placeholders(count($values)) . '))';
            array_push($parameters, $libraryId->value(), ...$values);
        }
    }

    /**
     * @param list<string> $where
     * @param list<string|int> $parameters
     * @param list<object> $ids
     */
    private function appendInFilter(array &$where, array &$parameters, array $ids, string $template): void
    {
        if ($ids === []) {
            return;
        }
        $values = $this->idValues($ids);
        $where[] = sprintf($template, $this->placeholders(count($values)));
        array_push($parameters, ...$values);
    }

    /** @return array{list<string>,list<string>} */
    private function searchGroups(string $term): array
    {
        $like = '%' . $this->database->esc_like($term) . '%';
        $collation = self::SEARCH_COLLATION;
        $titles = $this->tables->workAlternateTitles();
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $workSeries = $this->tables->workSeries();
        $series = $this->tables->series();
        $containments = $this->tables->workContainments();
        $works = $this->tables->works();
        $exact = "CONVERT(w.work_title USING utf8mb4) COLLATE {$collation}=%s OR e.isbn_10=%s OR e.isbn_13=%s "
            . "OR EXISTS (SELECT 1 FROM `{$titles}` ae_f WHERE ae_f.work_id=w.work_id AND CONVERT(ae_f.alternate_title USING utf8mb4) COLLATE {$collation}=%s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` ce_f INNER JOIN `{$works}` cwe_f ON cwe_f.work_id=ce_f.contained_work_id WHERE ce_f.parent_work_id=w.work_id AND CONVERT(cwe_f.work_title USING utf8mb4) COLLATE {$collation}=%s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` cae_f INNER JOIN `{$titles}` cate_f ON cate_f.work_id=cae_f.contained_work_id WHERE cae_f.parent_work_id=w.work_id AND CONVERT(cate_f.alternate_title USING utf8mb4) COLLATE {$collation}=%s)";
        $title = "CONVERT(w.work_title USING utf8mb4) COLLATE {$collation} LIKE %s "
            . "OR EXISTS (SELECT 1 FROM `{$titles}` at_f WHERE at_f.work_id=w.work_id AND CONVERT(at_f.alternate_title USING utf8mb4) COLLATE {$collation} LIKE %s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` ct_f INNER JOIN `{$works}` cw_f ON cw_f.work_id=ct_f.contained_work_id WHERE ct_f.parent_work_id=w.work_id AND CONVERT(cw_f.work_title USING utf8mb4) COLLATE {$collation} LIKE %s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` cat_f INNER JOIN `{$titles}` catt_f ON catt_f.work_id=cat_f.contained_work_id WHERE cat_f.parent_work_id=w.work_id AND CONVERT(catt_f.alternate_title USING utf8mb4) COLLATE {$collation} LIKE %s)";
        $author = "EXISTS (SELECT 1 FROM `{$contributors}` wc_s INNER JOIN `{$authors}` a_s ON a_s.author_id=wc_s.author_id WHERE wc_s.work_id=w.work_id AND CONVERT(a_s.display_name USING utf8mb4) COLLATE {$collation} LIKE %s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` ca_s INNER JOIN `{$contributors}` cwc_s ON cwc_s.work_id=ca_s.contained_work_id INNER JOIN `{$authors}` aa_s ON aa_s.author_id=cwc_s.author_id WHERE ca_s.parent_work_id=w.work_id AND CONVERT(aa_s.display_name USING utf8mb4) COLLATE {$collation} LIKE %s)";
        $seriesSql = "EXISTS (SELECT 1 FROM `{$workSeries}` ws_s INNER JOIN `{$series}` s_s ON s_s.series_id=ws_s.series_id WHERE ws_s.work_id=w.work_id AND CONVERT(s_s.display_name USING utf8mb4) COLLATE {$collation} LIKE %s) "
            . "OR EXISTS (SELECT 1 FROM `{$containments}` cs_s INNER JOIN `{$workSeries}` cws_s ON cws_s.work_id=cs_s.contained_work_id INNER JOIN `{$series}` ss_s ON ss_s.series_id=cws_s.series_id WHERE cs_s.parent_work_id=w.work_id AND CONVERT(ss_s.display_name USING utf8mb4) COLLATE {$collation} LIKE %s)";
        $other = 'CONVERT(i.inventory_number USING utf8mb4) COLLATE ' . $collation . ' LIKE %s';
        return [[$exact, $title, $author, $seriesSql, $other], [
            $term, $term, $term, $term, $term, $term,
            $like, $like, $like, $like,
            $like, $like,
            $like, $like,
            $like,
        ]];
    }

    /** @return array{string,list<string>} */
    private function containedMatchTitle(string $term): array
    {
        $like = '%' . $this->database->esc_like($term) . '%';
        $collation = self::SEARCH_COLLATION;
        $sql = "(SELECT cw_m.work_title FROM `{$this->tables->workContainments()}` ct_m "
            . "INNER JOIN `{$this->tables->works()}` cw_m ON cw_m.work_id=ct_m.contained_work_id "
            . "LEFT JOIN `{$this->tables->workAlternateTitles()}` at_m ON at_m.work_id=cw_m.work_id "
            . "LEFT JOIN `{$this->tables->workContributors()}` wc_m ON wc_m.work_id=cw_m.work_id "
            . "LEFT JOIN `{$this->tables->authors()}` a_m ON a_m.author_id=wc_m.author_id "
            . "LEFT JOIN `{$this->tables->workSeries()}` ws_m ON ws_m.work_id=cw_m.work_id "
            . "LEFT JOIN `{$this->tables->series()}` s_m ON s_m.series_id=ws_m.series_id "
            . "WHERE ct_m.parent_work_id=w.work_id AND (CONVERT(cw_m.work_title USING utf8mb4) COLLATE {$collation} LIKE %s "
            . "OR CONVERT(at_m.alternate_title USING utf8mb4) COLLATE {$collation} LIKE %s "
            . "OR CONVERT(a_m.display_name USING utf8mb4) COLLATE {$collation} LIKE %s "
            . "OR CONVERT(s_m.display_name USING utf8mb4) COLLATE {$collation} LIKE %s) "
            . 'ORDER BY ct_m.contained_position LIMIT 1)';
        return [$sql, [$like, $like, $like, $like]];
    }

    /** @return array{string,list<string|int>} */
    private function afterPredicate(CatalogQuery $query, object $anchor): array
    {
        if ($query->search() !== null) {
            return [
                'WHERE (relevance_rank>%d OR (relevance_rank=%d AND (work_title>%s OR (work_title=%s AND item_id>%s))))',
                [(int) $anchor->relevance_rank, (int) $anchor->relevance_rank, (string) $anchor->work_title, (string) $anchor->work_title, (string) $anchor->item_id],
            ];
        }
        if ($query->sort() === CatalogQuerySort::Author) {
            $missing = $anchor->sort_author === null ? 1 : 0;
            return [
                'WHERE (sort_author IS NULL>%d OR (sort_author IS NULL=%d AND (COALESCE(sort_author,\'\')>%s OR (COALESCE(sort_author,\'\')=%s AND (work_title>%s OR (work_title=%s AND item_id>%s))))))',
                [$missing, $missing, (string) ($anchor->sort_author ?? ''), (string) ($anchor->sort_author ?? ''), (string) $anchor->work_title, (string) $anchor->work_title, (string) $anchor->item_id],
            ];
        }
        if ($query->sort() === CatalogQuerySort::Series) {
            $seriesMissing = $anchor->sort_series_name === null ? 1 : 0;
            $positionMissing = $anchor->sort_series_position === null ? 1 : 0;
            return [
                'WHERE (sort_series_name IS NULL>%d OR (sort_series_name IS NULL=%d AND (COALESCE(sort_series_name,\'\')>%s OR (COALESCE(sort_series_name,\'\')=%s AND (sort_series_position IS NULL>%d OR (sort_series_position IS NULL=%d AND (COALESCE(sort_series_position,0)>%s OR (COALESCE(sort_series_position,0)=%s AND (work_title>%s OR (work_title=%s AND item_id>%s))))))))))',
                [$seriesMissing, $seriesMissing, (string) ($anchor->sort_series_name ?? ''), (string) ($anchor->sort_series_name ?? ''), $positionMissing, $positionMissing, (string) ($anchor->sort_series_position ?? '0'), (string) ($anchor->sort_series_position ?? '0'), (string) $anchor->work_title, (string) $anchor->work_title, (string) $anchor->item_id],
            ];
        }
        return [
            'WHERE (work_title>%s OR (work_title=%s AND item_id>%s))',
            [(string) $anchor->work_title, (string) $anchor->work_title, (string) $anchor->item_id],
        ];
    }

    private function orderBy(CatalogQuery $query): string
    {
        if ($query->search() !== null) {
            return 'ORDER BY relevance_rank,work_title,item_id';
        }
        return match ($query->sort()) {
            CatalogQuerySort::Title => 'ORDER BY work_title,item_id',
            CatalogQuerySort::Author => 'ORDER BY sort_author IS NULL,sort_author,work_title,item_id',
            CatalogQuerySort::Series => 'ORDER BY sort_series_name IS NULL,sort_series_name,sort_series_position IS NULL,sort_series_position,work_title,item_id',
        };
    }

    private function hydrate(object $row): CatalogQueryItemRecord
    {
        return new CatalogQueryItemRecord(
            new ItemId((string) $row->item_id),
            new WorkId((string) $row->work_id),
            new EditionId((string) $row->edition_id),
            (string) $row->work_title,
            ItemStatus::from((string) $row->item_status),
            $row->inventory_number === null ? null : (string) $row->inventory_number,
            $row->contained_match_title === null ? null : (string) $row->contained_match_title
        );
    }

    /**
     * @param list<object> $ids
     * @return list<string>
     */
    private function idValues(array $ids): array
    {
        return array_map(static fn (object $id): string => $id->value(), $ids);
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '%s'));
    }
}
