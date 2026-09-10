<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Wishlist\{WishlistEntryView,WishlistReadRepository};
use Biblio\Core\Catalog\{Author,AuthorId,EditionId,WorkId};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Wishlist\WishlistEntryId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbWishlistReadRepository implements WishlistReadRepository
{
    private const DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function findForUser(UserId $ownerUserId): array
    {
        $entries = $this->tables->wishlistEntries();
        $works = $this->tables->works();
        $editions = $this->tables->editions();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT we.wishlist_entry_id,we.work_id,we.edition_id,"
                . "CASE WHEN we.edition_id IS NULL THEN w.work_title ELSE e.edition_title END display_title,"
                . "we.created_at,we.updated_at FROM `{$entries}` we "
                . "INNER JOIN `{$works}` w ON w.work_id=we.work_id "
                . "LEFT JOIN `{$editions}` e ON e.edition_id=we.edition_id "
                . "WHERE we.user_id=%s "
                . "ORDER BY we.created_at DESC,we.wishlist_entry_id DESC",
            $ownerUserId->value()
        ));
        $workIds = array_values(array_unique(array_map(
            static fn (object $row): string => (string) $row->work_id,
            $rows
        )));
        $authors = $this->authorsByWork($workIds);

        try {
            return array_map(
                fn (object $row): WishlistEntryView => new WishlistEntryView(
                    new WishlistEntryId((string) $row->wishlist_entry_id),
                    new WorkId((string) $row->work_id),
                    $row->edition_id === null
                        ? null
                        : new EditionId((string) $row->edition_id),
                    (string) $row->display_title,
                    $authors[(string) $row->work_id] ?? [],
                    $this->date((string) $row->created_at),
                    $this->date((string) $row->updated_at)
                ),
                $rows
            );
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Wishlist read data is invalid.", $exception);
        }
    }

    /**
     * @param list<string> $workIds
     * @return array<string, list<Author>>
     */
    private function authorsByWork(array $workIds): array
    {
        if ($workIds === []) {
            return [];
        }
        $contributors = $this->tables->workContributors();
        $authors = $this->tables->authors();
        $placeholders = implode(",", array_fill(0, count($workIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT wc.work_id,a.author_id,a.display_name "
                . "FROM `{$contributors}` wc "
                . "INNER JOIN `{$authors}` a ON a.author_id=wc.author_id "
                . "WHERE wc.work_id IN ({$placeholders}) "
                . "ORDER BY wc.work_id,wc.contributor_position,a.author_id",
            ...$workIds
        ));
        try {
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row->work_id][] = new Author(
                    new AuthorId((string) $row->author_id),
                    (string) $row->display_name
                );
            }
            return $result;
        } catch (Throwable $exception) {
            throw $this->invalid("Stored Wishlist author data is invalid.", $exception);
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new PersistenceException(
                "Stored Wishlist timestamp is invalid.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }
        return $date;
    }

    private function invalid(string $message, Throwable $exception): PersistenceException
    {
        return new PersistenceException(
            $message,
            0,
            $exception,
            FailureReason::PersistenceReadFailed
        );
    }
}
