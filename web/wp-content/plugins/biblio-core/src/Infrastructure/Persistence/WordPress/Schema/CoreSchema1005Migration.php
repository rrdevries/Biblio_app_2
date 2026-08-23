<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class CoreSchema1005Migration implements CoreSchemaMigration
{
    private CoreSchemaHealthChecker $healthChecker;

    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
        $this->healthChecker = new CoreSchemaHealthChecker($database, $tables);
    }

    public function sourceVersion(): int { return 1004; }
    public function targetVersion(): int { return 1005; }

    public function assertPrecondition(): void
    {
        $base = $this->healthChecker->inspectForVersion(1004);
        if (!$base->isHealthy()) {
            throw new CoreSchemaHealthException($base);
        }

        $targets = $this->targetTables();
        $present = array_values(array_filter($targets, fn (string $table): bool => $this->exists($table)));
        if ($present !== []) {
            $retry = $this->healthChecker->inspectForVersion(1005);
            $expectedPrefix = array_slice($targets, 0, count($present));
            $allowedMissing = array_map(
                static fn (string $table): string => "Missing required table {$table}",
                array_slice($targets, count($present))
            );
            if ($present !== $expectedPrefix || $retry->errors() !== $allowedMissing) {
                throw new CoreSchemaMigrationException(
                    "Schema 1005 retry found an unknown partial Ratings/Reviews state: "
                    . $retry->summary()
                );
            }
        }
    }

    public function migrate(): void
    {
        if (!$this->exists($this->tables->ratings())) {
            $this->query($this->ratingSql(), "Ratings");
        }
        if (!$this->exists($this->tables->reviews())) {
            $this->query($this->reviewSql(), "Reviews");
        }
        if (!$this->exists($this->tables->contributionPublications())) {
            $this->query($this->publicationSql(), "contribution Publications");
        }
    }

    public function assertPostcondition(): void
    {
        $health = $this->healthChecker->inspectForVersion(1005);
        if (!$health->isHealthy()) {
            throw new CoreSchemaHealthException($health);
        }
    }

    /** @return list<string> */
    private function targetTables(): array
    {
        return [$this->tables->ratings(), $this->tables->reviews(), $this->tables->contributionPublications()];
    }

    private function query(string $sql, string $subject): void
    {
        if ($this->database->query($sql) === false) {
            throw new CoreSchemaMigrationException(
                "Could not create schema 1005 {$subject} table: " . $this->database->last_error
            );
        }
    }

    private function ratingSql(): string
    {
        $table = $this->tables->ratings(); $works = $this->tables->works(); $rounds = $this->tables->readingRounds();
        return "CREATE TABLE `{$table}` ("
            . "rating_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "work_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,reading_round_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "rating_half_units TINYINT UNSIGNED NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,"
            . "rating_version BIGINT UNSIGNED NOT NULL,unlinked_work_id VARCHAR(191) COLLATE utf8mb4_bin "
            . "AS (CASE WHEN reading_round_id IS NULL THEN work_id ELSE NULL END) STORED,"
            . "PRIMARY KEY (rating_id),UNIQUE KEY one_unlinked_rating (user_id,unlinked_work_id),"
            . "UNIQUE KEY one_rating_per_round (user_id,reading_round_id),"
            . "KEY ratings_by_user_work (user_id,work_id,updated_at,rating_id),"
            . "KEY ratings_by_user_round (user_id,reading_round_id,updated_at,rating_id),"
            . "KEY ratings_by_user_updated (user_id,updated_at,rating_id),KEY ratings_by_work (work_id,updated_at,rating_id),"
            . "CONSTRAINT ratings_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT ratings_round_fk FOREIGN KEY (reading_round_id) REFERENCES `{$rounds}` (reading_round_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT ratings_value_range CHECK (rating_half_units BETWEEN 2 AND 10),"
            . "CONSTRAINT ratings_version_positive CHECK (rating_version >= 1),"
            . "CONSTRAINT ratings_technical_time CHECK (updated_at >= created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function reviewSql(): string
    {
        $table = $this->tables->reviews(); $works = $this->tables->works(); $rounds = $this->tables->readingRounds();
        return "CREATE TABLE `{$table}` ("
            . "review_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,user_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "work_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,reading_round_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "review_content TEXT NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,"
            . "review_version BIGINT UNSIGNED NOT NULL,unlinked_work_id VARCHAR(191) COLLATE utf8mb4_bin "
            . "AS (CASE WHEN reading_round_id IS NULL THEN work_id ELSE NULL END) STORED,"
            . "PRIMARY KEY (review_id),UNIQUE KEY one_unlinked_review (user_id,unlinked_work_id),"
            . "UNIQUE KEY one_review_per_round (user_id,reading_round_id),"
            . "KEY reviews_by_user_work (user_id,work_id,updated_at,review_id),"
            . "KEY reviews_by_user_round (user_id,reading_round_id,updated_at,review_id),"
            . "KEY reviews_by_user_updated (user_id,updated_at,review_id),KEY reviews_by_work (work_id,updated_at,review_id),"
            . "CONSTRAINT reviews_work_fk FOREIGN KEY (work_id) REFERENCES `{$works}` (work_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT reviews_round_fk FOREIGN KEY (reading_round_id) REFERENCES `{$rounds}` (reading_round_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT reviews_length CHECK (CHAR_LENGTH(review_content) <= 5000),"
            . "CONSTRAINT reviews_version_positive CHECK (review_version >= 1),"
            . "CONSTRAINT reviews_technical_time CHECK (updated_at >= created_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function publicationSql(): string
    {
        $table = $this->tables->contributionPublications(); $libraries = $this->tables->libraries();
        $ratings = $this->tables->ratings(); $reviews = $this->tables->reviews();
        return "CREATE TABLE `{$table}` ("
            . "publication_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,library_id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
            . "rating_id VARCHAR(191) COLLATE utf8mb4_bin NULL,review_id VARCHAR(191) COLLATE utf8mb4_bin NULL,"
            . "author_status VARCHAR(16) NOT NULL,moderation_status VARCHAR(16) NOT NULL,moderation_reason TEXT NULL,"
            . "moderator_user_id VARCHAR(191) COLLATE utf8mb4_bin NULL,moderated_at DATETIME(6) NULL,"
            . "published_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,publication_version BIGINT UNSIGNED NOT NULL,"
            . "active_rating_id VARCHAR(191) COLLATE utf8mb4_bin AS (CASE WHEN rating_id IS NOT NULL AND author_status='active' AND moderation_status<>'removed' THEN rating_id ELSE NULL END) STORED,"
            . "active_review_id VARCHAR(191) COLLATE utf8mb4_bin AS (CASE WHEN review_id IS NOT NULL AND author_status='active' AND moderation_status<>'removed' THEN review_id ELSE NULL END) STORED,"
            . "PRIMARY KEY (publication_id),UNIQUE KEY rating_library_history (rating_id,library_id),"
            . "UNIQUE KEY review_library_history (review_id,library_id),UNIQUE KEY one_current_rating_publication (active_rating_id),"
            . "UNIQUE KEY one_current_review_publication (active_review_id),"
            . "KEY publications_by_library_state (library_id,author_status,moderation_status,updated_at,publication_id),"
            . "KEY publications_by_rating (rating_id),KEY publications_by_review (review_id),"
            . "CONSTRAINT publications_library_fk FOREIGN KEY (library_id) REFERENCES `{$libraries}` (library_id) ON UPDATE RESTRICT ON DELETE RESTRICT,"
            . "CONSTRAINT publications_rating_fk FOREIGN KEY (rating_id) REFERENCES `{$ratings}` (rating_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
            . "CONSTRAINT publications_review_fk FOREIGN KEY (review_id) REFERENCES `{$reviews}` (review_id) ON UPDATE RESTRICT ON DELETE CASCADE,"
            . "CONSTRAINT publications_source_xor CHECK ((rating_id IS NOT NULL) <> (review_id IS NOT NULL)),"
            . "CONSTRAINT publications_author_status CHECK (author_status IN ('active','withdrawn')),"
            . "CONSTRAINT publications_moderation_status CHECK (moderation_status IN ('visible','hidden','removed')),"
            . "CONSTRAINT publications_moderation_metadata CHECK ((moderation_status='visible' AND moderation_reason IS NULL AND moderator_user_id IS NULL AND moderated_at IS NULL) OR (moderation_status IN ('hidden','removed') AND moderation_reason IS NOT NULL AND CHAR_LENGTH(TRIM(moderation_reason))>0 AND moderator_user_id IS NOT NULL AND moderated_at IS NOT NULL)),"
            . "CONSTRAINT publications_version_positive CHECK (publication_version >= 1),"
            . "CONSTRAINT publications_technical_time CHECK (updated_at >= published_at)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function exists(string $table): bool
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
            DB_NAME,
            $table
        )) === 1;
    }
}
