<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Identity\PersonalMigrationTargetContentRepository;
use Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use wpdb;

final readonly class WpdbPersonalMigrationTargetContentRepository implements
    PersonalMigrationTargetContentRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function inspect(
        UserId $userId,
        LibraryId $libraryId
    ): PersonalMigrationTargetReadiness {
        return new PersonalMigrationTargetReadiness([
            "library_other_memberships" => $this->countOtherMemberships(
                $userId,
                $libraryId
            ),
            "library_items" => $this->countBy(
                $this->tables->items(),
                "library_id",
                $libraryId->value()
            ),
            "library_catalog_contexts" => $this->countBy(
                $this->tables->libraryCatalogContexts(),
                "library_id",
                $libraryId->value()
            ),
            "library_custom_classification_terms" =>
                $this->customClassificationCount($libraryId),
            "library_locations" => $this->countBy(
                $this->tables->locations(),
                "library_id",
                $libraryId->value()
            ),
            "library_collections" => $this->countBy(
                $this->tables->collections(),
                "library_id",
                $libraryId->value()
            ),
            "library_collection_memberships" => $this->countBy(
                $this->tables->collectionMemberships(),
                "library_id",
                $libraryId->value()
            ),
            "library_archive_periods" => $this->countBy(
                $this->tables->itemArchivePeriods(),
                "library_id",
                $libraryId->value()
            ),
            "library_publications" => $this->countBy(
                $this->tables->contributionPublications(),
                "library_id",
                $libraryId->value()
            ),
            "library_activity_events" => $this->countBy(
                $this->tables->libraryActivityEvents(),
                "library_id",
                $libraryId->value()
            ),
            "library_metadata_observations" => $this->countBy(
                $this->tables->metadataUserObservations(),
                "library_id",
                $libraryId->value()
            ),
            "library_metadata_lookup_snapshots" => $this->countBy(
                $this->tables->metadataLookupSnapshots(),
                "library_id",
                $libraryId->value()
            ),
            "user_external_loans" => $this->countBy(
                $this->tables->externalLoans(),
                "user_id",
                $userId->value()
            ),
            "user_reading_rounds" => $this->countBy(
                $this->tables->readingRounds(),
                "user_id",
                $userId->value()
            ),
            "user_personal_reading_truths" => $this->countBy(
                $this->tables->personalReadingTruths(),
                "user_id",
                $userId->value()
            ),
            "user_private_notes" => $this->countBy(
                $this->tables->privateNotes(),
                "user_id",
                $userId->value()
            ),
            "user_ratings" => $this->countBy(
                $this->tables->ratings(),
                "user_id",
                $userId->value()
            ),
            "user_reviews" => $this->countBy(
                $this->tables->reviews(),
                "user_id",
                $userId->value()
            ),
            "user_next_reading_entries" => $this->countBy(
                $this->tables->nextReadingEntries(),
                "user_id",
                $userId->value()
            ),
            "user_next_reading_lists" => $this->countBy(
                $this->tables->nextReadingLists(),
                "user_id",
                $userId->value()
            ),
            "user_next_reading_undo" => $this->countBy(
                $this->tables->nextReadingUndo(),
                "user_id",
                $userId->value()
            ),
        ]);
    }

    private function countOtherMemberships(
        UserId $userId,
        LibraryId $libraryId
    ): int {
        $table = $this->tables->memberships();
        $count = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` "
                . "WHERE library_id = %s AND user_id <> %s",
            $libraryId->value(),
            $userId->value()
        ));

        if ($count === null) {
            throw $this->readFailure();
        }

        return (int) $count;
    }

    private function customClassificationCount(LibraryId $libraryId): int
    {
        $count = 0;

        foreach ([
            $this->tables->libraryBookTypes(),
            $this->tables->libraryGenres(),
            $this->tables->librarySubjects(),
        ] as $table) {
            $value = $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM `{$table}` "
                    . "WHERE library_id = %s AND seed_key IS NULL",
                $libraryId->value()
            ));

            if ($value === null) {
                throw $this->readFailure();
            }

            $count += (int) $value;
        }

        return $count;
    }

    private function countBy(string $table, string $column, string $value): int
    {
        $count = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %s",
            $value
        ));

        if ($count === null) {
            throw $this->readFailure();
        }

        return (int) $count;
    }

    private function readFailure(): PersistenceException
    {
        return new PersistenceException(
            "Could not inspect personal migration target content.",
            failureReason: FailureReason::PersistenceReadFailed
        );
    }
}
