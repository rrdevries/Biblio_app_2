<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationEvidence;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourceCategoryStrategy;
use Biblio\Core\Application\Migration\Runner\MigrationSourceFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use JsonException;

final readonly class CurrentV1SourceAdapter implements MigrationSourceAdapter
{
    public const ADAPTER_ID = "current-v1-json-29";
    public const SOURCE_FAMILY = "biblio-v1";
    public const SOURCE_VERSION = "books-29.authors-2.reading-goals-2";

    public const BOOK = "v1.book";
    public const COPY = "v1.copy";
    public const AUTHOR = "v1.author";
    public const WISHLIST_ITEM = "v1.wishlist_item";
    public const READING_ROUND = "v1.reading_round";
    public const NOTE = "v1.note";
    public const CIRCULATION_ROUND = "v1.circulation_round";
    public const READING_GOAL = "v1.reading_goal";

    /** @var list<string> */
    private const DATA_FILES = [
        "data/authors.json",
        "data/book_enrich_cache.json",
        "data/book_types.json",
        "data/books.json",
        "data/cache.json",
        "data/carriers.json",
        "data/categories.json",
        "data/genres.json",
        "data/home_prefs.json",
        "data/next_to_read.json",
        "data/reading_goals.json",
        "data/recommendations_ignored.json",
        "data/recommendations_prefs.json",
        "data/releases.json",
        "data/releases_dismissed.json",
        "data/releases_excluded.json",
        "data/releases_merged.json",
        "data/releases_prefs.json",
        "data/taxonomy_aliases.json",
        "data/taxonomy_review_queue.json",
    ];

    /** @var list<string> */
    private const BOOK_FIELDS = [
        "acqMonth", "acqYear", "acquiredAt", "acquiredVia", "acquisition",
        "addedToSystemAt", "archive", "archived", "archivedAt", "authorIds",
        "authors", "authorsLocked", "binding", "bookNumber", "bookType",
        "bundleParentBookId", "carrier", "categories", "circulation",
        "circulationRounds", "collectionStatus", "containedWorks", "copyOfBookId",
        "coverType", "coverUrl", "dateAdded", "description", "disposal",
        "editionFormat", "enrichmentHistory", "exemplarPhotos", "favorite",
        "finishDates", "finishMonth", "finishYear", "finishedAt", "finishedDates",
        "genres", "giftFrom", "googleLink", "hasMultipleWorks", "id", "isbn",
        "isbn10", "isbn13", "language", "lending", "loanPerson", "location",
        "manualEntry", "notes", "ownershipStatus", "pages", "platformSource",
        "provenance", "publishDate", "publisher", "quotes", "rating", "readHistory",
        "readMarker", "readRegistration", "readStatus", "readingRounds", "reflection",
        "reviewed", "reviewedAt", "reviews", "series", "seriesName", "seriesNumber",
        "specialEdition", "specialEditionTypes", "specialFeatures", "startDates",
        "startedAt", "subtitle", "taxonomyMeta", "title", "titleGroupKey",
        "updatedAt", "variantOfBookId", "wishlist", "wishlistEditionPreference",
    ];

    /** @var list<string> */
    private const COPY_FIELDS = [
        "acquisition", "archiveReason", "archived", "archivedAt", "bookId",
        "circulationRounds", "condition", "copyNumber", "createdAt", "disposal",
        "exemplarPhotos", "id", "legacyBookNumber", "location", "notes",
        "ownershipStatus", "platformSource", "sourceBookNumber", "status", "updatedAt",
    ];

    /** @var list<string> */
    private const WISHLIST_FIELDS = [
        "authorSnapshot", "bookId", "createdAt", "desiredBinding", "desiredCarrier",
        "desiredLanguage", "fulfilledAt", "fulfilledCopyId", "id", "notes", "priority",
        "status", "titleGroupKey", "titleSnapshot", "type", "updatedAt",
    ];

    /** @var list<string> */
    private const AUTHOR_FIELDS = [
        "aliases", "bio", "createdAt", "displayName", "familyName", "givenName", "id",
        "links", "namePartsLocked", "photoUrl", "preferredLanguage", "provenance",
        "sortName", "updatedAt",
    ];

    /** @var list<string> */
    private const GOAL_FIELDS = [
        "active", "config", "createdAt", "id", "title", "type", "updatedAt",
    ];

    public function adapterId(): string
    {
        return self::ADAPTER_ID;
    }

    public function sourceFamily(): string
    {
        return self::SOURCE_FAMILY;
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === self::SOURCE_VERSION;
    }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        $source = $this->load($package);
        $findings = [];

        $books = $this->stableRows(
            $source["books"]["books"],
            "data/books.json#books",
            ["id"],
            $findings
        );
        $copies = $this->stableRows(
            $source["books"]["copies"],
            "data/books.json#copies",
            ["id", "bookId"],
            $findings
        );
        $wishlist = $this->stableRows(
            $source["books"]["wishlistItems"],
            "data/books.json#wishlistItems",
            ["id", "bookId"],
            $findings
        );
        $authors = $this->stableRows(
            $source["authors"]["authors"],
            "data/authors.json#authors",
            ["id"],
            $findings
        );
        $goals = $this->stableRows(
            $source["reading_goals"]["goals"],
            "data/reading_goals.json#goals",
            ["id"],
            $findings
        );

        $rounds = $this->nestedStableRows(
            $books,
            "readingRounds",
            "data/books.json#books/*/readingRounds",
            $findings
        );
        $notes = $this->nestedStableRows(
            $books,
            "notes",
            "data/books.json#books/*/notes",
            $findings
        );
        $circulation = $this->circulationRows($books, $copies, $findings);

        $categoryCounts = [
            "author_records" => count($source["authors"]["authors"]),
            "book_records" => count($source["books"]["books"]),
            "circulation_round_records" => count($circulation["rows"])
                + $circulation["malformed"],
            "copy_records" => count($source["books"]["copies"]),
            "note_records" => $this->nestedCount($source["books"]["books"], "notes"),
            "reading_goal_records" => count($source["reading_goals"]["goals"]),
            "reading_round_records" => $this->nestedCount(
                $source["books"]["books"],
                "readingRounds"
            ),
            "wishlist_records" => count($source["books"]["wishlistItems"]),
            "archive_occurrences" => $this->countRows(
                $source["books"]["copies"],
                static fn (array $row): bool => ($row["archived"] ?? null) === true
            ),
            "classification_assignments" => $this->nestedCount(
                $source["books"]["books"],
                "categories"
            ) + $this->nestedCount($source["books"]["books"], "genres"),
            "classification_definitions" => count($source["book_types"])
                + count($source["carriers"])
                + count($source["categories"])
                + count($source["genres"]),
            "collection_records" => 0,
            "contained_work_occurrences" => $this->nestedCount(
                $source["books"]["books"],
                "containedWorks"
            ),
            "contributor_occurrences" => $this->nestedCount(
                $source["books"]["books"],
                "authors"
            ),
            "copy_note_occurrences" => $this->countRows(
                $source["books"]["copies"],
                static fn (array $row): bool => self::nonEmptyString($row["notes"] ?? null)
            ),
            "cover_cache_files" => $this->fileCount($package, "data/cover-cache/"),
            "macos_metadata_files" => $this->fileCount($package, "__MACOSX/"),
            "migration_report_files" => $this->fileCount($package, "data/migration_reports/"),
            "rating_occurrences" => $this->countRows(
                $source["books"]["books"],
                static fn (array $row): bool => is_int($row["rating"] ?? null)
                    && $row["rating"] > 0
            ),
            "reading_registration_occurrences" => $this->countRows(
                $source["books"]["books"],
                static fn (array $row): bool => isset($row["readRegistration"])
                    && is_array($row["readRegistration"])
            ),
            "reflection_occurrences" => $this->countRows(
                $source["books"]["books"],
                static fn (array $row): bool => self::nonEmptyString($row["reflection"] ?? null)
            ),
            "review_occurrences" => $this->nestedCount(
                $source["books"]["books"],
                "reviews"
            ),
            "series_occurrences" => $this->countRows(
                $source["books"]["books"],
                static fn (array $row): bool => self::nonEmptyString($row["seriesName"] ?? null)
            ),
            "taxonomy_review_queue_records" => count($source["taxonomy_review_queue"]),
        ];

        $malformed = [
            "author_records" => count($source["authors"]["authors"]) - count($authors),
            "book_records" => count($source["books"]["books"]) - count($books),
            "circulation_round_records" => $circulation["malformed"],
            "copy_records" => count($source["books"]["copies"]) - count($copies),
            "note_records" => $categoryCounts["note_records"] - count($notes),
            "reading_goal_records" => count($source["reading_goals"]["goals"]) - count($goals),
            "reading_round_records" => $categoryCounts["reading_round_records"] - count($rounds),
            "wishlist_records" => count($source["books"]["wishlistItems"]) - count($wishlist),
        ];

        $strategies = [
            $this->stableStrategy("author_records", self::AUTHOR, $malformed["author_records"]),
            $this->stableStrategy("book_records", self::BOOK, $malformed["book_records"]),
            $this->stableStrategy(
                "circulation_round_records",
                self::CIRCULATION_ROUND,
                $malformed["circulation_round_records"]
            ),
            $this->stableStrategy("copy_records", self::COPY, $malformed["copy_records"]),
            $this->stableStrategy("note_records", self::NOTE, $malformed["note_records"]),
            $this->stableStrategy(
                "reading_goal_records",
                self::READING_GOAL,
                $malformed["reading_goal_records"]
            ),
            $this->stableStrategy(
                "reading_round_records",
                self::READING_ROUND,
                $malformed["reading_round_records"]
            ),
            $this->stableStrategy(
                "wishlist_records",
                self::WISHLIST_ITEM,
                $malformed["wishlist_records"]
            ),
        ];

        foreach ([
            "archive_occurrences" => "embedded_copy_state_no_archive_id",
            "classification_assignments" => "idless_classification_assignment",
            "classification_definitions" => "name_only_definition",
            "collection_records" => null,
            "contained_work_occurrences" => "idless_contained_work",
            "contributor_occurrences" => "idless_contributor_occurrence",
            "copy_note_occurrences" => "idless_copy_note",
            "cover_cache_files" => "auxiliary_cache_file",
            "macos_metadata_files" => "auxiliary_filesystem_metadata",
            "migration_report_files" => "historical_report_file",
            "rating_occurrences" => "idless_assessment",
            "reading_registration_occurrences" => "idless_reading_registration",
            "reflection_occurrences" => "idless_reflection",
            "review_occurrences" => "idless_assessment",
            "series_occurrences" => "name_only_series_occurrence",
            "taxonomy_review_queue_records" => "idless_review_queue_entry",
        ] as $category => $reason) {
            $strategies[] = new MigrationSourceCategoryStrategy(
                $category,
                [],
                $categoryCounts[$category],
                $categoryCounts[$category] > 0 ? $reason : null
            );
        }

        $this->addFieldInventory(
            $findings,
            $source["books"]["books"],
            "data/books.json#books"
        );
        $this->addFieldInventory(
            $findings,
            $source["books"]["copies"],
            "data/books.json#copies"
        );
        $this->addFieldInventory(
            $findings,
            $source["books"]["wishlistItems"],
            "data/books.json#wishlistItems"
        );
        $this->addFieldInventory(
            $findings,
            $source["authors"]["authors"],
            "data/authors.json#authors"
        );
        $this->addObservedValues($findings, $source);
        $this->addIntegrityFindings($findings, $source, $circulation["rows"]);

        return new MigrationSourceProfile(
            self::SOURCE_VERSION,
            $categoryCounts,
            [
                "auxiliary_caches",
                "home_preferences",
                "migration_reports",
                "recommendation_state",
                "release_tracking",
                "taxonomy_aliases",
                "taxonomy_review_queue",
            ],
            $findings,
            $strategies
        );
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        if (!$this->supportsVersion($profile->sourceVersion())) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedVersion,
                "CURRENT V1 source profile version changed before enumeration."
            );
        }

        $source = $this->load($package);
        $ignoredFindings = [];
        $books = $this->stableRows(
            $source["books"]["books"],
            "data/books.json#books",
            ["id"],
            $ignoredFindings
        );
        $copies = $this->stableRows(
            $source["books"]["copies"],
            "data/books.json#copies",
            ["id", "bookId"],
            $ignoredFindings
        );
        $authors = $this->stableRows(
            $source["authors"]["authors"],
            "data/authors.json#authors",
            ["id"],
            $ignoredFindings
        );
        $wishlist = $this->stableRows(
            $source["books"]["wishlistItems"],
            "data/books.json#wishlistItems",
            ["id", "bookId"],
            $ignoredFindings
        );

        foreach ($books as $book) {
            $references = [];
            foreach ($this->stringList($book["authorIds"] ?? []) as $authorId) {
                $references[] = self::AUTHOR . ":" . $authorId;
            }
            if (self::nonEmptyString($book["variantOfBookId"] ?? null)) {
                $references[] = self::BOOK . ":" . $book["variantOfBookId"];
            }
            yield new MigrationSourceRecord(
                self::BOOK,
                $book["id"],
                $book,
                $references
            );
        }

        foreach ($copies as $copy) {
            yield new MigrationSourceRecord(
                self::COPY,
                $copy["id"],
                $copy,
                [self::BOOK . ":" . $copy["bookId"]]
            );
        }

        foreach ($authors as $author) {
            yield new MigrationSourceRecord(self::AUTHOR, $author["id"], $author);
        }

        foreach ($wishlist as $item) {
            $references = [self::BOOK . ":" . $item["bookId"]];
            if (self::nonEmptyString($item["fulfilledCopyId"] ?? null)) {
                $references[] = self::COPY . ":" . $item["fulfilledCopyId"];
            }
            yield new MigrationSourceRecord(
                self::WISHLIST_ITEM,
                $item["id"],
                $item,
                $references
            );
        }

        foreach ($books as $book) {
            foreach ($this->stableNestedValues($book, "readingRounds") as $round) {
                yield new MigrationSourceRecord(
                    self::READING_ROUND,
                    $round["id"],
                    ["book_id" => $book["id"], "record" => $round],
                    [self::BOOK . ":" . $book["id"]]
                );
            }
            foreach ($this->stableNestedValues($book, "notes") as $note) {
                yield new MigrationSourceRecord(
                    self::NOTE,
                    $note["id"],
                    ["book_id" => $book["id"], "record" => $note],
                    [self::BOOK . ":" . $book["id"]]
                );
            }
        }

        $circulation = $this->circulationRows($books, $copies, $ignoredFindings);
        foreach ($circulation["rows"] as $id => $row) {
            yield new MigrationSourceRecord(
                self::CIRCULATION_ROUND,
                $id,
                $row["payload"],
                array_keys($row["references"])
            );
        }

        foreach ($this->stableRows(
            $source["reading_goals"]["goals"],
            "data/reading_goals.json#goals",
            ["id"],
            $ignoredFindings
        ) as $goal) {
            yield new MigrationSourceRecord(self::READING_GOAL, $goal["id"], $goal);
        }
    }

    /**
     * @return array{
     *   books:array{schemaVersion:int,books:list<mixed>,copies:list<mixed>,wishlistItems:list<mixed>},
     *   authors:array{schemaVersion:int,authors:list<mixed>},
     *   reading_goals:array{schemaVersion:int,goals:list<mixed>},
     *   book_types:list<mixed>,carriers:list<mixed>,categories:list<mixed>,genres:list<mixed>,
     *   taxonomy_review_queue:list<mixed>
     * }
     */
    private function load(MigrationSourcePackage $package): array
    {
        $this->assertLayout($package);

        $books = $this->decodeObject($package, "data/books.json");
        $authors = $this->decodeObject($package, "data/authors.json");
        $goals = $this->decodeObject($package, "data/reading_goals.json");
        $this->assertExactKeys($books, ["books", "copies", "schemaVersion", "wishlistItems"]);
        $this->assertExactKeys($authors, ["authors", "schemaVersion"]);
        $this->assertExactKeys($goals, ["goals", "schemaVersion"]);
        if (
            ($books["schemaVersion"] ?? null) !== 29
            || ($authors["schemaVersion"] ?? null) !== 2
            || ($goals["schemaVersion"] ?? null) !== 2
        ) {
            throw $this->unsupported("CURRENT V1 schema markers are not the reviewed combination.");
        }
        foreach ([
            [$books, "books"],
            [$books, "copies"],
            [$books, "wishlistItems"],
            [$authors, "authors"],
            [$goals, "goals"],
        ] as [$object, $key]) {
            if (!is_array($object[$key] ?? null) || !array_is_list($object[$key])) {
                throw $this->unsupported("CURRENT V1 primary collection structure is unsupported.");
            }
        }

        $this->assertReviewedRowKeys($books["books"], self::BOOK_FIELDS);
        $this->assertReviewedRowKeys($books["copies"], self::COPY_FIELDS);
        $this->assertReviewedRowKeys($books["wishlistItems"], self::WISHLIST_FIELDS);
        $this->assertReviewedRowKeys($authors["authors"], self::AUTHOR_FIELDS);
        $this->assertReviewedRowKeys($goals["goals"], self::GOAL_FIELDS);
        $this->assertListFields($books["books"], [
            "authorIds", "authors", "categories", "circulationRounds",
            "containedWorks", "enrichmentHistory", "finishDates", "finishedDates",
            "genres", "notes", "quotes", "readHistory", "readingRounds", "reviews",
            "specialEditionTypes", "specialFeatures", "startDates",
        ]);
        $this->assertListFields($books["copies"], ["circulationRounds"]);
        $this->assertListFields($authors["authors"], ["aliases"]);
        $this->assertNestedRowKeys($books["books"], "readingRounds", [
            "finishedAt", "finishedAtPartial", "id", "pauses", "startedAt",
            "startedAtPartial", "stopReason", "stoppedAt", "stoppedAtPartial",
        ]);
        $this->assertNestedRowKeys($books["books"], "notes", [
            "createdAt", "id", "text", "updatedAt",
        ]);
        $this->assertNestedRowKeys($books["books"], "reviews", ["date", "text"]);
        $this->assertNestedRowKeys($books["books"], "containedWorks", [
            "author", "isbn", "series", "seriesIndex", "title",
        ]);
        $this->assertNestedRowKeys($books["books"], "circulationRounds", [
            "counterparty", "endDate", "id", "notes", "startDate", "type",
        ]);
        $this->assertNestedRowKeys($books["copies"], "circulationRounds", [
            "counterparty", "endDate", "id", "notes", "startDate", "type",
        ]);
        $this->assertObjectFields($books["books"], [
            "acquisition" => ["date", "source", "type"],
            "circulation" => [],
            "disposal" => ["date", "reason"],
            "exemplarPhotos" => ["back", "front", "spine"],
            "lending" => ["counterparty", "dueAt", "mode", "notes", "startAt"],
            "provenance" => ["description"],
            "readRegistration" => ["mode", "partialDate"],
            "taxonomyMeta" => ["bookType", "canonical"],
        ]);
        $this->assertObjectFields($books["copies"], [
            "acquisition" => ["date", "source", "type"],
            "disposal" => ["date", "reason"],
            "exemplarPhotos" => ["back", "front", "spine"],
        ]);
        $this->assertObjectFields($authors["authors"], [
            "links" => ["wikipedia"],
            "provenance" => ["bio", "links", "photoUrl"],
        ]);
        $this->assertObjectFields($goals["goals"], [
            "config" => ["filters", "scope", "seriesName", "targetBooks", "year"],
        ]);
        $this->assertNestedObjectFields($books["books"], "acquisition", [
            "date" => ["precision", "value"],
        ]);
        $this->assertNestedObjectFields($books["books"], "disposal", [
            "date" => ["precision", "value"],
        ]);
        $this->assertNestedObjectFields($books["books"], "readRegistration", [
            "partialDate" => ["precision", "value"],
        ]);
        $this->assertNestedObjectFields($books["copies"], "acquisition", [
            "date" => ["precision", "value"],
        ]);
        $this->assertNestedObjectFields($books["copies"], "disposal", [
            "date" => ["precision", "value"],
        ]);
        $this->assertNestedObjectFields($goals["goals"], "config", [
            "filters" => ["goalYear", "libraryOnly", "physicalOnly", "seriesName"],
            "scope" => ["goalYear", "libraryOnly", "physicalOnly", "seriesName"],
        ]);
        $this->assertObjectFieldsInNestedRows($books["books"], "readingRounds", [
            "finishedAtPartial" => ["precision", "value"],
            "startedAtPartial" => ["precision", "value"],
            "stoppedAtPartial" => ["precision", "value"],
        ]);
        $this->assertObjectFieldsInNestedRows($books["books"], "circulationRounds", [
            "endDate" => ["precision", "value"],
            "startDate" => ["precision", "value"],
        ]);
        $this->assertObjectFieldsInNestedRows($books["copies"], "circulationRounds", [
            "endDate" => ["precision", "value"],
            "startDate" => ["precision", "value"],
        ]);

        $this->assertReviewedRowKeys($this->decodeList($package, "data/book_types.json"), [
            "description", "name", "sortOrder", "status",
        ]);
        $this->assertReviewedRowKeys($this->decodeList($package, "data/carriers.json"), [
            "description", "name", "sortOrder", "status",
        ]);
        $this->assertReviewedRowKeys($this->decodeList($package, "data/categories.json"), [
            "aliasOf", "description", "name", "sortOrder", "status",
        ]);
        $this->assertReviewedRowKeys($this->decodeList($package, "data/genres.json"), [
            "aliasOf", "allowedBookTypes", "category", "description", "name",
            "sortOrder", "status",
        ]);
        $this->assertReviewedRowKeys(
            $this->decodeList($package, "data/taxonomy_review_queue.json"),
            [
                "contexts", "firstSeenAt", "frequency", "lastSeenAt", "normalizedTerm",
                "resolution", "resolvedAt", "source", "status", "term",
            ]
        );

        $this->assertAuxiliaryContracts($package);

        return [
            "books" => $books,
            "authors" => $authors,
            "reading_goals" => $goals,
            "book_types" => $this->decodeList($package, "data/book_types.json"),
            "carriers" => $this->decodeList($package, "data/carriers.json"),
            "categories" => $this->decodeList($package, "data/categories.json"),
            "genres" => $this->decodeList($package, "data/genres.json"),
            "taxonomy_review_queue" => $this->decodeList(
                $package,
                "data/taxonomy_review_queue.json"
            ),
        ];
    }

    private function assertLayout(MigrationSourcePackage $package): void
    {
        $paths = [];
        foreach ($package->files() as $file) {
            $path = $file->relativePath();
            $paths[$path] = true;
            if (
                in_array($path, self::DATA_FILES, true)
                || preg_match('#^data/cover-cache/[^/]+\.(?:gif|img|jpg|json|png|webp)$#', $path) === 1
                || preg_match('#^data/migration_reports/[^/]+\.json$#', $path) === 1
                || str_starts_with($path, "__MACOSX/")
            ) {
                if (
                    str_starts_with($path, "data/")
                    && str_ends_with($path, ".json")
                    && !in_array($path, self::DATA_FILES, true)
                ) {
                    $this->decode($package, $path);
                }
                continue;
            }
            throw $this->unsupported("CURRENT V1 source contains an unreviewed path.");
        }
        foreach (self::DATA_FILES as $required) {
            if (!isset($paths[$required])) {
                throw $this->unsupported("CURRENT V1 source is missing a reviewed data file.");
            }
        }
    }

    private function assertAuxiliaryContracts(MigrationSourcePackage $package): void
    {
        $this->assertSchemaObject($package, "data/book_enrich_cache.json", 1, ["entries"]);
        $this->assertSchemaObject($package, "data/cache.json", 1, ["entries"]);
        $this->assertSchemaObject(
            $package,
            "data/home_prefs.json",
            1,
            ["hiddenWidgets", "widgetOrder"]
        );
        $this->assertExactKeys($this->decodeObject($package, "data/next_to_read.json"), ["items"]);
        $this->assertSchemaObject(
            $package,
            "data/recommendations_ignored.json",
            1,
            ["ignoredBookIds"]
        );
        $this->assertSchemaObject(
            $package,
            "data/recommendations_prefs.json",
            2,
            ["ignored", "shown"]
        );
        $this->assertSchemaObject(
            $package,
            "data/releases.json",
            1,
            ["byAuthor", "lastCheckedAt", "lastRun", "mergedDiff"]
        );

        $dismissed = $this->decodeObject($package, "data/releases_dismissed.json");
        $this->assertExactKeys($dismissed, ["dismissed", "version"]);
        if (($dismissed["version"] ?? null) !== 1) {
            throw $this->unsupported("CURRENT V1 release-dismissal marker is unsupported.");
        }
        $this->decodeList($package, "data/releases_excluded.json");
        $this->decodeList($package, "data/releases_merged.json");
        $this->assertSchemaObject(
            $package,
            "data/releases_prefs.json",
            1,
            ["trackedAuthorIds"]
        );

        $aliases = $this->decodeObject($package, "data/taxonomy_aliases.json");
        $this->assertExactKeys($aliases, ["rules", "version"]);
        if (($aliases["version"] ?? null) !== 2 || !is_array($aliases["rules"] ?? null)) {
            throw $this->unsupported("CURRENT V1 taxonomy-alias marker is unsupported.");
        }
    }

    /** @param list<string> $keys */
    private function assertSchemaObject(
        MigrationSourcePackage $package,
        string $path,
        int $version,
        array $keys
    ): void {
        $decoded = $this->decodeObject($package, $path);
        $this->assertExactKeys($decoded, [...$keys, "schemaVersion"]);
        if (($decoded["schemaVersion"] ?? null) !== $version) {
            throw $this->unsupported("CURRENT V1 auxiliary schema marker is unsupported.");
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw $this->unsupported("CURRENT V1 auxiliary structure is unsupported.");
            }
        }
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private function assertExactKeys(array $object, array $expected): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->unsupported("CURRENT V1 object keys differ from the reviewed structure.");
        }
    }

    /**
     * @param list<mixed> $rows
     * @param list<string> $allowed
     */
    private function assertReviewedRowKeys(array $rows, array $allowed): void
    {
        $allowedSet = array_fill_keys($allowed, true);
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (!is_string($key) || !isset($allowedSet[$key])) {
                    throw $this->unsupported(
                        "CURRENT V1 record contains an unreviewed field."
                    );
                }
            }
        }
    }

    /**
     * @param list<mixed> $parents
     * @param list<string> $allowed
     */
    private function assertNestedRowKeys(array $parents, string $field, array $allowed): void
    {
        foreach ($parents as $parent) {
            $values = is_array($parent) ? ($parent[$field] ?? null) : null;
            if ($values === null) {
                continue;
            }
            if (!is_array($values) || !array_is_list($values)) {
                throw $this->unsupported("CURRENT V1 nested record list is unsupported.");
            }
            $this->assertReviewedRowKeys($values, $allowed);
        }
    }

    /**
     * @param list<mixed> $rows
     * @param list<string> $fields
     */
    private function assertListFields(array $rows, array $fields): void
    {
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            foreach ($fields as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null) {
                    continue;
                }
                if (!is_array($row[$field]) || !array_is_list($row[$field])) {
                    throw $this->unsupported("CURRENT V1 reviewed list field changed shape.");
                }
            }
        }
    }

    /**
     * @param list<mixed> $rows
     * @param array<string,list<string>> $fields
     */
    private function assertObjectFields(array $rows, array $fields): void
    {
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            foreach ($fields as $field => $allowed) {
                if (!array_key_exists($field, $row) || $row[$field] === null) {
                    continue;
                }
                if (
                    !is_array($row[$field])
                    || ($row[$field] !== [] && array_is_list($row[$field]))
                ) {
                    throw $this->unsupported("CURRENT V1 reviewed object field changed shape.");
                }
                $this->assertAllowedKeys($row[$field], $allowed);
            }
        }
    }

    /**
     * @param list<mixed> $rows
     * @param array<string,list<string>> $fields
     */
    private function assertNestedObjectFields(
        array $rows,
        string $container,
        array $fields
    ): void {
        foreach ($rows as $row) {
            $object = is_array($row) ? ($row[$container] ?? null) : null;
            if ($object === null) {
                continue;
            }
            if (!is_array($object) || ($object !== [] && array_is_list($object))) {
                throw $this->unsupported("CURRENT V1 reviewed object container changed shape.");
            }
            $this->assertObjectFields([$object], $fields);
        }
    }

    /**
     * @param list<mixed> $parents
     * @param array<string,list<string>> $fields
     */
    private function assertObjectFieldsInNestedRows(
        array $parents,
        string $container,
        array $fields
    ): void {
        foreach ($parents as $parent) {
            $rows = is_array($parent) ? ($parent[$container] ?? null) : null;
            if ($rows === null) {
                continue;
            }
            if (!is_array($rows) || !array_is_list($rows)) {
                throw $this->unsupported("CURRENT V1 nested record list changed shape.");
            }
            $this->assertObjectFields($rows, $fields);
        }
    }

    /**
     * @param array<string,mixed> $object
     * @param list<string> $allowed
     */
    private function assertAllowedKeys(array $object, array $allowed): void
    {
        $allowedSet = array_fill_keys($allowed, true);
        foreach (array_keys($object) as $key) {
            if (!isset($allowedSet[$key])) {
                throw $this->unsupported("CURRENT V1 nested object contains an unreviewed field.");
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(MigrationSourcePackage $package, string $path): array
    {
        $decoded = $this->decode($package, $path);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw $this->unsupported("CURRENT V1 expected an object at a reviewed path.");
        }
        return $decoded;
    }

    /** @return list<mixed> */
    private function decodeList(MigrationSourcePackage $package, string $path): array
    {
        $decoded = $this->decode($package, $path);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw $this->unsupported("CURRENT V1 expected a list at a reviewed path.");
        }
        return $decoded;
    }

    private function decode(MigrationSourcePackage $package, string $path): mixed
    {
        try {
            return json_decode(
                $package->read($path),
                true,
                128,
                JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedStructure,
                "CURRENT V1 JSON is malformed at a reviewed path.",
                $exception
            );
        }
    }

    /**
     * @param list<mixed> $rows
     * @param list<string> $requiredStrings
     * @param list<MigrationSourceFinding> $findings
     * @return list<array<string, mixed>>
     */
    private function stableRows(
        array $rows,
        string $location,
        array $requiredStrings,
        array &$findings
    ): array {
        $valid = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                $findings[] = $this->malformed($location, $index);
                continue;
            }
            $ok = true;
            foreach ($requiredStrings as $field) {
                if (!self::nonEmptyString($row[$field] ?? null)) {
                    $ok = false;
                }
            }
            if (!$ok) {
                $findings[] = $this->malformed($location, $index);
                continue;
            }
            $valid[] = $row;
        }
        return $valid;
    }

    /**
     * @param list<array<string, mixed>> $parents
     * @param list<MigrationSourceFinding> $findings
     * @return list<array<string, mixed>>
     */
    private function nestedStableRows(
        array $parents,
        string $key,
        string $location,
        array &$findings
    ): array {
        $valid = [];
        $index = 0;
        foreach ($parents as $parent) {
            $values = $parent[$key] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                $findings[] = $this->malformed($location, $index++);
                continue;
            }
            foreach ($values as $value) {
                if (
                    !is_array($value)
                    || array_is_list($value)
                    || !self::nonEmptyString($value["id"] ?? null)
                ) {
                    $findings[] = $this->malformed($location, $index++);
                    continue;
                }
                $valid[] = $value;
                ++$index;
            }
        }
        return $valid;
    }

    /**
     * @param array<string, mixed> $parent
     * @return list<array<string, mixed>>
     */
    private function stableNestedValues(array $parent, string $key): array
    {
        $values = $parent[$key] ?? [];
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }
        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_array($value)
                && !array_is_list($value)
                && self::nonEmptyString($value["id"] ?? null)
        ));
    }

    /**
     * @param list<array<string, mixed>> $books
     * @param list<array<string, mixed>> $copies
     * @param list<MigrationSourceFinding> $findings
     * @return array{rows:array<string,array{payload:array<string,mixed>,references:array<string,true>}>,malformed:int}
     */
    private function circulationRows(array $books, array $copies, array &$findings): array
    {
        $rows = [];
        $malformed = 0;
        foreach ([["books", $books], ["copies", $copies]] as [$kind, $parents]) {
            foreach ($parents as $parentIndex => $parent) {
                $values = $parent["circulationRounds"] ?? [];
                if (!is_array($values) || !array_is_list($values)) {
                    ++$malformed;
                    $findings[] = $this->malformed(
                        "data/books.json#{$kind}/*/circulationRounds",
                        $parentIndex
                    );
                    continue;
                }
                foreach ($values as $index => $value) {
                    if (
                        !is_array($value)
                        || array_is_list($value)
                        || !self::nonEmptyString($value["id"] ?? null)
                    ) {
                        ++$malformed;
                        $findings[] = $this->malformed(
                            "data/books.json#{$kind}/*/circulationRounds",
                            $index
                        );
                        continue;
                    }
                    $id = $value["id"];
                    $rows[$id] ??= [
                        "payload" => ["book_occurrences" => [], "copy_occurrences" => []],
                        "references" => [],
                    ];
                    if ($kind === "books") {
                        $rows[$id]["payload"]["book_occurrences"][] = [
                            "book_id" => $parent["id"],
                            "record" => $value,
                        ];
                        $rows[$id]["references"][self::BOOK . ":" . $parent["id"]] = true;
                    } else {
                        $rows[$id]["payload"]["copy_occurrences"][] = [
                            "copy_id" => $parent["id"],
                            "book_id" => $parent["bookId"],
                            "record" => $value,
                        ];
                        $rows[$id]["references"][self::COPY . ":" . $parent["id"]] = true;
                        $rows[$id]["references"][self::BOOK . ":" . $parent["bookId"]] = true;
                    }
                }
            }
        }
        ksort($rows, SORT_STRING);
        return ["rows" => $rows, "malformed" => $malformed];
    }

    private function stableStrategy(
        string $category,
        string $sourceType,
        int $malformed
    ): MigrationSourceCategoryStrategy {
        return new MigrationSourceCategoryStrategy(
            $category,
            [$sourceType],
            $malformed,
            $malformed > 0 ? "malformed_record" : null
        );
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param list<mixed> $rows
     */
    private function addFieldInventory(array &$findings, array $rows, string $location): void
    {
        $fields = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $fields[$key] ??= ["present" => 0, "null" => 0, "empty" => 0, "types" => []];
                ++$fields[$key]["present"];
                $type = get_debug_type($value);
                $fields[$key]["types"][$type] = ($fields[$key]["types"][$type] ?? 0) + 1;
                if ($value === null) {
                    ++$fields[$key]["null"];
                }
                if ($value === "") {
                    ++$fields[$key]["empty"];
                }
            }
        }
        ksort($fields, SORT_STRING);
        foreach ($fields as $field => $profile) {
            ksort($profile["types"], SORT_STRING);
            $types = [];
            foreach ($profile["types"] as $type => $count) {
                $types[] = $type . ":" . $count;
            }
            $findings[] = new MigrationSourceFinding(
                "field_inventory",
                $location . "/*/" . $field,
                "present={$profile["present"]}; types=" . implode(",", $types)
                    . "; null={$profile["null"]}; empty_string={$profile["empty"]}."
            );
        }
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param array<string, mixed> $source
     */
    private function addObservedValues(array &$findings, array $source): void
    {
        foreach ([
            ["data/books.json#books/*/bookType", $source["books"]["books"], "bookType"],
            ["data/books.json#books/*/carrier", $source["books"]["books"], "carrier"],
            ["data/books.json#books/*/binding", $source["books"]["books"], "binding"],
            ["data/books.json#books/*/editionFormat", $source["books"]["books"], "editionFormat"],
            ["data/books.json#books/*/collectionStatus", $source["books"]["books"], "collectionStatus"],
            ["data/books.json#books/*/ownershipStatus", $source["books"]["books"], "ownershipStatus"],
            ["data/books.json#books/*/readMarker", $source["books"]["books"], "readMarker"],
            ["data/books.json#books/*/readStatus", $source["books"]["books"], "readStatus"],
            ["data/books.json#copies/*/status", $source["books"]["copies"], "status"],
            ["data/books.json#copies/*/ownershipStatus", $source["books"]["copies"], "ownershipStatus"],
            ["data/books.json#copies/*/condition", $source["books"]["copies"], "condition"],
            ["data/books.json#wishlistItems/*/status", $source["books"]["wishlistItems"], "status"],
            ["data/books.json#wishlistItems/*/type", $source["books"]["wishlistItems"], "type"],
        ] as [$location, $rows, $field]) {
            $this->addValueCounts($findings, $location, $rows, $field);
        }

        $this->addNestedValueCounts(
            $findings,
            "data/books.json#books/*/acquisition/type",
            $source["books"]["books"],
            "acquisition",
            "type"
        );
        $this->addNestedValueCounts(
            $findings,
            "data/books.json#copies/*/acquisition/type",
            $source["books"]["copies"],
            "acquisition",
            "type"
        );

        $archiveReasons = [];
        foreach ($source["books"]["copies"] as $copy) {
            if (is_array($copy) && ($copy["archived"] ?? null) === true) {
                $archiveReasons[] = $copy["archiveReason"] ?? null;
            }
        }
        $this->appendCounts(
            $findings,
            "data/books.json#copies/*/archiveReason",
            $archiveReasons
        );

        $ratings = [];
        foreach ($source["books"]["books"] as $book) {
            if (is_array($book)) {
                $ratings[] = $book["rating"] ?? null;
            }
        }
        $this->appendCounts($findings, "data/books.json#books/*/rating", $ratings);
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param list<mixed> $rows
     */
    private function addValueCounts(
        array &$findings,
        string $location,
        array $rows,
        string $field
    ): void {
        $values = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($field, $row)) {
                $values[] = $row[$field];
            }
        }
        $this->appendCounts($findings, $location, $values);
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param list<mixed> $rows
     */
    private function addNestedValueCounts(
        array &$findings,
        string $location,
        array $rows,
        string $objectField,
        string $valueField
    ): void {
        $values = [];
        foreach ($rows as $row) {
            $object = is_array($row) ? ($row[$objectField] ?? null) : null;
            if (is_array($object) && array_key_exists($valueField, $object)) {
                $values[] = $object[$valueField];
            }
        }
        $this->appendCounts($findings, $location, $values);
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param list<mixed> $values
     */
    private function appendCounts(array &$findings, string $location, array $values): void
    {
        $counts = [];
        foreach ($values as $value) {
            $label = match (true) {
                $value === "" => "<empty>",
                $value === null => "<null>",
                is_bool($value) => $value ? "true" : "false",
                is_int($value) => (string) $value,
                is_string($value)
                    && preg_match('/^[\p{L}\p{N} _&+.,\/-]{1,64}$/u', $value) === 1 => $value,
                default => "<redacted-non-categorical>",
            };
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        foreach ($counts as $value => $count) {
            $findings[] = new MigrationSourceFinding(
                "observed_raw_value",
                $location,
                "value={$value}; count={$count}."
            );
        }
    }

    /**
     * @param list<MigrationSourceFinding> $findings
     * @param array<string,mixed> $source
     * @param array<string,array{payload:array<string,mixed>,references:array<string,true>}> $circulation
     */
    private function addIntegrityFindings(
        array &$findings,
        array $source,
        array $circulation
    ): void {
        $bookIds = $this->stableIdSet($source["books"]["books"]);
        $copyIds = $this->stableIdSet($source["books"]["copies"]);
        $authorIds = $this->stableIdSet($source["authors"]["authors"]);
        $copyValid = 0;
        $copyMissing = 0;
        foreach ($source["books"]["copies"] as $copy) {
            if (!is_array($copy) || !self::nonEmptyString($copy["bookId"] ?? null)) {
                ++$copyMissing;
            } elseif (isset($bookIds[$copy["bookId"]])) {
                ++$copyValid;
            } else {
                ++$copyMissing;
            }
        }
        $findings[] = new MigrationSourceFinding(
            "reference_integrity",
            "data/books.json#copies/*/bookId",
            "valid={$copyValid}; missing={$copyMissing}."
        );

        $authorReferences = 0;
        $missingAuthorReferences = 0;
        $idlessAuthorBooks = 0;
        $authorCountMismatches = 0;
        $authorUse = [];
        foreach ($source["books"]["books"] as $book) {
            if (!is_array($book)) {
                continue;
            }
            $names = is_array($book["authors"] ?? null) ? $book["authors"] : [];
            $ids = $this->stringList($book["authorIds"] ?? []);
            if (count($names) > 0 && count($ids) === 0) {
                ++$idlessAuthorBooks;
            }
            if (count($names) !== count($ids)) {
                ++$authorCountMismatches;
            }
            foreach ($ids as $id) {
                ++$authorReferences;
                $authorUse[$id] = ($authorUse[$id] ?? 0) + 1;
                if (!isset($authorIds[$id])) {
                    ++$missingAuthorReferences;
                }
            }
        }
        $reused = count(array_filter($authorUse, static fn (int $count): bool => $count > 1));
        $maxUse = $authorUse === [] ? 0 : max($authorUse);
        $findings[] = new MigrationSourceFinding(
            "reference_integrity",
            "data/books.json#books/*/authorIds",
            "total={$authorReferences}; missing={$missingAuthorReferences}; reused_ids={$reused}; max_occurrences={$maxUse}."
        );
        $findings[] = new MigrationSourceFinding(
            "idless_structure",
            "data/books.json#books/*/authors",
            "books_with_names_and_no_author_ids={$idlessAuthorBooks}; count_mismatches={$authorCountMismatches}; no name merge permitted."
        );
        $findings[] = new MigrationSourceFinding(
            "identity_profile",
            "data/authors.json#authors",
            "stable_ids=" . count($authorIds)
                . "; duplicate_id_groups="
                . $this->duplicateGroupCount($this->ids($source["authors"]["authors"]))
                . "; duplicate_name_groups="
                . $this->duplicateGroupCount($this->strings(
                    $source["authors"]["authors"],
                    "displayName"
                ))
                . "; explicit_role_field=0."
        );

        $wishlistValid = 0;
        $wishlistMissing = 0;
        foreach ($source["books"]["wishlistItems"] as $item) {
            if (
                is_array($item)
                && self::nonEmptyString($item["bookId"] ?? null)
                && isset($bookIds[$item["bookId"]])
            ) {
                ++$wishlistValid;
            } else {
                ++$wishlistMissing;
            }
        }
        $findings[] = new MigrationSourceFinding(
            "reference_integrity",
            "data/books.json#wishlistItems/*/bookId",
            "valid={$wishlistValid}; missing={$wishlistMissing}."
        );

        $bookStates = ["open_borrowed" => 0, "open_lent_out" => 0, "closed" => 0];
        $copyStates = ["open_borrowed" => 0, "open_lent_out" => 0, "closed" => 0];
        $overlap = 0;
        $divergent = 0;
        $bookDuplicateOccurrences = 0;
        $copyDuplicateOccurrences = 0;
        foreach ($circulation as $row) {
            $bookOccurrences = $row["payload"]["book_occurrences"];
            $copyOccurrences = $row["payload"]["copy_occurrences"];
            $bookDuplicateOccurrences += max(0, count($bookOccurrences) - 1);
            $copyDuplicateOccurrences += max(0, count($copyOccurrences) - 1);
            foreach ($bookOccurrences as $occurrence) {
                $this->addCirculationState($bookStates, $occurrence["record"]);
            }
            foreach ($copyOccurrences as $occurrence) {
                $this->addCirculationState($copyStates, $occurrence["record"]);
            }
            if ($bookOccurrences !== [] && $copyOccurrences !== []) {
                ++$overlap;
                if (
                    MigrationEvidence::hash($bookOccurrences[0]["record"])
                    !== MigrationEvidence::hash($copyOccurrences[0]["record"])
                ) {
                    ++$divergent;
                }
            }
        }
        $findings[] = new MigrationSourceFinding(
            "product_decision_required",
            "data/books.json#books|copies/*/circulationRounds",
            "copy_open_borrowed={$copyStates["open_borrowed"]}; "
                . "copy_open_lent_out={$copyStates["open_lent_out"]}; "
                . "copy_closed={$copyStates["closed"]}; "
                . "book_open_borrowed={$bookStates["open_borrowed"]}; "
                . "book_open_lent_out={$bookStates["open_lent_out"]}; "
                . "book_closed={$bookStates["closed"]}; D-MIG-LOAN-01 required."
        );
        $findings[] = new MigrationSourceFinding(
            "source_representation_ambiguity",
            "data/books.json#books|copies/*/circulationRounds",
            "shared_id_groups={$overlap}; divergent_groups={$divergent}; "
                . "book_duplicate_occurrences={$bookDuplicateOccurrences}; "
                . "copy_duplicate_occurrences={$copyDuplicateOccurrences}; no source precedence applied."
        );

        $books = $source["books"]["books"];
        $copies = $source["books"]["copies"];
        $emptyIsbn = $this->countRows(
            $books,
            static fn (array $row): bool => ($row["isbn"] ?? null) === ""
        );
        $explicitNoIsbn = $this->countRows(
            $books,
            static fn (array $row): bool => array_key_exists("noIsbn", $row)
                || array_key_exists("no_isbn", $row)
        );
        $findings[] = new MigrationSourceFinding(
            "source_profile",
            "data/books.json#books/*/isbn",
            "nonempty=" . (count($books) - $emptyIsbn)
                . "; empty={$emptyIsbn}; explicit_no_isbn_marker={$explicitNoIsbn}; empty is not explicit no-ISBN."
        );

        $rounds = [];
        $bookNotes = [];
        $reviewCount = 0;
        $reflectionCount = 0;
        $ratingCount = 0;
        $seriesCount = 0;
        $seriesNames = [];
        $seriesPositions = 0;
        $seriesMissingPositions = 0;
        foreach ($books as $book) {
            if (!is_array($book)) {
                continue;
            }
            $bookRounds = is_array($book["readingRounds"] ?? null)
                ? $book["readingRounds"]
                : [];
            foreach ($bookRounds as $round) {
                if (is_array($round)) {
                    $rounds[] = $round;
                }
            }
            $notes = is_array($book["notes"] ?? null) ? $book["notes"] : [];
            foreach ($notes as $note) {
                if (is_array($note)) {
                    $bookNotes[] = $note;
                }
            }
            $reviewCount += is_array($book["reviews"] ?? null)
                ? count($book["reviews"])
                : 0;
            $reflectionCount += self::nonEmptyString($book["reflection"] ?? null) ? 1 : 0;
            $ratingCount += is_int($book["rating"] ?? null) && $book["rating"] > 0 ? 1 : 0;
            if (self::nonEmptyString($book["seriesName"] ?? null)) {
                ++$seriesCount;
                $seriesNames[] = $book["seriesName"];
                if (self::nonEmptyString($book["seriesNumber"] ?? null)) {
                    ++$seriesPositions;
                } else {
                    ++$seriesMissingPositions;
                }
            }
        }
        $activeRounds = 0;
        $finishedRounds = 0;
        $stoppedRounds = 0;
        foreach ($rounds as $round) {
            $finished = self::nonEmptyString($round["finishedAt"] ?? null)
                || (is_array($round["finishedAtPartial"] ?? null)
                    && self::nonEmptyString($round["finishedAtPartial"]["value"] ?? null));
            $stopped = self::nonEmptyString($round["stoppedAt"] ?? null)
                || (is_array($round["stoppedAtPartial"] ?? null)
                    && self::nonEmptyString($round["stoppedAtPartial"]["value"] ?? null));
            $finishedRounds += $finished ? 1 : 0;
            $stoppedRounds += $stopped ? 1 : 0;
            $activeRounds += !$finished && !$stopped ? 1 : 0;
        }
        $findings[] = new MigrationSourceFinding(
            "source_profile",
            "data/books.json#books/*/readingRounds",
            "stable_ids=" . count($rounds)
                . "; active_like={$activeRounds}; finished_like={$finishedRounds}; stopped_like={$stoppedRounds}; no mapping applied."
        );
        $findings[] = new MigrationSourceFinding(
            "source_profile",
            "data/books.json#books/*/notes|rating|reviews|reflection",
            "stable_note_ids=" . count($bookNotes)
                . "; ratings={$ratingCount}; reviews={$reviewCount}; reflections={$reflectionCount}; no Note/Review inference applied."
        );
        $findings[] = new MigrationSourceFinding(
            "idless_structure",
            "data/books.json#books/*/seriesName",
            "occurrences={$seriesCount}; distinct_names=" . count(array_unique($seriesNames))
                . "; positions={$seriesPositions}; missing_positions={$seriesMissingPositions}; stable_series_ids=0."
        );

        $conditionNonempty = $this->countRows(
            $copies,
            static fn (array $row): bool => self::nonEmptyString($row["condition"] ?? null)
        );
        $locationNonempty = $this->countRows(
            $copies,
            static fn (array $row): bool => self::nonEmptyString($row["location"] ?? null)
        );
        $acquisition = 0;
        $acquisitionTypeMissing = 0;
        foreach ($copies as $copy) {
            if (!is_array($copy) || !is_array($copy["acquisition"] ?? null)) {
                continue;
            }
            ++$acquisition;
            if (!self::nonEmptyString($copy["acquisition"]["type"] ?? null)) {
                ++$acquisitionTypeMissing;
            }
        }
        $findings[] = new MigrationSourceFinding(
            $acquisitionTypeMissing > 0 ? "malformed_value" : "source_profile",
            "data/books.json#copies/*/acquisition",
            "objects={$acquisition}; missing_type={$acquisitionTypeMissing}; price_fields=0; currency_fields=0."
        );
        $findings[] = new MigrationSourceFinding(
            "source_profile",
            "data/books.json#copies/*/condition|location",
            "condition_nonempty={$conditionNonempty}; location_nonempty={$locationNonempty}; location_ids=0."
        );

        $privateSourceCount = 0;
        $privateSources = [];
        foreach ($source["books"]["copies"] as $copy) {
            $acquisition = is_array($copy) ? ($copy["acquisition"] ?? null) : null;
            $value = is_array($acquisition) ? ($acquisition["source"] ?? null) : null;
            if (self::nonEmptyString($value)) {
                ++$privateSourceCount;
                $privateSources[hash("sha256", $value)] = true;
            }
        }
        $findings[] = new MigrationSourceFinding(
            "private_values_omitted",
            "data/books.json#copies/*/acquisition/source",
            "nonempty={$privateSourceCount}; distinct=" . count($privateSources) . "; values omitted."
        );

        $findings[] = new MigrationSourceFinding(
            "reference_integrity",
            "data/books.json#copies/*/id",
            "stable_copy_ids=" . count($copyIds) . "; duplicate IDs are rejected during enumeration."
        );
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, true>
     */
    private function stableIdSet(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::nonEmptyString($row["id"] ?? null)) {
                $ids[$row["id"]] = true;
            }
        }
        return $ids;
    }

    /**
     * @param list<mixed> $rows
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return $this->strings($rows, "id");
    }

    /**
     * @param list<mixed> $rows
     * @return list<string>
     */
    private function strings(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::nonEmptyString($row[$field] ?? null)) {
                $values[] = $row[$field];
            }
        }
        return $values;
    }

    /** @param list<string> $values */
    private function duplicateGroupCount(array $values): int
    {
        $counts = array_count_values($values);
        return count(array_filter($counts, static fn (int $count): bool => $count > 1));
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }
        return array_values(array_filter($values, self::nonEmptyString(...)));
    }

    /** @param list<mixed> $rows */
    private function nestedCount(array $rows, string $key): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $values = is_array($row) ? ($row[$key] ?? null) : null;
            if (is_array($values) && array_is_list($values)) {
                $count += count($values);
            }
        }
        return $count;
    }

    /** @param list<mixed> $rows @param callable(array<string,mixed>):bool $predicate */
    private function countRows(array $rows, callable $predicate): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row) && !array_is_list($row) && $predicate($row)) {
                ++$count;
            }
        }
        return $count;
    }

    private function fileCount(MigrationSourcePackage $package, string $prefix): int
    {
        return count(array_filter(
            $package->files(),
            static fn ($file): bool => str_starts_with($file->relativePath(), $prefix)
        ));
    }

    /**
     * @param array{open_borrowed:int,open_lent_out:int,closed:int} $states
     * @param array<string,mixed> $record
     */
    private function addCirculationState(array &$states, array $record): void
    {
        $end = $record["endDate"] ?? null;
        $open = $end === null
            || $end === ""
            || (is_array($end) && !self::nonEmptyString($end["value"] ?? null));
        if (!$open) {
            ++$states["closed"];
        } elseif (($record["type"] ?? null) === "borrowed") {
            ++$states["open_borrowed"];
        } elseif (($record["type"] ?? null) === "lent_out") {
            ++$states["open_lent_out"];
        }
    }

    private function malformed(string $location, int $index): MigrationSourceFinding
    {
        return new MigrationSourceFinding(
            "malformed_record",
            $location . "/" . $index,
            "Record lacks the reviewed object shape and stable source identity."
        );
    }

    private function unsupported(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(MigrationRunnerReason::UnsupportedStructure, $message);
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== "";
    }
}
