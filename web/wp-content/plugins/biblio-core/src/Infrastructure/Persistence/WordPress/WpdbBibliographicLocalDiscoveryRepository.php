<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicLocalDiscoveryRepository;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicLocalDiscoveryRepository implements
    BibliographicLocalDiscoveryRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function searchText(BibliographicDiscoveryQuery $query, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException("Invalid local discovery limit.");
        }
        $works = $this->tables->works();
        $editions = $this->tables->editions();
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $tokens = preg_split('/\s+/u', $query->normalizedValue());
        if ($tokens === false || $tokens === []) {
            throw new \InvalidArgumentException("Invalid local discovery query.");
        }
        $workPredicates = [];
        $editionPredicates = [];
        $workParameters = [];
        $editionParameters = [];
        foreach ($tokens as $token) {
            $pattern = "%" . $this->database->esc_like($token) . "%";
            $workPredicates[] = "(w.work_title LIKE %s OR EXISTS ("
                . "SELECT 1 FROM `{$contributors}` wc INNER JOIN `{$authors}` a "
                . "ON a.author_id=wc.author_id WHERE wc.work_id=w.work_id "
                . "AND a.display_name LIKE %s))";
            array_push($workParameters, $pattern, $pattern);
            $editionPredicates[] = "(e.edition_title LIKE %s OR w.work_title LIKE %s OR EXISTS ("
                . "SELECT 1 FROM `{$contributors}` wc INNER JOIN `{$authors}` a "
                . "ON a.author_id=wc.author_id WHERE wc.work_id=e.work_id "
                . "AND a.display_name LIKE %s))";
            array_push($editionParameters, $pattern, $pattern, $pattern);
        }
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT 'work' AS result_type,w.work_id,NULL AS edition_id,"
                . "w.work_title AS result_title,NULL AS isbn_10,NULL AS isbn_13 "
                . "FROM `{$works}` w WHERE " . implode(" AND ", $workPredicates)
                . " UNION ALL "
                . "SELECT 'edition',e.work_id,e.edition_id,e.edition_title,e.isbn_10,e.isbn_13 "
                . "FROM `{$editions}` e INNER JOIN `{$works}` w ON w.work_id=e.work_id "
                . "WHERE " . implode(" AND ", $editionPredicates)
                . " ORDER BY result_title,result_type,work_id,edition_id LIMIT %d",
            ...[...$workParameters, ...$editionParameters, $limit]
        ));

        try {
            $result = [];
            foreach ($rows as $order => $row) {
                $workId = new WorkId((string) $row->work_id);
                if ((string) $row->result_type === "work") {
                    $result[] = BibliographicDiscoveryCandidate::localWork(
                        $workId, (string) $row->result_title, $query, $order
                    );
                    continue;
                }
                $identity = null;
                if ($row->isbn_13 !== null) {
                    $metadata = EditionIsbnMetadata::identified(
                        $row->isbn_10 === null ? null : new Isbn10((string) $row->isbn_10),
                        new Isbn13((string) $row->isbn_13)
                    );
                    $identity = CanonicalIsbnIdentity::fromMetadata($metadata);
                }
                $result[] = BibliographicDiscoveryCandidate::localEdition(
                    $workId,
                    new EditionId((string) $row->edition_id),
                    (string) $row->result_title,
                    $query,
                    $order,
                    $identity
                );
            }
            return $result;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored local bibliographic discovery data is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }
}
